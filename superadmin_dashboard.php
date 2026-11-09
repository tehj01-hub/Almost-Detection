<?php
require_once __DIR__ . '/app/session_helper.php';
require_once __DIR__ . '/app/system_config.php';
secsysStartRoleSession('Administrator');

/* =========================================================
   LOGIN CHECK
========================================================= */
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

/* =========================================================
   DATABASE CONNECTION
   Database: security_system
========================================================= */

$conn = secsysConnectDb();
secsysEnsureSchema($conn);
$systemName = secsysGetSystemName($conn);
$systemIcon = secsysGetSystemIcon($conn);

/* Letterhead + "Prepared by" for the printed report */
$reportTitle    = secsysGetSystemTitle($conn);
$reportSubtitle = secsysGetSystemSubtitle($conn);
$reportPreparedBy = (string)($_SESSION['username'] ?? 'Administrator');
$stmtAdminName = $conn->prepare("SELECT full_name FROM users WHERE username = ? LIMIT 1");
if ($stmtAdminName) {
    $stmtAdminName->bind_param("s", $reportPreparedBy);
    $stmtAdminName->execute();
    $adminRow = $stmtAdminName->get_result()->fetch_assoc();
    if ($adminRow && trim((string)($adminRow['full_name'] ?? '')) !== '') {
        $reportPreparedBy = trim((string)$adminRow['full_name']);
    }
    $stmtAdminName->close();
}

/* =========================================================
   ADMIN-ADDED CAMERAS (stored as JSON in system_settings;
   loader shared with guard_dashboard.php via system_config.php)
========================================================= */

function secsysValidateCameraInput(array $in, ?string &$error): ?array
{
    $name = trim((string)($in['name'] ?? ''));
    $direction = strtoupper(trim((string)($in['direction'] ?? 'MONITOR')));
    $model = trim((string)($in['model'] ?? ''));
    $url = trim((string)($in['url'] ?? ''));

    if ($name === '' || mb_strlen($name) > 40) {
        $error = 'Camera name is required (max 40 characters).';
        return null;
    }
    if (!in_array($direction, ['IN', 'OUT', 'MONITOR'], true)) {
        $direction = 'MONITOR';
    }
    if (mb_strlen($model) > 30) {
        $error = 'Camera model must be 30 characters or less.';
        return null;
    }
    if ($url === '' || strlen($url) > 500) {
        $error = 'Stream URL is required.';
        return null;
    }
    // Allow http(s) URLs or same-site relative paths (e.g. camera_proxy.php?path=/video/exit)
    $hasScheme = preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1;
    if ($hasScheme && !preg_match('#^https?://#i', $url)) {
        $error = 'Stream URL must start with http:// or https:// (browser-viewable MJPEG / snapshot stream).';
        return null;
    }
    if (strpos($url, '//') === 0) {
        $error = 'Stream URL must start with http:// or https://.';
        return null;
    }

    return [
        'name'      => $name,
        'direction' => $direction,
        'model'     => $model,
        'url'       => $url,
    ];
}

if (isset($_GET['action']) && in_array($_GET['action'], ['camera_add', 'camera_update', 'camera_delete'], true)) {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");

    // Custom header forces a CORS preflight, blocking cross-site form posts.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit();
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $cameras = secsysLoadExtraCameras($conn);
    $action = $_GET['action'];
    $error = null;
    $resultCam = null;

    if ($action === 'camera_add') {
        if (count($cameras) >= SECSYS_MAX_EXTRA_CAMERAS) {
            $error = 'Maximum of ' . SECSYS_MAX_EXTRA_CAMERAS . ' additional cameras reached.';
        } else {
            $data = secsysValidateCameraInput($input, $error);
            if ($data !== null) {
                $resultCam = ['id' => 'cam_' . bin2hex(random_bytes(5))] + $data;
                $cameras[] = $resultCam;
            }
        }
    } else {
        $id = (string)($input['id'] ?? '');
        $idx = null;
        foreach ($cameras as $i => $cam) {
            if ($cam['id'] === $id) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            $error = 'Camera not found.';
        } elseif ($action === 'camera_delete') {
            array_splice($cameras, $idx, 1);
        } else {
            $data = secsysValidateCameraInput($input, $error);
            if ($data !== null) {
                $resultCam = ['id' => $id] + $data;
                $cameras[$idx] = $resultCam;
            }
        }
    }

    if ($error !== null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $error]);
        exit();
    }

    if (!secsysSetSetting($conn, SECSYS_EXTRA_CAMERAS_KEY, json_encode(array_values($cameras), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not save camera settings.']);
        exit();
    }

    $actLabel = ['camera_add' => 'Added', 'camera_update' => 'Updated', 'camera_delete' => 'Removed'][$action];
    $camName = $resultCam['name'] ?? ($input['name'] ?? ($input['id'] ?? ''));
    @secsysLogActivity($conn, (string)$_SESSION['username'], 'Camera Settings', $actLabel . ' dashboard camera: ' . $camName);

    echo json_encode(['success' => true, 'camera' => $resultCam, 'cameras' => array_values($cameras)]);
    exit();
}

$extraCameras = secsysLoadExtraCameras($conn);

/* =========================================================
   FAST-PATH AJAX: LIVE DASHBOARD STATS
   Returns counts & hourly chart data for real-time auto-refresh
========================================================= */
if (isset($_GET['action']) && $_GET['action'] === 'dashboard_stats') {
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

    $todayDate = date("Y-m-d");

    // 1. Vehicle log statistics for today
    $sqlStats = "
        SELECT
            vehicle_category,
            vehicle_type,
            direction,
            COUNT(*) AS total
        FROM vehicle_logs
        WHERE DATE(date_created) = ?
        GROUP BY vehicle_category, vehicle_type, direction
    ";
    $stmtStats = $conn->prepare($sqlStats);
    $regMotoIn = 0; $regMotoOut = 0; $regCarIn = 0; $regCarOut = 0;
    $visMotoIn = 0; $visMotoOut = 0; $visCarIn = 0; $visCarOut = 0;

    if ($stmtStats) {
        $stmtStats->bind_param("s", $todayDate);
        $stmtStats->execute();
        $resStats = $stmtStats->get_result();
        while ($row = $resStats->fetch_assoc()) {
            $cat = strtolower(trim((string)($row['vehicle_category'] ?? '')));
            $typ = strtolower(trim((string)($row['vehicle_type'] ?? '')));
            $dir = strtoupper(trim((string)($row['direction'] ?? '')));
            $tot = (int)($row['total'] ?? 0);

            if ($cat === 'registered') {
                if ($typ === 'motorcycle') {
                    if ($dir === 'IN')  $regMotoIn = $tot;
                    if ($dir === 'OUT') $regMotoOut = $tot;
                } elseif ($typ === 'car') {
                    if ($dir === 'IN')  $regCarIn = $tot;
                    if ($dir === 'OUT') $regCarOut = $tot;
                }
            } elseif ($cat === 'visitor') {
                if ($typ === 'motorcycle') {
                    if ($dir === 'IN')  $visMotoIn = $tot;
                    if ($dir === 'OUT') $visMotoOut = $tot;
                } elseif ($typ === 'car') {
                    if ($dir === 'IN')  $visCarIn = $tot;
                    if ($dir === 'OUT') $visCarOut = $tot;
                }
            }
        }
        $stmtStats->close();
    }

    $totRegToday = $regMotoIn + $regCarIn;
    $totVisToday = $visMotoIn + $visCarIn;

    // 2. Hourly graph breakdown for chart
    $hoursList = [];
    $regHourly = array_fill(0, 24, 0);
    $visHourly = array_fill(0, 24, 0);
    $motoHourly = array_fill(0, 24, 0);
    $carHourly = array_fill(0, 24, 0);

    for ($h = 0; $h < 24; $h++) {
        $hoursList[] = date("g A", strtotime(sprintf("%02d:00:00", $h)));
    }

    $sqlHourly = "
        SELECT
            HOUR(date_created) AS log_hour,
            SUM(CASE WHEN vehicle_category = 'Registered' THEN 1 ELSE 0 END) AS registered_total,
            SUM(CASE WHEN vehicle_category = 'Visitor' THEN 1 ELSE 0 END) AS visitor_total,
            SUM(CASE WHEN vehicle_type = 'Motorcycle' THEN 1 ELSE 0 END) AS motorcycle_total,
            SUM(CASE WHEN vehicle_type = 'Car' THEN 1 ELSE 0 END) AS car_total
        FROM vehicle_logs
        WHERE DATE(date_created) = ?
        GROUP BY HOUR(date_created)
        ORDER BY HOUR(date_created)
    ";
    $stmtHourly = $conn->prepare($sqlHourly);
    if ($stmtHourly) {
        $stmtHourly->bind_param("s", $todayDate);
        $stmtHourly->execute();
        $resHourly = $stmtHourly->get_result();
        while ($r = $resHourly->fetch_assoc()) {
            $hr = (int)$r['log_hour'];
            if ($hr >= 0 && $hr < 24) {
                $regHourly[$hr] = (int)$r['registered_total'];
                $visHourly[$hr] = (int)$r['visitor_total'];
                $motoHourly[$hr] = (int)$r['motorcycle_total'];
                $carHourly[$hr] = (int)$r['car_total'];
            }
        }
        $stmtHourly->close();
    }

    $conn->close();

    echo json_encode([
        'success' => true,
        'stats' => [
            'reg_moto_in' => $regMotoIn,
            'reg_moto_out' => $regMotoOut,
            'reg_car_in' => $regCarIn,
            'reg_car_out' => $regCarOut,
            'vis_moto_in' => $visMotoIn,
            'vis_moto_out' => $visMotoOut,
            'vis_car_in' => $visCarIn,
            'vis_car_out' => $visCarOut,
            'total_reg_today' => $totRegToday,
            'total_vis_today' => $totVisToday
        ],
        'chart' => [
            'labels' => $hoursList,
            'series' => [
                $regHourly,
                $visHourly,
                $motoHourly,
                $carHourly
            ]
        ]
    ]);
    exit();
}


/* =========================================================
   TODAY'S DATE
========================================================= */

$today = date("Y-m-d");

$displayDate = date("l, F d, Y");


/* =========================================================
   DEFAULT VALUES
========================================================= */

$registeredMotorcycleIn = 0;
$registeredMotorcycleOut = 0;
$registeredCarIn = 0;
$registeredCarOut = 0;

$visitorMotorcycleIn = 0;
$visitorMotorcycleOut = 0;
$visitorCarIn = 0;
$visitorCarOut = 0;


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

    }
}

$stmt->close();


/* =========================================================
   TOTAL REGISTERED VEHICLES ENTERED TODAY
========================================================= */

$totalRegisteredToday =
    $registeredMotorcycleIn +
    $registeredCarIn;


/* =========================================================
   TOTAL VISITOR VEHICLES ENTERED TODAY
========================================================= */

$totalVisitorsToday =
    $visitorMotorcycleIn +
    $visitorCarIn;


/* =========================================================
   HOURLY GRAPH DATA
========================================================= */

$hours = [];

$registeredHourly = [];
$visitorHourly = [];
$motorcycleHourly = [];
$carHourly = [];


/* Create 24 hours */

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
   RECENT VEHICLE ACTIVITY (same as guard_dashboard.php)
   Latest 5 visitor + 5 registered captures today, newest first.
========================================================= */
$recentVisitorVehicles = [];
$recentRegisteredVehicles = [];
$activityToday = date("Y-m-d");

$stmtRecentVis = $conn->prepare("
    SELECT
        vl.id,
        vl.plate_number,
        vl.vehicle_type,
        COALESCE(NULLIF(vl.purpose, ''), vv.purpose, '') AS purpose,
        COALESCE(NULLIF(vl.visitor_name, ''), vv.visitor_name, '') AS visitor_name,
        vl.direction,
        vl.date_created
    FROM vehicle_logs vl
    LEFT JOIN visitor_vehicles vv
        ON vl.visitor_vehicle_id = vv.id
    WHERE DATE(vl.date_created) = ?
      AND LOWER(TRIM(vl.vehicle_category)) = 'visitor'
    ORDER BY vl.id DESC
    LIMIT 5
");
if ($stmtRecentVis) {
    $stmtRecentVis->bind_param("s", $activityToday);
    $stmtRecentVis->execute();
    $resRecentVis = $stmtRecentVis->get_result();
    while ($row = $resRecentVis->fetch_assoc()) {
        $recentVisitorVehicles[] = $row;
    }
    $stmtRecentVis->close();
}

$stmtRecentReg = $conn->prepare("
    SELECT
        vl.id,
        vl.plate_number,
        vl.vehicle_type,
        vl.direction,
        vl.date_created
    FROM vehicle_logs vl
    WHERE DATE(vl.date_created) = ?
      AND LOWER(TRIM(vl.vehicle_category)) = 'registered'
    ORDER BY vl.id DESC
    LIMIT 5
");
if ($stmtRecentReg) {
    $stmtRecentReg->bind_param("s", $activityToday);
    $stmtRecentReg->execute();
    $resRecentReg = $stmtRecentReg->get_result();
    while ($row = $resRecentReg->fetch_assoc()) {
        $recentRegisteredVehicles[] = $row;
    }
    $stmtRecentReg->close();
}

$conn->close();

?>

<!DOCTYPE html>

<html lang="en" class="ui-scale-fixed">

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
<script src="assets/js/print_report.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/print_report.js'); ?>"></script>
<script src="assets/js/chart_zoom_fix.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/chart_zoom_fix.js'); ?>"></script>


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

    padding: 32px 38px 40px;

    width: calc(100% - 212px);
    box-sizing: border-box;
}


/* =========================================================
   HEADER
========================================================= */

.header {
    margin-bottom: 22px;
    width: 100%;
    max-width: 1050px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    box-sizing: border-box;
}

.header-left {
    display: flex;
    flex-direction: column;
}

.header h1 {
    font-size: 26px;
    font-weight: 600;
    color: #111827;
    line-height: 1.2;
}

.header p {
    font-size: 13.5px;
    color: #64748b;
    margin-top: 4px;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 16px;
}

.clock {
    font-size: 28px;
    font-weight: 300;
    color: #334155;
    letter-spacing: 0.5px;
    font-variant-numeric: tabular-nums;
}


/* =========================================================
   VEHICLE ACTIVITY CARDS
   Same look as the Security Guard dashboard (guard_dashboard.php):
   Visitor = blue panel (left), Registered = green panel (right).
========================================================= */

.vehicle-cards,
.activity-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    width: 100%;
    max-width: 1050px;
    margin-bottom: 16px;
    box-sizing: border-box;
}

.vehicle-card,
.activity-card {
    background: #baf7b4;
    border: 1px solid #dedede;
    border-radius: 21px;
    padding: 13px 15px 15px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.13);
    min-width: 0;
    box-sizing: border-box;
}

.vehicle-card.panel-visitor {
    background: #b3dcf4;
}

.vehicle-card.panel-registered {
    background: #baf7b4;
}

.vehicle-card h2,
.activity-card h2 {
    font-size: 15px;
    font-weight: 600;
    color: #222;
    margin: 0 0 8px;
    min-height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-align: center;
}


/* =========================================================
   STATISTIC ROWS (same as guard .vehicle-item rows)
========================================================= */

.stats {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.stat {
    min-height: 44px;
    border-radius: 12px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    background: rgba(255, 255, 255, 0.85);
    border: 1px solid #e2e8f0;
    transition: all 0.18s ease;
    box-sizing: border-box;
}

.stat.row-in {
    background: #dcfce7;
    border: 1px solid #86efac;
    border-left: 5px solid #16a34a;
}

.stat.row-in:hover {
    background: #bbf7d0;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(22, 163, 74, 0.18);
}

.stat.row-out {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    border-left: 5px solid #dc2626;
}

.stat.row-out:hover {
    background: #fecaca;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(220, 38, 38, 0.18);
}

.stat-label {
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.stat-label .stat-dir {
    font-size: 10.5px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 9999px;
    letter-spacing: 0.4px;
}

.stat.row-in .stat-dir {
    background: #ffffff;
    color: #15803d;
    border: 1px solid #86efac;
}

.stat.row-out .stat-dir {
    background: #ffffff;
    color: #b91c1c;
    border: 1px solid #fca5a5;
}

.stat-value {
    font-size: 24px;
    line-height: 1;
    color: #1f2937;
    font-weight: 600;
    display: inline-block;
    transition: color 0.3s ease;
}

.stat.row-in .stat-value {
    color: #064e3b;
}

.stat.row-out .stat-value {
    color: #881337;
}

@keyframes statNumberPulse {
    0% { transform: scale(1); }
    35% { transform: scale(1.22); color: #16a34a; font-weight: 700; text-shadow: 0 0 10px rgba(22, 163, 74, 0.4); }
    100% { transform: scale(1); }
}

.stat-number-updated {
    animation: statNumberPulse 0.9s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
}


/* =========================================================
   TOTAL CARDS (same as guard dashboard)
========================================================= */

.total-cards {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    width: 100%;
    max-width: 1050px;
    margin-bottom: 16px;
    box-sizing: border-box;
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
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.10);
    min-width: 0;
    box-sizing: border-box;
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
    display: inline-block;
    transition: color 0.3s ease;
}


/* =========================================================
   CHART (same as guard dashboard)
========================================================= */

.chart-container {
    width: 100%;
    max-width: 1050px;
    background: white;
    border: 1px solid #dedede;
    border-radius: 21px;
    margin-top: 16px;
    padding: 20px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.11);
    box-sizing: border-box;
}

.chart-title {
    font-size: 21px;
    font-weight: 500;
    color: #222;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-subtitle {
    font-size: 12.5px;
    color: #64748b;
    margin-top: 4px;
    margin-bottom: 16px;
}

.chart-wrapper {
    position: relative;
    width: 100%;
    height: 300px;
}


/* =========================================================
   DATABASE BACKUP & RETRIEVE CARD
========================================================= */

.db-backup-card {
    width: 100%;
    max-width: 1050px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 18px 24px;
    margin-top: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    box-sizing: border-box;
}

.db-backup-info {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}

.db-backup-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #eff6ff;
    color: #1d4ed8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}

.db-backup-title {
    font-size: 15.5px;
    font-weight: 600;
    color: #0f172a;
}

.db-backup-desc {
    font-size: 12.5px;
    color: #64748b;
    margin-top: 3px;
    line-height: 1.4;
}

.btn-db-backup {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: #2563eb;
    color: #ffffff;
    border-radius: 10px;
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(37, 99, 235, 0.25);
    transition: all 0.15s ease;
}

.btn-db-backup:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
    box-shadow: 0 3px 6px rgba(37, 99, 235, 0.35);
}


/* =========================================================
   DASHBOARD ACTIONS: PRINT REPORT
========================================================= */

.dashboard-actions {
    width: 100%;
    max-width: 1050px;
    display: flex;
    align-items: center;
    justify-content: center;   /* Print Report centered under the chart */
    margin-top: 18px;
    box-sizing: border-box;
}

.print-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 22px;
    height: 40px;
    margin: 0;
    border: none;
    border-radius: 10px;
    background: #2563eb;
    color: white;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(37, 99, 235, 0.25);
    transition: all 0.15s ease;
}

.print-button:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
    box-shadow: 0 3px 6px rgba(37, 99, 235, 0.35);
}

.print-button:active {
    transform: translateY(0);
}

.btn-header-backup {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 14px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    border-radius: 8px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
    transition: all 0.15s ease;
}

.btn-header-backup:hover {
    background: #dbeafe;
    border-color: #93c5fd;
    transform: translateY(-1px);
}


/* =========================================================
   LARGE SCREEN
========================================================= */

@media (min-width: 1400px) {

    .main {
        padding-left: 45px;
        padding-right: 45px;
    }

    .header,
    .camera-section-header,
    .camera-grid,
    .vehicle-cards,
    .activity-grid,
    .total-cards,
    .chart-container,
    .db-backup-card,
    .dashboard-actions {
        max-width: 1100px;
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
        padding: 28px 25px 35px;
    }

    .header,
    .camera-section-header,
    .camera-grid,
    .vehicle-cards,
    .activity-grid,
    .total-cards,
    .chart-container,
    .db-backup-card,
    .dashboard-actions {
        max-width: 100%;
    }

    .vehicle-card h2,
    .activity-card h2 {
        font-size: 17px;
    }

    .stat {
        min-height: 78px;
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

        padding: 25px 20px 30px;
    }

    .camera-grid,
    .vehicle-cards,
    .activity-grid,
    .total-cards {
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .clock {
        font-size: 24px;
    }
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 600px) {

    .main {
        padding: 20px 14px 30px;
    }

    .header h1 {
        font-size: 22px;
    }

    .clock {
        font-size: 20px;
    }

    .camera-grid,
    .vehicle-cards,
    .activity-grid,
    .total-cards {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .vehicle-card h2,
    .activity-card h2 {
        font-size: 17px;
    }

    .stat {
        min-height: 80px;
    }

    .chart-container {
        padding: 16px;
    }

    .chart-title {
        font-size: 17px;
    }

    .chart-wrapper {
        height: 240px;
    }

    .db-backup-card {
        flex-direction: column;
        align-items: stretch;
        padding: 16px;
    }

    .btn-db-backup {
        width: 100%;
        justify-content: center;
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

    .sidebar,
    .camera-grid,
    .camera-section-header,
    .db-backup-card,
    .dashboard-actions,
    .tapo-modal-overlay {
        display: none !important;
    }

    .main {
        margin-left: 0;

        width: 100%;
        max-width: 100%;

        padding: 20px;
    }

    .print-button {
        display: none;
    }

    .vehicle-card,
    .total-card,
    .chart-container {
        box-shadow: none;
        border: 1px solid #ccc;
    }
}


/* =========================================================
   CAMERA GRID (2-CAMERA TAPO C500 SYSTEM)
========================================================= */

.camera-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    width: 100%;
    max-width: 1050px;
    margin-bottom: 10px;
}

.camera-section-title {
    font-size: 15px;
    font-weight: 700;
    color: #1f2937;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.camera-count {
    font-size: 11px;
    font-weight: 600;
    color: #475569;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    padding: 2px 8px;
    border-radius: 9999px;
}

.btn-add-camera {
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 7px 14px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.15s ease;
}

.btn-add-camera:hover {
    background: #1d4ed8;
}

/* Every card uses the same width (2 equal columns) and the same 16:9 screen */
.camera-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    width: 100%;
    max-width: 1050px;
    margin-bottom: 16px;
    box-sizing: border-box;
}

.camera-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    min-width: 0;
    position: relative;
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
}

.camera-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    min-height: 28px;
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
    min-width: 0;
}

.camera-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.camera-actions {
    display: inline-flex;
    gap: 6px;
    flex-shrink: 0;
}

.camera-tag {
    white-space: nowrap;
}

.camera-tag.dir-badge {
    font-size: 11px;
    padding: 3px 10px;
    font-weight: 800;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.camera-tag.dir-in {
    color: #15803d;
    background: #dcfce7;
    border-color: #86efac;
}

.camera-tag.dir-out {
    color: #b91c1c;
    background: #fee2e2;
    border-color: #fca5a5;
}

.camera-tag.dir-monitor {
    color: #0369a1;
    background: #e0f2fe;
    border-color: #7dd3fc;
}

.btn-cam-remove {
    color: #b91c1c !important;
}

.btn-cam-remove:hover {
    background: #fee2e2 !important;
}

/* "Add camera" tile shares the card's exact footprint */
.camera-card.camera-card-add {
    border: 2px dashed #cbd5e1;
    background: #f8fafc;
    box-shadow: none;
    cursor: pointer;
    align-items: center;
    justify-content: center;
    color: #475569;
    font-weight: 600;
    font-size: 13px;
    gap: 6px;
    min-height: 100%;
    transition: border-color 0.15s ease, background 0.15s ease;
}

.camera-card.camera-card-add:hover {
    border-color: #2563eb;
    background: #eff6ff;
    color: #1d4ed8;
}

.camera-card-add-icon {
    font-size: 30px;
    line-height: 1;
}

.camera-form-error {
    display: none;
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
    border-radius: 6px;
    padding: 7px 10px;
    font-size: 12px;
    margin-bottom: 12px;
}

.tapo-form-group select {
    width: 100%;
    padding: 9px 11px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-size: 13px;
    box-sizing: border-box;
    background: #fff;
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
    aspect-ratio: 16 / 9;
    border-radius: 8px;
    overflow: hidden;
    background: #0f172a;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

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

</style>

<style>

/* =========================================================
   RECENT VEHICLE ACTIVITY - copied from guard_dashboard.php
   (scoped to .admin-activity so the admin count panels keep
   their own styles)
========================================================= */

.admin-activity {
    margin-bottom: 16px;
}

.admin-activity .activity-cards {
    background: #b3dcf4;
    border: 1px solid #dedede;
    border-radius: 21px;
    padding: 13px 15px 15px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.13);
    min-width: 0;
}

.admin-activity .activity-card {
    background: #baf7b4;
}

.admin-activity-link {
    position: absolute;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    font-size: 11.5px;
    font-weight: 700;
    color: #1769d3;
    text-decoration: none;
    background: rgba(255, 255, 255, 0.75);
    border: 1px solid rgba(23, 105, 211, 0.25);
    border-radius: 6px;
    padding: 3px 10px;
}

.admin-activity-link:hover {
    background: #ffffff;
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

.admin-activity .dir-badge {
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

.admin-activity .dir-badge-in {
    background: #dcfce7 !important;
    color: #15803d !important;
    border: 1px solid #86efac !important;
}

.admin-activity .dir-badge-out {
    background: #fee2e2 !important;
    color: #b91c1c !important;
    border: 1px solid #fca5a5 !important;
}

.admin-activity .dir-badge-monitor {
    background: #e0f2fe !important;
    color: #0369a1 !important;
    border: 1px solid #7dd3fc !important;
}

.admin-activity .type-text {
    font-size: 11px;
    font-weight: 600;
    color: #374151;
    margin-right: 4px;
}

.admin-activity .activity-header {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
    position: relative;
    min-height: 26px;
}

.admin-activity .activity-title {
    text-align: center;
    font-size: 15px;
    margin-bottom: 8px;
    font-weight: 600;
}

.admin-activity .activity-header .activity-title {
    margin-bottom: 0;
}

.admin-activity .activity-stack {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.admin-activity .vehicle-item {
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

.admin-activity .vehicle-item.row-in {
    background: #dcfce7 !important;
    border: 1px solid #86efac !important;
    border-left: 5px solid #16a34a !important;
}

.admin-activity .vehicle-item.row-in:hover {
    background: #bbf7d0 !important;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(22, 163, 74, 0.18);
}

.admin-activity .vehicle-item.row-in .plate-text {
    color: #064e3b !important;
}

.admin-activity .vehicle-item.row-out {
    background: #fee2e2 !important;
    border: 1px solid #fca5a5 !important;
    border-left: 5px solid #dc2626 !important;
}

.admin-activity .vehicle-item.row-out:hover {
    background: #fecaca !important;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(220, 38, 38, 0.18);
}

.admin-activity .vehicle-item.row-out .plate-text {
    color: #881337 !important;
}

.admin-activity .vehicle-item:not(.row-in):not(.row-out) {
    background: #e2e8f0;
    border: 1px solid #cbd5e1;
    border-left: 5px solid #64748b;
}

.admin-activity .vehicle-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.admin-activity .vehicle-plate {
    font-size: 12px;
    font-weight: 600;
    color: #111;
    display: flex;
    align-items: center;
    gap: 6px;
}

.admin-activity .vehicle-direction {
    font-size: 10px;
    color: #4b5563;
}

.admin-activity .vehicle-badges {
    display: flex;
    align-items: center;
    gap: 5px;
    flex-shrink: 0;
    overflow: hidden;
}

.admin-activity .purpose-pill {
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

.admin-activity .purpose-pill.needs-info {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #f59e0b;
}

.admin-activity .captured {
    background: #c5d8ef;
    border-radius: 10px;
    padding: 3px 7px;
    font-size: 8px;
    font-weight: 600;
    color: #374151;
}

.admin-activity .activity-card .captured {
    background: #bbf2b5;
}

.admin-activity .empty-activity {
    text-align: center;
    color: #666;
    font-size: 11px;
    padding: 15px 5px;
}

/* Today's In / Out breakdown: compact 2 x 2 grid under the totals */
.admin-counts {
    margin-top: 0;
}

.admin-counts .stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px;
}

.admin-counts .vehicle-card h2,
.admin-counts.activity-grid h2 {
    font-size: 13px;
    margin-bottom: 6px;
    min-height: 20px;
}

.admin-counts .stat {
    min-height: 38px;
    padding: 6px 10px;
}

.admin-counts .stat-value {
    font-size: 20px;
}

.admin-counts .stat-label {
    white-space: nowrap;
    font-size: 12px;
    gap: 6px;
    min-width: 0;
}

.admin-counts .stat-label .stat-dir {
    padding: 1px 7px;
    font-size: 9.5px;
}
</style>

<!-- Same look as the Security Guard dashboard for every Administrator page -->
<link rel="stylesheet" href="assets/css/admin_uniform.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/admin_uniform.css'); ?>">
<!-- Enlarges the dashboard 25% on desktop (loaded after the page styles) -->
<link rel="stylesheet" href="assets/css/ui_scale.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/ui_scale.css'); ?>">

</head>


<body>


<!-- =====================================================
     SIDEBAR
====================================================== -->

<aside class="sidebar">


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

            <div class="logo-subtitle"><?php echo secsysAdminSidebarSubtitleHtml(); ?></div>

        </div>

    </div>


    <nav class="nav">


        <a href="superadmin_dashboard.php"
           class="active">

            <span class="nav-icon">
                🖥️
            </span>

            <span>
                Dashboard
            </span>

        </a>


        <a href="logs.php">

            <span class="nav-icon">
                📋
            </span>

            <span>
                Logs
            </span>

        </a>


        <a href="registered.php">

            <span class="nav-icon">
                🚗
            </span>

            <span>
                Registered
            </span>

        </a>


        <a href="visitor.php">

            <span class="nav-icon">
                👥
            </span>

            <span>
                Visitor
            </span>

        </a>


        <a href="security_guard.php">

            <span class="nav-icon">
                🛡️
            </span>

            <span>
                Security Guard
            </span>

        </a>


        <a href="security_information.php">

            <span class="nav-icon">
                📑
            </span>

            <span>
                Security Information
            </span>

        </a>


        <a href="report.php">

            <span class="nav-icon">
                📈
            </span>

            <span>
                Report
            </span>

        </a>

        <a href="backup.php">

            <span class="nav-icon">
                💾
            </span>

            <span>
                Backup &amp; Retrieve
            </span>

        </a>

        <a href="profile.php">

            <span class="nav-icon">
                ⚙️
            </span>

            <span>
                Profile
            </span>

        </a>


    </nav>


    <a href="logout.php?role=Administrator"
       class="logout">

        <span class="nav-icon">🚪</span>
        <span>Logout</span>

    </a>


</aside>



<!-- =====================================================
     MAIN
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
                <?php echo htmlspecialchars($displayDate); ?> &bull; <span style="font-size:12px; color:#64748b; font-weight:500;">(Resets daily at 12:00 AM)</span>
            </p>
            <div style="margin-top:6px;"><?php echo secsysNetworkAccessHtml(true); ?></div>
        </div>

        <div class="header-right">
            <div class="dual-clock">
                <div class="clock-card" title="Time from internet time servers (NTP)">
                    <div class="clock-label"><span>🌐 Internet Time</span><span class="clock-badge" id="internetClockStatus">Syncing…</span></div>
                    <div class="clock" id="internetClock">--:--:--</div>
                </div>
                <div class="clock-card" title="This unit's own clock">
                    <div class="clock-label"><span>🖥️ System Time</span><span class="clock-badge" id="systemClockDrift"></span></div>
                    <div class="clock" id="liveClock"><?php echo date('h:i:s A'); ?></div>
                </div>
            </div>
        </div>

    </div>



    <!-- =================================================
         CAMERA SECTION (TAPO C500 - 2 CAMERAS)
    ================================================== -->

    <div class="camera-section-header">
        <div class="camera-section-title">
            📹 Live Cameras
            <span class="camera-count" id="cameraCount"></span>
        </div>
        <button
            type="button"
            class="btn-add-camera"
            onclick="openCameraEditor()"
            title="Add extra camera"
        >
            ➕ Add Camera
        </button>
    </div>

    <!-- All camera cards (built-in Entrance/Exit + admin-added) are rendered
         from one template in renderCameraGrid() so every card is identical. -->
    <div class="camera-grid" id="cameraGrid"></div>


    <!-- =================================================
         RECENT VEHICLE ACTIVITY (same layout as the Security
         Guard dashboard: Visitor left, Registered right).
         Read-only for the Administrator; refreshed live by
         assets/js/auto_refresh.js (data-live).
    ================================================== -->

    <div class="activity-grid admin-activity" id="adminLiveActivity" data-live>

        <!-- VISITOR (LEFT) -->
        <div class="activity-cards">
            <div class="activity-header">
                <div class="activity-title">Visitor</div>
                <a class="admin-activity-link" href="visitor.php" title="Open the Visitor module">View all ›</a>
            </div>
            <div class="activity-stack">
                <?php if (!empty($recentVisitorVehicles)): ?>
                    <?php foreach ($recentVisitorVehicles as $vehicle):
                        $vPlate   = trim((string)($vehicle['plate_number'] ?? ''));
                        $vType    = secsysNormalizeVehicleType(trim((string)($vehicle['vehicle_type'] ?? 'Car')));
                        $vPurpose = trim((string)($vehicle['purpose'] ?? ''));
                        $isOutVis = strtoupper(trim((string)($vehicle['direction'] ?? 'IN'))) === 'OUT';
                        $vTime    = !empty($vehicle['date_created']) ? date('h:i A', strtotime($vehicle['date_created'])) : '';
                    ?>
                        <div class="vehicle-item <?php echo $isOutVis ? 'row-out' : 'row-in'; ?>">
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
                                <?php if ($vPurpose !== '' && strtolower($vPurpose) !== 'visitor entry'): ?>
                                    <span class="purpose-pill" title="<?php echo htmlspecialchars($vPurpose, ENT_QUOTES); ?>"><?php echo htmlspecialchars($vPurpose); ?></span>
                                <?php elseif (!$isOutVis): ?>
                                    <span class="purpose-pill needs-info">Awaiting Info</span>
                                <?php endif; ?>
                                <div class="captured"><?php echo htmlspecialchars($vTime); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-activity">No visitor vehicle captured today.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- REGISTERED (RIGHT) -->
        <div class="activity-card">
            <div class="activity-header">
                <div class="activity-title">Registered</div>
                <a class="admin-activity-link" href="logs.php" title="Open the Logs module">View all ›</a>
            </div>
            <div class="activity-stack">
                <?php if (!empty($recentRegisteredVehicles)): ?>
                    <?php foreach ($recentRegisteredVehicles as $vehicle):
                        $rPlate   = trim((string)($vehicle['plate_number'] ?? ''));
                        $rType    = trim((string)($vehicle['vehicle_type'] ?? ''));
                        $isOutReg = strtoupper(trim((string)($vehicle['direction'] ?? 'IN'))) === 'OUT';
                        $rTime    = !empty($vehicle['date_created']) ? date('h:i A', strtotime($vehicle['date_created'])) : '';
                    ?>
                        <div class="vehicle-item <?php echo $isOutReg ? 'row-out' : 'row-in'; ?>">
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
                                <div class="captured"><?php echo htmlspecialchars($rTime); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-activity">No registered vehicle captured today.</div>
                <?php endif; ?>
            </div>
        </div>

    </div>



    <!-- =================================================
         VEHICLE ACTIVITY CARDS
    ================================================== -->

    <!-- =================================================
         TOTALS (Visitor left, Registered right - same as guard dashboard)
    ================================================== -->

    <div class="total-cards" id="liveTotals">

        <div class="total-card">
            <div class="total-label">Total Visitor Vehicles Entered Today</div>
            <div class="total-number" id="statTotalVisToday"><?php echo $totalVisitorsToday; ?></div>
        </div>

        <div class="total-card">
            <div class="total-label">Total Registered Vehicles Entered Today</div>
            <div class="total-number" id="statTotalRegToday"><?php echo $totalRegisteredToday; ?></div>
        </div>

    </div>



    <!-- TODAY'S IN / OUT BREAKDOWN (compact; admin-only detail) -->
    <div class="vehicle-cards activity-grid admin-counts" id="liveVehicleStats">

        <!-- VISITOR VEHICLES (blue, left - same as guard dashboard) -->
        <section class="vehicle-card activity-card panel-visitor">
            <h2>Visitor Vehicles Today</h2>
            <div class="stats">
                <div class="stat row-in">
                    <div class="stat-label">🏍 Motorcycle <span class="stat-dir">🟢 IN</span></div>
                    <div class="stat-value" id="statVisMotoIn"><?php echo $visitorMotorcycleIn; ?></div>
                </div>
                <div class="stat row-out">
                    <div class="stat-label">🏍 Motorcycle <span class="stat-dir">🔴 OUT</span></div>
                    <div class="stat-value" id="statVisMotoOut"><?php echo $visitorMotorcycleOut; ?></div>
                </div>
                <div class="stat row-in">
                    <div class="stat-label">🚗 Car <span class="stat-dir">🟢 IN</span></div>
                    <div class="stat-value" id="statVisCarIn"><?php echo $visitorCarIn; ?></div>
                </div>
                <div class="stat row-out">
                    <div class="stat-label">🚗 Car <span class="stat-dir">🔴 OUT</span></div>
                    <div class="stat-value" id="statVisCarOut"><?php echo $visitorCarOut; ?></div>
                </div>
            </div>
        </section>

        <!-- REGISTERED VEHICLES (green, right - same as guard dashboard) -->
        <section class="vehicle-card activity-card panel-registered">
            <h2>Registered Vehicles Today</h2>
            <div class="stats">
                <div class="stat row-in">
                    <div class="stat-label">🏍 Motorcycle <span class="stat-dir">🟢 IN</span></div>
                    <div class="stat-value" id="statRegMotoIn"><?php echo $registeredMotorcycleIn; ?></div>
                </div>
                <div class="stat row-out">
                    <div class="stat-label">🏍 Motorcycle <span class="stat-dir">🔴 OUT</span></div>
                    <div class="stat-value" id="statRegMotoOut"><?php echo $registeredMotorcycleOut; ?></div>
                </div>
                <div class="stat row-in">
                    <div class="stat-label">🚗 Car <span class="stat-dir">🟢 IN</span></div>
                    <div class="stat-value" id="statRegCarIn"><?php echo $registeredCarIn; ?></div>
                </div>
                <div class="stat row-out">
                    <div class="stat-label">🚗 Car <span class="stat-dir">🔴 OUT</span></div>
                    <div class="stat-value" id="statRegCarOut"><?php echo $registeredCarOut; ?></div>
                </div>
            </div>
        </section>

    </div>



    <!-- =================================================
         CHART
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


        <!-- Live chart data (read by assets/js/auto_refresh.js) -->
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
         DASHBOARD ACTIONS: PRINT REPORT
    ================================================== -->
    <div class="dashboard-actions">

        <button
            class="print-button"
            onclick="printDashboard()">
            🖨️ Print Report
        </button>

    </div>


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
                        <span>Entrance Camera (Local / Webcam 0)</span>
                        <span style="background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700;">Direction: IN</span>
                    </div>
                    <span id="modalEntranceStatus" style="font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: #e2e8f0; color: #475569;">
                        Checking...
                    </span>
                </div>

                <div class="tapo-form-group">
                    <label for="cfgEntranceSource">Hardware Source (USB Cam Index or RTSP URL):</label>
                    <input
                        type="text"
                        id="cfgEntranceSource"
                        placeholder="0"
                    >
                    <div class="tapo-help-text">
                        For local USB webcam: <code>0</code> | For Tapo/RTSP: <code>rtsp://username:password@IP:554/stream2</code>
                    </div>
                </div>

                <div class="tapo-form-group" style="margin-bottom: 0;">
                    <label for="cfgEntranceUrl">Dashboard Stream URL:</label>
                    <input
                        type="text"
                        id="cfgEntranceUrl"
                        placeholder="http://localhost:5000/video/entrance"
                    >
                </div>
            </div>

            <!-- Exit Camera Card -->
            <div class="cam-section-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <div style="font-weight: 700; font-size: 13px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span>Exit Camera (Local / Webcam 1)</span>
                        <span style="background: #ffedd5; color: #c2410c; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700;">Direction: OUT</span>
                    </div>
                    <span id="modalExitStatus" style="font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: #e2e8f0; color: #475569;">
                        Checking...
                    </span>
                </div>

                <div class="tapo-form-group">
                    <label for="cfgExitSource">Hardware Source (USB Cam Index or RTSP URL):</label>
                    <input
                        type="text"
                        id="cfgExitSource"
                        placeholder="1"
                    >
                    <div class="tapo-help-text">
                        For local USB webcam: <code>1</code> (or <code>0</code>) | For Tapo/RTSP: <code>rtsp://...IP:554/stream2</code>
                    </div>
                </div>

                <div class="tapo-form-group" style="margin-bottom: 0;">
                    <label for="cfgExitUrl">Dashboard Stream URL:</label>
                    <input
                        type="text"
                        id="cfgExitUrl"
                        placeholder="http://localhost:5000/video/exit"
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
     ADD / EDIT CAMERA MODAL
========================================================= -->
<div class="tapo-modal-overlay" id="cameraEditorModal">

    <div class="tapo-modal-box" style="max-width: 520px;">

        <div class="tapo-modal-header">
            <div class="tapo-modal-title" id="cameraEditorTitle">➕ Add Camera</div>
            <button type="button" class="tapo-modal-close" onclick="closeCameraEditor()" aria-label="Close">&times;</button>
        </div>

        <div class="tapo-modal-body">

            <div class="camera-form-error" id="camEditorError"></div>

            <input type="hidden" id="camEditorId" value="">

            <div class="tapo-form-group">
                <label for="camEditorName">Camera Name:</label>
                <input type="text" id="camEditorName" maxlength="40" placeholder="e.g. Gate 2 Cam">
            </div>

            <div class="tapo-form-group">
                <label for="camEditorDirection">Direction / Purpose:</label>
                <select id="camEditorDirection">
                    <option value="IN">IN (Entrance)</option>
                    <option value="OUT">OUT (Exit)</option>
                    <option value="MONITOR" selected>Monitoring only</option>
                </select>
            </div>

            <div class="tapo-form-group">
                <label for="camEditorModel">Camera Model (optional):</label>
                <input type="text" id="camEditorModel" maxlength="30" placeholder="Tapo C500">
            </div>

            <div class="tapo-form-group" style="margin-bottom: 0;">
                <label for="camEditorUrl">Dashboard Stream URL:</label>
                <input type="text" id="camEditorUrl" maxlength="500" placeholder="http://192.168.1.20:5000/video/entrance">
                <div class="tapo-help-text">
                    Must be a browser-viewable MJPEG / image stream (http:// or https://), e.g. another detection
                    server's <code>/video/...</code> feed. Raw <code>rtsp://</code> links cannot play in a browser.
                </div>
            </div>

        </div>

        <div class="tapo-modal-footer">
            <button type="button" class="btn-tapo-cancel" onclick="closeCameraEditor()">Cancel</button>
            <button type="button" class="btn-tapo-save" id="camEditorSaveBtn" onclick="saveCameraEditor()">💾 Save Camera</button>
        </div>

    </div>

</div>



<script>

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

/* =========================================================
   PRINT REPORT (same layout as the Visitor Vehicle Report)
   Uses the live dashboard numbers; printed through
   assets/js/print_report.js (hidden frame, no pop-up).
========================================================= */

const REPORT_META = <?php echo json_encode([
    'preparedBy' => $reportPreparedBy,
    'reportDate' => date('F d, Y'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;

function reportNum(id) {
    const el = document.getElementById(id);
    const n = el ? parseInt(String(el.textContent).replace(/[^0-9-]/g, ""), 10) : 0;
    return isNaN(n) ? 0 : n;
}

function collectRecentActivity(index, category) {
    const card = document.querySelectorAll("#adminLiveActivity > div")[index];
    if (!card) return [];
    return Array.from(card.querySelectorAll(".vehicle-item")).map(function (row) {
        const q = function (sel) {
            const el = row.querySelector(sel);
            return el ? el.textContent.replace(/\s+/g, " ").trim() : "";
        };
        return {
            time: q(".captured"),
            plate: q(".plate-text"),
            type: q(".type-text") || "-",
            category: category,
            direction: row.classList.contains("row-out") ? "OUT" : "IN",
            purpose: q(".purpose-pill") || "-"
        };
    });
}

function printDashboard() {
    if (!window.SecsysPrint) {
        window.print();
        return;
    }
    const P = window.SecsysPrint;
    const c = function (text) { return { text: text, cls: "center" }; };

    // Summary (live tiles)
    const summary = [
        ["Registered", "Motorcycle", reportNum("statRegMotoIn"), reportNum("statRegMotoOut")],
        ["Registered", "Car", reportNum("statRegCarIn"), reportNum("statRegCarOut")],
        ["Visitor", "Motorcycle", reportNum("statVisMotoIn"), reportNum("statVisMotoOut")],
        ["Visitor", "Car", reportNum("statVisCarIn"), reportNum("statVisCarOut")]
    ];
    const sumIn = summary.reduce(function (t, r) { return t + r[2]; }, 0);
    const sumOut = summary.reduce(function (t, r) { return t + r[3]; }, 0);
    const summaryRows = summary.map(function (r, i) { return [c(i + 1), r[0], r[1], c(r[2]), c(r[3])]; });
    summaryRows.push({ rowClass: "total", cells: [c(""), "TOTAL", "", c(sumIn), c(sumOut)] });

    // Hourly (chart data, only hours with movement)
    const hourly = [];
    let chartImg = "";
    const chart = (typeof Chart !== "undefined" && Chart.getChart) ? Chart.getChart("vehicleChart") : null;
    if (chart) {
        const sets = chart.data.datasets || [];
        const pick = function (name) {
            const ds = sets.find(function (d) { return String(d.label).toLowerCase() === name; });
            return ds ? ds.data : [];
        };
        const r = pick("registered"), v = pick("visitor"), m = pick("motor"), k = pick("car");
        (chart.data.labels || []).forEach(function (label, i) {
            const vals = [r[i] || 0, v[i] || 0, m[i] || 0, k[i] || 0];
            if (vals.some(function (x) { return x > 0; })) {
                hourly.push([c(hourly.length + 1), c(label), c(vals[0]), c(vals[1]), c(vals[2]), c(vals[3])]);
            }
        });
        // draw synchronously first: the chart may still be animating (or the tab
        // may not have painted yet), which would capture a blank image
        try { chart.draw(); chartImg = chart.toBase64Image("image/png", 1); } catch (e) { chartImg = ""; }
    }

    // Recent activity (latest captures shown on the dashboard)
    const recent = collectRecentActivity(0, "Visitor").concat(collectRecentActivity(1, "Registered"))
        .map(function (x, i) { return [c(i + 1), c(x.time), x.plate, x.type, x.category, c(x.direction), x.purpose]; });

    const body =
        P.table([["#", 6], ["Category", 24], ["Vehicle Type", 24], ["Entered (IN)", 23], ["Exited (OUT)", 23]],
            summaryRows, "No vehicle movement today.", "Summary of Vehicle Movement")
        + P.table([["#", 6], ["Hour", 18], ["Registered", 19], ["Visitor", 19], ["Motorcycle", 19], ["Car", 19]],
            hourly, "No vehicle movement recorded today.", "Hourly Vehicle Activity")
        + (chartImg ? '<div class="chart"><img src="' + chartImg + '" alt="Hourly vehicle activity"></div>' : "")
        + P.table([["#", 5], ["Time", 12], ["Plate Number", 16], ["Vehicle Type", 14], ["Category", 13], ["Direction", 11], ["Purpose", 29]],
            recent, "No vehicle captured today.", "Recent Vehicle Activity");

    P.print({
        title: "Daily Vehicle Monitoring Report",
        meta: [
            "Report Date: " + REPORT_META.reportDate,
            "Total Registered Vehicles Entered: " + reportNum("statTotalRegToday")
                + "  |  Total Visitor Vehicles Entered: " + reportNum("statTotalVisToday"),
            "Prepared by: " + REPORT_META.preparedBy
        ],
        body: body,
        footer: "Daily Vehicle Monitoring Report"
    });
}


/* =========================================================
   LIVE CLOCK
========================================================= */

/* Live clocks (Internet + System) are driven by assets/js/dual_clock.js */


/* =========================================================
   DUAL CAMERA (ENTRANCE & EXIT) CONTROLLER
========================================================= */

const LOCAL_HOST = (window.location && window.location.hostname) ? window.location.hostname : "localhost";
const DEFAULT_ENTRANCE_URL = "http://" + LOCAL_HOST + ":5000/video/entrance";
const DEFAULT_EXIT_URL = "http://" + LOCAL_HOST + ":5000/video/exit";
const API_BASE_URL = "http://" + LOCAL_HOST + ":5000";

/* Built-in cameras served by detect_plate_server.py; admin-added ones come from the DB */
const BUILTIN_CAMERAS = [
    { key: "entrance", name: "Entrance Cam", direction: "IN",  model: "", builtin: true },
    { key: "exit",     name: "Exit Cam",     direction: "OUT", model: "", builtin: true }
];
let extraCameras = <?php echo json_encode(array_map(function ($c) {
    return ['key' => $c['id'], 'name' => $c['name'], 'direction' => $c['direction'], 'model' => $c['model'], 'url' => $c['url'], 'builtin' => false];
}, $extraCameras), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
const MAX_EXTRA_CAMERAS = <?php echo (int)SECSYS_MAX_EXTRA_CAMERAS; ?>;

function camEscape(str) {
    return String(str === undefined || str === null ? "" : str)
        .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}

function findCamera(camKey) {
    return BUILTIN_CAMERAS.concat(extraCameras).find(c => c.key === camKey) || null;
}

function getCamUrl(camKey) {
    const cam = findCamera(camKey);
    if (cam && !cam.builtin) {
        return cam.url;
    }
    const defaultUrl = camKey === "entrance" ? DEFAULT_ENTRANCE_URL : DEFAULT_EXIT_URL;
    // A saved localhost / 127.0.0.1 address only works on the server PC: follow the
    // address this page was opened with, so the feed works from any device
    const url = localStorage.getItem("tapo_" + camKey + "_url") || defaultUrl;
    return String(url).replace(/^(https?:\/\/)(localhost|127\.0\.0\.1)(:5000)/i, "$1" + LOCAL_HOST + "$3");
}

function cameraTagHtml(cam) {
    /* Same direction badges as the Security Guard dashboard */
    let text, cls;
    if (cam.builtin && cam.key === "entrance") {
        text = "🟢 IN (Entrance)"; cls = "dir-in";
    } else if (cam.builtin && cam.key === "exit") {
        text = "🔴 OUT (Exit Only)"; cls = "dir-out";
    } else if (cam.direction === "IN") {
        text = "🟢 IN" + (cam.model ? " · " + cam.model : ""); cls = "dir-in";
    } else if (cam.direction === "OUT") {
        text = "🔴 OUT" + (cam.model ? " · " + cam.model : ""); cls = "dir-out";
    } else {
        text = "🔵 MONITOR" + (cam.model ? " · " + cam.model : ""); cls = "dir-monitor";
    }
    return '<span class="camera-tag dir-badge ' + cls + '">' + camEscape(text) + '</span>';
}

/* One template for every camera card -> all cards look identical */
function buildCameraCard(cam) {
    const k = cam.key; // restricted to [a-z0-9_] (validated server-side)
    const setupAction = cam.builtin ? "openTapoModal('" + k + "')" : "openCameraEditor('" + k + "')";
    const setupLabel = cam.builtin ? "Setup" : "Edit";
    const removeBtn = cam.builtin ? "" :
        '<button type="button" class="btn-cam-setup btn-cam-remove" onclick="removeCamera(\'' + k + '\')" title="Remove camera">🗑️ Remove</button>';

    return '' +
        '<div class="camera-card" id="card_' + k + '">' +
            '<div class="camera-card-header">' +
                '<div class="camera-title">' +
                    '<span class="camera-name" title="' + camEscape(cam.name) + '">' + camEscape(cam.name) + '</span>' +
                    cameraTagHtml(cam) +
                '</div>' +
                '<div class="camera-actions">' +
                    '<button type="button" class="btn-cam-setup" onclick="' + setupAction + '" title="Configure camera stream">⚙️ ' + setupLabel + '</button>' +
                    removeBtn +
                '</div>' +
            '</div>' +
            '<div class="camera-screen">' +
                '<img id="' + k + 'CamFeed" alt="' + camEscape(cam.name) + ' Stream"' +
                    ' onload="onCamStreamLoad(\'' + k + '\')" onerror="onCamStreamError(\'' + k + '\')">' +
                '<div class="camera-offline" id="' + k + 'CamOffline" style="display:none;">' +
                    '<div class="camera-offline-icon">📹</div>' +
                    '<div class="camera-offline-title">' + camEscape(cam.name) + ' Offline</div>' +
                    '<div class="camera-offline-sub">Camera stream or AI detection server is not connected on this address.</div>' +
                    '<div class="camera-offline-actions">' +
                        '<button type="button" class="btn-offline-retry" onclick="reloadCamStream(\'' + k + '\')">🔄 Reconnect</button>' +
                        '<button type="button" class="btn-offline-setup" onclick="' + setupAction + '">⚙️ ' + setupLabel + ' Camera</button>' +
                    '</div>' +
                '</div>' +
                '<div class="camera-status">' +
                    '<span class="status-dot offline" id="' + k + 'Dot"></span>' +
                    '<span id="' + k + 'StatusText">Connecting...</span>' +
                '</div>' +
            '</div>' +
        '</div>';
}

function renderCameraGrid() {
    const grid = document.getElementById("cameraGrid");
    if (!grid) return;

    const all = BUILTIN_CAMERAS.concat(extraCameras);
    let html = all.map(buildCameraCard).join("");

    // Only display an in-grid slot when camera count is odd, keeping the 2-column grid
    // perfectly aligned with the activity cards directly below. The header "+ Add Camera" button is always available.
    if (all.length % 2 !== 0 && extraCameras.length < MAX_EXTRA_CAMERAS) {
        html += '<div class="camera-card camera-card-add" onclick="openCameraEditor()" role="button" tabindex="0"' +
                ' onkeydown="if(event.key===\'Enter\'){openCameraEditor();}">' +
                    '<span class="camera-card-add-icon">➕</span>' +
                    '<span>Add Camera</span>' +
                '</div>';
    }
    grid.innerHTML = html;

    const countEl = document.getElementById("cameraCount");
    if (countEl) {
        countEl.textContent = all.length + (all.length === 1 ? " camera" : " cameras");
    }

    all.forEach(cam => {
        const img = document.getElementById(cam.key + "CamFeed");
        if (img) img.src = getCamUrl(cam.key);
    });
}

function initTapoCameras() {
    renderCameraGrid();

    checkDetectionServerStatus();
    setInterval(checkDetectionServerStatus, 5000);
}

/* ---------- Add / Edit / Remove admin cameras ---------- */

function showCameraEditorError(msg) {
    const el = document.getElementById("camEditorError");
    if (!el) return;
    el.textContent = msg || "";
    el.style.display = msg ? "block" : "none";
}

function openCameraEditor(camKey) {
    const cam = camKey ? findCamera(camKey) : null;
    if (!cam && extraCameras.length >= MAX_EXTRA_CAMERAS) {
        alert("Maximum of " + MAX_EXTRA_CAMERAS + " additional cameras reached.");
        return;
    }
    document.getElementById("cameraEditorTitle").textContent = cam ? "⚙️ Edit Camera" : "➕ Add Camera";
    document.getElementById("camEditorId").value = cam ? cam.key : "";
    document.getElementById("camEditorName").value = cam ? cam.name : "";
    document.getElementById("camEditorDirection").value = cam ? cam.direction : "MONITOR";
    document.getElementById("camEditorModel").value = cam ? cam.model : "";
    document.getElementById("camEditorUrl").value = cam ? cam.url : "";
    showCameraEditorError("");
    document.getElementById("cameraEditorModal").classList.add("active");
    document.getElementById("camEditorName").focus();
}

function closeCameraEditor() {
    document.getElementById("cameraEditorModal").classList.remove("active");
}

async function postCameraAction(action, payload) {
    const res = await fetch("superadmin_dashboard.php?action=" + action, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
        body: JSON.stringify(payload),
        cache: "no-store"
    });
    let data = null;
    try { data = await res.json(); } catch (e) { /* non-JSON response */ }
    if (!data || !data.success) {
        throw new Error((data && data.message) || "Request failed (HTTP " + res.status + ").");
    }
    return data;
}

function applyServerCameras(list) {
    extraCameras = (list || []).map(c => ({
        key: c.id, name: c.name, direction: c.direction, model: c.model, url: c.url, builtin: false
    }));
    renderCameraGrid();
}

async function saveCameraEditor() {
    const id = document.getElementById("camEditorId").value;
    const payload = {
        id: id,
        name: document.getElementById("camEditorName").value.trim(),
        direction: document.getElementById("camEditorDirection").value,
        model: document.getElementById("camEditorModel").value.trim(),
        url: document.getElementById("camEditorUrl").value.trim()
    };

    if (!payload.name) { showCameraEditorError("Please enter a camera name."); return; }
    if (!payload.url) { showCameraEditorError("Please enter the stream URL."); return; }
    if (/^rtsp:/i.test(payload.url)) {
        showCameraEditorError("Browsers cannot play rtsp:// links. Use the http:// MJPEG feed from a detection/stream server.");
        return;
    }

    const btn = document.getElementById("camEditorSaveBtn");
    btn.disabled = true;
    try {
        const data = await postCameraAction(id ? "camera_update" : "camera_add", payload);
        applyServerCameras(data.cameras);
        closeCameraEditor();
    } catch (e) {
        showCameraEditorError(e.message);
    } finally {
        btn.disabled = false;
    }
}

async function removeCamera(camKey) {
    const cam = findCamera(camKey);
    if (!cam || cam.builtin) return;
    if (!confirm('Remove camera "' + cam.name + '" from the dashboard?')) return;
    try {
        const data = await postCameraAction("camera_delete", { id: camKey, name: cam.name });
        applyServerCameras(data.cameras);
    } catch (e) {
        alert("Could not remove camera: " + e.message);
    }
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

    const savedUrl = getCamUrl(camKey);
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
                if (statusEnt) {
                    const isConn = data.cameras.entrance.connected;
                    statusEnt.innerText = isConn ? "Connected" : "Offline";
                    statusEnt.style.background = isConn ? "#dcfce7" : "#fee2e2";
                    statusEnt.style.color = isConn ? "#15803d" : "#dc2626";
                }
            }
            if (data.cameras.exit && inputExitSrc) {
                inputExitSrc.value = data.cameras.exit.source || "";
                if (statusExit) {
                    const isConn = data.cameras.exit.connected;
                    statusExit.innerText = isConn ? "Connected" : "Offline";
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

/* Preset camera address: keeps the username/password already typed in the
   field (credentials are never stored in the code) and only sets the camera IP. */
function presetRtspUrl(currentValue, ip) {
    const m = String(currentValue || "").match(/^rtsp:\/\/([^@\s\/]+)@/i);
    const login = m ? m[1] : "USERNAME:PASSWORD";
    return "rtsp://" + login + "@" + ip + ":554/stream2";
}

function applyPresetDualTapo() {
    const entSrc = document.getElementById("cfgEntranceSource");
    const exitSrc = document.getElementById("cfgExitSource");
    const entUrl = document.getElementById("cfgEntranceUrl");
    const exitUrl = document.getElementById("cfgExitUrl");

    if (entSrc) entSrc.value = presetRtspUrl(entSrc.value, "192.168.1.18");
    if (exitSrc) exitSrc.value = presetRtspUrl(exitSrc.value, "192.168.1.19");
    if (entUrl) entUrl.value = DEFAULT_ENTRANCE_URL;
    if (exitUrl) exitUrl.value = DEFAULT_EXIT_URL;
}

function applyPresetTapoAndWebcam() {
    const entSrc = document.getElementById("cfgEntranceSource");
    const exitSrc = document.getElementById("cfgExitSource");
    const entUrl = document.getElementById("cfgEntranceUrl");
    const exitUrl = document.getElementById("cfgExitUrl");

    if (entSrc) entSrc.value = presetRtspUrl(entSrc.value, "192.168.1.18");
    if (exitSrc) exitSrc.value = presetRtspUrl(exitSrc.value, "192.168.1.19");
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

    // Push hardware camera sources to Python detection server live
    const entSrcVal = inputEntranceSrc ? inputEntranceSrc.value.trim() : "";
    const exitSrcVal = inputExitSrc ? inputExitSrc.value.trim() : "";

    try {
        await fetch(API_BASE_URL + "/api/cameras", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                entrance_source: entSrcVal,
                exit_source: exitSrcVal
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
    const camEditor = document.getElementById("cameraEditorModal");
    if (camEditor && e.target === camEditor) {
        closeCameraEditor();
    }
});

document.addEventListener("DOMContentLoaded", initTapoCameras);

/* =========================================================
   ADMIN DASHBOARD LIVE STATS & CHART AUTO-REFRESH
   Updates all counters & vehicleChart in real-time without
   reloading the page or interrupting streams.
========================================================= */
let isPollingAdminStats = false;

function updateStatElement(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    const strVal = String(value !== undefined && value !== null ? value : 0);
    if (el.textContent.trim() !== strVal) {
        el.textContent = strVal;
        el.classList.remove("stat-number-updated");
        void el.offsetWidth; // Force CSS animation reflow
        el.classList.add("stat-number-updated");
    }
}

async function pollAdminDashboardStats() {
    if (isPollingAdminStats) return;
    isPollingAdminStats = true;

    try {
        const res = await fetch("superadmin_dashboard.php?action=dashboard_stats", {
            cache: "no-store",
            credentials: "same-origin"
        });
        if (!res.ok) return;

        const data = await res.json();
        if (!data || !data.success || !data.stats) return;

        // 1. Registered vehicle statistics
        updateStatElement("statRegMotoIn", data.stats.reg_moto_in);
        updateStatElement("statRegMotoOut", data.stats.reg_moto_out);
        updateStatElement("statRegCarIn", data.stats.reg_car_in);
        updateStatElement("statRegCarOut", data.stats.reg_car_out);

        // 2. Visitor vehicle statistics
        updateStatElement("statVisMotoIn", data.stats.vis_moto_in);
        updateStatElement("statVisMotoOut", data.stats.vis_moto_out);
        updateStatElement("statVisCarIn", data.stats.vis_car_in);
        updateStatElement("statVisCarOut", data.stats.vis_car_out);

        // 3. Totals
        updateStatElement("statTotalRegToday", data.stats.total_reg_today);
        updateStatElement("statTotalVisToday", data.stats.total_vis_today);

        // 4. Hourly Activity Chart
        if (typeof vehicleChart !== "undefined" && vehicleChart && data.chart && Array.isArray(data.chart.series)) {
            let chartChanged = false;
            data.chart.series.forEach((series, idx) => {
                if (vehicleChart.data.datasets[idx]) {
                    const oldStr = JSON.stringify(vehicleChart.data.datasets[idx].data);
                    const newStr = JSON.stringify(series);
                    if (oldStr !== newStr) {
                        vehicleChart.data.datasets[idx].data = series;
                        chartChanged = true;
                    }
                }
            });
            if (chartChanged) {
                vehicleChart.update("none");
            }
        }
    } catch (e) {
        // Silently handle temporary network glitches
    } finally {
        isPollingAdminStats = false;
    }
}

// Poll every 2.5 seconds for instant number updates
setInterval(pollAdminDashboardStats, 2500);
document.addEventListener("visibilitychange", function () {
    if (!document.hidden) {
        pollAdminDashboardStats();
    }
});

</script>

<!-- Shared Per-Duration Visitor Reminder & Overstay Alarm Engine -->
<script>window.SECSYS_ALARM_ROLE = "Administrator";</script>
<script src="assets/js/visitor_duration_alarm.js"></script>

<!-- Live auto-refresh (no full page reload) -->
<script>window.AUTO_REFRESH_SECONDS = 5;</script>
<script src="assets/js/dual_clock.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/dual_clock.js'); ?>"></script>
<script src="assets/js/auto_refresh.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/auto_refresh.js'); ?>"></script>


</body>

</html>
