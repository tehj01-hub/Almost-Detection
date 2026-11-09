"""
Shared Pending Visitor Detection Queue (Python LPR <-> PHP Guard Dashboard).

Holds newly detected Visitor arrival (IN) plates outside MySQL until the guard
submits the Visitor Information Input modal. If the guard cancels/closes the
modal, the plate is marked cancelled and is NEVER inserted into the database
(visitor_vehicles, vehicle_logs, vehicle_log_dates).
"""

import json
import os
import re
import time
import uuid

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFAULT_QUEUE_PATH = os.path.join(BASE_DIR, "uploads", "pending_visitors.json")

PENDING_TTL_SECONDS = 600.0     # Expire untouched pending prompts after 10 minutes
RESOLVED_TTL_SECONDS = 45.0     # Keep cancelled/saved cooldown markers for 45 seconds


def _normalize_plate(plate_text):
    return re.sub(r"[^A-Z0-9]", "", str(plate_text or "").upper())


def _acquire_lock(lock_path, timeout=0.6):
    os.makedirs(os.path.dirname(lock_path), exist_ok=True)
    deadline = time.monotonic() + timeout
    while True:
        try:
            fd = os.open(lock_path, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
            os.write(fd, str(os.getpid()).encode("ascii", errors="ignore"))
            os.close(fd)
            return True
        except FileExistsError:
            try:
                if (time.time() - os.path.getmtime(lock_path)) > 3.0:
                    os.unlink(lock_path)
                    continue
            except OSError:
                pass
            if time.monotonic() >= deadline:
                return False
            time.sleep(0.01)
        except OSError:
            return False


def _release_lock(lock_path):
    try:
        os.unlink(lock_path)
    except OSError:
        pass


def _prune_items(items, now_ts):
    kept = []
    for item in items:
        if not isinstance(item, dict):
            continue
        status = str(item.get("status") or "pending").lower()
        created_at = float(item.get("created_at") or 0.0)
        updated_at = float(item.get("updated_at") or created_at)
        if status == "pending":
            if (now_ts - created_at) <= PENDING_TTL_SECONDS:
                kept.append(item)
        else:
            if (now_ts - updated_at) <= RESOLVED_TTL_SECONDS:
                kept.append(item)
    return kept


def _read_queue_unlocked(queue_path):
    if not os.path.exists(queue_path):
        return []
    try:
        with open(queue_path, "r", encoding="utf-8") as fh:
            raw = fh.read().strip()
        if not raw:
            return []
        parsed = json.loads(raw)
        if isinstance(parsed, dict) and isinstance(parsed.get("items"), list):
            return parsed["items"]
        if isinstance(parsed, list):
            return parsed
    except Exception:
        pass
    return []


def _write_queue_unlocked(queue_path, items):
    os.makedirs(os.path.dirname(queue_path), exist_ok=True)
    payload = {"items": items, "updated_at": time.time()}
    with open(queue_path, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False)


from app.vehicle_visit_logger import find_open_vehicle_visit


def has_open_visitor_visit(connection, plate_number):
    """
    Return True if `plate_number` currently has an active, unclosed 'IN' visit
    (meaning the vehicle is currently Inside).
    """
    norm = _normalize_plate(plate_number)
    if not connection or not norm:
        return False
    cursor = None
    try:
        cursor = connection.cursor(dictionary=True)
        return find_open_vehicle_visit(cursor, norm, for_update=False) is not None
    except Exception:
        return False
    finally:
        if cursor is not None:
            try:
                cursor.close()
            except Exception:
                pass


has_open_vehicle_visit = has_open_visitor_visit


def is_visitor_pending_or_suppressed(plate_number, cooldown_seconds=30, queue_path=None):
    """
    Return True if `plate_number` is currently awaiting guard confirmation ('pending')
    or within the post-modal suppression window in `pending_visitors.json`.
    """
    norm = _normalize_plate(plate_number)
    if not norm:
        return False
    qpath = queue_path or DEFAULT_QUEUE_PATH
    now_ts = time.time()
    items = _prune_items(_read_queue_unlocked(qpath), now_ts)
    for existing in items:
        if _normalize_plate(existing.get("normalized") or existing.get("plate_number")) != norm:
            continue
        status = str(existing.get("status") or "pending").lower()
        updated_at = float(existing.get("updated_at") or existing.get("created_at") or 0.0)
        if status == "pending":
            return True
        if status in ("cancelled", "saved") and (now_ts - updated_at) < float(cooldown_seconds):
            return True
    return False


def clear_pending_visitor_for_plate(plate_number, queue_path=None):
    """
    Remove any queued/saved pending visitor entries for `plate_number` when the
    vehicle exits ('OUT'), so a subsequent return arrival at Entrance is not suppressed.
    """
    norm = _normalize_plate(plate_number)
    if not norm:
        return False
    qpath = queue_path or DEFAULT_QUEUE_PATH
    if not os.path.exists(qpath):
        return False
    lock_path = qpath + ".lock"
    if not _acquire_lock(lock_path):
        return False
    try:
        now_ts = time.time()
        items = _prune_items(_read_queue_unlocked(qpath), now_ts)
        filtered = [
            item for item in items
            if _normalize_plate(item.get("normalized") or item.get("plate_number")) != norm
        ]
        if len(filtered) != len(items):
            _write_queue_unlocked(qpath, filtered)
            return True
        return False
    finally:
        _release_lock(lock_path)


def enqueue_pending_visitor(
    plate_number,
    db_info=None,
    channel_name="Entrance Cam",
    confidence=0.0,
    cooldown_seconds=30,
    queue_path=None,
):
    """
    Queue a Visitor arrival (IN) plate for guard form confirmation without
    inserting anything into MySQL.

    Returns the queued or existing dict on success, or None if invalid/locked.
    """
    db_info = db_info or {}
    display_plate = str(db_info.get("official_plate") or plate_number or "").strip()
    norm = _normalize_plate(display_plate)
    if not norm:
        return None

    qpath = queue_path or DEFAULT_QUEUE_PATH
    lock_path = qpath + ".lock"
    if not _acquire_lock(lock_path):
        return None

    try:
        now_ts = time.time()
        items = _prune_items(_read_queue_unlocked(qpath), now_ts)

        for existing in items:
            if _normalize_plate(existing.get("normalized") or existing.get("plate_number")) != norm:
                continue
            status = str(existing.get("status") or "pending").lower()
            updated_at = float(existing.get("updated_at") or existing.get("created_at") or 0.0)
            if status == "pending":
                _write_queue_unlocked(qpath, items)
                result = dict(existing)
                result["already_queued"] = True
                return result
            if status in ("cancelled", "saved") and (now_ts - updated_at) < float(cooldown_seconds):
                _write_queue_unlocked(qpath, items)
                result = dict(existing)
                result["suppressed"] = True
                return result

        raw_purpose = str(db_info.get("purpose") or "").strip()
        if raw_purpose.lower() == "visitor entry":
            raw_purpose = ""

        entry = {
            "pending_id": f"pv_{int(now_ts * 1000)}_{uuid.uuid4().hex[:6]}",
            "plate_number": display_plate,
            "normalized": norm,
            "vehicle_type": str(db_info.get("vehicle_type") or "Car"),
            "purpose": raw_purpose,
            "direction": "IN",
            "camera": str(channel_name or "Entrance Cam"),
            "confidence": round(float(confidence or 0.0), 4),
            "status": "pending",
            "created_at": now_ts,
            "updated_at": now_ts,
        }
        items.append(entry)
        _write_queue_unlocked(qpath, items)
        return entry
    finally:
        _release_lock(lock_path)

