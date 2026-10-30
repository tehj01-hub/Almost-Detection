"""Confirm plate readings across frames before creating vehicle records."""
from collections import Counter, deque
import time


class PlateTracker:
    def __init__(self, minimum_votes=3, window_size=8, minimum_share=0.6, expiry_seconds=8, fast_accept_confidence=0.70):
        self.fast_accept_confidence = fast_accept_confidence
        self.minimum_votes = minimum_votes
        self.window_size = window_size
        self.minimum_share = minimum_share
        self.expiry_seconds = expiry_seconds
        self.tracks = []
        self.present_plates = {}

    @staticmethod
    def overlap(a, b):
        x1, y1 = max(a[0], b[0]), max(a[1], b[1])
        x2, y2 = min(a[2], b[2]), min(a[3], b[3])
        intersection = max(0, x2-x1) * max(0, y2-y1)
        union = (a[2]-a[0])*(a[3]-a[1]) + (b[2]-b[0])*(b[3]-b[1]) - intersection
        return intersection / union if union > 0 else 0

    @staticmethod
    def same_reading_family(a, b):
        # Local track association only; never use fuzzy matching to assign a registered vehicle.
        previous = list(range(len(b) + 1))
        for i, x in enumerate(a, 1):
            current = [i]
            for j, y in enumerate(b, 1):
                current.append(min(current[-1]+1, previous[j]+1, previous[j-1]+(x != y)))
            previous = current
        return previous[-1] <= 2

    def observe(self, channel, direction, reading, now=None):
        now = time.monotonic() if now is None else now
        box = reading.get('bbox')
        if not box or len(box) != 4:
            return None
        self.tracks = [t for t in self.tracks if now-t['last_seen'] <= self.expiry_seconds]
        matches = [t for t in self.tracks if t['channel'] == channel and t['direction'] == direction
                   and self.overlap(t['box'], box) >= 0.3
                   and (not t['emitted'] or self.same_reading_family(t['winner'], reading.get('normalized', '')))]
        track = max(matches, key=lambda t:self.overlap(t['box'], box)) if matches else None
        if track is None:
            track = dict(channel=channel, direction=direction, box=box, last_seen=now,
                         readings=deque(maxlen=self.window_size), emitted=False, vote_time=None, misses=0)
            self.tracks.append(track)
        track.update(box=box, last_seen=now, misses=0)
        if track['vote_time'] == now:
            return None
        track['vote_time'] = now
        track['readings'].append(dict(reading))
        if (not track['emitted'] and reading.get('decision') == 'ACCEPT'
                and reading.get('normalized') and reading.get('confidence', 0) >= self.fast_accept_confidence):
            track['emitted'] = True
            track['winner'] = reading['normalized']
            return dict(reading)

        accepted = [r for r in track['readings'] if r.get('decision') == 'ACCEPT' and r.get('normalized')]
        counts = Counter(r['normalized'] for r in accepted)
        if not counts:
            return None
        winner, votes = counts.most_common(1)[0]
        if votes < self.minimum_votes or votes / len(track['readings']) < self.minimum_share:
            return None
        if track['emitted']:
            if winner == track['winner']:
                return None
            # A similar next plate needs stronger sustained agreement than a first observation.
            if votes < 7 or votes / len(track['readings']) < 0.85:
                return None
        track['emitted'] = True
        track['winner'] = winner
        return max((r for r in accepted if r['normalized'] == winner), key=lambda r:r.get('confidence', 0))

    def observe_frame(self, channel, direction, readings, now=None, visible_boxes=None, require_departure=False):
        """Expire tracks after three processed frames without a matching plate box."""
        now = time.monotonic() if now is None else now
        boxes = visible_boxes if visible_boxes is not None else [r['bbox'] for r in readings if r.get('bbox')]
        if require_departure:
            for key, presence in list(self.present_plates.items()):
                if key[:2] != (channel, direction):
                    continue
                exact = next((r for r in readings if r.get('normalized') == key[2]), None)
                matched = exact.get('bbox') if exact else next((b for b in boxes if self.overlap(presence['box'], b) >= 0.3), None)
                if matched:
                    presence.update(box=matched, misses=0)
                else:
                    presence['misses'] += 1
                    if presence['misses'] >= 3:
                        del self.present_plates[key]
        for track in self.tracks:
            if track['channel'] == channel and track['direction'] == direction:
                matches = [box for box in boxes if self.overlap(track['box'], box) >= 0.3]
                if matches:
                    track['box'] = max(matches, key=lambda box:self.overlap(track['box'],box))
                    track['last_seen'] = now
                    track['misses'] = 0
                else:
                    track['misses'] += 1
        confirmed = []
        for reading in readings:
            winner = self.observe(channel, direction, reading, now=now)
            if winner:
                key = (channel, direction, winner['normalized'])
                if not require_departure or key not in self.present_plates:
                    confirmed.append(winner)
                    if require_departure:
                        self.present_plates[key] = dict(box=winner['bbox'], misses=0)
        self.tracks = [t for t in self.tracks if t['misses'] < 3 and now-t['last_seen'] <= self.expiry_seconds]
        return confirmed

    def retry_confirmation(self, channel, direction, plate):
        """Allow persistence failures to retry on the next accepted observation."""
        self.present_plates.pop((channel, direction, plate), None)
        for track in self.tracks:
            if (track['channel'], track['direction'], track.get('winner')) == (channel, direction, plate):
                track['emitted'] = False
