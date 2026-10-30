"""Transactional vehicle visits for fixed-direction and shared cameras."""
import hashlib
import re


def record_vehicle_visit(connection, plate_number, db_info, direction='AUTO'):
    """Return the committed direction and IDs; raise on failure without partial writes."""
    if direction not in ('AUTO', 'IN', 'OUT'):
        raise ValueError('Invalid visit direction')
    plate = db_info.get('official_plate') or plate_number
    normalized = re.sub(r'[^A-Z0-9]', '', plate.upper())
    if not normalized:
        raise ValueError('Plate is empty')
    lock_name = 'secsys:visit:' + hashlib.sha256(
        (connection.database + ':' + normalized).encode()).hexdigest()[:45]
    cursor = connection.cursor(dictionary=True)
    locked = False
    try:
        cursor.execute('SELECT GET_LOCK(%s, 5) AS acquired', (lock_name,))
        locked = cursor.fetchone()['acquired'] == 1
        if not locked:
            raise RuntimeError('Vehicle visit is being updated; retry')
        connection.start_transaction()
        cursor.execute("""
            SELECT id, vehicle_log_id FROM vehicle_log_dates
            WHERE UPPER(REGEXP_REPLACE(plate_number, '[^a-zA-Z0-9]', '')) = %s
              AND status = 'IN' AND time_in IS NOT NULL AND time_out IS NULL
            ORDER BY id DESC LIMIT 1 FOR UPDATE
        """, (normalized,))
        visit = cursor.fetchone()
        resolved = ('OUT' if visit else 'IN') if direction == 'AUTO' else direction
        category = db_info.get('category', 'Visitor')
        vehicle_type = db_info.get('vehicle_type') or 'Car'
        visitor_id = db_info.get('visitor_vehicle_id')
        purpose = db_info.get('purpose') or 'Visitor Entry'
        visitor_name = None
        if category == 'Visitor':
            if not visitor_id:
                cursor.execute("""
                    SELECT id FROM visitor_vehicles
                    WHERE UPPER(REGEXP_REPLACE(plate_number, '[^a-zA-Z0-9]', '')) = %s
                    ORDER BY id DESC LIMIT 1
                """, (normalized,))
                row = cursor.fetchone()
                visitor_id = row['id'] if row else None
            if not visitor_id and resolved == 'IN':
                cursor.execute("""
                    INSERT INTO visitor_vehicles
                        (visitor_name, address_to_visit, vehicle_type, plate_number,
                         purpose, status, date_in, time_in, date_created)
                    VALUES ('Visitor', '-', %s, %s, %s, 'IN', CURDATE(), NOW(), NOW())
                """, (vehicle_type, plate, purpose))
                visitor_id = cursor.lastrowid
            if visitor_id:
                cursor.execute('SELECT visitor_name FROM visitor_vehicles WHERE id = %s', (visitor_id,))
                row = cursor.fetchone()
                visitor_name = row['visitor_name'] if row else 'Visitor'
            else:
                visitor_name = 'Visitor'
        date_column = 'date_in' if resolved == 'IN' else 'date_out'
        cursor.execute(f"""
            INSERT INTO vehicle_logs
                (plate_number, vehicle_category, vehicle_type, registered_vehicle_id,
                 visitor_vehicle_id, visitor_name, purpose, direction,
                 recognition_method, {date_column}, date_created)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, 'OCR', CURDATE(), NOW())
        """, (plate, category, vehicle_type, db_info.get('registered_vehicle_id'),
              visitor_id, visitor_name, purpose, resolved))
        log_id = cursor.lastrowid
        if resolved == 'OUT' and visit:
            visit_id = visit['id']
            cursor.execute("""
                UPDATE vehicle_log_dates SET date_out=CURDATE(), time_out=NOW(), status='OUT'
                WHERE id=%s
            """, (visit_id,))
            cursor.execute('UPDATE vehicle_logs SET date_out=CURDATE() WHERE id=%s',
                           (visit['vehicle_log_id'],))
        elif resolved == 'OUT':
            cursor.execute("""
                INSERT INTO vehicle_log_dates
                    (vehicle_log_id, plate_number, vehicle_category, vehicle_type,
                     purpose, date_out, time_out, status)
                VALUES (%s, %s, %s, %s, %s, CURDATE(), NOW(), 'OUT')
            """, (log_id, plate, category, vehicle_type, purpose))
            visit_id = cursor.lastrowid
        else:
            cursor.execute("""
                INSERT INTO vehicle_log_dates
                    (vehicle_log_id, plate_number, vehicle_category, vehicle_type,
                     purpose, date_in, time_in, status)
                VALUES (%s, %s, %s, %s, %s, CURDATE(), NOW(), 'IN')
            """, (log_id, plate, category, vehicle_type, purpose))
            visit_id = cursor.lastrowid
        if category == 'Visitor' and visitor_id:
            if resolved == 'IN':
                cursor.execute("""
                    UPDATE visitor_vehicles SET date_in=CURDATE(), time_in=NOW(),
                        date_out=NULL, time_out=NULL, status='IN' WHERE id=%s
                """, (visitor_id,))
            else:
                cursor.execute("""
                    UPDATE visitor_vehicles SET date_out=CURDATE(), time_out=NOW(), status='OUT'
                    WHERE id=%s
                """, (visitor_id,))
        connection.commit()
        return {'direction': resolved, 'log_id': log_id, 'visit_id': visit_id}
    except Exception:
        connection.rollback()
        raise
    finally:
        if locked:
            cursor.execute('SELECT RELEASE_LOCK(%s)', (lock_name,))
            cursor.fetchone()
        cursor.close()
