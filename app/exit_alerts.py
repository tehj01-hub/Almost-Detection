"""Exit-without-entry alerts.

When the exit camera reads a plate that has no open visit (the vehicle never
passed the entrance camera and was never logged IN), the visit logger refuses
the OUT. Instead of silently dropping it, we record a warning here so the guard
dashboard can raise a notification and the admin can review it later.
"""
import re

CREATE_TABLE_SQL = """
    CREATE TABLE IF NOT EXISTS exit_without_entry_alerts (
        id INT NOT NULL AUTO_INCREMENT,
        plate_number VARCHAR(50) NOT NULL,
        normalized_plate VARCHAR(50) NOT NULL,
        vehicle_category VARCHAR(30) NOT NULL DEFAULT 'Unknown',
        vehicle_type VARCHAR(50) DEFAULT NULL,
        camera_name VARCHAR(100) DEFAULT NULL,
        confidence DECIMAL(5,2) DEFAULT NULL,
        plate_image VARCHAR(255) DEFAULT NULL,
        vehicle_image VARCHAR(255) DEFAULT NULL,
        detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status ENUM('New','Acknowledged') NOT NULL DEFAULT 'New',
        acknowledged_by VARCHAR(50) DEFAULT NULL,
        acknowledged_at DATETIME DEFAULT NULL,
        ack_note VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_ewe_plate_time (normalized_plate, detected_at),
        KEY idx_ewe_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
"""

# The same plate is only alerted once in this window (the exit camera sees a
# car for many frames, and it may sit at the barrier).
REPEAT_WINDOW_SECONDS = 600


def normalize_plate(plate):
    return re.sub(r'[^A-Z0-9]', '', str(plate or '').upper())


def ensure_table(connection):
    cursor = connection.cursor()
    try:
        cursor.execute(CREATE_TABLE_SQL)
        connection.commit()
    finally:
        cursor.close()


def record_exit_without_entry(connection, plate_number, db_info=None, camera_name=None,
                              confidence=None, repeat_seconds=REPEAT_WINDOW_SECONDS):
    """Store one alert for a plate seen leaving without an entry.

    Returns {'id': ..., 'plate': ...} for a new alert, or None when the plate is
    empty or was already alerted within `repeat_seconds`.
    """
    db_info = db_info or {}
    plate = str(db_info.get('official_plate') or plate_number or '').strip().upper()
    normalized = normalize_plate(plate)
    if not normalized:
        return None
    ensure_table(connection)
    cursor = connection.cursor()
    try:
        cursor.execute(
            """
            SELECT id FROM exit_without_entry_alerts
            WHERE normalized_plate = %s AND detected_at >= NOW() - INTERVAL %s SECOND
            ORDER BY id DESC LIMIT 1
            """,
            (normalized, int(repeat_seconds)),
        )
        if cursor.fetchone():
            return None
        if db_info.get('registered_vehicle_id'):
            category = 'Registered'
        elif db_info.get('visitor_vehicle_id'):
            category = 'Visitor'
        else:
            category = 'Unknown'
        conf = None
        if confidence is not None:
            try:
                conf = round(float(confidence) * 100, 2) if float(confidence) <= 1 else round(float(confidence), 2)
            except (TypeError, ValueError):
                conf = None
        cursor.execute(
            """
            INSERT INTO exit_without_entry_alerts
                (plate_number, normalized_plate, vehicle_category, vehicle_type,
                 camera_name, confidence, detected_at, status)
            VALUES (%s, %s, %s, %s, %s, %s, NOW(), 'New')
            """,
            (plate, normalized, category, db_info.get('vehicle_type') or None,
             camera_name, conf),
        )
        alert_id = cursor.lastrowid
        connection.commit()
        return {'id': alert_id, 'plate': plate, 'category': category}
    except Exception:
        connection.rollback()
        raise
    finally:
        cursor.close()


def attach_alert_image(connection, alert_id, column, relative_path):
    """Save the plate or vehicle photo path on an alert (column is whitelisted)."""
    if column not in ('plate_image', 'vehicle_image') or not relative_path:
        return
    cursor = connection.cursor()
    try:
        cursor.execute(
            f"UPDATE exit_without_entry_alerts SET {column} = %s WHERE id = %s",
            (relative_path, int(alert_id)),
        )
        connection.commit()
    finally:
        cursor.close()
