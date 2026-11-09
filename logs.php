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
========================================================= */

$conn = secsysConnectDb();
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

    foreach (['period', 'period_date', 'period_month'] as $periodKey) {
        if (($_GET[$periodKey] ?? '') !== '') {
            $params[$periodKey] = $_GET[$periodKey];
        }
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
   UNARCHIVE (move archived logs from today back to the
   active list; past days stay archived)
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'unarchive_selected'
) {
    $actorId = trim((string)($_SESSION['username'] ?? 'Administrator'));
    $unarchive = secsysUnarchiveLogs($conn, secsysPostedLogIds(), $actorId, null);
    header("Location: logs.php?" . ($unarchive['error'] !== null
        ? 'unarchived=error'
        : 'unarchived=' . (int)$unarchive['restored'] . '&kept=' . (int)$unarchive['kept_past']));
    exit();
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
                (" . SECSYS_VEHICLE_LOG_COLUMNS . ", archived_at, archived_by)
                SELECT " . SECSYS_VEHICLE_LOG_COLUMNS . ", NOW(), ?
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

/* Archive modal filters (same model as registered.php / visitor.php):
   Daily (one date) or Monthly (Jan-Dec of the current year) + search. */
$archiveMonthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

$archiveBasis = (($_GET['archive_basis'] ?? '') === 'monthly') ? 'monthly' : 'daily';

$archiveDate = getArchiveDate();
if ($archiveDate === '' || $archiveDate > $today) {
    $archiveDate = $today;
}

$archiveMonth = isset($_GET['archive_month']) ? (int)$_GET['archive_month'] : (int)date('n');
if ($archiveMonth < 1 || $archiveMonth > 12) {
    $archiveMonth = (int)date('n');
}
$archiveYear = (int)date('Y');

$archiveSearch = trim((string)($_GET['archive_q'] ?? ''));
if (function_exists('mb_substr')) {
    $archiveSearch = mb_substr($archiveSearch, 0, 60);
} else {
    $archiveSearch = substr($archiveSearch, 0, 60);
}

$showArchive =
    isset($_GET['show_archive'])
    || isset($_GET['archive_basis'])
    || isset($_GET['archive_date'])
    || isset($_GET['archive_month'])
    || isset($_GET['archive_q']);

$archiveDisplayLabel = $archiveBasis === 'monthly'
    ? $archiveMonthNames[$archiveMonth] . ' ' . $archiveYear
    : date('F d, Y', strtotime($archiveDate));

/* Query string for links inside the archive modal */
function archiveQuery($changes = [])
{
    global $archiveBasis, $archiveDate, $archiveMonth, $archiveSearch;

    $params = [
        'show_archive'            => 1,
        'archive_basis'           => $archiveBasis,
        'archive_date'            => $archiveDate,
        'archive_month'           => $archiveMonth,
        'archive_q'               => $archiveSearch,
        'archive_registered_page' => $_GET['archive_registered_page'] ?? 1,
        'archive_visitor_page'    => $_GET['archive_visitor_page'] ?? 1,
    ];

    foreach ($changes as $key => $value) {
        $params[$key] = $value;
    }

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }

    return http_build_query($params);
}


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
   DAILY / MONTHLY VIEW
   Today (default) = live logs only. Any other day or a month =
   live logs in that range + logs already moved to the archive
   (archived rows are shown read-only).
========================================================= */

$listPeriod = secsysListPeriod('daily', false);

if (!$listPeriod['is_today']) {

    $periodRows = [];

    if ($listPeriod['has_today']) {
        $stmtActive = $conn->prepare("
            SELECT vl.*, rv.owner_name
            FROM vehicle_logs vl
            LEFT JOIN registered_vehicles rv ON vl.registered_vehicle_id = rv.id
            WHERE vl.date_created >= ? AND vl.date_created < ?
        ");
        $stmtActive->bind_param("ss", $listPeriod['start'], $listPeriod['end']);
        $stmtActive->execute();
        $resActive = $stmtActive->get_result();
        while ($r = $resActive->fetch_assoc()) {
            $periodRows[] = $r;
        }
        $stmtActive->close();
    }

    $stmtArchived = $conn->prepare("
        SELECT va.*, rv.owner_name
        FROM vehicle_logs_archive va
        LEFT JOIN registered_vehicles rv ON va.registered_vehicle_id = rv.id
        WHERE va.date_created >= ? AND va.date_created < ?
    ");
    $stmtArchived->bind_param("ss", $listPeriod['start'], $listPeriod['end']);
    $stmtArchived->execute();
    $resArchived = $stmtArchived->get_result();
    while ($r = $resArchived->fetch_assoc()) {
        if (empty($r['archived_at'])) {
            $r['archived_at'] = $r['date_created'];   // mark as archived (read-only)
        }
        $periodRows[] = $r;
    }
    $stmtArchived->close();

    $resultLogs = $periodRows;
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
    if (is_array($result)) {
        $rows = $result;                     // already-fetched rows (Daily / Monthly view)
    } elseif ($result) {
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
                "vehicle_image" => $row['vehicle_image'] ?? null,
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
                    if (empty($sessions[$sessionIndex]['vehicle_image']) && !empty($row['vehicle_image'])) {
                        $sessions[$sessionIndex]['vehicle_image'] = $row['vehicle_image'];
                    }
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
                    "vehicle_image" => $row['vehicle_image'] ?? null,
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

        $sessions = array_values(
            array_filter(
                $sessions,
                function ($s) use ($searchQuery) {
                    return secsysMatchesPlateSearch(
                        $searchQuery,
                        (string)($s['plate_number'] ?? ''),
                        (string)($s['name'] ?? ''),
                        (string)($s['vehicle_type'] ?? ''),
                        (string)($s['purpose'] ?? '')
                    );
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

$isArchivePrint =
    (($_GET['print'] ?? '') === '1')
    && (($_GET['source'] ?? '') === 'archive');


/* Search inside the archive: plate, name, vehicle type or purpose */
function archiveSessionMatches($session, $needle)
{
    if ($needle === '') {
        return true;
    }

    return secsysMatchesPlateSearch(
        (string)$needle,
        (string)($session['plate_number'] ?? ''),
        (string)($session['name'] ?? ''),
        (string)($session['vehicle_type'] ?? ''),
        (string)($session['purpose'] ?? '')
    );
}


if ($showArchive || $isArchivePrint) {

    if ($archiveBasis === 'monthly') {

        $stmt = $conn->prepare("
            SELECT
                va.*,
                rv.owner_name
            FROM vehicle_logs_archive va
            LEFT JOIN registered_vehicles rv
                ON va.registered_vehicle_id = rv.id
            WHERE YEAR(va.date_created) = ?
              AND MONTH(va.date_created) = ?
            ORDER BY va.date_created DESC
        ");

        $stmt->bind_param("ii", $archiveYear, $archiveMonth);

    } else {

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

        $stmt->bind_param("s", $archiveDate);
    }

    $stmt->execute();

    $archiveSessions = buildSessions($stmt->get_result());

    $stmt->close();

    foreach ($archiveSessions as $session) {

        if (!archiveSessionMatches($session, $archiveSearch)) {
            continue;
        }

        if (strtolower($session['category']) === "registered") {
            $archiveRegistered[] = $session;
        } else {
            $archiveVisitors[] = $session;
        }
    }

    /* Newest first */
    $byTimeInDesc = function ($x, $y) {
        return strtotime($y['time_in'] ?? $y['time_out'] ?? '1970-01-01')
             <=> strtotime($x['time_in'] ?? $x['time_out'] ?? '1970-01-01');
    };

    usort($archiveRegistered, $byTimeInDesc);
    usort($archiveVisitors, $byTimeInDesc);
}


/* =========================================================
   ARCHIVE PAGINATION
========================================================= */

$archiveRegisteredPagination =
    paginate(
        $archiveRegistered,
        isset($_GET['archive_registered_page']) ? (int)$_GET['archive_registered_page'] : 1,
        $rowsPerPage
    );

$archiveVisitorPagination =
    paginate(
        $archiveVisitors,
        isset($_GET['archive_visitor_page']) ? (int)$_GET['archive_visitor_page'] : 1,
        $rowsPerPage
    );


/* =========================================================
   PRINT MODE
========================================================= */

/* Download PDF (logs.php?download=pdf&source=...) uses the same data as Print */
$isPdfDownload =
    ($_GET['download'] ?? '') === 'pdf';

$isPrint =
    (isset($_GET['print']) &&
    $_GET['print'] === "1")
    || $isPdfDownload;


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

        $printDateLabel =
            $archiveDisplayLabel
            . ($archiveSearch !== '' ? ' (search: "' . $archiveSearch . '")' : '');

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

        $printDateLabel =
            $listPeriod['is_today']
            ? date("F d, Y", strtotime($today))
            : $listPeriod['label'];

    }

}



/* =========================================================
   PRINT REPORT (same layout as the Visitor Vehicle Report)
   logs.php?print=1&source=current | archive
========================================================= */
if ($isPrint) {
    require_once __DIR__ . '/app/print_report.php';

    $printSummary = secsysBuildVehicleSummary(array_merge($printRegistered, $printVisitors));
    $printPlateStats = $printSummary['per_plate'];

    $printColumns = [
        ['#', 4], ['Plate Number', 13], ['Name', 16], ['Vehicle Type', 11],
        ['Date', 11], ['Time In', 11], ['Time Out', 11], ['Entries / Exits', 13], ['Status', 10],
    ];
    $printRows = function (array $sessions) use ($printPlateStats): array {
        $rows = [];
        $number = 1;
        foreach ($sessions as $row) {
            $pKey = strtoupper(trim((string)($row['plate_number'] ?? '')));
            $pIn  = (int)($printPlateStats[$pKey]['in_count'] ?? (!empty($row['time_in']) ? 1 : 0));
            $pOut = (int)($printPlateStats[$pKey]['out_count'] ?? (!empty($row['time_out']) ? 1 : 0));
            $rows[] = [
                ['text' => $number++, 'class' => 'center'],
                $row['plate_number'] ?? '-',
                $row['name'] ?? '-',
                $row['vehicle_type'] ?? '-',
                ['text' => formatLogDate($row), 'class' => 'center'],
                ['text' => formatTime($row['time_in'] ?? null), 'class' => 'center'],
                ['text' => formatTime($row['time_out'] ?? null), 'class' => 'center'],
                ['text' => $pIn . ' IN / ' . $pOut . ' OUT', 'class' => 'center'],
                ['text' => $row['status'] ?? '-', 'class' => 'center'],
            ];
        }
        return $rows;
    };

    $isArchiveReport = ($printSource === 'archive');
    $reportName = $isArchiveReport ? 'Archived Vehicle Logs Report' : 'Vehicle Logs Report';

    $body  = secsysPrintTable($printColumns, $printRows($printRegistered), 'No registered vehicle logs.', 'Registered Vehicle Logs (' . count($printRegistered) . ')');
    $body .= secsysPrintTable($printColumns, $printRows($printVisitors), 'No visitor vehicle logs.', 'Visitor Vehicle Logs (' . count($printVisitors) . ')');

    $reportMeta   = [($isArchiveReport ? 'Archive Period: ' : 'Period: ') . $printDateLabel];
    $activePrintSearch = $isArchiveReport ? $archiveSearch : $searchQuery;
    if ($activePrintSearch !== '') {
        $reportMeta[] = 'Search Plate "' . $activePrintSearch . '": Entered (IN) ' . (int)$printSummary['total_in'] . ' time(s) | Exited (OUT) ' . (int)$printSummary['total_out'] . ' time(s)';
    }
    $reportFooter = $isArchiveReport ? 'Archived Vehicle Logs Monitoring Report' : 'Vehicle Logs Monitoring Report';

    if ($isPdfDownload) {
        // e.g. vehicle_logs_report_2026-11-04.pdf / archived_vehicle_logs_report_2026-11.pdf
        $fileStamp = preg_replace('/[^0-9A-Za-z_-]+/', '-', (string)$printDate);
        if ($isArchiveReport && ($archiveBasis ?? 'daily') === 'monthly') {
            $fileStamp = sprintf('%04d-%02d', (int)$archiveYear, (int)$archiveMonth);
        } elseif (!$isArchiveReport && !$listPeriod['is_today']) {
            $fileStamp = $listPeriod['basis'] === 'monthly'
                ? sprintf('%04d-%02d', (int)$listPeriod['year'], (int)$listPeriod['month'])
                : $listPeriod['date'];
        }
        $downloadName = ($isArchiveReport ? 'archived_vehicle_logs_report_' : 'vehicle_logs_report_') . $fileStamp;
        if (secsysWantsCsvDownload()) {
            // CSV option: same rows as the PDF
            secsysStreamCsvFromHtml(
                secsysBuildPrintDocumentHtml($reportName, $reportMeta, $body, $reportFooter, false),
                $downloadName
            );
            exit;
        }
        secsysStreamPdfReport(
            $reportName,
            $reportMeta,
            $body,
            $reportFooter,
            $downloadName . '.pdf'
        );
        exit;
    }

    secsysRenderPrintDocument($reportName, $reportMeta, $body, $reportFooter);
    exit;
}

?>

<!DOCTYPE html>

<html lang="en" class="ui-scale-fixed">

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

    /* sits right after Print Report / Archive Selected */
    margin-left: 0;
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


/* =========================================================
   ONE WIDTH FOR THE WHOLE PAGE
   Buttons, search filter, Daily / Monthly bar and the log
   tables all line up (tables use max-width 1080px).
========================================================= */

.main > .action-bar,
.main > .search-filter-bar,
.main > .period-filter,
.main > #liveLogsPlateSearchSummary {
    width: 100%;
    max-width: 1080px;
    box-sizing: border-box;
}

/* =========================================================
   ARCHIVE WINDOW (same look as registered.php / visitor.php)
========================================================= */

.modal {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    padding: 20px;
}

.modal.show {
    display: flex;
}

.modal-content {
    max-width: 95%;
    max-height: 90vh;
    overflow-y: auto;
    background: white;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 10px 35px rgba(0, 0, 0, 0.25);
    animation: archiveModalIn 0.18s ease-out;
    box-sizing: border-box;
}

.modal-archive {
    width: 96%;
    max-width: 1250px;
    border-radius: 20px;
    padding: 22px;
}

@keyframes archiveModalIn {
    from { transform: translateY(-15px); opacity: 0; }
    to   { transform: translateY(0); opacity: 1; }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-title {
    font-size: 20px;
    font-weight: 400;
}

.archive-subheading {
    font-size: 11px;
    color: #777;
    padding: 4px 0 0;
}

.close-modal {
    border: none;
    background: none;
    font-size: 25px;
    cursor: pointer;
    color: #666;
}

.close-modal:hover {
    color: #111;
}

.archive-loading .modal-content {
    opacity: 0.65;
    transition: opacity 0.15s ease;
}

.archive-toolbar-card {
    margin-bottom: 14px;
    padding: 14px 18px;
}

.archive-toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.archive-filter-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.archive-basis-toggle {
    display: inline-flex;
    background: #eef4fc;
    border: 1px solid #c8dcf7;
    border-radius: 22px;
    padding: 3px;
    gap: 4px;
}

.archive-basis-toggle button {
    border: none;
    height: 32px;
    padding: 0 16px;
    border-radius: 18px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    background: transparent;
    color: #1769d3;
}

.archive-basis-toggle button.active {
    background: #2f80ed;
    color: #ffffff;
}

.archive-picker {
    align-items: center;
    gap: 8px;
}

.archive-date-label {
    font-size: 12px;
    color: #555;
}

#archivePanel .archive-date {
    height: 36px;
    border: 1px solid #2f80ed;
    border-radius: 22px;
}

.archive-search-box {
    flex: 1 1 240px;
    min-width: 200px;
    max-width: 380px;
    height: 36px;
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid #2f80ed;
    border-radius: 22px;
    padding: 0 14px;
    background: #ffffff;
    box-sizing: border-box;
}

.archive-search-box:focus-within {
    box-shadow: 0 0 0 3px rgba(47, 128, 237, 0.18);
}

.archive-search-icon {
    font-size: 12px;
    opacity: 0.7;
}

.archive-search {
    flex: 1;
    min-width: 0;
    border: none;
    outline: none;
    font-size: 12px;
    color: #333;
    background: transparent;
    height: 100%;
}

.archive-print-btn {
    margin-left: auto;
    height: 36px;
    display: inline-flex;
    align-items: center;
    padding: 0 16px;
    border-radius: 20px;
    background: #2f80ed;
    color: #ffffff;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}

.archive-print-btn:hover {
    background: #1769d3;
}

.archive-log-section {
    margin-top: 6px;
    margin-bottom: 14px;
}

.archive-log-header {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin: 0 4px 8px;
}

.archive-log-title {
    font-size: 15px;
    font-weight: 600;
    color: #222;
}

.archive-log-count {
    font-size: 11px;
    color: #777;
}

.archive-table-card {
    margin-bottom: 0;
    overflow: hidden;
}

.archive-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.archive-plate {
    font-family: "Courier New", monospace;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.archive-purpose {
    white-space: normal;
    max-width: 240px;
}

.archive-empty-row td {
    text-align: center;
    color: #888;
    padding: 22px 12px;
}

@media print {
    #archivePanel {
        display: none !important;
    }
}

</style>

<link rel="stylesheet" href="assets/css/period_filter.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/period_filter.css'); ?>">

<!-- Same look as the Security Guard dashboard for every Administrator page -->
<link rel="stylesheet" href="assets/css/admin_uniform.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/admin_uniform.css'); ?>">
<!-- Enlarges the page up to 25% on desktop for easier reading (loaded after the page styles) -->
<link rel="stylesheet" href="assets/css/ui_scale.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/ui_scale.css'); ?>">

</head>


<body>


<?php /* print view: see "PRINT REPORT" block above <!DOCTYPE html> */ ?>


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

    <?php echo secsysUnarchiveNoticeHtml('logs.php', 'vehicle'); ?>


    <!-- =================================================
         ACTION BAR
    ================================================== -->

    <div class="action-bar">


        <!-- PRINT CURRENT LOGS -->

        <a
            href="logs.php?<?php echo logsQuery(['print' => '1', 'source' => 'current']); ?>"
            onclick="return printLogsReport(this.href);"
            class="action-button"
        >
            🖨️ Print Report
        </a>


        <!-- DOWNLOAD PDF (same report as Print, saved as a file) -->

        <a
            href="logs.php?<?php echo logsQuery(['download' => 'pdf', 'source' => 'current']); ?>"
            class="action-button download"
            title="Download the logs report as PDF or CSV"
            data-download-choice
        >
            📥 Download ▾
        </a>


        <!-- ARCHIVE (opens the archive window, same as Registered / Visitor) -->

        <div class="archive-date-box">
            <button
                type="button"
                class="view-archive"
                onclick="toggleArchive()"
            >
                📦 Archive
            </button>
        </div>


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

    <!-- DAILY / MONTHLY VIEW -->
    <?php
    echo secsysRenderPeriodFilter($listPeriod, 'logs.php', [
        'q'         => $_GET['q'] ?? '',
        'status'    => $_GET['status'] ?? '',
        'time_from' => $_GET['time_from'] ?? '',
        'time_to'   => $_GET['time_to'] ?? '',
        'sort'      => $_GET['sort'] ?? '',
        'dir'       => $_GET['dir'] ?? '',
    ]);

    $logsPeriodStartDate = ($listPeriod['basis'] === 'monthly')
        ? sprintf('%04d-%02d-01', (int)$listPeriod['year'], (int)$listPeriod['month'])
        : $listPeriod['date'];
    $logsPeriodEndDate = ($listPeriod['basis'] === 'monthly')
        ? date('Y-m-t', strtotime($logsPeriodStartDate))
        : $listPeriod['date'];
    $activeLogsVehicleSummary = secsysBuildVehicleSummary(array_merge($registeredSessions, $visitorSessions));
    $activeLogsPlateStats     = $activeLogsVehicleSummary['per_plate_map'] ?? $activeLogsVehicleSummary['per_plate'];
    // Enrich row pills with full period DB movement counts so archived visits from the same day are also reflected
    $knownActivePlates = [];
    foreach ($activeLogsVehicleSummary['per_plate'] as $pi) {
        if (!empty($pi['plate_number']) && $pi['plate_number'] !== '-') {
            $knownActivePlates[] = $pi['plate_number'];
        }
    }
    if (!empty($knownActivePlates) || $searchQuery !== '') {
        $dbActiveCounts = secsysQueryPlateMovementCounts($conn, $searchQuery, $knownActivePlates, $logsPeriodStartDate, $logsPeriodEndDate);
        foreach ($dbActiveCounts as $pk => $dbc) {
            $rawUp = strtoupper(trim((string)($dbc['plate_number'] ?? '')));
            foreach ([$pk, $rawUp] as $lookupKey) {
                if ($lookupKey === '') {
                    continue;
                }
                if (isset($activeLogsPlateStats[$lookupKey])) {
                    $activeLogsPlateStats[$lookupKey]['in_count']  = max((int)$activeLogsPlateStats[$lookupKey]['in_count'], (int)$dbc['period_in']);
                    $activeLogsPlateStats[$lookupKey]['out_count'] = max((int)$activeLogsPlateStats[$lookupKey]['out_count'], (int)$dbc['period_out']);
                } else {
                    $activeLogsPlateStats[$lookupKey] = [
                        'in_count'  => (int)$dbc['period_in'],
                        'out_count' => (int)$dbc['period_out'],
                    ];
                }
            }
        }
    }
    ?>

    <style>
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
    </style>

    <!-- PLATE SEARCH ENTRY & EXIT COUNTER BANNER -->
    <div id="liveLogsPlateSearchSummary" data-live>
        <?php if ($searchQuery !== ''): ?>
            <?php echo secsysRenderPlateSearchSummaryHtml(
                $searchQuery,
                array_merge($registeredSessions, $visitorSessions),
                $listPeriod['label'],
                $conn,
                $logsPeriodStartDate,
                $logsPeriodEndDate
            ); ?>
        <?php endif; ?>
    </div>

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

                            <th class="col-time">
                                Entries / Exits
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
                            $pKey = strtoupper(trim((string)($row['plate_number'] ?? '')));
                            $pIn  = (int)($activeLogsPlateStats[$pKey]['in_count'] ?? (!empty($row['time_in']) ? 1 : 0));
                            $pOut = (int)($activeLogsPlateStats[$pKey]['out_count'] ?? (!empty($row['time_out']) ? 1 : 0));

                            ?>


                            <tr>


                                <!-- CHECKBOX -->

                                <td>

                                    <?php if (empty($row['archived_at'])): ?>
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
                                    <?php else: ?>
                                    <span class="archived-row-badge" title="Already in the archive" aria-label="Already in the archive">📦</span>
                                    <?php endif; ?>

                                </td>


                                <!-- NUMBER -->

                                <td>

                                    <?php
                                    echo $number++;
                                    ?>.

                                </td>


                                <!-- PLATE -->

                                <td>

                                    <?php echo secsysClickablePlateHtml($row); ?>

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


                                <!-- ENTRIES / EXITS -->

                                <td>
                                    <?php echo secsysPlateEntryExitPillHtml($pIn, $pOut); ?>
                                </td>


                            </tr>


                        <?php endforeach; ?>


                    <?php else: ?>


                        <tr>

                            <td
                                colspan="7"
                                class="empty-row"
                            >

                                No registered vehicle
                                logs found for <?php echo htmlspecialchars($listPeriod['is_today'] ? 'today' : $listPeriod['label']); ?>.

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

                            <th class="col-time">
                                Entries / Exits
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
                            $pKey = strtoupper(trim((string)($row['plate_number'] ?? '')));
                            $pIn  = (int)($activeLogsPlateStats[$pKey]['in_count'] ?? (!empty($row['time_in']) ? 1 : 0));
                            $pOut = (int)($activeLogsPlateStats[$pKey]['out_count'] ?? (!empty($row['time_out']) ? 1 : 0));

                            ?>


                            <tr>


                                <td>

                                    <?php if (empty($row['archived_at'])): ?>
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
                                    <?php else: ?>
                                    <span class="archived-row-badge" title="Already in the archive" aria-label="Already in the archive">📦</span>
                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php
                                    echo $number++;
                                    ?>.

                                </td>


                                <td>

                                    <?php echo secsysClickablePlateHtml($row); ?>

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
                                    <?php echo secsysPlateEntryExitPillHtml($pIn, $pOut); ?>
                                </td>


                            </tr>


                        <?php endforeach; ?>


                    <?php else: ?>


                        <tr>

                            <td
                                colspan="7"
                                class="empty-row"
                            >

                                No visitor vehicle
                                logs found for <?php echo htmlspecialchars($listPeriod['is_today'] ? 'today' : $listPeriod['label']); ?>.

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
         ARCHIVE WINDOW
         Same layout as the Registered / Visitor archive:
         Daily / Monthly filter, search, print, paginated tables.
    ================================================== -->

    <?php
    $archiveCombinedSummary = secsysBuildVehicleSummary(array_merge($archiveRegistered, $archiveVisitors));
    $archivePlateStats      = $archiveCombinedSummary['per_plate'];

    function renderArchiveTable($title, $pagination, $pageParam, $isVisitor, $emptyLabel, $archivePlateStats = [])
    {
        $items = $pagination['items'];
        global $rowsPerPage;
        $start = ($pagination['page'] - 1) * $rowsPerPage;
        ?>
        <div class="archive-log-section">

            <div class="archive-log-header">
                <div class="archive-log-title"><?php echo htmlspecialchars($title); ?></div>
                <div class="archive-log-count"><?php echo (int)$pagination['total']; ?> record(s)</div>
            </div>

            <div class="table-card archive-table-card">
                <div class="archive-table-wrapper">
                    <table class="log-table archive-log-table">
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Plate Number</th>
                                <th><?php echo $isVisitor ? 'Visitor' : 'Owner'; ?></th>
                                <th>Vehicle Type</th>
                                <?php if ($isVisitor): ?><th>Purpose</th><?php endif; ?>
                                <th>Date</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Entries / Exits</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($items) === 0): ?>
                            <tr class="archive-empty-row">
                                <td colspan="<?php echo $isVisitor ? 10 : 9; ?>">
                                    <?php echo htmlspecialchars($emptyLabel); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $index => $row): ?>
                                <?php
                                    $pKey = strtoupper(trim((string)($row['plate_number'] ?? '')));
                                    $pIn  = (int)($archivePlateStats[$pKey]['in_count'] ?? (!empty($row['time_in']) ? 1 : 0));
                                    $pOut = (int)($archivePlateStats[$pKey]['out_count'] ?? (!empty($row['time_out']) ? 1 : 0));
                                ?>
                                <tr>
                                    <td><?php echo $start + $index + 1; ?>.</td>
                                    <td><?php echo secsysClickablePlateHtml($row, [], 'archive-plate'); ?></td>
                                    <td><?php echo htmlspecialchars($row['name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($row['vehicle_type'] ?? '-'); ?></td>
                                    <?php if ($isVisitor): ?>
                                        <td class="archive-purpose"><?php echo htmlspecialchars(($row['purpose'] ?? '') !== '' ? $row['purpose'] : '-'); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo formatLogDate($row); ?></td>
                                    <td><?php echo formatTime($row['time_in']); ?></td>
                                    <td><?php echo formatTime($row['time_out']); ?></td>
                                    <td><?php echo secsysPlateEntryExitPillHtml($pIn, $pOut); ?></td>
                                    <td><?php echo secsysUnarchiveButtonHtml('logs.php', $row); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($pagination['totalPages'] > 1): ?>
                    <div class="pagination archive-pagination">
                        <?php $cp = $pagination['page']; $tp = $pagination['totalPages']; ?>
                        <?php if ($cp > 1): ?>
                            <a href="logs.php?<?php echo archiveQuery([$pageParam => $cp - 1]); ?>">&lt;</a>
                        <?php else: ?>
                            <a class="disabled">&lt;</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $tp; $i++): ?>
                            <a href="logs.php?<?php echo archiveQuery([$pageParam => $i]); ?>"
                               class="<?php echo $i === $cp ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($cp < $tp): ?>
                            <a href="logs.php?<?php echo archiveQuery([$pageParam => $cp + 1]); ?>">&gt;</a>
                        <?php else: ?>
                            <a class="disabled">&gt;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    $archiveEmptySuffix = $archiveSearch !== ''
        ? ' matching "' . $archiveSearch . '"'
        : '';
    ?>

    <div
        class="modal <?php echo $showArchive ? 'show' : ''; ?>"
        id="archivePanel"
    >

        <div class="modal-content modal-archive">

            <div class="modal-header" style="margin-bottom: 14px;">
                <div>
                    <div class="modal-title">
                        Vehicle Log Archive
                    </div>
                    <div class="archive-subheading">
                        Archived logs for
                        <strong><?php echo htmlspecialchars($archiveDisplayLabel); ?></strong>
                        <?php if ($archiveSearch !== ''): ?>
                            &bull; matching <strong>"<?php echo htmlspecialchars($archiveSearch); ?>"</strong>
                        <?php endif; ?>
                    </div>
                </div>

                <button
                    type="button"
                    class="close-modal"
                    onclick="closeArchive()"
                    aria-label="Close archive"
                >
                    ×
                </button>
            </div>

            <div class="table-card archive-toolbar-card">

                <div class="archive-toolbar">

                    <!-- DAILY OR MONTHLY FILTER -->
                    <div class="archive-filter-group">

                        <input type="hidden" id="archiveBasis" value="<?php echo htmlspecialchars($archiveBasis); ?>">

                        <div class="archive-basis-toggle">
                            <button
                                type="button"
                                id="btnBasisDaily"
                                class="<?php echo $archiveBasis === 'daily' ? 'active' : ''; ?>"
                                onclick="setArchiveBasis('daily')"
                            >
                                📅 Daily
                            </button>
                            <button
                                type="button"
                                id="btnBasisMonthly"
                                class="<?php echo $archiveBasis === 'monthly' ? 'active' : ''; ?>"
                                onclick="setArchiveBasis('monthly')"
                            >
                                🗓️ Monthly
                            </button>
                        </div>

                        <!-- DAILY CALENDAR PICKER -->
                        <div id="dailyPickerBox" class="archive-picker" style="display:<?php echo $archiveBasis === 'daily' ? 'flex' : 'none'; ?>;">
                            <span class="archive-date-label">Select Date:</span>
                            <input
                                type="date"
                                id="archiveDate"
                                class="archive-date"
                                value="<?php echo htmlspecialchars($archiveDate); ?>"
                                max="<?php echo $today; ?>"
                                onchange="loadArchiveFilter()"
                            >
                        </div>

                        <!-- MONTHLY DROPDOWN (JANUARY - DECEMBER) -->
                        <div id="monthlyPickerBox" class="archive-picker" style="display:<?php echo $archiveBasis === 'monthly' ? 'flex' : 'none'; ?>;">
                            <span class="archive-date-label">Select Month:</span>
                            <select id="archiveMonth" class="archive-date" onchange="loadArchiveFilter()">
                                <?php foreach ($archiveMonthNames as $mNum => $mName): ?>
                                    <option value="<?php echo (int)$mNum; ?>" <?php echo (int)$archiveMonth === (int)$mNum ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($mName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                    <!-- SEARCH INSIDE THE ARCHIVE -->
                    <div class="archive-search-box">
                        <span class="archive-search-icon" aria-hidden="true">🔍</span>
                        <input
                            type="search"
                            id="archiveSearch"
                            class="archive-search"
                            placeholder="Search plate, name, type or purpose..."
                            value="<?php echo htmlspecialchars($archiveSearch); ?>"
                            autocomplete="off"
                            aria-label="Search archived logs"
                        >
                    </div>

                    <!-- PRINT -->
                    <a
                        class="archive-print-btn"
                        onclick="return printLogsReport(this.href);"
                        href="logs.php?print=1&amp;source=archive&amp;<?php echo htmlspecialchars(archiveQuery()); ?>"
                    >
                        🖨️ Print Archive Report
                    </a>

                    <!-- DOWNLOAD (PDF or CSV) -->
                    <a
                        class="archive-print-btn"
                        data-download-choice
                        title="Download the archive report as PDF or CSV"
                        href="logs.php?download=pdf&amp;source=archive&amp;<?php echo htmlspecialchars(archiveQuery()); ?>"
                    >
                        📥 Download Archive ▾
                    </a>

                </div>

            </div>

            <?php
            if ($archiveSearch !== '') {
                $archStartDate = ($archiveBasis === 'monthly')
                    ? sprintf('%04d-%02d-01', (int)$archiveYear, (int)$archiveMonth)
                    : $archiveDate;
                $archEndDate = ($archiveBasis === 'monthly')
                    ? date('Y-m-t', strtotime($archStartDate))
                    : $archiveDate;
                echo secsysRenderPlateSearchSummaryHtml(
                    $archiveSearch,
                    array_merge($archiveRegistered, $archiveVisitors),
                    'Archive: ' . $archiveDisplayLabel,
                    $conn,
                    $archStartDate,
                    $archEndDate
                );
            }

            renderArchiveTable(
                'Registered Vehicle Logs',
                $archiveRegisteredPagination,
                'archive_registered_page',
                false,
                'No archived registered vehicle logs for ' . $archiveDisplayLabel . $archiveEmptySuffix . '.',
                $archivePlateStats
            );

            renderArchiveTable(
                'Visitor Vehicle Logs',
                $archiveVisitorPagination,
                'archive_visitor_page',
                true,
                'No archived visitor logs for ' . $archiveDisplayLabel . $archiveEmptySuffix . '.',
                $archivePlateStats
            );
            ?>

        </div>

    </div>


    <script>

    /* =========================================================
       ARCHIVE WINDOW (same behaviour as registered.php)
       Filters, search and pages reload inside the window
       without a page jump.
    ========================================================= */

    var archiveSearchTimer = null;

    /* =========================================================
       PRINT WITHOUT A NEW TAB
       Loads the print view (logs.php?print=1...) into a hidden
       frame on this page; it calls window.print() when loaded,
       so the print dialog opens here and no tab is created.
    ========================================================= */
    function printLogsReport(url)
    {
        var old = document.getElementById("logsPrintFrame");
        if (old) old.remove();

        var frame = document.createElement("iframe");
        frame.id = "logsPrintFrame";
        frame.setAttribute("aria-hidden", "true");
        frame.style.cssText = "position:fixed; right:0; bottom:0; width:0; height:0; border:0; visibility:hidden;";
        frame.src = url;
        document.body.appendChild(frame);

        return false;   // stop the link from opening the page itself
    }

    function toggleArchive()
    {
        var panel = document.getElementById("archivePanel");
        if (!panel) return;

        if (!panel.dataset.loaded) {
            loadArchiveFilter();          // first open: fetch today's archive
        } else {
            panel.classList.add("show");
        }
    }

    function closeArchive()
    {
        var panel = document.getElementById("archivePanel");
        if (panel) panel.classList.remove("show");

        // Drop the archive filters from the address bar (like registered.php)
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            ["show_archive", "archive_basis", "archive_date", "archive_month",
             "archive_q", "archive_registered_page", "archive_visitor_page"]
                .forEach(function (k) { url.searchParams.delete(k); });
            window.history.replaceState({}, document.title, url.pathname + url.search);
        }
    }

    function setArchiveBasis(basis)
    {
        var basisInput = document.getElementById("archiveBasis");
        if (basisInput) basisInput.value = basis;

        var daily = basis !== "monthly";
        var dailyBox = document.getElementById("dailyPickerBox");
        var monthlyBox = document.getElementById("monthlyPickerBox");
        if (dailyBox) dailyBox.style.display = daily ? "flex" : "none";
        if (monthlyBox) monthlyBox.style.display = daily ? "none" : "flex";

        var bd = document.getElementById("btnBasisDaily");
        var bm = document.getElementById("btnBasisMonthly");
        if (bd) bd.classList.toggle("active", daily);
        if (bm) bm.classList.toggle("active", !daily);

        loadArchiveFilter();
    }

    function archiveFilterUrl(extra)
    {
        var val = function (id) {
            var el = document.getElementById(id);
            return el ? el.value : "";
        };

        var params = new URLSearchParams();
        params.set("show_archive", "1");
        params.set("archive_basis", val("archiveBasis") || "daily");
        params.set("archive_date", val("archiveDate"));
        params.set("archive_month", val("archiveMonth"));
        var q = val("archiveSearch").trim();
        if (q !== "") params.set("archive_q", q);

        Object.keys(extra || {}).forEach(function (k) { params.set(k, extra[k]); });

        return "logs.php?" + params.toString();
    }

    function loadArchiveFilter(url)
    {
        fetchArchive(url || archiveFilterUrl({
            archive_registered_page: 1,
            archive_visitor_page: 1
        }));
    }

    function fetchArchive(url)
    {
        var panel = document.getElementById("archivePanel");
        if (!panel) return;

        // Remember the search box state so typing is not interrupted
        var active = document.activeElement;
        var searchFocused = active && active.id === "archiveSearch";
        var caret = searchFocused ? active.selectionStart : null;

        panel.classList.add("show", "archive-loading");

        fetch(url, { credentials: "same-origin", cache: "no-store" })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, "text/html");
                var fresh = doc.getElementById("archivePanel");
                if (!fresh) throw new Error("archive missing");

                // Keep what the user is typing right now (newer than the request)
                var typed = document.getElementById("archiveSearch");
                var typedValue = typed ? typed.value : null;

                panel.innerHTML = fresh.innerHTML;
                panel.dataset.loaded = "1";
                panel.classList.add("show");
                panel.classList.remove("archive-loading");

                var search = document.getElementById("archiveSearch");
                if (search && typedValue !== null && search.value !== typedValue) {
                    search.value = typedValue;
                }
                bindArchiveControls();
                if (searchFocused && search) {
                    search.focus();
                    var pos = caret === null ? search.value.length : Math.min(caret, search.value.length);
                    try { search.setSelectionRange(pos, pos); } catch (e) {}
                }

                if (window.history && window.history.replaceState) {
                    window.history.replaceState({}, document.title, url);
                }
            })
            .catch(function () {
                window.location.href = url;
            });
    }

    function bindArchiveControls()
    {
        var panel = document.getElementById("archivePanel");
        if (!panel) return;

        // Search: filter as you type (short pause), Enter = immediately
        var search = document.getElementById("archiveSearch");
        if (search && !search.dataset.bound) {
            search.dataset.bound = "1";
            search.addEventListener("input", function () {
                clearTimeout(archiveSearchTimer);
                archiveSearchTimer = setTimeout(function () { loadArchiveFilter(); }, 350);
            });
            search.addEventListener("keydown", function (e) {
                if (e.key === "Enter") {
                    e.preventDefault();
                    clearTimeout(archiveSearchTimer);
                    loadArchiveFilter();
                }
            });
            // The (x) clear button of type=search fires "search"
            search.addEventListener("search", function () {
                clearTimeout(archiveSearchTimer);
                loadArchiveFilter();
            });
        }

        // Pagination inside the window
        panel.querySelectorAll(".archive-pagination a[href]").forEach(function (link) {
            link.addEventListener("click", function (e) {
                e.preventDefault();
                fetchArchive(link.getAttribute("href"));
            });
        });
    }

    (function () {
        var panel = document.getElementById("archivePanel");
        if (!panel) return;

        if (panel.classList.contains("show")) {
            panel.dataset.loaded = "1";   // opened straight from a link
        }
        bindArchiveControls();

        // Click on the dark backdrop closes the window
        panel.addEventListener("click", function (e) {
            if (e.target === panel) closeArchive();
        });

        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && panel.classList.contains("show")) {
                closeArchive();
            }
        });
    })();

    </script>


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
        var searchAbortCtrl = null;

        logSearch.addEventListener("input", function() {

            clearTimeout(timer);

            timer = setTimeout(function() {

                var url = buildUrl({
                    "q": logSearch.value.trim(),
                    "registered_page": "",
                    "visitor_page": ""
                });

                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, "", url);
                }

                if (searchAbortCtrl) {
                    searchAbortCtrl.abort();
                }
                searchAbortCtrl = new AbortController();

                fetch(url, { credentials: "same-origin", cache: "no-store", signal: searchAbortCtrl.signal })
                    .then(function(r) { return r.text(); })
                    .then(function(html) {
                        var doc = new DOMParser().parseFromString(html, "text/html");
                        ["liveLogsPlateSearchSummary", "liveRegisteredLogs", "liveVisitorLogs"].forEach(function(id) {
                            var cur = document.getElementById(id);
                            var next = doc.getElementById(id);
                            if (cur && next) {
                                cur.innerHTML = next.innerHTML;
                            }
                        });
                        var nextClear = doc.getElementById("clearFilters");
                        if (clearFilters && nextClear) {
                            clearFilters.className = nextClear.className;
                            clearFilters.setAttribute("href", nextClear.getAttribute("href") || "logs.php");
                        }
                    })
                    .catch(function() {});

            }, 300);

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
<script src="assets/js/download_format.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/download_format.js'); ?>"></script>
<script src="assets/js/auto_refresh.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/auto_refresh.js'); ?>"></script>
<?php echo secsysYoloCaptureModalAssetsHtml(); ?>


</body>

</html>
