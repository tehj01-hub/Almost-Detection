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
secsysEnsureSchema($conn);
$systemName = secsysGetSystemName($conn);
$systemIcon = secsysGetSystemIcon($conn);


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
}

.header h1 {
    font-size: 25px;

    font-weight: 500;

    line-height: 1.2;
}

.header p {
    font-size: 14px;

    color: #666;

    margin-top: 5px;
}


/* =========================================================
   VEHICLE CARDS
========================================================= */

.vehicle-cards {
    display: grid;

    grid-template-columns:
        repeat(2, minmax(320px, 1fr));

    gap: 14px;

    width: 100%;

    max-width: 1050px;
}


/* =========================================================
   VEHICLE CARD
========================================================= */

.vehicle-card {
    background: #cce8fc;

    border-radius: 19px;

    padding: 14px;

    box-shadow:
        0 2px 5px rgba(0, 0, 0, 0.14);

    min-width: 0;
}

.vehicle-card h2 {
    font-size: 20px;

    font-weight: 500;

    margin-bottom: 10px;
}


/* =========================================================
   STATISTICS GRID
========================================================= */

.stats {
    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 8px;
}

.stat {
    background: white;

    min-height: 86px;

    border-radius: 15px;

    display: flex;

    flex-direction: column;

    align-items: center;

    justify-content: center;

    box-shadow:
        0 1px 4px rgba(0, 0, 0, 0.13);

    transition:
        transform 0.15s ease,
        box-shadow 0.15s ease;
}

.stat:hover {
    transform: translateY(-1px);

    box-shadow:
        0 3px 7px rgba(0, 0, 0, 0.15);
}

.stat-label {
    font-size: 11px;

    line-height: 14px;

    text-align: center;

    color: #555;
}

.stat-value {
    font-size: 30px;

    line-height: 32px;

    color: #444;

    font-weight: 400;

    margin-top: 2px;
}


/* =========================================================
   TOTAL CARDS
========================================================= */

.total-cards {
    display: grid;

    grid-template-columns:
        repeat(2, minmax(300px, 1fr));

    gap: 14px;

    width: 100%;

    max-width: 1050px;

    margin-top: 12px;
}

.total-card {
    min-height: 65px;

    background: white;

    border: 1px solid #dedede;

    border-radius: 18px;

    padding: 10px 20px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.10);
}

.total-label {
    font-size: 14px;

    line-height: 14px;

    color: #555;

    max-width: 250px;
}

.total-number {
    font-size: 29px;

    font-weight: 400;

    color: #444;

    margin-left: 15px;
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

    margin-top: 18px;

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

    margin: 20px 0 0;

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

    .vehicle-cards,
    .total-cards,
    .chart-container {
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

    .vehicle-cards,
    .total-cards {
        max-width: 850px;
    }

    .vehicle-card h2 {
        font-size: 18px;
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

    .vehicle-cards {
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
        padding: 20px 14px 30px;
    }

    .header h1 {
        font-size: 22px;
    }

    .vehicle-cards {
        grid-template-columns: 1fr;
    }

    .total-cards {
        grid-template-columns: 1fr;
    }

    .vehicle-card h2 {
        font-size: 19px;
    }

    .stat {
        min-height: 82px;
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

    .sidebar,
    .camera-grid,
    .tapo-modal-overlay {
        display: none !important;
    }

    .main {
        margin-left: 0;

        width: 100%;

        padding: 20px;
    }

    .print-button {
        display: none;
    }

    .vehicle-card,
    .total-card,
    .chart-container {
        box-shadow: none;
    }
}


/* =========================================================
   CAMERA GRID (2-CAMERA TAPO C500 SYSTEM)
========================================================= */

.camera-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(320px, 1fr));
    gap: 14px;
    width: 100%;
    max-width: 1050px;
    margin-bottom: 16px;
}

.camera-card {
    background: #ffffff;
    border: 1px solid #dedede;
    border-radius: 19px;
    padding: 13px 15px 15px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.14);
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


@media (max-width: 600px) {
    .camera-grid {
        grid-template-columns: 1fr;
    }
}

</style>

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

            <div class="logo-subtitle">
                Administrator
            </div>

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

        <h1>
            Dashboard
        </h1>

        <p>
            <?php echo htmlspecialchars($displayDate); ?>
        </p>

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
                    <span class="camera-tag">Tapo C500</span>
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
                    <span class="camera-tag">Exit (Tapo C500)</span>
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
         VEHICLE CARDS
    ================================================== -->

    <div class="vehicle-cards" id="liveVehicleStats" data-live>


        <!-- =============================================
             REGISTERED VEHICLES
        ============================================== -->

        <section class="vehicle-card">

            <h2>
                Registered Vehicles
            </h2>


            <div class="stats">


                <!-- Motorcycle IN -->

                <div class="stat">

                    <div class="stat-label">
                        🏍 Motorcycle In<br>
                        Today
                    </div>

                    <div class="stat-value">

                        <?php
                        echo $registeredMotorcycleIn;
                        ?>

                    </div>

                </div>


                <!-- Motorcycle OUT -->

                <div class="stat">

                    <div class="stat-label">
                        🏍 Motorcycle Out<br>
                        Today
                    </div>

                    <div class="stat-value">

                        <?php
                        echo $registeredMotorcycleOut;
                        ?>

                    </div>

                </div>


                <!-- Car IN -->

                <div class="stat">

                    <div class="stat-label">
                        🚗 Car In<br>
                        Today
                    </div>

                    <div class="stat-value">

                        <?php
                        echo $registeredCarIn;
                        ?>

                    </div>

                </div>


                <!-- Car OUT -->

                <div class="stat">

                    <div class="stat-label">
                        🚗 Car Out<br>
                        Today
                    </div>

                    <div class="stat-value">

                        <?php
                        echo $registeredCarOut;
                        ?>

                    </div>

                </div>


            </div>

        </section>



        <!-- =============================================
             VISITOR VEHICLES
        ============================================== -->

        <section class="vehicle-card">

            <h2>
                Visitor Vehicles
            </h2>


            <div class="stats">


                <!-- Motorcycle IN -->

                <div class="stat">

                    <div class="stat-label">
                        🏍 Motorcycle In<br>
                        Today
                    </div>

                    <div class="stat-value">

                        <?php
                        echo $visitorMotorcycleIn;
                        ?>

                    </div>

                </div>


                <!-- Motorcycle OUT -->

                <div class="stat">

                    <div class="stat-label">
                        🏍 Motorcycle Out<br>
                        Today
                    </div>

                    <div class="stat-value">

                        <?php
                        echo $visitorMotorcycleOut;
                        ?>

                    </div>

                </div>


                <!-- Car IN -->

                <div class="stat">

                    <div class="stat-label">
                        🚗 Car In<br>
                        Today
                    </div>

                    <div class="stat-value">

                        <?php
                        echo $visitorCarIn;
                        ?>

                    </div>

                </div>


                <!-- Car OUT -->

                <div class="stat">

                    <div class="stat-label">
                        🚗 Car Out<br>
                        Today
                    </div>

                    <div class="stat-value">

                        <?php
                        echo $visitorCarOut;
                        ?>

                    </div>

                </div>


            </div>

        </section>


    </div>



    <!-- =================================================
         TOTALS
    ================================================== -->

    <div class="total-cards" id="liveTotals" data-live>


        <div class="total-card">

            <div class="total-label">

                Total Registered Car and
                Motorcycle In Today

            </div>

            <div class="total-number">

                <?php
                echo $totalRegisteredToday;
                ?>

            </div>

        </div>


        <div class="total-card">

            <div class="total-label">

                Total Visitors Car and
                Motorcycle In Today

            </div>

            <div class="total-number">

                <?php
                echo $totalVisitorsToday;
                ?>

            </div>

        </div>


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
                        <span>Entrance Camera (192.168.1.18)</span>
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
                        <span>Exit Camera (192.168.1.19)</span>
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

function printDashboard() {

    window.print();

}


/* =========================================================
   DUAL CAMERA (ENTRANCE & EXIT) CONTROLLER
========================================================= */

const DEFAULT_ENTRANCE_URL = "http://127.0.0.1:5000/video/entrance";
const DEFAULT_EXIT_URL = "http://127.0.0.1:5000/video/exit";
const API_BASE_URL = "http://127.0.0.1:5000";

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
});

document.addEventListener("DOMContentLoaded", initTapoCameras);

</script>

<!-- Shared Per-Duration Visitor Reminder & Overstay Alarm Engine -->
<script>window.SECSYS_ALARM_ROLE = "Administrator";</script>
<script src="assets/js/visitor_duration_alarm.js"></script>

<!-- Live auto-refresh (no full page reload) -->
<script>window.AUTO_REFRESH_SECONDS = 5;</script>
<script src="assets/js/auto_refresh.js"></script>


</body>

</html>
