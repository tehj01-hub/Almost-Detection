<?php

require_once __DIR__ . '/session_helper.php';
require_once __DIR__ . '/system_config.php';
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
   HELPER
========================================================= */

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


function formatTime($time)
{
    if (!$time) {
        return "";
    }

    return date("g:i A", strtotime($time));
}


/* =========================================================
   MAKE SURE TABLES EXIST
========================================================= */

$conn->query("
    CREATE TABLE IF NOT EXISTS security_guard (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        guard_id VARCHAR(30) NOT NULL UNIQUE,
        full_name VARCHAR(150) NOT NULL,
        contact_number VARCHAR(30) DEFAULT '',
        address VARCHAR(255) DEFAULT '',
        date_hired DATE NOT NULL,
        shift_id INT DEFAULT NULL,
        assignment VARCHAR(100) DEFAULT '',
        status ENUM('Active','Inactive','Archived')
            NOT NULL DEFAULT 'Active',
        login_status ENUM('Active','Inactive')
            NOT NULL DEFAULT 'Inactive',
        date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        archived_at DATETIME DEFAULT NULL
    )
");


/* =========================================================
   MAKE SURE LOGIN STATUS COLUMN EXISTS
========================================================= */

$loginStatusColumn = $conn->query("
    SHOW COLUMNS FROM security_guard LIKE 'login_status'
");

if ($loginStatusColumn && $loginStatusColumn->num_rows === 0) {

    $conn->query("
        ALTER TABLE security_guard
        ADD COLUMN login_status ENUM('Active','Inactive')
        NOT NULL DEFAULT 'Inactive'
        AFTER status
    ");

}


/* =========================================================
   MAKE SURE SHIFT TABLE EXISTS
========================================================= */

$conn->query("
    CREATE TABLE IF NOT EXISTS guard_shifts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shift_name VARCHAR(100) NOT NULL UNIQUE,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        status ENUM('Active','Inactive')
            NOT NULL DEFAULT 'Active',
        date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");


/* =========================================================
   INSERT DEFAULT SHIFTS
========================================================= */

$checkMorning = $conn->query("
    SELECT id
    FROM guard_shifts
    WHERE shift_name = 'Morning Shift'
    LIMIT 1
");

if ($checkMorning && $checkMorning->num_rows === 0) {

    $conn->query("
        INSERT INTO guard_shifts
        (
            shift_name,
            start_time,
            end_time,
            status
        )
        VALUES
        (
            'Morning Shift',
            '06:00:00',
            '18:00:00',
            'Active'
        )
    ");

}


$checkNight = $conn->query("
    SELECT id
    FROM guard_shifts
    WHERE shift_name = 'Night Shift'
    LIMIT 1
");

if ($checkNight && $checkNight->num_rows === 0) {

    $conn->query("
        INSERT INTO guard_shifts
        (
            shift_name,
            start_time,
            end_time,
            status
        )
        VALUES
        (
            'Night Shift',
            '18:00:00',
            '06:00:00',
            'Active'
        )
    ");

}


/* =========================================================
   MAKE SURE CONTACT TABLE EXISTS
========================================================= */

$conn->query("
    CREATE TABLE IF NOT EXISTS security_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contact_name VARCHAR(150) NOT NULL,
        position VARCHAR(100) DEFAULT '',
        contact_number VARCHAR(50) DEFAULT '',
        email VARCHAR(150) DEFAULT '',
        address VARCHAR(255) DEFAULT '',
        status ENUM('Active','Inactive')
            NOT NULL DEFAULT 'Active',
        date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");


/* =========================================================
   GET SHIFTS
========================================================= */

$shifts = [];

$shiftResult = $conn->query("
    SELECT
        id,
        shift_name,
        start_time,
        end_time,
        status
    FROM guard_shifts
    WHERE status = 'Active'
    ORDER BY
        CASE
            WHEN shift_name = 'Morning Shift' THEN 1
            WHEN shift_name = 'Night Shift' THEN 2
            ELSE 3
        END,
        id
");

if ($shiftResult) {

    while ($row = $shiftResult->fetch_assoc()) {

        $shifts[] = $row;

    }

}


/* =========================================================
   DETERMINE CURRENT SHIFT
========================================================= */

$currentShift = null;

$currentTime = date("H:i:s");

foreach ($shifts as $shift) {

    $start = $shift['start_time'];

    $end = $shift['end_time'];

    if ($start < $end) {

        if (
            $currentTime >= $start &&
            $currentTime < $end
        ) {

            $currentShift = $shift;
            break;

        }

    } else {

        if (
            $currentTime >= $start ||
            $currentTime < $end
        ) {

            $currentShift = $shift;
            break;

        }

    }

}


/* =========================================================
   AUTO-OFFLINE GUARD STATUS REFRESH
   Guards without a heartbeat in the last 30s are marked Inactive.
========================================================= */

$conn->query("
    UPDATE security_guard
    SET login_status = 'Inactive'
    WHERE status = 'Active'
    AND login_status = 'Active'
    AND last_seen IS NOT NULL
    AND last_seen < (NOW() - INTERVAL 30 SECOND)
");


/* =========================================================
   GET GUARDS
========================================================= */

$guards = [];

$guardResult = $conn->query("
    SELECT
        sg.*,
        gs.shift_name,
        gs.start_time,
        gs.end_time
    FROM security_guard sg
    LEFT JOIN guard_shifts gs
        ON sg.shift_id = gs.id
    WHERE sg.status = 'Active'
    ORDER BY
        gs.id ASC,
        sg.full_name ASC
");

if ($guardResult) {

    while ($row = $guardResult->fetch_assoc()) {

        $guards[] = $row;

    }

}


/* =========================================================
   STATISTICS
========================================================= */

$totalGuards = count($guards);

$totalMorning = 0;
$totalNight = 0;
$totalAssigned = 0;
$totalUnassigned = 0;


foreach ($guards as $guard) {

    if (!empty($guard['assignment'])) {

        $totalAssigned++;

    } else {

        $totalUnassigned++;

    }


    if (
        strtolower(
            trim($guard['shift_name'] ?? '')
        ) === 'morning shift'
    ) {

        $totalMorning++;

    } elseif (
        strtolower(
            trim($guard['shift_name'] ?? '')
        ) === 'night shift'
    ) {

        $totalNight++;

    }

}


/* =========================================================
   CURRENT SHIFT GUARDS
========================================================= */

$currentShiftGuards = 0;

if ($currentShift) {

    foreach ($guards as $guard) {

        if (
            isset($guard['shift_id']) &&
            (int)$guard['shift_id'] ===
            (int)$currentShift['id']
        ) {

            $currentShiftGuards++;

        }

    }

}


/* =========================================================
   SECURITY CONTACTS
========================================================= */

$contacts = [];

$contactResult = $conn->query("
    SELECT
        *
    FROM security_contacts
    WHERE status = 'Active'
    ORDER BY contact_name ASC
");

if ($contactResult) {

    while ($row = $contactResult->fetch_assoc()) {

        $contacts[] = $row;

    }

}

/* =========================================================
   AJAX: GUARD STATUS POLLING
========================================================= */

if (($_GET['ajax'] ?? '') === 'guard_status') {

    if (isset($conn) && !$conn->connect_error) {

        /* Auto-mark guards as Inactive if no heartbeat for 30 seconds */
        $conn->query("
            UPDATE security_guard
            SET login_status = 'Inactive'
            WHERE status = 'Active'
            AND login_status = 'Active'
            AND last_seen IS NOT NULL
            AND last_seen < (NOW() - INTERVAL 30 SECOND)
        ");

        $statusResult = $conn->query("
            SELECT guard_id, login_status
            FROM security_guard
            WHERE status = 'Active'
        ");

        $statuses = [];

        if ($statusResult) {

            while ($row = $statusResult->fetch_assoc()) {

                $statuses[$row['guard_id']] = $row['login_status'];

            }

        }

        header("Content-Type: application/json; charset=utf-8");

        echo json_encode($statuses);

        exit();

    }

}



?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Security Information | <?php echo htmlspecialchars($systemName); ?></title>


<link rel="preconnect"
      href="https://fonts.googleapis.com">

<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
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
    font-family: Arial, Helvetica, sans-serif;

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
   MAIN
========================================================= */

.main {

    margin-left: 212px;

    min-height: 100vh;

    padding: 32px 38px 40px;

    width: calc(100% - 212px);

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
   CURRENT SHIFT
========================================================= */

.shift-banner {

    background: #ffffff;

    border-radius: 14px;

    padding: 22px 25px;

    border: 1px solid #e8edf5;

    box-shadow: 0 3px 12px rgba(0,0,0,0.03);

    margin-bottom: 22px;

    display: flex;

    justify-content: space-between;

    align-items: center;

}


.shift-banner h2 {

    font-size: 17px;

    font-weight: 600;

    margin-bottom: 5px;

}


.shift-banner p {

    font-size: 12px;

    color: #777;

}


.shift-count {

    min-width: 90px;

    text-align: center;

}


.shift-count .number {

    font-size: 27px;

    font-weight: 600;

    color: #2f80ed;

}


.shift-count .label {

    font-size: 11px;

    color: #777;

}


/* =========================================================
   CARDS
========================================================= */

.card {

    background: #ffffff;

    border-radius: 14px;

    border: 1px solid #e8edf5;

    box-shadow: 0 3px 12px rgba(0,0,0,0.03);

    overflow: hidden;

    margin-bottom: 22px;

}


.card-header {

    padding: 19px 22px;

    border-bottom: 1px solid #edf0f5;

    display: flex;

    justify-content: space-between;

    align-items: center;

}


.card-header h2 {

    font-size: 16px;

    font-weight: 600;

}


.card-header p {

    color: #888;

    font-size: 11px;

}


/* =========================================================
   STATISTICS
========================================================= */

.stats {

    display: grid;

    grid-template-columns: repeat(4, 1fr);

    gap: 16px;

    margin-bottom: 22px;

}


.stat {

    background: #ffffff;

    border: 1px solid #e8edf5;

    border-radius: 13px;

    padding: 19px 20px;

    box-shadow: 0 3px 12px rgba(0,0,0,0.03);

}


.stat-label {

    color: #777;

    font-size: 12px;

    margin-bottom: 8px;

}


.stat-value {

    font-size: 25px;

    font-weight: 600;

    color: #2f80ed;

}


/* =========================================================
   TABLE
========================================================= */

.table-wrapper {

    overflow-x: auto;

}


table {

    width: 100%;

    border-collapse: collapse;

}


thead th {

    background: #f8faff;

    color: #555;

    font-size: 11px;

    font-weight: 600;

    text-align: left;

    padding: 14px 18px;

    border-bottom: 1px solid #e9edf3;

    white-space: nowrap;

}


tbody td {

    padding: 15px 18px;

    font-size: 12px;

    color: #444;

    border-bottom: 1px solid #f0f2f6;

    vertical-align: middle;

}


tbody tr:hover {

    background: #fbfcff;

}


.guard-name {

    font-weight: 600;

    color: #222;

}


.guard-id {

    font-size: 10px;

    color: #999;

    margin-top: 2px;

}


/* =========================================================
   BADGES
========================================================= */

.badge {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-height: 25px;

    padding: 4px 10px;

    border-radius: 20px;

    font-size: 10px;

    font-weight: 600;

    white-space: nowrap;

}


.badge-blue {

    background: #eaf3ff;

    color: #2f80ed;

}


.badge-green {

    background: #eaf8f0;

    color: #20a35a;

}


.badge-gray {

    background: #f0f1f3;

    color: #777;

}


.badge-orange {

    background: #fff4e5;

    color: #d98600;

}


/* =========================================================
   CONTACTS
========================================================= */

.contact-grid {

    display: grid;

    grid-template-columns: repeat(3, 1fr);

    gap: 16px;

    padding: 20px;

}


.contact {

    border: 1px solid #edf0f5;

    border-radius: 11px;

    padding: 17px;

    background: #ffffff;

}


.contact-name {

    font-weight: 600;

    font-size: 13px;

    margin-bottom: 3px;

}


.contact-position {

    color: #2f80ed;

    font-size: 10px;

    margin-bottom: 10px;

}


.contact-info {

    font-size: 11px;

    color: #666;

    line-height: 1.8;

}


/* =========================================================
   SHIFT INFORMATION
========================================================= */

.shift-grid {

    display: grid;

    grid-template-columns: repeat(2, 1fr);

    gap: 16px;

    padding: 20px;

}


.shift-card {

    border: 1px solid #edf0f5;

    border-radius: 11px;

    padding: 18px;

}


.shift-card h3 {

    font-size: 13px;

    margin-bottom: 8px;

}


.shift-card p {

    font-size: 11px;

    color: #777;

    line-height: 1.8;

}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1000px) {

    .stats {

        grid-template-columns: repeat(2, 1fr);

    }

    .contact-grid {

        grid-template-columns: repeat(2, 1fr);

    }

}


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

    .stats {

        grid-template-columns: 1fr;

    }

    .contact-grid,
    .shift-grid {

        grid-template-columns: 1fr;

    }

    .shift-banner {

        flex-direction: column;

        align-items: flex-start;

    }

}


@media (max-width: 600px) {

    .main {

        padding: 20px 14px 30px;

    }

}


@media print {

    body {

        background: white;

    }

    .sidebar {

        display: none !important;

    }

    .main {

        margin-left: 0 !important;

        width: 100% !important;

        padding: 20px !important;

    }

}

        gap: 15px;

    }

}

</style>

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

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

    <a href="superadmin_dashboard.php">
        <span class="nav-icon">🖥️</span>
        <span>Dashboard</span>
    </a>

    <a href="logs.php">
        <span class="nav-icon">📋</span>
        <span>Logs</span>
    </a>

    <a href="registered.php">
        <span class="nav-icon">🚗</span>
        <span>Registered</span>
    </a>

    <a href="visitor.php">
        <span class="nav-icon">👥</span>
        <span>Visitor</span>
    </a>

    <a href="security_guard.php">
        <span class="nav-icon">🛡️</span>
        <span>Security Guard</span>
    </a>

    <a href="security_information.php"
       class="active">
        <span class="nav-icon">📑</span>
        <span>Security Information</span>
    </a>

    <a href="report.php">
        <span class="nav-icon">📈</span>
        <span>Report</span>
    </a>

    <a href="profile.php">
        <span class="nav-icon">⚙️</span>
        <span>Profile</span>
    </a>

</nav>


<a href="logout.php?role=Administrator"
   class="logout">

    <span class="nav-icon">🚪</span>
    <span>Logout</span>

</a>

</aside>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">


    <div class="header">

        <h1>
            Security Information
        </h1>

        <p>
            Security personnel overview, current shifts,
            assignments and security contacts.
        </p>

    </div>


    <!-- =====================================================
         CURRENT SHIFT
    ====================================================== -->

    <section class="shift-banner">

        <div>

            <h2>

                Current Shift:

                <?php

                if ($currentShift) {

                    echo e(
                        $currentShift['shift_name']
                    );

                } else {

                    echo "No Active Shift";

                }

                ?>

            </h2>


            <p>

                <?php

                if ($currentShift) {

                    echo e(
                        formatTime(
                            $currentShift['start_time']
                        )
                    );

                    echo " - ";

                    echo e(
                        formatTime(
                            $currentShift['end_time']
                        )
                    );

                } else {

                    echo "No shift schedule is currently active.";

                }

                ?>

            </p>

        </div>


        <div class="shift-count">

            <div class="number">
                <?php echo $currentShiftGuards; ?>
            </div>

            <div class="label">
                Guards on Duty
            </div>

        </div>

    </section>


    <!-- =====================================================
         STATISTICS
    ====================================================== -->

    <div class="stats">


        <div class="stat">

            <div class="stat-label">
                Total Active Guards
            </div>

            <div class="stat-value">
                <?php echo $totalGuards; ?>
            </div>

        </div>


        <div class="stat">

            <div class="stat-label">
                Morning Shift
            </div>

            <div class="stat-value">
                <?php echo $totalMorning; ?>
            </div>

        </div>


        <div class="stat">

            <div class="stat-label">
                Night Shift
            </div>

            <div class="stat-value">
                <?php echo $totalNight; ?>
            </div>

        </div>


        <div class="stat">

            <div class="stat-label">
                Assigned Guards
            </div>

            <div class="stat-value">
                <?php echo $totalAssigned; ?>
            </div>

        </div>

    </div>


    <!-- =====================================================
         SECURITY GUARDS
    ====================================================== -->

    <section class="card">


        <div class="card-header">

            <div>

                <h2>
                    Security Guards
                </h2>

                <p>
                    Current security personnel information
                </p>

            </div>

        </div>


        <div class="table-wrapper">

            <table id="guard-table">

                <thead>

                    <tr>

                        <th>
                            Guard
                        </th>

                        <th>
                            Shift
                        </th>

                        <th>
                            Schedule
                        </th>

                        <th>
                            Assignment
                        </th>

                        <th>
                            Status
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if (empty($guards)): ?>

                    <tr>

                        <td colspan="5"
                            style="text-align:center; padding:30px; color:#888;">

                            No active security guards found.

                        </td>

                    </tr>

                <?php else: ?>


                    <?php foreach ($guards as $guard): ?>

                        <tr>


                            <!-- GUARD -->

                            <td>

                                <div class="guard-name">

                                    <?php

                                    echo e(
                                        $guard['full_name']
                                    );

                                    ?>

                                </div>


                                <div class="guard-id">

                                    <?php

                                    echo e(
                                        $guard['guard_id']
                                    );

                                    ?>

                                </div>

                            </td>


                            <!-- SHIFT -->

                            <td>

                                <span class="badge badge-blue">

                                    <?php

                                    echo e(

                                        $guard['shift_name']

                                        ?? 'Not Assigned'

                                    );

                                    ?>

                                </span>

                            </td>


                            <!-- SCHEDULE -->

                            <td>

                                <?php if (!empty($guard['start_time'])): ?>

                                    <?php

                                    echo e(

                                        formatTime(

                                            $guard['start_time']

                                        )

                                    );

                                    ?>

                                    -

                                    <?php

                                    echo e(

                                        formatTime(

                                            $guard['end_time']

                                        )

                                    );

                                    ?>

                                <?php else: ?>

                                    <span style="color:#999;">
                                        No Schedule
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- ASSIGNMENT -->

                            <td>

                                <?php if (!empty($guard['assignment'])): ?>

                                    <?php

                                    echo e(
                                        $guard['assignment']
                                    );

                                    ?>

                                <?php else: ?>

                                    <span class="badge badge-gray">

                                        Not Assigned

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- LOGIN STATUS -->

                            <td>

                                <?php if (
                                    isset($guard['login_status']) &&
                                    $guard['login_status'] === 'Active'
                                ): ?>

                                    <span class="badge badge-green">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="badge badge-gray">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>


                        </tr>

                    <?php endforeach; ?>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </section>


    <!-- =====================================================
         SECURITY CONTACTS
    ====================================================== -->

    <section class="card">


        <div class="card-header">

            <div>

                <h2>
                    Security Contacts
                </h2>

                <p>
                    Important security personnel contacts
                </p>

            </div>

        </div>


        <div class="contact-grid">


            <?php if (empty($contacts)): ?>

                <div class="contact">

                    <div class="contact-name">
                        No Contacts Available
                    </div>

                    <div class="contact-info">
                        No active security contacts have been added.
                    </div>

                </div>

            <?php else: ?>


                <?php foreach ($contacts as $contact): ?>

                    <div class="contact">


                        <div class="contact-name">

                            <?php

                            echo e(
                                $contact['contact_name']
                            );

                            ?>

                        </div>


                        <?php if (!empty($contact['position'])): ?>

                            <div class="contact-position">

                                <?php

                                echo e(
                                    $contact['position']
                                );

                                ?>

                            </div>

                        <?php endif; ?>


                        <div class="contact-info">


                            <?php if (!empty($contact['contact_number'])): ?>

                                <div>

                                    <strong>
                                        Contact:
                                    </strong>

                                    <?php

                                    echo e(
                                        $contact['contact_number']
                                    );

                                    ?>

                                </div>

                            <?php endif; ?>


                            <?php if (!empty($contact['email'])): ?>

                                <div>

                                    <strong>
                                        Email:
                                    </strong>

                                    <?php

                                    echo e(
                                        $contact['email']
                                    );

                                    ?>

                                </div>

                            <?php endif; ?>


                            <?php if (!empty($contact['address'])): ?>

                                <div>

                                    <strong>
                                        Address:
                                    </strong>

                                    <?php

                                    echo e(
                                        $contact['address']
                                    );

                                    ?>

                                </div>

                            <?php endif; ?>


                        </div>


                    </div>

                <?php endforeach; ?>


            <?php endif; ?>


        </div>

    </section>


</main>



<script>
/* =========================================================
   LIVE GUARD STATUS POLLING
   Updates the login status badges automatically every
   5 seconds without requiring a page refresh.
========================================================= */

function updateGuardStatus() {

    /* Make sure the URL is correct (same page + ajax flag). */
    const url = window.location.pathname + "?ajax=guard_status";

    fetch(url)
        .then(function (response) {
            return response.json();
        })
        .then(function (statuses) {

            if (!statuses) {
                return;
            }

            /* Each table row for a guard holds the guard_id
               inside its .guard-id element. */
            const rows = document.querySelectorAll(
                "#guard-table tbody tr"
            );

            rows.forEach(function (row) {

                const idEl = row.querySelector(".guard-id");

                if (!idEl) {
                    return;
                }

                const guardId = idEl.textContent.trim();

                const newStatus = statuses[guardId];

                if (!newStatus) {
                    return;
                }

                /* The status badge is the last <td> in the row. */
                const cells = row.querySelectorAll("td");

                if (cells.length === 0) {
                    return;
                }

                const lastCell = cells[cells.length - 1];

                lastCell.innerHTML = "";

                if (newStatus === "Active") {

                    const badge = document.createElement("span");
                    badge.className = "badge badge-green";
                    badge.textContent = "Active";
                    lastCell.appendChild(badge);

                } else {

                    const badge = document.createElement("span");
                    badge.className = "badge badge-gray";
                    badge.textContent = "Inactive";
                    lastCell.appendChild(badge);

                }

            });

        })
        .catch(function () {
            /* Silently ignore network/parse errors. */
        });

}

/* Refresh the statuses immediately on page load. */
updateGuardStatus();

/* Then keep polling every 5 seconds. */
setInterval(updateGuardStatus, 5000);

</script>

</body>

</html>
