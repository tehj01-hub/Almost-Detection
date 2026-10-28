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
   SETTINGS
========================================================= */

$rowsPerPage = 6;

$today = date("Y-m-d");

$displayDate = date("l, F d, Y");


/* =========================================================
   SEARCH / SORT / TIME RANGE
========================================================= */

$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';

$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

$timeFrom = (isset($_GET['time_from']) && preg_match('/^\d{2}:\d{2}$/', trim($_GET['time_from']))) ? trim($_GET['time_from']) : '';

$timeTo = (isset($_GET['time_to']) && preg_match('/^\d{2}:\d{2}$/', trim($_GET['time_to']))) ? trim($_GET['time_to']) : '';

$sortBy = isset($_GET['sort']) ? $_GET['sort'] : '';

$sortDir = (
    isset($_GET['dir']) && $_GET['dir'] === 'desc'
) ? 'desc' : 'asc';


/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function formatTime($datetime)
{
    if (!$datetime) {
        return "-";
    }

    return date("h:i A", strtotime($datetime));
}


function formatLogDate($row)
{
    $dt = !empty($row['time_in']) ? $row['time_in'] : ($row['time_out'] ?? null);
    if (!$dt) {
        return "-";
    }

    return date("M d, Y", strtotime($dt));
}


function formatDateTime($datetime)
{
    if (!$datetime) {
        return "-";
    }

    return date("M d, Y h:i A", strtotime($datetime));
}


function getArchiveDate()
{
    if (
        isset($_GET['archive_date']) &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['archive_date'])
    ) {
        return $_GET['archive_date'];
    }

    return "";
}


/*
 * Build a query string that preserves the active
 * search / sort context across dropdown navigation
 * and pagination links.
 */

function logsQuery($changes = [], $dropPages = true)
{
    $params = [];

    if ($_GET['q'] ?? '') {
        $params['q'] = $_GET['q'];
    }

    if ($_GET['status'] ?? '') {
        $params['status'] = $_GET['status'];
    }

    if ($_GET['time_from'] ?? '') {
        $params['time_from'] = $_GET['time_from'];
    }

    if ($_GET['time_to'] ?? '') {
        $params['time_to'] = $_GET['time_to'];
    }

    if ($_GET['sort'] ?? '') {
        $params['sort'] = $_GET['sort'];
    }

    if ($_GET['dir'] ?? '') {
        $params['dir'] = $_GET['dir'];
    }

    if ($_GET['archive_date'] ?? '') {
        $params['archive_date'] = $_GET['archive_date'];
    }

    if (
        !$dropPages
        && isset(
            $_GET['registered_page']
        )
    ) {
        $params['registered_page'] =
            $_GET['registered_page'];
    }

    if (
        !$dropPages
        && isset(
            $_GET['visitor_page']
        )
    ) {
        $params['visitor_page'] =
            $_GET['visitor_page'];
    }

    if (
        !$dropPages
        && isset(
            $_GET['archive_registered_page']
        )
    ) {
        $params['archive_registered_page'] =
            $_GET['archive_registered_page'];
    }

    if (
        !$dropPages
        && isset(
            $_GET['archive_visitor_page']
        )
    ) {
        $params['archive_visitor_page'] =
            $_GET['archive_visitor_page'];
    }

    foreach ($changes as $key => $value) {

        if ($value === "" || $value === null) {

            unset($params[$key]);

            continue;
        }

        $params[$key] = $value;
    }

    return http_build_query($params);
}


/* =========================================================
   MANUAL ARCHIVE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['archive_selected'])
) {

    $selectedGroups = $_POST['selected_logs'] ?? [];

    $allIds = [];


    /* -----------------------------------------------
       Collect IDs from selected sessions
    ------------------------------------------------ */

    foreach ($selectedGroups as $group) {

        $ids = explode(",", $group);

        foreach ($ids as $id) {

            $id = (int)$id;

            if ($id > 0) {
                $allIds[] = $id;
            }
        }
    }


    $allIds = array_values(
        array_unique($allIds)
    );


    if (count($allIds) > 0) {

        $conn->begin_transaction();

        try {

            /*
             * Create ?,?,? placeholders
             */

            $placeholders = implode(
                ",",
                array_fill(
                    0,
                    count($allIds),
                    "?"
                )
            );


            $types = str_repeat(
                "i",
                count($allIds)
            );


            /* =========================================
               COPY TO ARCHIVE
            ========================================== */

            $actorId = trim($_SESSION['username'] ?? 'Administrator');

            $sqlArchive = "
                INSERT INTO vehicle_logs_archive
                (
                    id,
                    plate_number,
                    vehicle_type,
                    vehicle_category,
                    registered_vehicle_id,
                    visitor_vehicle_id,
                    visitor_name,
                    purpose,
                    direction,
                    recognition_method,
                    date_created,
                    archived_at,
                    archived_by
                )

                SELECT
                    id,
                    plate_number,
                    vehicle_type,
                    vehicle_category,
                    registered_vehicle_id,
                    visitor_vehicle_id,
                    visitor_name,
                    purpose,
                    direction,
                    recognition_method,
                    date_created,
                    NOW(),
                    ?

                FROM vehicle_logs

                WHERE id IN ($placeholders)
                ON DUPLICATE KEY UPDATE
                    archived_at = NOW(),
                    archived_by = VALUES(archived_by)
            ";


            $stmt = $conn->prepare($sqlArchive);

            $stmt->bind_param(
                "s" . $types,
                $actorId,
                ...$allIds
            );

            $stmt->execute();

            $stmt->close();


            /* =========================================
               DELETE FROM ACTIVE LOGS
            ========================================== */

            $sqlDelete = "
                DELETE FROM vehicle_logs
                WHERE id IN ($placeholders)
            ";


            $stmt = $conn->prepare($sqlDelete);

            $stmt->bind_param(
                $types,
                ...$allIds
            );

            $stmt->execute();

            $stmt->close();


            $conn->commit();
            secsysLogActivity($conn, $actorId, 'Archive', "Administrator archived " . count($allIds) . " vehicle log record(s).");


            header(
                "Location: logs.php?archived=success"
            );

            exit();

        }

        catch (Exception $e) {

            $conn->rollback();

            header(
                "Location: logs.php?archived=error"
            );

            exit();
        }

    }

    else {

        header(
            "Location: logs.php?archived=none"
        );

        exit();
    }
}


/* =========================================================
   GET ARCHIVE DATE
========================================================= */

$archiveDate = getArchiveDate();


/* =========================================================
   DAILY 12:00 AM RESET & GET CURRENT LOGS
========================================================= */

secsysAutoArchivePastLogs($conn);

$sqlLogs = "
    SELECT
        vl.*,

        rv.owner_name

    FROM vehicle_logs vl

    LEFT JOIN registered_vehicles rv
        ON vl.registered_vehicle_id = rv.id

    WHERE DATE(vl.date_created) = CURDATE()

    ORDER BY vl.date_created DESC
";


$resultLogs = $conn->query($sqlLogs);

if (!$resultLogs) {
    die("Logs Query Error: " . $conn->error);
}


/* =========================================================
   CREATE DISPLAY SESSIONS
========================================================= */

function buildSessions($result)
{
    $sessions = [];
    $openSessions = [];

    // Collect all rows and ensure strict chronological (ASC) ordering for pairing
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    usort($rows, function ($a, $b) {
        $ta = strtotime($a['date_created'] ?? '1970-01-01');
        $tb = strtotime($b['date_created'] ?? '1970-01-01');
        if ($ta === $tb) {
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        }
        return $ta <=> $tb;
    });

    foreach ($rows as $row) {
        $plate = strtoupper(trim($row['plate_number'] ?? ''));
        $category = strtolower(trim($row['vehicle_category'] ?? 'registered'));
        $direction = strtoupper(trim($row['direction'] ?? 'IN'));
        $vehicleType = !empty($row['vehicle_type']) ? $row['vehicle_type'] : 'Vehicle';
        $logId = isset($row['id']) ? (int)$row['id'] : 0;
        $dateCreated = $row['date_created'] ?? null;
        $archivedAt = $row['archived_at'] ?? null;

        if ($category === "registered") {
            $name = !empty($row['owner_name']) ? $row['owner_name'] : "Registered Vehicle";
        } else {
            $name = !empty($row['visitor_name']) ? $row['visitor_name'] : "Visitor";
        }

        if ($direction === "IN") {
            $session = [
                "ids" => $logId > 0 ? [$logId] : [],
                "name" => $name,
                "plate_number" => $plate,
                "vehicle_type" => $vehicleType,
                "time_in" => $dateCreated,
                "time_out" => null,
                "status" => "Inside",
                "purpose" => $row['purpose'] ?? "",
                "category" => $row['vehicle_category'] ?? ucfirst($category),
                "archived_at" => $archivedAt
            ];

            $sessions[] = $session;
            $sessionIndex = count($sessions) - 1;
            $openSessions[$plate][] = $sessionIndex;
        } elseif ($direction === "OUT") {
            $matched = false;

            if (isset($openSessions[$plate]) && count($openSessions[$plate]) > 0) {
                $sessionIndex = array_shift($openSessions[$plate]);
                if ($logId > 0) {
                    $sessions[$sessionIndex]['ids'][] = $logId;
                }
                $sessions[$sessionIndex]['time_out'] = $dateCreated;
                $sessions[$sessionIndex]['status'] = "Outside";
                if (!empty($archivedAt)) {
                    $sessions[$sessionIndex]['archived_at'] = $archivedAt;
                }
                $matched = true;
            }

            if (!$matched) {
                // Standalone OUT event (do NOT pull from archive to prevent data overlap)
                $sessions[] = [
                    "ids" => $logId > 0 ? [$logId] : [],
                    "name" => $name,
                    "plate_number" => $plate,
                    "vehicle_type" => $vehicleType,
                    "time_in" => null,
                    "time_out" => $dateCreated,
                    "status" => "Outside",
                    "purpose" => $row['purpose'] ?? "",
                    "category" => $row['vehicle_category'] ?? ucfirst($category),
                    "archived_at" => $archivedAt
                ];
            }
        }
    }

    // Sort final sessions in reverse chronological order (newest activity at the top)
    usort($sessions, function ($a, $b) {
        $ta = strtotime(!empty($a['time_out']) ? $a['time_out'] : ($a['time_in'] ?? '1970-01-01'));
        $tb = strtotime(!empty($b['time_out']) ? $b['time_out'] : ($b['time_in'] ?? '1970-01-01'));
        return $tb <=> $ta;
    });

    return $sessions;
}


$allSessions = buildSessions($resultLogs);


/* =========================================================
   SEPARATE REGISTERED AND VISITOR
========================================================= */

$registeredSessions = [];

$visitorSessions = [];


foreach ($allSessions as $session) {

    if (
        strtolower($session['category'])
        === "registered"
    ) {

        $registeredSessions[] =
            $session;

    }

    else {

        $visitorSessions[] =
            $session;

    }
}


/* =========================================================
   TIME RANGE MATCH HELPER
========================================================= */

function matchesTimeRange($timeIn, $timeOut, $timeFrom, $timeTo)
{
    if ($timeFrom === '' && $timeTo === '') {
        return true;
    }

    $tIn = !empty($timeIn) ? date("H:i", strtotime($timeIn)) : null;
    $tOut = !empty($timeOut) ? date("H:i", strtotime($timeOut)) : null;

    if ($tIn === null && $tOut === null) {
        return false;
    }

    $inRange = function ($t) use ($timeFrom, $timeTo) {
        if ($t === null) {
            return false;
        }
        if ($timeFrom !== '' && $timeTo !== '') {
            if ($timeFrom <= $timeTo) {
                return ($t >= $timeFrom && $t <= $timeTo);
            } else {
                return ($t >= $timeFrom || $t <= $timeTo);
            }
        }
        if ($timeFrom !== '') {
            return $t >= $timeFrom;
        }
        if ($timeTo !== '') {
            return $t <= $timeTo;
        }
        return true;
    };

    if ($inRange($tIn) || $inRange($tOut)) {
        return true;
    }

    if ($timeFrom !== '' && $timeTo !== '' && $timeFrom <= $timeTo && $tIn !== null && $tOut !== null) {
        if ($tIn <= $timeFrom && $tOut >= $timeTo) {
            return true;
        }
    }

    return false;
}


/* =========================================================
   SEARCH / SORT FUNCTION
========================================================= */

function filterAndSortSessions(
    $sessions,
    $searchQuery,
    $sortBy,
    $sortDir,
    $statusFilter = '',
    $timeFrom = '',
    $timeTo = ''
) {

    /* --- text search (plate) --- */

    if ($searchQuery !== '') {

        $q = strtolower($searchQuery);

        $sessions = array_values(
            array_filter(
                $sessions,
                function ($s) use ($q) {
                    return stripos($s['plate_number'], $q) !== false;
                }
            )
        );

    }

    /* --- status filter (Inside / Outside) --- */

    if ($statusFilter !== '' && strtolower($statusFilter) !== 'all') {
        $sf = strtolower($statusFilter);
        $sessions = array_values(
            array_filter(
                $sessions,
                function ($s) use ($sf) {
                    return strtolower($s['status'] ?? '') === $sf;
                }
            )
        );
    }

    /* --- time range filter (From Hour - To Hour) --- */

    if ($timeFrom !== '' || $timeTo !== '') {
        $sessions = array_values(
            array_filter(
                $sessions,
                function ($s) use ($timeFrom, $timeTo) {
                    return matchesTimeRange($s['time_in'] ?? null, $s['time_out'] ?? null, $timeFrom, $timeTo);
                }
            )
        );
    }


    /* --- sort --- */

    $validSortCols = [
        'date',
        'time',
        'status'
    ];

    if (
        $sortBy !== ''
        && in_array($sortBy, $validSortCols)
        && count($sessions) > 0
    ) {

        usort(
            $sessions,
            function ($a, $b) use ($sortBy, $sortDir) {

                if ($sortBy === 'status') {
                    $cmp = strcasecmp($a['status'] ?? '', $b['status'] ?? '');
                    return $sortDir === 'desc' ? -$cmp : $cmp;
                }

                /* Sessions only carry full datetime values,
                   so for 'date' / 'time' extract the relevant
                   part (time_in first, fall back to time_out). */
                $extract = function ($s) use ($sortBy) {

                    $dt = $s['time_in'] ?: $s['time_out'];

                    if (!$dt) {
                        return '';
                    }

                    if ($sortBy === 'date') {
                        return date(
                            'Y-m-d',
                            strtotime($dt)
                        );
                    }

                    if ($sortBy === 'time') {
                        return date(
                            'H:i:s',
                            strtotime($dt)
                        );
                    }

                    return $s['plate'] ?? '';

                };

                $valA = $extract($a);
                $valB = $extract($b);

                $cmp = strcasecmp($valA, $valB);

                return $sortDir === 'desc' ? -$cmp : $cmp;

            }
        );

    }


    return $sessions;
}


$registeredSessions = filterAndSortSessions(
    $registeredSessions,
    $searchQuery,
    $sortBy,
    $sortDir,
    $statusFilter,
    $timeFrom,
    $timeTo
);

$visitorSessions = filterAndSortSessions(
    $visitorSessions,
    $searchQuery,
    $sortBy,
    $sortDir,
    $statusFilter,
    $timeFrom,
    $timeTo
);


/* =========================================================
   PAGINATION FUNCTION
========================================================= */

function paginate(
    $data,
    $page,
    $perPage
) {

    $total =
        count($data);

    $totalPages =
        max(
            1,
            ceil($total / $perPage)
        );


    $page =
        max(
            1,
            min(
                $page,
                $totalPages
            )
        );


    $offset =
        ($page - 1) * $perPage;


    return [

        "items" =>
            array_slice(
                $data,
                $offset,
                $perPage
            ),

        "total" =>
            $total,

        "page" =>
            $page,

        "totalPages" =>
            $totalPages

    ];
}


/* =========================================================
   CURRENT PAGINATION
========================================================= */

$registeredPage =
    isset($_GET['registered_page'])
    ? (int)$_GET['registered_page']
    : 1;


$visitorPage =
    isset($_GET['visitor_page'])
    ? (int)$_GET['visitor_page']
    : 1;


$registeredPagination =
    paginate(
        $registeredSessions,
        $registeredPage,
        $rowsPerPage
    );


$visitorPagination =
    paginate(
        $visitorSessions,
        $visitorPage,
        $rowsPerPage
    );


/* =========================================================
   ARCHIVE DATA
========================================================= */

$archiveRegistered = [];

$archiveVisitors = [];


if ($archiveDate !== "") {

    $stmt = $conn->prepare("
        SELECT
            va.*,
            rv.owner_name

        FROM vehicle_logs_archive va

        LEFT JOIN registered_vehicles rv
            ON va.registered_vehicle_id = rv.id

        WHERE DATE(va.date_created) = ?

        ORDER BY va.date_created DESC
    ");


    $stmt->bind_param(
        "s",
        $archiveDate
    );


    $stmt->execute();


    $archiveResult =
        $stmt->get_result();


    $archiveSessions =
        buildSessions(
            $archiveResult
        );


    foreach ($archiveSessions as $session) {

        if (
            strtolower(
                $session['category']
            ) === "registered"
        ) {

            $archiveRegistered[] =
                $session;

        }

        else {

            $archiveVisitors[] =
                $session;

        }

    }


    $stmt->close();


    $archiveRegistered = filterAndSortSessions(
        $archiveRegistered,
        $searchQuery,
        $sortBy,
        $sortDir,
        $statusFilter,
        $timeFrom,
        $timeTo
    );

    $archiveVisitors = filterAndSortSessions(
        $archiveVisitors,
        $searchQuery,
        $sortBy,
        $sortDir,
        $statusFilter,
        $timeFrom,
        $timeTo
    );

}


/* =========================================================
   ARCHIVE PAGINATION
========================================================= */

$archiveRegisteredPage =
    isset($_GET['archive_registered_page'])
    ? (int)$_GET['archive_registered_page']
    : 1;


$archiveVisitorPage =
    isset($_GET['archive_visitor_page'])
    ? (int)$_GET['archive_visitor_page']
    : 1;


$archiveRegisteredPagination =
    paginate(
        $archiveRegistered,
        $archiveRegisteredPage,
        $rowsPerPage
    );


$archiveVisitorPagination =
    paginate(
        $archiveVisitors,
        $archiveVisitorPage,
        $rowsPerPage
    );


/* =========================================================
   PRINT MODE
========================================================= */

$isPrint =
    isset($_GET['print']) &&
    $_GET['print'] === "1";


$printSource =
    $_GET['source'] ?? "current";


if ($isPrint) {

    if ($printSource === "archive") {

        $printRegistered =
            $archiveRegistered;

        $printVisitors =
            $archiveVisitors;

        $printTitle =
            "Archived Vehicle Logs";

        $printDate =
            $archiveDate;

    }

    else {

        $printRegistered =
            $registeredSessions;

        $printVisitors =
            $visitorSessions;

        $printTitle =
            "Vehicle Logs";

        $printDate =
            $today;

    }

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Logs | <?php echo htmlspecialchars($systemName); ?>
</title>


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
    font-family:
        Arial,
        Helvetica,
        sans-serif;

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
        0 1px 3px rgba(0,0,0,.08);

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

    width: calc(100% - 212px);

    min-height: 100vh;

    padding: 32px 38px 40px;
    box-sizing: border-box;
}


/* =========================================================
   HEADER
========================================================= */

.header {

    margin-bottom: 12px;
}

.header h1 {

    font-size: 24px;

    font-weight: 500;
}

.header p {

    font-size: 13px;

    color: #666;

    margin-top: 3px;
}


/* =========================================================
   ACTION BAR
========================================================= */

.action-bar {

    display: flex;

    align-items: center;

    gap: 12px;

    margin-bottom: 10px;

    flex-wrap: wrap;
}

.action-button {

    height: 34px;

    padding: 0 25px;

    border: none;

    border-radius: 20px;

    background: #2f80ed;

    color: white;

    font-size: 13px;

    cursor: pointer;

    text-decoration: none;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    transition: .2s;
}

.action-button:hover {

    background: #176bd1;
}

.action-button.archive {

    background: #2f80ed;
}

.action-button.archive:hover {

    background: #176bd1;
}


/* =========================================================
   ARCHIVE DATE
========================================================= */

.archive-date-box {

    display: flex;

    align-items: center;

    gap: 8px;

    margin-left: auto;
}

.archive-date-box label {

    font-size: 12px;

    color: #555;
}

.archive-date {

    height: 34px;

    border: 1px solid #ccc;

    border-radius: 18px;

    padding: 0 13px;

    background: white;

    font-size: 12px;

    color: #333;

    cursor: pointer;
}

.view-archive {

    height: 34px;

    padding: 0 17px;

    border: 1px solid #2f80ed;

    border-radius: 18px;

    background: white;

    color: #2f80ed;

    font-size: 12px;

    cursor: pointer;
}

.view-archive:hover {

    background: #edf5ff;
}


/* =========================================================
   NOTIFICATION
========================================================= */

.notice {

    max-width: 1000px;

    padding: 9px 14px;

    border-radius: 10px;

    margin-bottom: 10px;

    font-size: 12px;
}

.notice.success {

    background: #e7f7ed;

    color: #16733b;

    border: 1px solid #bde5ca;
}

.notice.error {

    background: #ffeaea;

    color: #a52222;

    border: 1px solid #f0bcbc;
}

.notice.info {

    background: #edf5ff;

    color: #245b9e;

    border: 1px solid #c9ddf7;
}


/* =========================================================
   LOG SECTION
========================================================= */

.log-section {

    width: 100%;

    max-width: 1080px;

    margin-top: 12px;
}


/* =========================================================
   SECTION TITLE
========================================================= */

.section-header {

    display: flex;

    align-items: center;

    justify-content: space-between;

    margin-bottom: 7px;
}

.section-title {

    font-size: 18px;

    font-weight: 500;
}

.section-count {

    font-size: 11px;

    color: #777;
}


/* =========================================================
   TABLE CONTAINER
========================================================= */

.table-card {

    background: white;

    border: 1px solid #ddd;

    border-radius: 20px;

    overflow: hidden;

    box-shadow:
        0 2px 4px rgba(0,0,0,.15);
}


/* =========================================================
   TABLE
========================================================= */

.log-table {

    width: 100%;

    border-collapse: collapse;

    table-layout: fixed;
}

.log-table th {

    height: 38px;

    background: #fafafa;

    font-size: 11px;

    font-weight: 500;

    color: #333;

    border-bottom: 1px solid #ddd;

    text-align: center;
    vertical-align: middle;

    padding: 5px;
}

.log-table td {

    height: 39px;

    font-size: 11px;

    border-bottom: 1px solid #ddd;

    text-align: center;
    vertical-align: middle;

    padding: 5px;

    overflow: hidden;

    text-overflow: ellipsis;

    white-space: nowrap;
}

.log-table tbody tr:hover {

    background: #f7fbff;
}


/* =========================================================
   COLUMN WIDTHS
========================================================= */

.col-check {
    width: 45px;
}

.col-number {
    width: 45px;
}

.col-name {
    width: 25%;
}

.col-plate {
    width: 21%;
}

.col-time {
    width: 17%;
}


/* =========================================================
   CHECKBOX
========================================================= */

.log-checkbox,
.select-all {

    width: 14px;

    height: 14px;

    cursor: pointer;

    accent-color: #2f80ed;
}


/* =========================================================
   TABLE TOOLBAR
========================================================= */

.table-toolbar {

    min-height: 44px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 6px 13px;

    border-bottom: 1px solid #ddd;

    background: #fff;
}

.select-all-box {

    display: flex;

    align-items: center;

    gap: 7px;

    font-size: 11px;

    color: #555;
}

.archive-selected {

    height: 29px;

    padding: 0 15px;

    border: none;

    border-radius: 16px;

    background: #2f80ed;

    color: white;

    font-size: 11px;

    cursor: pointer;
}

.archive-selected:hover {

    background: #176bd1;
}


/* =========================================================
   SEARCH / SORT BAR
========================================================= */

.search-filter-bar {

    display: flex;

    align-items: center;

    gap: 14px;

    padding: 12px 0;

    flex-wrap: wrap;
}

.search-box {

    display: flex;

    align-items: center;

    gap: 8px;

    background: white;

    border: 1px solid #ddd;

    border-radius: 20px;

    padding: 0 14px;

    height: 36px;

    min-width: 260px;
}

.search-icon {

    font-size: 13px;

    color: #999;
}

.search-input {

    border: none;

    outline: none;

    font-size: 12px;

    width: 100%;

    background: transparent;
}

.is-hidden {

    display: none;
}


/* =========================================================
   SORT DROPDOWN
========================================================= */

.sort-dropdown {

    position: relative;
}

.sort-toggle {

    display: flex;

    align-items: center;

    gap: 7px;

    height: 34px;

    padding: 0 12px;

    border: 1px solid #ddd;

    border-radius: 16px;

    font-size: 12px;

    background: white;

    color: #333;

    outline: none;

    cursor: pointer;
}

.sort-toggle:hover,
.sort-toggle.sort-open {

    border-color: #2f80ed;
}

.sort-toggle-label {

    display: flex;

    align-items: center;

    gap: 5px;
}

.sort-toggle-label strong {

    font-weight: 600;
}

.sort-caret {

    font-size: 10px;

    color: #999;
}

.sort-menu {

    position: absolute;

    top: calc(100% + 5px);

    right: 0;

    min-width: 170px;

    list-style: none;

    margin: 0;

    padding: 6px;

    background: white;

    border: 1px solid #ddd;

    border-radius: 10px;

    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);

    display: none;

    z-index: 40;
}

.sort-menu.sort-open {

    display: block;
}

.sort-menu li {

    margin: 2px 0;
}

.sort-menu a {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 10px;

    padding: 7px 10px;

    border-radius: 6px;

    text-decoration: none;

    color: #333;

    font-size: 12px;
}

.sort-menu a:hover {

    background: #f0f6ff;

    color: #2f80ed;
}

.sort-menu a.active {

    background: #e8f1fe;

    color: #2f80ed;

    font-weight: 600;
}

.sort-menu .sort-divider {

    height: 1px;

    margin: 6px 4px;

    background: #e2e2e2;
}


/* =========================================================
   EMPTY TABLE
========================================================= */

.empty-row {

    height: 90px !important;

    color: #888;

    font-size: 12px !important;
}


/* =========================================================
   PAGINATION
========================================================= */

.pagination {

    min-height: 43px;

    display: flex;

    align-items: center;

    justify-content: center;

    gap: 2px;

    padding: 6px;

    background: white;
}

.pagination a {

    min-width: 23px;

    height: 23px;

    padding: 0 6px;

    display: flex;

    align-items: center;

    justify-content: center;

    text-decoration: none;

    border: 1px solid #ddd;

    color: #333;

    font-size: 10px;

    background: white;
}

.pagination a:hover {

    background: #edf5ff;
}

.pagination a.active {

    background: #2f80ed;

    color: white;

    border-color: #2f80ed;
}

.pagination a.disabled {

    color: #aaa;

    pointer-events: none;
}


/* =========================================================
   ARCHIVE AREA
========================================================= */

.archive-area {

    width: 100%;

    max-width: 1080px;

    margin-top: 30px;

    padding-top: 20px;

    border-top: 1px dashed #ccc;
}

.archive-heading {

    display: flex;

    align-items: center;

    justify-content: space-between;

    margin-bottom: 12px;
}

.archive-heading h2 {

    font-size: 20px;

    font-weight: 500;
}

.archive-heading p {

    font-size: 11px;

    color: #777;

    margin-top: 3px;
}

.archive-print {

    height: 34px;

    padding: 0 20px;

    border: none;

    border-radius: 20px;

    background: #2f80ed;

    color: white;

    cursor: pointer;

    font-size: 12px;

    text-decoration: none;

    display: inline-flex;

    align-items: center;
}


/* =========================================================
   ARCHIVE EMPTY
========================================================= */

.archive-empty {

    background: white;

    border: 1px solid #ddd;

    border-radius: 18px;

    padding: 35px;

    text-align: center;

    color: #777;

    font-size: 13px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1000px) {

    .main {

        padding-left: 20px;

        padding-right: 20px;
    }

    .archive-date-box {

        margin-left: 0;

        width: 100%;
    }

    .archive-date {

        flex: 1;
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

        padding: 22px 15px 30px;
    }
}


@media (max-width: 600px) {

    .main {

        padding: 18px 10px 25px;
    }

    .header h1 {

        font-size: 21px;
    }

    .action-bar {

        align-items: stretch;

        flex-direction: column;
    }

    .action-button {

        width: 100%;
    }

    .archive-date-box {

        flex-direction: column;

        align-items: stretch;
    }

    .archive-date,
    .view-archive {

        width: 100%;
    }

    .log-table {

        min-width: 720px;
    }

    .table-card {

        overflow-x: auto;
    }

    .section-header {

        align-items: flex-start;

        flex-direction: column;

        gap: 3px;
    }

    .archive-heading {

        align-items: flex-start;

        flex-direction: column;

        gap: 10px;
    }
}


/* =========================================================
   PRINT MODE
========================================================= */

@media print {

    @page {

        size: A4 portrait;

        margin: 15mm;
    }

    body {

        background: white;

        color: black;
    }

    .sidebar,
    .action-bar,
    .notice,
    .archive-area,
    .table-toolbar,
    .pagination,
    .no-print {

        display: none !important;
    }

    .main {

        margin: 0;

        width: 100%;

        padding: 0;
    }

    .print-header {

        display: block !important;

        margin-bottom: 20px;

        text-align: center;
    }

    .print-header h1 {

        font-size: 22px;

        margin-bottom: 5px;
    }

    .print-header p {

        font-size: 12px;

        color: #555;
    }

    .print-section {

        display: block;

        margin-bottom: 25px;
    }

    .print-section h2 {

        font-size: 16px;

        margin-bottom: 8px;
    }

    .print-table {

        width: 100%;

        border-collapse: collapse;
    }

    .print-table th,
    .print-table td {

        border: 1px solid #333;

        padding: 7px;

        font-size: 10px;

        text-align: center;
    }

    .print-table th {

        background: #f1f1f1;

        font-weight: bold;
    }
}

.print-header {

    display: none;
}

</style>

</head>


<body>


<?php if ($isPrint): ?>


<!-- =====================================================
     PRINT VERSION
====================================================== -->

<div class="main">

    <div class="print-header">

        <h1>
            L. Surveillance
        </h1>

        <p>
            <?php echo htmlspecialchars($printTitle); ?>
        </p>

        <p>
            Date:
            <?php
            echo date(
                "F d, Y",
                strtotime($printDate)
            );
            ?>
        </p>

    </div>


    <!-- REGISTERED PRINT -->

    <div class="print-section">

        <h2>
            Registered Vehicle Logs
        </h2>


        <table class="print-table">

            <thead>

                <tr>

                    <th>No.</th>

                    <th>Plate Number</th>

                    <th>Date</th>

                    <th>Time In</th>

                    <th>Time Out</th>

                    <th>Status</th>

                </tr>

            </thead>


            <tbody>

            <?php if (count($printRegistered) > 0): ?>

                <?php
                $number = 1;
                ?>

                <?php foreach ($printRegistered as $row): ?>

                    <tr>

                        <td>
                            <?php echo $number++; ?>.
                        </td>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $row['plate_number']
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo formatLogDate($row);
                            ?>
                        </td>

                        <td>
                            <?php
                            echo formatTime(
                                $row['time_in']
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo formatTime(
                                $row['time_out']
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $row['status'] ?? '-'
                            );
                            ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>

                    <td colspan="6">
                        No registered vehicle logs.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>


    <!-- VISITOR PRINT -->

    <div class="print-section">

        <h2>
            Visitor Vehicle Logs
        </h2>


        <table class="print-table">

            <thead>

                <tr>

                    <th>No.</th>

                    <th>Plate Number</th>

                    <th>Date</th>

                    <th>Time In</th>

                    <th>Time Out</th>

                    <th>Status</th>

                </tr>

            </thead>


            <tbody>

            <?php if (count($printVisitors) > 0): ?>

                <?php
                $number = 1;
                ?>

                <?php foreach ($printVisitors as $row): ?>

                    <tr>

                        <td>
                            <?php echo $number++; ?>.
                        </td>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $row['plate_number']
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo formatLogDate($row);
                            ?>
                        </td>

                        <td>
                            <?php
                            echo formatTime(
                                $row['time_in']
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo formatTime(
                                $row['time_out']
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $row['status'] ?? '-'
                            );
                            ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>

                    <td colspan="6">
                        No visitor vehicle logs.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<script>

window.onload = function() {

    window.print();

};

</script>


<?php exit; ?>

<?php endif; ?>


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


        <a href="superadmin_dashboard.php">

            <span class="nav-icon">
                🖥️
            </span>

            <span>
                Dashboard
            </span>

        </a>


        <a
            href="logs.php"
            class="active"
        >

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


    <a
        href="logout.php?role=Administrator"
        class="logout"
    >
        <span class="nav-icon">🚪</span>
        <span>Logout</span>
    </a>


</aside>



<!-- =====================================================
     MAIN
====================================================== -->

<main class="main">


    <!-- HEADER -->

    <div class="header">

        <h1>
            Logs
        </h1>

        <p>
            <?php
            echo htmlspecialchars(
                $displayDate
            );
            ?> &bull; <span style="font-size:12px; color:#4b5563; font-weight:500;">(Resets daily at 12:00 AM)</span>
        </p>

    </div>


    <!-- =================================================
         NOTIFICATIONS
    ================================================== -->

    <?php if (isset($_GET['archived'])): ?>

        <?php if ($_GET['archived'] === 'success'): ?>

            <div class="notice success">

                Selected vehicle logs were
                successfully archived.

            </div>

        <?php elseif ($_GET['archived'] === 'none'): ?>

            <div class="notice info">

                Please select at least one
                vehicle log to archive.

            </div>

        <?php else: ?>

            <div class="notice error">

                Unable to archive the selected logs.

                Please try again.

            </div>

        <?php endif; ?>

    <?php endif; ?>


    <!-- =================================================
         ACTION BAR
    ================================================== -->

    <div class="action-bar">


        <!-- PRINT CURRENT LOGS -->

        <a
            href="logs.php?<?php echo logsQuery(['print' => '1', 'source' => 'current']); ?>"
            target="_blank"
            class="action-button"
        >
            🖨️ Print Report
        </a>


        <!-- ARCHIVE SELECTED -->

        <button
            type="submit"
            form="archiveForm"
            name="archive_selected"
            class="action-button archive"
        >
            📦 Archive Selected
        </button>


        <!-- ARCHIVE DATE -->

        <form
            method="GET"
            class="archive-date-box"
        >

            <label>
                View Archive:
            </label>

            <input
                type="date"
                name="archive_date"
                class="archive-date"
                value="<?php
                    echo htmlspecialchars(
                        $archiveDate
                    );
                ?>"
                max="<?php
                    echo $today;
                ?>"
                required
            >

            <?php if ($searchQuery !== ''): ?>
                <input
                    type="hidden"
                    name="q"
                    value="<?php
                        echo htmlspecialchars(
                            $searchQuery
                        );
                    ?>"
                >
            <?php endif; ?>

            <?php if ($sortBy !== ''): ?>
                <input
                    type="hidden"
                    name="sort"
                    value="<?php
                        echo htmlspecialchars(
                            $sortBy
                        );
                    ?>"
                >
                <input
                    type="hidden"
                    name="dir"
                    value="<?php
                        echo htmlspecialchars(
                            $sortDir
                        );
                    ?>"
                >
            <?php endif; ?>


            <button
                type="submit"
                class="view-archive"
            >
                📦 View Archive
            </button>

        </form>


    </div>


    <!-- =================================================
         SEARCH / SORT BAR
    ================================================== -->

    <div class="search-filter-bar">

        <div class="search-box">

            <span class="search-icon">
                &#128269;
            </span>

            <input
                type="text"
                id="logSearch"
                class="search-input"
                placeholder="Search plate number..."
                value="<?php
                    echo htmlspecialchars(
                        $searchQuery
                    );
                ?>"
            >

        </div>


        <!-- STATUS FILTER (FOR REPORT / SORT) -->

        <select
            id="logStatusFilter"
            class="archive-date"
            style="height: 36px; border-radius: 18px; padding: 0 12px;"
            onchange="window.location.href = '?' + new URLSearchParams(Object.assign(Object.fromEntries(new URLSearchParams(window.location.search)), {status: this.value, registered_page: 1, visitor_page: 1})).toString()"
        >
            <option value="">All Statuses (Report)</option>
            <option value="Inside" <?php echo $statusFilter === 'Inside' ? 'selected' : ''; ?>>Inside</option>
            <option value="Outside" <?php echo $statusFilter === 'Outside' ? 'selected' : ''; ?>>Outside</option>
        </select>

        <!-- TIME IN / TIME OUT RANGE FILTER -->

        <div style="display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid #d4d4d4; border-radius: 18px; padding: 0 12px; height: 36px;">
            <span style="font-size: 12px; color: #555; font-weight: 600;">Hours:</span>
            <input
                type="time"
                id="logTimeFrom"
                value="<?php echo htmlspecialchars($timeFrom); ?>"
                title="From Hour (Time In / Out)"
                style="border: none; background: transparent; font-size: 12px; color: #111; outline: none;"
                onchange="window.location.href = '?' + new URLSearchParams(Object.assign(Object.fromEntries(new URLSearchParams(window.location.search)), {time_from: this.value, registered_page: 1, visitor_page: 1})).toString()"
            >
            <span style="font-size: 12px; color: #777;">to</span>
            <input
                type="time"
                id="logTimeTo"
                value="<?php echo htmlspecialchars($timeTo); ?>"
                title="To Hour (Time In / Out)"
                style="border: none; background: transparent; font-size: 12px; color: #111; outline: none;"
                onchange="window.location.href = '?' + new URLSearchParams(Object.assign(Object.fromEntries(new URLSearchParams(window.location.search)), {time_to: this.value, registered_page: 1, visitor_page: 1})).toString()"
            >
        </div>

        <!-- SORT BY -->

        <div class="sort-dropdown">

            <button
                type="button"
                class="sort-toggle"
                id="sortToggle"
                aria-haspopup="true"
                aria-expanded="false"
            >
                <span class="sort-toggle-label">
                    Sort:
                    <strong>
                        <?php
                        echo in_array(
                            $sortBy,
                            ['date', 'time', 'status']
                        )
                            ? ucfirst($sortBy)
                            : 'Default';
                        ?>
                    </strong>
                </span>
                <span class="sort-caret">
                    &#9662;
                </span>
            </button>

            <ul class="sort-menu" id="sortMenu">

                <?php foreach (['date', 'time', 'status'] as $sortFieldKey): ?>

                    <li>
                        <a
                            href="?<?php echo logsQuery([
                                'sort' => $sortFieldKey,
                                'dir'  => $sortDir
                            ]); ?>"
                            data-sort="<?php echo $sortFieldKey; ?>"
                            class="<?php
                                echo $sortBy === $sortFieldKey
                                    ? 'active'
                                    : '';
                            ?>"
                        >
                            <span>
                                <?php echo $sortFieldKey === 'status' ? 'Status (Inside/Outside)' : ucfirst($sortFieldKey); ?>
                            </span>
                        </a>
                    </li>

                <?php endforeach; ?>

                <li class="sort-divider"></li>

                <?php foreach ([
                    'asc'  => 'Ascending',
                    'desc' => 'Descending'
                ] as $sortDirKey => $sortDirLabel): ?>

                    <li>
                        <a
                            href="?<?php echo logsQuery([
                                'sort' => in_array(
                                    $sortBy,
                                    ['date', 'time', 'status']
                                )
                                    ? $sortBy
                                    : 'time',
                                'dir'  => $sortDirKey
                            ]); ?>"
                            data-dir="<?php echo $sortDirKey; ?>"
                            class="<?php
                                echo (
                                    in_array(
                                        $sortBy,
                                        ['date', 'time', 'status']
                                    )
                                    && $sortDir === $sortDirKey
                                ) ? 'active' : '';
                            ?>"
                        >
                            <span>
                                <?php echo $sortDirLabel; ?>
                            </span>
                        </a>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>


        <!-- CLEAR FILTERS -->

        <a
            href="logs.php"
            class="<?php
                echo (
                    $searchQuery !== ''
                    || $statusFilter !== ''
                    || $timeFrom !== ''
                    || $timeTo !== ''
                    || $sortBy !== ''
                ) ? '' : 'is-hidden';
            ?>"
            id="clearFilters"
        >
            &#10005; Clear
        </a>

    </div>


    <!-- =================================================
         ARCHIVE FORM
    ================================================== -->

    <form
        method="POST"
        id="archiveForm"
    >


        <!-- =================================================
             REGISTERED VEHICLES
        ================================================== -->

        <section class="log-section" id="liveRegisteredLogs" data-live>


            <div class="section-header">

                <div class="section-title">
                    Registered Vehicle Logs
                </div>

                <div class="section-count">

                    <?php
                    echo $registeredPagination['total'];
                    ?>

                    record(s)

                </div>

            </div>


            <div class="table-card">


                <!-- TOOLBAR -->

                <div class="table-toolbar">

                    <label class="select-all-box">

                        <input
                            type="checkbox"
                            class="select-all"
                            data-target="registered"
                        >

                        Select All

                    </label>


                    <button
                        type="submit"
                        name="archive_selected"
                        class="archive-selected"
                    >
                        Archive Selected
                    </button>

                </div>


                <!-- TABLE -->

                <table class="log-table">

                    <thead>

                        <tr>

                            <th class="col-check">
                                <input
                                    type="checkbox"
                                    class="select-all"
                                    data-target="registered"
                                >
                            </th>

                            <th class="col-number">
                                No.
                            </th>

                            <th class="col-plate">
                                Plate Number
                            </th>

                            <th class="col-time">
                                Date
                            </th>

                            <th class="col-time">
                                Time In
                            </th>

                            <th class="col-time">
                                Time Out
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (
                        count(
                            $registeredPagination['items']
                        ) > 0
                    ): ?>


                        <?php

                        $number =
                            (
                                (
                                    $registeredPagination['page']
                                    - 1
                                )
                                *
                                $rowsPerPage
                            )
                            + 1;

                        ?>


                        <?php foreach (
                            $registeredPagination['items']
                            as $row
                        ): ?>


                            <?php

                            $idString =
                                implode(
                                    ",",
                                    $row['ids']
                                );

                            ?>


                            <tr>


                                <!-- CHECKBOX -->

                                <td>

                                    <input
                                        type="checkbox"
                                        name="selected_logs[]"
                                        value="<?php
                                            echo htmlspecialchars(
                                                $idString
                                            );
                                        ?>"
                                        class="log-checkbox registered-check"
                                    >

                                </td>


                                <!-- NUMBER -->

                                <td>

                                    <?php
                                    echo $number++;
                                    ?>.

                                </td>


                                <!-- PLATE -->

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $row['plate_number']
                                    );
                                    ?>

                                </td>


                                <!-- DATE -->

                                <td>

                                    <?php
                                    echo formatLogDate($row);
                                    ?>

                                </td>


                                <!-- TIME IN -->

                                <td>

                                    <?php
                                    echo formatTime(
                                        $row['time_in']
                                    );
                                    ?>

                                </td>


                                <!-- TIME OUT -->

                                <td>

                                    <?php
                                    echo formatTime(
                                        $row['time_out']
                                    );
                                    ?>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                    <?php else: ?>


                        <tr>

                            <td
                                colspan="6"
                                class="empty-row"
                            >

                                No registered vehicle
                                logs found for today.

                            </td>

                        </tr>


                    <?php endif; ?>


                    </tbody>

                </table>


                <!-- PAGINATION -->

                <div class="pagination">

                    <?php

                    $currentPage =
                        $registeredPagination['page'];

                    $totalPages =
                        $registeredPagination['totalPages'];

                    ?>


                    <?php if ($currentPage > 1): ?>

                        <a href="?<?php
                            echo logsQuery([
                                'registered_page' => $currentPage - 1,
                            ]);
                        ?>">

                            &lt;

                        </a>

                    <?php else: ?>

                        <a class="disabled">
                            &lt;
                        </a>

                    <?php endif; ?>


                    <?php

                    for (
                        $i = 1;
                        $i <= $totalPages;
                        $i++
                    ):

                    ?>

                        <a
                            href="?<?php
                                echo logsQuery([
                                    'registered_page' => $i,
                                ]);
                            ?>"
                            class="<?php
                                echo
                                $i === $currentPage
                                ? 'active'
                                : '';
                            ?>"
                        >

                            <?php
                            echo $i;
                            ?>

                        </a>

                    <?php endfor; ?>


                    <?php if (
                        $currentPage < $totalPages
                    ): ?>

                        <a href="?<?php
                            echo logsQuery([
                                'registered_page' => $currentPage + 1,
                            ]);
                        ?>">

                            &gt;

                        </a>

                    <?php else: ?>

                        <a class="disabled">
                            &gt;
                        </a>

                    <?php endif; ?>

                </div>


            </div>

        </section>



        <!-- =================================================
             VISITOR VEHICLES
        ================================================== -->

        <section class="log-section" id="liveVisitorLogs" data-live>


            <div class="section-header">

                <div class="section-title">
                    Visitor Vehicle Logs
                </div>

                <div class="section-count">

                    <?php
                    echo $visitorPagination['total'];
                    ?>

                    record(s)

                </div>

            </div>


            <div class="table-card">


                <!-- TOOLBAR -->

                <div class="table-toolbar">

                    <label class="select-all-box">

                        <input
                            type="checkbox"
                            class="select-all"
                            data-target="visitor"
                        >

                        Select All

                    </label>


                    <button
                        type="submit"
                        name="archive_selected"
                        class="archive-selected"
                    >
                        Archive Selected
                    </button>

                </div>


                <!-- TABLE -->

                <table class="log-table">

                    <thead>

                        <tr>

                            <th class="col-check">

                                <input
                                    type="checkbox"
                                    class="select-all"
                                    data-target="visitor"
                                >

                            </th>

                            <th class="col-number">
                                No.
                            </th>

                            <th class="col-plate">
                                Plate Number
                            </th>

                            <th class="col-time">
                                Date
                            </th>

                            <th class="col-time">
                                Time In
                            </th>

                            <th class="col-time">
                                Time Out
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (
                        count(
                            $visitorPagination['items']
                        ) > 0
                    ): ?>


                        <?php

                        $number =
                            (
                                (
                                    $visitorPagination['page']
                                    - 1
                                )
                                *
                                $rowsPerPage
                            )
                            + 1;

                        ?>


                        <?php foreach (
                            $visitorPagination['items']
                            as $row
                        ): ?>


                            <?php

                            $idString =
                                implode(
                                    ",",
                                    $row['ids']
                                );

                            ?>


                            <tr>


                                <td>

                                    <input
                                        type="checkbox"
                                        name="selected_logs[]"
                                        value="<?php
                                            echo htmlspecialchars(
                                                $idString
                                            );
                                        ?>"
                                        class="log-checkbox visitor-check"
                                    >

                                </td>


                                <td>

                                    <?php
                                    echo $number++;
                                    ?>.

                                </td>


                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $row['plate_number']
                                    );
                                    ?>

                                </td>


                                <td>

                                    <?php
                                    echo formatLogDate($row);
                                    ?>

                                </td>


                                <td>

                                    <?php
                                    echo formatTime(
                                        $row['time_in']
                                    );
                                    ?>

                                </td>


                                <td>

                                    <?php
                                    echo formatTime(
                                        $row['time_out']
                                    );
                                    ?>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                    <?php else: ?>


                        <tr>

                            <td
                                colspan="6"
                                class="empty-row"
                            >

                                No visitor vehicle
                                logs found for today.

                            </td>

                        </tr>


                    <?php endif; ?>


                    </tbody>

                </table>


                <!-- PAGINATION -->

                <div class="pagination">

                    <?php

                    $currentPage =
                        $visitorPagination['page'];

                    $totalPages =
                        $visitorPagination['totalPages'];

                    ?>


                    <?php if ($currentPage > 1): ?>

                        <a href="?<?php
                            echo logsQuery([
                                'visitor_page' => $currentPage - 1,
                            ]);
                        ?>">

                            &lt;

                        </a>

                    <?php else: ?>

                        <a class="disabled">
                            &lt;
                        </a>

                    <?php endif; ?>


                    <?php

                    for (
                        $i = 1;
                        $i <= $totalPages;
                        $i++
                    ):

                    ?>

                        <a
                            href="?<?php
                                echo logsQuery([
                                    'visitor_page' => $i,
                                ]);
                            ?>"
                            class="<?php
                                echo
                                $i === $currentPage
                                ? 'active'
                                : '';
                            ?>"
                        >

                            <?php
                            echo $i;
                            ?>

                        </a>

                    <?php endfor; ?>


                    <?php if (
                        $currentPage < $totalPages
                    ): ?>

                        <a href="?<?php
                            echo logsQuery([
                                'visitor_page' => $currentPage + 1,
                            ]);
                        ?>">

                            &gt;

                        </a>

                    <?php else: ?>

                        <a class="disabled">
                            &gt;
                        </a>

                    <?php endif; ?>

                </div>


            </div>

        </section>


    </form>



    <!-- =================================================
         ARCHIVE
    ================================================== -->

    <?php if ($archiveDate !== ""): ?>


        <section class="archive-area">


            <div class="archive-heading">

                <div>

                    <h2>
                        Vehicle Log Archive
                    </h2>

                    <p>

                        Showing archived logs for
                        <strong>
                            <?php
                            echo date(
                                "F d, Y",
                                strtotime(
                                    $archiveDate
                                )
                            );
                            ?>
                        </strong>

                    </p>

                </div>


                <a
                    href="logs.php?print=1&source=archive&archive_date=<?php
                        echo urlencode(
                            $archiveDate
                        );
                    ?>"
                    target="_blank"
                    class="archive-print"
                >
                    Print Archive Report
                </a>

            </div>


            <?php if (
                count($archiveRegistered) === 0 &&
                count($archiveVisitors) === 0
            ): ?>


                <div class="archive-empty">

                    No archived vehicle logs were
                    found for this date.

                </div>


            <?php else: ?>


                <!-- =====================================
                     ARCHIVED REGISTERED
                ====================================== -->

                <?php if (
                    count($archiveRegistered) > 0
                ): ?>

                    <div class="log-section">


                        <div class="section-header">

                            <div class="section-title">
                                Registered Vehicle Logs
                            </div>

                            <div class="section-count">

                                <?php
                                echo count(
                                    $archiveRegistered
                                );
                                ?>

                                record(s)

                            </div>

                        </div>


                        <div class="table-card">


                            <table class="log-table">

                                <thead>

                                    <tr>

                                        <th>
                                            No.
                                        </th>

                                        <th>
                                            Plate Number
                                        </th>

                                        <th>
                                            Date
                                        </th>

                                        <th>
                                            Time In
                                        </th>

                                        <th>
                                            Time Out
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>

                                <?php

                                $number = 1;

                                foreach (
                                    $archiveRegistered
                                    as $row
                                ):

                                ?>

                                    <tr>

                                        <td>
                                            <?php
                                            echo $number++;
                                            ?>.
                                        </td>

                                        <td>
                                            <?php
                                            echo htmlspecialchars(
                                                $row['plate_number']
                                            );
                                            ?>
                                        </td>

                                        <td>
                                            <?php
                                            echo formatLogDate($row);
                                            ?>
                                        </td>

                                        <td>
                                            <?php
                                            echo formatTime(
                                                $row['time_in']
                                            );
                                            ?>
                                        </td>

                                        <td>
                                            <?php
                                            echo formatTime(
                                                $row['time_out']
                                            );
                                            ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                <?php endif; ?>


                <!-- =====================================
                     ARCHIVED VISITORS
                ====================================== -->

                <?php if (
                    count($archiveVisitors) > 0
                ): ?>

                    <div class="log-section">


                        <div class="section-header">

                            <div class="section-title">
                                Visitor Vehicle Logs
                            </div>

                            <div class="section-count">

                                <?php
                                echo count(
                                    $archiveVisitors
                                );
                                ?>

                                record(s)

                            </div>

                        </div>


                        <div class="table-card">


                            <table class="log-table">

                                <thead>

                                    <tr>

                                        <th>
                                            No.
                                        </th>

                                        <th>
                                            Plate Number
                                        </th>

                                        <th>
                                            Date
                                        </th>

                                        <th>
                                            Time In
                                        </th>

                                        <th>
                                            Time Out
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>

                                <?php

                                $number = 1;

                                foreach (
                                    $archiveVisitors
                                    as $row
                                ):

                                ?>

                                    <tr>

                                        <td>
                                            <?php
                                            echo $number++;
                                            ?>.
                                        </td>

                                        <td>
                                            <?php
                                            echo htmlspecialchars(
                                                $row['plate_number']
                                            );
                                            ?>
                                        </td>

                                        <td>
                                            <?php
                                            echo formatLogDate($row);
                                            ?>
                                        </td>

                                        <td>
                                            <?php
                                            echo formatTime(
                                                $row['time_in']
                                            );
                                            ?>
                                        </td>

                                        <td>
                                            <?php
                                            echo formatTime(
                                                $row['time_out']
                                            );
                                            ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                <?php endif; ?>


            <?php endif; ?>


        </section>

    <?php endif; ?>


</main>



<script>

/* =========================================================
   SEARCH / SORT NAVIGATION
========================================================= */

(function() {

    var baseUrl = window.location.pathname;

    function currentParams() {

        var searchParams =
            new URLSearchParams(window.location.search);

        var params = {};

        searchParams.forEach(function(value, key) {

            if (
                key !== "registered_page"
                && key !== "visitor_page"
                && key !== "archive_registered_page"
                && key !== "archive_visitor_page"
                && key !== "filter"
            ) {
                params[key] = value;
            }

        });

        return params;
    }


    function buildUrl(changes) {

        var params = currentParams();

        Object.keys(changes).forEach(function(key) {

            var value = changes[key];

            if (value === "" || value === null) {
                delete params[key];
            } else {
                params[key] = value;
            }

        });

        var query =
            Object.keys(params)
            .map(function(key) {
                return encodeURIComponent(key) +
                    "=" +
                    encodeURIComponent(params[key]);
            })
            .join("&");

        return baseUrl + (query ? "?" + query : "");
    }


    var logSearch = document.getElementById("logSearch");

    var clearFilters = document.getElementById("clearFilters");


    if (logSearch) {

        var timer = null;

        logSearch.addEventListener("input", function() {

            clearTimeout(timer);

            timer = setTimeout(function() {

                var url = buildUrl({
                    "q": logSearch.value.trim()
                });

                window.location.href = url;

            }, 400);

        });

    }


    /* Sort dropdown: toggle the menu, close it on an
       outside click or Escape, and navigate from the
       menu items (fields keep the current direction,
       directions keep the current field). */

    var sortToggle = document.getElementById("sortToggle");

    var sortMenu = document.getElementById("sortMenu");

    if (sortToggle && sortMenu) {

        sortToggle.addEventListener("click", function(event) {

            event.stopPropagation();

            var isOpen = sortMenu.classList.toggle("sort-open");

            sortToggle.setAttribute(
                "aria-expanded",
                isOpen ? "true" : "false"
            );

        });


        document.addEventListener("click", function(event) {

            if (
                !sortMenu.contains(event.target)
                && event.target !== sortToggle
            ) {
                sortMenu.classList.remove("sort-open");

                sortToggle.setAttribute("aria-expanded", "false");
            }

        });


        document.addEventListener("keydown", function(event) {

            if (event.key === "Escape") {

                sortMenu.classList.remove("sort-open");

                sortToggle.setAttribute("aria-expanded", "false");
            }

        });


        sortMenu.querySelectorAll(
            "a[data-sort], a[data-dir]"
        ).forEach(function(link) {

            link.addEventListener("click", function(event) {

                event.preventDefault();

                var changes = {};

                if (link.hasAttribute("data-sort")) {
                    changes.sort = link.getAttribute("data-sort");
                }

                if (link.hasAttribute("data-dir")) {

                    changes.dir = link.getAttribute("data-dir");

                    /* Keep the active field when picking a
                       direction; only fall back to "time"
                       if no valid field is currently set. */
                    var searchParams =
                        new URLSearchParams(window.location.search);

                    var activeSort = searchParams.get("sort") || "";

                    if (
                        ["date", "time"]
                            .indexOf(activeSort) === -1
                    ) {
                        activeSort = "time";
                    }

                    changes.sort = activeSort;
                }

                window.location.href = buildUrl(changes);

            });

        });

    }

})();


/* =========================================================
   SELECT ALL
   (event delegation: keeps working after auto-refresh
    replaces the table rows)
========================================================= */

document.addEventListener(
    "change",
    function(event) {

        const selectAll = event.target;

        if (!selectAll.classList.contains("select-all")) {
            return;
        }

        const target =
            selectAll.dataset.target;

        const checkboxes =
            document.querySelectorAll(
                "." +
                target +
                "-check"
            );


        checkboxes.forEach(
            function(checkbox) {

                checkbox.checked =
                    selectAll.checked;

            }
        );


        /*
         * Keep the other Select All checkbox
         * synchronized.
         */

        document.querySelectorAll(
            '.select-all[data-target="' +
            target +
            '"]'
        ).forEach(
            function(other) {

                other.checked =
                    selectAll.checked;

            }
        );

    }
);


/* =========================================================
   UNCHECK SELECT ALL WHEN ONE IS UNCHECKED
========================================================= */

document.addEventListener(
    "change",
    function(event) {

        const checkbox = event.target;

        if (!checkbox.classList.contains("log-checkbox")) {
            return;
        }

        const className =
            checkbox.classList.contains(
                "registered-check"
            )
            ? "registered"
            : "visitor";


        const all =
            document.querySelectorAll(
                "." +
                className +
                "-check"
            );


        const selectAll =
            document.querySelectorAll(
                '.select-all[data-target="' +
                className +
                '"]'
            );


        const checkedCount =
            document.querySelectorAll(
                "." +
                className +
                "-check:checked"
            ).length;


        selectAll.forEach(
            function(box) {

                box.checked =
                    all.length > 0 &&
                    checkedCount ===
                    all.length;

            }
        );

    }
);


/* =========================================================
   CONFIRM ARCHIVE
========================================================= */

document.querySelectorAll(
    "#archiveForm"
).forEach(function(form) {

    form.addEventListener(
        "submit",
        function(event) {

            const selected =
                document.querySelectorAll(
                    ".log-checkbox:checked"
                );


            if (selected.length === 0) {

                event.preventDefault();

                alert(
                    "Please select at least one vehicle log to archive."
                );

                return;
            }


            const confirmed =
                confirm(
                    "Are you sure you want to archive " +
                    selected.length +
                    " selected log(s)?"
                );


            if (!confirmed) {

                event.preventDefault();

            }

        }
    );

});

</script>

<!-- Live auto-refresh (no full page reload) -->
<script>window.AUTO_REFRESH_SECONDS = 5;</script>
<script src="auto_refresh.js"></script>


</body>

</html>
