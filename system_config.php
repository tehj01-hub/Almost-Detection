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


/* =====================================================================
   VISITOR STAY DURATION, MULTI-STAGE REMINDERS & OVERSTAY ALARM ENGINE
   ---------------------------------------------------------------------
   Every visitor stay duration gets tailored reminder milestones before
   time expires plus an urgent Overstay Alarm as soon as time hits 00:00.
   ===================================================================== */

const SECSYS_DURATION_PRESETS = [
    'Drop - off/on - 15 Mins' => [
        'purpose_key'    => 'Drop - off/on',
        'minutes'        => 15,
        'duration_label' => '15 Mins',
        'milestones'     => [5, 2],
        'schedule_text'  => 'Reminders at 5m & 2m left • Overstay alarm at 15m',
    ],
    'Delivery - 20 Mins' => [
        'purpose_key'    => 'Delivery',
        'minutes'        => 20,
        'duration_label' => '20 Mins',
        'milestones'     => [5, 2],
        'schedule_text'  => 'Reminders at 5m & 2m left • Overstay alarm at 20m',
    ],
    'Service Maintenance - 8 Hours' => [
        'purpose_key'    => 'Service Maintenance',
        'minutes'        => 480,
        'duration_label' => '8 Hours',
        'milestones'     => [60, 30, 15, 5],
        'schedule_text'  => 'Reminders at 1h, 30m, 15m & 5m left • Overstay alarm at 8h',
    ],
    'Birthday - 12 Hours' => [
        'purpose_key'    => 'Birthday',
        'minutes'        => 720,
        'duration_label' => '12 Hours',
        'milestones'     => [60, 30, 15, 5],
        'schedule_text'  => 'Reminders at 1h, 30m, 15m & 5m left • Overstay alarm at 12h',
    ],
    'Visiting Resident - 12 Hours' => [
        'purpose_key'    => 'Visiting Resident',
        'minutes'        => 720,
        'duration_label' => '12 Hours',
        'milestones'     => [60, 30, 15, 5],
        'schedule_text'  => 'Reminders at 1h, 30m, 15m & 5m left • Overstay alarm at 12h',
    ],
];


/**
 * Return tailored reminder milestones (in minutes before expiry, sorted DESC)
 * for any stay duration in minutes.
 */
function secsysGetDurationMilestones(int $allowedMinutes): array
{
    if ($allowedMinutes <= 2) {
        return [1];
    }
    if ($allowedMinutes <= 5) {
        return [2, 1];
    }
    if ($allowedMinutes <= 14) {
        return [3, 1];
    }
    if ($allowedMinutes <= 30) {
        return [5, 2];
    }
    if ($allowedMinutes <= 90) {
        return [15, 5, 2];
    }
    if ($allowedMinutes <= 240) {
        return [30, 15, 5];
    }
    return [60, 30, 15, 5];
}


/**
 * Resolve any visitor purpose string and/or explicit allowed_minutes into a
 * normalized duration descriptor with exact milestones.
 */
function secsysResolveVisitorDuration(string $purpose, ?int $explicitMinutes = null): array
{
    $purposeTrim = trim($purpose);
    $pLower = strtolower($purposeTrim);

    // Generic camera placeholder before guard enters purpose
    $isGenericPlaceholder = (
        $purposeTrim === '' ||
        $pLower === 'visitor entry' ||
        $pLower === 'visitor' ||
        $pLower === 'none'
    );

    if ($explicitMinutes !== null && $explicitMinutes > 0) {
        $mins = max(1, (int)$explicitMinutes);
        $durLabel = secsysFormatDuration($mins);
        $milestones = secsysGetDurationMilestones($mins);
        if (isset(SECSYS_DURATION_PRESETS[$purposeTrim]) && SECSYS_DURATION_PRESETS[$purposeTrim]['minutes'] === $mins) {
            return [
                'purpose_display' => $purposeTrim,
                'allowed_minutes' => $mins,
                'duration_label'  => SECSYS_DURATION_PRESETS[$purposeTrim]['duration_label'],
                'milestones'      => SECSYS_DURATION_PRESETS[$purposeTrim]['milestones'],
                'is_pending'      => false,
            ];
        }
        $cleanBase = $isGenericPlaceholder ? 'Custom Visit' : preg_replace('/\s*-\s*\d+\s*(mins?|minutes?|hours?|hrs?)$/i', '', $purposeTrim);
        $cleanBase = trim($cleanBase) !== '' ? trim($cleanBase) : 'Custom Visit';
        $display = (stripos($purposeTrim, 'min') !== false || stripos($purposeTrim, 'hour') !== false || stripos($purposeTrim, 'hr') !== false)
            ? $purposeTrim
            : ($cleanBase . ' - ' . ucwords($durLabel));

        return [
            'purpose_display' => $display,
            'allowed_minutes' => $mins,
            'duration_label'  => ucwords($durLabel),
            'milestones'      => $milestones,
            'is_pending'      => false,
        ];
    }

    if (isset(SECSYS_DURATION_PRESETS[$purposeTrim])) {
        $preset = SECSYS_DURATION_PRESETS[$purposeTrim];
        return [
            'purpose_display' => $purposeTrim,
            'allowed_minutes' => $preset['minutes'],
            'duration_label'  => $preset['duration_label'],
            'milestones'      => $preset['milestones'],
            'is_pending'      => false,
        ];
    }

    if ($isGenericPlaceholder) {
        return [
            'purpose_display' => $purposeTrim,
            'allowed_minutes' => 0,
            'duration_label'  => 'Pending Input',
            'milestones'      => [],
            'is_pending'      => true,
        ];
    }

    // Parse embedded duration pattern like "... - 45 Mins" or "... 2 Hours"
    if (preg_match('/(\d+)\s*(hour|hr|hrs|hours)\b/i', $purposeTrim, $mHr)) {
        $mins = max(1, (int)$mHr[1] * 60);
        return [
            'purpose_display' => $purposeTrim,
            'allowed_minutes' => $mins,
            'duration_label'  => ucwords(secsysFormatDuration($mins)),
            'milestones'      => secsysGetDurationMilestones($mins),
            'is_pending'      => false,
        ];
    }
    if (preg_match('/(\d+)\s*(min|mins|minute|minutes)\b/i', $purposeTrim, $mMin)) {
        $mins = max(1, (int)$mMin[1]);
        return [
            'purpose_display' => $purposeTrim,
            'allowed_minutes' => $mins,
            'duration_label'  => ucwords(secsysFormatDuration($mins)),
            'milestones'      => secsysGetDurationMilestones($mins),
            'is_pending'      => false,
        ];
    }

    // Keyword fallback for standard purposes stored without suffix
    if (strpos($pLower, 'drop') !== false) {
        $mins = 15;
    } elseif (strpos($pLower, 'delivery') !== false) {
        $mins = 20;
    } elseif (strpos($pLower, 'service') !== false || strpos($pLower, 'maintenance') !== false) {
        $mins = 480;
    } elseif (strpos($pLower, 'birthday') !== false || strpos($pLower, 'visiting') !== false) {
        $mins = 720;
    } else {
        $mins = 60;
    }

    return [
        'purpose_display' => $purposeTrim,
        'allowed_minutes' => $mins,
        'duration_label'  => ucwords(secsysFormatDuration($mins)),
        'milestones'      => secsysGetDurationMilestones($mins),
        'is_pending'      => false,
    ];
}


/**
 * Evaluate the current timer state, active reminder milestone, or overstay
 * escalation stage for an active visitor stay.
 */
function secsysEvaluateVisitorTimer(int $allowedMinutes, int $secondsRemaining): array
{
    $allowedMinutes = max(1, $allowedMinutes);
    $milestones = secsysGetDurationMilestones($allowedMinutes);
    $finalMilestone = !empty($milestones) ? (int)end($milestones) : 1;

    if ($secondsRemaining <= 0) {
        $secOver = abs($secondsRemaining);
        $minOver = max(1, (int)ceil(max(1, $secOver) / 60));
        // Escalation bucket every 5 minutes overdue (0, 5, 10, 15, ...)
        $escalationBucket = intdiv((int)floor($secOver / 60), 5) * 5;
        return [
            'state'             => 'OVERDUE',
            'stage_key'         => 'overdue_' . $escalationBucket,
            'active_milestone'  => 0,
            'milestones'        => $milestones,
            'seconds_remaining' => $secondsRemaining,
            'minutes_left'      => 0,
            'minutes_overdue'   => $minOver,
            'seconds_overdue'   => $secOver,
        ];
    }

    $minutesLeftCeil = (int)ceil($secondsRemaining / 60);

    // Check milestones from smallest (most urgent) to largest
    $reversed = array_reverse($milestones);
    $matchedMilestone = null;
    foreach ($reversed as $ms) {
        if ($secondsRemaining <= ($ms * 60)) {
            $matchedMilestone = (int)$ms;
            break;
        }
    }

    if ($matchedMilestone !== null) {
        $isFinal = ($matchedMilestone === $finalMilestone);
        return [
            'state'             => $isFinal ? 'FINAL_WARNING' : 'REMINDER',
            'stage_key'         => ($isFinal ? 'final_' : 'rem_') . $matchedMilestone . 'm',
            'active_milestone'  => $matchedMilestone,
            'milestones'        => $milestones,
            'seconds_remaining' => $secondsRemaining,
            'minutes_left'      => $minutesLeftCeil,
            'minutes_overdue'   => 0,
            'seconds_overdue'   => 0,
        ];
    }

    return [
        'state'             => 'ACTIVE',
        'stage_key'         => 'active',
        'active_milestone'  => null,
        'milestones'        => $milestones,
        'seconds_remaining' => $secondsRemaining,
        'minutes_left'      => $minutesLeftCeil,
        'minutes_overdue'   => 0,
        'seconds_overdue'   => 0,
    ];
}


/**
 * Fetch all currently inside (IN) visitor vehicles for today with exact
 * second-level countdowns, tailored per-duration milestones, and overstay state.
 */
function secsysFetchActiveVisitorTimers(mysqli $conn): array
{
    $serverNowTs = time();
    $nowRes = @$conn->query("SELECT UNIX_TIMESTAMP(NOW()) AS now_ts");
    if ($nowRes && ($nr = $nowRes->fetch_assoc())) {
        $serverNowTs = (int)$nr['now_ts'];
    }

    $activeTimers = [];
    $overdueVisitors = [];
    $dueSoonVisitors = [];
    $pendingInfoVisitors = [];

    $sql = "
        SELECT
            vv.id,
            vv.visitor_name,
            vv.plate_number,
            vv.vehicle_type,
            vv.purpose,
            vv.allowed_minutes,
            vv.time_in,
            vv.expected_time_out,
            vv.date_created,
            UNIX_TIMESTAMP(COALESCE(vv.time_in, vv.date_created)) AS vv_time_in_ts,
            UNIX_TIMESTAMP(vv.expected_time_out) AS vv_expected_out_ts,
            (
                SELECT vl.id
                FROM vehicle_logs vl
                WHERE (vl.visitor_vehicle_id = vv.id OR REPLACE(UPPER(vl.plate_number), ' ', '') = REPLACE(UPPER(vv.plate_number), ' ', ''))
                  AND vl.vehicle_category = 'Visitor'
                  AND vl.date_created >= CURDATE()
                ORDER BY vl.id DESC
                LIMIT 1
            ) AS latest_log_id,
            (
                SELECT vl.direction
                FROM vehicle_logs vl
                WHERE (vl.visitor_vehicle_id = vv.id OR REPLACE(UPPER(vl.plate_number), ' ', '') = REPLACE(UPPER(vv.plate_number), ' ', ''))
                  AND vl.vehicle_category = 'Visitor'
                  AND vl.date_created >= CURDATE()
                ORDER BY vl.id DESC
                LIMIT 1
            ) AS latest_log_direction,
            (
                SELECT UNIX_TIMESTAMP(vl.date_created)
                FROM vehicle_logs vl
                WHERE (vl.visitor_vehicle_id = vv.id OR REPLACE(UPPER(vl.plate_number), ' ', '') = REPLACE(UPPER(vv.plate_number), ' ', ''))
                  AND vl.vehicle_category = 'Visitor'
                  AND vl.date_created >= CURDATE()
                ORDER BY vl.id DESC
                LIMIT 1
            ) AS latest_log_ts
        FROM visitor_vehicles vv
        WHERE vv.status = 'IN'
          AND (
              vv.date_created >= CURDATE()
              OR vv.date_in >= CURDATE()
              OR vv.time_in >= CURDATE()
          )
        ORDER BY vv.id DESC
    ";

    $res = @$conn->query($sql);
    if (!$res) {
        return [
            'server_now_ts'         => $serverNowTs,
            'active_timers'         => [],
            'overdue_visitors'      => [],
            'due_soon_visitors'     => [],
            'pending_info_visitors' => [],
        ];
    }

    $seenPlates = [];

    while ($row = $res->fetch_assoc()) {
        $plateNorm = strtoupper(str_replace(' ', '', trim((string)($row['plate_number'] ?? ''))));
        if ($plateNorm === '' || isset($seenPlates[$plateNorm])) {
            continue;
        }

        $vvTimeInTs = (int)($row['vv_time_in_ts'] ?? 0);
        $latestDir  = strtoupper(trim((string)($row['latest_log_direction'] ?? '')));
        $latestTs   = (int)($row['latest_log_ts'] ?? 0);

        // If latest gate log today is OUT at or after time_in, visitor has already departed
        if ($latestDir === 'OUT' && $latestTs > 0 && $latestTs >= ($vvTimeInTs - 2)) {
            continue;
        }

        $seenPlates[$plateNorm] = true;

        $timeInTs = $vvTimeInTs > 0 ? $vvTimeInTs : ($latestTs > 0 ? $latestTs : $serverNowTs);
        if ($latestDir === 'IN' && $latestTs > $timeInTs) {
            $timeInTs = $latestTs;
        }

        $rawAllowed = $row['allowed_minutes'] !== null ? (int)$row['allowed_minutes'] : null;
        $rawExpectedTs = $row['vv_expected_out_ts'] !== null ? (int)$row['vv_expected_out_ts'] : 0;

        // Detect if this is a returning vehicle whose expected_time_out belongs to an earlier visit today
        $hasStaleExpected = ($rawExpectedTs > 0 && $rawExpectedTs < $timeInTs);
        if ($hasStaleExpected) {
            $rawExpectedTs = 0;
        }

        $resolved = secsysResolveVisitorDuration(
            (string)($row['purpose'] ?? ''),
            ($rawAllowed !== null && $rawAllowed > 0 && !$hasStaleExpected) ? $rawAllowed : null
        );

        if ($resolved['is_pending'] && $rawExpectedTs <= 0) {
            $pendingInfoVisitors[] = [
                'id'                => (int)$row['id'],
                'log_id'            => (int)($row['latest_log_id'] ?? 0),
                'plate_number'      => (string)$row['plate_number'],
                'vehicle_type'      => (string)($row['vehicle_type'] ?: 'Car'),
                'purpose'           => (string)($row['purpose'] ?? ''),
                'time_in_ts'        => $timeInTs,
                'time_in_formatted' => date('h:i A', $timeInTs),
                'seconds_waiting'   => max(0, $serverNowTs - $timeInTs),
            ];
            continue;
        }

        $allowedMinutes = max(1, (int)$resolved['allowed_minutes']);
        $expectedOutTs = $rawExpectedTs > 0 ? $rawExpectedTs : ($timeInTs + ($allowedMinutes * 60));

        // Reconcile allowed_minutes if guard extended expected_time_out manually
        $effectiveDurationMins = max($allowedMinutes, (int)round(($expectedOutTs - $timeInTs) / 60));
        $secondsRemaining = $expectedOutTs - $serverNowTs;
        $eval = secsysEvaluateVisitorTimer($effectiveDurationMins, $secondsRemaining);

        $totalSeconds = max(1, $expectedOutTs - $timeInTs);
        $elapsedSeconds = max(0, $serverNowTs - $timeInTs);
        $progressPct = min(100, round(($elapsedSeconds / $totalSeconds) * 100, 1));

        $item = [
            'id'                          => (int)$row['id'],
            'log_id'                      => (int)($row['latest_log_id'] ?? 0),
            'visitor_name'                => (string)($row['visitor_name'] ?: 'Visitor'),
            'plate_number'                => (string)$row['plate_number'],
            'plate_norm'                  => $plateNorm,
            'vehicle_type'                => (string)($row['vehicle_type'] ?: 'Car'),
            'purpose'                     => $resolved['purpose_display'],
            'duration_label'              => ucwords(secsysFormatDuration($effectiveDurationMins)),
            'allowed_minutes'             => $effectiveDurationMins,
            'milestones'                  => $eval['milestones'],
            'active_milestone'            => $eval['active_milestone'],
            'state'                       => $eval['state'],
            'stage_key'                   => $eval['stage_key'],
            'time_in_ts'                  => $timeInTs,
            'expected_out_ts'             => $expectedOutTs,
            'time_in_formatted'           => date('h:i A', $timeInTs),
            'expected_time_out_formatted' => date('h:i A', $expectedOutTs),
            'seconds_remaining'           => $secondsRemaining,
            'minutes_left'                => $eval['minutes_left'],
            'minutes_overdue'             => $eval['minutes_overdue'],
            'seconds_overdue'             => $eval['seconds_overdue'],
            'progress_pct'                => $progressPct,
        ];

        $activeTimers[] = $item;
        if ($eval['state'] === 'OVERDUE') {
            $overdueVisitors[] = $item;
        } elseif ($eval['state'] === 'FINAL_WARNING' || $eval['state'] === 'REMINDER') {
            $dueSoonVisitors[] = $item;
        }
    }

    // Sort active timers so most urgent (lowest seconds_remaining) comes first
    usort($activeTimers, function ($a, $b) {
        return $a['seconds_remaining'] <=> $b['seconds_remaining'];
    });
    usort($overdueVisitors, function ($a, $b) {
        return $a['seconds_remaining'] <=> $b['seconds_remaining'];
    });
    usort($dueSoonVisitors, function ($a, $b) {
        return $a['seconds_remaining'] <=> $b['seconds_remaining'];
    });

    return [
        'server_now_ts'         => $serverNowTs,
        'active_timers'         => $activeTimers,
        'overdue_visitors'      => $overdueVisitors,
        'due_soon_visitors'     => $dueSoonVisitors,
        'pending_info_visitors' => $pendingInfoVisitors,
    ];
}
