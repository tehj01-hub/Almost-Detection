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
const SECSYS_VEHICLE_TYPES = ['Car', 'Motorcycle', 'Truck', 'Tricycle', 'E-Bike', 'Bike'];


/* Extra dashboard cameras added by the Administrator (shown on the
   admin and security guard dashboards). Stored as JSON in system_settings. */
const SECSYS_EXTRA_CAMERAS_KEY = 'dashboard_extra_cameras';
const SECSYS_MAX_EXTRA_CAMERAS = 12;

/**
 * Load the admin-added camera list straight from the DB (no settings cache,
 * no schema check) so it is cheap enough for the guard dashboard to poll.
 */
function secsysLoadExtraCameras(mysqli $conn): array
{
    $raw = '[]';
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        if ($stmt) {
            $key = SECSYS_EXTRA_CAMERAS_KEY;
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $raw = (string)$row['setting_value'];
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        return []; // table not created yet
    }

    $list = json_decode($raw, true);
    if (!is_array($list)) {
        return [];
    }
    $clean = [];
    foreach ($list as $cam) {
        if (!is_array($cam) || empty($cam['id']) || !preg_match('/^cam_[a-z0-9]{6,20}$/', (string)$cam['id'])) {
            continue;
        }
        $clean[] = [
            'id'        => (string)$cam['id'],
            'name'      => (string)($cam['name'] ?? 'Camera'),
            'direction' => (string)($cam['direction'] ?? 'MONITOR'),
            'model'     => (string)($cam['model'] ?? ''),
            'url'       => (string)($cam['url'] ?? ''),
        ];
    }
    return $clean;
}


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
 * Self-heal local XAMPP MariaDB/MySQL when it is stopped or hung on a
 * corrupted multi-master.info / relay-log file after an unclean shutdown.
 */
function secsysTryRepairAndStartXamppMysql(): bool
{
    $xamppDir = dirname(__DIR__, 3); // e.g. D:\xampp
    $mysqld   = $xamppDir . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqld.exe';
    $myIni    = $xamppDir . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'my.ini';
    $dataDir  = $xamppDir . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'data';

    if (!is_file($mysqld) || !is_dir($dataDir)) {
        return false;
    }

    // Stop any hung mysqld.exe instance that bound the port but is stuck before accepting handshakes
    @exec('taskkill /F /IM mysqld.exe >NUL 2>&1');
    usleep(500000);

    // Clean up corrupted replication metadata files (multi-master.info bug in XAMPP MariaDB 10.4)
    $multiMaster = $dataDir . DIRECTORY_SEPARATOR . 'multi-master.info';
    if (is_file($multiMaster) && @filesize($multiMaster) > 0) {
        @unlink($multiMaster);
    }
    foreach (['master*.info', 'relay-log*.info', 'mysql-relay-bin*', 'mysql.pid'] as $pattern) {
        $matches = @glob($dataDir . DIRECTORY_SEPARATOR . $pattern);
        if (is_array($matches)) {
            foreach ($matches as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    // Launch mysqld.exe in the background
    $cmd = 'start "" /B "' . $mysqld . '" --defaults-file="' . str_replace('\\', '/', $myIni) . '" --standalone >NUL 2>&1';
    @pclose(@popen($cmd, 'r'));

    return true;
}

/**
 * Connect to the security_system MySQL database.
 * Automatically tries XAMPP port 3307 and 3306 (or honors SECSYS_DB_*
 * environment variables) and self-heals a stopped/hung XAMPP MySQL service.
 */
function secsysConnectDb(bool $allowFailure = false): ?mysqli
{
    $envHost = getenv('SECSYS_DB_HOST');
    $envPort = getenv('SECSYS_DB_PORT');
    $envUser = getenv('SECSYS_DB_USER');
    $envPass = getenv('SECSYS_DB_PASS');
    $envName = getenv('SECSYS_DB_NAME');

    $hosts = ($envHost !== false && trim($envHost) !== '')
        ? [trim($envHost)]
        : ['127.0.0.1', 'localhost'];

    $ports = ($envPort !== false && trim($envPort) !== '')
        ? [(int)$envPort]
        : [3307, 3306];

    $user = ($envUser !== false && $envUser !== '') ? $envUser : 'root';
    $pass = ($envPass !== false) ? $envPass : '';
    $db   = ($envName !== false && trim($envName) !== '') ? trim($envName) : 'security_system';

    $lastError = 'Unable to connect to MySQL.';

    /* Try the host/port that worked last time first. */
    $candidates = [];
    foreach ($hosts as $host) {
        foreach ($ports as $port) {
            $candidates[] = [$host, $port];
        }
    }
    $cacheFile = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'secsys_db_endpoint_' . md5($user . '|' . $db) . '.txt';
    $cached = @file_get_contents($cacheFile);
    if (is_string($cached) && preg_match('/^(.+):(\d+)$/', trim($cached), $m)) {
        foreach ($candidates as $i => $candidate) {
            if ($candidate[0] === $m[1] && $candidate[1] === (int)$m[2]) {
                unset($candidates[$i]);
                array_unshift($candidates, $candidate);
                break;
            }
        }
    }

    $tryConnect = function (array $list, int $timeoutSec = 2) use ($user, $pass, $db, $cacheFile, $cached, &$lastError): ?mysqli {
        foreach ($list as [$host, $port]) {
            try {
                $conn = mysqli_init();
                if ($conn === false) {
                    continue;
                }
                $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeoutSec);
                if (@$conn->real_connect($host, $user, $pass, $db, $port)) {
                    $conn->set_charset('utf8mb4');
                    if (trim((string)$cached) !== $host . ':' . $port) {
                        @file_put_contents($cacheFile, $host . ':' . $port, LOCK_EX);
                    }
                    return $conn;
                }
                $lastError = $conn->connect_error ?: $lastError;
            } catch (mysqli_sql_exception $e) {
                $lastError = $e->getMessage();
            }
        }
        return null;
    };

    // Fast pass on 127.0.0.1 first
    $primaryCandidates = array_values(array_filter($candidates, function ($c) {
        return $c[0] === '127.0.0.1';
    }));
    if (empty($primaryCandidates)) {
        $primaryCandidates = $candidates;
    }

    $conn = $tryConnect($primaryCandidates, 2);
    if ($conn instanceof mysqli) {
        return $conn;
    }

    // Attempt to self-heal and start XAMPP MySQL if it is stopped or hung on multi-master.info
    if (secsysTryRepairAndStartXamppMysql()) {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            usleep(1000000); // wait 1s per attempt (up to 6s) for InnoDB startup
            $conn = $tryConnect($primaryCandidates, 2);
            if ($conn instanceof mysqli) {
                return $conn;
            }
        }
    }

    // Final fallback on any remaining candidates
    $conn = $tryConnect($candidates, 2);
    if ($conn instanceof mysqli) {
        return $conn;
    }

    if ($allowFailure) {
        return null;
    }

    die('Database Connection Failed: ' . htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8'));
}


/**
 * Normalize any vehicle type string to one of SECSYS_VEHICLE_TYPES.
 * Unknown values fall back to 'Car'.
 */
function secsysNormalizeVehicleType($value): string
{
    $value = strtolower(trim((string)$value));

    foreach (SECSYS_VEHICLE_TYPES as $type) {
        if (strtolower($type) === $value) {
            return $type;
        }
    }

    if (strpos($value, 'ebike') !== false || strpos($value, 'e-bike') !== false) {
        return 'E-Bike';
    }
    if (strpos($value, 'tricycle') !== false || strpos($value, 'trike') !== false) {
        return 'Tricycle';
    }
    if (strpos($value, 'motor') !== false || strpos($value, 'scooter') !== false) {
        return 'Motorcycle';
    }
    if (strpos($value, 'bike') !== false || strpos($value, 'bicycle') !== false) {
        return 'Bike';
    }
    if (strpos($value, 'truck') !== false || strpos($value, 'lorry') !== false || strpos($value, 'pickup') !== false) {
        return 'Truck';
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
       1. Keep every vehicle_type ENUM column in sync with SECSYS_VEHICLE_TYPES.
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

        $missingType = false;
        foreach (SECSYS_VEHICLE_TYPES as $vt) {
            if (strpos($type, "'" . strtolower($vt) . "'") === false) {
                $missingType = true;
                break;
            }
        }

        if (strpos($type, 'enum(') === 0 && $missingType) {
            $conn->query(
                "ALTER TABLE `$table`
                 MODIFY vehicle_type ENUM($enumList) NOT NULL"
            );
        }
    }

    /* -------------------------------------------------------------
       2. Visitor time allowance & identification columns.
    ------------------------------------------------------------- */

    $visitorColumns = [
        'allowed_minutes'   => "INT DEFAULT NULL AFTER purpose",
        'expected_time_out' => "DATETIME DEFAULT NULL AFTER time_in",
        'id_number'         => "VARCHAR(100) DEFAULT NULL AFTER id_type",
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

    $checkVvaIdNum = $conn->query("SHOW COLUMNS FROM visitor_vehicles_archive LIKE 'id_number'");
    if ($checkVvaIdNum && $checkVvaIdNum->num_rows === 0) {
        $conn->query("ALTER TABLE visitor_vehicles_archive ADD COLUMN id_number VARCHAR(100) DEFAULT NULL AFTER id_type");
    }

    $checkVlIdType = $conn->query("SHOW COLUMNS FROM vehicle_logs LIKE 'id_type'");
    if ($checkVlIdType && $checkVlIdType->num_rows === 0) {
        $conn->query("ALTER TABLE vehicle_logs ADD COLUMN id_type VARCHAR(100) DEFAULT NULL AFTER visitor_name");
    }
    $checkVlIdNum = $conn->query("SHOW COLUMNS FROM vehicle_logs LIKE 'id_number'");
    if ($checkVlIdNum && $checkVlIdNum->num_rows === 0) {
        $conn->query("ALTER TABLE vehicle_logs ADD COLUMN id_number VARCHAR(100) DEFAULT NULL AFTER id_type");
    }

    $checkVlaIdType = $conn->query("SHOW COLUMNS FROM vehicle_logs_archive LIKE 'id_type'");
    if ($checkVlaIdType && $checkVlaIdType->num_rows === 0) {
        $conn->query("ALTER TABLE vehicle_logs_archive ADD COLUMN id_type VARCHAR(100) DEFAULT NULL AFTER visitor_name");
    }
    $checkVlaIdNum = $conn->query("SHOW COLUMNS FROM vehicle_logs_archive LIKE 'id_number'");
    if ($checkVlaIdNum && $checkVlaIdNum->num_rows === 0) {
        $conn->query("ALTER TABLE vehicle_logs_archive ADD COLUMN id_number VARCHAR(100) DEFAULT NULL AFTER id_type");
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
       5a. Security contacts table and columns.
    ------------------------------------------------------------- */
    $conn->query("
        CREATE TABLE IF NOT EXISTS security_contacts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contact_name VARCHAR(150) NOT NULL,
            position VARCHAR(100) DEFAULT '',
            contact_number VARCHAR(50) DEFAULT '',
            email VARCHAR(150) DEFAULT '',
            address VARCHAR(255) DEFAULT '',
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $scColsRes = $conn->query("SHOW COLUMNS FROM security_contacts");
    $scExisting = [];
    if ($scColsRes) {
        while ($sc = $scColsRes->fetch_assoc()) {
            $scExisting[strtolower($sc['Field'])] = true;
        }
        $scColsRes->close();
    }
    if (!isset($scExisting['position'])) {
        $conn->query("ALTER TABLE security_contacts ADD COLUMN `position` VARCHAR(100) DEFAULT '' AFTER contact_name");
    }
    if (!isset($scExisting['contact_number'])) {
        $conn->query("ALTER TABLE security_contacts ADD COLUMN `contact_number` VARCHAR(50) DEFAULT '' AFTER contact_name");
    }
    if (!isset($scExisting['email'])) {
        $conn->query("ALTER TABLE security_contacts ADD COLUMN `email` VARCHAR(150) DEFAULT ''");
    }
    if (!isset($scExisting['address'])) {
        $conn->query("ALTER TABLE security_contacts ADD COLUMN `address` VARCHAR(255) DEFAULT ''");
    }
    if (!isset($scExisting['status'])) {
        $conn->query("ALTER TABLE security_contacts ADD COLUMN `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'");
    }

    /* -------------------------------------------------------------
       5b. Forgot password: administrators' contact number (guards
           use security_guard.contact_number) and failed-attempt log
           used to slow down guessing.
    ------------------------------------------------------------- */
    // YOLOv10 vehicle photo taken when the plate was read (path under uploads/vehicle_captures/)
    foreach (['vehicle_logs', 'vehicle_logs_archive'] as $photoTable) {
        $checkPhoto = $conn->query("SHOW COLUMNS FROM `$photoTable` LIKE 'vehicle_image'");
        if ($checkPhoto && $checkPhoto->num_rows === 0) {
            $conn->query("ALTER TABLE `$photoTable` ADD COLUMN vehicle_image VARCHAR(255) DEFAULT NULL AFTER date_created");
        }
    }

    $checkUserContact = $conn->query("SHOW COLUMNS FROM users LIKE 'contact_number'");
    if ($checkUserContact && $checkUserContact->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN contact_number VARCHAR(30) DEFAULT NULL AFTER full_name");
    }
    $conn->query("
        CREATE TABLE IF NOT EXISTS password_reset_attempts (
            id INT NOT NULL AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            succeeded TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_reset_user_time (username, attempted_at),
            KEY idx_reset_ip_time (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    /* -------------------------------------------------------------
       6. Daily 12:00 AM Midnight Log Reset (auto-archive past logs).
    ------------------------------------------------------------- */
    secsysAutoArchivePastLogs($conn);

    /* -------------------------------------------------------------
       7. Weekly automatic database backup + 6-month retention.
    ------------------------------------------------------------- */
    secsysMaybeRunAutoBackup($conn);
}


/**
 * Every column a vehicle log has. Archiving copies all of them and
 * unarchiving puts all of them back, so nothing is lost either way.
 */
const SECSYS_VEHICLE_LOG_COLUMNS = 'id, plate_number, vehicle_type, vehicle_category,
    registered_vehicle_id, visitor_vehicle_id, visitor_name,
    id_type, id_number, purpose, allowed_minutes, expected_time_out,
    direction, recognition_method, date_in, date_out, date_created, vehicle_image';

/**
 * Can this archived row go back to the active logs? The active logs only
 * hold today's records (past days are archived automatically at 12:00 AM),
 * so only logs from today can be unarchived.
 */
function secsysArchivedLogIsToday(array $row): bool
{
    foreach (['time_in', 'time_out', 'date_created', 'log_date', 'date'] as $key) {
        if (!empty($row[$key]) && ($ts = strtotime((string)$row[$key])) !== false) {
            return date('Y-m-d', $ts) === date('Y-m-d');
        }
    }
    return false;
}

/**
 * Move archived logs back into the active logs (vehicle_logs).
 * $ids: archive log ids (one visit = its IN and OUT ids).
 * $category: 'Visitor' / 'Registered' to limit a page to its own logs, or null.
 * Logs from past days are left in the archive (see secsysArchivedLogIsToday).
 * Returns ['restored' => int, 'kept_past' => int, 'error' => ?string].
 */
function secsysUnarchiveLogs(mysqli $conn, array $ids, string $actorId, ?string $category = null): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
        return $id > 0;
    })));
    $result = ['restored' => 0, 'kept_past' => 0, 'error' => null];
    if (!$ids) {
        return $result;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $categorySql = $category !== null ? ' AND vehicle_category = ?' : '';
    $params = $ids;
    $types = str_repeat('i', count($ids));
    if ($category !== null) {
        $params[] = $category;
        $types .= 's';
    }

    $conn->begin_transaction();
    try {
        // Split the selection into today's logs (can return) and past days' logs (stay archived)
        $todayIds = [];
        $stmt = $conn->prepare("
            SELECT id, DATE(date_created) = CURDATE() AS is_today
            FROM vehicle_logs_archive
            WHERE id IN ($placeholders) $categorySql
            FOR UPDATE
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if ((int)$row['is_today'] === 1) {
                $todayIds[] = (int)$row['id'];
            } else {
                $result['kept_past']++;
            }
        }
        $stmt->close();

        if ($todayIds) {
            $todayPlaceholders = implode(',', array_fill(0, count($todayIds), '?'));
            $todayTypes = str_repeat('i', count($todayIds));

            // Same id is kept, so links from visit records still point at it
            $stmt = $conn->prepare("
                INSERT IGNORE INTO vehicle_logs (" . SECSYS_VEHICLE_LOG_COLUMNS . ")
                SELECT " . SECSYS_VEHICLE_LOG_COLUMNS . "
                FROM vehicle_logs_archive
                WHERE id IN ($todayPlaceholders)
            ");
            $stmt->bind_param($todayTypes, ...$todayIds);
            $stmt->execute();
            $stmt->close();

            // Remove from the archive only what is now in the active logs
            $stmt = $conn->prepare("
                DELETE FROM vehicle_logs_archive
                WHERE id IN ($todayPlaceholders)
                  AND id IN (SELECT id FROM vehicle_logs WHERE id IN ($todayPlaceholders))
            ");
            $stmt->bind_param($todayTypes . $todayTypes, ...array_merge($todayIds, $todayIds));
            $stmt->execute();
            $result['restored'] = $stmt->affected_rows;
            $stmt->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $result['error'] = 'Unable to unarchive the selected logs.';
        return $result;
    }

    if ($result['restored'] > 0) {
        $kind = $category !== null ? strtolower($category) . ' vehicle' : 'vehicle';
        secsysLogActivity($conn, $actorId, 'Unarchive', "Restored " . $result['restored'] . " $kind log record(s) from the archive.");
    }
    return $result;
}

/**
 * Vehicle photo path for a pending visitor (the detection server saves it as
 * uploads/vehicle_captures/<pending id>.jpg when the plate is read).
 */
function secsysPendingVehiclePhotoPath(string $pendingId): ?string
{
    return preg_match('/^pv_\d{10,16}_[a-f0-9]{6}$/', $pendingId)
        ? 'uploads/vehicle_captures/' . $pendingId . '.jpg'
        : null;
}

/**
 * Inject the YOLOv10 plate & vehicle capture modal script once per page request.
 */
function secsysYoloCaptureModalAssetsHtml(): string
{
    static $injected = false;
    if ($injected) {
        return '';
    }
    $injected = true;
    // Version by file time so browsers pick up changes; the stylesheet loads
    // with the page so plate numbers are styled before the first click
    $cssVer = (int)@filemtime(__DIR__ . '/../assets/css/plate_link.css');
    $jsVer = (int)@filemtime(__DIR__ . '/../assets/js/yolo_capture_modal.js');
    return '<link rel="stylesheet" href="assets/css/plate_link.css?v=' . $cssVer . '">'
        . '<script src="assets/js/yolo_capture_modal.js?v=' . $jsVer . '"></script>';
}

/**
 * Render a clickable license plate badge that opens the YOLOv10 captured
 * license plate crop, vehicle image, plate number, and vehicle details.
 *
 * @param array|string $plateOrRow Either a log/vehicle row array or a plate string.
 * @param array        $extraRow   Optional row array when $plateOrRow is a string.
 * @param string       $className  Optional extra CSS class(es).
 * @param string       $style      Optional inline CSS style.
 */
function secsysClickablePlateHtml($plateOrRow, array $extraRow = [], string $className = '', string $style = ''): string
{
    $row = is_array($plateOrRow) ? $plateOrRow : $extraRow;
    $plate = is_array($plateOrRow)
        ? trim((string)($plateOrRow['plate_number'] ?? ''))
        : trim((string)$plateOrRow);

    if ($plate === '' || $plate === '-' || $plate === '—') {
        return htmlspecialchars($plate !== '' ? $plate : '—', ENT_QUOTES, 'UTF-8');
    }

    $rawIds = [];
    if (!empty($row['ids']) && is_array($row['ids'])) {
        $rawIds = $row['ids'];
    } elseif (!empty($row['log_id'])) {
        $rawIds = [(int)$row['log_id']];
    } elseif (!empty($row['id']) && isset($row['time_in'])) {
        $rawIds = [(int)$row['id']];
    }
    $ids = array_values(array_filter(array_map('intval', $rawIds)));
    $idsStr = implode(',', $ids);

    $href = 'vehicle_photo.php?plate=' . rawurlencode($plate);
    if ($idsStr !== '') {
        $href .= '&ids=' . rawurlencode($idsStr);
    }

    $classes = trim('secsys-plate-link ' . $className);
    $escPlate = htmlspecialchars($plate, ENT_QUOTES, 'UTF-8');
    $escIds = htmlspecialchars($idsStr, ENT_QUOTES, 'UTF-8');
    $escHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
    $styleAttr = $style !== '' ? ' style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"' : '';

    return secsysYoloCaptureModalAssetsHtml()
        . '<a href="' . $escHref . '"'
        . ' class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-plate="' . $escPlate . '"'
        . ' data-ids="' . $escIds . '"'
        . $styleAttr
        . ' onclick="return window.secsysOpenPlateCaptureModal ? window.secsysOpenPlateCaptureModal(event, this) : true;"'
        . ' title="Click to view YOLOv10 captured license plate &amp; vehicle image">'
        . $escPlate
        . '</a>';
}

/**
 * Legacy helper kept for compatibility; license plates themselves are now clickable
 * via secsysClickablePlateHtml().
 */
function secsysVehiclePhotoLinkHtml(array $row): string
{
    return '';
}

/**
 * "Unarchive" button for one archived row (a visit = its IN/OUT log ids).
 * Posts action=unarchive_selected to $page. Disabled for past days' logs.
 */
function secsysUnarchiveButtonHtml(string $page, array $row): string
{
    $ids = array_values(array_filter(array_map('intval', (array)($row['ids'] ?? []))));
    $canRestore = $ids && secsysArchivedLogIsToday($row);
    $style = 'display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:8px; '
        . 'font-size:11px; font-weight:700; white-space:nowrap; border:1px solid '
        . ($canRestore ? '#93c5fd; background:#eff6ff; color:#1d4ed8; cursor:pointer;' : '#e2e8f0; background:#f8fafc; color:#94a3b8; cursor:not-allowed;');
    $title = $canRestore
        ? 'Move this log back to the active list'
        : 'Only logs from today can be unarchived. Past days stay in the archive because the active list only shows today.';

    return '<form method="POST" action="' . htmlspecialchars($page, ENT_QUOTES) . '" style="margin:0;"'
        . ' onsubmit="return confirm(\'Move this log back to the active list?\');">'
        . '<input type="hidden" name="action" value="unarchive_selected">'
        . '<input type="hidden" name="selected_logs[]" value="' . htmlspecialchars(implode(',', $ids), ENT_QUOTES) . '">'
        . '<button type="submit" style="' . $style . '" title="' . htmlspecialchars($title, ENT_QUOTES) . '"' . ($canRestore ? '' : ' disabled') . '>'
        . '&#8617; Unarchive</button></form>';
}

/** Collect log ids from posted selected_logs[] groups ("12,13"). */
function secsysPostedLogIds(): array
{
    $ids = [];
    foreach ((array)($_POST['selected_logs'] ?? []) as $group) {
        foreach (explode(',', (string)$group) as $id) {
            if ((int)$id > 0) {
                $ids[] = (int)$id;
            }
        }
    }
    return array_values(array_unique($ids));
}

/** Notice shown after an unarchive (reads ?unarchived=N&kept=M). Empty string if none. */
function secsysUnarchiveNoticeHtml(string $page, string $logKind): string
{
    if (!isset($_GET['unarchived'])) {
        return '';
    }
    $close = '<a href="' . htmlspecialchars($page, ENT_QUOTES) . '" style="text-decoration:none; color:inherit; font-weight:bold;">&times;</a>';
    $value = (string)$_GET['unarchived'];
    $kept = (int)($_GET['kept'] ?? 0);

    if ($value === 'error') {
        return '<div class="notice error"><span>&#9888; An error occurred while unarchiving the logs. Please try again.</span>' . $close . '</div>';
    }
    $restored = (int)$value;
    if ($restored > 0) {
        $text = '&#10004; ' . $restored . ' archived ' . htmlspecialchars($logKind) . ' log record(s) were restored to the active list.';
        if ($kept > 0) {
            $text .= ' ' . $kept . ' record(s) from past days stay in the archive.';
        }
        return '<div class="notice success"><span>' . $text . '</span>' . $close . '</div>';
    }
    return '<div class="notice info"><span>&#9888; Nothing was unarchived. Only logs from today can return to the active list; past days stay in the archive.</span>' . $close . '</div>';
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
                id_type, id_number,
                purpose, allowed_minutes, expected_time_out, direction, recognition_method,
                date_in, date_out, date_created, vehicle_image, archived_at, archived_by
            )
            SELECT
                id, plate_number, vehicle_type, vehicle_category,
                registered_vehicle_id, visitor_vehicle_id, visitor_name,
                id_type, id_number,
                purpose, allowed_minutes, expected_time_out, direction, recognition_method,
                date_in, date_out, date_created, vehicle_image, NOW(), 'System (12:00 AM Reset)'
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

/**
 * SQL column "still_inside" (1/0) for a vehicle_logs row aliased $alias:
 * 1 when it is an entry (IN) whose vehicle has not left yet, i.e. its visit
 * has no time out and no later exit (OUT) was logged for the same plate.
 * Dashboards show these rows in yellow ("INSIDE").
 */
function secsysStillInsideSql(string $alias = 'vl'): string
{
    $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
    return "CASE WHEN {$a}.direction = 'IN'
            AND NOT EXISTS (
                SELECT 1 FROM vehicle_log_dates si_d
                WHERE si_d.vehicle_log_id = {$a}.id AND si_d.time_out IS NOT NULL
            )
            AND NOT EXISTS (
                SELECT 1 FROM vehicle_logs si_o
                WHERE si_o.direction = 'OUT' AND si_o.id > {$a}.id
                  AND REPLACE(UPPER(si_o.plate_number), ' ', '') = REPLACE(UPPER({$a}.plate_number), ' ', '')
            )
        THEN 1 ELSE 0 END AS still_inside";
}

/**
 * This PC's address on the network, so other devices (guard PCs, phones)
 * know what to type. Found from the connection the PC uses to reach the
 * internet, so it stays correct when the router hands out a new IP.
 * Returns e.g. ['ip' => '192.168.1.31', 'url' => 'http://192.168.1.31/Ultra/'],
 * or null when the PC is not on a network.
 */
function secsysNetworkAccessInfo(): ?array
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }

    $isUsable = function ($ip) {
        return is_string($ip)
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && strpos($ip, '127.') !== 0
            && strpos($ip, '169.254.') !== 0;   // no address from the router
    };

    $ip = null;
    // 1. The interface Windows uses for the internet. A UDP "connect" only picks
    //    the route; no packet is sent.
    $probe = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
    if ($probe) {
        $local = (string)@stream_socket_get_name($probe, false);
        fclose($probe);
        $candidate = preg_replace('/:\d+$/', '', $local);
        if ($isUsable($candidate)) {
            $ip = $candidate;
        }
    }
    // 2. Offline LAN: first home/office network address of this PC
    if ($ip === null) {
        foreach ((array)@gethostbynamel(gethostname()) as $candidate) {
            if ($isUsable($candidate) && preg_match('/^(192\.168\.|10\.)/', $candidate)) {
                $ip = $candidate;
                break;
            }
        }
    }
    if ($ip === null) {
        return $cached = null;
    }

    $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $defaultPort = $https ? 443 : 80;
    $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

    return $cached = [
        'ip'  => $ip,
        'url' => $scheme . '://' . $ip . ($port !== $defaultPort ? ':' . $port : '') . $basePath . '/',
    ];
}

/**
 * Small "open on other devices" box with the network address and a Copy button.
 * $compact = true gives a one-line chip for dashboards.
 */
function secsysNetworkAccessHtml(bool $compact = false): string
{
    $info = secsysNetworkAccessInfo();
    if ($info === null) {
        return '';
    }
    $url = htmlspecialchars($info['url'], ENT_QUOTES, 'UTF-8');
    $copy = '<button type="button" onclick="(function(b){var t=' . htmlspecialchars(json_encode($info['url']), ENT_QUOTES, 'UTF-8') . ';'
        . 'var done=function(){b.textContent=\'Copied!\';setTimeout(function(){b.textContent=\'Copy\';},1500);};'
        . 'if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(done);}'
        . 'else{var a=document.createElement(\'textarea\');a.value=t;document.body.appendChild(a);a.select();document.execCommand(\'copy\');a.remove();done();}'
        . '})(this)" style="border:1px solid #93c5fd; background:#fff; color:#1d4ed8; border-radius:999px; padding:2px 10px; font-size:12px; font-weight:600; cursor:pointer; width:auto; height:auto; margin:0;">Copy</button>';

    if ($compact) {
        return '<span title="Type this address in a browser on another device connected to the same Wi-Fi/network"'
            . ' style="display:inline-flex; align-items:center; gap:6px; background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a;'
            . ' border-radius:999px; padding:3px 4px 3px 10px; font-size:12px; font-weight:600;">'
            . '&#127760; Network address: <a href="' . $url . '" style="color:#1d4ed8;">' . $url . '</a> ' . $copy . '</span>';
    }
    return '<div style="margin-top:16px; padding:10px 12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; font-size:13px; color:#1e3a8a; text-align:center;">'
        . '&#127760; To open this system on another device on the same Wi-Fi/network, go to:<br>'
        . '<a href="' . $url . '" style="font-weight:700; color:#1d4ed8; font-size:15px;">' . $url . '</a> ' . $copy
        . '</div>';
}

/**
 * The signed-in administrator, so several admins can be told apart.
 * Returns ['username' => ..., 'name' => full name (or username if blank)].
 * Read fresh from the users table, so a name changed in Profile shows at once.
 */
function secsysCurrentAdmin(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $username = trim((string)($_SESSION['username'] ?? ''));
    $cached = ['username' => $username, 'name' => $username !== '' ? $username : 'Administrator'];
    if ($username === '') {
        return $cached;
    }

    try {
        $conn = secsysConnectDb(true);
        if ($conn) {
            $stmt = $conn->prepare("SELECT full_name FROM users WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $fullName = trim((string)($row['full_name'] ?? ''));
                if ($fullName !== '') {
                    $cached['name'] = $fullName;
                }
            }
            $conn->close();
        }
    } catch (Throwable $e) {
        // Keep the username if the lookup fails
    }
    return $cached;
}

/** "Administrator P (adminp)" - used in reports to show who prepared them. */
function secsysCurrentAdminLabel(): string
{
    $admin = secsysCurrentAdmin();
    return $admin['name'] !== $admin['username'] && $admin['username'] !== ''
        ? $admin['name'] . ' (' . $admin['username'] . ')'
        : $admin['name'];
}

/** Sidebar text under the system name: the admin's name, with the role below it. */
function secsysAdminSidebarSubtitleHtml(): string
{
    $admin = secsysCurrentAdmin();
    $name = htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars('Signed in as ' . secsysCurrentAdminLabel() . ' (Administrator)', ENT_QUOTES, 'UTF-8');

    return '<span title="' . $title . '" style="display:block; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:700; color:#1e293b;">' . $name . '</span>'
        . '<span style="display:block; font-size:0.85em; opacity:0.8;">Administrator</span>';
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
 * Ensure the Security Office and Subdivision Admin Office contact rows exist
 * in security_contacts with valid contact numbers synced with system_settings.
 */
function secsysEnsureSecurityContacts(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS security_contacts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            contact_name VARCHAR(150) NOT NULL,
            position VARCHAR(100) DEFAULT '',
            contact_number VARCHAR(50) DEFAULT '',
            email VARCHAR(150) DEFAULT '',
            address VARCHAR(255) DEFAULT '',
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $scColsRes = $conn->query("SHOW COLUMNS FROM security_contacts");
    $existingCols = [];
    if ($scColsRes) {
        while ($sc = $scColsRes->fetch_assoc()) {
            $existingCols[strtolower($sc['Field'])] = true;
        }
        $scColsRes->close();
    }
    if (!isset($existingCols['position'])) {
        $conn->query("ALTER TABLE security_contacts ADD COLUMN `position` VARCHAR(100) DEFAULT '' AFTER contact_name");
        $existingCols['position'] = true;
    }
    if (!isset($existingCols['contact_number'])) {
        $conn->query("ALTER TABLE security_contacts ADD COLUMN `contact_number` VARCHAR(50) DEFAULT '' AFTER contact_name");
        $existingCols['contact_number'] = true;
    }
    if (!isset($existingCols['email'])) {
        $conn->query("ALTER TABLE security_contacts ADD COLUMN `email` VARCHAR(150) DEFAULT ''");
        $existingCols['email'] = true;
    }
    if (!isset($existingCols['address'])) {
        $conn->query("ALTER TABLE security_contacts ADD COLUMN `address` VARCHAR(255) DEFAULT ''");
        $existingCols['address'] = true;
    }
    if (!isset($existingCols['status'])) {
        $conn->query("ALTER TABLE security_contacts ADD COLUMN `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'");
        $existingCols['status'] = true;
    }

    $hasPosition = isset($existingCols['position']);
    $hasStatus   = isset($existingCols['status']);

    $defaults = [
        [
            'name'        => 'Security Office',
            'setting_key' => 'security_office_number',
            'position'    => '24/7 Main Gate Security',
            'default_num' => '0917-123-4567',
        ],
        [
            'name'        => 'Subdivision Admin Office',
            'setting_key' => 'subdivision_admin_office_number',
            'position'    => 'Subdivision Administration',
            'default_num' => '0918-765-4321',
        ],
    ];

    foreach ($defaults as $item) {
        $name       = $item['name'];
        $settingKey = $item['setting_key'];
        $position   = $item['position'];
        $defaultNum = $item['default_num'];

        $settingVal = trim(secsysGetSetting($conn, $settingKey, ''));

        $selectFields = "id, contact_number";
        if ($hasPosition) {
            $selectFields .= ", position";
        }
        if ($hasStatus) {
            $selectFields .= ", status";
        }

        $stmt = $conn->prepare("
            SELECT $selectFields
            FROM security_contacts
            WHERE LOWER(TRIM(contact_name)) = LOWER(?)
            ORDER BY id ASC
            LIMIT 1
        ");
        $row = null;
        if ($stmt) {
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if ($row) {
            $rowId  = (int)$row['id'];
            $rowNum = trim((string)($row['contact_number'] ?? ''));
            $rowPos = $hasPosition ? trim((string)($row['position'] ?? '')) : '';
            $rowSt  = $hasStatus ? ($row['status'] ?? '') : 'Active';

            $finalNum = $settingVal !== '' ? $settingVal : ($rowNum !== '' ? $rowNum : $defaultNum);
            $finalPos = $rowPos !== '' ? $rowPos : $position;

            if ($settingVal === '') {
                secsysSetSetting($conn, $settingKey, $finalNum);
            }

            if ($rowNum !== $finalNum || ($hasPosition && $rowPos !== $finalPos) || ($hasStatus && $rowSt !== 'Active')) {
                if ($hasPosition && $hasStatus) {
                    $up = $conn->prepare("
                        UPDATE security_contacts
                        SET contact_name = ?, contact_number = ?, position = ?, status = 'Active'
                        WHERE id = ?
                    ");
                    if ($up) {
                        $up->bind_param("sssi", $name, $finalNum, $finalPos, $rowId);
                        $up->execute();
                        $up->close();
                    }
                } elseif ($hasPosition) {
                    $up = $conn->prepare("
                        UPDATE security_contacts
                        SET contact_name = ?, contact_number = ?, position = ?
                        WHERE id = ?
                    ");
                    if ($up) {
                        $up->bind_param("sssi", $name, $finalNum, $finalPos, $rowId);
                        $up->execute();
                        $up->close();
                    }
                } elseif ($hasStatus) {
                    $up = $conn->prepare("
                        UPDATE security_contacts
                        SET contact_name = ?, contact_number = ?, status = 'Active'
                        WHERE id = ?
                    ");
                    if ($up) {
                        $up->bind_param("ssi", $name, $finalNum, $rowId);
                        $up->execute();
                        $up->close();
                    }
                } else {
                    $up = $conn->prepare("
                        UPDATE security_contacts
                        SET contact_name = ?, contact_number = ?
                        WHERE id = ?
                    ");
                    if ($up) {
                        $up->bind_param("ssi", $name, $finalNum, $rowId);
                        $up->execute();
                        $up->close();
                    }
                }
            }
        } else {
            $finalNum = $settingVal !== '' ? $settingVal : $defaultNum;
            if ($settingVal === '') {
                secsysSetSetting($conn, $settingKey, $finalNum);
            }

            if ($hasPosition && $hasStatus) {
                $ins = $conn->prepare("
                    INSERT INTO security_contacts (contact_name, position, contact_number, status)
                    VALUES (?, ?, ?, 'Active')
                ");
                if ($ins) {
                    $ins->bind_param("sss", $name, $position, $finalNum);
                    $ins->execute();
                    $ins->close();
                }
            } elseif ($hasPosition) {
                $ins = $conn->prepare("
                    INSERT INTO security_contacts (contact_name, position, contact_number)
                    VALUES (?, ?, ?)
                ");
                if ($ins) {
                    $ins->bind_param("sss", $name, $position, $finalNum);
                    $ins->execute();
                    $ins->close();
                }
            } elseif ($hasStatus) {
                $ins = $conn->prepare("
                    INSERT INTO security_contacts (contact_name, contact_number, status)
                    VALUES (?, ?, 'Active')
                ");
                if ($ins) {
                    $ins->bind_param("ss", $name, $finalNum);
                    $ins->execute();
                    $ins->close();
                }
            } else {
                $ins = $conn->prepare("
                    INSERT INTO security_contacts (contact_name, contact_number)
                    VALUES (?, ?)
                ");
                if ($ins) {
                    $ins->bind_param("ss", $name, $finalNum);
                    $ins->execute();
                    $ins->close();
                }
            }
        }
    }
}

function secsysGetSecurityOfficeNumber(mysqli $conn): string
{
    secsysEnsureSecurityContacts($conn);
    $val = trim(secsysGetSetting($conn, 'security_office_number', '0917-123-4567'));
    return $val !== '' ? $val : '0917-123-4567';
}

function secsysGetSubdivisionAdminOfficeNumber(mysqli $conn): string
{
    secsysEnsureSecurityContacts($conn);
    $val = trim(secsysGetSetting($conn, 'subdivision_admin_office_number', '0918-765-4321'));
    return $val !== '' ? $val : '0918-765-4321';
}

function secsysUpdateSecurityOfficeContacts(mysqli $conn, string $securityOfficeNumber, string $subdivisionAdminNumber): void
{
    secsysEnsureSecurityContacts($conn);

    $secNum = trim($securityOfficeNumber);
    $admNum = trim($subdivisionAdminNumber);
    if ($secNum === '') {
        $secNum = '0917-123-4567';
    }
    if ($admNum === '') {
        $admNum = '0918-765-4321';
    }

    secsysSetSetting($conn, 'security_office_number', $secNum);
    secsysSetSetting($conn, 'subdivision_admin_office_number', $admNum);

    $colCheck = $conn->query("SHOW COLUMNS FROM security_contacts LIKE 'status'");
    $hasStatus = ($colCheck && $colCheck->num_rows > 0);

    $pairs = [
        'Security Office'          => $secNum,
        'Subdivision Admin Office' => $admNum,
    ];
    foreach ($pairs as $officeName => $numVal) {
        $sql = $hasStatus
            ? "UPDATE security_contacts SET contact_number = ?, status = 'Active' WHERE LOWER(TRIM(contact_name)) = LOWER(?)"
            : "UPDATE security_contacts SET contact_number = ? WHERE LOWER(TRIM(contact_name)) = LOWER(?)";
        $up = $conn->prepare($sql);
        if ($up) {
            $up->bind_param("ss", $numVal, $officeName);
            $up->execute();
            $up->close();
        }
    }
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

/* =====================================================================
   PENDING VISITOR ARRIVAL QUEUE (PYTHON LPR <-> GUARD MODAL)
   ---------------------------------------------------------------------
   Holds newly detected Visitor IN plates outside MySQL until the guard
   submits the Visitor Information Input form. Cancelling the form marks
   the pending entry as 'cancelled' so nothing is written to MySQL.
   ===================================================================== */

function secsysPendingVisitorQueuePath(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pending_visitors.json';
}

function secsysAcquirePendingQueueLock(string $lockPath, float $timeoutSec = 0.6): bool
{
    $dir = dirname($lockPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $deadline = microtime(true) + $timeoutSec;
    while (true) {
        $fh = @fopen($lockPath, 'x');
        if ($fh !== false) {
            @fwrite($fh, (string)getmypid());
            @fclose($fh);
            return true;
        }

        $mtime = @filemtime($lockPath);
        if ($mtime !== false && (time() - $mtime) > 3) {
            @unlink($lockPath);
            continue;
        }

        if (microtime(true) >= $deadline) {
            return false;
        }
        usleep(10000);
    }
}

function secsysReleasePendingQueueLock(string $lockPath): void
{
    @unlink($lockPath);
}

function secsysPrunePendingVisitorItems(array $items, float $nowTs): array
{
    $kept = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $status = strtolower(trim((string)($item['status'] ?? 'pending')));
        $createdAt = (float)($item['created_at'] ?? 0);
        $updatedAt = (float)($item['updated_at'] ?? $createdAt);

        if ($status === 'pending') {
            if (($nowTs - $createdAt) <= 600.0) {
                $kept[] = $item;
            }
        } else {
            if (($nowTs - $updatedAt) <= 45.0) {
                $kept[] = $item;
            }
        }
    }
    return $kept;
}

function secsysGetPendingVisitors(?string $queuePath = null): array
{
    $qPath = $queuePath ?: secsysPendingVisitorQueuePath();
    if (!is_file($qPath)) {
        return [];
    }

    $raw = @file_get_contents($qPath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = @json_decode($raw, true);
    $items = [];
    if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
        $items = $decoded['items'];
    } elseif (is_array($decoded)) {
        $items = $decoded;
    }

    $nowTs = microtime(true);
    $pending = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $status = strtolower(trim((string)($item['status'] ?? 'pending')));
        $createdAt = (float)($item['created_at'] ?? 0);
        if ($status === 'pending' && ($nowTs - $createdAt) <= 600.0) {
            $pending[] = [
                'pending_id'   => (string)($item['pending_id'] ?? ''),
                'plate_number' => (string)($item['plate_number'] ?? ''),
                'normalized'   => (string)($item['normalized'] ?? ''),
                'vehicle_type' => (string)($item['vehicle_type'] ?? 'Car'),
                'purpose'      => (string)($item['purpose'] ?? ''),
                'direction'    => 'IN',
                'camera'       => (string)($item['camera'] ?? 'Entrance Cam'),
                'confidence'   => (float)($item['confidence'] ?? 0),
                'created_at'   => $createdAt,
            ];
        }
    }

    usort($pending, function ($a, $b) {
        return ($a['created_at'] <=> $b['created_at']);
    });

    return $pending;
}

function secsysResolvePendingVisitor(
    string $pendingId,
    string $plateNumber = '',
    string $newStatus = 'cancelled',
    ?string $queuePath = null
): bool {
    $qPath = $queuePath ?: secsysPendingVisitorQueuePath();
    if (!is_file($qPath)) {
        return true;
    }

    $lockPath = $qPath . '.lock';
    if (!secsysAcquirePendingQueueLock($lockPath)) {
        return false;
    }

    try {
        $raw = @file_get_contents($qPath);
        $decoded = ($raw !== false && trim($raw) !== '') ? @json_decode($raw, true) : null;
        $items = [];
        if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
            $items = $decoded['items'];
        } elseif (is_array($decoded)) {
            $items = $decoded;
        }

        $nowTs = microtime(true);
        $items = secsysPrunePendingVisitorItems($items, $nowTs);
        $normTarget = preg_replace('/[^A-Z0-9]/', '', strtoupper($plateNumber));
        $statusClean = ($newStatus === 'saved') ? 'saved' : 'cancelled';

        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $curStatus = strtolower(trim((string)($item['status'] ?? 'pending')));
            if ($curStatus !== 'pending') {
                continue;
            }
            $itemId = (string)($item['pending_id'] ?? '');
            $itemNorm = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($item['normalized'] ?? $item['plate_number'] ?? '')));

            $matched = ($pendingId !== '' && $itemId === $pendingId)
                || ($normTarget !== '' && $itemNorm === $normTarget);

            if ($matched) {
                $item['status'] = $statusClean;
                $item['updated_at'] = $nowTs;
            }
        }
        unset($item);

        @file_put_contents($qPath, json_encode([
            'items'      => $items,
            'updated_at' => $nowTs,
        ], JSON_UNESCAPED_UNICODE));

        return true;
    } finally {
        secsysReleasePendingQueueLock($lockPath);
    }
}



/* =====================================================================
   DAILY / MONTHLY LIST FILTER (Logs, Registered, Visitor modules)
   ---------------------------------------------------------------------
   URL parameters (shared by every page):
       period        all | daily | monthly
       period_date   YYYY-MM-DD   (daily)
       period_month  1-12         (monthly, current year)
   ===================================================================== */

const SECSYS_MONTH_NAMES = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

/**
 * Read the list period from the request.
 * $default: 'daily' (today) or 'all'. $allowAll adds the "All" choice.
 */
function secsysListPeriod(string $default = 'daily', bool $allowAll = false): array
{
    $today = date('Y-m-d');
    $allowed = $allowAll ? ['all', 'daily', 'monthly'] : ['daily', 'monthly'];

    $basis = (string)($_GET['period'] ?? $default);
    if (!in_array($basis, $allowed, true)) {
        $basis = $default;
    }

    $date = (string)($_GET['period_date'] ?? '');
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date || $date > $today) {
        $date = $today;
    }

    $month = isset($_GET['period_month']) ? (int)$_GET['period_month'] : (int)date('n');
    if ($month < 1 || $month > 12) {
        $month = (int)date('n');
    }
    $year = (int)date('Y');

    if ($basis === 'all') {
        $label = 'All dates';
    } elseif ($basis === 'monthly') {
        $label = SECSYS_MONTH_NAMES[$month] . ' ' . $year;
    } else {
        $label = ($date === $today ? 'Today, ' : '') . date('F d, Y', strtotime($date));
    }

    // SQL-friendly range [start, end)
    $start = null;
    $end = null;
    if ($basis === 'daily') {
        $start = $date . ' 00:00:00';
        $end = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';
    } elseif ($basis === 'monthly') {
        $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $end = date('Y-m-d', strtotime(sprintf('%04d-%02d-01', $year, $month) . ' +1 month')) . ' 00:00:00';
    }

    return [
        'basis'     => $basis,
        'date'      => $date,
        'month'     => $month,
        'year'      => $year,
        'label'     => $label,
        'start'     => $start,
        'end'       => $end,
        'today'     => $today,
        'default'   => $default,
        'allow_all' => $allowAll,
        // true when the list shows only today's live records (the normal view)
        'is_today'  => $basis === 'daily' && $date === $today,
        // true when the range includes today (live, not-yet-archived rows apply)
        'has_today' => $basis === 'all'
            || ($basis === 'daily' && $date === $today)
            || ($basis === 'monthly' && $month === (int)date('n')),
    ];
}

/** Does a datetime/date string fall inside the period? */
function secsysPeriodMatches(?string $datetime, array $period): bool
{
    if ($period['basis'] === 'all') {
        return true;
    }
    if ($datetime === null || $datetime === '') {
        return false;
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return false;
    }
    return $ts >= strtotime($period['start']) && $ts < strtotime($period['end']);
}

/** Query-string fragment ("period=..&...") to keep the period in links; '' for the default view. */
function secsysPeriodQuery(array $period): string
{
    $isDefault = $period['basis'] === $period['default']
        && ($period['basis'] !== 'daily' || $period['date'] === $period['today']);
    if ($isDefault) {
        return '';
    }
    $params = ['period' => $period['basis']];
    if ($period['basis'] === 'daily') {
        $params['period_date'] = $period['date'];
    } elseif ($period['basis'] === 'monthly') {
        $params['period_month'] = $period['month'];
    }
    return http_build_query($params);
}

/** Hidden <input>s carrying the period inside other GET forms (search, time filters). */
function secsysPeriodHiddenInputs(array $period): string
{
    $q = secsysPeriodQuery($period);
    if ($q === '') {
        return '';
    }
    parse_str($q, $params);
    $html = '';
    foreach ($params as $k => $v) {
        $html .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string)$v) . '">';
    }
    return $html;
}

/**
 * Render the Daily / Monthly filter bar (GET form, submits instantly).
 * $keep: other query params to preserve (search, sort, ...).
 * Styles: assets/css/period_filter.css
 */
function secsysRenderPeriodFilter(array $period, string $action, array $keep = []): string
{
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };
    $basis = $period['basis'];

    $out = '<form method="GET" action="' . $h($action) . '" class="period-filter" data-period-filter>';

    foreach ($keep as $k => $v) {
        if ($v === '' || $v === null || is_array($v)) {
            continue;
        }
        $out .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
    }

    $out .= '<span class="period-filter-label">Show:</span>';
    $out .= '<div class="period-toggle" role="group" aria-label="Show records by period">';
    if ($period['allow_all']) {
        $out .= '<button type="submit" name="period" value="all" class="' . ($basis === 'all' ? 'active' : '') . '">📋 All</button>';
    }
    $out .= '<button type="submit" name="period" value="daily" data-period="daily" class="' . ($basis === 'daily' ? 'active' : '') . '">📅 Daily</button>';
    $out .= '<button type="submit" name="period" value="monthly" data-period="monthly" class="' . ($basis === 'monthly' ? 'active' : '') . '">🗓️ Monthly</button>';
    $out .= '</div>';

    if ($basis === 'daily') {
        $out .= '<input type="date" name="period_date" class="period-input" value="' . $h($period['date']) . '" max="' . $h($period['today']) . '"'
              . ' aria-label="Select date" onchange="this.form.requestSubmit(this.form.querySelector(\'[data-period=daily]\'))">';
        if (!$period['is_today']) {
            $out .= '<a class="period-today-link" href="' . $h($action) . '?' . $h(http_build_query(array_merge(array_filter($keep, 'strlen'), ['period' => 'daily']))) . '">Back to today</a>';
        }
    } elseif ($basis === 'monthly') {
        $out .= '<select name="period_month" class="period-input" aria-label="Select month"'
              . ' onchange="this.form.requestSubmit(this.form.querySelector(\'[data-period=monthly]\'))">';
        foreach (SECSYS_MONTH_NAMES as $num => $name) {
            $out .= '<option value="' . $num . '"' . ($num === $period['month'] ? ' selected' : '') . '>' . $h($name) . '</option>';
        }
        $out .= '</select>';
    } else {
        // keep the last chosen date/month when switching back
        $out .= '<input type="hidden" name="period_date" value="' . $h($period['date']) . '">';
    }

    $out .= '<span class="period-current">Showing: <strong>' . $h($period['label']) . '</strong></span>';
    $out .= '</form>';

    return $out;
}


/**
 * Keep only rows where any of $fields contains $needle (case-insensitive).
 * Used by the search bar inside the archive windows.
 */
function secsysFilterRowsBySearch(array $rows, string $needle, array $fields): array
{
    $needle = trim($needle);
    if ($needle === '') {
        return $rows;
    }
    return array_values(array_filter($rows, function ($row) use ($needle, $fields) {
        foreach ($fields as $field) {
            if (isset($row[$field]) && is_scalar($row[$field]) && stripos((string)$row[$field], $needle) !== false) {
                return true;
            }
        }
        return false;
    }));
}

/** Read the archive search text from the request (max 60 characters). */
function secsysArchiveSearchFromRequest(): string
{
    $q = trim((string)($_GET['archive_q'] ?? ''));
    return function_exists('mb_substr') ? mb_substr($q, 0, 60) : substr($q, 0, 60);
}



/**
 * Weekly automatic backup trigger (called once per page load from
 * secsysEnsureSchema). Only a cheap timestamp check normally; the backup
 * engine (app/backup_helper.php) is loaded only when a weekly backup or the
 * daily clean-up of backups older than 6 months is due.
 * Command-line runs (tests, scripts) are skipped unless they define
 * SECSYS_ALLOW_CLI_AUTOBACKUP (tools/run_auto_backup.php does).
 */
function secsysMaybeRunAutoBackup(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (PHP_SAPI === 'cli' && !defined('SECSYS_ALLOW_CLI_AUTOBACKUP')) {
        return;
    }

    try {
        $values = [];
        $res = @$conn->query("
            SELECT setting_key, setting_value
            FROM system_settings
            WHERE setting_key IN ('auto_backup_last_at', 'backup_last_purge_at')
        ");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $values[$row['setting_key']] = (int)strtotime((string)$row['setting_value']);
            }
        }

        $now = time();
        $backupDue = empty($values['auto_backup_last_at']) || ($now - $values['auto_backup_last_at']) >= 7 * 86400;
        $purgeDue  = empty($values['backup_last_purge_at']) || ($now - $values['backup_last_purge_at']) >= 86400;

        if (!$backupDue && !$purgeDue) {
            return;
        }

        require_once __DIR__ . '/backup_helper.php';
        secsysRunAutoBackupIfDue($conn);
    } catch (Throwable $e) {
        // A backup problem must never break a page; it is retried on a later request.
        error_log('[secsys auto-backup] ' . $e->getMessage());
    }
}


/* =====================================================================
   VEHICLE SUMMARY FOR DAY / PERIOD & PLATE ENTRY/EXIT FREQUENCY HELPERS
   ===================================================================== */

/**
 * Normalize a plate number into a canonical comparison key (uppercase, no spaces or hyphens).
 */
function secsysNormalizePlateKey(string $plate): string
{
    return strtoupper((string)preg_replace('/[\s\-]+/', '', trim($plate)));
}

/**
 * Check whether a search query matches any of the provided text/plate fields
 * (supports both substring match and space/hyphen-insensitive plate match).
 */
function secsysMatchesPlateSearch(string $searchQuery, string ...$fields): bool
{
    $q = trim($searchQuery);
    if ($q === '') {
        return true;
    }
    $qNorm = secsysNormalizePlateKey($q);
    foreach ($fields as $f) {
        $fStr = (string)$f;
        if ($fStr === '') {
            continue;
        }
        if (stripos($fStr, $q) !== false) {
            return true;
        }
        if ($qNorm !== '' && stripos(secsysNormalizePlateKey($fStr), $qNorm) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Build a complete vehicle summary (totals, Registered vs Visitor breakdown,
 * Vehicle Type breakdown, and per-plate Entry/Exit counts) from session rows.
 */
function secsysBuildVehicleSummary(array $sessions): array
{
    $emptyBucket = function (): array {
        return [
            'sessions'      => 0,
            'unique_plates' => 0,
            'in_count'      => 0,
            'out_count'     => 0,
            'inside'        => 0,
            'outside'       => 0,
            '_plates'       => [],
        ];
    };

    $regBucket = $emptyBucket();
    $visBucket = $emptyBucket();
    $allPlates = [];

    $typeBuckets = [
        'Car'        => array_merge($emptyBucket(), ['label' => 'Car', 'icon' => '🚗', 'registered' => 0, 'visitor' => 0]),
        'Motorcycle' => array_merge($emptyBucket(), ['label' => 'Motorcycle', 'icon' => '🏍️', 'registered' => 0, 'visitor' => 0]),
        'Truck'      => array_merge($emptyBucket(), ['label' => 'Truck', 'icon' => '🚚', 'registered' => 0, 'visitor' => 0]),
    ];

    $perPlate = [];
    $totalIn  = 0;
    $totalOut = 0;
    $inside   = 0;
    $outside  = 0;

    foreach ($sessions as $s) {
        $plateRaw = strtoupper(trim((string)($s['plate_number'] ?? '')));
        $plateNorm = secsysNormalizePlateKey($plateRaw);
        $plateKey = $plateNorm !== '' ? $plateNorm : 'UNKNOWN';
        $catRaw   = strtolower(trim((string)($s['category'] ?? 'registered')));
        $isReg    = ($catRaw === 'registered');
        $catLabel = $isReg ? 'Registered' : 'Visitor';
        $typeKey  = secsysNormalizeVehicleType($s['vehicle_type'] ?? 'Car');

        $hasIn    = !empty($s['time_in']);
        $hasOut   = !empty($s['time_out']);
        $isInside = (strtolower(trim((string)($s['status'] ?? ''))) === 'inside');

        $inInc  = $hasIn ? 1 : 0;
        $outInc = $hasOut ? 1 : 0;

        $totalIn  += $inInc;
        $totalOut += $outInc;
        if ($isInside) {
            $inside++;
        } else {
            $outside++;
        }

        if ($plateNorm !== '') {
            $allPlates[$plateNorm] = true;
        }

        // Category bucket
        $targetCat = &$visBucket;
        if ($isReg) {
            $targetCat = &$regBucket;
        }
        $targetCat['sessions']++;
        $targetCat['in_count']  += $inInc;
        $targetCat['out_count'] += $outInc;
        if ($isInside) {
            $targetCat['inside']++;
        } else {
            $targetCat['outside']++;
        }
        if ($plateNorm !== '') {
            $targetCat['_plates'][$plateNorm] = true;
        }
        unset($targetCat);

        // Vehicle type bucket
        if (!isset($typeBuckets[$typeKey])) {
            $typeBuckets[$typeKey] = array_merge($emptyBucket(), [
                'label'      => $typeKey,
                'icon'       => '🚙',
                'registered' => 0,
                'visitor'    => 0,
            ]);
        }
        $tb = &$typeBuckets[$typeKey];
        $tb['sessions']++;
        if ($isReg) {
            $tb['registered']++;
        } else {
            $tb['visitor']++;
        }
        $tb['in_count']  += $inInc;
        $tb['out_count'] += $outInc;
        if ($isInside) {
            $tb['inside']++;
        } else {
            $tb['outside']++;
        }
        if ($plateNorm !== '') {
            $tb['_plates'][$plateNorm] = true;
        }
        unset($tb);

        $rowName = trim((string)($s['name'] ?? ($s['owner_name'] ?? ($s['visitor_name'] ?? ''))));
        $rowPurpose = trim((string)($s['purpose'] ?? ''));

        // Per-plate tracking
        if (!isset($perPlate[$plateKey])) {
            $perPlate[$plateKey] = [
                'plate_number'     => $plateRaw !== '' ? $plateRaw : '-',
                'plate_key'        => $plateKey,
                'category'         => $catLabel,
                'vehicle_type'     => $typeKey,
                'name'             => $rowName,
                'purpose'          => $rowPurpose,
                'actor_label'      => trim((string)($s['actor_label'] ?? '')),
                'sessions'         => 0,
                'in_count'         => 0,
                'out_count'        => 0,
                'inside'           => 0,
                'outside'          => 0,
                'latest_status'    => $isInside ? 'Inside' : 'Outside',
                'first_time_in'    => null,
                'last_time_in'     => null,
                'last_time_out'    => null,
                'last_activity_ts' => 0,
            ];
        }

        $pp = &$perPlate[$plateKey];
        // Prefer readable plate formatting (e.g. with space) if available
        if ($plateRaw !== '' && strpos($plateRaw, ' ') !== false && strpos($pp['plate_number'], ' ') === false) {
            $pp['plate_number'] = $plateRaw;
        }
        $pp['sessions']++;
        $pp['in_count']  += $inInc;
        $pp['out_count'] += $outInc;
        if ($isInside) {
            $pp['inside']++;
        } else {
            $pp['outside']++;
        }

        if ($pp['name'] === '' && $rowName !== '') {
            $pp['name'] = $rowName;
        }
        if ($pp['purpose'] === '' && $rowPurpose !== '') {
            $pp['purpose'] = $rowPurpose;
        }

        $tInTs  = $hasIn ? (int)strtotime((string)$s['time_in']) : 0;
        $tOutTs = $hasOut ? (int)strtotime((string)$s['time_out']) : 0;

        if ($tInTs > 0) {
            if ($pp['first_time_in'] === null || $tInTs < (int)strtotime((string)$pp['first_time_in'])) {
                $pp['first_time_in'] = (string)$s['time_in'];
            }
            if ($pp['last_time_in'] === null || $tInTs >= (int)strtotime((string)$pp['last_time_in'])) {
                $pp['last_time_in'] = (string)$s['time_in'];
            }
        }
        if ($tOutTs > 0) {
            if ($pp['last_time_out'] === null || $tOutTs >= (int)strtotime((string)$pp['last_time_out'])) {
                $pp['last_time_out'] = (string)$s['time_out'];
            }
        }

        $latestInTs  = $pp['last_time_in'] ? (int)strtotime((string)$pp['last_time_in']) : 0;
        $latestOutTs = $pp['last_time_out'] ? (int)strtotime((string)$pp['last_time_out']) : 0;
        $maxTs       = max($latestInTs, $latestOutTs);
        if ($maxTs > 0) {
            $pp['last_activity_ts'] = $maxTs;
            $pp['latest_status']    = ($latestOutTs >= $latestInTs && $latestOutTs > 0) ? 'Outside' : 'Inside';
        }
        unset($pp);
    }

    $regBucket['unique_plates'] = count($regBucket['_plates']);
    unset($regBucket['_plates']);

    $visBucket['unique_plates'] = count($visBucket['_plates']);
    unset($visBucket['_plates']);

    foreach ($typeBuckets as &$tb) {
        $tb['unique_plates'] = count($tb['_plates']);
        unset($tb['_plates']);
    }
    unset($tb);

    // Sort perPlate by most active / latest activity
    uasort($perPlate, function ($a, $b) {
        if ($b['sessions'] !== $a['sessions']) {
            return $b['sessions'] <=> $a['sessions'];
        }
        return ($b['last_activity_ts'] ?? 0) <=> ($a['last_activity_ts'] ?? 0);
    });

    // Also index per_plate by raw plate_number uppercase so callers looking up by either raw or normalized key succeed
    $perPlateIndexed = $perPlate;
    foreach ($perPlate as $normKey => $info) {
        $rawUp = strtoupper(trim((string)($info['plate_number'] ?? '')));
        if ($rawUp !== '' && !isset($perPlateIndexed[$rawUp])) {
            $perPlateIndexed[$rawUp] = $info;
        }
    }

    return [
        'total_sessions' => count($sessions),
        'unique_plates'  => count($allPlates),
        'total_in'       => $totalIn,
        'total_out'      => $totalOut,
        'inside'         => $inside,
        'outside'        => $outside,
        'registered'     => $regBucket,
        'visitor'        => $visBucket,
        'by_type'        => array_values($typeBuckets),
        'per_plate'      => $perPlate,
        'per_plate_map'  => $perPlateIndexed,
    ];
}

/**
 * Query exact IN and OUT movement counts from both `vehicle_logs` and
 * `vehicle_logs_archive` for plates matching a search query (or specific plates).
 * Deduplicates by (normalized_plate, direction, date_created).
 */
function secsysQueryPlateMovementCounts(
    mysqli $conn,
    string $searchQuery,
    array $knownPlates = [],
    ?string $periodStartDate = null,
    ?string $periodEndDate = null,
    ?string $categoryFilter = null
): array {
    $q = trim($searchQuery);
    $qNorm = secsysNormalizePlateKey($q);
    if ($q === '' && empty($knownPlates)) {
        return [];
    }

    $like = '%' . $q . '%';
    $likeNorm = '%' . ($qNorm !== '' ? $qNorm : $q) . '%';
    $rows = [];

    if ($q !== '') {
        foreach (['vehicle_logs', 'vehicle_logs_archive'] as $tbl) {
            $sql = "
                SELECT plate_number, vehicle_type, vehicle_category, direction, date_created, purpose
                FROM {$tbl}
                WHERE (
                    plate_number LIKE ?
                    OR REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') LIKE ?
                )
            ";
            $stmt = @$conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ss", $like, $likeNorm);
                if (@$stmt->execute()) {
                    $res = $stmt->get_result();
                    while ($r = $res->fetch_assoc()) {
                        $rows[] = $r;
                    }
                }
                $stmt->close();
            }
        }
    }

    // Also fetch any knownPlates that matched by owner/visitor name rather than plate substring
    $extraPlatesNorm = [];
    foreach ($knownPlates as $kp) {
        $kpNorm = secsysNormalizePlateKey((string)$kp);
        if ($kpNorm !== '') {
            $extraPlatesNorm[$kpNorm] = $kpNorm;
        }
    }
    if (!empty($extraPlatesNorm)) {
        $extraList = array_values($extraPlatesNorm);
        $ph = implode(',', array_fill(0, count($extraList), '?'));
        $types = str_repeat('s', count($extraList));
        foreach (['vehicle_logs', 'vehicle_logs_archive'] as $tbl) {
            $sql = "
                SELECT plate_number, vehicle_type, vehicle_category, direction, date_created, purpose
                FROM {$tbl}
                WHERE REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') IN ({$ph})
            ";
            $stmt = @$conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$extraList);
                if (@$stmt->execute()) {
                    $res = $stmt->get_result();
                    while ($r = $res->fetch_assoc()) {
                        $rows[] = $r;
                    }
                }
                $stmt->close();
            }
        }
    }

    $seenRaw = [];
    $stats   = [];

    foreach ($rows as $r) {
        $plate = strtoupper(trim((string)($r['plate_number'] ?? '')));
        $plateKey = secsysNormalizePlateKey($plate);
        if ($plateKey === '') {
            continue;
        }
        $cat = ucfirst(strtolower(trim((string)($r['vehicle_category'] ?? 'Registered'))));
        if ($categoryFilter !== null && $categoryFilter !== '' && strcasecmp($cat, $categoryFilter) !== 0) {
            continue;
        }
        $dir = strtoupper(trim((string)($r['direction'] ?? 'IN')));
        $dt  = trim((string)($r['date_created'] ?? ''));
        $dedupKey = $plateKey . '|' . $dir . '|' . $dt;
        if (isset($seenRaw[$dedupKey])) {
            continue;
        }
        $seenRaw[$dedupKey] = true;

        if (!isset($stats[$plateKey])) {
            $stats[$plateKey] = [
                'plate_number'      => $plate,
                'plate_key'         => $plateKey,
                'category'          => $cat,
                'vehicle_type'      => secsysNormalizeVehicleType($r['vehicle_type'] ?? 'Car'),
                'name'              => '',
                'purpose'           => trim((string)($r['purpose'] ?? '')),
                'period_in'         => 0,
                'period_out'        => 0,
                'all_time_in'       => 0,
                'all_time_out'      => 0,
                'first_in'          => null,
                'last_in'           => null,
                'last_out'          => null,
                'all_time_last_in'  => null,
                'all_time_last_out' => null,
            ];
        } else {
            if (strpos($plate, ' ') !== false && strpos($stats[$plateKey]['plate_number'], ' ') === false) {
                $stats[$plateKey]['plate_number'] = $plate;
            }
            if ($stats[$plateKey]['purpose'] === '' && !empty($r['purpose'])) {
                $stats[$plateKey]['purpose'] = trim((string)$r['purpose']);
            }
        }

        $isIn = ($dir === 'IN');
        if ($isIn) {
            $stats[$plateKey]['all_time_in']++;
            if ($dt !== '' && ($stats[$plateKey]['all_time_last_in'] === null || strcmp($dt, $stats[$plateKey]['all_time_last_in']) > 0)) {
                $stats[$plateKey]['all_time_last_in'] = $dt;
            }
        } else {
            $stats[$plateKey]['all_time_out']++;
            if ($dt !== '' && ($stats[$plateKey]['all_time_last_out'] === null || strcmp($dt, $stats[$plateKey]['all_time_last_out']) > 0)) {
                $stats[$plateKey]['all_time_last_out'] = $dt;
            }
        }

        $inPeriod = true;
        if ($periodStartDate !== null && $periodEndDate !== null && $dt !== '') {
            $dOnly = substr($dt, 0, 10);
            $inPeriod = ($dOnly >= $periodStartDate && $dOnly <= $periodEndDate);
        }

        if ($inPeriod) {
            if ($isIn) {
                $stats[$plateKey]['period_in']++;
                if ($dt !== '' && ($stats[$plateKey]['first_in'] === null || strcmp($dt, $stats[$plateKey]['first_in']) < 0)) {
                    $stats[$plateKey]['first_in'] = $dt;
                }
                if ($dt !== '' && ($stats[$plateKey]['last_in'] === null || strcmp($dt, $stats[$plateKey]['last_in']) > 0)) {
                    $stats[$plateKey]['last_in'] = $dt;
                }
            } else {
                $stats[$plateKey]['period_out']++;
                if ($dt !== '' && ($stats[$plateKey]['last_out'] === null || strcmp($dt, $stats[$plateKey]['last_out']) > 0)) {
                    $stats[$plateKey]['last_out'] = $dt;
                }
            }
        }
    }

    // Enrich owner/visitor names from registered_vehicles and visitor_vehicles (and discover registered/visitor plates with 0 logs if searched directly)
    if ($q !== '') {
        if ($categoryFilter === null || $categoryFilter === '' || strcasecmp($categoryFilter, 'Registered') === 0) {
            $stmtRv = @$conn->prepare("
                SELECT plate_number, vehicle_type, owner_name
                FROM registered_vehicles
                WHERE plate_number LIKE ?
                   OR REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') LIKE ?
                   OR owner_name LIKE ?
            ");
            if ($stmtRv) {
                $stmtRv->bind_param("sss", $like, $likeNorm, $like);
                if (@$stmtRv->execute()) {
                    $resRv = $stmtRv->get_result();
                    while ($rv = $resRv->fetch_assoc()) {
                        $pRaw = strtoupper(trim((string)($rv['plate_number'] ?? '')));
                        $pKey = secsysNormalizePlateKey($pRaw);
                        if ($pKey === '') {
                            continue;
                        }
                        if (!isset($stats[$pKey])) {
                            $stats[$pKey] = [
                                'plate_number'      => $pRaw,
                                'plate_key'         => $pKey,
                                'category'          => 'Registered',
                                'vehicle_type'      => secsysNormalizeVehicleType($rv['vehicle_type'] ?? 'Car'),
                                'name'              => trim((string)($rv['owner_name'] ?? '')),
                                'purpose'           => 'Homeowner / Resident',
                                'period_in'         => 0,
                                'period_out'        => 0,
                                'all_time_in'       => 0,
                                'all_time_out'      => 0,
                                'first_in'          => null,
                                'last_in'           => null,
                                'last_out'          => null,
                                'all_time_last_in'  => null,
                                'all_time_last_out' => null,
                            ];
                        } else {
                            if ($stats[$pKey]['name'] === '' && !empty($rv['owner_name'])) {
                                $stats[$pKey]['name'] = trim((string)$rv['owner_name']);
                            }
                        }
                    }
                }
                $stmtRv->close();
            }
        }

        if ($categoryFilter === null || $categoryFilter === '' || strcasecmp($categoryFilter, 'Visitor') === 0) {
            $stmtVv = @$conn->prepare("
                SELECT plate_number, vehicle_type, visitor_name, purpose
                FROM visitor_vehicles
                WHERE plate_number LIKE ?
                   OR REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') LIKE ?
                   OR visitor_name LIKE ?
                ORDER BY id DESC
            ");
            if ($stmtVv) {
                $stmtVv->bind_param("sss", $like, $likeNorm, $like);
                if (@$stmtVv->execute()) {
                    $resVv = $stmtVv->get_result();
                    while ($vv = $resVv->fetch_assoc()) {
                        $pRaw = strtoupper(trim((string)($vv['plate_number'] ?? '')));
                        $pKey = secsysNormalizePlateKey($pRaw);
                        if ($pKey === '') {
                            continue;
                        }
                        if (!isset($stats[$pKey])) {
                            $stats[$pKey] = [
                                'plate_number'      => $pRaw,
                                'plate_key'         => $pKey,
                                'category'          => 'Visitor',
                                'vehicle_type'      => secsysNormalizeVehicleType($vv['vehicle_type'] ?? 'Car'),
                                'name'              => trim((string)($vv['visitor_name'] ?? '')),
                                'purpose'           => trim((string)($vv['purpose'] ?? '')),
                                'period_in'         => 0,
                                'period_out'        => 0,
                                'all_time_in'       => 0,
                                'all_time_out'      => 0,
                                'first_in'          => null,
                                'last_in'           => null,
                                'last_out'          => null,
                                'all_time_last_in'  => null,
                                'all_time_last_out' => null,
                            ];
                        } else {
                            if ($stats[$pKey]['name'] === '' && !empty($vv['visitor_name'])) {
                                $stats[$pKey]['name'] = trim((string)$vv['visitor_name']);
                            }
                            if ($stats[$pKey]['purpose'] === '' && !empty($vv['purpose'])) {
                                $stats[$pKey]['purpose'] = trim((string)$vv['purpose']);
                            }
                        }
                    }
                }
                $stmtVv->close();
            }
        }
    }

    // Lookup owner/visitor names for any matched plates still missing a name
    $missingNameKeys = [];
    foreach ($stats as $pKey => $st) {
        if ($st['name'] === '') {
            $missingNameKeys[] = $pKey;
        }
    }
    if (!empty($missingNameKeys)) {
        $ph = implode(',', array_fill(0, count($missingNameKeys), '?'));
        $types = str_repeat('s', count($missingNameKeys));
        $stmtLookupRv = @$conn->prepare("
            SELECT plate_number, owner_name
            FROM registered_vehicles
            WHERE REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') IN ({$ph})
        ");
        if ($stmtLookupRv) {
            $stmtLookupRv->bind_param($types, ...$missingNameKeys);
            if (@$stmtLookupRv->execute()) {
                $res = $stmtLookupRv->get_result();
                while ($r = $res->fetch_assoc()) {
                    $pk = secsysNormalizePlateKey((string)($r['plate_number'] ?? ''));
                    if ($pk !== '' && isset($stats[$pk]) && $stats[$pk]['name'] === '' && !empty($r['owner_name'])) {
                        $stats[$pk]['name'] = trim((string)$r['owner_name']);
                    }
                }
            }
            $stmtLookupRv->close();
        }

        $stmtLookupVv = @$conn->prepare("
            SELECT plate_number, visitor_name, purpose
            FROM visitor_vehicles
            WHERE REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') IN ({$ph})
            ORDER BY id DESC
        ");
        if ($stmtLookupVv) {
            $stmtLookupVv->bind_param($types, ...$missingNameKeys);
            if (@$stmtLookupVv->execute()) {
                $res = $stmtLookupVv->get_result();
                while ($r = $res->fetch_assoc()) {
                    $pk = secsysNormalizePlateKey((string)($r['plate_number'] ?? ''));
                    if ($pk !== '' && isset($stats[$pk])) {
                        if ($stats[$pk]['name'] === '' && !empty($r['visitor_name'])) {
                            $stats[$pk]['name'] = trim((string)$r['visitor_name']);
                        }
                        if ($stats[$pk]['purpose'] === '' && !empty($r['purpose'])) {
                            $stats[$pk]['purpose'] = trim((string)$r['purpose']);
                        }
                    }
                }
            }
            $stmtLookupVv->close();
        }
    }

    return $stats;
}

/**
 * Render a compact pill showing a vehicle's IN and OUT count for table cells.
 */
function secsysPlateEntryExitPillHtml(int $inCount, int $outCount): string
{
    return '<span class="secsys-io-pill" title="Entered subdivision ' . $inCount . ' time(s), Exited subdivision ' . $outCount . ' time(s)">'
        . '<span class="secsys-io-in">&#8599; ' . $inCount . ' IN</span>'
        . '<span class="secsys-io-sep">&bull;</span>'
        . '<span class="secsys-io-out">&#8600; ' . $outCount . ' OUT</span>'
        . '</span>';
}

/**
 * Render a prominent Vehicle Entry & Exit Summary banner when searching for a plate number.
 */
function secsysRenderPlateSearchSummaryHtml(
    string $searchQuery,
    array $matchedSessions,
    string $periodLabel = 'Today',
    ?mysqli $conn = null,
    ?string $periodStartDate = null,
    ?string $periodEndDate = null,
    ?string $categoryFilter = null
): string {
    $q = trim($searchQuery);
    if ($q === '') {
        return '';
    }

    $summary = secsysBuildVehicleSummary($matchedSessions);
    $plateItems = $summary['per_plate'];

    // Enrich with DB movement counts (so archived logs from the same day or all-time history are included)
    $dbCounts = [];
    if ($conn instanceof mysqli) {
        $knownPlatesList = [];
        foreach ($plateItems as $pi) {
            if (!empty($pi['plate_number']) && $pi['plate_number'] !== '-') {
                $knownPlatesList[] = $pi['plate_number'];
            }
        }
        $dbCounts = secsysQueryPlateMovementCounts(
            $conn,
            $q,
            $knownPlatesList,
            $periodStartDate,
            $periodEndDate,
            $categoryFilter
        );

        foreach ($dbCounts as $plateKey => $dbc) {
            if (!isset($plateItems[$plateKey])) {
                // Plate exists in DB (e.g. archived earlier or from another date), include it
                $firstIn = $dbc['first_in'] ?? $dbc['last_in'] ?? $dbc['all_time_last_in'];
                $lastIn  = $dbc['last_in'] ?? $dbc['all_time_last_in'];
                $lastOut = $dbc['last_out'] ?? $dbc['all_time_last_out'];
                $latestStatus = ($lastOut !== null && ($lastIn === null || strcmp($lastOut, $lastIn) >= 0))
                    ? 'Outside'
                    : ($lastIn !== null ? 'Inside' : 'Outside');
                $plateItems[$plateKey] = [
                    'plate_number'     => $dbc['plate_number'],
                    'plate_key'        => $plateKey,
                    'category'         => $dbc['category'],
                    'vehicle_type'     => $dbc['vehicle_type'],
                    'name'             => $dbc['name'] ?? '',
                    'purpose'          => $dbc['purpose'] ?? '',
                    'sessions'         => max($dbc['period_in'], $dbc['period_out']),
                    'in_count'         => $dbc['period_in'],
                    'out_count'        => $dbc['period_out'],
                    'all_time_in'      => $dbc['all_time_in'],
                    'all_time_out'     => $dbc['all_time_out'],
                    'inside'           => $latestStatus === 'Inside' ? 1 : 0,
                    'outside'          => $latestStatus === 'Outside' ? 1 : 0,
                    'latest_status'    => $latestStatus,
                    'first_time_in'    => $firstIn,
                    'last_time_in'     => $lastIn,
                    'last_time_out'    => $lastOut,
                    'last_activity_ts' => max($lastIn ? (int)strtotime($lastIn) : 0, $lastOut ? (int)strtotime($lastOut) : 0),
                ];
            } else {
                // Take the max of current view and period DB count so archived-today visits are also counted
                $plateItems[$plateKey]['in_count']     = max($plateItems[$plateKey]['in_count'], $dbc['period_in']);
                $plateItems[$plateKey]['out_count']    = max($plateItems[$plateKey]['out_count'], $dbc['period_out']);
                $plateItems[$plateKey]['all_time_in']  = max($plateItems[$plateKey]['in_count'], $dbc['all_time_in']);
                $plateItems[$plateKey]['all_time_out'] = max($plateItems[$plateKey]['out_count'], $dbc['all_time_out']);
                if ($plateItems[$plateKey]['name'] === '' && !empty($dbc['name'])) {
                    $plateItems[$plateKey]['name'] = $dbc['name'];
                }
                if ($plateItems[$plateKey]['purpose'] === '' && !empty($dbc['purpose'])) {
                    $plateItems[$plateKey]['purpose'] = $dbc['purpose'];
                }
                if ($plateItems[$plateKey]['first_time_in'] === null && !empty($dbc['first_in'])) {
                    $plateItems[$plateKey]['first_time_in'] = $dbc['first_in'];
                }
                if ($plateItems[$plateKey]['last_time_in'] === null && !empty($dbc['last_in'])) {
                    $plateItems[$plateKey]['last_time_in'] = $dbc['last_in'];
                }
                if ($plateItems[$plateKey]['last_time_out'] === null && !empty($dbc['last_out'])) {
                    $plateItems[$plateKey]['last_time_out'] = $dbc['last_out'];
                }
            }
        }
    }

    $totalIn       = 0;
    $totalOut      = 0;
    $allTimeInSum  = 0;
    $allTimeOutSum = 0;
    foreach ($plateItems as &$pi) {
        $pi['all_time_in']  = $pi['all_time_in'] ?? $pi['in_count'];
        $pi['all_time_out'] = $pi['all_time_out'] ?? $pi['out_count'];
        $totalIn       += (int)$pi['in_count'];
        $totalOut      += (int)$pi['out_count'];
        $allTimeInSum  += (int)$pi['all_time_in'];
        $allTimeOutSum += (int)$pi['all_time_out'];
    }
    unset($pi);

    $h = function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    $fmtTime = function (?string $dt): string {
        if (!$dt) {
            return '-';
        }
        $ts = strtotime($dt);
        return $ts ? date('M d, Y h:i A', $ts) : '-';
    };

    $plateCount = count($plateItems);
    $showAllTimeCol = ($allTimeInSum > $totalIn || $allTimeOutSum > $totalOut);

    $out = '<style>
    .pss-card {
        background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
        border: 1px solid #bfdbfe;
        border-left: 5px solid #2f80ed;
        border-radius: 16px;
        padding: 16px 20px;
        margin-bottom: 18px;
        box-shadow: 0 3px 10px rgba(47, 128, 237, 0.08);
        font-family: Arial, Helvetica, sans-serif;
    }
    .pss-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        padding-bottom: 12px;
        border-bottom: 1px solid #e2e8f0;
    }
    .pss-title-wrap {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .pss-icon {
        width: 38px;
        height: 38px;
        border-radius: 11px;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        color: #1d4ed8;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        flex-shrink: 0;
    }
    .pss-title {
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
    }
    .pss-sub {
        font-size: 12px;
        color: #475569;
        margin-top: 2px;
    }
    .pss-kpis {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
    }
    .pss-kpi {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 13px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }
    .pss-kpi strong {
        font-size: 14px;
    }
    .pss-kpi-in {
        background: #dcfce7;
        color: #15803d;
        border: 1px solid #86efac;
    }
    .pss-kpi-out {
        background: #dbeafe;
        color: #1d4ed8;
        border: 1px solid #93c5fd;
    }
    .pss-kpi-alltime {
        background: #f3e8ff;
        color: #6d28d9;
        border: 1px solid #d8b4fe;
    }
    .pss-table-wrap {
        margin-top: 12px;
        overflow-x: auto;
    }
    .pss-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12.5px;
        text-align: center;
    }
    .pss-table th {
        background: #f1f5f9;
        color: #334155;
        font-weight: 700;
        font-size: 11.5px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        padding: 8px 10px;
        border-bottom: 2px solid #cbd5e1;
        text-align: center;
        white-space: nowrap;
    }
    .pss-table td {
        padding: 9px 10px;
        border-bottom: 1px solid #e2e8f0;
        color: #1e293b;
        vertical-align: middle;
        text-align: center;
    }
    .pss-table tbody tr:last-child td {
        border-bottom: none;
    }
    .pss-plate-badge {
        display: inline-block;
        font-family: Consolas, monospace;
        font-size: 13px;
        font-weight: 700;
        background: #eff6ff;
        color: #1e3a8a;
        border: 1px solid #bfdbfe;
        padding: 3px 9px;
        border-radius: 6px;
    }
    .pss-count-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border-radius: 999px;
        font-weight: 700;
        font-size: 12px;
    }
    .pss-count-in {
        background: #e7f7ed;
        color: #16733b;
        border: 1px solid #bde5ca;
    }
    .pss-count-out {
        background: #edf5ff;
        color: #245b9e;
        border: 1px solid #c9ddf7;
    }
    .secsys-io-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }
    .secsys-io-in { color: #15803d; }
    .secsys-io-sep { color: #94a3b8; }
    .secsys-io-out { color: #1d4ed8; }
    </style>';

    $out .= '<div class="pss-card" role="region" aria-label="Plate Number Entry and Exit Summary">';
    $out .= '<div class="pss-top">';
    $out .= '<div class="pss-title-wrap">';
    $out .= '<div class="pss-icon">&#128663;</div>';
    $out .= '<div>';
    $out .= '<div class="pss-title">Plate Search: &ldquo;' . $h($q) . '&rdquo;</div>';
    $out .= '<div class="pss-sub">' . $h($periodLabel) . '</div>';
    $out .= '</div>';
    $out .= '</div>';

    $out .= '<div class="pss-kpis">';
    $out .= '<span class="pss-kpi pss-kpi-in">&#8599; Entered (IN): <strong>' . $totalIn . '</strong></span>';
    $out .= '<span class="pss-kpi pss-kpi-out">&#8600; Exited (OUT): <strong>' . $totalOut . '</strong></span>';
    $out .= '</div>';
    $out .= '</div>';

    if ($plateCount === 0) {
        $out .= '<div style="padding: 12px 4px 2px; font-size: 13px; color: #64748b;">'
              . 'No records found for <strong>&ldquo;' . $h($q) . '&rdquo;</strong> (0 Entered IN / 0 Exited OUT).'
              . '</div>';
    } else {
        $out .= '<div class="pss-table-wrap">';
        $out .= '<table class="pss-table print-table">';
        $out .= '<thead><tr>'
              . '<th>Plate Number</th>'
              . '<th>Category</th>'
              . '<th>Vehicle Type</th>'
              . '<th>Entered (IN)</th>'
              . '<th>Exited (OUT)</th>'
              . '<th>Status</th>'
              . '</tr></thead><tbody>';

        foreach ($plateItems as $item) {
            $isInside = ($item['latest_status'] === 'Inside');
            $stBadge = $isInside
                ? '<span class="status-badge status-inside" style="background:#e7f7ed;color:#16733b;border:1px solid #bde5ca;padding:3px 10px;border-radius:14px;font-size:11px;font-weight:700;">Inside</span>'
                : '<span class="status-badge status-outside" style="background:#edf5ff;color:#245b9e;border:1px solid #c9ddf7;padding:3px 10px;border-radius:14px;font-size:11px;font-weight:700;">Outside</span>';

            $out .= '<tr>'
                  . '<td>' . secsysClickablePlateHtml($item['plate_number'], [], 'pss-plate-badge') . '</td>'
                  . '<td><strong>' . $h($item['category']) . '</strong></td>'
                  . '<td>' . $h($item['vehicle_type']) . '</td>'
                  . '<td><span class="pss-count-pill pss-count-in">&#8599; ' . (int)$item['in_count'] . '</span></td>'
                  . '<td><span class="pss-count-pill pss-count-out">&#8600; ' . (int)$item['out_count'] . '</span></td>'
                  . '<td>' . $stBadge . '</td>'
                  . '</tr>';
        }

        $out .= '</tbody></table></div>';
    }

    $out .= '</div>';
    return $out;
}

