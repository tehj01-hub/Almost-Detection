import unittest
try:
    from lpr_engine.plate_tracker import PlateTracker
except ImportError:
    PlateTracker = None

class PlateTrackerTests(unittest.TestCase):
    def tracker(self):
        self.assertIsNotNone(PlateTracker, 'Missing cross-frame plate confirmation')
        return PlateTracker()
    def reading(self, text, decision='ACCEPT', bbox=(10,10,110,50)):
        return dict(normalized=text, text=text, confidence=.75, decision=decision, bbox=bbox)
    def test_one_plate_with_ocr_variants_logs_stable_winner_once(self):
        tracker=self.tracker(); results=[]
        for t,text in enumerate(['D566CP','A566CP','566CPJ','D566CP','D566CP','A566CP','566CPJ','D566CP']):
            result=tracker.observe('entrance','IN',self.reading(text),now=t)
            if result: results.append(result['normalized'])
        self.assertEqual(results,['D566CP'])
    def test_eighty_percent_logs_on_first_frame(self):
        tracker=self.tracker();reading=self.reading('D566CP');reading['confidence']=.80
        result=tracker.observe_frame('entrance','IN',[reading],now=0)
        self.assertEqual([r['normalized'] for r in result],['D566CP'])
    def test_below_eighty_percent_still_needs_three_readings(self):
        tracker=self.tracker();reading=self.reading('D566CP');reading['confidence']=.799
        self.assertEqual(tracker.observe_frame('entrance','IN',[reading],now=0),[])
        self.assertEqual(tracker.observe_frame('entrance','IN',[reading],now=1),[])
        self.assertEqual(len(tracker.observe_frame('entrance','IN',[reading],now=2)),1)
    def test_high_confidence_held_plate_is_not_logged_repeatedly(self):
        tracker=self.tracker();reading=self.reading('D566CP');reading['confidence']=.96
        results=[]
        for t in range(20):results+=tracker.observe_frame('entrance','AUTO',[reading],now=t,require_departure=True)
        self.assertEqual(len(results),1)
    def test_uncertain_readings_do_not_create_vehicle_records(self):
        tracker=self.tracker()
        for t in range(8):
            self.assertIsNone(tracker.observe('entrance','IN',self.reading('A566CP','NEEDS_REVIEW'),now=t))
    def test_two_distinct_visible_plates_are_not_merged(self):
        tracker=self.tracker(); results=[]
        for t in range(4):
            for text,box in [('D566CP',(10,10,110,50)),('A566CP',(210,10,310,50))]:
                result=tracker.observe('entrance','IN',self.reading(text,bbox=box),now=t)
                if result: results.append(result['normalized'])
        self.assertEqual(results,['D566CP','A566CP'])
    def test_direction_change_can_record_departure(self):
        tracker=self.tracker(); results=[]
        for direction in ['IN','OUT']:
            for t in range(3):
                result=tracker.observe('entrance',direction,self.reading('D566CP'),now=t)
                if result: results.append(direction)
        self.assertEqual(results,['IN','OUT'])
    def test_following_vehicle_at_same_position_can_confirm(self):
        tracker=self.tracker(); results=[]
        for t,text in enumerate(['D566CP']*3+['XYZ1234']*5):
            results += tracker.observe_frame('entrance','IN',[self.reading(text)],now=t)
        self.assertEqual([r['normalized'] for r in results],['D566CP','XYZ1234'])
    def test_next_plate_differing_by_one_character_can_confirm(self):
        tracker=self.tracker(); results=[]
        for t,text in enumerate(['D566CP']*3+['A566CP']*9):
            results += tracker.observe_frame('entrance','IN',[self.reading(text)],now=t)
        self.assertEqual([r['normalized'] for r in results],['D566CP','A566CP'])
    def test_ocr_failures_do_not_reset_a_still_visible_plate(self):
        tracker=self.tracker(); results=[]
        for t in range(3): results += tracker.observe_frame('entrance','IN',[self.reading('D566CP')],now=t)
        for t in range(3,7):
            tracker.observe_frame('entrance','IN',[],now=t,visible_boxes=[(10,10,110,50)])
        for t in range(7,11): results += tracker.observe_frame('entrance','IN',[self.reading('A566CP')],now=t)
        self.assertEqual([r['normalized'] for r in results],['D566CP'])
    def test_multiple_boxes_in_one_frame_are_not_multiple_votes(self):
        tracker=self.tracker()
        readings=[self.reading('D566CP')]*3
        self.assertEqual(tracker.observe_frame('entrance','IN',readings,now=0),[])
        self.assertEqual(tracker.observe_frame('entrance','IN',readings,now=1),[])
        results=tracker.observe_frame('entrance','IN',readings,now=2)
        self.assertEqual([r['normalized'] for r in results],['D566CP'])
    def test_visible_plate_stays_suppressed_but_new_visit_can_confirm(self):
        tracker=self.tracker(); results=[]
        for t in range(20):
            results += tracker.observe_frame('entrance','IN',[self.reading('D566CP')],now=t)
        self.assertEqual(len(results),1)
        for t in range(20,23): tracker.observe_frame('entrance','IN',[],now=t)
        results=[]
        for t in range(23,26): results += tracker.observe_frame('entrance','IN',[self.reading('D566CP')],now=t)
        self.assertEqual(len(results),1)
    def test_shared_auto_does_not_rescan_plate_after_clock_gap_or_box_movement(self):
        tracker=self.tracker(); results=[]
        for t in range(3):results+=tracker.observe_frame('entrance','AUTO',[self.reading('D566CP')],now=t,require_departure=True)
        for t in range(50,55):results+=tracker.observe_frame('entrance','AUTO',[self.reading('D566CP',bbox=(200,10,300,50))],now=t,require_departure=True)
        self.assertEqual(len(results),1)
        for t in range(55,58):tracker.observe_frame('entrance','AUTO',[],now=t,require_departure=True)
        for t in range(58,61):results+=tracker.observe_frame('entrance','AUTO',[self.reading('D566CP')],now=t,require_departure=True)
        self.assertEqual(len(results),2)
    def test_plate_can_return_after_leaving_view(self):
        tracker=self.tracker(); results=[]
        for t in [0,1,2,40,41,42]:
            result=tracker.observe('entrance','IN',self.reading('D566CP'),now=t)
            if result: results.append(result['normalized'])
        self.assertEqual(results,['D566CP','D566CP'])

if __name__=='__main__': unittest.main()
