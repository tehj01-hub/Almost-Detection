<?php

require_once __DIR__ . '/app/session_helper.php';
require_once __DIR__ . '/app/system_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* =========================================================
   AUTHENTICATE SECURITY GUARD OR ADMINISTRATOR SESSION
========================================================= */
$requestedRole = trim((string)($_GET['role'] ?? $_POST['role'] ?? ''));
$rolesToTry = ($requestedRole === 'Administrator')
    ? ['Administrator', 'Security Guard']
    : ['Security Guard', 'Administrator'];

$actorId = '';
$actorRole = '';

foreach ($rolesToTry as $roleCandidate) {
    secsysStartRoleSession($roleCandidate);
    if (!empty($_SESSION['username'])) {
        $actorRole = (string)($_SESSION['role'] ?? $roleCandidate);
        $actorId = trim((string)($_SESSION['guard_id'] ?? $_SESSION['username'] ?? $actorRole));
        break;
    }
}

// Release PHP session lock immediately so concurrent polling/streams never block
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($actorId === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized session.'
    ]);
    exit();
}

/* =========================================================
   DATABASE CONNECTION
========================================================= */
$conn = @new mysqli('localhost:3307', 'root', '', 'security_system');
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.'
    ]);
    exit();
}
$conn->set_charset('utf8mb4');
secsysEnsureSchema($conn);

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? 'status'));

/* =========================================================
   1. EXTEND VISITOR DURATION (+5m, +15m, +30m, +1h, etc.)
========================================================= */
if ($action === 'extend_duration' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $visitorId = (int)($_POST['visitor_id'] ?? 0);
    $plateRaw  = strtoupper(trim((string)($_POST['plate_number'] ?? '')));
    $extraMins = (int)($_POST['extra_minutes'] ?? 15);
    $extraMins = max(1, min(1440, $extraMins));

    if ($visitorId <= 0 && $plateRaw === '') {
        echo json_encode(['success' => false, 'message' => 'Missing visitor identifier.']);
        $conn->close();
        exit();
    }

    // Locate active visitor_vehicles record
    if ($visitorId > 0) {
        $stmtFind = $conn->prepare("SELECT id, plate_number, purpose, allowed_minutes, time_in, expected_time_out FROM visitor_vehicles WHERE id = ? LIMIT 1");
        $stmtFind->bind_param('i', $visitorId);
    } else {
        $plateNoSp = str_replace(' ', '', $plateRaw);
        $stmtFind = $conn->prepare("
            SELECT id, plate_number, purpose, allowed_minutes, time_in, expected_time_out
            FROM visitor_vehicles
            WHERE REPLACE(UPPER(plate_number), ' ', '') = ?
              AND status = 'IN'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtFind->bind_param('s', $plateNoSp);
    }

    $vvRow = null;
    if ($stmtFind) {
        $stmtFind->execute();
        $resFind = $stmtFind->get_result();
        $vvRow = $resFind ? $resFind->fetch_assoc() : null;
        $stmtFind->close();
    }

    if (!$vvRow) {
        echo json_encode(['success' => false, 'message' => 'Active visitor vehicle not found.']);
        $conn->close();
        exit();
    }

    $vvId = (int)$vvRow['id'];
    $plateClean = (string)$vvRow['plate_number'];
    $resolved = secsysResolveVisitorDuration(
        (string)($vvRow['purpose'] ?? ''),
        $vvRow['allowed_minutes'] !== null ? (int)$vvRow['allowed_minutes'] : null
    );
    $baseMins = max(1, (int)$resolved['allowed_minutes']);

    // Extend from whichever is later: current expected_time_out or NOW()
    $updVv = $conn->prepare("
        UPDATE visitor_vehicles
        SET expected_time_out = DATE_ADD(
                GREATEST(
                    COALESCE(
                        CASE WHEN expected_time_out >= COALESCE(time_in, date_created) THEN expected_time_out ELSE NULL END,
                        DATE_ADD(COALESCE(time_in, NOW()), INTERVAL ? MINUTE)
                    ),
                    NOW()
                ),
                INTERVAL ? MINUTE
            ),
            allowed_minutes = TIMESTAMPDIFF(
                MINUTE,
                COALESCE(time_in, NOW()),
                DATE_ADD(
                    GREATEST(
                        COALESCE(
                            CASE WHEN expected_time_out >= COALESCE(time_in, date_created) THEN expected_time_out ELSE NULL END,
                            DATE_ADD(COALESCE(time_in, NOW()), INTERVAL ? MINUTE)
                        ),
                        NOW()
                    ),
                    INTERVAL ? MINUTE
                )
            )
        WHERE id = ?
    ");
    if ($updVv) {
        $updVv->bind_param('iiiii', $baseMins, $extraMins, $baseMins, $extraMins, $vvId);
        $updVv->execute();
        $updVv->close();
    }

    // Sync latest vehicle_logs & vehicle_log_dates row for today
    $stmtSyncLog = $conn->prepare("
        UPDATE vehicle_logs vl
        INNER JOIN visitor_vehicles vv ON vv.id = ?
        SET vl.allowed_minutes = vv.allowed_minutes,
            vl.expected_time_out = vv.expected_time_out
        WHERE (vl.visitor_vehicle_id = vv.id OR REPLACE(UPPER(vl.plate_number), ' ', '') = REPLACE(UPPER(vv.plate_number), ' ', ''))
          AND vl.vehicle_category = 'Visitor'
          AND vl.direction = 'IN'
          AND vl.date_created >= CURDATE()
    ");
    if ($stmtSyncLog) {
        $stmtSyncLog->bind_param('i', $vvId);
        $stmtSyncLog->execute();
        $stmtSyncLog->close();
    }

    $stmtSyncVld = $conn->prepare("
        UPDATE vehicle_log_dates vld
        INNER JOIN visitor_vehicles vv ON vv.id = ?
        SET vld.allowed_minutes = vv.allowed_minutes,
            vld.expected_time_out = vv.expected_time_out
        WHERE REPLACE(UPPER(vld.plate_number), ' ', '') = REPLACE(UPPER(vv.plate_number), ' ', '')
          AND vld.vehicle_category = 'Visitor'
          AND vld.status = 'IN'
          AND vld.date_created >= CURDATE()
    ");
    if ($stmtSyncVld) {
        $stmtSyncVld->bind_param('i', $vvId);
        @$stmtSyncVld->execute();
        $stmtSyncVld->close();
    }

    secsysLogActivity(
        $conn,
        $actorId,
        'Visitor Duration Extended',
        "Extended stay duration for visitor plate $plateClean by +$extraMins mins."
    );

    $snapshot = secsysFetchActiveVisitorTimers($conn);
    $conn->close();

    echo json_encode(array_merge(
        [
            'success' => true,
            'message' => "Extended $plateClean by +$extraMins mins.",
        ],
        $snapshot
    ));
    exit();
}

/* =========================================================
   2. MANUALLY MARK VISITOR AS EXITED (OUT)
========================================================= */
if ($action === 'mark_exited' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $visitorId = (int)($_POST['visitor_id'] ?? 0);
    $plateRaw  = strtoupper(trim((string)($_POST['plate_number'] ?? '')));

    if ($visitorId <= 0 && $plateRaw === '') {
        echo json_encode(['success' => false, 'message' => 'Missing visitor identifier.']);
        $conn->close();
        exit();
    }

    if ($visitorId > 0) {
        $stmtFind = $conn->prepare("SELECT * FROM visitor_vehicles WHERE id = ? LIMIT 1");
        $stmtFind->bind_param('i', $visitorId);
    } else {
        $plateNoSp = str_replace(' ', '', $plateRaw);
        $stmtFind = $conn->prepare("
            SELECT * FROM visitor_vehicles
            WHERE REPLACE(UPPER(plate_number), ' ', '') = ?
              AND status = 'IN'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtFind->bind_param('s', $plateNoSp);
    }

    $vvRow = null;
    if ($stmtFind) {
        $stmtFind->execute();
        $resFind = $stmtFind->get_result();
        $vvRow = $resFind ? $resFind->fetch_assoc() : null;
        $stmtFind->close();
    }

    if (!$vvRow) {
        echo json_encode(['success' => false, 'message' => 'Visitor vehicle record not found.']);
        $conn->close();
        exit();
    }

    $vvId       = (int)$vvRow['id'];
    $plateClean = (string)$vvRow['plate_number'];
    $vType      = secsysNormalizeVehicleType($vvRow['vehicle_type'] ?? 'Car');
    $purpose    = (string)($vvRow['purpose'] ?? 'Visitor');
    $vName      = (string)($vvRow['visitor_name'] ?? 'Visitor');

    // Update visitor_vehicles to OUT
    $updOut = $conn->prepare("
        UPDATE visitor_vehicles
        SET status = 'OUT',
            date_out = CURDATE(),
            time_out = NOW()
        WHERE id = ?
    ");
    if ($updOut) {
        $updOut->bind_param('i', $vvId);
        $updOut->execute();
        $updOut->close();
    }

    // Check if latest vehicle_logs entry today is IN; if so, log corresponding OUT entry
    $plateNoSp = str_replace(' ', '', strtoupper($plateClean));
    $stmtLatest = $conn->prepare("
        SELECT id, direction
        FROM vehicle_logs
        WHERE (visitor_vehicle_id = ? OR REPLACE(UPPER(plate_number), ' ', '') = ?)
          AND vehicle_category = 'Visitor'
          AND date_created >= CURDATE()
        ORDER BY id DESC
        LIMIT 1
    ");
    $needOutLog = true;
    if ($stmtLatest) {
        $stmtLatest->bind_param('is', $vvId, $plateNoSp);
        $stmtLatest->execute();
        $resLatest = $stmtLatest->get_result();
        if ($rLat = $resLatest->fetch_assoc()) {
            if (strtoupper((string)$rLat['direction']) === 'OUT') {
                $needOutLog = false;
            }
        }
        $stmtLatest->close();
    }

    if ($needOutLog) {
        $insOut = $conn->prepare("
            INSERT INTO vehicle_logs
                (plate_number, vehicle_type, vehicle_category, visitor_vehicle_id, visitor_name, purpose, direction, recognition_method, date_out, date_created)
            VALUES
                (?, ?, 'Visitor', ?, ?, ?, 'OUT', 'Manual', CURDATE(), NOW())
        ");
        if ($insOut) {
            $insOut->bind_param('ssiss', $plateClean, $vType, $vvId, $vName, $purpose);
            @$insOut->execute();
            $insOut->close();
        }
    }

    // Close open vehicle_log_dates record if present
    $updVld = $conn->prepare("
        UPDATE vehicle_log_dates
        SET status = 'OUT',
            date_out = CURDATE(),
            time_out = NOW()
        WHERE REPLACE(UPPER(plate_number), ' ', '') = ?
          AND vehicle_category = 'Visitor'
          AND status = 'IN'
    ");
    if ($updVld) {
        $updVld->bind_param('s', $plateNoSp);
        @$updVld->execute();
        $updVld->close();
    }

    secsysLogActivity(
        $conn,
        $actorId,
        'Visitor Marked Exited',
        "Manually marked visitor vehicle $plateClean ($purpose) as OUT."
    );

    $snapshot = secsysFetchActiveVisitorTimers($conn);
    $conn->close();

    echo json_encode(array_merge(
        [
            'success' => true,
            'message' => "Marked $plateClean as Exited (OUT).",
        ],
        $snapshot
    ));
    exit();
}

/* =========================================================
   3. DEFAULT: FETCH LIVE VISITOR DURATION TIMERS & ALARMS
========================================================= */
$snapshot = secsysFetchActiveVisitorTimers($conn);
$conn->close();

echo json_encode(array_merge(
    [
        'success'          => true,
        'role'             => $actorRole,
        'duration_presets' => SECSYS_DURATION_PRESETS,
    ],
    $snapshot
));
