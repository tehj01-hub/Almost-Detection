<?php
require_once __DIR__ . '/session_helper.php';
require_once __DIR__ . '/system_config.php';
secsysStartRoleSession('Security Guard');

/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION['username'])) {

    /* The guard session is empty. If the Administrator is
       logged in on this browser, send them back to their
       dashboard instead of treating them as a guard. */
    secsysStartRoleSession('Administrator');

    if (isset($_SESSION['username'])) {
        header("Location: superadmin_dashboard.php");
        exit();
    }

    header("Location: login.php");
    exit();
}


/* =========================================================
   ROLE CHECK
========================================================= */

if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'Security Guard'
) {
    header("Location: superadmin_dashboard.php");
    exit();
}

if (empty($_SESSION['guard_id'])) {
    header("Location: logout.php?role=Security%20Guard");
    exit();
}


/* =========================================================
   DATABASE CONNECTION
   Database: security_system
========================================================= */

$conn = new mysqli(
    "localhost:3307",
    "root",
    "",
    "security_system"
);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

/* =========================================================
   FAST-PATH AJAX DISPATCHER
   Bypasses heavy dashboard computations, schema checks, and
   releases PHP session locks immediately for instant response.
========================================================= */

$ajaxAction = $_POST['action'] ?? $_GET['action'] ?? '';

if ($ajaxAction !== '') {

    $guardID = trim($_SESSION['guard_id'] ?? '');
    $partnerGuardID = trim($_SESSION['partner_guard_id'] ?? '');
    // Release PHP session lock immediately so parallel requests never wait/block
    session_write_close();

    $today = date("Y-m-d");

    /* ---------------------------------------------------------
       1. SAVE VISITOR INFORMATION (INSTANT SAVE)
    --------------------------------------------------------- */
    if ($ajaxAction === 'save_visitor_info' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header("Content-Type: application/json; charset=utf-8");

        $logId = (int)($_POST['log_id'] ?? 0);
        $rawPlate = strtoupper(trim($_POST['plate_number'] ?? ''));
        $plateNormalized = preg_replace('/[^A-Z0-9 ]/', '', $rawPlate);
        $plateClean = trim(preg_replace('/\s+/', ' ', $plateNormalized));
        $plateNoSpaces = str_replace(' ', '', $plateClean);
        $vehicleType = trim($_POST['vehicle_type'] ?? 'Car');
        if (!in_array($vehicleType, ['Car', 'Motorcycle', 'Truck'], true)) {
            $vehicleType = 'Car';
        }
        $purpose = trim($_POST['purpose'] ?? '');

        if ($plateClean === '' || $purpose === '') {
            echo json_encode([
                'success' => false,
                'message' => 'Please enter the Plate Number, Vehicle Type, and Purpose.'
            ]);
            $conn->close();
            exit();
        }

        // Allowed duration mapping
        $purposeDurationMap = [
            'Birthday - 12 Hours'          => 720,
            'Visiting Resident - 12 Hours' => 720,
            'Delivery - 20 Mins'           => 20,
            'Drop - off/on - 15 Mins'      => 15,
            'Service Maintenance - 8 Hours'=> 480,
        ];
        $allowedMinutes = $purposeDurationMap[$purpose] ?? null;
        if ($allowedMinutes === null) {
            $pLower = strtolower($purpose);
            if (strpos($pLower, 'birthday') !== false || strpos($pLower, 'visiting') !== false) {
                $allowedMinutes = 720;
            } elseif (strpos($pLower, 'delivery') !== false) {
                $allowedMinutes = 20;
            } elseif (strpos($pLower, 'drop') !== false) {
                $allowedMinutes = 15;
            } elseif (strpos($pLower, 'service') !== false || strpos($pLower, 'maintenance') !== false) {
                $allowedMinutes = 480;
            } else {
                $allowedMinutes = 720;
            }
        }

        // Step 1: Upsert into visitor_vehicles for today using indexed lookup
        $visitorVehicleId = null;
        $findVv = $conn->prepare("
            SELECT id
            FROM visitor_vehicles
            WHERE (plate_number = ? OR plate_number = ?)
              AND date_created >= CURDATE()
            ORDER BY id DESC
            LIMIT 1
        ");
        if ($findVv) {
            $findVv->bind_param("ss", $plateClean, $plateNoSpaces);
            $findVv->execute();
            $resVv = $findVv->get_result();
            if ($rowVv = $resVv->fetch_assoc()) {
                $visitorVehicleId = (int)$rowVv['id'];
            }
            $findVv->close();
        }

        if ($visitorVehicleId) {
            $updVv = $conn->prepare("
                UPDATE visitor_vehicles
                SET plate_number = ?,
                    vehicle_type = ?,
                    purpose = ?,
                    allowed_minutes = ?,
                    date_in = COALESCE(date_in, CURDATE()),
                    expected_time_out = DATE_ADD(COALESCE(time_in, NOW()), INTERVAL ? MINUTE)
                WHERE id = ?
            ");
            if ($updVv) {
                $updVv->bind_param("sssiii", $plateClean, $vehicleType, $purpose, $allowedMinutes, $allowedMinutes, $visitorVehicleId);
                $updVv->execute();
                $updVv->close();
            }
        } else {
            $insVv = $conn->prepare("
                INSERT INTO visitor_vehicles
                    (visitor_name, address_to_visit, vehicle_type, plate_number, purpose, allowed_minutes, status, date_in, time_in, expected_time_out, date_created)
                VALUES
                    ('Visitor', '-', ?, ?, ?, ?, 'IN', CURDATE(), NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())
            ");
            if ($insVv) {
                $insVv->bind_param("sssii", $vehicleType, $plateClean, $purpose, $allowedMinutes, $allowedMinutes);
                if ($insVv->execute()) {
                    $visitorVehicleId = (int)$conn->insert_id;
                }
                $insVv->close();
            }
        }

        // Step 2: Update existing vehicle_logs row (fast indexed lookup or primary key)
        $direction = 'IN';

        if ($logId <= 0) {
            $findLog = $conn->prepare("
                SELECT id, direction
                FROM vehicle_logs
                WHERE (plate_number = ? OR plate_number = ?)
                  AND vehicle_category = 'Visitor'
                  AND date_created >= CURDATE()
                ORDER BY id DESC
                LIMIT 1
            ");
            if ($findLog) {
                $findLog->bind_param("ss", $plateClean, $plateNoSpaces);
                $findLog->execute();
                $resLog = $findLog->get_result();
                if ($rowLog = $resLog->fetch_assoc()) {
                    $logId = (int)$rowLog['id'];
                    $direction = $rowLog['direction'] ?: 'IN';
                }
                $findLog->close();
            }
        }

        if ($logId > 0) {
            $updLog = $conn->prepare("
                UPDATE vehicle_logs
                SET plate_number = ?,
                    vehicle_type = ?,
                    purpose = ?,
                    allowed_minutes = ?,
                    expected_time_out = DATE_ADD(date_created, INTERVAL ? MINUTE),
                    date_in = COALESCE(date_in, DATE(date_created)),
                    visitor_vehicle_id = ?
                WHERE id = ?
            ");
            if ($updLog) {
                $updLog->bind_param("sssiiii", $plateClean, $vehicleType, $purpose, $allowedMinutes, $allowedMinutes, $visitorVehicleId, $logId);
                $updLog->execute();
                $updLog->close();
            }
        } else {
            $insLog = $conn->prepare("
                INSERT INTO vehicle_logs
                    (plate_number, vehicle_type, vehicle_category, visitor_vehicle_id, visitor_name, purpose, allowed_minutes, expected_time_out, direction, recognition_method, date_in, date_created)
                VALUES
                    (?, ?, 'Visitor', ?, 'Visitor', ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 'IN', 'Manual', CURDATE(), NOW())
            ");
            if ($insLog) {
                $insLog->bind_param("ssisii", $plateClean, $vehicleType, $visitorVehicleId, $purpose, $allowedMinutes, $allowedMinutes);
                if ($insLog->execute()) {
                    $logId = (int)$conn->insert_id;
                }
                $insLog->close();
            }
        }

        // Step 3: Fast update or insert into vehicle_log_dates
        if ($logId > 0) {
            $checkVld = $conn->prepare("SELECT id FROM vehicle_log_dates WHERE vehicle_log_id = ? LIMIT 1");
            if ($checkVld) {
                $checkVld->bind_param("i", $logId);
                $checkVld->execute();
                $resVld = $checkVld->get_result();
                if ($rowVld = $resVld->fetch_assoc()) {
                    $vldId = (int)$rowVld['id'];
                    $updVld = $conn->prepare("
                        UPDATE vehicle_log_dates
                        SET plate_number = ?, vehicle_type = ?, purpose = ?, allowed_minutes = ?,
                            expected_time_out = DATE_ADD(COALESCE(time_in, NOW()), INTERVAL ? MINUTE)
                        WHERE id = ?
                    ");
                    if ($updVld) {
                        $updVld->bind_param("sssiii", $plateClean, $vehicleType, $purpose, $allowedMinutes, $allowedMinutes, $vldId);
                        $updVld->execute();
                        $updVld->close();
                    }
                } else {
                    $insVld = $conn->prepare("
                        INSERT INTO vehicle_log_dates
                            (vehicle_log_id, plate_number, vehicle_category, vehicle_type, purpose, allowed_minutes, date_in, time_in, expected_time_out, status)
                        VALUES
                            (?, ?, 'Visitor', ?, ?, ?, CURDATE(), NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), 'IN')
                    ");
                    if ($insVld) {
                        $insVld->bind_param("isssii", $logId, $plateClean, $vehicleType, $purpose, $allowedMinutes, $allowedMinutes);
                        $insVld->execute();
                        $insVld->close();
                    }
                }
                $checkVld->close();
            }
        }

        echo json_encode([
            'success' => true,
            'log' => [
                'id' => $logId,
                'plate_number' => $plateClean,
                'vehicle_category' => 'Visitor',
                'vehicle_type' => $vehicleType,
                'purpose' => $purpose,
                'direction' => $direction
            ]
        ]);

        $conn->close();
        exit();
    }

    /* ---------------------------------------------------------
       2. LIVE FEED (POLLING - ULTRA FAST)
    --------------------------------------------------------- */
    if ($ajaxAction === 'live_feed' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        header("Content-Type: application/json; charset=utf-8");

        $sinceId = (int)($_GET['since_id'] ?? 0);
        $newLogs = [];
        $maxId = $sinceId;

        $stmtNew = $conn->prepare("
            SELECT
                vl.id,
                vl.plate_number,
                vl.vehicle_category,
                vl.vehicle_type,
                COALESCE(NULLIF(vl.purpose, ''), vv.purpose, '') AS purpose,
                vl.direction,
                vl.date_created
            FROM vehicle_logs vl
            LEFT JOIN visitor_vehicles vv
                ON vl.visitor_vehicle_id = vv.id
            WHERE vl.date_created >= CURDATE()
              AND vl.id > ?
            ORDER BY vl.id ASC
            LIMIT 20
        ");

        if ($stmtNew) {
            $stmtNew->bind_param("i", $sinceId);
            $stmtNew->execute();
            $resNew = $stmtNew->get_result();
            while ($rowNew = $resNew->fetch_assoc()) {
                $rowNew['id'] = (int)$rowNew['id'];
                if ($rowNew['id'] > $maxId) {
                    $maxId = $rowNew['id'];
                }
                $newLogs[] = $rowNew;
            }
            $stmtNew->close();
        }

        // Fast count totals using index
        $totalRegisteredToday = 0;
        $totalVisitorsToday = 0;
        $qTotals = $conn->query("
            SELECT 
                COALESCE(SUM(CASE WHEN vehicle_category = 'Registered' AND direction = 'IN' THEN 1 ELSE 0 END), 0) AS reg_tot,
                COALESCE(SUM(CASE WHEN vehicle_category = 'Visitor' AND direction = 'IN' THEN 1 ELSE 0 END), 0) AS vis_tot
            FROM vehicle_logs
            WHERE date_created >= CURDATE()
        ");
        if ($qTotals && $rowTot = $qTotals->fetch_assoc()) {
            $totalRegisteredToday = (int)$rowTot['reg_tot'];
            $totalVisitorsToday = (int)$rowTot['vis_tot'];
        }

        // Overstay checks (only if visitors exist)
        $overdueVisitors = [];
        $dueSoonVisitors = [];

        $stmtOverstay = $conn->prepare("
            SELECT
                vv.id,
                vv.plate_number,
                vv.vehicle_type,
                vv.purpose,
                vv.allowed_minutes,
                DATE_FORMAT(vv.time_in, '%h:%i %p') AS time_in_formatted,
                DATE_FORMAT(vv.expected_time_out, '%h:%i %p') AS expected_time_out_formatted,
                TIMESTAMPDIFF(MINUTE, NOW(), vv.expected_time_out) AS minutes_remaining
            FROM visitor_vehicles vv
            WHERE vv.status = 'IN'
              AND vv.date_created >= CURDATE()
              AND vv.expected_time_out IS NOT NULL
            ORDER BY vv.expected_time_out ASC
        ");
        if ($stmtOverstay) {
            $stmtOverstay->execute();
            $resOverstay = $stmtOverstay->get_result();
            while ($rowO = $resOverstay->fetch_assoc()) {
                $rem = (int)$rowO['minutes_remaining'];
                if ($rem < 0) {
                    $rowO['minutes_overdue'] = abs($rem);
                    $overdueVisitors[] = $rowO;
                } elseif ($rem <= 15) {
                    $rowO['minutes_left'] = $rem;
                    $dueSoonVisitors[] = $rowO;
                }
            }
            $stmtOverstay->close();
        }

        echo json_encode([
            'success' => true,
            'new_logs' => $newLogs,
            'max_id' => $maxId,
            'total_registered' => $totalRegisteredToday,
            'total_visitors' => $totalVisitorsToday,
            'overdue_visitors' => $overdueVisitors,
            'due_soon_visitors' => $dueSoonVisitors
        ]);

        $conn->close();
        exit();
    }

    /* ---------------------------------------------------------
       3. GUARD HEARTBEAT
    --------------------------------------------------------- */
    if ($ajaxAction === 'heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header("Content-Type: application/json; charset=utf-8");

        if ($guardID === '') {
            echo json_encode(['success' => false, 'message' => 'Guard session not found.']);
            $conn->close();
            exit();
        }

        $heartbeat = $conn->prepare("
            UPDATE security_guard
            SET login_status = 'Active', last_seen = NOW()
            WHERE guard_id = ? AND status = 'Active'
            LIMIT 1
        ");

        if ($heartbeat) {
            $heartbeat->bind_param("s", $guardID);
            $heartbeat->execute();

            if ($partnerGuardID !== '') {
                $heartbeat->bind_param("s", $partnerGuardID);
                $heartbeat->execute();
            }

            $success = ($heartbeat->affected_rows >= 0);
            $heartbeat->close();
            echo json_encode(['success' => $success]);
        } else {
            echo json_encode(['success' => false]);
        }

        $conn->close();
        exit();
    }
}

/* =========================================================
   FULL DASHBOARD PAGE INITIALIZATION
   (Runs only on full page loads, not on fast AJAX requests)
========================================================= */

secsysEnsureSchema($conn);
$systemName = secsysGetSystemName($conn);
$systemIcon = secsysGetSystemIcon($conn);


/* =========================================================
   INITIAL HEARTBEAT
   Immediately records the guard as online.
========================================================= */

$guardID = trim(
    $_SESSION['guard_id'] ?? ''
);
$partnerGuardID = trim(
    $_SESSION['partner_guard_id'] ?? ''
);
$primaryGuardName = trim($_SESSION['guard_full_name'] ?? $_SESSION['username'] ?? '');
$partnerGuardName = trim($_SESSION['partner_guard_name'] ?? '');

if ($guardID !== '') {

    $initialHeartbeat = $conn->prepare("
        UPDATE security_guard
        SET
            login_status = 'Active',
            last_seen = NOW()
        WHERE guard_id = ?
        LIMIT 1
    ");

    if ($initialHeartbeat) {

        $initialHeartbeat->bind_param(
            "s",
            $guardID
        );
        $initialHeartbeat->execute();

        if ($partnerGuardID !== '') {
            $initialHeartbeat->bind_param("s", $partnerGuardID);
            $initialHeartbeat->execute();
        }

        $initialHeartbeat->close();

    }

}


/* =========================================================
   TODAY'S DATE
========================================================= */

$today = date("Y-m-d");

$displayDate = date("l, F d, Y");


/* =========================================================
   DEFAULT STATISTICS
========================================================= */

$registeredMotorcycleIn = 0;
$registeredMotorcycleOut = 0;
$registeredCarIn = 0;
$registeredCarOut = 0;
$registeredTruckIn = 0;
$registeredTruckOut = 0;

$visitorMotorcycleIn = 0;
$visitorMotorcycleOut = 0;
$visitorCarIn = 0;
$visitorCarOut = 0;
$visitorTruckIn = 0;
$visitorTruckOut = 0;


/* =========================================================
   GET TODAY'S VEHICLE LOG STATISTICS
========================================================= */

$sql = "
    SELECT
        vehicle_category,
        vehicle_type,
        direction,
        COUNT(*) AS total
    FROM vehicle_logs
    WHERE DATE(date_created) = ?
    GROUP BY vehicle_category, vehicle_type, direction
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Query Error: " . $conn->error);
}

$stmt->bind_param("s", $today);

$stmt->execute();

$result = $stmt->get_result();


while ($row = $result->fetch_assoc()) {

    $category = strtolower(trim($row['vehicle_category']));
    $type = strtolower(trim($row['vehicle_type']));
    $direction = strtoupper(trim($row['direction']));
    $total = (int)$row['total'];


    /* =====================================================
       REGISTERED VEHICLES
    ===================================================== */

    if ($category === 'registered') {

        if ($type === 'motorcycle') {

            if ($direction === 'IN') {
                $registeredMotorcycleIn = $total;
            }

            if ($direction === 'OUT') {
                $registeredMotorcycleOut = $total;
            }
        }

        elseif ($type === 'car') {

            if ($direction === 'IN') {
                $registeredCarIn = $total;
            }

            if ($direction === 'OUT') {
                $registeredCarOut = $total;
            }
        }

        elseif ($type === 'truck') {

            if ($direction === 'IN') {
                $registeredTruckIn = $total;
            }

            if ($direction === 'OUT') {
                $registeredTruckOut = $total;
            }
        }
    }


    /* =====================================================
       VISITOR VEHICLES
    ===================================================== */

    if ($category === 'visitor') {

        if ($type === 'motorcycle') {

            if ($direction === 'IN') {
                $visitorMotorcycleIn = $total;
            }

            if ($direction === 'OUT') {
                $visitorMotorcycleOut = $total;
            }
        }

        elseif ($type === 'car') {

            if ($direction === 'IN') {
                $visitorCarIn = $total;
            }

            if ($direction === 'OUT') {
                $visitorCarOut = $total;
            }
        }

        elseif ($type === 'truck') {

            if ($direction === 'IN') {
                $visitorTruckIn = $total;
            }

            if ($direction === 'OUT') {
                $visitorTruckOut = $total;
            }
        }
    }
}

$stmt->close();


/* =========================================================
   TOTAL VEHICLES ENTERED TODAY
========================================================= */

$totalRegisteredToday =
    $registeredMotorcycleIn +
    $registeredCarIn +
    $registeredTruckIn;

$totalVisitorsToday =
    $visitorMotorcycleIn +
    $visitorCarIn +
    $visitorTruckIn;


/* =========================================================
   HOURLY GRAPH DATA
========================================================= */

$hours = [];

$registeredHourly = [];
$visitorHourly = [];
$motorcycleHourly = [];
$carHourly = [];


for ($hour = 0; $hour < 24; $hour++) {

    $hours[] = date(
        "g A",
        strtotime(sprintf("%02d:00:00", $hour))
    );

    $registeredHourly[] = 0;
    $visitorHourly[] = 0;
    $motorcycleHourly[] = 0;
    $carHourly[] = 0;
}


/* =========================================================
   GET HOURLY VEHICLE DATA
========================================================= */

$sqlHourly = "
    SELECT
        HOUR(date_created) AS log_hour,

        SUM(
            CASE
                WHEN vehicle_category = 'Registered'
                THEN 1
                ELSE 0
            END
        ) AS registered_total,

        SUM(
            CASE
                WHEN vehicle_category = 'Visitor'
                THEN 1
                ELSE 0
            END
        ) AS visitor_total,

        SUM(
            CASE
                WHEN vehicle_type = 'Motorcycle'
                THEN 1
                ELSE 0
            END
        ) AS motorcycle_total,

        SUM(
            CASE
                WHEN vehicle_type = 'Car'
                THEN 1
                ELSE 0
            END
        ) AS car_total

    FROM vehicle_logs

    WHERE DATE(date_created) = ?

    GROUP BY HOUR(date_created)

    ORDER BY HOUR(date_created)
";

$stmtHourly = $conn->prepare($sqlHourly);

if (!$stmtHourly) {
    die("Hourly Query Error: " . $conn->error);
}

$stmtHourly->bind_param("s", $today);

$stmtHourly->execute();

$resultHourly = $stmtHourly->get_result();


while ($row = $resultHourly->fetch_assoc()) {

    $hour = (int)$row['log_hour'];

    $registeredHourly[$hour] =
        (int)$row['registered_total'];

    $visitorHourly[$hour] =
        (int)$row['visitor_total'];

    $motorcycleHourly[$hour] =
        (int)$row['motorcycle_total'];

    $carHourly[$hour] =
        (int)$row['car_total'];
}

$stmtHourly->close();


/* =========================================================
   RECENT VEHICLE ACTIVITY (STACK UP TO 5 PER CATEGORY)
========================================================= */

$recentVisitorVehicles = [];
$recentRegisteredVehicles = [];
$latestLogIdToday = 0;

$sqlMaxId = $conn->prepare("
    SELECT COALESCE(MAX(id), 0) AS max_id
    FROM vehicle_logs
    WHERE DATE(date_created) = ?
");
if ($sqlMaxId) {
    $sqlMaxId->bind_param("s", $today);
    $sqlMaxId->execute();
    $resMax = $sqlMaxId->get_result();
    if ($rowMax = $resMax->fetch_assoc()) {
        $latestLogIdToday = (int)$rowMax['max_id'];
    }
    $sqlMaxId->close();
}

$sqlRecentVisitors = "
    SELECT
        vl.id,
        vl.plate_number,
        vl.vehicle_category,
        vl.vehicle_type,
        COALESCE(NULLIF(vl.purpose, ''), vv.purpose, '') AS purpose,
        vl.direction,
        vl.date_created
    FROM vehicle_logs vl
    LEFT JOIN visitor_vehicles vv
        ON vl.visitor_vehicle_id = vv.id
    WHERE DATE(vl.date_created) = ?
      AND LOWER(TRIM(vl.vehicle_category)) = 'visitor'
    ORDER BY vl.id DESC
    LIMIT 5
";

$stmtRecentVis = $conn->prepare($sqlRecentVisitors);
if ($stmtRecentVis) {
    $stmtRecentVis->bind_param("s", $today);
    $stmtRecentVis->execute();
    $resRecentVis = $stmtRecentVis->get_result();
    while ($row = $resRecentVis->fetch_assoc()) {
        $recentVisitorVehicles[] = $row;
    }
    $stmtRecentVis->close();
}

$sqlRecentRegistered = "
    SELECT
        vl.id,
        vl.plate_number,
        vl.vehicle_category,
        vl.vehicle_type,
        vl.direction,
        vl.date_created
    FROM vehicle_logs vl
    WHERE DATE(vl.date_created) = ?
      AND LOWER(TRIM(vl.vehicle_category)) = 'registered'
    ORDER BY vl.id DESC
    LIMIT 5
";

$stmtRecentReg = $conn->prepare($sqlRecentRegistered);
if ($stmtRecentReg) {
    $stmtRecentReg->bind_param("s", $today);
    $stmtRecentReg->execute();
    $resRecentReg = $stmtRecentReg->get_result();
    while ($row = $resRecentReg->fetch_assoc()) {
        $recentRegisteredVehicles[] = $row;
    }
    $stmtRecentReg->close();
}

/* Initial vehicle logs today for seeding Notification Drawer */
$initialLogsToday = [];
$stmtInitialLogs = $conn->prepare("
    SELECT
        vl.id,
        vl.plate_number,
        vl.vehicle_category,
        vl.vehicle_type,
        COALESCE(NULLIF(vl.purpose, ''), vv.purpose, '') AS purpose,
        vl.direction,
        vl.date_created
    FROM vehicle_logs vl
    LEFT JOIN visitor_vehicles vv
        ON vl.visitor_vehicle_id = vv.id
    WHERE DATE(vl.date_created) = ?
    ORDER BY vl.id DESC
    LIMIT 30
");
if ($stmtInitialLogs) {
    $stmtInitialLogs->bind_param("s", $today);
    $stmtInitialLogs->execute();
    $resInit = $stmtInitialLogs->get_result();
    while ($rowInit = $resInit->fetch_assoc()) {
        $initialLogsToday[] = $rowInit;
    }
    $stmtInitialLogs->close();
}



$conn->close();

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>
    Dashboard | <?php echo htmlspecialchars($systemName); ?>
</title>


<!-- =====================================================
     CHART.JS
====================================================== -->

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


<style>

/* =========================================================
   RESET
========================================================= */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html,
body {
    width: 100%;
    min-height: 100%;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f7f7f7;
    color: #111;
}


/* =========================================================
   SIDEBAR
========================================================= */

.sidebar {

    position: fixed;

    top: 0;
    left: 0;

    width: 212px;
    height: 100vh;

    background: #ffffff;

    border-right: 1px solid #dddddd;

    padding: 32px 14px;

    z-index: 1000;

    display: flex;

    flex-direction: column;

    font-family: Arial, Helvetica, sans-serif;
    box-sizing: border-box;
}


/* =========================================================
   LOGO
========================================================= */

.logo {

    display: flex;

    align-items: center;

    gap: 10px;

    padding-left: 3px;

    margin-bottom: 38px;

    font-family: Arial, Helvetica, sans-serif;
}


.logo-shield {

    width: 34px;
    height: 38px;

    background: #1478e8;

    clip-path: polygon(
        50% 0%,
        90% 15%,
        90% 58%,
        75% 82%,
        50% 100%,
        25% 82%,
        10% 58%,
        10% 15%
    );

    display: flex;

    align-items: center;

    justify-content: center;

    color: white;

    font-size: 18px;

    font-weight: bold;
    flex-shrink: 0;
    overflow: hidden;
    font-family: Arial, Helvetica, sans-serif;
}


.logo-shield img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.logo-text {
    line-height: 17px;
    font-family: Arial, Helvetica, sans-serif;
}


.logo-title {

    font-size: 17px;

    font-weight: 500;

    white-space: nowrap;
    color: #111;
    font-family: Arial, Helvetica, sans-serif;
}


.logo-subtitle {

    font-size: 12px;

    color: #444;
    font-family: Arial, Helvetica, sans-serif;
}


/* =========================================================
   NAVIGATION
========================================================= */

.nav {

    display: flex;

    flex-direction: column;

    gap: 7px;

    width: 100%;
    font-family: Arial, Helvetica, sans-serif;
}


.nav a {

    width: 100%;

    min-height: 43px;

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 0 14px;

    border-radius: 22px;

    text-decoration: none;

    color: #111;

    font-size: 16px;
    font-family: Arial, Helvetica, sans-serif;

    transition:
        background 0.2s ease,
        color 0.2s ease;
    box-sizing: border-box;
}


.nav a:hover {

    background: #edf5ff;
}


.nav a.active {

    background: #2f80ed;

    color: white;
}


.nav-icon {

    width: 21px;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 18px;

    flex-shrink: 0;
    overflow: hidden;
    font-family: Arial, Helvetica, sans-serif;
}


/* =========================================================
   LOGOUT
========================================================= */

.logout {

    margin-top: auto;

    width: 100%;

    min-height: 38px;

    border: 1px solid #dddddd;

    border-radius: 18px;

    background: white;

    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.08);

    display: flex;

    align-items: center;

    justify-content: center;

    color: #111;

    text-decoration: none;

    font-size: 15px;
    font-family: Arial, Helvetica, sans-serif;

    transition: background 0.2s ease;
    box-sizing: border-box;
}


.logout:hover {

    background: #f3f3f3;
}


/* =========================================================
   MAIN CONTENT
========================================================= */

.main {

    margin-left: 212px;

    min-height: 100vh;

    padding: 0 38px 40px;

    width: calc(100% - 212px);
    box-sizing: border-box;
}


/* =========================================================
   HEADER
========================================================= */

.header {

    min-height: 82px;

    border-bottom: 1px solid #e4e4e4;

    margin: 0 -38px 21px;

    padding: 0 38px;

    display: flex;

    align-items: center;

    justify-content: space-between;
}

/* =========================================================
   NOTIFICATION BUTTON, DRAWER, AND OVERSTAY REMINDER ALARM
========================================================= */

.header-right {
    display: flex;
    align-items: center;
    gap: 16px;
}

.btn-notif-bell {
    position: relative;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 50%;
    width: 44px;
    height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    transition: all 0.18s ease;
}

.btn-notif-bell:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    transform: translateY(-1px);
    box-shadow: 0 3px 6px rgba(0, 0, 0, 0.12);
}

.btn-notif-bell .bell-icon {
    font-size: 20px;
    line-height: 1;
}

.notif-badge {
    position: absolute;
    top: -3px;
    right: -3px;
    background: #ef4444;
    color: #ffffff;
    font-size: 10px;
    font-weight: 800;
    min-width: 18px;
    height: 18px;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    border: 2px solid #ffffff;
    box-sizing: border-box;
    animation: notifPulse 1.8s infinite;
}

@keyframes notifPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.18); }
}

/* OVERSTAY REMINDER ALERT BANNER */
.overstay-alert-banner {
    background: #fff1f2;
    border: 1px solid #fecdd3;
    border-left: 6px solid #e11d48;
    border-radius: 12px;
    padding: 12px 18px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    box-shadow: 0 3px 10px rgba(225, 29, 72, 0.12);
    animation: alertSlideDown 0.3s ease-out;
}

@keyframes alertSlideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}

.overstay-alert-content {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.overstay-alert-icon {
    font-size: 22px;
    animation: alertBounce 1s infinite alternate;
}

@keyframes alertBounce {
    from { transform: scale(1); }
    to { transform: scale(1.22); }
}

.overstay-alert-text {
    font-size: 13px;
    color: #881337;
    line-height: 1.4;
}

.overstay-alert-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.btn-banner-mute {
    background: #ffffff;
    border: 1px solid #dc2626;
    color: #dc2626;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
}
.btn-banner-mute:hover {
    background: #fee2e2;
}

.btn-view-overstay {
    background: #e11d48;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    padding: 7px 14px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s ease;
    white-space: nowrap;
}

.btn-view-overstay:hover {
    background: #be123c;
}

.btn-dismiss-alert {
    background: none;
    border: none;
    font-size: 20px;
    color: #9f1239;
    cursor: pointer;
    line-height: 1;
    padding: 2px 6px;
}

/* NOTIFICATION DRAWER */
.notif-drawer-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.45);
    backdrop-filter: blur(2px);
    z-index: 99998;
}

.notif-drawer-overlay.active {
    display: block;
}

.notif-drawer {
    position: fixed;
    top: 0;
    right: -430px;
    bottom: 0;
    width: 410px;
    max-width: 92vw;
    background: #ffffff;
    z-index: 99999;
    box-shadow: -6px 0 25px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    transition: right 0.28s cubic-bezier(0.16, 1, 0.3, 1);
}

.notif-drawer.open {
    right: 0;
}

.notif-drawer-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
}

.notif-drawer-title {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}

.notif-drawer-count {
    background: #e2e8f0;
    color: #334155;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 9999px;
}

.btn-close-drawer {
    background: none;
    border: none;
    font-size: 24px;
    color: #64748b;
    cursor: pointer;
    line-height: 1;
}

.btn-close-drawer:hover {
    color: #0f172a;
}

.notif-tabs {
    display: flex;
    padding: 8px 14px;
    gap: 6px;
    background: #f1f5f9;
    border-bottom: 1px solid #e2e8f0;
    overflow-x: auto;
}

.notif-tab {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s ease;
}

.notif-tab.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}

.notif-actions-bar {
    padding: 8px 18px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #ffffff;
}

.btn-notif-action {
    background: none;
    border: none;
    color: #2563eb;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    padding: 3px 8px;
    border-radius: 4px;
}

.btn-notif-action:hover {
    background: #f1f5f9;
}

.btn-notif-action.text-danger {
    color: #dc2626;
}

.btn-notif-action.text-danger:hover {
    background: #fee2e2;
}

.notif-list {
    flex: 1;
    overflow-y: auto;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.notif-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 12px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    transition: all 0.15s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.notif-item.notif-in {
    border-left: 4px solid #16a34a;
    background: #f0fdf4;
}

.notif-item.notif-out {
    border-left: 4px solid #dc2626;
    background: #fef2f2;
}

.notif-item.notif-overdue {
    border-left: 4px solid #e11d48;
    background: #fff1f2;
    border-color: #fecdd3;
}

.notif-item.notif-duesoon {
    border-left: 4px solid #f59e0b;
    background: #fffbeb;
    border-color: #fde68a;
}

.notif-item.unread {
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.notif-item-icon {
    font-size: 18px;
    line-height: 1;
    margin-top: 2px;
}

.notif-item-body {
    flex: 1;
    min-width: 0;
}

.notif-item-title {
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    margin-bottom: 2px;
}

.notif-item-time {
    font-size: 10px;
    color: #64748b;
    font-weight: 500;
    white-space: nowrap;
}

.notif-item-sub {
    font-size: 12px;
    color: #475569;
    line-height: 1.3;
}

.notif-empty-state {
    text-align: center;
    padding: 40px 16px;
    color: #94a3b8;
    font-size: 13px;
}

.notif-empty-icon {
    font-size: 32px;
    margin-bottom: 8px;
}

.badge-overdue-pulse {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 7px;
    border-radius: 9999px;
    font-size: 9px;
    font-weight: 800;
    background: #e11d48;
    color: #ffffff;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    animation: overdueGlow 1.2s infinite alternate;
}

@keyframes overdueGlow {
    from { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0.4); }
    to { box-shadow: 0 0 0 4px rgba(225, 29, 72, 0); }
}

.badge-duesoon {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 7px;
    border-radius: 9999px;
    font-size: 9px;
    font-weight: 800;
    background: #f59e0b;
    color: #ffffff;
    letter-spacing: 0.5px;
}




.header-left {

    display: flex;

    flex-direction: column;

    justify-content: center;
}


.header h1 {

    font-size: 25px;

    font-weight: 500;

    line-height: 1.1;
}


.header p {

    font-size: 14px;

    color: #666;

    margin-top: 3px;
}


.clock {

    font-size: 32px;

    font-weight: 300;

    color: #444;

    letter-spacing: 0.5px;
}


/* =========================================================
   CAMERA GRID (2-CAMERA TAPO C500 SYSTEM)
========================================================= */

.camera-grid {

    display: grid;

    grid-template-columns:
        repeat(2, minmax(320px, 1fr));

    gap: 16px;

    width: 100%;

    max-width: 1050px;

    margin: 0 auto;
}


/* =========================================================
   CAMERA CARD
========================================================= */

.camera-card {

    background: #ffffff;

    border: 1px solid #dedede;

    border-radius: 19px;

    padding: 13px 15px 15px;

    box-shadow:
        0 2px 5px rgba(0, 0, 0, 0.14);

    min-width: 0;

    position: relative;
}

.camera-card-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 9px;
}

.camera-title {

    font-size: 14px;

    font-weight: 600;

    color: #222;

    display: inline-flex;

    align-items: center;

    gap: 6px;

    margin-bottom: 0;
}

.camera-tag {

    font-size: 10px;

    font-weight: 600;

    color: #0b63ce;

    background: #e8f1fd;

    border: 1px solid #c8ddf8;

    padding: 2px 7px;

    border-radius: 6px;

    text-transform: uppercase;

    letter-spacing: 0.5px;
}

.btn-cam-setup {

    background: #f3f4f6;

    border: 1px solid #d1d5db;

    border-radius: 6px;

    padding: 4px 9px;

    font-size: 11px;

    font-weight: 600;

    color: #374151;

    cursor: pointer;

    display: inline-flex;

    align-items: center;

    gap: 4px;

    transition: all 0.15s ease;
}

.btn-cam-setup:hover {

    background: #e5e7eb;

    color: #111827;
}

.camera-screen {

    width: 100%;

    height: 250px;

    border-radius: 8px;

    overflow: hidden;

    background: #0f172a;

    position: relative;

    display: flex;

    align-items: center;

    justify-content: center;
}


/* =========================================================
   CAMERA IMAGE
========================================================= */

.camera-screen img {

    width: 100%;

    height: 100%;

    object-fit: contain;

    background: #000;

    display: block;
}


.camera-offline {

    color: #d1d5db;

    font-size: 13px;

    text-align: center;

    background: #111827;

    width: 100%;

    height: 100%;

    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    padding: 16px;

    box-sizing: border-box;
}

.camera-offline-icon {

    font-size: 32px;

    margin-bottom: 6px;

    opacity: 0.85;
}

.camera-offline-title {

    font-weight: 600;

    font-size: 14px;

    color: #f3f4f6;

    margin-bottom: 3px;
}

.camera-offline-sub {

    font-size: 11px;

    color: #9ca3af;

    max-width: 280px;

    line-height: 1.4;

    margin-bottom: 12px;
}

.camera-offline-actions {

    display: flex;

    gap: 8px;

    flex-wrap: wrap;

    justify-content: center;
}

.btn-offline-retry {

    background: #2563eb;

    color: #fff;

    border: none;

    border-radius: 6px;

    padding: 5px 12px;

    font-size: 11px;

    font-weight: 600;

    cursor: pointer;
}

.btn-offline-retry:hover {

    background: #1d4ed8;
}

.btn-offline-setup {

    background: rgba(255, 255, 255, 0.12);

    color: #e5e7eb;

    border: 1px solid rgba(255, 255, 255, 0.25);

    border-radius: 6px;

    padding: 5px 12px;

    font-size: 11px;

    font-weight: 600;

    cursor: pointer;
}

.btn-offline-setup:hover {

    background: rgba(255, 255, 255, 0.22);
}


/* =========================================================
   CAMERA STATUS
========================================================= */

.camera-status {

    position: absolute;

    top: 9px;

    right: 9px;

    background: rgba(0, 0, 0, 0.72);

    backdrop-filter: blur(4px);

    padding: 4px 10px;

    border-radius: 12px;

    font-size: 10px;

    font-weight: 600;

    color: #fff;

    display: inline-flex;

    align-items: center;

    gap: 6px;

    z-index: 2;
}

.status-dot {

    width: 7px;

    height: 7px;

    border-radius: 50%;

    display: inline-block;
}

.status-dot.online {

    background: #22c55e;

    box-shadow: 0 0 6px #22c55e;

    animation: camPulse 1.8s infinite;
}

.status-dot.offline {

    background: #ef4444;
}

@keyframes camPulse {

    0% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
    }

    70% {
        transform: scale(1.1);
        box-shadow: 0 0 0 6px rgba(34, 197, 94, 0);
    }

    100% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
    }
}


/* =========================================================
   TAPO C500 CONNECTION MODAL
========================================================= */

.tapo-modal-overlay {

    display: none;

    position: fixed;

    top: 0;

    left: 0;

    right: 0;

    bottom: 0;

    background: rgba(0, 0, 0, 0.55);

    backdrop-filter: blur(3px);

    z-index: 9999;

    align-items: center;

    justify-content: center;

    padding: 16px;
}

.tapo-modal-overlay.active {

    display: flex;
}

.tapo-modal-box {

    background: #ffffff;

    border-radius: 16px;

    max-width: 580px;

    width: 100%;

    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);

    overflow: hidden;

    animation: tapoModalIn 0.2s ease-out;
}

@keyframes tapoModalIn {

    from {
        opacity: 0;
        transform: translateY(-15px) scale(0.98);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.tapo-modal-header {

    padding: 16px 20px;

    border-bottom: 1px solid #e5e7eb;

    display: flex;

    justify-content: space-between;

    align-items: center;

    background: #f8fafc;
}

.tapo-modal-title {

    font-size: 16px;

    font-weight: 700;

    color: #1e293b;

    display: flex;

    align-items: center;

    gap: 8px;
}

.tapo-modal-close {

    background: none;

    border: none;

    font-size: 22px;

    color: #64748b;

    cursor: pointer;

    line-height: 1;
}

.tapo-modal-close:hover {

    color: #111827;
}

.tapo-modal-body {

    padding: 20px;

    max-height: 75vh;

    overflow-y: auto;

    font-size: 13px;

    color: #334155;
}

.tapo-guide-box {

    background: #f1f5f9;

    border-left: 4px solid #2563eb;

    border-radius: 6px;

    padding: 12px 14px;

    margin-bottom: 16px;

    line-height: 1.5;
}

.tapo-guide-box ol {

    margin: 8px 0 0 18px;

    padding: 0;
}

.tapo-guide-box li {

    margin-bottom: 4px;
}

.tapo-form-group {

    margin-bottom: 14px;
}

.tapo-form-group label {

    display: block;

    font-weight: 600;

    font-size: 12px;

    color: #1f2937;

    margin-bottom: 5px;
}

.tapo-form-group input {

    width: 100%;

    padding: 8px 12px;

    border: 1px solid #cbd5e1;

    border-radius: 8px;

    font-size: 13px;

    box-sizing: border-box;

    font-family: inherit;
}

.tapo-form-group input:focus {

    outline: none;

    border-color: #2563eb;

    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

.tapo-help-text {

    font-size: 11px;

    color: #64748b;

    margin-top: 4px;
}

.tapo-code-snippet {

    background: #0f172a;

    color: #38bdf8;

    padding: 8px 12px;

    border-radius: 6px;

    font-family: Consolas, monospace;

    font-size: 12px;

    word-break: break-all;

    margin-top: 5px;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 10px;
}

.btn-copy-code {

    background: rgba(255, 255, 255, 0.15);

    border: none;

    color: #fff;

    font-size: 11px;

    padding: 3px 8px;

    border-radius: 4px;

    cursor: pointer;

    white-space: nowrap;
}

.btn-copy-code:hover {

    background: rgba(255, 255, 255, 0.25);
}

.tapo-modal-footer {

    padding: 14px 20px;

    border-top: 1px solid #e5e7eb;

    background: #f8fafc;

    display: flex;

    justify-content: flex-end;

    gap: 10px;
}

.btn-tapo-cancel {

    background: #e2e8f0;

    border: none;

    color: #475569;

    padding: 8px 16px;

    border-radius: 8px;

    font-size: 13px;

    font-weight: 500;

    cursor: pointer;
}

.btn-tapo-save {

    background: #2563eb;

    border: none;

    color: #fff;

    padding: 8px 18px;

    border-radius: 8px;

    font-size: 13px;

    font-weight: 600;

    cursor: pointer;
}

.btn-tapo-save:hover {

    background: #1d4ed8;
}


/* =========================================================
   DIRECTION BADGES (HIGH CONTRAST IN & OUT)
========================================================= */
.dir-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 9999px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    vertical-align: middle;
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
}
.dir-badge-in {
    background: #dcfce7 !important;
    color: #15803d !important;
    border: 1px solid #86efac !important;
}
.dir-badge-out {
    background: #fee2e2 !important;
    color: #b91c1c !important;
    border: 1px solid #fca5a5 !important;
}
.type-text {
    font-size: 11px;
    font-weight: 600;
    color: #374151;
    margin-right: 4px;
}

.cam-section-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 14px;
}
.cam-presets-container {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}
.btn-cam-preset {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #334155;
    font-size: 11px;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.btn-cam-preset:hover {
    background: #e2e8f0;
    border-color: #94a3b8;
    color: #0f172a;
}



/* =========================================================
   VEHICLE ACTIVITY (ALIGNED WITH CAMERA GRID)
========================================================= */

.activity-grid {

    display: grid;

    grid-template-columns:
        repeat(2, minmax(320px, 1fr));

    gap: 16px;

    width: 100%;

    max-width: 1050px;

    margin: 16px auto 0;
}


/* =========================================================
   ACTIVITY CARD
========================================================= */

.activity-card {

    background: #baf7b4;

    border: 1px solid #dedede;

    border-radius: 21px;

    padding: 13px 15px 15px;

    box-shadow:
        0 2px 5px rgba(0, 0, 0, 0.13);

    min-width: 0;
}

.activity-cards {

    background: #b3dcf4;

    border: 1px solid #dedede;

    border-radius: 21px;

    padding: 13px 15px 15px;

    box-shadow:
        0 2px 5px rgba(0, 0, 0, 0.13);

    min-width: 0;
}

.activity-header {

    display: flex;

    align-items: center;

    justify-content: center;

    margin-bottom: 8px;

    position: relative;

    min-height: 26px;
}

.activity-title {

    text-align: center;

    font-size: 15px;

    margin-bottom: 8px;

    font-weight: 600;
}

.activity-header .activity-title {

    margin-bottom: 0;
}

.activity-stack {

    display: flex;

    flex-direction: column;

    gap: 6px;
}


/* =========================================================
   VEHICLE ITEM (STACKED, MAX 5)
========================================================= */

.vehicle-item {
    min-height: 44px;
    border-radius: 12px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    animation: stackSlideIn 0.22s ease-out;
    transition: all 0.18s ease;
    box-sizing: border-box;
}

/* VEHICLES ENTERING (IN): Entire row is soft emerald green with bold green accent bar */
.vehicle-item.row-in {
    background: #dcfce7 !important;
    border: 1px solid #86efac !important;
    border-left: 5px solid #16a34a !important;
}

.vehicle-item.row-in:hover {
    background: #bbf7d0 !important;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(22, 163, 74, 0.18);
}

.vehicle-item.row-in .plate-text {
    color: #064e3b !important;
}

/* VEHICLES EXITING (OUT): Entire row is soft crimson/rose red with bold red accent bar */
.vehicle-item.row-out {
    background: #fee2e2 !important;
    border: 1px solid #fca5a5 !important;
    border-left: 5px solid #dc2626 !important;
}

.vehicle-item.row-out:hover {
    background: #fecaca !important;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(220, 38, 38, 0.18);
}

.vehicle-item.row-out .plate-text {
    color: #881337 !important;
}

/* Fallback if no direction class */
.vehicle-item:not(.row-in):not(.row-out) {
    background: #e2e8f0;
    border: 1px solid #cbd5e1;
    border-left: 5px solid #64748b;
}

.vehicle-item.visitor-item-row {
    cursor: pointer;
}

.vehicle-item.visitor-item-out {
    cursor: pointer;
}

@keyframes stackSlideIn {

    from {
        opacity: 0;
        transform: translateY(-6px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}


.vehicle-info {

    display: flex;

    flex-direction: column;

    min-width: 0;
}


.vehicle-plate {

    font-size: 12px;

    font-weight: 600;

    color: #111;

    display: flex;

    align-items: center;

    gap: 6px;
}


.vehicle-direction {

    font-size: 10px;

    color: #4b5563;
}

.vehicle-badges {

    display: flex;

    align-items: center;

    gap: 5px;

    flex-shrink: 0;
    overflow: hidden;
}

.purpose-pill {

    background: rgba(255, 255, 255, 0.78);

    border-radius: 10px;

    padding: 3px 8px;

    font-size: 9px;

    font-weight: 600;

    color: #1e3a8a;

    max-width: 130px;

    white-space: nowrap;

    overflow: hidden;

    text-overflow: ellipsis;
}

.purpose-pill.needs-info {

    background: #fef3c7;

    color: #92400e;

    border: 1px solid #f59e0b;
}

.captured {

    background: #c5d8ef;

    border-radius: 10px;

    padding: 3px 7px;

    font-size: 8px;

    font-weight: 600;

    color: #374151;
}

.activity-card .captured {

    background: #bbf2b5;
}


/* =========================================================
   EMPTY ACTIVITY
========================================================= */

.empty-activity {

    text-align: center;

    color: #666;

    font-size: 11px;

    padding: 15px 5px;
}


/* =========================================================
   TOTAL CARDS (ALIGNED WITH CAMERA & ACTIVITY CARDS)
========================================================= */

.total-cards {

    display: grid;

    grid-template-columns:
        repeat(2, minmax(320px, 1fr));

    gap: 16px;

    width: 100%;

    max-width: 1050px;

    margin: 16px auto 0;
}


.total-card {

    min-height: 65px;

    background: white;

    border: 1px solid #dedede;

    border-radius: 18px;

    padding: 12px 20px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.10);

    min-width: 0;
}


.total-label {

    font-size: 13px;

    line-height: 15px;

    color: #555;

    max-width: 260px;
}


.total-number {

    font-size: 29px;

    font-weight: 500;

    color: #333;

    margin-left: 15px;
}


/* =========================================================
   VISITOR ARRIVAL NOTIFICATION TOAST & DUTY BADGE
========================================================= */

.duty-guards-bar {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 6px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 12px;
    color: #1e3a8a;
    font-weight: 600;
}

.duty-guards-bar a {
    color: #2563eb;
    text-decoration: none;
    font-weight: 700;
    margin-left: 4px;
    font-size: 11px;
}

.duty-guards-bar a:hover {
    text-decoration: underline;
}

.visitor-toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 11000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 370px;
    width: calc(100% - 40px);
    pointer-events: none;
}

.visitor-toast {
    pointer-events: auto;
    background: #ffffff;
    border-left: 5px solid #2563eb;
    border-radius: 14px;
    padding: 14px 16px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.22);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    animation: toastSlideIn 0.28s ease-out;
}

@keyframes toastSlideIn {
    from {
        opacity: 0;
        transform: translateX(40px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.visitor-toast-title {
    font-size: 13px;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 3px;
}

.visitor-toast-body {
    font-size: 12px;
    color: #334155;
    line-height: 1.4;
}

.visitor-toast-close {
    background: none;
    border: none;
    color: #64748b;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    padding: 0 2px;
}

/* =========================================================
   VISITOR INFORMATION INPUT MODAL
========================================================= */

.visitor-modal-overlay {

    display: none;

    position: fixed;

    top: 0;

    left: 0;

    right: 0;

    bottom: 0;

    background: rgba(15, 23, 42, 0.58);

    backdrop-filter: blur(3px);

    z-index: 10000;

    align-items: center;

    justify-content: center;

    padding: 16px;
}

.visitor-modal-overlay.active {

    display: flex;
}

.visitor-modal-box {

    background: #ffffff;

    border-radius: 18px;

    max-width: 440px;

    width: 100%;

    box-shadow: 0 14px 34px rgba(0, 0, 0, 0.26);

    overflow: hidden;

    animation: tapoModalIn 0.2s ease-out;
}

.visitor-modal-header {

    padding: 15px 20px;

    background: #eff6ff;

    border-bottom: 1px solid #dbeafe;

    display: flex;

    align-items: center;

    justify-content: space-between;
}

.visitor-modal-title {

    font-size: 15px;

    font-weight: 700;

    color: #1e3a8a;

    display: flex;

    align-items: center;

    gap: 8px;
}

.visitor-modal-badge {

    font-size: 10px;

    font-weight: 600;

    background: #dbeafe;

    color: #1d4ed8;

    padding: 2px 8px;

    border-radius: 10px;
}

.visitor-modal-body {

    padding: 18px 20px;
}

.visitor-form-group {

    margin-bottom: 14px;
}

.visitor-form-group label {

    display: block;

    font-size: 12px;

    font-weight: 600;

    color: #1f2937;

    margin-bottom: 5px;
}

.visitor-form-group input,
.visitor-form-group select {

    width: 100%;

    padding: 9px 12px;

    border: 1px solid #cbd5e1;

    border-radius: 9px;

    font-size: 13px;

    font-family: inherit;

    color: #111827;

    background: #fff;

    box-sizing: border-box;
}

.visitor-form-group input:focus,
.visitor-form-group select:focus {

    outline: none;

    border-color: #2563eb;

    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

.visitor-purpose-chips {

    display: flex;

    flex-wrap: wrap;

    gap: 6px;

    margin-top: 6px;
}

.visitor-purpose-chip {

    background: #f1f5f9;

    border: 1px solid #cbd5e1;

    border-radius: 12px;

    padding: 3px 9px;

    font-size: 11px;

    color: #334155;

    cursor: pointer;

    transition: all 0.12s ease;
}

.visitor-purpose-chip:hover {

    background: #dbeafe;

    border-color: #93c5fd;

    color: #1e40af;
}

.visitor-form-error {

    display: none;

    background: #fef2f2;

    border: 1px solid #fecaca;

    color: #b91c1c;

    font-size: 12px;

    padding: 8px 10px;

    border-radius: 8px;

    margin-bottom: 12px;
}

.visitor-modal-footer {

    padding: 12px 20px;

    background: #f8fafc;

    border-top: 1px solid #e5e7eb;

    display: flex;

    justify-content: flex-end;

    gap: 8px;
}


/* =========================================================
   CHART
========================================================= */

.chart-container {

    width: 100%;

    max-width: 1050px;

    background: white;

    border: 1px solid #dedede;

    border-radius: 21px;

    margin: 18px auto 0;

    padding: 20px;

    box-shadow:
        0 1px 4px rgba(0, 0, 0, 0.11);
}


.chart-title {

    font-size: 21px;

    font-weight: 500;
}


.chart-subtitle {

    font-size: 12px;

    color: #777;

    margin-top: 5px;

    margin-bottom: 12px;
}


.chart-wrapper {

    position: relative;

    width: 100%;

    height: 300px;
}


/* =========================================================
   PRINT BUTTON
========================================================= */

.print-button {

    display: block;

    width: 175px;

    height: 38px;

    margin: 20px auto 0;

    border: none;

    border-radius: 22px;

    background: #2f80ed;

    color: white;

    font-size: 14px;

    cursor: pointer;

    transition:
        background 0.2s ease,
        transform 0.15s ease;
}


.print-button:hover {

    background: #176bd1;

    transform: translateY(-1px);
}


.print-button:active {

    transform: translateY(0);
}


/* =========================================================
   LARGE SCREEN
========================================================= */

@media (min-width: 1400px) {

    .main {

        padding-left: 45px;

        padding-right: 45px;
    }

    .header {

        margin-left: -45px;

        margin-right: -45px;

        padding-left: 45px;

        padding-right: 45px;
    }

    .camera-grid,
    .activity-grid,
    .total-cards,
    .chart-container {

        max-width: 1100px;
    }

    .camera-screen {

        height: 250px;
    }

    .chart-wrapper {

        height: 330px;
    }
}


/* =========================================================
   MEDIUM SCREEN
========================================================= */

@media (max-width: 1100px) {

    .main {

        padding-left: 25px;

        padding-right: 25px;
    }

    .header {

        margin-left: -25px;

        margin-right: -25px;

        padding-left: 25px;

        padding-right: 25px;
    }

    .camera-grid,
    .activity-grid,
    .total-cards,
    .chart-container {

        max-width: 850px;
    }

    .camera-screen {

        height: 210px;
    }

    .chart-wrapper {

        height: 270px;
    }
}


/* =========================================================
   TABLET
========================================================= */

@media (max-width: 800px) {

    .sidebar {

        width: 76px;

        padding: 24px 8px;
    }


    .logo {

        justify-content: center;

        padding: 0;
    }


    .logo-shield img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.logo-text {

        display: none;
    }


    .nav a {

        justify-content: center;

        padding: 0;
    }


    .nav a span:not(.nav-icon) {

        display: none;
    }


    .logout {

        font-size: 0;
    }


    .logout::before {

        content: "⇥";

        font-size: 20px;
    }


    .main {

        margin-left: 76px;

        width: calc(100% - 76px);

        padding: 20px;
    }


    .header {

        margin-left: -20px;

        margin-right: -20px;

        padding-left: 20px;

        padding-right: 20px;
    }


    .clock {

        font-size: 25px;
    }


    .camera-grid {

        grid-template-columns: 1fr 1fr;
    }


    .activity-grid {

        grid-template-columns: 1fr 1fr;
    }


    .total-cards {

        grid-template-columns: 1fr 1fr;
    }
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 600px) {

    .main {

        padding: 15px 14px 30px;
    }


    .header {

        margin-left: -14px;

        margin-right: -14px;

        padding-left: 14px;

        padding-right: 14px;

        min-height: 75px;
    }


    .header h1 {

        font-size: 21px;
    }


    .header p {

        font-size: 11px;
    }


    .clock {

        font-size: 19px;
    }


    .camera-grid {

        grid-template-columns: 1fr;
    }


    .activity-grid {

        grid-template-columns: 1fr;
    }


    .total-cards {

        grid-template-columns: 1fr;
    }


    .camera-screen {

        height: 180px;
    }


    .chart-container {

        padding: 15px;
    }


    .chart-title {

        font-size: 18px;
    }


    .chart-wrapper {

        height: 240px;
    }


    .print-button {

        width: 100%;

        margin-top: 16px;
    }
}


/* =========================================================
   PRINT
========================================================= */

@media print {

    body {

        background: white;
    }


    .sidebar {

        display: none;
    }


    .main {

        margin-left: 0;

        width: 100%;

        padding: 20px;
    }


    .header {

        margin: 0 0 20px;

        padding: 0;

        border-bottom: 1px solid #ddd;
    }


    .print-button {

        display: none;
    }


    .camera-card,
    .activity-card,
    .total-card,
    .chart-container {

        box-shadow: none;
    }
}

</style>

</head>


<body>


<!-- =====================================================
     SIDEBAR
====================================================== -->

<aside class="sidebar">


    <!-- LOGO -->

    <div class="logo">

        <div class="logo-shield">
            <?php if (!empty($systemIcon)): ?>
                <img src="<?php echo htmlspecialchars($systemIcon); ?>" alt="Icon">
            <?php else: ?>
                ✓
            <?php endif; ?>
        </div>

        <div class="logo-text">

            <div class="logo-title">
                <?php echo htmlspecialchars($systemName); ?>
            </div>

            <div class="logo-subtitle">
                Security Guard
            </div>

        </div>

    </div>


    <!-- NAVIGATION -->

    <nav class="nav">


        <!-- DASHBOARD -->

        <a href="guard_dashboard.php"
           class="active">

            <span class="nav-icon">
                🖥️
            </span>

            <span>
                Dashboard
            </span>

        </a>


        <!-- REGISTERED -->

        <a href="registered_logs.php">

            <span class="nav-icon">
                🚗
            </span>

            <span>
                Registered
            </span>

        </a>


        <!-- VISITOR -->

        <a href="visitor_logs.php">

            <span class="nav-icon">
                👥
            </span>

            <span>
                Visitor
            </span>

        </a>


    </nav>


    <!-- LOGOUT -->

    <a href="logout.php?role=Security%20Guard"
       class="logout">

        🚪 Logout

    </a>


</aside>



<!-- =====================================================
     MAIN CONTENT
====================================================== -->

<main class="main">


    <!-- =================================================
         HEADER
    ================================================== -->

    <div class="header">


        <div class="header-left">

            <h1>
                Dashboard
            </h1>

            <p>
                <?php echo htmlspecialchars($displayDate); ?>
            </p>

            <div class="duty-guards-bar">
                <span>🛡️ On Duty: <?php
                    echo htmlspecialchars($primaryGuardName !== '' ? $primaryGuardName : $guardID);
                    if ($partnerGuardID !== '') {
                        echo ' &amp; ' . htmlspecialchars($partnerGuardName !== '' ? $partnerGuardName : $partnerGuardID);
                    }
                ?></span>
                <a href="guard_select_partner.php" title="Select or switch co-duty guard">[Change Partner]</a>
            </div>

        </div>


        <div class="header-right">

            <button
                type="button"
                class="btn-notif-bell"
                id="btnNotifBell"
                onclick="toggleNotificationDrawer()"
                title="View Notifications & Overstay Reminders"
            >
                <span class="bell-icon">🔔</span>
                <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
            </button>

            <div class="clock" id="liveClock">
                12:30 AM
            </div>

        </div>

    </div>

    <!-- OVERSTAY / DUE TIME REMINDER ALERT BANNER -->
    <div class="overstay-alert-banner" id="overstayAlertBanner" style="display:none;">
        <div class="overstay-alert-content">
            <span class="overstay-alert-icon">⚠️</span>
            <div class="overstay-alert-text" id="overstayAlertText">
                <strong>Visitor Time Limit Notice:</strong> 1 visitor vehicle has exceeded their allowed time!
            </div>
        </div>
        <div class="overstay-alert-actions">
            <button type="button" class="btn-banner-mute" id="bannerMuteBtn" onclick="toggleOverstayAlarmAudio()">🔊 Mute Alarm</button>
            <button type="button" class="btn-view-overstay" onclick="openNotificationDrawerWithTab('ALERT')">View Details</button>
            <button type="button" class="btn-dismiss-alert" onclick="dismissOverstayBanner()" aria-label="Dismiss">&times;</button>
        </div>
    </div>



    <!-- =================================================
         CAMERA SECTION (TAPO C500 - 2 CAMERAS)
    ================================================== -->

    <div class="camera-grid">


        <!-- =============================================
             ENTRANCE CAMERA (TAPO C500)
        ============================================== -->

        <div class="camera-card" id="cardEntranceCam">

            <div class="camera-card-header">

                <div class="camera-title">
                    <span>Entrance Cam</span>
                    <span class="camera-tag dir-badge-in" style="font-size:11px; padding:3px 10px; font-weight:800;">🟢 IN (Entrance)</span>
                </div>

                <button
                    type="button"
                    class="btn-cam-setup"
                    onclick="openTapoModal('entrance')"
                    title="Configure Tapo C500 stream"
                >
                    ⚙️ Setup
                </button>

            </div>

            <div class="camera-screen" id="entranceScreen">

                <img
                    id="entranceCamFeed"
                    src="http://127.0.0.1:5000/video/entrance"
                    alt="Tapo C500 Entrance Stream"
                    onload="onCamStreamLoad('entrance')"
                    onerror="onCamStreamError('entrance')"
                >

                <div
                    class="camera-offline"
                    id="entranceCamOffline"
                    style="display:none;"
                >

                    <div class="camera-offline-icon">📹</div>

                    <div class="camera-offline-title">
                        Entrance Cam Offline
                    </div>

                    <div class="camera-offline-sub">
                        Tapo C500 stream or AI detection server is not connected on this address.
                    </div>

                    <div class="camera-offline-actions">

                        <button
                            type="button"
                            class="btn-offline-retry"
                            onclick="reloadCamStream('entrance')"
                        >
                            🔄 Reconnect
                        </button>

                        <button
                            type="button"
                            class="btn-offline-setup"
                            onclick="openTapoModal('entrance')"
                        >
                            ⚙️ Setup Tapo C500
                        </button>

                    </div>

                </div>

                <div class="camera-status" id="entranceCamStatus">

                    <span class="status-dot offline" id="entranceDot"></span>

                    <span id="entranceStatusText">Connecting...</span>

                </div>

            </div>

        </div>



        <!-- =============================================
             EXIT CAMERA (TAPO C500)
        ============================================== -->

        <div class="camera-card" id="cardExitCam">

            <div class="camera-card-header">

                <div class="camera-title">
                    <span>Exit Cam</span>
                    <span class="camera-tag dir-badge-out" style="font-size:11px; padding:3px 10px; font-weight:800;">🔴 OUT (Exit Only)</span>
                </div>

                <button
                    type="button"
                    class="btn-cam-setup"
                    onclick="openTapoModal('exit')"
                    title="Configure Tapo C500 stream"
                >
                    ⚙️ Setup
                </button>

            </div>

            <div class="camera-screen" id="exitScreen">

                <img
                    id="exitCamFeed"
                    src="http://127.0.0.1:5000/video/exit"
                    alt="Tapo C500 Exit Stream"
                    onload="onCamStreamLoad('exit')"
                    onerror="onCamStreamError('exit')"
                >

                <div
                    class="camera-offline"
                    id="exitCamOffline"
                    style="display:none;"
                >

                    <div class="camera-offline-icon">📹</div>

                    <div class="camera-offline-title">
                        Exit Cam Offline
                    </div>

                    <div class="camera-offline-sub">
                        Tapo C500 stream or AI detection server is not connected on this address.
                    </div>

                    <div class="camera-offline-actions">

                        <button
                            type="button"
                            class="btn-offline-retry"
                            onclick="reloadCamStream('exit')"
                        >
                            🔄 Reconnect
                        </button>

                        <button
                            type="button"
                            class="btn-offline-setup"
                            onclick="openTapoModal('exit')"
                        >
                            ⚙️ Setup Tapo C500
                        </button>

                    </div>

                </div>

                <div class="camera-status" id="exitCamStatus">

                    <span class="status-dot offline" id="exitDot"></span>

                    <span id="exitStatusText">Connecting...</span>

                </div>

            </div>

        </div>


    </div>



    <!-- =================================================
         RECENT VEHICLE ACTIVITY (STACKED, MAX 5, NO REFRESH)
    ================================================== -->

    <div class="activity-grid" id="liveActivity" data-live>


        <!-- =============================================
             VISITOR (LEFT COLUMN - UNDER ENTRANCE CAM)
        ============================================== -->

        <div class="activity-cards">

            <div class="activity-header">

                <div class="activity-title">
                    Visitor
                </div>

            </div>

            <div class="activity-stack" id="visitorActivityStack">

                <?php if (!empty($recentVisitorVehicles)): ?>

                    <?php foreach ($recentVisitorVehicles as $vehicle):
                        $vLogId   = (int)($vehicle['id'] ?? 0);
                        $vPlate   = trim((string)($vehicle['plate_number'] ?? ''));
                        $vType    = trim((string)($vehicle['vehicle_type'] ?? 'Car'));
                        if (!in_array($vType, ['Car', 'Motorcycle', 'Truck'], true)) {
                            $vType = 'Car';
                        }
                        $vPurpose = trim((string)($vehicle['purpose'] ?? ''));
                        $vDir     = trim((string)($vehicle['direction'] ?? 'IN'));
                    ?>

                        <?php
                        $isOutVis = (strtoupper($vDir) === 'OUT');
                        ?>
                        <div
                            class="vehicle-item <?php echo $isOutVis ? 'row-out visitor-item-out' : 'row-in visitor-item-row'; ?>"
                            data-log-id="<?php echo $vLogId; ?>"
                            data-direction="<?php echo $vDir; ?>"
                            data-plate="<?php echo htmlspecialchars($vPlate, ENT_QUOTES); ?>"
                            data-vehicle-type="<?php echo htmlspecialchars($vType, ENT_QUOTES); ?>"
                            data-purpose="<?php echo htmlspecialchars($vPurpose, ENT_QUOTES); ?>"
                            <?php if ($isOutVis): ?>
                                onclick="openVisitorExitModalFromRow(this)"
                                title="Visitor vehicle exited premises (Click to view exit details)"
                            <?php else: ?>
                                onclick="openVisitorModalFromRow(this)"
                                title="Click to enter or update visitor information"
                            <?php endif; ?>
                        >

                            <div class="vehicle-info">

                                <div class="vehicle-plate">
                                    <span class="plate-text"><?php echo htmlspecialchars($vPlate); ?></span>
                                </div>

                                <div class="vehicle-direction">
                                    <span class="type-text"><?php echo htmlspecialchars($vType); ?></span>
                                    <span class="dir-badge <?php echo $isOutVis ? 'dir-badge-out' : 'dir-badge-in'; ?>">
                                        <?php echo $isOutVis ? '🔴 OUT' : '🟢 IN'; ?>
                                    </span>
                                </div>

                            </div>

                            <div class="vehicle-badges">

                                <?php if ($isOutVis): ?>
                                    <span class="purpose-pill" style="background:#fee2e2; color:#991b1b; border:1px solid #f87171;" title="<?php echo htmlspecialchars($vPurpose !== '' ? $vPurpose : 'Exited', ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars($vPurpose !== '' ? $vPurpose : 'Exited'); ?>
                                    </span>
                                <?php elseif ($vPurpose !== ''): ?>
                                    <span class="purpose-pill" title="<?php echo htmlspecialchars($vPurpose, ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars($vPurpose); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="purpose-pill needs-info">
                                        Input Info
                                    </span>
                                <?php endif; ?>

                                <div class="captured">
                                    Captured
                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-activity" id="visitorEmptyMsg">
                        No visitor vehicle captured today.
                    </div>

                <?php endif; ?>

            </div>

        </div>



        <!-- =============================================
             REGISTERED (RIGHT COLUMN - UNDER EXIT CAM)
        ============================================== -->

        <div class="activity-card">

            <div class="activity-header" style="justify-content: center;">

                <div class="activity-title">
                    Registered
                </div>

            </div>

            <div class="activity-stack" id="registeredActivityStack">

                <?php if (!empty($recentRegisteredVehicles)): ?>

                    <?php foreach ($recentRegisteredVehicles as $vehicle):
                        $rLogId = (int)($vehicle['id'] ?? 0);
                        $rPlate = trim((string)($vehicle['plate_number'] ?? ''));
                        $rType  = trim((string)($vehicle['vehicle_type'] ?? ''));
                        $rDir   = trim((string)($vehicle['direction'] ?? 'IN'));
                        $rSub   = ($rType !== '' && strtolower($rType) !== 'unknown')
                            ? ($rType . ' • ' . $rDir)
                            : $rDir;
                    ?>

                        <?php
                        $isOutReg = (strtoupper($rDir) === 'OUT');
                        ?>
                        <div
                            class="vehicle-item <?php echo $isOutReg ? 'row-out' : 'row-in'; ?>"
                            data-log-id="<?php echo $rLogId; ?>"
                            data-direction="<?php echo $rDir; ?>"
                            data-plate="<?php echo htmlspecialchars($rPlate, ENT_QUOTES); ?>"
                        >

                            <div class="vehicle-info">

                                <div class="vehicle-plate">
                                    <span class="plate-text"><?php echo htmlspecialchars($rPlate); ?></span>
                                </div>

                                <div class="vehicle-direction">
                                    <?php if ($rType !== '' && strtolower($rType) !== 'unknown'): ?>
                                        <span class="type-text"><?php echo htmlspecialchars($rType); ?></span>
                                    <?php endif; ?>
                                    <span class="dir-badge <?php echo $isOutReg ? 'dir-badge-out' : 'dir-badge-in'; ?>">
                                        <?php echo $isOutReg ? '🔴 OUT' : '🟢 IN'; ?>
                                    </span>
                                </div>

                            </div>

                            <div class="vehicle-badges">

                                <div class="captured">
                                    Captured
                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-activity" id="registeredEmptyMsg">
                        No registered vehicle captured today.
                    </div>

                <?php endif; ?>

            </div>

        </div>


    </div>



    <!-- =================================================
         TOTALS (ALIGNED UNDER VISITOR & REGISTERED CARDS)
    ================================================== -->

    <div class="total-cards" id="liveTotals" data-live>


        <!-- VISITOR (LEFT - ALIGNED WITH VISITOR ACTIVITY CARD ABOVE) -->

        <div class="total-card">

            <div class="total-label">

                Total Visitor Vehicles
                Entered Today

            </div>

            <div class="total-number" id="totalVisitorsCount">

                <?php
                echo $totalVisitorsToday;
                ?>

            </div>

        </div>


        <!-- REGISTERED (RIGHT - ALIGNED WITH REGISTERED ACTIVITY CARD ABOVE) -->

        <div class="total-card">

            <div class="total-label">

                Total Registered Vehicles
                Entered Today

            </div>

            <div class="total-number" id="totalRegisteredCount">

                <?php
                echo $totalRegisteredToday;
                ?>

            </div>

        </div>


    </div>



    <!-- =================================================
         VEHICLE CHART
    ================================================== -->

    <section class="chart-container">


        <div class="chart-title">

            Data of Vehicles Enter and Exit

        </div>


        <div class="chart-subtitle">

            Hourly breakdown of registered and visitor vehicles

        </div>


        <div class="chart-wrapper">

            <canvas id="vehicleChart"></canvas>

        </div>


        <!-- Live chart data (read by auto_refresh.js) -->
        <script type="application/json" id="liveChartData" data-live><?php
            echo json_encode([
                'labels' => $hours,
                'data'   => [
                    $registeredHourly,
                    $visitorHourly,
                    $motorcycleHourly,
                    $carHourly
                ]
            ]);
        ?></script>


    </section>



    <!-- =================================================
         PRINT
    ================================================== -->

    <button
        class="print-button"
        onclick="printDashboard()">

        🖨️ Print Report

    </button>


</main>


<!-- =========================================================
     TAPO C500 CONFIGURATION MODAL
========================================================= -->
<!-- =========================================================
     DUAL CAMERA CONFIGURATION MODAL (ENTRANCE & EXIT)
========================================================= -->
<div class="tapo-modal-overlay" id="tapoSetupModal">

    <div class="tapo-modal-box" style="max-width: 650px;">

        <div class="tapo-modal-header">

            <div class="tapo-modal-title">
                📹 Dual Camera System Setup (Entrance & Exit)
            </div>

            <button
                type="button"
                class="tapo-modal-close"
                onclick="closeTapoModal()"
                aria-label="Close"
            >
                &times;
            </button>

        </div>

        <div class="tapo-modal-body">

            <!-- Quick Presets -->
            <div style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 12px; color: #1e293b; display: block; margin-bottom: 6px;">
                    ⚡ Quick Presets (1-Click Setup):
                </label>
                <div class="cam-presets-container">
                    <button type="button" class="btn-cam-preset" onclick="applyPresetDualTapo()">
                        📹 2x Tapo C500 (RTSP)
                    </button>
                    <button type="button" class="btn-cam-preset" onclick="applyPresetTapoAndWebcam()">
                        🎥 Tapo Entrance + USB Exit
                    </button>
                    <button type="button" class="btn-cam-preset" onclick="applyPresetDualWebcams()">
                        💻 Dual USB Webcams (0 & 1)
                    </button>
                </div>
            </div>

            <!-- Entrance Camera Card -->
            <div class="cam-section-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <div style="font-weight: 700; font-size: 13px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span>🟢 Entrance Camera (192.168.1.18)</span>
                        <span style="background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700;">Direction: IN</span>
                    </div>
                    <span id="modalEntranceStatus" style="font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: #e2e8f0; color: #475569;">
                        Checking...
                    </span>
                </div>

                <div class="tapo-form-group">
                    <label for="cfgEntranceSource">Hardware Source (Tapo RTSP URL or USB Cam Index):</label>
                    <input
                        type="text"
                        id="cfgEntranceSource"
                        placeholder="rtsp://Jhetlogs:123456789ten@192.168.1.18:554/stream2"
                    >
                    <div class="tapo-help-text">
                        For Tapo C500: <code>rtsp://username:password@IP:554/stream2</code> | For USB webcam: <code>0</code>
                    </div>
                </div>

                <div class="tapo-form-group">
                    <label for="cfgEntranceZoom">Camera View / Lane Focus (Narrow Mode):</label>
                    <select id="cfgEntranceZoom" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff;">
                        <option value="1.35" selected>🔍 Narrow (Lane Focus 1.35x - Recommended for Plates)</option>
                        <option value="1.5">🔬 Extra Narrow (Tight Lane Focus 1.5x)</option>
                        <option value="1.2">🔎 Moderate Narrow (1.2x)</option>
                        <option value="1.0">🌐 Wide (Full Original Wide-Angle 1.0x)</option>
                    </select>
                </div>

                <div class="tapo-form-group" style="margin-bottom: 0;">
                    <label for="cfgEntranceUrl">Dashboard Stream URL:</label>
                    <input
                        type="text"
                        id="cfgEntranceUrl"
                        placeholder="http://127.0.0.1:5000/video/entrance"
                    >
                </div>
            </div>

            <!-- Exit Camera Card -->
            <div class="cam-section-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <div style="font-weight: 700; font-size: 13px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span>🔴 Exit Camera (192.168.1.19 - EXIT ONLY)</span>
                        <span style="background: #ffedd5; color: #c2410c; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700;">Direction: OUT</span>
                    </div>
                    <span id="modalExitStatus" style="font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: #e2e8f0; color: #475569;">
                        Checking...
                    </span>
                </div>

                <div class="tapo-form-group">
                    <label for="cfgExitSource">Hardware Source (Tapo RTSP URL or USB Cam Index):</label>
                    <input
                        type="text"
                        id="cfgExitSource"
                        placeholder="rtsp://Jhetlogs:123456789ten@192.168.1.19:554/stream2"
                    >
                    <div class="tapo-help-text">
                        For 2nd Tapo C500: <code>rtsp://...IP:554/stream2</code> | For USB webcam: <code>0</code> or <code>1</code>
                    </div>
                </div>

                <div class="tapo-form-group">
                    <label for="cfgExitZoom">Camera View / Lane Focus (Narrow Mode):</label>
                    <select id="cfgExitZoom" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff;">
                        <option value="1.35" selected>🔍 Narrow (Lane Focus 1.35x - Recommended for Plates)</option>
                        <option value="1.5">🔬 Extra Narrow (Tight Lane Focus 1.5x)</option>
                        <option value="1.2">🔎 Moderate Narrow (1.2x)</option>
                        <option value="1.0">🌐 Wide (Full Original Wide-Angle 1.0x)</option>
                    </select>
                </div>

                <div class="tapo-form-group" style="margin-bottom: 0;">
                    <label for="cfgExitUrl">Dashboard Stream URL:</label>
                    <input
                        type="text"
                        id="cfgExitUrl"
                        placeholder="http://127.0.0.1:5000/video/exit"
                    >
                </div>
            </div>

            <div class="tapo-guide-box" style="margin-bottom: 0;">
                <strong>📋 Real-Time Switching:</strong>
                <span style="display:block; margin-top:4px;">
                    Saving will immediately switch the hardware cameras on the detection server (<code>detect_plate_server.py</code>) and reload both feeds live on this dashboard.
                </span>
            </div>

        </div>

        <div class="tapo-modal-footer">

            <button
                type="button"
                class="btn-tapo-cancel"
                onclick="closeTapoModal()"
            >
                Cancel
            </button>

            <button
                type="button"
                class="btn-tapo-save"
                onclick="saveTapoSettings()"
            >
                💾 Save & Apply Cameras
            </button>

        </div>

    </div>

</div>


<!-- =========================================================
     VISITOR INFORMATION INPUT MODAL
========================================================= -->
<div class="visitor-toast-container" id="visitorToastContainer"></div>

<div class="visitor-modal-overlay" id="visitorInfoModal">

    <div class="visitor-modal-box">

        <div class="visitor-modal-header">

            <div class="visitor-modal-title">
                <span>🚘 Visitor Information Input</span>
                <span class="visitor-modal-badge" id="visitorModalBadge">Visitor</span>
            </div>

            <button
                type="button"
                class="tapo-modal-close"
                onclick="closeVisitorModal()"
                aria-label="Close"
            >
                &times;
            </button>

        </div>

        <form id="visitorInfoForm" onsubmit="saveVisitorInfo(event)">

            <div class="visitor-modal-body">

                <div class="visitor-form-error" id="visitorFormError"></div>

                <input type="hidden" id="visitorLogId" value="0">

                <div class="visitor-form-group">

                    <label for="visitorPlateInput">Plate Number</label>

                    <input
                        type="text"
                        id="visitorPlateInput"
                        name="plate_number"
                        placeholder="e.g. NBC 1234"
                        style="text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;"
                        required
                    >

                </div>

                <div class="visitor-form-group">

                    <label for="visitorVehicleTypeInput">Vehicle Type</label>

                    <select id="visitorVehicleTypeInput" name="vehicle_type" required>
                        <option value="Car">Car</option>
                        <option value="Motorcycle">Motorcycle</option>
                        <option value="Truck">Truck</option>
                    </select>

                </div>

                <div class="visitor-form-group" style="margin-bottom: 4px;">

                    <label for="visitorPurposeInput">Purpose &amp; Allowed Time</label>

                    <select id="visitorPurposeInput" name="purpose" required>
                        <option value="" disabled selected>-- Select Purpose &amp; Time Limit --</option>
                        <option value="Birthday - 12 Hours">Birthday - 12 Hours</option>
                        <option value="Visiting Resident - 12 Hours">Visiting Resident - 12 Hours</option>
                        <option value="Delivery - 20 Mins">Delivery - 20 Mins</option>
                        <option value="Drop - off/on - 15 Mins">Drop - off/on - 15 Mins</option>
                        <option value="Service Maintenance - 8 Hours">Service Maintenance - 8 Hours</option>
                    </select>

                    <div class="visitor-purpose-chips">
                        <span class="visitor-purpose-chip" onclick="setQuickPurpose('Birthday - 12 Hours')">Birthday - 12 Hours</span>
                        <span class="visitor-purpose-chip" onclick="setQuickPurpose('Visiting Resident - 12 Hours')">Visiting Resident - 12 Hours</span>
                        <span class="visitor-purpose-chip" onclick="setQuickPurpose('Delivery - 20 Mins')">Delivery - 20 Mins</span>
                        <span class="visitor-purpose-chip" onclick="setQuickPurpose('Drop - off/on - 15 Mins')">Drop - off/on - 15 Mins</span>
                        <span class="visitor-purpose-chip" onclick="setQuickPurpose('Service Maintenance - 8 Hours')">Service Maintenance - 8 Hours</span>
                    </div>

                </div>

            </div>

            <div class="visitor-modal-footer">

                <button
                    type="button"
                    class="btn-tapo-cancel"
                    onclick="closeVisitorModal()"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn-tapo-save"
                    id="btnSaveVisitorInfo"
                >
                    Save Visitor Info
                </button>

            </div>

        </form>

    </div>

</div>

<!-- =========================================================
     VISITOR EXIT / DEPARTURE DETAILS MODAL (EXIT ONLY)
========================================================= -->
<div class="visitor-modal-overlay" id="visitorExitModal">

    <div class="visitor-modal-box" style="max-width: 480px;">

        <div class="visitor-modal-header" style="background: #fef2f2; border-bottom: 1px solid #fecaca;">

            <div class="visitor-modal-title" style="color: #991b1b;">
                <span>🚗 Visitor Departure (Exit Cam)</span>
                <span class="dir-badge dir-badge-out" style="font-size:11px; padding:3px 10px;">🔴 OUT</span>
            </div>

            <button
                type="button"
                class="tapo-modal-close"
                onclick="closeVisitorExitModal()"
                aria-label="Close"
            >
                &times;
            </button>

        </div>

        <div class="visitor-modal-body">

            <div style="background:#fef2f2; border-left:4px solid #dc2626; padding:12px; border-radius:6px; margin-bottom:14px;">
                <div style="font-weight:700; color:#991b1b; font-size:14px; margin-bottom:4px;">Vehicle Has Exited Premises</div>
                <div style="font-size:12px; color:#7f1d1d;">This visitor vehicle was recorded at Exit Camera (192.168.1.19). It has completed its visit and departed.</div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:14px;">
                <div class="cam-section-card" style="padding:10px; margin-bottom:0;">
                    <div style="font-size:11px; color:#6b7280; font-weight:600;">Plate Number</div>
                    <div id="exitModalPlate" style="font-size:16px; font-weight:800; color:#111827; letter-spacing:0.5px;">-</div>
                </div>
                <div class="cam-section-card" style="padding:10px; margin-bottom:0;">
                    <div style="font-size:11px; color:#6b7280; font-weight:600;">Vehicle Type</div>
                    <div id="exitModalType" style="font-size:16px; font-weight:700; color:#111827;">-</div>
                </div>
            </div>

            <div class="cam-section-card" style="padding:10px; margin-bottom:14px;">
                <div style="font-size:11px; color:#6b7280; font-weight:600;">Recorded Visit Purpose</div>
                <div id="exitModalPurpose" style="font-size:13px; font-weight:600; color:#374151; margin-top:3px;">None</div>
            </div>

            <div class="cam-section-card" style="padding:10px; margin-bottom:0; background:#f0fdf4; border-color:#bbf7d0;">
                <div style="font-size:11px; color:#15803d; font-weight:600;">Session Status</div>
                <div style="font-size:13px; font-weight:700; color:#15803d; margin-top:2px;">Closed / Completed in Database</div>
            </div>

        </div>

        <div class="visitor-modal-footer">

            <button
                type="button"
                class="btn-tapo-cancel"
                onclick="closeVisitorExitModal()"
                style="width: 100%; text-align: center; padding: 10px;"
            >
                Close Departure Details
            </button>

        </div>

    </div>

</div>




<script>

/* =========================================================
   LIVE CLOCK
========================================================= */

function updateClock() {

    const now = new Date();

    let hours = now.getHours();

    let minutes = now.getMinutes();

    let seconds = now.getSeconds();

    const period = hours >= 12 ? "PM" : "AM";

    hours = hours % 12;

    hours = hours ? hours : 12;

    minutes =
        minutes < 10
            ? "0" + minutes
            : minutes;

    seconds =
        seconds < 10
            ? "0" + seconds
            : seconds;


    document.getElementById("liveClock").textContent =
        hours + ":" +
        minutes + ":" +
        seconds + " " +
        period;
}


updateClock();

setInterval(updateClock, 1000);

/* =========================================================
   GUARD HEARTBEAT
   Periodically refresh login_status and last_seen so the
   security information page always sees this guard as Active
   while the browser tab is open.
========================================================= */

function sendHeartbeat() {

    const formData = new FormData();

    formData.append("action", "heartbeat");

    fetch(window.location.pathname, {

        method: "POST",

        body: formData

    })
    .then(function (response) {
        return response.json();
    })
    .then(function (data) {
        /* No action needed; only used to keep the
           guard marked as Active/last_seen current. */
    })
    .catch(function () {
        /* Silently ignore transient network errors. */
    });

}

/* Send on load, then every 25 seconds. */
sendHeartbeat();

setInterval(sendHeartbeat, 25000);






/* =========================================================
   DASHBOARD CHART
========================================================= */

const chartCanvas =
    document.getElementById("vehicleChart");


const vehicleChart =
    new Chart(chartCanvas, {

        type: "line",


        data: {

            labels:

                <?php
                echo json_encode($hours);
                ?>,


            datasets: [

                /* =========================================
                   REGISTERED
                ========================================== */

                {

                    label: "Registered",

                    data:

                        <?php
                        echo json_encode(
                            $registeredHourly
                        );
                        ?>,

                    borderWidth: 2,

                    pointRadius: 0,

                    tension: 0,

                    fill: false

                },


                /* =========================================
                   VISITOR
                ========================================== */

                {

                    label: "Visitor",

                    data:

                        <?php
                        echo json_encode(
                            $visitorHourly
                        );
                        ?>,

                    borderWidth: 2,

                    pointRadius: 0,

                    tension: 0,

                    fill: false

                },


                /* =========================================
                   MOTORCYCLE
                ========================================== */

                {

                    label: "Motor",

                    data:

                        <?php
                        echo json_encode(
                            $motorcycleHourly
                        );
                        ?>,

                    borderWidth: 2,

                    pointRadius: 0,

                    tension: 0,

                    fill: false

                },


                /* =========================================
                   CAR
                ========================================== */

                {

                    label: "Car",

                    data:

                        <?php
                        echo json_encode(
                            $carHourly
                        );
                        ?>,

                    borderWidth: 2,

                    pointRadius: 0,

                    tension: 0,

                    fill: false

                }

            ]

        },


        options: {

            responsive: true,

            maintainAspectRatio: false,


            interaction: {

                mode: "index",

                intersect: false

            },


            plugins: {

                legend: {

                    position: "bottom",

                    labels: {

                        boxWidth: 20,

                        boxHeight: 2,

                        padding: 12,

                        font: {

                            size: 10

                        }

                    }

                },


                tooltip: {

                    enabled: true

                }

            },


            scales: {

                x: {

                    grid: {

                        color: "#dddddd"

                    },

                    ticks: {

                        maxTicksLimit: 8,

                        font: {

                            size: 9

                        }

                    }

                },


                y: {

                    beginAtZero: true,

                    ticks: {

                        precision: 0,

                        font: {

                            size: 9

                        }

                    },

                    grid: {

                        color: "#dddddd"

                    }

                }

            }

        }

    });



/* =========================================================
   PRINT DASHBOARD
========================================================= */

function printDashboard() {

    window.print();

}


/* =========================================================
   STACKED ACTIVITY CARDS (MAX 5) & VISITOR INPUT FORM
========================================================= */

const MAX_STACK_ITEMS = 5;
let lastSeenLogId = <?php echo (int)$latestLogIdToday; ?>;
const INITIAL_TODAY_LOGS = <?php echo json_encode($initialLogsToday ?? []); ?>;
const promptedVisitorLogIds = new Set();

function escapeHtmlText(str) {
    const div = document.createElement("div");
    div.textContent = str == null ? "" : String(str);
    return div.innerHTML;
}

function playVisitorNotificationSound() {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = "sine";
        osc.frequency.setValueAtTime(880, ctx.currentTime);
        osc.frequency.setValueAtTime(1174.66, ctx.currentTime + 0.12);
        gain.gain.setValueAtTime(0.18, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.45);
    } catch (e) {}
}


function showVisitorExitNotification(plateNumber, vehicleType, purpose) {
    playVisitorNotificationSound();
    const container = document.getElementById("visitorToastContainer");
    if (!container) return;

    const toast = document.createElement("div");
    toast.className = "visitor-toast";
    toast.style.borderLeft = "4px solid #ef4444";
    toast.style.background = "#fff5f5";

    toast.innerHTML =
        '<div>' +
            '<div class="visitor-toast-title" style="color:#b91c1c;">🔴 Visitor Vehicle Exited</div>' +
            '<div class="visitor-toast-body">' +
                '<div>Plate: <strong>' + escapeHtmlText(plateNumber) + '</strong> (' + escapeHtmlText(vehicleType || "Car") + ')</div>' +
                '<div style="color:#7f1d1d; font-size:11px; margin-top:2px;">Gate: Exit Camera (192.168.1.19)</div>' +
            '</div>' +
        '</div>' +
        '<button type="button" class="visitor-toast-close" aria-label="Dismiss">&times;</button>';

    const closeBtn = toast.querySelector(".visitor-toast-close");
    if (closeBtn) {
        closeBtn.onclick = function() {
            toast.remove();
        };
    }

    container.appendChild(toast);

    setTimeout(function() {
        if (toast.parentNode) {
            toast.remove();
        }
    }, 6000);
}

function openVisitorExitModalFromRow(rowEl) {
    if (!rowEl) return;
    const plate = rowEl.getAttribute("data-plate") || "";
    const vType = rowEl.getAttribute("data-vehicle-type") || "Car";
    const purpose = rowEl.getAttribute("data-purpose") || "None recorded";

    const modal = document.getElementById("visitorExitModal");
    const pEl = document.getElementById("exitModalPlate");
    const tEl = document.getElementById("exitModalType");
    const prpEl = document.getElementById("exitModalPurpose");

    if (pEl) pEl.innerText = plate;
    if (tEl) tEl.innerText = vType;
    if (prpEl) prpEl.innerText = purpose || "None recorded";

    if (modal) modal.classList.add("active");
}

function closeVisitorExitModal() {
    const modal = document.getElementById("visitorExitModal");
    if (modal) modal.classList.remove("active");
}

function showVisitorArrivalNotification(plateNumber, vehicleType, purpose) {
    playVisitorNotificationSound();
    const container = document.getElementById("visitorToastContainer");
    if (!container) return;

    const toast = document.createElement("div");
    toast.className = "visitor-toast";
    const purposeLine = purpose
        ? "<div>Purpose: <strong>" + escapeHtmlText(purpose) + "</strong></div>"
        : "<div>Please input visitor purpose &amp; allowed time.</div>";

    toast.innerHTML =
        '<div>' +
            '<div class="visitor-toast-title">🔔 Visitor Vehicle Arrived!</div>' +
            '<div class="visitor-toast-body">' +
                '<div>Plate: <strong>' + escapeHtmlText(plateNumber) + '</strong> (' + escapeHtmlText(vehicleType || "Car") + ')</div>' +
                purposeLine +
            '</div>' +
        '</div>' +
        '<button type="button" class="visitor-toast-close" aria-label="Dismiss">&times;</button>';

    const closeBtn = toast.querySelector(".visitor-toast-close");
    if (closeBtn) {
        closeBtn.onclick = function() {
            toast.remove();
        };
    }

    container.appendChild(toast);

    setTimeout(function() {
        if (toast.parentNode) {
            toast.remove();
        }
    }, 8000);
}

function openVisitorModal(plateNumber, vehicleType, purpose, logId, isAutoDetected) {

    const modal = document.getElementById("visitorInfoModal");
    const plateInput = document.getElementById("visitorPlateInput");
    const typeSelect = document.getElementById("visitorVehicleTypeInput");
    const purposeInput = document.getElementById("visitorPurposeInput");
    const logIdInput = document.getElementById("visitorLogId");
    const badge = document.getElementById("visitorModalBadge");
    const errBox = document.getElementById("visitorFormError");

    if (!modal) return;

    if (errBox) {
        errBox.style.display = "none";
        errBox.textContent = "";
    }

    if (logIdInput) {
        logIdInput.value = logId ? String(logId) : "0";
    }
    if (plateInput) {
        plateInput.value = (plateNumber || "").toUpperCase();
    }
    if (typeSelect) {
        const vtLower = (vehicleType || "").toLowerCase();
        const normalizedType =
            vtLower === "motorcycle"
                ? "Motorcycle"
                : (vtLower === "truck" ? "Truck" : "Car");
        typeSelect.value = normalizedType;
    }
    if (purposeInput) {
        if (purpose) {
            let matched = false;
            for (let i = 0; i < purposeInput.options.length; i++) {
                if (purposeInput.options[i].value === purpose) {
                    matched = true;
                    break;
                }
            }
            if (!matched) {
                const opt = document.createElement("option");
                opt.value = purpose;
                opt.textContent = purpose;
                purposeInput.appendChild(opt);
            }
            purposeInput.value = purpose;
        } else {
            purposeInput.selectedIndex = 0;
        }
    }
    if (badge) {
        badge.textContent = isAutoDetected ? "New Visitor Detected" : "Visitor Entry";
    }

    modal.classList.add("active");

    setTimeout(function () {
        if (plateInput && !plateInput.value.trim()) {
            plateInput.focus();
        } else if (purposeInput) {
            purposeInput.focus();
        }
    }, 60);
}

function openVisitorModalFromRow(rowEl) {

    if (!rowEl) return;
    const logId = parseInt(rowEl.getAttribute("data-log-id") || "0", 10);
    const plate = rowEl.getAttribute("data-plate") || "";
    const vType = rowEl.getAttribute("data-vehicle-type") || "Car";
    const purpose = rowEl.getAttribute("data-purpose") || "";

    openVisitorModal(plate, vType, purpose, logId, false);
}

function closeVisitorModal() {

    const modal = document.getElementById("visitorInfoModal");
    if (modal) {
        modal.classList.remove("active");
    }
}

function setQuickPurpose(purposeText) {

    const purposeInput = document.getElementById("visitorPurposeInput");
    if (purposeInput) {
        purposeInput.value = purposeText;
        purposeInput.focus();
    }
}

function stackVehicleItem(logData) {

    if (!logData || !logData.plate_number) return;

    const category = String(logData.vehicle_category || "").toLowerCase();
    const isVisitor = category === "visitor";
    const stackContainer = document.getElementById(
        isVisitor ? "visitorActivityStack" : "registeredActivityStack"
    );

    if (!stackContainer) return;

    // Remove empty placeholder if present
    const emptyMsg = stackContainer.querySelector(".empty-activity");
    if (emptyMsg) {
        emptyMsg.remove();
    }

    const logId = parseInt(logData.id || "0", 10);
    const plate = String(logData.plate_number || "").trim().toUpperCase();
    const rawType = String(logData.vehicle_type || "").trim();
    const rtLower = rawType.toLowerCase();
    const vType = rtLower === "motorcycle" ? "Motorcycle" : (rtLower === "truck" ? "Truck" : "Car");
    const purpose = String(logData.purpose || "").trim();
    const direction = String(logData.direction || "IN").trim().toUpperCase();

    // If category transitioned (e.g. Visitor was registered), purge from opposite stack immediately
    const otherStack = document.getElementById(
        isVisitor ? "registeredActivityStack" : "visitorActivityStack"
    );
    if (otherStack && plate) {
        const itemInOther = otherStack.querySelector('.vehicle-item[data-plate="' + plate + '"]');
        if (itemInOther) {
            itemInOther.remove();
        }
    }

    // Check if an item with this logId or same plate at top already exists to update in-place
    let existingItem = null;
    if (logId > 0) {
        existingItem = stackContainer.querySelector('.vehicle-item[data-log-id="' + logId + '"]');
    }
    if (!existingItem && plate) {
        existingItem = stackContainer.querySelector('.vehicle-item[data-plate="' + plate + '"]');
    }

    const isOut = direction === "OUT";

    if (existingItem) {
        existingItem.setAttribute("data-plate", plate);
        existingItem.setAttribute("data-vehicle-type", vType);
        existingItem.setAttribute("data-purpose", purpose);
        existingItem.setAttribute("data-direction", direction);

        // Update entire row color based on IN vs OUT
        existingItem.classList.remove("row-in", "row-out");
        existingItem.classList.add(isOut ? "row-out" : "row-in");

        const plateSpan = existingItem.querySelector(".plate-text");
        if (plateSpan) plateSpan.textContent = plate;

        const subContainer = existingItem.querySelector(".vehicle-direction");
        if (subContainer) {
            const dirHtml = isOut
                ? '<span class="dir-badge dir-badge-out">🔴 OUT</span>'
                : '<span class="dir-badge dir-badge-in">🟢 IN</span>';
            const typeStr = isVisitor
                ? escapeHtmlText(vType)
                : ((rawType && rawType.toLowerCase() !== "unknown") ? escapeHtmlText(rawType) : "");
            subContainer.innerHTML = (typeStr ? '<span class="type-text">' + typeStr + '</span> ' : '') + dirHtml;
        }

        if (isVisitor) {
            const pill = existingItem.querySelector(".purpose-pill");
            if (isOut) {
                existingItem.classList.remove("visitor-item-row");
                existingItem.classList.add("visitor-item-out");
                existingItem.onclick = function() { openVisitorExitModalFromRow(existingItem); };
                existingItem.setAttribute("title", "Visitor vehicle exited (Click to view exit details)");
                if (pill) {
                    pill.className = "purpose-pill";
                    pill.style.background = "#fee2e2";
                    pill.style.color = "#991b1b";
                    pill.style.border = "1px solid #f87171";
                    pill.textContent = purpose !== "" ? purpose : "Exited";
                }
            } else {
                existingItem.classList.add("visitor-item-row");
                existingItem.classList.remove("visitor-item-out");
                existingItem.onclick = function() { openVisitorModalFromRow(existingItem); };
                existingItem.setAttribute("title", "Click to enter or update visitor information");
                if (pill) {
                    pill.style.background = "";
                    pill.style.color = "";
                    pill.style.border = "";
                    if (purpose !== "") {
                        pill.className = "purpose-pill";
                        pill.textContent = purpose;
                        pill.title = purpose;
                    } else {
                        pill.className = "purpose-pill needs-info";
                        pill.textContent = "Input Info";
                        pill.removeAttribute("title");
                    }
                }
            }
        }
        return;
    }

    // Build new item element and prepend to top of stack
    const itemDiv = document.createElement("div");
    const rowColorClass = isOut ? "row-out" : "row-in";
    itemDiv.className = isVisitor
        ? ("vehicle-item " + rowColorClass + (isOut ? " visitor-item-out" : " visitor-item-row"))
        : ("vehicle-item " + rowColorClass);
    itemDiv.setAttribute("data-direction", direction);
    if (logId > 0) {
        itemDiv.setAttribute("data-log-id", String(logId));
    }
    itemDiv.setAttribute("data-plate", plate);

    if (isVisitor) {
        itemDiv.setAttribute("data-vehicle-type", vType);
        itemDiv.setAttribute("data-purpose", purpose);

        let pillHtml = "";
        if (isOut) {
            itemDiv.setAttribute("title", "Visitor vehicle exited (Click to view exit details)");
            itemDiv.onclick = function () {
                openVisitorExitModalFromRow(itemDiv);
            };
            const label = purpose !== "" ? escapeHtmlText(purpose) : "Exited";
            pillHtml = '<span class="purpose-pill" style="background:#fee2e2; color:#991b1b; border:1px solid #f87171;">' + label + '</span>';
        } else {
            itemDiv.setAttribute("title", "Click to enter or update visitor information");
            itemDiv.onclick = function () {
                openVisitorModalFromRow(itemDiv);
            };
            pillHtml = purpose !== ""
                ? '<span class="purpose-pill" title="' + escapeHtmlText(purpose) + '">' + escapeHtmlText(purpose) + '</span>'
                : '<span class="purpose-pill needs-info">Input Info</span>';
        }

        itemDiv.innerHTML =
            '<div class="vehicle-info">' +
                '<div class="vehicle-plate"><span class="plate-text">' + escapeHtmlText(plate) + '</span></div>' +
                '<div class="vehicle-direction">' +
                    '<span class="type-text">' + escapeHtmlText(vType) + '</span> ' +
                    (isOut ? '<span class="dir-badge dir-badge-out">🔴 OUT</span>' : '<span class="dir-badge dir-badge-in">🟢 IN</span>') +
                '</div>' +
            '</div>' +
            '<div class="vehicle-badges">' +
                pillHtml +
                '<div class="captured">Captured</div>' +
            '</div>';
    } else {
        itemDiv.innerHTML =
            '<div class="vehicle-info">' +
                '<div class="vehicle-plate"><span class="plate-text">' + escapeHtmlText(plate) + '</span></div>' +
                '<div class="vehicle-direction">' +
                    ((rawType && rawType.toLowerCase() !== "unknown") ? '<span class="type-text">' + escapeHtmlText(rawType) + '</span> ' : '') +
                    (isOut ? '<span class="dir-badge dir-badge-out">🔴 OUT</span>' : '<span class="dir-badge dir-badge-in">🟢 IN</span>') +
                '</div>' +
            '</div>' +
            '<div class="vehicle-badges">' +
                '<div class="captured">Captured</div>' +
            '</div>';
    }

    // Prepend at the top of the stack
    stackContainer.insertBefore(itemDiv, stackContainer.firstChild);

    // Strictly cap displayed plate numbers to MAX_STACK_ITEMS (5)
    const items = stackContainer.querySelectorAll(".vehicle-item");
    if (items.length > MAX_STACK_ITEMS) {
        for (let i = MAX_STACK_ITEMS; i < items.length; i++) {
            items[i].remove();
        }
    }
}

async function saveVisitorInfo(e) {

    e.preventDefault();

    const plateInput = document.getElementById("visitorPlateInput");
    const typeSelect = document.getElementById("visitorVehicleTypeInput");
    const purposeInput = document.getElementById("visitorPurposeInput");
    const logIdInput = document.getElementById("visitorLogId");
    const errBox = document.getElementById("visitorFormError");
    const saveBtn = document.getElementById("btnSaveVisitorInfo");

    const plate = (plateInput ? plateInput.value.trim().toUpperCase() : "");
    const vType = (typeSelect ? typeSelect.value : "Car");
    const purpose = (purposeInput ? purposeInput.value.trim() : "");
    const logId = (logIdInput ? parseInt(logIdInput.value || "0", 10) : 0);

    if (!plate || !purpose) {
        if (errBox) {
            errBox.textContent = "Please fill in the Plate Number, Vehicle Type, and Purpose.";
            errBox.style.display = "block";
        }
        return;
    }

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = "Saving...";
    }

    try {
        const formData = new FormData();
        formData.append("action", "save_visitor_info");
        formData.append("plate_number", plate);
        formData.append("vehicle_type", vType);
        formData.append("purpose", purpose);
        formData.append("log_id", String(logId));

        const res = await fetch(window.location.pathname, {
            method: "POST",
            body: formData
        });
        const data = await res.json();

        if (!data.success) {
            if (errBox) {
                errBox.textContent = data.message || "Failed to save visitor info.";
                errBox.style.display = "block";
            }
        } else {
            const savedLog = data.log || {};
            const resolvedId = parseInt(savedLog.id || data.log_id || logId || "0", 10);
            if (resolvedId > lastSeenLogId) {
                lastSeenLogId = resolvedId;
            }
            if (resolvedId > 0) {
                promptedVisitorLogIds.add(resolvedId);
            }

            stackVehicleItem({
                id: resolvedId,
                plate_number: savedLog.plate_number || data.plate_number || plate,
                vehicle_type: savedLog.vehicle_type || data.vehicle_type || vType,
                vehicle_category: "Visitor",
                purpose: savedLog.purpose || data.purpose || purpose,
                direction: savedLog.direction || data.direction || "IN"
            });

            closeVisitorModal();
            pollLiveCaptures();
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = "Network error while saving visitor information.";
            errBox.style.display = "block";
        }
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = "Save Visitor Info";
        }
    }
}

let isPollingLiveCaptures = false;
async function pollLiveCaptures() {
    if (isPollingLiveCaptures) return;
    isPollingLiveCaptures = true;

    try {
        const url = window.location.pathname + "?action=live_feed&since_id=" + encodeURIComponent(lastSeenLogId);
        const res = await fetch(url, { cache: "no-store" });
        const data = await res.json();

        if (!data || !data.success) return;

        // Update visitor stay limit reminders & trigger alarm if any overdue
        handleOverstayReminders(data.overdue_visitors || [], data.due_soon_visitors || []);

        // Update total counts smoothly
        const visTotalEl = document.getElementById("totalVisitorsCount");
        const regTotalEl = document.getElementById("totalRegisteredCount");
        if (visTotalEl && typeof data.total_visitors !== "undefined") {
            visTotalEl.textContent = String(data.total_visitors);
        }
        if (regTotalEl && typeof data.total_registered !== "undefined") {
            regTotalEl.textContent = String(data.total_registered);
        }

        if (Array.isArray(data.new_logs) && data.new_logs.length > 0) {
            for (let i = 0; i < data.new_logs.length; i++) {
                const item = data.new_logs[i];
                const id = parseInt(item.id || "0", 10);
                if (id > lastSeenLogId) {
                    lastSeenLogId = id;
                }

                // Stack plate number onto Visitor or Registered card (max 5)
                stackVehicleItem(item);

                // Add to Notification Center history
                const itemDir = String(item.direction || "IN").toUpperCase();
                const itemCat = String(item.vehicle_category || "Visitor");
                const itemPlate = String(item.plate_number || "Unknown");
                const itemType = String(item.vehicle_type || "Car");
                const itemPurpose = String(item.purpose || "");

                if (itemDir === "OUT") {
                    addNotification(
                        "OUT",
                        "🔴 Vehicle Exited: " + itemPlate,
                        itemCat + " • " + itemType + (itemPurpose ? " • " + itemPurpose : "") + " (Exit Cam)",
                        item
                    );
                } else {
                    addNotification(
                        "IN",
                        "🟢 Vehicle Entered: " + itemPlate,
                        itemCat + " • " + itemType + (itemPurpose ? " • " + itemPurpose : "") + " (Entrance Cam)",
                        item
                    );
                }

                // Show arrival notification & automatically activate Visitor Information Form when a Visitor arrives
                const isVisitor = String(item.vehicle_category || "").toLowerCase() === "visitor";
                const modal = document.getElementById("visitorInfoModal");
                const modalOpen = modal && modal.classList.contains("active");

                const isExiting = String(item.direction || "IN").toUpperCase() === "OUT";
                if (isVisitor && !promptedVisitorLogIds.has(id)) {
                    promptedVisitorLogIds.add(id);

                    if (isExiting) {
                        // Exiting visitor: Show Exit departure toast. DO NOT open arrival entry form!
                        showVisitorExitNotification(
                            item.plate_number || "",
                            item.vehicle_type || "Car",
                            item.purpose || ""
                        );
                    } else {
                        // Arriving visitor: Show Arrival toast & open the entry information form
                        showVisitorArrivalNotification(
                            item.plate_number || "",
                            item.vehicle_type || "Car",
                            item.purpose || ""
                        );
                        if (!modalOpen) {
                            openVisitorModal(
                                item.plate_number || "",
                                item.vehicle_type || "Car",
                                item.purpose || "",
                                id,
                                true
                            );
                        }
                    }
                }
            }
        }
    } catch (e) {
        // Ignore transient network errors
    } finally {
        isPollingLiveCaptures = false;
    }
}

setInterval(pollLiveCaptures, 1500);


/* =========================================================
   DUAL CAMERA (ENTRANCE & EXIT) CONTROLLER
========================================================= */

const DEFAULT_ENTRANCE_URL = "http://127.0.0.1:5000/video/entrance";
const DEFAULT_EXIT_URL = "http://127.0.0.1:5000/video/exit";
const API_BASE_URL = "http://127.0.0.1:5000";


/* =========================================================
   NOTIFICATION CENTER & OVERSTAY REMINDER ALARM CONTROLLER
========================================================= */

const NOTIF_STORAGE_KEY = "guard_dashboard_notifications_history";
let notificationsList = [];
let currentNotifFilter = "all";
let acknowledgedOverdueIds = new Set();
let lastOverstayAlarmSound = 0;

let alarmAudioMuted = localStorage.getItem("guard_alarm_muted") === "true";

function toggleOverstayAlarmAudio() {
    alarmAudioMuted = !alarmAudioMuted;
    localStorage.setItem("guard_alarm_muted", alarmAudioMuted ? "true" : "false");
    updateAlarmSoundButton();
    if (!alarmAudioMuted) {
        // Play a short chirp to confirm unmuted
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            gain.gain.setValueAtTime(0.15, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.15);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.15);
        } catch(e) {}
    }
}

function updateAlarmSoundButton() {
    const btn = document.getElementById("btnToggleAlarmSound");
    if (btn) {
        btn.innerHTML = alarmAudioMuted ? "🔇 Alarm Muted" : "🔊 Sound On";
        btn.style.color = alarmAudioMuted ? "#ef4444" : "#16a34a";
    }
    const bannerMuteBtn = document.getElementById("bannerMuteBtn");
    if (bannerMuteBtn) {
        bannerMuteBtn.innerHTML = alarmAudioMuted ? "🔇 Unmute Alarm" : "🔊 Mute Alarm";
    }
}

function loadStoredNotifications() {
    try {
        const stored = localStorage.getItem(NOTIF_STORAGE_KEY);
        if (stored) {
            notificationsList = JSON.parse(stored);
        }
    } catch (e) {
        notificationsList = [];
    }

    // If storage is empty, seed with today's initial vehicle logs
    if ((!notificationsList || notificationsList.length === 0) && typeof INITIAL_TODAY_LOGS !== "undefined" && Array.isArray(INITIAL_TODAY_LOGS) && INITIAL_TODAY_LOGS.length > 0) {
        INITIAL_TODAY_LOGS.forEach(item => {
            const itemDir = String(item.direction || "IN").toUpperCase();
            const itemCat = String(item.vehicle_category || "Visitor");
            const itemPlate = String(item.plate_number || "Unknown");
            const itemType = String(item.vehicle_type || "Car");
            const itemPurpose = String(item.purpose || "");

            notificationsList.push({
                id: "notif_init_" + item.id,
                type: itemDir === "OUT" ? "OUT" : "IN",
                title: itemDir === "OUT" ? ("🔴 Vehicle Exited: " + itemPlate) : ("🟢 Vehicle Entered: " + itemPlate),
                sub: itemCat + " • " + itemType + (itemPurpose ? " • " + itemPurpose : "") + " (" + (itemDir === "OUT" ? "Exit Cam" : "Entrance Cam") + ")",
                time: item.date_created ? item.date_created.substr(11, 5) : "",
                timestamp: Date.now(),
                read: true,
                meta: item
            });
        });
        saveNotificationsToStorage();
    }

    updateNotificationUI();
    updateAlarmSoundButton();
}

function saveNotificationsToStorage() {
    try {
        // Keep at most 120 most recent notifications
        if (notificationsList.length > 120) {
            notificationsList = notificationsList.slice(0, 120);
        }
        localStorage.setItem(NOTIF_STORAGE_KEY, JSON.stringify(notificationsList));
    } catch (e) {}
}

function addNotification(type, title, subText, metaData) {
    const now = new Date();
    const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    const notif = {
        id: "notif_" + Date.now() + "_" + Math.random().toString(36).substr(2, 5),
        type: type, // 'IN', 'OUT', 'OVERDUE', 'DUE_SOON'
        title: title,
        sub: subText,
        time: timeStr,
        timestamp: Date.now(),
        read: false,
        meta: metaData || {}
    };

    notificationsList.unshift(notif);
    saveNotificationsToStorage();
    updateNotificationUI();
}

function updateNotificationUI() {
    const badge = document.getElementById("notifBadge");
    const drawerCount = document.getElementById("drawerTotalCount");
    const summaryText = document.getElementById("unreadSummaryText");
    const listContainer = document.getElementById("notifList");

    const unreadCount = notificationsList.filter(n => !n.read).length;

    if (badge) {
        if (unreadCount > 0) {
            badge.innerText = unreadCount > 99 ? "99+" : unreadCount;
            badge.style.display = "inline-flex";
        } else {
            badge.style.display = "none";
        }
    }

    if (drawerCount) {
        drawerCount.innerText = notificationsList.length;
    }

    if (summaryText) {
        summaryText.innerText = unreadCount + " unread";
    }

    if (!listContainer) return;

    // Filter items
    let filtered = notificationsList;
    if (currentNotifFilter === "IN") {
        filtered = notificationsList.filter(n => n.type === "IN");
    } else if (currentNotifFilter === "OUT") {
        filtered = notificationsList.filter(n => n.type === "OUT");
    } else if (currentNotifFilter === "ALERT") {
        filtered = notificationsList.filter(n => n.type === "OVERDUE" || n.type === "DUE_SOON");
    }

    if (filtered.length === 0) {
        listContainer.innerHTML =
            '<div class="notif-empty-state">' +
                '<div class="notif-empty-icon">📭</div>' +
                '<div>No ' + (currentNotifFilter === "all" ? "" : currentNotifFilter + " ") + 'notifications found.</div>' +
            '</div>';
        return;
    }

    let html = "";
    for (let i = 0; i < filtered.length; i++) {
        const item = filtered[i];
        let itemClass = "notif-item";
        let icon = "🔔";

        if (item.type === "IN") {
            itemClass += " notif-in";
            icon = "🟢";
        } else if (item.type === "OUT") {
            itemClass += " notif-out";
            icon = "🔴";
        } else if (item.type === "OVERDUE") {
            itemClass += " notif-overdue";
            icon = "⚠️";
        } else if (item.type === "DUE_SOON") {
            itemClass += " notif-duesoon";
            icon = "⏰";
        }

        if (!item.read) {
            itemClass += " unread";
        }

        html +=
            '<div class="' + itemClass + '" onclick="markNotificationSingleRead(\'' + item.id + '\')">' +
                '<div class="notif-item-icon">' + icon + '</div>' +
                '<div class="notif-item-body">' +
                    '<div class="notif-item-title">' +
                        '<span>' + escapeHtmlText(item.title) + '</span>' +
                        '<span class="notif-item-time">' + escapeHtmlText(item.time) + '</span>' +
                    '</div>' +
                    '<div class="notif-item-sub">' + escapeHtmlText(item.sub) + '</div>' +
                '</div>' +
            '</div>';
    }
    listContainer.innerHTML = html;
}

function filterNotifications(filterType) {
    currentNotifFilter = filterType;
    const tabs = document.querySelectorAll(".notif-tab");
    tabs.forEach(t => {
        if (t.getAttribute("data-filter") === filterType) {
            t.classList.add("active");
        } else {
            t.classList.remove("active");
        }
    });
    updateNotificationUI();
}

function markAllNotificationsAsRead() {
    notificationsList.forEach(n => n.read = true);
    saveNotificationsToStorage();
    updateNotificationUI();
}

function markNotificationSingleRead(id) {
    const item = notificationsList.find(n => n.id === id);
    if (item && !item.read) {
        item.read = true;
        saveNotificationsToStorage();
        updateNotificationUI();
    }
}

function clearAllNotifications() {
    if (confirm("Are you sure you want to clear all notification history?")) {
        notificationsList = [];
        saveNotificationsToStorage();
        updateNotificationUI();
    }
}

function toggleNotificationDrawer() {
    const drawer = document.getElementById("notifDrawer");
    const overlay = document.getElementById("notifDrawerOverlay");
    if (!drawer) return;

    if (drawer.classList.contains("open")) {
        closeNotificationDrawer();
    } else {
        drawer.classList.add("open");
        if (overlay) overlay.classList.add("active");
        // Mark all as read when opening drawer
        markAllNotificationsAsRead();
    }
}

function closeNotificationDrawer() {
    const drawer = document.getElementById("notifDrawer");
    const overlay = document.getElementById("notifDrawerOverlay");
    if (drawer) drawer.classList.remove("open");
    if (overlay) overlay.classList.remove("active");
}

function openNotificationDrawerWithTab(filterType) {
    toggleNotificationDrawer();
    filterNotifications(filterType);
}

function dismissOverstayBanner() {
    const banner = document.getElementById("overstayAlertBanner");
    if (banner) banner.style.display = "none";
}

/* OVERSTAY ALARM CHIME (Web Audio API Synthesizer) */
function playOverstayAlarmSound() {
    if (alarmAudioMuted) return;
    const now = Date.now();
    // Throttle alarm to sound at most once every 30 seconds
    if (now - lastOverstayAlarmSound < 30000) return;
    lastOverstayAlarmSound = now;

    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();

        const playTone = (freq, start, duration) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = "sawtooth";
            osc.frequency.setValueAtTime(freq, ctx.currentTime + start);
            gain.gain.setValueAtTime(0.2, ctx.currentTime + start);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + start + duration);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(ctx.currentTime + start);
            osc.stop(ctx.currentTime + start + duration);
        };

        // Two-tone warning beep: 784 Hz (G5) -> 659 Hz (E5) -> 784 Hz (G5)
        playTone(784, 0.0, 0.15);
        playTone(659, 0.2, 0.15);
        playTone(784, 0.4, 0.25);
    } catch (e) {}
}

function handleOverstayReminders(overdueList, dueSoonList) {
    const banner = document.getElementById("overstayAlertBanner");
    const textEl = document.getElementById("overstayAlertText");

    if (Array.isArray(overdueList) && overdueList.length > 0) {
        // We have active overdue visitors!
        playOverstayAlarmSound();

        if (banner && textEl) {
            banner.style.display = "flex";
            const first = overdueList[0];
            const count = overdueList.length;
            const extra = count > 1 ? (" (and " + (count - 1) + " other" + (count > 2 ? "s" : "") + ")") : "";
            textEl.innerHTML =
                '<strong>⚠️ VISITOR OVERSTAY ALERT:</strong> Plate <strong>' + escapeHtmlText(first.plate_number) + '</strong> ' +
                'exceeded stay limit (' + escapeHtmlText(first.purpose || 'Visitor') + '). Due: ' + escapeHtmlText(first.expected_time_out_formatted) +
                ' &bull; <span style="font-weight:800; color:#b91c1c;">' + first.minutes_overdue + ' mins overdue!</span>' + extra;
        }

        // Add overdue notification to drawer if not already recorded recently
        overdueList.forEach(v => {
            const key = "overdue_" + v.id + "_" + v.plate_number;
            if (!acknowledgedOverdueIds.has(key)) {
                acknowledgedOverdueIds.add(key);
                addNotification(
                    "OVERDUE",
                    "⚠️ Overstay Alert: " + (v.plate_number || "Visitor"),
                    (v.vehicle_type || "Car") + " • " + (v.purpose || "Visitor") + " • Due at " + v.expected_time_out_formatted + " (" + v.minutes_overdue + " mins overdue)",
                    v
                );
            }
        });
    } else if (Array.isArray(dueSoonList) && dueSoonList.length > 0) {
        // Visitors due soon (within 15 minutes)
        if (banner && textEl && banner.style.display === "none") {
            const first = dueSoonList[0];
            banner.style.display = "flex";
            banner.style.background = "#fffbeb";
            banner.style.borderLeftColor = "#f59e0b";
            textEl.innerHTML =
                '<strong>⏰ VISITOR DUE SOON:</strong> Plate <strong>' + escapeHtmlText(first.plate_number) + '</strong> ' +
                'time limit expires in <strong>' + first.minutes_left + ' minutes</strong> (' + escapeHtmlText(first.expected_time_out_formatted) + ').';
        }

        dueSoonList.forEach(v => {
            const key = "duesoon_" + v.id + "_" + v.plate_number;
            if (!acknowledgedOverdueIds.has(key)) {
                acknowledgedOverdueIds.add(key);
                addNotification(
                    "DUE_SOON",
                    "⏰ Due Soon: " + (v.plate_number || "Visitor"),
                    (v.vehicle_type || "Car") + " • Time limit expires in " + v.minutes_left + " mins (at " + v.expected_time_out_formatted + ")",
                    v
                );
            }
        });
    } else {
        if (banner) banner.style.display = "none";
    }
}


function initTapoCameras() {
    const entranceUrl = localStorage.getItem("tapo_entrance_url") || DEFAULT_ENTRANCE_URL;
    const exitUrl = localStorage.getItem("tapo_exit_url") || DEFAULT_EXIT_URL;

    const entranceImg = document.getElementById("entranceCamFeed");
    const exitImg = document.getElementById("exitCamFeed");

    if (entranceImg) {
        entranceImg.src = entranceUrl;
    }
    if (exitImg) {
        exitImg.src = exitUrl;
    }

    checkDetectionServerStatus();
    setInterval(checkDetectionServerStatus, 5000);
}

function onCamStreamLoad(camKey) {
    const offlineDiv = document.getElementById(camKey + "CamOffline");
    const dot = document.getElementById(camKey + "Dot");
    const text = document.getElementById(camKey + "StatusText");

    if (offlineDiv) {
        offlineDiv.style.display = "none";
    }
    if (dot) {
        dot.className = "status-dot online";
    }
    if (text) {
        text.innerText = "Live";
        text.style.color = "#22c55e";
    }
}

function onCamStreamError(camKey) {
    const img = document.getElementById(camKey + "CamFeed");
    const offlineDiv = document.getElementById(camKey + "CamOffline");
    const dot = document.getElementById(camKey + "Dot");
    const text = document.getElementById(camKey + "StatusText");

    if (img) {
        img.style.display = "none";
    }
    if (offlineDiv) {
        offlineDiv.style.display = "flex";
    }
    if (dot) {
        dot.className = "status-dot offline";
    }
    if (text) {
        text.innerText = "Offline";
        text.style.color = "#ef4444";
    }
}

function reloadCamStream(camKey) {
    const img = document.getElementById(camKey + "CamFeed");
    const offlineDiv = document.getElementById(camKey + "CamOffline");
    const dot = document.getElementById(camKey + "Dot");
    const text = document.getElementById(camKey + "StatusText");

    if (!img) return;

    if (offlineDiv) {
        offlineDiv.style.display = "none";
    }
    img.style.display = "block";

    if (dot) {
        dot.className = "status-dot offline";
    }
    if (text) {
        text.innerText = "Connecting...";
        text.style.color = "#f59e0b";
    }

    const defaultUrl = camKey === "entrance" ? DEFAULT_ENTRANCE_URL : DEFAULT_EXIT_URL;
    const savedUrl = localStorage.getItem("tapo_" + camKey + "_url") || defaultUrl;
    const cacheBuster = (savedUrl.indexOf("?") === -1 ? "?" : "&") + "t=" + Date.now();
    img.src = savedUrl + cacheBuster;
}

async function openTapoModal(targetCam) {
    const modal = document.getElementById("tapoSetupModal");
    const inputEntranceUrl = document.getElementById("cfgEntranceUrl");
    const inputExitUrl = document.getElementById("cfgExitUrl");
    const inputEntranceSrc = document.getElementById("cfgEntranceSource");
    const inputExitSrc = document.getElementById("cfgExitSource");
    const statusEnt = document.getElementById("modalEntranceStatus");
    const statusExit = document.getElementById("modalExitStatus");

    if (inputEntranceUrl) {
        inputEntranceUrl.value = localStorage.getItem("tapo_entrance_url") || DEFAULT_ENTRANCE_URL;
    }
    if (inputExitUrl) {
        inputExitUrl.value = localStorage.getItem("tapo_exit_url") || DEFAULT_EXIT_URL;
    }

    if (modal) {
        modal.classList.add("active");
    }

    // Fetch live hardware camera sources & connection state from Python server
    try {
        const res = await fetch(API_BASE_URL + "/api/cameras", { cache: "no-store" });
        const data = await res.json();
        if (data.success && data.cameras) {
            if (data.cameras.entrance && inputEntranceSrc) {
                inputEntranceSrc.value = data.cameras.entrance.source || "";
                if (data.cameras.entrance.zoom_factor && document.getElementById("cfgEntranceZoom")) {
                    document.getElementById("cfgEntranceZoom").value = String(data.cameras.entrance.zoom_factor);
                }
                if (statusEnt) {
                    const isConn = data.cameras.entrance.connected;
                    statusEnt.innerText = isConn ? "🟢 Connected" : "🔴 Offline";
                    statusEnt.style.background = isConn ? "#dcfce7" : "#fee2e2";
                    statusEnt.style.color = isConn ? "#15803d" : "#dc2626";
                }
            }
            if (data.cameras.exit && inputExitSrc) {
                inputExitSrc.value = data.cameras.exit.source || "";
                if (data.cameras.exit.zoom_factor && document.getElementById("cfgExitZoom")) {
                    document.getElementById("cfgExitZoom").value = String(data.cameras.exit.zoom_factor);
                }
                if (statusExit) {
                    const isConn = data.cameras.exit.connected;
                    statusExit.innerText = isConn ? "🟢 Connected" : "🔴 Offline";
                    statusExit.style.background = isConn ? "#dcfce7" : "#fee2e2";
                    statusExit.style.color = isConn ? "#15803d" : "#dc2626";
                }
            }
        }
    } catch (e) {
        if (statusEnt) statusEnt.innerText = "⚠️ Server Offline";
        if (statusExit) statusExit.innerText = "⚠️ Server Offline";
    }
}

function closeTapoModal() {
    const modal = document.getElementById("tapoSetupModal");
    if (modal) {
        modal.classList.remove("active");
    }
}

function applyPresetDualTapo() {
    const entSrc = document.getElementById("cfgEntranceSource");
    const exitSrc = document.getElementById("cfgExitSource");
    const entUrl = document.getElementById("cfgEntranceUrl");
    const exitUrl = document.getElementById("cfgExitUrl");

    if (entSrc) entSrc.value = "rtsp://Jhetlogs:123456789ten@192.168.1.18:554/stream2";
    if (exitSrc) exitSrc.value = "rtsp://Jhetlogs:123456789ten@192.168.1.19:554/stream2";
    if (entUrl) entUrl.value = DEFAULT_ENTRANCE_URL;
    if (exitUrl) exitUrl.value = DEFAULT_EXIT_URL;
}

function applyPresetTapoAndWebcam() {
    const entSrc = document.getElementById("cfgEntranceSource");
    const exitSrc = document.getElementById("cfgExitSource");
    const entUrl = document.getElementById("cfgEntranceUrl");
    const exitUrl = document.getElementById("cfgExitUrl");

    if (entSrc) entSrc.value = "rtsp://Jhetlogs:123456789ten@192.168.1.18:554/stream2";
    if (exitSrc) exitSrc.value = "rtsp://Jhetlogs:123456789ten@192.168.1.19:554/stream2";
    if (entUrl) entUrl.value = DEFAULT_ENTRANCE_URL;
    if (exitUrl) exitUrl.value = DEFAULT_EXIT_URL;
}

function applyPresetDualWebcams() {
    const entSrc = document.getElementById("cfgEntranceSource");
    const exitSrc = document.getElementById("cfgExitSource");
    const entUrl = document.getElementById("cfgEntranceUrl");
    const exitUrl = document.getElementById("cfgExitUrl");

    if (entSrc) entSrc.value = "0";
    if (exitSrc) exitSrc.value = "1";
    if (entUrl) entUrl.value = DEFAULT_ENTRANCE_URL;
    if (exitUrl) exitUrl.value = DEFAULT_EXIT_URL;
}

async function saveTapoSettings() {
    const inputEntranceUrl = document.getElementById("cfgEntranceUrl");
    const inputExitUrl = document.getElementById("cfgExitUrl");
    const inputEntranceSrc = document.getElementById("cfgEntranceSource");
    const inputExitSrc = document.getElementById("cfgExitSource");

    const entranceUrlVal = (inputEntranceUrl && inputEntranceUrl.value.trim()) || DEFAULT_ENTRANCE_URL;
    const exitUrlVal = (inputExitUrl && inputExitUrl.value.trim()) || DEFAULT_EXIT_URL;

    localStorage.setItem("tapo_entrance_url", entranceUrlVal);
    localStorage.setItem("tapo_exit_url", exitUrlVal);

    // Push hardware camera sources & view/zoom to Python detection server live
    const entSrcVal = inputEntranceSrc ? inputEntranceSrc.value.trim() : "";
    const exitSrcVal = inputExitSrc ? inputExitSrc.value.trim() : "";
    const entZoomVal = document.getElementById("cfgEntranceZoom") ? parseFloat(document.getElementById("cfgEntranceZoom").value) : 1.35;
    const exitZoomVal = document.getElementById("cfgExitZoom") ? parseFloat(document.getElementById("cfgExitZoom").value) : 1.35;

    try {
        await fetch(API_BASE_URL + "/api/cameras", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                entrance_source: entSrcVal,
                exit_source: exitSrcVal,
                entrance_zoom: entZoomVal,
                exit_zoom: exitZoomVal
            })
        });
    } catch (e) {
        console.warn("Could not push hardware sources to detection server:", e);
    }

    closeTapoModal();

    reloadCamStream("entrance");
    reloadCamStream("exit");
}

async function checkDetectionServerStatus() {
    try {
        const res = await fetch(API_BASE_URL + "/api/status", { cache: "no-store" });
        const data = await res.json();
        if (data.success && data.cameras) {
            const ent = data.cameras.entrance;
            const ex = data.cameras.exit;

            const entranceImg = document.getElementById("entranceCamFeed");
            if (ent && ent.connected && entranceImg && entranceImg.style.display === "none") {
                reloadCamStream("entrance");
            }

            const exitImg = document.getElementById("exitCamFeed");
            if (ex && ex.connected && exitImg && exitImg.style.display === "none") {
                reloadCamStream("exit");
            }
        }
    } catch (e) {
        // Server unreachable
    }
}


window.addEventListener("click", function(e) {

    const tapoModal = document.getElementById("tapoSetupModal");
    if (tapoModal && e.target === tapoModal) {
        closeTapoModal();
    }

    const visitorModal = document.getElementById("visitorInfoModal");
    if (visitorModal && e.target === visitorModal) {
        closeVisitorModal();
    }

    const exitModal = document.getElementById("visitorExitModal");
    if (exitModal && e.target === exitModal) {
        closeVisitorExitModal();
    }
});

document.addEventListener("DOMContentLoaded", function() { initTapoCameras(); loadStoredNotifications(); pollLiveCaptures(); });

</script>

<!-- Live auto-refresh (no full page reload) -->
<script>window.AUTO_REFRESH_SECONDS = 5;</script>
<script src="auto_refresh.js"></script>



<!-- =========================================================
     NOTIFICATION DRAWER (IN/OUT LOGS & OVERSTAY ALERTS)
========================================================= -->
<div class="notif-drawer-overlay" id="notifDrawerOverlay" onclick="closeNotificationDrawer()"></div>

<aside class="notif-drawer" id="notifDrawer">

    <div class="notif-drawer-header">
        <div class="notif-drawer-title">
            <span>🔔 Activity &amp; Alerts</span>
            <span class="notif-drawer-count" id="drawerTotalCount">0</span>
        </div>
        <button type="button" class="btn-close-drawer" onclick="closeNotificationDrawer()" aria-label="Close">&times;</button>
    </div>

    <div class="notif-tabs">
        <button type="button" class="notif-tab active" data-filter="all" onclick="filterNotifications('all')">All</button>
        <button type="button" class="notif-tab" data-filter="IN" onclick="filterNotifications('IN')">🟢 Entries</button>
        <button type="button" class="notif-tab" data-filter="OUT" onclick="filterNotifications('OUT')">🔴 Exits</button>
        <button type="button" class="notif-tab" data-filter="ALERT" onclick="filterNotifications('ALERT')">⚠️ Due Alerts</button>
    </div>

    <div class="notif-actions-bar">
        <span id="unreadSummaryText" style="font-size:11px; color:#64748b; font-weight:600;">0 unread</span>
        <div style="display:flex; gap:6px;">
            <button type="button" class="btn-notif-action" id="btnToggleAlarmSound" onclick="toggleOverstayAlarmAudio()">🔊 Sound On</button>
            <button type="button" class="btn-notif-action" onclick="markAllNotificationsAsRead()">Mark Read</button>
            <button type="button" class="btn-notif-action text-danger" onclick="clearAllNotifications()">Clear All</button>
        </div>
    </div>

    <div class="notif-list" id="notifList">
        <div class="notif-empty-state">
            <div class="notif-empty-icon">📭</div>
            <div>No activity notifications yet today.</div>
        </div>
    </div>

</aside>

</body>

</html>