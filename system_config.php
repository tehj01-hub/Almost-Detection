<?php

/* =====================================================================
   SHARED SYSTEM CONFIGURATION
   ---------------------------------------------------------------------
   - Vehicle types accepted across the whole system
   - Visit purposes and the time allowance attached to each
   - Self-healing schema upgrade (run on login) so the database always
     has the columns/tables these features need, even on a fresh copy
     of the database (e.g. a different laptop during the defense).
   ===================================================================== */


/* Vehicle types accepted everywhere (forms, validation, DB enums). */
const SECSYS_VEHICLE_TYPES = ['Car', 'Motorcycle', 'Truck'];


/* Visit purpose => allowed stay in minutes.
   null means "no fixed time limit" (used by "Other"). */
const SECSYS_VISIT_PURPOSES = [
    'Birthday'            => 720,  // 12 hours
    'Visiting Resident'   => 720,  // 12 hours
    'Delivery'            => 20,   // 20 minutes
    'Drop-off/on'         => 15,   // 15 minutes
    'Service Maintenance' => 480,  // 8 hours
    'Other'               => null, // free-text purpose, no fixed limit
];


/**
 * Normalize any vehicle type string to one of SECSYS_VEHICLE_TYPES.
 * Unknown values fall back to 'Car'.
 */
function secsysNormalizeVehicleType($value)
{
    $value = strtolower(trim((string)$value));

    foreach (SECSYS_VEHICLE_TYPES as $type) {
        if (strtolower($type) === $value) {
            return $type;
        }
    }

    return 'Car';
}


/**
 * Human-readable duration, e.g. 720 -> "12 hours", 20 -> "20 mins".
 */
function secsysFormatDuration($minutes)
{
    if ($minutes === null || $minutes <= 0) {
        return 'No time limit';
    }

    if ($minutes % 60 === 0) {
        $hours = intdiv($minutes, 60);
        return $hours . ($hours === 1 ? ' hour' : ' hours');
    }

    return $minutes . ' mins';
}


/**
 * Make sure the database has everything the newer features need.
 * Safe to call repeatedly: every step checks before changing anything.
 */
function secsysEnsureSchema(mysqli $conn)
{
    static $alreadyChecked = false;

    if ($alreadyChecked) {
        return;
    }

    $alreadyChecked = true;

    /* -------------------------------------------------------------
       1. Add 'Truck' to every vehicle_type ENUM column.
    ------------------------------------------------------------- */

    $enumList = "'" . implode("','", SECSYS_VEHICLE_TYPES) . "'";

    $vehicleTables = [
        'registered_vehicles',
        'registered_vehicles_archive',
        'vehicle_logs',
        'vehicle_logs_archive',
        'visitor_vehicles',
    ];

    foreach ($vehicleTables as $table) {

        $col = $conn->query(
            "SHOW COLUMNS FROM `$table` LIKE 'vehicle_type'"
        );

        if (!$col || $col->num_rows === 0) {
            continue; // table or column missing; nothing to upgrade
        }

        $info = $col->fetch_assoc();
        $type = strtolower($info['Type'] ?? '');

        if (
            strpos($type, 'enum(') === 0 &&
            strpos($type, "'truck'") === false
        ) {
            $conn->query(
                "ALTER TABLE `$table`
                 MODIFY vehicle_type ENUM($enumList) NOT NULL"
            );
        }
    }

    /* -------------------------------------------------------------
       2. Visitor time allowance columns.
    ------------------------------------------------------------- */

    $visitorColumns = [
        'allowed_minutes'   => "INT DEFAULT NULL AFTER purpose",
        'expected_time_out' => "DATETIME DEFAULT NULL AFTER time_in",
    ];

    foreach ($visitorColumns as $column => $definition) {

        $check = $conn->query(
            "SHOW COLUMNS FROM visitor_vehicles LIKE '$column'"
        );

        if ($check && $check->num_rows === 0) {
            $conn->query(
                "ALTER TABLE visitor_vehicles
                 ADD COLUMN $column $definition"
            );
        }
    }

    /* -------------------------------------------------------------
       3. Duty pairing history (which guards were on duty together).
    ------------------------------------------------------------- */

    $conn->query("
        CREATE TABLE IF NOT EXISTS guard_duty_pairs (
            id INT NOT NULL AUTO_INCREMENT,
            primary_guard_id VARCHAR(30) NOT NULL,
            partner_guard_id VARCHAR(30) DEFAULT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_duty_primary (primary_guard_id),
            KEY idx_duty_partner (partner_guard_id),
            KEY idx_duty_open (ended_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    /* -------------------------------------------------------------
       4. Guard activity / Audit trail table & archived_by tracking.
    ------------------------------------------------------------- */

    $conn->query("
        CREATE TABLE IF NOT EXISTS guard_activity (
            id INT NOT NULL AUTO_INCREMENT,
            guard_id VARCHAR(30) NOT NULL,
            activity_type VARCHAR(100) NOT NULL,
            activity_description TEXT DEFAULT '',
            date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_guard_activity_time (date_created),
            KEY idx_guard_activity_guard (guard_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $archiveTablesWithUser = [
        'vehicle_logs_archive',
        'registered_vehicles_archive',
        'visitor_vehicles_archive',
    ];

    foreach ($archiveTablesWithUser as $archTbl) {
        $tblExists = $conn->query("SHOW TABLES LIKE '$archTbl'");
        if ($tblExists && $tblExists->num_rows > 0) {
            $colCheck = $conn->query("SHOW COLUMNS FROM `$archTbl` LIKE 'archived_by'");
            if ($colCheck && $colCheck->num_rows === 0) {
                $conn->query("ALTER TABLE `$archTbl` ADD COLUMN archived_by VARCHAR(100) DEFAULT NULL");
            }
        }
    }

    $colCheckVla = $conn->query("SHOW COLUMNS FROM `vehicle_logs_archive` LIKE 'allowed_minutes'");
    if ($colCheckVla && $colCheckVla->num_rows === 0) {
        $conn->query("ALTER TABLE `vehicle_logs_archive` ADD COLUMN allowed_minutes INT(11) DEFAULT NULL AFTER purpose, ADD COLUMN expected_time_out DATETIME DEFAULT NULL AFTER allowed_minutes");
    }

    /* -------------------------------------------------------------
       5. System variables / settings table (Profile module).
    ------------------------------------------------------------- */
    $conn->query("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    /* -------------------------------------------------------------
       6. Daily 12:00 AM Midnight Log Reset (auto-archive past logs).
    ------------------------------------------------------------- */
    secsysAutoArchivePastLogs($conn);
}


/**
 * Daily 12:00 AM Midnight Log Reset.
 * Automatically moves past days' vehicle logs (created before today's 12:00 AM)
 * from vehicle_logs into vehicle_logs_archive and purges them from the active table.
 * This guarantees active logs start fresh at 12:00 AM every single day.
 */
function secsysAutoArchivePastLogs(mysqli $conn): int
{
    $today = date("Y-m-d");

    // Fast check: any logs created before today?
    $checkRes = @$conn->query("SELECT COUNT(*) AS cnt FROM vehicle_logs WHERE DATE(date_created) < '$today'");
    $cnt = $checkRes ? (int)($checkRes->fetch_assoc()['cnt'] ?? 0) : 0;
    if ($cnt === 0) {
        return 0;
    }

    $conn->begin_transaction();
    try {
        $sqlArchive = "
            INSERT INTO vehicle_logs_archive
            (
                id, plate_number, vehicle_type, vehicle_category,
                registered_vehicle_id, visitor_vehicle_id, visitor_name,
                purpose, allowed_minutes, expected_time_out, direction, recognition_method,
                date_in, date_out, date_created, archived_at, archived_by
            )
            SELECT
                id, plate_number, vehicle_type, vehicle_category,
                registered_vehicle_id, visitor_vehicle_id, visitor_name,
                purpose, allowed_minutes, expected_time_out, direction, recognition_method,
                date_in, date_out, date_created, NOW(), 'System (12:00 AM Reset)'
            FROM vehicle_logs
            WHERE DATE(date_created) < ?
            ON DUPLICATE KEY UPDATE
                archived_at = VALUES(archived_at),
                archived_by = VALUES(archived_by)
        ";

        $stmt = $conn->prepare($sqlArchive);
        if ($stmt) {
            $stmt->bind_param("s", $today);
            $stmt->execute();
            $stmt->close();
        }

        $delStmt = $conn->prepare("DELETE FROM vehicle_logs WHERE DATE(date_created) < ?");
        if ($delStmt) {
            $delStmt->bind_param("s", $today);
            $delStmt->execute();
            $delStmt->close();
        }

        $conn->commit();
        secsysLogActivity($conn, 'SYSTEM', 'Daily Reset', "Automatic 12:00 AM midnight reset: archived $cnt past vehicle log record(s).");
        return $cnt;
    } catch (Exception $e) {
        $conn->rollback();
        return 0;
    }
}


/**
 * Retrieve a system setting from system_settings table with fallback default.
 */
function secsysGetSetting(mysqli $conn, string $key, string $default = ''): string
{
    static $settingsCache = null;
    if ($settingsCache === null) {
        $settingsCache = [];
        secsysEnsureSchema($conn);
        $res = @$conn->query("SELECT setting_key, setting_value FROM system_settings");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $settingsCache[$row['setting_key']] = (string)$row['setting_value'];
            }
        }
    }
    return array_key_exists($key, $settingsCache) ? (string)$settingsCache[$key] : $default;
}

function secsysGetSystemName(mysqli $conn): string
{
    return secsysGetSetting($conn, 'system_name', 'L. Surveillance');
}

function secsysGetSystemIcon(mysqli $conn): string
{
    return secsysGetSetting($conn, 'system_icon', '');
}

function secsysGetSystemTitle(mysqli $conn): string
{
    return secsysGetSetting($conn, 'system_title', "Lessandra’s Surveillance System");
}

function secsysGetSystemSubtitle(mysqli $conn): string
{
    return secsysGetSetting($conn, 'system_subtitle', 'Phase 2, Salinas 1, Bacoor, Cavite');
}

function secsysGetLoginBackground(mysqli $conn): string
{
    return secsysGetSetting($conn, 'login_background', '');
}


/**
 * Save or update a system setting in system_settings table.
 */
function secsysSetSetting(mysqli $conn, string $key, string $value): bool
{
    secsysEnsureSchema($conn);
    $stmt = $conn->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    if ($stmt) {
        $stmt->bind_param("ss", $key, $value);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
    return false;
}


/**
 * Record an audit trail / guard activity entry safely.
 */
function secsysLogActivity(mysqli $conn, string $actorId, string $activityType, string $description)
{
    secsysEnsureSchema($conn);
    $actorId = trim($actorId) !== '' ? trim($actorId) : 'SYSTEM';
    $stmt = @$conn->prepare("
        INSERT INTO guard_activity (guard_id, activity_type, activity_description, date_created)
        VALUES (?, ?, ?, NOW())
    ");
    if ($stmt) {
        $stmt->bind_param("sss", $actorId, $activityType, $description);
        @$stmt->execute();
        $stmt->close();
    }
}


