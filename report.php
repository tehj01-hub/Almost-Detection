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
   DOMPDF
========================================================= */
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/* =========================================================
   DATABASE CONNECTION
========================================================= */
$conn = secsysConnectDb();
secsysEnsureSchema($conn);
$systemName = secsysGetSystemName($conn);
$systemIcon = secsysGetSystemIcon($conn);

/* =========================================================
   HELPER FUNCTIONS
========================================================= */
if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
    }
}

if (!function_exists('formatTime')) {
    function formatTime($datetime)
    {
        if (!$datetime) {
            return "—";
        }
        return date("h:i A", strtotime($datetime));
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime($datetime)
    {
        if (!$datetime) {
            return "—";
        }
        return date("M d, Y h:i A", strtotime($datetime));
    }
}

if (!function_exists('formatShortDate')) {
    function formatShortDate($datetime)
    {
        if (!$datetime) {
            return "—";
        }
        return date("M d, Y", strtotime($datetime));
    }
}

if (!function_exists('matchesTimeRange')) {
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
}

/* =========================================================
   DATE BASIS FILTER (DAILY OR MONTHLY - SAME AS ARCHIVE)
========================================================= */
$today = date("Y-m-d");
$displayDate = date("l, F d, Y");

$report_basis = (isset($_GET["report_basis"]) && $_GET["report_basis"] === "monthly") ? "monthly" : "daily";

$report_date = $_GET["report_date"] ?? date("Y-m-d");
$date_object = DateTime::createFromFormat("Y-m-d", $report_date);
if (!$date_object || $date_object->format("Y-m-d") !== $report_date) {
    $report_date = date("Y-m-d");
}

$report_month = isset($_GET["report_month"]) ? intval($_GET["report_month"]) : intval(date("n"));
if ($report_month < 1 || $report_month > 12) {
    $report_month = intval(date("n"));
}

$report_year = isset($_GET["report_year"]) ? intval($_GET["report_year"]) : intval(date("Y"));
if ($report_year < 2000 || $report_year > 2100) {
    $report_year = intval(date("Y"));
}

$month_names = [
    1  => "January",
    2  => "February",
    3  => "March",
    4  => "April",
    5  => "May",
    6  => "June",
    7  => "July",
    8  => "August",
    9  => "September",
    10 => "October",
    11 => "November",
    12 => "December"
];

if ($report_basis === "monthly") {
    $periodStart = sprintf("%04d-%02d-01", $report_year, $report_month);
    $periodEnd   = date("Y-m-t", strtotime($periodStart));
    $periodDisplayLabel = $month_names[$report_month] . " " . $report_year;
} else {
    $periodStart = $report_date;
    $periodEnd   = $report_date;
    $periodDisplayLabel = date("F d, Y", strtotime($report_date));
}

/* =========================================================
   PLATE REPORT DOWNLOAD (one plate number, detailed)
   report.php?download=plate&plate=ABC1234&range=period|all&format=pdf|csv
========================================================= */
if (($_GET['download'] ?? '') === 'plate') {
    require_once __DIR__ . '/app/plate_report.php';
    $plateParam = trim((string)($_GET['plate'] ?? ''));
    $allDates = (($_GET['range'] ?? 'period') === 'all');

    if (secsysPlateReportNorm($plateParam) === '') {
        header('Content-Type: text/plain; charset=utf-8', true, 400);
        echo 'Please enter a plate number.';
        exit();
    }

    $plateData = secsysPlateReportData($conn, $plateParam, $allDates ? null : $periodStart, $allDates ? null : $periodEnd);
    $rangeLabel = $allDates ? 'All dates' : $periodDisplayLabel;
    $meta = [
        'Plate Number: ' . $plateData['plate'],
        'Period: ' . $rangeLabel,
    ];
    if (!secsysPlateReportHasData($plateData)) {
        $meta[] = 'No record of this plate was found in the system.';
    }
    $bodyHtml = secsysPlateReportBodyHtml($conn, $plateData);
    $fileBase = 'Plate_Report_' . preg_replace('/[^A-Za-z0-9]+/', '', $plateData['plate']) . '_' . ($allDates ? 'All_Dates' : $periodStart);

    secsysLogActivity(
        $conn,
        (string)($_SESSION['username'] ?? 'Administrator'),
        'Report Download',
        'Downloaded the detailed plate report for ' . $plateData['plate'] . ' (' . $rangeLabel . ', ' . (secsysWantsCsvDownload() ? 'CSV' : 'PDF') . ').'
    );

    if (secsysWantsCsvDownload()) {
        secsysStreamCsvFromHtml(
            secsysBuildPrintDocumentHtml('Plate Detailed Report', $meta, $bodyHtml, 'Plate Detailed Report', false),
            $fileBase
        );
        exit();
    }
    secsysStreamPdfReport('Plate Detailed Report', $meta, $bodyHtml, 'Plate Detailed Report', $fileBase . '.pdf');
    exit();
}

$searchQuery  = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : ''; // Inside / Outside / ''
$categoryFilter = isset($_GET['category']) ? trim($_GET['category']) : ''; // Registered / Visitor / ''

// Hours filter: HH:MM only, strictly without seconds
$timeFrom = '';
if (isset($_GET['time_from'])) {
    $rawF = trim($_GET['time_from']);
    if (preg_match('/^(\d{2}:\d{2})/', $rawF, $m)) {
        $timeFrom = $m[1];
    }
}
$timeTo = '';
if (isset($_GET['time_to'])) {
    $rawT = trim($_GET['time_to']);
    if (preg_match('/^(\d{2}:\d{2})/', $rawT, $m)) {
        $timeTo = $m[1];
    }
}

$sortBy  = isset($_GET['sort']) ? trim($_GET['sort']) : 'archived_at';
if ($sortBy === 'status') {
    $sortBy = 'archived_at';
}
$sortDir   = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'asc' : 'desc';
$auditType = isset($_GET['audit_type']) ? trim($_GET['audit_type']) : '';

/* =========================================================
   1. LOAD SECURITY GUARDS MAP & ACTIVE GUARDS ON PERIOD
========================================================= */
$guardMap = []; // guard_id => ['full_name' => ..., 'status' => ..., 'login_status' => ..., 'last_seen' => ...]
$usernameToGuardId = [];

$resGuards = $conn->query("
    SELECT sg.*, u.username
    FROM security_guard sg
    LEFT JOIN users u
        ON (
            u.guard_id COLLATE utf8mb4_unicode_ci = sg.guard_id COLLATE utf8mb4_unicode_ci
            OR LOWER(u.username COLLATE utf8mb4_unicode_ci) = LOWER(sg.full_name COLLATE utf8mb4_unicode_ci)
        )
    ORDER BY sg.guard_id ASC
");
if ($resGuards) {
    while ($g = $resGuards->fetch_assoc()) {
        $gid = trim($g['guard_id']);
        $guardMap[$gid] = $g;
        if (!empty($g['username'])) {
            $usernameToGuardId[strtolower(trim($g['username']))] = $gid;
        }
        $usernameToGuardId[strtolower(trim($g['full_name']))] = $gid;
    }
}

// Administrators (username => full name), so each admin's actions show
// with their own name, e.g. "Administrator P (adminp)"
$adminUsernames = [];
$resAdmins = $conn->query("SELECT username, full_name FROM users WHERE role = 'Administrator'");
if ($resAdmins) {
    while ($adm = $resAdmins->fetch_assoc()) {
        $adminUsernames[strtolower(trim((string)$adm['username']))] = trim((string)($adm['full_name'] ?? ''));
    }
}

if (!function_exists('resolveActorLabel')) {
    function resolveActorLabel($actorRaw, $guardMap, $usernameToGuardId)
    {
        global $adminUsernames;

        $actor = trim((string)$actorRaw);
        if ($actor === '') {
            return 'Security Guard (On Duty)';
        }
        if (strtoupper($actor) === 'SYSTEM') {
            return 'System (Automatic)';
        }
        if (isset($guardMap[$actor])) {
            return $guardMap[$actor]['full_name'] . ' (' . $actor . ')';
        }
        $lower = strtolower($actor);
        if (isset($usernameToGuardId[$lower])) {
            $gid = $usernameToGuardId[$lower];
            return $guardMap[$gid]['full_name'] . ' (' . $gid . ')';
        }
        if (isset($adminUsernames[$lower]) && $adminUsernames[$lower] !== '') {
            return $adminUsernames[$lower] . ' (' . $actor . ')';
        }
        if ($lower === 'admin' || $lower === 'administrator' || isset($adminUsernames[$lower])) {
            return 'Administrator (' . $actor . ')';
        }
        return $actor;
    }
}

/*
 * Module names used in the audit trail. Each one is a real page of the system,
 * named as it appears in the sidebar / page title, so the reader knows exactly
 * where the action was done. The two dashboards are named after their user.
 */
const REPORT_MODULES = [
    'Administrator Dashboard',   // superadmin_dashboard.php
    'Security Guard Dashboard',  // guard_dashboard.php
    'Logs',                      // logs.php (Administrator)
    'Registered',                // registered.php (Administrator)
    'Visitor',                   // visitor.php (Administrator)
    'Registered Logs',           // registered_logs.php (Security Guard)
    'Visitor Logs',              // visitor_logs.php (Security Guard)
    'Security Guard',            // security_guard.php
    'Security Information',      // security_information.php
    'Report',                    // report.php
    'Backup & Retrieve',         // backup.php
    'Profile',                   // profile.php
    'Login',                     // login.php
    'Logout',                    // the Logout button in the sidebar
    'Forgot Password',           // forgot_password.php
    'Select Duty Partner',       // guard_select_partner.php
];

if (!function_exists('reportIsGuardActor')) {
    /** Security guards are logged by their guard ID (SG-0001); administrators by username. */
    function reportIsGuardActor($actor)
    {
        return (bool)preg_match('/^SG-/i', trim((string)$actor));
    }
}

if (!function_exists('reportAuditModule')) {
    /**
     * The page (module) where the logged action was done.
     */
    function reportAuditModule($activityType, $description, $actor = '')
    {
        $type = strtolower(trim((string)$activityType));
        $desc = strtolower((string)$description);
        $dashboard = reportIsGuardActor($actor) ? 'Security Guard Dashboard' : 'Administrator Dashboard';

        switch ($type) {
            case 'login':
                return 'Login';
            case 'logout':
                return 'Logout';
            case 'co-duty login':
                return 'Select Duty Partner';
            case 'visitor duration extended':
            case 'visitor marked exited':
                return $dashboard;                       // visitor timer controls on the dashboard
            case 'manual registered log':
            case 'exit alert acknowledged':
                return 'Security Guard Dashboard';       // "Add Registered" / exit-without-entry warning
            case 'camera settings':
                return 'Administrator Dashboard';        // camera setup on the admin dashboard
            case 'registered fields update':
                return 'Registered';
            case 'report download':
                return 'Report';                         // Plate Report download                     // Add Field / rename / remove on registered.php
            case 'daily reset':
                return 'Logs';                           // automatic 12:00 AM archive of the logs
            case 'profile update':
            case 'password change':
            case 'system settings update':
            case 'security contacts update':
                return 'Profile';
            case 'password reset':
                return 'Forgot Password';
            case 'archive':
            case 'unarchive':
                if (strpos($desc, 'visitor vehicle log') !== false) {
                    return 'Visitor Logs';
                }
                if (strpos($desc, 'registered vehicle log') !== false) {
                    return 'Registered Logs';
                }
                return 'Logs';
        }

        if (strpos($type, 'database') === 0) {
            return 'Backup & Retrieve';                  // backup, restore, merge, upload, cleanup
        }
        if (strpos($type, 'guard') === 0) {
            return 'Security Guard';                     // guard added / updated / archived
        }
        return $dashboard;
    }
}

// Duty pairs in the selected period
$dutyPairsThisPeriod = [];
$stmtPairs = $conn->prepare("
    SELECT
        gdp.*,
        sg1.full_name AS primary_name,
        sg2.full_name AS partner_name
    FROM guard_duty_pairs gdp
    LEFT JOIN security_guard sg1 ON gdp.primary_guard_id = sg1.guard_id
    LEFT JOIN security_guard sg2 ON gdp.partner_guard_id = sg2.guard_id
    WHERE DATE(gdp.started_at) BETWEEN ? AND ?
    ORDER BY gdp.started_at DESC
");
if ($stmtPairs) {
    $stmtPairs->bind_param("ss", $periodStart, $periodEnd);
    $stmtPairs->execute();
    $resPairs = $stmtPairs->get_result();
    while ($pRow = $resPairs->fetch_assoc()) {
        $dutyPairsThisPeriod[] = $pRow;
    }
    $stmtPairs->close();
}

// Guard activity in the selected period
$guardActivityThisPeriod = [];
$stmtActPeriod = $conn->prepare("
    SELECT
        ga.*,
        sg.full_name
    FROM guard_activity ga
    LEFT JOIN security_guard sg ON ga.guard_id = sg.guard_id
    WHERE DATE(ga.date_created) BETWEEN ? AND ?
    ORDER BY ga.date_created DESC
");
if ($stmtActPeriod) {
    $stmtActPeriod->bind_param("ss", $periodStart, $periodEnd);
    $stmtActPeriod->execute();
    $resActPeriod = $stmtActPeriod->get_result();
    while ($aRow = $resActPeriod->fetch_assoc()) {
        $guardActivityThisPeriod[] = $aRow;
    }
    $stmtActPeriod->close();
}

// Build active guards summary for selected period
$periodGuards = [];

foreach ($guardMap as $gid => $gInfo) {
    $isOnlineNow = ($gInfo['login_status'] ?? '') === 'Active';
    $lastSeenInPeriod = !empty($gInfo['last_seen']) && (substr($gInfo['last_seen'], 0, 10) >= $periodStart && substr($gInfo['last_seen'], 0, 10) <= $periodEnd);

    $periodGuards[$gid] = [
        'guard_id'       => $gid,
        'full_name'      => $gInfo['full_name'],
        'contact_number' => $gInfo['contact_number'] ?? '',
        'status'         => $gInfo['status'] ?? 'Active',
        'login_status'   => $gInfo['login_status'] ?? 'Inactive',
        'last_seen'      => $gInfo['last_seen'] ?? null,
        'duty_sessions'  => 0,
        'partners'       => [],
        'archived_count' => 0,
        'activity_count' => 0,
        'was_active'     => ($isOnlineNow || $lastSeenInPeriod || ($gInfo['status'] ?? '') === 'Active'),
    ];
}

foreach ($dutyPairsThisPeriod as $pair) {
    $p1 = trim($pair['primary_guard_id'] ?? '');
    $p2 = trim($pair['partner_guard_id'] ?? '');
    if ($p1 !== '' && isset($periodGuards[$p1])) {
        $periodGuards[$p1]['duty_sessions']++;
        $periodGuards[$p1]['was_active'] = true;
        if (empty($periodGuards[$p1]['last_seen']) || strtotime($pair['started_at']) > strtotime($periodGuards[$p1]['last_seen'])) {
            $periodGuards[$p1]['last_seen'] = $pair['started_at'];
        }
        if ($p2 !== '' && isset($guardMap[$p2])) {
            $periodGuards[$p1]['partners'][$p2] = $guardMap[$p2]['full_name'] . ' (' . $p2 . ')';
        }
    }
    if ($p2 !== '' && isset($periodGuards[$p2])) {
        $periodGuards[$p2]['duty_sessions']++;
        $periodGuards[$p2]['was_active'] = true;
        if (empty($periodGuards[$p2]['last_seen']) || strtotime($pair['started_at']) > strtotime($periodGuards[$p2]['last_seen'])) {
            $periodGuards[$p2]['last_seen'] = $pair['started_at'];
        }
        if ($p1 !== '' && isset($guardMap[$p1])) {
            $periodGuards[$p2]['partners'][$p1] = $guardMap[$p1]['full_name'] . ' (' . $p1 . ')';
        }
    }
}

foreach ($guardActivityThisPeriod as $act) {
    $actorRaw = trim($act['guard_id'] ?? '');
    $resolvedGid = isset($periodGuards[$actorRaw]) ? $actorRaw : ($usernameToGuardId[strtolower($actorRaw)] ?? '');
    if ($resolvedGid !== '' && isset($periodGuards[$resolvedGid])) {
        $periodGuards[$resolvedGid]['activity_count']++;
        // Kept for the "Audit Actions" popup (click the count to see each action)
        $periodGuards[$resolvedGid]['actions'][] = [
            'time'        => !empty($act['date_created']) ? date('M d, Y h:i A', strtotime($act['date_created'])) : '-',
            'sort'        => (string)($act['date_created'] ?? ''),
            'event'       => (string)($act['activity_type'] ?? 'Activity'),
            'module'      => reportAuditModule($act['activity_type'] ?? '', $act['activity_description'] ?? '', $actorRaw),
            'description' => (string)($act['activity_description'] ?? ''),
        ];
        $periodGuards[$resolvedGid]['was_active'] = true;
        if (stripos($act['activity_type'], 'Login') !== false) {
            $periodGuards[$resolvedGid]['duty_sessions'] = max(1, $periodGuards[$resolvedGid]['duty_sessions']);
        }
        $actTime = $act['date_created'] ?? null;
        if ($actTime && (empty($periodGuards[$resolvedGid]['last_seen']) || strtotime($actTime) > strtotime($periodGuards[$resolvedGid]['last_seen']))) {
            $periodGuards[$resolvedGid]['last_seen'] = $actTime;
        }
    }
}

/* =========================================================
   2. DATA ARCHIVED BY SECURITY GUARDS (LATEST BY archived_at)
   + SESSIONS WITH INSIDE / OUTSIDE STATUS & TIME RANGE
========================================================= */
if (!function_exists('buildReportSessions')) {
function buildReportSessions($resultOrRows, $guardMap, $usernameToGuardId, $dutyPairs, array $ownerMap = [], array $visitorMap = [])
{
    $sessions = [];
    $openSessions = [];

    $rowsList = [];
    if (is_array($resultOrRows)) {
        $rowsList = $resultOrRows;
    } elseif ($resultOrRows) {
        while ($r = $resultOrRows->fetch_assoc()) {
            $rowsList[] = $r;
        }
    }

    foreach ($rowsList as $row) {
        $plate = strtoupper(trim($row['plate_number'] ?? ''));
        $plateKey = secsysNormalizePlateKey($plate);
        if ($plateKey === '') {
            $plateKey = $plate !== '' ? $plate : 'UNKNOWN';
        }
        $direction = strtoupper(trim($row['direction'] ?? 'IN'));
        $category = ucfirst(strtolower(trim($row['vehicle_category'] ?? 'Registered')));
        $vehicleType = !empty($row['vehicle_type']) ? $row['vehicle_type'] : 'Car';
        $dateCreated = $row['date_created'] ?? null;
        $archivedAt = $row['archived_at'] ?? null;
        $archivedByRaw = trim($row['archived_by'] ?? '');

        $rowName = trim((string)($row['visitor_name'] ?? ($row['owner_name'] ?? ($row['name'] ?? ''))));
        $rowPurpose = trim((string)($row['purpose'] ?? ''));
        if ($category === 'Registered') {
            if ($rowName === '' && isset($ownerMap[$plateKey])) {
                $rowName = $ownerMap[$plateKey];
            }
            if ($rowPurpose === '') {
                $rowPurpose = 'Homeowner / Resident';
            }
        } else {
            if ($rowName === '' && isset($visitorMap[$plateKey]['name'])) {
                $rowName = $visitorMap[$plateKey]['name'];
            }
            if ($rowPurpose === '' && isset($visitorMap[$plateKey]['purpose'])) {
                $rowPurpose = $visitorMap[$plateKey]['purpose'];
            }
        }

        // If archived_by was not recorded on older archive rows, infer from duty pair active around archived_at
        if ($archivedByRaw === '' && $archivedAt) {
            $archTs = strtotime($archivedAt);
            foreach ($dutyPairs as $dp) {
                $startTs = strtotime($dp['started_at']);
                $endTs = !empty($dp['ended_at']) ? strtotime($dp['ended_at']) : ($startTs + 43200);
                if ($archTs >= ($startTs - 3600) && $archTs <= ($endTs + 3600)) {
                    $archivedByRaw = $dp['primary_guard_id'];
                    break;
                }
            }
        }

        $actorLabel = resolveActorLabel($archivedByRaw, $guardMap, $usernameToGuardId);

        if ($direction === 'IN') {
            $session = [
                'id'           => (int)($row['id'] ?? 0),
                'plate_number' => $plate,
                'vehicle_type' => $vehicleType,
                'category'     => $category,
                'name'         => $rowName,
                'purpose'      => $rowPurpose,
                'time_in'      => $dateCreated,
                'time_out'     => null,
                'status'       => 'Inside',
                'archived_at'  => $archivedAt,
                'archived_by'  => $archivedByRaw,
                'actor_label'  => $actorLabel,
            ];
            $sessions[] = $session;
            $openSessions[$plateKey][] = count($sessions) - 1;
        } elseif ($direction === 'OUT') {
            $matched = false;
            if (isset($openSessions[$plateKey]) && count($openSessions[$plateKey]) > 0) {
                $idx = array_shift($openSessions[$plateKey]);
                $sessions[$idx]['time_out'] = $dateCreated;
                $sessions[$idx]['status'] = 'Outside';
                if ($sessions[$idx]['name'] === '' && $rowName !== '') {
                    $sessions[$idx]['name'] = $rowName;
                }
                if ($sessions[$idx]['purpose'] === '' && $rowPurpose !== '') {
                    $sessions[$idx]['purpose'] = $rowPurpose;
                }
                if ($archivedAt && (!$sessions[$idx]['archived_at'] || strtotime($archivedAt) > strtotime($sessions[$idx]['archived_at']))) {
                    $sessions[$idx]['archived_at'] = $archivedAt;
                }
                if ($archivedByRaw !== '' && $sessions[$idx]['archived_by'] === '') {
                    $sessions[$idx]['archived_by'] = $archivedByRaw;
                    $sessions[$idx]['actor_label'] = $actorLabel;
                }
                $matched = true;
            }

            if (!$matched) {
                $sessions[] = [
                    'id'           => (int)($row['id'] ?? 0),
                    'plate_number' => $plate,
                    'vehicle_type' => $vehicleType,
                    'category'     => $category,
                    'name'         => $rowName,
                    'purpose'      => $rowPurpose,
                    'time_in'      => null,
                    'time_out'     => $dateCreated,
                    'status'       => 'Outside',
                    'archived_at'  => $archivedAt,
                    'archived_by'  => $archivedByRaw,
                    'actor_label'  => $actorLabel,
                ];
            }
        }
    }

    return $sessions;
}
}

// Preload owner_name & visitor_name lookup maps by normalized plate key
$reportOwnerByPlate = [];
$resReportOwners = @$conn->query("SELECT plate_number, owner_name FROM registered_vehicles");
if ($resReportOwners) {
    while ($ro = $resReportOwners->fetch_assoc()) {
        $pk = secsysNormalizePlateKey((string)($ro['plate_number'] ?? ''));
        if ($pk !== '' && !empty($ro['owner_name'])) {
            $reportOwnerByPlate[$pk] = trim((string)$ro['owner_name']);
        }
    }
}

$reportVisitorByPlate = [];
$resReportVisitors = @$conn->query("SELECT plate_number, visitor_name, purpose FROM visitor_vehicles ORDER BY id ASC");
if ($resReportVisitors) {
    while ($rv = $resReportVisitors->fetch_assoc()) {
        $pk = secsysNormalizePlateKey((string)($rv['plate_number'] ?? ''));
        if ($pk !== '') {
            $reportVisitorByPlate[$pk] = [
                'name'    => trim((string)($rv['visitor_name'] ?? '')),
                'purpose' => trim((string)($rv['purpose'] ?? '')),
            ];
        }
    }
}

// Fetch archived vehicle logs within the selected period (by DATE(archived_at) or DATE(date_created))
$stmtArchLogs = $conn->prepare("
    SELECT *
    FROM vehicle_logs_archive
    WHERE (DATE(archived_at) BETWEEN ? AND ? OR DATE(date_created) BETWEEN ? AND ?)
    ORDER BY date_created ASC
");
$archivedRawRows = [];
if ($stmtArchLogs) {
    $stmtArchLogs->bind_param("ssss", $periodStart, $periodEnd, $periodStart, $periodEnd);
    $stmtArchLogs->execute();
    $resArchLogs = $stmtArchLogs->get_result();
    while ($arRow = $resArchLogs->fetch_assoc()) {
        $archivedRawRows[] = $arRow;
    }
    $stmtArchLogs->close();
}
$archivedSessions = buildReportSessions($archivedRawRows, $guardMap, $usernameToGuardId, $dutyPairsThisPeriod, $reportOwnerByPlate, $reportVisitorByPlate);

// Count archived records per guard
foreach ($archivedSessions as $as) {
    $rawBy = trim($as['archived_by'] ?? '');
    $gid = isset($periodGuards[$rawBy]) ? $rawBy : ($usernameToGuardId[strtolower($rawBy)] ?? '');
    if ($gid !== '' && isset($periodGuards[$gid])) {
        $periodGuards[$gid]['archived_count']++;
        $periodGuards[$gid]['was_active'] = true;
    }
}

// Filter guards to those active in the selected period (or matching search)
$activePeriodGuardsList = array_values(array_filter($periodGuards, function ($g) use ($searchQuery) {
    if (!$g['was_active']) {
        return false;
    }
    if ($searchQuery !== '') {
        $q = strtolower($searchQuery);
        $inName = stripos($g['full_name'], $q) !== false || stripos($g['guard_id'], $q) !== false;
        if (!$inName) {
            return true;
        }
    }
    return true;
}));

// Also fetch currently active (unarchived) vehicle logs in the selected period so the admin can filter all logs by Inside/Outside & Hours if desired
$stmtCurrLogs = $conn->prepare("
    SELECT
        id, plate_number, vehicle_type, vehicle_category,
        purpose, direction, date_created,
        NULL AS archived_at, 'Active Log' AS archived_by
    FROM vehicle_logs
    WHERE DATE(date_created) BETWEEN ? AND ?
    ORDER BY date_created ASC
");
$currentRawRows = [];
if ($stmtCurrLogs) {
    $stmtCurrLogs->bind_param("ss", $periodStart, $periodEnd);
    $stmtCurrLogs->execute();
    $resCurrLogs = $stmtCurrLogs->get_result();
    while ($crRow = $resCurrLogs->fetch_assoc()) {
        $currentRawRows[] = $crRow;
    }
    $stmtCurrLogs->close();
}
$currentPeriodSessions = buildReportSessions($currentRawRows, $guardMap, $usernameToGuardId, $dutyPairsThisPeriod, $reportOwnerByPlate, $reportVisitorByPlate);

// Build deduplicated, chronologically paired vehicle movement sessions for the selected day/period (by movement date_created)
$periodMovementDedup = [];
$periodMovementRows  = [];
foreach ($archivedRawRows as $arRow) {
    $dt = trim((string)($arRow['date_created'] ?? ''));
    $dOnly = $dt !== '' ? substr($dt, 0, 10) : '';
    if ($dOnly !== '' && ($dOnly < $periodStart || $dOnly > $periodEnd)) {
        // Row was created on a previous date and only auto-archived during this period; skip for daily vehicle movement summary
        continue;
    }
    $pKey = secsysNormalizePlateKey((string)($arRow['plate_number'] ?? ''));
    $dir  = strtoupper(trim((string)($arRow['direction'] ?? 'IN')));
    $dk   = $pKey . '|' . $dir . '|' . $dt;
    if (!isset($periodMovementDedup[$dk])) {
        $periodMovementDedup[$dk] = true;
        $periodMovementRows[] = $arRow;
    }
}
foreach ($currentRawRows as $crRow) {
    $dt   = trim((string)($crRow['date_created'] ?? ''));
    $pKey = secsysNormalizePlateKey((string)($crRow['plate_number'] ?? ''));
    $dir  = strtoupper(trim((string)($crRow['direction'] ?? 'IN')));
    $dk   = $pKey . '|' . $dir . '|' . $dt;
    if (!isset($periodMovementDedup[$dk])) {
        $periodMovementDedup[$dk] = true;
        $periodMovementRows[] = $crRow;
    }
}
usort($periodMovementRows, function ($a, $b) {
    $cmp = strcmp((string)($a['date_created'] ?? ''), (string)($b['date_created'] ?? ''));
    if ($cmp !== 0) {
        return $cmp;
    }
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});
$periodVehicleSessionsRaw = !empty($periodMovementRows)
    ? buildReportSessions($periodMovementRows, $guardMap, $usernameToGuardId, $dutyPairsThisPeriod, $reportOwnerByPlate, $reportVisitorByPlate)
    : array_merge($archivedSessions, $currentPeriodSessions);

// Combine or filter archived sessions based on search, status (Inside/Outside), category, and time range (time_from - time_to)
$filterReportSessions = function ($sessions) use ($searchQuery, $statusFilter, $categoryFilter, $timeFrom, $timeTo, $sortBy, $sortDir) {
    if ($searchQuery !== '') {
        $sessions = array_values(array_filter($sessions, function ($s) use ($searchQuery) {
            return secsysMatchesPlateSearch(
                $searchQuery,
                (string)($s['plate_number'] ?? ''),
                (string)($s['name'] ?? ''),
                (string)($s['purpose'] ?? ''),
                (string)($s['actor_label'] ?? ''),
                (string)($s['vehicle_type'] ?? ''),
                (string)($s['category'] ?? '')
            );
        }));
    }

    if ($statusFilter !== '' && strtolower($statusFilter) !== 'all') {
        $sf = strtolower($statusFilter);
        $sessions = array_values(array_filter($sessions, function ($s) use ($sf) {
            return strtolower($s['status'] ?? '') === $sf;
        }));
    }

    if ($categoryFilter !== '' && strtolower($categoryFilter) !== 'all') {
        $cf = strtolower($categoryFilter);
        $sessions = array_values(array_filter($sessions, function ($s) use ($cf) {
            return strtolower($s['category'] ?? '') === $cf;
        }));
    }

    if ($timeFrom !== '' || $timeTo !== '') {
        $sessions = array_values(array_filter($sessions, function ($s) use ($timeFrom, $timeTo) {
            return matchesTimeRange($s['time_in'] ?? null, $s['time_out'] ?? null, $timeFrom, $timeTo);
        }));
    }

    usort($sessions, function ($a, $b) use ($sortBy, $sortDir) {
        if ($sortBy === 'plate_number') {
            $cmp = strcasecmp($a['plate_number'] ?? '', $b['plate_number'] ?? '');
        } elseif ($sortBy === 'time_in') {
            $cmp = strcmp($a['time_in'] ?? '1000-01-01', $b['time_in'] ?? '1000-01-01');
        } elseif ($sortBy === 'time_out') {
            $cmp = strcmp($a['time_out'] ?? '1000-01-01', $b['time_out'] ?? '1000-01-01');
        } else {
            // Default: latest archived_at first, fallback to time_in
            $dtA = $a['archived_at'] ?: ($a['time_in'] ?: ($a['time_out'] ?: '1000-01-01'));
            $dtB = $b['archived_at'] ?: ($b['time_in'] ?: ($b['time_out'] ?: '1000-01-01'));
            $cmp = strcmp($dtA, $dtB);
        }
        return $sortDir === 'desc' ? -$cmp : $cmp;
    });

    return $sessions;
};

$filteredArchivedSessions = $filterReportSessions($archivedSessions);
$filteredActiveSessions   = $filterReportSessions($currentPeriodSessions);   // not yet archived
$allCombinedSessions      = $filterReportSessions($periodVehicleSessionsRaw);

$insideCount  = 0;
$outsideCount = 0;
foreach ($allCombinedSessions as $cs) {
    if (($cs['status'] ?? '') === 'Inside') {
        $insideCount++;
    } else {
        $outsideCount++;
    }
}

$vehicleDaySummary   = secsysBuildVehicleSummary($allCombinedSessions);
$perPlateReportStats = $vehicleDaySummary['per_plate_map'] ?? $vehicleDaySummary['per_plate'];
$perPlateReportRows  = array_values($vehicleDaySummary['per_plate']);

/* =========================================================
   3. AUDIT TRAIL FOR DEEP REPORT
   Combines guard_activity, guard_duty_pairs, and archive events
========================================================= */
$auditTrail = [];

// A. From guard_activity
foreach ($guardActivityThisPeriod as $ga) {
    $actorLabel = resolveActorLabel($ga['guard_id'] ?? '', $guardMap, $usernameToGuardId);
    $auditTrail[] = [
        'timestamp'   => $ga['date_created'] ?? null,
        'actor'       => $actorLabel,
        'event_type'  => $ga['activity_type'] ?: 'Activity',
        'module'      => reportAuditModule($ga['activity_type'] ?? '', $ga['activity_description'] ?? '', $ga['guard_id'] ?? ''),
        'description' => $ga['activity_description'],
    ];
}

// B. From guard_duty_pairs
foreach ($dutyPairsThisPeriod as $dp) {
    $primaryLabel = !empty($dp['primary_name'])
        ? $dp['primary_name'] . ' (' . $dp['primary_guard_id'] . ')'
        : $dp['primary_guard_id'];
    if (!empty($dp['partner_guard_id'])) {
        $partnerLabel = !empty($dp['partner_name'])
            ? $dp['partner_name'] . ' (' . $dp['partner_guard_id'] . ')'
            : $dp['partner_guard_id'];
        $desc = "Started co-duty shift paired with " . $partnerLabel . ".";
    } else {
        $desc = "Started solo duty shift.";
    }
    if (!empty($dp['ended_at'])) {
        $desc .= " Shift ended at " . date("M d, Y h:i A", strtotime($dp['ended_at'])) . ".";
    }
    $auditTrail[] = [
        'timestamp'   => $dp['started_at'],
        'actor'       => $primaryLabel,
        'event_type'  => 'Duty Shift',
        'module'      => 'Select Duty Partner',
        'description' => $desc,
    ];
}

// C. Grouped vehicle_logs_archive events (so historical archives always appear in the deep audit trail)
$stmtArchAudit = $conn->prepare("
    SELECT
        DATE_FORMAT(archived_at, '%Y-%m-%d %H:%i') AS arch_minute,
        MAX(archived_at) AS archived_at,
        vehicle_category,
        archived_by,
        COUNT(*) AS record_count,
        GROUP_CONCAT(DISTINCT plate_number ORDER BY plate_number SEPARATOR ', ') AS plates
    FROM vehicle_logs_archive
    WHERE DATE(archived_at) BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(archived_at, '%Y-%m-%d %H:%i'), vehicle_category, archived_by
    ORDER BY archived_at DESC
");
if ($stmtArchAudit) {
    $stmtArchAudit->bind_param("ss", $periodStart, $periodEnd);
    $stmtArchAudit->execute();
    $resArchAudit = $stmtArchAudit->get_result();
    while ($aa = $resArchAudit->fetch_assoc()) {
        $actorLabel = resolveActorLabel($aa['archived_by'] ?? '', $guardMap, $usernameToGuardId);
        $platesPreview = mb_strlen($aa['plates'] ?? '') > 70 ? mb_substr($aa['plates'], 0, 70) . '...' : ($aa['plates'] ?? '');
        $auditTrail[] = [
            'timestamp'   => $aa['archived_at'],
            'actor'       => $actorLabel,
            'event_type'  => 'Vehicle Log Archive',
            // Archived by a guard on their log page, otherwise on the Administrator's Logs page (or the 12:00 AM reset)
            'module'      => reportIsGuardActor($aa['archived_by'] ?? '') ? $aa['vehicle_category'] . ' Logs' : 'Logs',
            'description' => "Archived " . (int)$aa['record_count'] . " " . strtolower($aa['vehicle_category']) . " vehicle movement log(s) [Plates: " . $platesPreview . "].",
        ];
    }
    $stmtArchAudit->close();
}

// D. Registered vehicles archived in the period
$stmtRegArchAudit = $conn->prepare("
    SELECT plate_number, vehicle_type, address, archived_at, archive_reason, archived_by
    FROM registered_vehicles_archive
    WHERE DATE(archived_at) BETWEEN ? AND ?
    ORDER BY archived_at DESC
");
if ($stmtRegArchAudit) {
    $stmtRegArchAudit->bind_param("ss", $periodStart, $periodEnd);
    $stmtRegArchAudit->execute();
    $resRegArch = $stmtRegArchAudit->get_result();
    while ($ra = $resRegArch->fetch_assoc()) {
        $actorLabel = !empty($ra['archived_by']) ? resolveActorLabel($ra['archived_by'], $guardMap, $usernameToGuardId) : 'Administrator';
        $auditTrail[] = [
            'timestamp'   => $ra['archived_at'],
            'actor'       => $actorLabel,
            'event_type'  => 'Registered Archive',
            'module'      => 'Registered',
            'description' => "Archived registered " . ($ra['vehicle_type'] ?? 'Vehicle') . " (" . ($ra['plate_number'] ?? '') . ") at " . ($ra['address'] ?? 'N/A') . " — " . ($ra['archive_reason'] ?? 'Manual Archive') . ".",
        ];
    }
    $stmtRegArchAudit->close();
}

// Exit camera read a vehicle leaving that never came in (exit_without_entry_alerts)
// The same rows also make up the "Exit Without Entry Log" section.
$exitAlertLog = [];
$stmtExitAlerts = $conn->prepare("
    SELECT id, plate_number, vehicle_category, vehicle_type, camera_name, confidence, plate_image, vehicle_image,
           detected_at, status, acknowledged_by, acknowledged_at, ack_note
    FROM exit_without_entry_alerts
    WHERE DATE(detected_at) BETWEEN ? AND ?
    ORDER BY detected_at DESC
");
if ($stmtExitAlerts) {
    $stmtExitAlerts->bind_param("ss", $periodStart, $periodEnd);
    $stmtExitAlerts->execute();
    $resExitAlerts = $stmtExitAlerts->get_result();
    while ($ea = $resExitAlerts->fetch_assoc()) {
        $ea['acknowledged_label'] = !empty($ea['acknowledged_by'])
            ? resolveActorLabel((string)$ea['acknowledged_by'], $guardMap, $usernameToGuardId)
            : '';
        $exitAlertLog[] = $ea;
        $eaKind = ($ea['vehicle_category'] ?? '') === 'Unknown' ? 'unregistered vehicle' : strtolower((string)$ea['vehicle_category']) . ' vehicle';
        $eaStatus = ($ea['status'] ?? 'New') === 'Acknowledged'
            ? ' Acknowledged by ' . resolveActorLabel((string)$ea['acknowledged_by'], $guardMap, $usernameToGuardId) . ' at ' . date('h:i A', strtotime((string)$ea['acknowledged_at'])) . '.'
            : ' Not yet acknowledged.';
        $auditTrail[] = [
            'timestamp'   => $ea['detected_at'],
            'actor'       => 'System (' . ($ea['camera_name'] ?: 'Exit Cam') . ')',
            'event_type'  => 'Exit Without Entry',
            'module'      => 'Security Guard Dashboard',
            'description' => 'Exit camera read ' . $eaKind . ' ' . ($ea['plate_number'] ?? '') . ' leaving without an entry record.' . $eaStatus,
        ];
    }
    $stmtExitAlerts->close();
}

// The search box also narrows the Exit Without Entry Log (plate, camera, guard, note)
if ($searchQuery !== '') {
    $exitAlertLog = array_values(array_filter($exitAlertLog, function ($ea) use ($searchQuery) {
        $hay = implode(' ', [$ea['plate_number'], $ea['vehicle_category'], $ea['vehicle_type'], $ea['camera_name'],
                             $ea['acknowledged_label'], $ea['ack_note'], $ea['status']]);
        $plain = preg_replace('/[^A-Z0-9]/i', '', $searchQuery);
        return stripos($hay, $searchQuery) !== false
            || ($plain !== '' && stripos(preg_replace('/[^A-Z0-9]/i', '', $hay), $plain) !== false);
    }));
}
$exitAlertNew = count(array_filter($exitAlertLog, function ($ea) { return ($ea['status'] ?? 'New') !== 'Acknowledged'; }));

// Filter & sort audit trail newest first
if ($auditType !== '' && strtolower($auditType) !== 'all') {
    if (strpos($auditType, 'module:') === 0) {
        // "By Module" options: exact module name
        $wantModule = substr($auditType, strlen('module:'));
        $auditTrail = array_values(array_filter($auditTrail, function ($item) use ($wantModule) {
            return strcasecmp($item['module'], $wantModule) === 0;
        }));
    } else {
        // Event options match the event type only (not the module name).
        $atLower = strtolower($auditType);
        $auditTrail = array_values(array_filter($auditTrail, function ($item) use ($atLower) {
            if (stripos($item['event_type'], $atLower) !== false) {
                return true;
            }
            // The automatic midnight reset is an archive operation too
            return $atLower === 'archive' && strcasecmp($item['event_type'], 'Daily Reset') === 0;
        }));
    }
}

if ($searchQuery !== '') {
    $q = strtolower($searchQuery);
    $auditTrail = array_values(array_filter($auditTrail, function ($item) use ($q) {
        return stripos($item['actor'], $q) !== false
            || stripos($item['event_type'], $q) !== false
            || stripos($item['module'], $q) !== false
            || stripos($item['description'], $q) !== false;
    }));
}

usort($auditTrail, function ($a, $b) {
    return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
});

/* =========================================================
   PDF EXPORT (DEEP REPORT)
========================================================= */
if (isset($_GET['download']) && $_GET['download'] === 'pdf') {
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');

    $dompdf = new Dompdf($options);
    $generatedAt = date("F d, Y h:i A");
    $periodStr = $periodDisplayLabel;

    $pdfHtml = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Administrator Report | <?php echo e($systemName); ?></title>
        <style>
            @page { margin: 28px 32px; }
            body { font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #111; }
            .header-table { width: 100%; border-collapse: collapse; border-bottom: 2px solid #1478e8; padding-bottom: 8px; margin-bottom: 14px; }
            .system-title { font-size: 18px; font-weight: bold; color: #111; }
            .system-sub { font-size: 10px; color: #555; }
            .report-title { font-size: 15px; font-weight: bold; color: #1478e8; text-align: right; }
            .report-period { font-size: 10px; color: #555; text-align: right; }
            .section-heading { font-size: 12px; font-weight: bold; color: #1478e8; margin: 14px 0 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
            table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            table.data-table th { background: #1478e8; color: #fff; font-size: 9px; padding: 6px 6px; text-align: left; border: 1px solid #1478e8; }
            table.data-table td { font-size: 9px; padding: 5px 6px; border: 1px solid #dcdcdc; vertical-align: top; }
            table.data-table tr:nth-child(even) { background: #f8fafc; }
            .badge-in { color: #16733b; font-weight: bold; }
            .badge-inside { color: #a16207; background: #fef9c3; font-weight: bold; }
            .badge-out { color: #245b9e; font-weight: bold; }
        </style>
    </head>
    <body>
        <table class="header-table">
            <tr>
                <td>
                    <div class="system-title">L. Surveillance</div>
                    <div class="system-sub">Lessandra Subdivision Security System &bull; Administrator Report</div>
                </td>
                <td>
                    <div class="report-title">Security, Archive &amp; Audit Trail Report</div>
                    <div class="report-period">Basis: ' . ucfirst($report_basis) . ' &bull; Period: ' . e($periodStr) . ' &bull; Generated: ' . e($generatedAt) . '</div>
                    <div class="report-period">Prepared by: ' . e(secsysCurrentAdminLabel()) . ' &bull; Administrator</div>
                </td>
            </tr>
        </table>

        <div class="section-heading">Summary of Vehicles for ' . e($periodDisplayLabel) . '</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Vehicle Type</th>
                    <th>Registered</th>
                    <th>Visitor</th>
                    <th>Entered (IN)</th>
                    <th>Exited (OUT)</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>';

    if (empty($vehicleDaySummary['by_type'])) {
        $pdfHtml .= '<tr><td colspan="6" style="text-align:center; color:#666;">No vehicles detected in this period.</td></tr>';
    }
    foreach ($vehicleDaySummary['by_type'] as $vtRow) {
        $pdfHtml .= '<tr>
            <td><strong>' . e($vtRow['label']) . '</strong></td>
            <td>' . (int)$vtRow['registered'] . '</td>
            <td>' . (int)$vtRow['visitor'] . '</td>
            <td class="badge-in">' . (int)$vtRow['in_count'] . '</td>
            <td class="badge-out">' . (int)$vtRow['out_count'] . '</td>
            <td><strong>' . (int)$vtRow['sessions'] . '</strong></td>
        </tr>';
    }
    $pdfHtml .= '<tr style="background:#eef4fc; font-weight:bold;">
        <td>TOTAL</td>
        <td>' . (int)$vehicleDaySummary['registered']['sessions'] . '</td>
        <td>' . (int)$vehicleDaySummary['visitor']['sessions'] . '</td>
        <td class="badge-in">' . (int)$vehicleDaySummary['total_in'] . '</td>
        <td class="badge-out">' . (int)$vehicleDaySummary['total_out'] . '</td>
        <td>' . (int)$vehicleDaySummary['total_sessions'] . '</td>
    </tr></tbody></table>

        <div class="section-heading">1. Security Guards Active on ' . e($periodDisplayLabel) . ' (' . count($activePeriodGuardsList) . ')</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Guard ID</th>
                    <th>Security Guard Name</th>
                    <th>Co-Duty Partner(s)</th>
                    <th>Duty Sessions</th>
                    <th>Records Archived</th>
                    <th>Audit Actions</th>
                    <th>Last Active</th>
                </tr>
            </thead>
            <tbody>';

    if (empty($activePeriodGuardsList)) {
        $pdfHtml .= '<tr><td colspan="7" style="text-align:center;">No active security guards recorded for this period.</td></tr>';
    } else {
        foreach ($activePeriodGuardsList as $wg) {
            $partnersStr = !empty($wg['partners']) ? implode(', ', $wg['partners']) : 'Solo / None';
            $pdfHtml .= '<tr>
                <td>' . e($wg['guard_id']) . '</td>
                <td><strong>' . e($wg['full_name']) . '</strong></td>
                <td>' . e($partnersStr) . '</td>
                <td>' . (int)$wg['duty_sessions'] . '</td>
                <td>' . (int)$wg['archived_count'] . '</td>
                <td>' . (int)$wg['activity_count'] . '</td>
                <td>' . e(formatDateTime($wg['last_seen'])) . '</td>
            </tr>';
        }
    }

    $pdfHtml .= '</tbody></table>

        <div class="section-heading">2. Vehicle Status Report &amp; Data Archived (' . count($allCombinedSessions) . ' records)</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Archived / Handled By</th>
                    <th>Plate Number</th>
                    <th>Vehicle Type</th>
                    <th>Category</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Entries / Exits</th>
                    <th>Status</th>
                    <th>Date Archived</th>
                </tr>
            </thead>
            <tbody>';

    if (empty($allCombinedSessions)) {
        $pdfHtml .= '<tr><td colspan="10" style="text-align:center;">No vehicle logs or archived records matching criteria.</td></tr>';
    } else {
        $idx = 1;
        foreach ($allCombinedSessions as $row) {
            $stClass = ($row['status'] === 'Inside') ? 'badge-inside' : 'badge-out';
            $archDateStr = !empty($row['archived_at']) ? formatDateTime($row['archived_at']) : 'Active Log';
            $pKey = strtoupper(trim((string)($row['plate_number'] ?? '')));
            $pIn  = (int)($perPlateReportStats[$pKey]['in_count'] ?? (!empty($row['time_in']) ? 1 : 0));
            $pOut = (int)($perPlateReportStats[$pKey]['out_count'] ?? (!empty($row['time_out']) ? 1 : 0));
            $pdfHtml .= '<tr>
                <td>' . $idx++ . '</td>
                <td>' . e($row['actor_label']) . '</td>
                <td><strong>' . e($row['plate_number']) . '</strong></td>
                <td>' . e($row['vehicle_type']) . '</td>
                <td>' . e($row['category']) . '</td>
                <td>' . e(formatTime($row['time_in'])) . '</td>
                <td>' . e(formatTime($row['time_out'])) . '</td>
                <td><strong>' . $pIn . ' IN / ' . $pOut . ' OUT</strong></td>
                <td class="' . $stClass . '">' . e($row['status']) . '</td>
                <td>' . e($archDateStr) . '</td>
            </tr>';
        }
    }

    $pdfHtml .= '</tbody></table>

        <div class="section-heading">3. Audit Trail for Report (' . count($auditTrail) . ' events)</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:125px;">Date &amp; Time</th>
                    <th style="width:140px;">Security Guard / Actor</th>
                    <th style="width:95px;">Event Type</th>
                    <th style="width:95px;">Module</th>
                    <th>Report Description</th>
                </tr>
            </thead>
            <tbody>';

    if (empty($auditTrail)) {
        $pdfHtml .= '<tr><td colspan="5" style="text-align:center;">No audit trail entries found for this period.</td></tr>';
    } else {
        foreach (array_slice($auditTrail, 0, 250) as $at) {
            $pdfHtml .= '<tr>
                <td>' . e(formatDateTime($at['timestamp'])) . '</td>
                <td><strong>' . e($at['actor']) . '</strong></td>
                <td>' . e($at['event_type']) . '</td>
                <td>' . e($at['module']) . '</td>
                <td>' . e($at['description']) . '</td>
            </tr>';
        }
    }

    $pdfHtml .= '</tbody></table>

        <div class="section-heading">4. Exit Without Entry Log (' . count($exitAlertLog) . ' alerts, ' . (int)$exitAlertNew . ' not acknowledged)</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:22px;">#</th>
                    <th style="width:110px;">Date &amp; Time</th>
                    <th>Plate Number</th>
                    <th>Vehicle</th>
                    <th>Camera</th>
                    <th>Status</th>
                    <th>Acknowledged By</th>
                    <th>Acknowledged At</th>
                    <th>Guard\'s Note</th>
                </tr>
            </thead>
            <tbody>';

    if (empty($exitAlertLog)) {
        $pdfHtml .= '<tr><td colspan="9" style="text-align:center;">No vehicle exited without an entry record in this period.</td></tr>';
    } else {
        foreach ($exitAlertLog as $n => $ea) {
            $eaAcked = ($ea['status'] ?? 'New') === 'Acknowledged';
            $eaKind = ($ea['vehicle_category'] ?? '') === 'Unknown' ? 'Unregistered' : (string)$ea['vehicle_category'];
            $pdfHtml .= '<tr>
                <td>' . ($n + 1) . '</td>
                <td>' . e(formatDateTime($ea['detected_at'])) . '</td>
                <td><strong>' . e($ea['plate_number']) . '</strong></td>
                <td>' . e($eaKind . (!empty($ea['vehicle_type']) ? ' / ' . $ea['vehicle_type'] : '')) . '</td>
                <td>' . e($ea['camera_name'] ?: 'Exit Cam') . '</td>
                <td>' . ($eaAcked ? 'Acknowledged' : 'Not Acknowledged') . '</td>
                <td>' . e($eaAcked ? $ea['acknowledged_label'] : '-') . '</td>
                <td>' . e($eaAcked ? formatDateTime($ea['acknowledged_at']) : '-') . '</td>
                <td>' . e((string)($ea['ack_note'] ?? '') !== '' ? $ea['ack_note'] : '-') . '</td>
            </tr>';
        }
    }

    $pdfHtml .= '</tbody></table>
    </body>
    </html>';

    // CSV option: same rows as the PDF
    require_once __DIR__ . '/app/print_report.php';
    if (secsysWantsCsvDownload()) {
        secsysStreamCsvFromHtml($pdfHtml, "Security_Report_" . $report_basis . "_" . $periodStart);
        exit();
    }

    $dompdf->loadHtml($pdfHtml);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream("Security_Report_" . $report_basis . "_" . $periodStart . ".pdf", ["Attachment" => 1]);
    exit();
}

/* =========================================================
   PAGINATION FOR ARCHIVED DATA & AUDIT TRAIL
========================================================= */
// Every table section of the report shows 7 rows per page; the rest go to the next page
const REPORT_ROWS_PER_PAGE = 7;
$perPage = REPORT_ROWS_PER_PAGE;

// View mode for Section 2: 'archived' (default) or 'all' (Active + Archived)
// Two separate views (never mixed): Active Logs (default) or Archived Data
$dataScope = (isset($_GET['scope']) && $_GET['scope'] === 'archived') ? 'archived' : 'active';
$activeDataRows = ($dataScope === 'archived') ? $filteredArchivedSessions : $filteredActiveSessions;

$archPage = isset($_GET['ap']) ? max(1, (int)$_GET['ap']) : 1;
$totalArchRows = count($activeDataRows);
$totalArchPages = max(1, (int)ceil($totalArchRows / $perPage));
if ($archPage > $totalArchPages) {
    $archPage = $totalArchPages;
}
$pagedArchRows = array_slice($activeDataRows, ($archPage - 1) * $perPage, $perPage);

$auditPerPage = REPORT_ROWS_PER_PAGE;
$auditPage = isset($_GET['tp']) ? max(1, (int)$_GET['tp']) : 1;
$totalAuditRows = count($auditTrail);
$totalAuditPages = max(1, (int)ceil($totalAuditRows / $auditPerPage));
if ($auditPage > $totalAuditPages) {
    $auditPage = $totalAuditPages;
}
$pagedAuditRows = array_slice($auditTrail, ($auditPage - 1) * $auditPerPage, $auditPerPage);

if (!function_exists('reportUrl')) {
    function reportUrl($overrides = [])
    {
        $params = $_GET;
        foreach ($overrides as $k => $v) {
            if ($v === null || $v === '') {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        return 'report.php' . (!empty($params) ? '?' . http_build_query($params) : '');
    }
}

if (!function_exists('reportPagerHtml')) {
    /** Page numbers under a section table ("ep", "gp", ... is that section's page parameter). */
    function reportPagerHtml($param, $page, $pages)
    {
        if ($pages <= 1) {
            return '';
        }
        $html = '<div class="pagination">';
        if ($page > 1) {
            $html .= '<a href="' . e(reportUrl([$param => $page - 1])) . '">&lsaquo;</a>';
        }
        for ($p = 1; $p <= $pages; $p++) {
            $html .= $p === $page
                ? '<span class="active">' . $p . '</span>'
                : '<a href="' . e(reportUrl([$param => $p])) . '">' . $p . '</a>';
        }
        if ($page < $pages) {
            $html .= '<a href="' . e(reportUrl([$param => $page + 1])) . '">&rsaquo;</a>';
        }
        return $html . '</div>';
    }
}

/* Pages for the sections that showed every row before */
$guardPages = max(1, (int)ceil(count($activePeriodGuardsList) / REPORT_ROWS_PER_PAGE));
$guardPage = min($guardPages, max(1, (int)($_GET['gp'] ?? 1)));
$pagedPeriodGuards = array_slice($activePeriodGuardsList, ($guardPage - 1) * REPORT_ROWS_PER_PAGE, REPORT_ROWS_PER_PAGE);

$vehicleTypeRows = $vehicleDaySummary['by_type'];
$vehicleTypePages = max(1, (int)ceil(count($vehicleTypeRows) / REPORT_ROWS_PER_PAGE));
$vehicleTypePage = min($vehicleTypePages, max(1, (int)($_GET['vp'] ?? 1)));
$pagedVehicleTypes = array_slice($vehicleTypeRows, ($vehicleTypePage - 1) * REPORT_ROWS_PER_PAGE, REPORT_ROWS_PER_PAGE);
?>
<!DOCTYPE html>
<html lang="en" class="ui-scale-fixed">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Report | <?php echo e($systemName); ?></title>
<style>
/* =========================================================
   RESET & BASE
========================================================= */
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { width: 100%; min-height: 100%; }
body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f7f7f7;
    color: #111;
}

/* =========================================================
   SIDEBAR (MATCHES ALL ADMIN PAGES)
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
.logo {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-left: 3px;
    margin-bottom: 38px;
}
.logo-shield {
    width: 34px;
    height: 38px;
    background: #1478e8;
    clip-path: polygon(50% 0%, 90% 15%, 90% 58%, 75% 82%, 50% 100%, 25% 82%, 10% 58%, 10% 15%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 18px;
    font-weight: bold;
    flex-shrink: 0;
    overflow: hidden;
}
.logo-shield img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.logo-text { line-height: 17px; }
.logo-title { font-size: 17px; font-weight: 500; white-space: nowrap; color: #111; }
.logo-subtitle { font-size: 12px; color: #444; }
.nav {
    display: flex;
    flex-direction: column;
    gap: 7px;
    width: 100%;
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
    transition: background 0.2s ease, color 0.2s ease;
}
.nav a:hover { background: #edf5ff; }
.nav a.active { background: #2f80ed; color: white; }
.nav-icon {
    width: 21px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.logout {
    margin-top: auto;
    width: 100%;
    min-height: 38px;
    border: 1px solid #dddddd;
    border-radius: 18px;
    background: white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #111;
    text-decoration: none;
    font-size: 15px;
    transition: background 0.2s ease;
}
.logout:hover { background: #f3f3f3; }

/* =========================================================
   MAIN CONTENT
========================================================= */
.main {
    margin-left: 212px;
    min-height: 100vh;
    padding: 32px 38px 45px;
    width: calc(100% - 212px);
}
.header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 20px;
}
.header h1 { font-size: 25px; font-weight: 500; line-height: 1.2; }
.header p { font-size: 14px; color: #666; margin-top: 4px; }

.header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.btn {
    height: 38px;
    padding: 0 18px;
    border: none;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-decoration: none;
    transition: all 0.18s ease;
}
.btn-primary { background: #2f80ed; color: #fff; }
.btn-primary:hover { background: #1c6dd0; }
.btn-outline { background: #fff; color: #222; border: 1px solid #cfd9e8; }
.btn-outline:hover { background: #f1f5f9; }

/* =========================================================
   SUMMARY STAT CARDS
========================================================= */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(190px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    background: #f0f6fc;
    border: 1px solid #c8dcf0;
    border-radius: 18px;
    padding: 16px 18px;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05);
}
.summary-label {
    font-size: 12px;
    color: #0369a1;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.summary-value {
    font-size: 28px;
    font-weight: 600;
    color: #0c4a6e;
    margin-top: 6px;
}
.summary-sub {
    font-size: 11px;
    color: #64748b;
    margin-top: 4px;
}

/* =========================================================
   FILTER BAR
========================================================= */
.filter-card {
    background: #f0f6fc;
    border: 1px solid #c8dcf0;
    border-radius: 18px;
    padding: 16px 20px;
    margin-bottom: 22px;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05);
}
.filter-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.filter-group {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
.filter-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ffffff;
    border: 1px solid #c8dcf0;
    border-radius: 18px;
    padding: 0 12px;
    height: 36px;
    font-size: 12px;
    color: #1e293b;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}
.filter-pill input,
.filter-pill select {
    border: none;
    background: transparent;
    font-size: 12px;
    color: #1e293b;
    outline: none;
}

/* =========================================================
   SECTION CARDS & TABLES
========================================================= */
.section-card {
    background: #f0f6fc;
    border: 1px solid #c8dcf0;
    border-radius: 20px;
    padding: 20px 22px;
    margin-bottom: 24px;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05);
}
#liveReportSection1,
#liveReportSection2,
#liveReportSection3,
#liveReportExitAlerts,
#liveReportVehicleSummary {
    background: #f0f6fc;
    border: 1px solid #c8dcf0;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05);
}
.section-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding-bottom: 12px;
    margin-bottom: 14px;
    border-bottom: 1px solid #c8dcf0;
}
.section-title {
    font-size: 18px;
    font-weight: 600;
    color: #0369a1;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-subtitle {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
}
.badge-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    color: #0284c7;
    border: 1px solid #c8dcf0;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 12px;
}
/* Locked Section 2 header so scope-tabs never shifts or wraps */
#liveReportSection2 .section-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: nowrap;
    gap: 16px;
}
#liveReportSection2 .section-top > div:first-child {
    flex: 1 1 auto;
    min-width: 0;
}
#liveReportSection2 .scope-tabs {
    flex-shrink: 0;
    align-self: flex-start;
    margin-top: 2px;
}
.scope-tabs {
    position: relative;
    display: inline-flex;
    align-items: center;
    width: 290px;
    height: 38px;
    background: #e2edf8;
    border: 1px solid #c8dcf0;
    border-radius: 20px;
    padding: 3px;
    box-sizing: border-box;
    user-select: none;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.05);
}
.scope-glider {
    position: absolute;
    top: 3px;
    bottom: 3px;
    left: 3px;
    width: calc((100% - 6px) / 2);
    background: #0284c7;
    border-radius: 16px;
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.32);
    transition: transform 0.26s cubic-bezier(0.4, 0, 0.2, 1);
    pointer-events: none;
    z-index: 1;
    will-change: transform;
}
.scope-tabs[data-active="active"] .scope-glider {
    transform: translateX(0);
}
.scope-tabs[data-active="archived"] .scope-glider {
    transform: translateX(100%);
}
.scope-tab {
    position: relative;
    z-index: 2;
    flex: 1 1 50%;
    width: 50%;
    height: 100%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 8px;
    border-radius: 16px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    color: #0369a1;
    cursor: pointer;
    background: transparent !important;
    border: none;
    box-shadow: none !important;
    transition: color 0.22s cubic-bezier(0.4, 0, 0.2, 1);
    white-space: nowrap;
    box-sizing: border-box;
}
.scope-tab:hover {
    color: #0284c7;
}
.scope-tab.active {
    color: #ffffff !important;
    background: transparent !important;
    box-shadow: none !important;
}
.scope-tab-count {
    font-size: 11px;
    opacity: 0.92;
}
@media (max-width: 768px) {
    #liveReportSection2 .section-top {
        flex-wrap: wrap;
        gap: 12px;
    }
    #liveReportSection2 .scope-tabs {
        width: 100%;
    }
}

@keyframes sectionSwapFadeIn {
    0% {
        opacity: 0.35;
        transform: translateY(4px);
    }
    100% {
        opacity: 1;
        transform: translateY(0);
    }
}
.section-swap-animating {
    animation: sectionSwapFadeIn 0.24s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.scope-loading {
    opacity: 0.55;
    transition: opacity 0.15s ease;
}

.table-wrapper { width: 100%; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: center; }
thead th {
    text-align: center;
    font-weight: 600;
    padding: 11px 12px;
    border-bottom: 2px solid #c8dcf0;
    background: #f8fafc;
    color: #0369a1;
    white-space: nowrap;
    vertical-align: middle;
}
tbody tr {
    background: #ffffff;
}
tbody td {
    background: #ffffff;
    color: #1e293b;
    padding: 11px 12px;
    border-bottom: 1px solid #e2edf6;
    vertical-align: middle;
    text-align: center;
}
tbody tr:hover td { background: #f8fafc; }
tbody tr.vehicle-summary-total td {
    background: #f1f5f9;
    font-weight: 700;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 3px 10px;
    border-radius: 14px;
    font-size: 11px;
    font-weight: 600;
}
.status-inside { background: #fef9c3; color: #854d0e; border: 1px solid #facc15; font-weight: 700; }
.status-outside { background: #edf5ff; color: #245b9e; border: 1px solid #c9ddf7; }
.status-online { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.status-offline { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
.plate-tag {
    display: inline-block;
    font-family: Consolas, monospace;
    font-size: 13px;
    font-weight: 700;
    background: #f0f4f8;
    padding: 3px 7px;
    border-radius: 5px;
    border: 1px solid #d8e2ef;
}
.empty-cell {
    text-align: center;
    padding: 32px 12px;
    color: #888;
    font-size: 13px;
    background: #ffffff;
}

/* =========================================================
   VEHICLE SUMMARY GRID & ENTRY/EXIT PILLS
========================================================= */
.vehicle-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}
.vehicle-summary-box {
    background: #ffffff;
    border: 1px solid #c8dcf0;
    border-radius: 14px;
    padding: 13px 15px;
    text-align: left;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}
.vehicle-summary-box-label {
    font-size: 11px;
    font-weight: 700;
    color: #0369a1;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.vehicle-summary-box-val {
    font-size: 20px;
    font-weight: 700;
    color: #0c4a6e;
    margin-top: 5px;
    display: flex;
    align-items: baseline;
    gap: 6px;
    flex-wrap: wrap;
}
.vehicle-summary-box-sub {
    font-size: 11.5px;
    color: #64748b;
    margin-top: 4px;
    line-height: 1.35;
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

/* =========================================================
   PAGINATION
========================================================= */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 5px;
    margin-top: 16px;
}
.pagination a, .pagination span {
    min-width: 28px;
    height: 28px;
    padding: 0 7px;
    border: 1px solid #c8dcf0;
    border-radius: 6px;
    background: #ffffff;
    font-size: 12px;
    color: #1e293b;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
}
.pagination a:hover { background: #f8fafc; border-color: #0284c7; }
.pagination .active { background: #0284c7; border-color: #0284c7; color: white; font-weight: bold; }

@media (max-width: 1100px) {
    .vehicle-summary-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 1000px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 800px) {
    .sidebar { width: 76px; padding: 24px 8px; }
    .logo { justify-content: center; padding: 0; }
    .logo-text, .nav a span:not(.nav-icon) { display: none; }
    .nav a { justify-content: center; padding: 0; }
    .logout { font-size: 0; }
    .logout::before { content: "⇥"; font-size: 20px; }
    .main { margin-left: 76px; width: calc(100% - 76px); padding: 24px 18px 35px; }
}
@media print {
    .sidebar, .filter-card, .header-actions, .pagination, .scope-tabs { display: none !important; }
    .main { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
    .section-card, .summary-card { box-shadow: none !important; border: 1px solid #ccc !important; }
}
</style>
<!-- Same look as the Security Guard dashboard for every Administrator page -->
<link rel="stylesheet" href="assets/css/admin_uniform.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/admin_uniform.css'); ?>">
<!-- Enlarges the page 25% on laptops/desktops for easier reading (loaded after the page styles) -->
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
                <img src="<?php echo e($systemIcon); ?>" alt="Icon">
            <?php else: ?>
                ✓
            <?php endif; ?>
        </div>
        <div class="logo-text">
            <div class="logo-title"><?php echo e($systemName); ?></div>
            <div class="logo-subtitle"><?php echo secsysAdminSidebarSubtitleHtml(); ?></div>
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
        <a href="security_information.php">
            <span class="nav-icon">📑</span>
            <span>Security Information</span>
        </a>
        <a href="report.php" class="active">
            <span class="nav-icon">📈</span>
            <span>Report</span>
        </a>
        <a href="backup.php">
            <span class="nav-icon">💾</span>
            <span>Backup &amp; Retrieve</span>
        </a>
        <a href="profile.php">
            <span class="nav-icon">⚙️</span>
            <span>Profile</span>
        </a>
    </nav>

    <a href="logout.php?role=Administrator" class="logout"><span class="nav-icon">🚪</span><span>Logout</span></a>
</aside>

<!-- =====================================================
     MAIN CONTENT
====================================================== -->
<main class="main">

    <!-- HEADER -->
    <div class="header">
        <div>
            <h1>Deep Security &amp; Audit Report</h1>
            <p>
                Security Guards, Archived Vehicle Data &amp; Comprehensive Audit Trail
                &bull; <strong><?php echo e($periodDisplayLabel); ?></strong>
            </p>
            <p class="prepared-by" style="margin-top:4px;">
                Prepared by: <strong><?php echo e(secsysCurrentAdminLabel()); ?></strong> &bull; Administrator
            </p>
        </div>

        <div class="header-actions">
            <a href="<?php echo e(reportUrl(['download' => 'pdf'])); ?>" class="btn btn-primary" data-download-choice title="Download the report as PDF or CSV">
                📥 Download Report ▾
            </a>
            <button type="button" class="btn btn-outline" onclick="openPlateReportModal()" title="Download a detailed report for one plate number">
                🔎 Plate Report
            </button>
            <button type="button" class="btn btn-outline" onclick="window.print()">
                🖨️ Print Report
            </button>
        </div>
    </div>

    <!-- SUMMARY STAT CARDS -->
    <div class="summary-grid" id="liveReportSummary" data-live>
        <div class="summary-card">
            <div class="summary-label">Active Guards (<?php echo $report_basis === 'monthly' ? 'Monthly' : 'Daily'; ?>)</div>
            <div class="summary-value"><?php echo count($activePeriodGuardsList); ?></div>
            <!-- <div class="summary-sub"><?php echo count($dutyPairsThisPeriod); ?> duty pairing session(s)</div> -->
        </div>

        <div class="summary-card">
            <div class="summary-label">Data Archived on Period</div>
            <div class="summary-value"><?php echo count($filteredArchivedSessions); ?></div>
            <div class="summary-sub">Filtered by latest archive date</div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Inside vs Outside Status</div>
            <div class="summary-value" style="font-size:22px;">
                <span style="color:#a16207;"><?php echo $insideCount; ?> Inside</span>
                <span style="color:#94a3b8; font-weight:400;">/</span>
                <span style="color:#245b9e;"><?php echo $outsideCount; ?> Outside</span>
            </div>
            <div class="summary-sub">Database status across report period</div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Audit Trail Events</div>
            <div class="summary-value"><?php echo count($auditTrail); ?></div>
            <div class="summary-sub">Logins, duty shifts &amp; archive actions</div>
        </div>
    </div>

    <!-- FILTER & SORT CONTROLS -->
    <div class="filter-card">
        <form method="GET" action="report.php" id="reportFilterForm" class="filter-row">
            <input type="hidden" name="scope" value="<?php echo e($dataScope); ?>">

            <div class="filter-group">
                <!-- Search -->
                <div class="filter-pill" style="min-width:200px;">
                    <span>&#128269;</span>
                    <input type="text" name="search" placeholder="Search guard, plate, or event..." value="<?php echo e($searchQuery); ?>" style="width:100%;">
                </div>

                <!-- Daily / Monthly Basis Filter (Same as Archive) -->
                <div class="filter-pill" style="gap:6px;">
                    <input type="hidden" name="report_basis" id="reportBasis" value="<?php echo e($report_basis); ?>">
                    <div style="display:inline-flex; background:#f0f6fc; border:1px solid #c8dcf0; border-radius:18px; padding:2px; gap:3px;">
                        <button type="button" id="btnBasisDaily" data-keep-scroll="reportFilterForm" onclick="setReportBasis('daily')"
                                style="border:none; height:28px; padding:0 14px; border-radius:16px; font-size:12px; font-weight:600; cursor:pointer; background:<?php echo $report_basis === 'daily' ? '#0284c7' : 'transparent'; ?>; color:<?php echo $report_basis === 'daily' ? '#ffffff' : '#0369a1'; ?>;">
                            Daily
                        </button>
                        <button type="button" id="btnBasisMonthly" data-keep-scroll="reportFilterForm" onclick="setReportBasis('monthly')"
                                style="border:none; height:28px; padding:0 14px; border-radius:16px; font-size:12px; font-weight:600; cursor:pointer; background:<?php echo $report_basis === 'monthly' ? '#0284c7' : 'transparent'; ?>; color:<?php echo $report_basis === 'monthly' ? '#ffffff' : '#0369a1'; ?>;">
                            Monthly
                        </button>
                    </div>

                    <!-- Daily Calendar Picker -->
                    <div id="reportDailyBox" style="display:<?php echo $report_basis === 'daily' ? 'inline-flex' : 'none'; ?>; align-items:center; gap:6px;">
                        <span style="font-size:12px; font-weight:600; color:#555;">Date:</span>
                        <input type="date" name="report_date" id="reportDate" value="<?php echo e($report_date); ?>" onchange="this.form.submit()">
                    </div>

                    <!-- Monthly Dropdown (January - December) -->
                    <div id="reportMonthlyBox" style="display:<?php echo $report_basis === 'monthly' ? 'inline-flex' : 'none'; ?>; align-items:center; gap:6px;">
                        <span style="font-size:12px; font-weight:600; color:#555;">Month:</span>
                        <select name="report_month" id="reportMonth" onchange="this.form.submit()">
                            <?php foreach ($month_names as $m_num => $m_name): ?>
                                <option value="<?php echo (int)$m_num; ?>" <?php echo (int)$report_month === (int)$m_num ? 'selected' : ''; ?>>
                                    <?php echo e($m_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Time In / Time Out Hour Range (Without Seconds) -->
                <div class="filter-pill">
                    <span style="font-weight:600; color:#555;">Hours (Time In–Out):</span>
                    <input type="time" name="time_from" step="60" value="<?php echo e($timeFrom); ?>" title="From Hour (HH:MM)" onchange="this.form.submit()">
                    <span style="color:#888;">to</span>
                    <input type="time" name="time_to" step="60" value="<?php echo e($timeTo); ?>" title="To Hour (HH:MM)" onchange="this.form.submit()">
                </div>

                <!-- Status Filter (Inside / Outside from DB) -->
                <div class="filter-pill">
                    <span style="font-weight:600; color:#555;">Status:</span>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">All (Inside &amp; Outside)</option>
                        <option value="Inside" <?php echo $statusFilter === 'Inside' ? 'selected' : ''; ?>>Inside Only</option>
                        <option value="Outside" <?php echo $statusFilter === 'Outside' ? 'selected' : ''; ?>>Outside Only</option>
                    </select>
                </div>

                <!-- Sort By (Status Option Removed) -->
                <div class="filter-pill">
                    <span style="font-weight:600; color:#555;">Sort:</span>
                    <select name="sort" onchange="this.form.submit()">
                        <option value="archived_at" <?php echo $sortBy === 'archived_at' ? 'selected' : ''; ?>>Latest Archived Date</option>
                        <option value="time_in" <?php echo $sortBy === 'time_in' ? 'selected' : ''; ?>>Time In</option>
                        <option value="time_out" <?php echo $sortBy === 'time_out' ? 'selected' : ''; ?>>Time Out</option>
                        <option value="plate_number" <?php echo $sortBy === 'plate_number' ? 'selected' : ''; ?>>Plate Number</option>
                    </select>
                    <select name="dir" onchange="this.form.submit()">
                        <option value="desc" <?php echo $sortDir === 'desc' ? 'selected' : ''; ?>>DESC</option>
                        <option value="asc" <?php echo $sortDir === 'asc' ? 'selected' : ''; ?>>ASC</option>
                    </select>
                </div>
            </div>

            <div class="filter-group">
                <button type="submit" class="btn btn-primary" style="height:36px; padding:0 15px;">🔍 Apply Filters</button>
                <a href="report.php" class="btn btn-outline" style="height:36px; padding:0 14px;">🔄 Reset</a>
            </div>
        </form>
    </div>

    <!-- PLATE SEARCH ENTRY & EXIT COUNTER BANNER -->
    <div id="liveReportPlateSearch" data-live>
        <?php if ($searchQuery !== ''): ?>
            <?php echo secsysRenderPlateSearchSummaryHtml(
                $searchQuery,
                $allCombinedSessions,
                $periodDisplayLabel,
                $conn,
                $periodStart,
                $periodEnd,
                $categoryFilter !== '' ? $categoryFilter : null
            ); ?>
        <?php endif; ?>
    </div>

    <!-- =====================================================
         NEW SECTION: SUMMARY OF VEHICLES FOR THAT DAY / PERIOD
    ====================================================== -->
    <section class="section-card" id="liveReportVehicleSummary" data-live>
        <div class="section-top">
            <div>
                <div class="section-title">
                    <span>&#128663; Summary of Vehicles for <?php echo e($periodDisplayLabel); ?></span>
                    <span class="badge-pill"><?php echo (int)$vehicleDaySummary['total_sessions']; ?> Total</span>
                </div>
                <div class="section-subtitle">
                    Summary of Registered and Visitor vehicles for <?php echo e($periodDisplayLabel); ?>
                </div>
            </div>
        </div>

        <div class="vehicle-summary-grid">
            <div class="vehicle-summary-box">
                <div class="vehicle-summary-box-label">Total Vehicles</div>
                <div class="vehicle-summary-box-val" style="color:#1478e8;">
                    <?php echo (int)$vehicleDaySummary['total_sessions']; ?>
                </div>
            </div>

            <div class="vehicle-summary-box">
                <div class="vehicle-summary-box-label">Registered Vehicles</div>
                <div class="vehicle-summary-box-val">
                    <?php echo (int)$vehicleDaySummary['registered']['sessions']; ?>
                </div>
            </div>

            <div class="vehicle-summary-box">
                <div class="vehicle-summary-box-label">Visitor Vehicles</div>
                <div class="vehicle-summary-box-val">
                    <?php echo (int)$vehicleDaySummary['visitor']['sessions']; ?>
                </div>
            </div>

            <div class="vehicle-summary-box">
                <div class="vehicle-summary-box-label">Entered / Exited</div>
                <div class="vehicle-summary-box-val">
                    <span style="color:#15803d;"><?php echo (int)$vehicleDaySummary['total_in']; ?> IN</span>
                    <span style="color:#94a3b8; font-weight:400;">/</span>
                    <span style="color:#1d4ed8;"><?php echo (int)$vehicleDaySummary['total_out']; ?> OUT</span>
                </div>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Vehicle Type</th>
                        <th>Registered</th>
                        <th>Visitor</th>
                        <th>Entered (IN)</th>
                        <th>Exited (OUT)</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vehicleDaySummary['by_type'])): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; color:#64748b; padding:14px;">
                                No vehicles detected for <?php echo e($periodDisplayLabel); ?>. Each vehicle type (Car, Motorcycle, Truck, ...) gets its own row once it is detected.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($pagedVehicleTypes as $vt): ?>
                        <tr>
                            <td style="font-weight:700; color:#1e293b;">
                                <?php echo e(($vt['icon'] ?? '🚗') . ' ' . $vt['label']); ?>
                            </td>
                            <td><?php echo (int)$vt['registered']; ?></td>
                            <td><?php echo (int)$vt['visitor']; ?></td>
                            <td style="color:#16733b; font-weight:700;"><?php echo (int)$vt['in_count']; ?></td>
                            <td style="color:#245b9e; font-weight:700;"><?php echo (int)$vt['out_count']; ?></td>
                            <td><strong><?php echo (int)$vt['sessions']; ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="vehicle-summary-total" style="background:#f1f5f9; font-weight:700;">
                        <td style="color:#0f172a;">TOTAL</td>
                        <td><?php echo (int)$vehicleDaySummary['registered']['sessions']; ?></td>
                        <td><?php echo (int)$vehicleDaySummary['visitor']['sessions']; ?></td>
                        <td style="color:#16733b;"><?php echo (int)$vehicleDaySummary['total_in']; ?></td>
                        <td style="color:#245b9e;"><?php echo (int)$vehicleDaySummary['total_out']; ?></td>
                        <td style="color:#1478e8;"><?php echo (int)$vehicleDaySummary['total_sessions']; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php echo reportPagerHtml('vp', $vehicleTypePage, $vehicleTypePages); ?>
    </section>

    <!-- =====================================================
         SECTION 1: SECURITY GUARDS ACTIVE ON PERIOD
    ====================================================== -->
    <section class="section-card" id="liveReportSection1" data-live>
        <div class="section-top">
            <div>
                <div class="section-title">
                    <span>&#128737; Security Guards Active on <?php echo e($periodDisplayLabel); ?></span>
                    <span class="badge-pill"><?php echo count($activePeriodGuardsList); ?> Guard(s)</span>
                </div>
                <div class="section-subtitle">
                    Personnel active on duty, co-duty partners, and total data archived during <?php echo e($periodDisplayLabel); ?>
                </div>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Guard ID</th>
                        <th>Security Guard Name</th>
                        <th>Current Status</th>
                        <th>Co-Duty Partner(s)</th>
                        <th>Duty Sessions</th>
                        <th>Data Archived</th>
                        <th>Audit Actions</th>
                        <th>Last Seen / Active</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activePeriodGuardsList)): ?>
                        <tr>
                            <td colspan="8" class="empty-cell">
                                No security guards recorded as active during the selected period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pagedPeriodGuards as $wg): ?>
                            <?php
                                $isOnline = ($wg['login_status'] === 'Active');
                                $partnersStr = !empty($wg['partners']) ? implode(', ', $wg['partners']) : 'Solo / None recorded';
                            ?>
                            <tr>
                                <td><strong><?php echo e($wg['guard_id']); ?></strong></td>
                                <td style="font-weight:600; color:#1e293b;"><?php echo e($wg['full_name']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $isOnline ? 'status-online' : 'status-offline'; ?>">
                                        <?php echo $isOnline ? 'On Duty (Online)' : 'Off Duty'; ?>
                                    </span>
                                </td>
                                <td style="color:#475569;"><?php echo e($partnersStr); ?></td>
                                <td><strong><?php echo (int)$wg['duty_sessions']; ?></strong> shift(s)</td>
                                <td>
                                    <span class="badge-pill"><?php echo (int)$wg['archived_count']; ?> record(s)</span>
                                </td>
                                <td>
                                    <?php
                                        $guardActions = $wg['actions'] ?? [];
                                        usort($guardActions, function ($a, $b) { return strcmp($b['sort'], $a['sort']); });
                                        foreach ($guardActions as &$ga) { unset($ga['sort']); }
                                        unset($ga);
                                    ?>
                                    <?php if (!empty($guardActions)): ?>
                                        <button type="button" class="audit-actions-link"
                                            title="View the actions made by <?php echo e($wg['full_name']); ?>"
                                            data-guard="<?php echo e($wg['full_name'] . ' (' . $wg['guard_id'] . ')'); ?>"
                                            data-actions="<?php echo e(json_encode($guardActions, JSON_UNESCAPED_UNICODE)); ?>">
                                            <?php echo (int)$wg['activity_count']; ?> event(s) &rsaquo;
                                        </button>
                                    <?php else: ?>
                                        <?php echo (int)$wg['activity_count']; ?> event(s)
                                    <?php endif; ?>
                                </td>
                                <td style="color:#64748b; font-size:12px;"><?php echo e(formatDateTime($wg['last_seen'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php echo reportPagerHtml('gp', $guardPage, $guardPages); ?>
    </section>

    <!-- =====================================================
         SECTION 2: DATA THEY ARCHIVE (WITH INSIDE/OUTSIDE & HOUR RANGE)
    ====================================================== -->
    <section class="section-card" id="liveReportSection2" data-live>
        <div class="section-top">
            <div>
                <div class="section-title">
                    <span>&#128452; Vehicle Status Report &amp; Data Archived</span>
                    <span class="badge-pill"><?php echo $totalArchRows; ?> Record(s)</span>
                </div>
                <div class="section-subtitle">
                    <?php if ($dataScope === 'archived'): ?>
                        Archived logs only, looked up by the date archived (latest first).
                    <?php else: ?>
                        Active vehicle logs only, latest first.
                    <?php endif; ?>
                    Includes <strong>Inside / Outside</strong> status and <strong>Time In – Time Out</strong> hour range filtering.
                </div>
            </div>

            <div class="scope-tabs" data-active="<?php echo $dataScope === 'archived' ? 'archived' : 'active'; ?>">
                <span class="scope-glider" aria-hidden="true"></span>
                <a href="<?php echo e(reportUrl(['scope' => null, 'ap' => 1])); ?>" class="scope-tab <?php echo $dataScope === 'active' ? 'active' : ''; ?>" data-scope="active">
                    <span>Active Logs</span> <span class="scope-tab-count">(<?php echo count($filteredActiveSessions); ?>)</span>
                </a>
                <a href="<?php echo e(reportUrl(['scope' => 'archived', 'ap' => 1])); ?>" class="scope-tab <?php echo $dataScope === 'archived' ? 'active' : ''; ?>" data-scope="archived">
                    <span>Archived Data</span> <span class="scope-tab-count">(<?php echo count($filteredArchivedSessions); ?>)</span>
                </a>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:44px;">#</th>
                        <th style="min-width:150px;">Archived / Handled By</th>
                        <th style="min-width:110px;">Plate Number</th>
                        <th style="min-width:100px;">Vehicle Type</th>
                        <th style="min-width:90px;">Category</th>
                        <th style="min-width:90px;">Log Date</th>
                        <th style="min-width:80px;">Time In</th>
                        <th style="min-width:80px;">Time Out</th>
                        <th style="min-width:105px;">Entries / Exits</th>
                        <th style="min-width:85px;">Status</th>
                        <th style="min-width:130px;">Date Archived</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pagedArchRows)): ?>
                        <tr>
                            <td colspan="11" class="empty-cell">
                                No archived or vehicle log data found matching your selected period, status, or hour range.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $rowNum = ($archPage - 1) * $perPage + 1; ?>
                        <?php foreach ($pagedArchRows as $ar): ?>
                            <?php
                                $logDt = !empty($ar['time_in']) ? $ar['time_in'] : ($ar['time_out'] ?? $ar['archived_at']);
                                $isInside = ($ar['status'] ?? '') === 'Inside';
                                $pKey = strtoupper(trim((string)($ar['plate_number'] ?? '')));
                                $pIn  = (int)($perPlateReportStats[$pKey]['in_count'] ?? (!empty($ar['time_in']) ? 1 : 0));
                                $pOut = (int)($perPlateReportStats[$pKey]['out_count'] ?? (!empty($ar['time_out']) ? 1 : 0));
                            ?>
                            <tr>
                                <td style="color:#777;"><?php echo $rowNum++; ?></td>
                                <td style="font-weight:600; color:#1e293b;"><?php echo e($ar['actor_label']); ?></td>
                                <td><?php echo secsysClickablePlateHtml($ar['plate_number'], $ar, 'plate-tag'); ?></td>
                                <td><?php echo e($ar['vehicle_type']); ?></td>
                                <td><?php echo e($ar['category']); ?></td>
                                <td style="color:#555; font-size:12px;"><?php echo e(formatShortDate($logDt)); ?></td>
                                <td><?php echo e(formatTime($ar['time_in'])); ?></td>
                                <td><?php echo e(formatTime($ar['time_out'])); ?></td>
                                <td><?php echo secsysPlateEntryExitPillHtml($pIn, $pOut); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $isInside ? 'status-inside' : 'status-outside'; ?>">
                                        <?php echo e($ar['status']); ?>
                                    </span>
                                </td>
                                <td style="color:#475569; font-size:12px;">
                                    <?php echo !empty($ar['archived_at']) ? e(formatDateTime($ar['archived_at'])) : '<span style="color:#2f80ed; font-weight:600;">Active Log</span>'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalArchPages > 1): ?>
            <div class="pagination">
                <?php if ($archPage > 1): ?>
                    <a href="<?php echo e(reportUrl(['ap' => $archPage - 1])); ?>">&lsaquo;</a>
                <?php endif; ?>
                <?php for ($p = 1; $p <= $totalArchPages; $p++): ?>
                    <?php if ($p === $archPage): ?>
                        <span class="active"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="<?php echo e(reportUrl(['ap' => $p])); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($archPage < $totalArchPages): ?>
                    <a href="<?php echo e(reportUrl(['ap' => $archPage + 1])); ?>">&rsaquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- =====================================================
         SECTION 3: AUDIT TRAIL FOR DEEP REPORT
    ====================================================== -->
    <section class="section-card" id="liveReportSection3" data-live>
        <div class="section-top">
            <div>
                <div class="section-title">
                    <span>&#128269; Audit Trail for Report</span>
                    <span class="badge-pill"><?php echo $totalAuditRows; ?> Event(s)</span>
                </div>
                <div class="section-subtitle">
                    Chronological security audit trail of guard logins, logouts, co-duty pairings, and data archiving operations
                </div>
            </div>

            <form method="GET" action="report.php" id="auditFilterForm" style="display:flex; align-items:center; gap:8px;">
                <input type="hidden" name="report_basis" value="<?php echo e($report_basis); ?>">
                <input type="hidden" name="report_date" value="<?php echo e($report_date); ?>">
                <input type="hidden" name="report_month" value="<?php echo e($report_month); ?>">
                <input type="hidden" name="status" value="<?php echo e($statusFilter); ?>">
                <input type="hidden" name="time_from" value="<?php echo e($timeFrom); ?>">
                <input type="hidden" name="time_to" value="<?php echo e($timeTo); ?>">
                <input type="hidden" name="scope" value="<?php echo e($dataScope); ?>">
                <div class="filter-pill">
                    <span style="font-weight:600; color:#555;">Event Filter:</span>
                    <select name="audit_type" onchange="this.form.submit()">
                        <option value="">All Audit Events</option>
                        <option value="Login" <?php echo $auditType === 'Login' ? 'selected' : ''; ?>>Logins &amp; Co-Duty</option>
                        <option value="Logout" <?php echo $auditType === 'Logout' ? 'selected' : ''; ?>>Logouts</option>
                        <option value="Duty" <?php echo $auditType === 'Duty' ? 'selected' : ''; ?>>Duty Shifts</option>
                        <option value="Archive" <?php echo $auditType === 'Archive' ? 'selected' : ''; ?>>Archive Operations</option>
                        <optgroup label="By Module">
                            <?php foreach (REPORT_MODULES as $moduleOption): ?>
                                <option value="<?php echo e('module:' . $moduleOption); ?>" <?php echo $auditType === 'module:' . $moduleOption ? 'selected' : ''; ?>><?php echo e($moduleOption); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
            </form>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:165px;">Date &amp; Time</th>
                        <th style="width:210px;">Security Guard / Actor</th>
                        <th style="width:140px;">Event Type</th>
                        <th style="width:150px;">Module</th>
                        <th>Audit Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pagedAuditRows)): ?>
                        <tr>
                            <td colspan="5" class="empty-cell">
                                No audit trail events recorded for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pagedAuditRows as $audit): ?>
                            <tr>
                                <td style="color:#475569; font-size:12px; white-space:nowrap;">
                                    <?php echo e(formatDateTime($audit['timestamp'])); ?>
                                </td>
                                <td style="font-weight:600; color:#1e293b;">
                                    <?php echo e($audit['actor']); ?>
                                </td>
                                <td>
                                    <span class="badge-pill" style="background:#f1f5f9; color:#0f172a; border:1px solid #cbd5e1;">
                                        <?php echo e($audit['event_type']); ?>
                                    </span>
                                </td>
                                <td style="color:#334155; font-size:12px; font-weight:600;">
                                    <?php echo e($audit['module']); ?>
                                </td>
                                <td style="color:#334155; line-height:1.4;">
                                    <?php echo e($audit['description']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalAuditPages > 1): ?>
            <div class="pagination">
                <?php if ($auditPage > 1): ?>
                    <a href="<?php echo e(reportUrl(['tp' => $auditPage - 1])); ?>">&lsaquo;</a>
                <?php endif; ?>
                <?php for ($p = 1; $p <= $totalAuditPages; $p++): ?>
                    <?php if ($p === $auditPage): ?>
                        <span class="active"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="<?php echo e(reportUrl(['tp' => $p])); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($auditPage < $totalAuditPages): ?>
                    <a href="<?php echo e(reportUrl(['tp' => $auditPage + 1])); ?>">&rsaquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- =====================================================
         SECTION 4: EXIT WITHOUT ENTRY LOG
         Vehicles the Exit Cam read leaving that were never logged IN
    ====================================================== -->
    <section class="section-card" id="liveReportExitAlerts" data-live>
        <div class="section-top">
            <div>
                <div class="section-title">
                    <span>&#128680; Exit Without Entry Log</span>
                    <span class="badge-pill"><?php echo count($exitAlertLog); ?> Alert(s)</span>
                    <?php if ($exitAlertNew > 0): ?>
                        <span class="badge-pill" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;"><?php echo (int)$exitAlertNew; ?> Not Acknowledged</span>
                    <?php endif; ?>
                </div>
                <div class="section-subtitle">
                    Vehicles the Exit Cam read leaving that were never logged IN at the entrance, and how the guard on duty handled each one
                </div>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:44px;">#</th>
                        <th style="width:165px;">Date &amp; Time</th>
                        <th>Plate Number</th>
                        <th>Vehicle</th>
                        <th>Camera</th>
                        <th>Status</th>
                        <th>Acknowledged By</th>
                        <th>Guard's Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $exitPerPage = REPORT_ROWS_PER_PAGE;
                    $exitPages = max(1, (int)ceil(count($exitAlertLog) / $exitPerPage));
                    $exitPage = min($exitPages, max(1, (int)($_GET['ep'] ?? 1)));
                    $pagedExitAlerts = array_slice($exitAlertLog, ($exitPage - 1) * $exitPerPage, $exitPerPage);
                    ?>
                    <?php if (empty($pagedExitAlerts)): ?>
                        <tr>
                            <td colspan="8" class="empty-cell">
                                No vehicle exited without an entry record in this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pagedExitAlerts as $n => $ea): ?>
                            <?php
                            $eaAcked = ($ea['status'] ?? 'New') === 'Acknowledged';
                            $eaKind = ($ea['vehicle_category'] ?? '') === 'Unknown' ? 'Unregistered' : (string)$ea['vehicle_category'];
                            ?>
                            <tr>
                                <td><?php echo ($exitPage - 1) * $exitPerPage + $n + 1; ?></td>
                                <td style="color:#475569; font-size:12px; white-space:nowrap;"><?php echo e(formatDateTime($ea['detected_at'])); ?></td>
                                <td><?php echo secsysClickablePlateHtml((string)$ea['plate_number'], ['alert_id' => (int)$ea['id']]); ?></td>
                                <td><?php echo e($eaKind . (!empty($ea['vehicle_type']) ? ' · ' . $ea['vehicle_type'] : '')); ?></td>
                                <td><?php echo e($ea['camera_name'] ?: 'Exit Cam'); ?></td>
                                <td>
                                    <?php if ($eaAcked): ?>
                                        <span class="badge-pill" style="background:#dcfce7; color:#166534; border:1px solid #86efac;">Acknowledged</span>
                                    <?php else: ?>
                                        <span class="badge-pill" style="background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;">Not Acknowledged</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($eaAcked): ?>
                                        <div style="font-weight:600; color:#1e293b;"><?php echo e($ea['acknowledged_label']); ?></div>
                                        <div style="font-size:11.5px; color:#64748b;"><?php echo e(formatDateTime($ea['acknowledged_at'])); ?></div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#334155; line-height:1.4; max-width:260px; white-space:normal;"><?php echo $ea['ack_note'] !== null && $ea['ack_note'] !== '' ? e($ea['ack_note']) : '<span style="color:#94a3b8;">—</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($exitPages > 1): ?>
            <div class="pagination">
                <?php if ($exitPage > 1): ?>
                    <a href="<?php echo e(reportUrl(['ep' => $exitPage - 1])); ?>">&lsaquo;</a>
                <?php endif; ?>
                <?php for ($p = 1; $p <= $exitPages; $p++): ?>
                    <?php if ($p === $exitPage): ?>
                        <span class="active"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="<?php echo e(reportUrl(['ep' => $p])); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($exitPage < $exitPages): ?>
                    <a href="<?php echo e(reportUrl(['ep' => $exitPage + 1])); ?>">&rsaquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<!-- =====================================================
     PLATE REPORT WINDOW (detailed report for one plate)
====================================================== -->
<?php require_once __DIR__ . '/app/plate_report.php'; $knownPlates = secsysPlateReportKnownPlates($conn); ?>
<div class="plate-report-backdrop" id="plateReportModal" role="dialog" aria-modal="true" aria-labelledby="plateReportTitle">
    <form class="plate-report-box" onsubmit="return downloadPlateReport(event)" autocomplete="off">
        <div class="plate-report-head">
            <div class="plate-report-title" id="plateReportTitle">🔎 Plate Report</div>
            <button type="button" class="plate-report-close" onclick="closePlateReportModal()" aria-label="Close">&times;</button>
        </div>

        <p class="plate-report-hint">
            Download everything recorded for one plate number: vehicle details, entries and exits (active and archived),
            visits with time inside, exit-without-entry warnings and audit trail mentions.
        </p>

        <label class="plate-report-label" for="plateReportInput">Plate Number</label>
        <input type="text" id="plateReportInput" class="plate-report-input" list="plateReportList"
               placeholder="Type or pick a plate, e.g. NA16155" maxlength="30" required>
        <datalist id="plateReportList">
            <?php foreach ($knownPlates as $kp): ?>
                <option value="<?php echo e($kp); ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <div class="plate-report-label">Date Range</div>
        <div class="plate-report-options">
            <label><input type="radio" name="plate_range" value="period" checked> <?php echo e($periodDisplayLabel); ?> (this report)</label>
            <label><input type="radio" name="plate_range" value="all"> All dates</label>
        </div>

        <div class="plate-report-label">File Type</div>
        <div class="plate-report-options">
            <label><input type="radio" name="plate_format" value="pdf" checked> PDF</label>
            <label><input type="radio" name="plate_format" value="csv"> CSV (Excel)</label>
        </div>

        <div class="plate-report-error" id="plateReportError" hidden></div>

        <div class="plate-report-actions">
            <button type="button" class="btn btn-outline" onclick="closePlateReportModal()">Cancel</button>
            <button type="submit" class="btn btn-primary">📥 Download Plate Report</button>
        </div>
    </form>
</div>

<style>
.plate-report-backdrop {
    display: none; position: fixed; inset: 0; z-index: 10040; background: rgba(15, 23, 42, 0.55);
    align-items: center; justify-content: center; padding: 16px;
}
.plate-report-backdrop.open { display: flex; }
.plate-report-box {
    background: #fff; border-radius: 16px; width: 100%; max-width: 460px; max-height: calc(100vh - 32px); overflow-y: auto;
    padding: 18px 20px 16px; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
    box-sizing: border-box; overflow-x: hidden;
}
.plate-report-box * { box-sizing: border-box; }
.plate-report-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 6px; }
.plate-report-title { font-size: 17px; font-weight: 700; color: #0f172a; }
.plate-report-close { background: none; border: none; font-size: 24px; line-height: 1; color: #64748b; cursor: pointer; }
.plate-report-hint { font-size: 12.5px; color: #475569; line-height: 1.45; margin: 0 0 12px; }
.plate-report-label { display: block; font-size: 12.5px; font-weight: 700; color: #1e293b; margin: 10px 0 6px; }
.plate-report-input {
    width: 100%; height: 40px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 12px;
    font-size: 14px; letter-spacing: 0.5px; text-transform: uppercase;
}
.plate-report-input:focus { outline: none; border-color: #2f80ed; box-shadow: 0 0 0 3px rgba(47, 128, 237, 0.15); }
.plate-report-options { display: flex; flex-wrap: wrap; gap: 8px 18px; font-size: 13px; color: #1e293b; }
.plate-report-options label { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
.plate-report-error {
    margin-top: 12px; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px;
    padding: 8px 10px; font-size: 12.5px;
}
.plate-report-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
@media print { .plate-report-backdrop { display: none !important; } }
</style>

<script>
function openPlateReportModal()
{
    const modal = document.getElementById("plateReportModal");
    const input = document.getElementById("plateReportInput");
    // Start from the plate in the search box, if any
    const search = document.querySelector('#reportFilterForm input[name="search"]');
    if (search && search.value.trim() && !input.value.trim()) {
        input.value = search.value.trim().toUpperCase();
    }
    document.getElementById("plateReportError").hidden = true;
    modal.classList.add("open");
    setTimeout(function () { input.focus(); input.select(); }, 60);
}

function closePlateReportModal()
{
    document.getElementById("plateReportModal").classList.remove("open");
}

function downloadPlateReport(event)
{
    event.preventDefault();
    const plate = document.getElementById("plateReportInput").value.trim();
    const err = document.getElementById("plateReportError");
    if (!/[A-Za-z0-9]/.test(plate)) {
        err.textContent = "Please enter a plate number.";
        err.hidden = false;
        return false;
    }
    err.hidden = true;
    const range = document.querySelector('input[name="plate_range"]:checked').value;
    const format = document.querySelector('input[name="plate_format"]:checked').value;

    // Keep the report's current period (date / month), then ask for this plate
    const params = new URLSearchParams(window.location.search);
    ["search", "status", "category", "time_from", "time_to", "sort", "dir", "scope", "audit_type", "ap", "tp", "ep", "gp", "vp"].forEach(function (k) { params.delete(k); });
    params.set("download", "plate");
    params.set("plate", plate);
    params.set("range", range);
    if (format === "csv") {
        params.set("format", "csv");
    } else {
        params.delete("format");
    }
    window.location.href = "report.php?" + params.toString();
    setTimeout(closePlateReportModal, 400);
    return false;
}

document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") closePlateReportModal();
});
document.getElementById("plateReportModal").addEventListener("click", function (event) {
    if (event.target === this) closePlateReportModal();
});
</script>

<script>
function setReportBasis(basis) {
    const basisInput = document.getElementById('reportBasis');
    if (basisInput) basisInput.value = basis;

    const dailyBox = document.getElementById('reportDailyBox');
    const monthlyBox = document.getElementById('reportMonthlyBox');
    const btnDaily = document.getElementById('btnBasisDaily');
    const btnMonthly = document.getElementById('btnBasisMonthly');

    if (basis === 'monthly') {
        if (dailyBox) dailyBox.style.display = 'none';
        if (monthlyBox) monthlyBox.style.display = 'inline-flex';
        if (btnDaily) { btnDaily.style.background = 'transparent'; btnDaily.style.color = '#0369a1'; }
        if (btnMonthly) { btnMonthly.style.background = '#0284c7'; btnMonthly.style.color = '#ffffff'; }
    } else {
        if (dailyBox) dailyBox.style.display = 'inline-flex';
        if (monthlyBox) monthlyBox.style.display = 'none';
        if (btnDaily) { btnDaily.style.background = '#0284c7'; btnDaily.style.color = '#ffffff'; }
        if (btnMonthly) { btnMonthly.style.background = 'transparent'; btnMonthly.style.color = '#0369a1'; }
    }

    const form = document.getElementById('reportFilterForm');
    if (form) form.submit();
}
</script>

<script src="assets/js/keep_scroll.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/keep_scroll.js'); ?>"></script>

<script>window.AUTO_REFRESH_SECONDS = 5;</script>
<!-- =====================================================
     AUDIT ACTIONS POPUP (click a guard's "event(s)" count)
====================================================== -->
<style>
.audit-actions-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 700;
    font-size: 12px;
    padding: 4px 11px;
    border-radius: 999px;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s ease, border-color 0.15s ease, transform 0.12s ease;
}
.audit-actions-link:hover,
.audit-actions-link:focus {
    background: #dbeafe;
    border-color: #93c5fd;
    transform: translateY(-1px);
    outline: none;
}

.audit-modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    width: calc(100vw / var(--ui-scale, 1));
    height: calc(100vh / var(--ui-scale, 1));
    z-index: 9500;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(3px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
}
.audit-modal-backdrop.open {
    display: flex;
}

.audit-modal {
    background: #ffffff;
    border: 1px solid #dbe3ef;
    border-radius: 18px;
    width: 920px;
    max-width: min(920px, calc(94vw / var(--ui-scale, 1)));
    max-height: calc(86vh / var(--ui-scale, 1));
    display: flex;
    flex-direction: column;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
    overflow: hidden;
    animation: auditModalIn 0.18s ease-out;
}
@keyframes auditModalIn {
    from { opacity: 0; transform: translateY(-10px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.audit-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 16px 22px;
    background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
    border-bottom: 1px solid #e2e8f0;
    flex-shrink: 0;
}
.audit-modal-head-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.audit-modal-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    flex-shrink: 0;
}
.audit-modal-title-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.audit-modal-title {
    font-size: 17px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.25;
}
.audit-modal-guard-pill {
    display: inline-flex;
    align-items: center;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    font-size: 12px;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 999px;
}
.audit-modal-sub {
    font-size: 12.5px;
    color: #64748b;
    margin-top: 3px;
}
.audit-modal-close {
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.15s ease;
}
.audit-modal-close:hover,
.audit-modal-close:focus {
    background: #fee2e2;
    border-color: #fecaca;
    color: #b91c1c;
    outline: none;
}

.audit-modal-body {
    overflow-y: auto;
    overflow-x: auto;
    padding: 0;
    flex: 1;
    background: #ffffff;
}
.audit-modal .audit-modal-table {
    zoom: 1 !important;
    width: 100% !important;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
    text-align: left;
}
.audit-modal .audit-modal-table thead th {
    position: sticky;
    top: 0;
    z-index: 3;
    background: #f8fafc;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.45px;
    color: #475569;
    padding: 11px 14px;
    border-bottom: 2px solid #e2e8f0;
    white-space: nowrap;
}
.audit-modal .audit-modal-table thead th.col-num {
    width: 46px;
    text-align: center;
    padding-left: 18px;
}
.audit-modal .audit-modal-table tbody td {
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: middle;
    text-align: left;
    line-height: 1.45;
}
.audit-modal .audit-modal-table tbody tr:nth-child(even) {
    background: #fcfdff;
}
.audit-modal .audit-modal-table tbody tr:hover {
    background: #f1f7ff;
}
.audit-modal .audit-modal-table tbody td.col-num {
    text-align: center;
    color: #94a3b8;
    font-weight: 600;
    font-size: 12px;
    padding-left: 18px;
}
.audit-modal .audit-modal-table tbody td.col-time {
    white-space: nowrap;
    color: #475569;
    font-size: 12.5px;
    font-weight: 500;
}
.audit-modal .audit-modal-table tbody td.col-desc {
    color: #1e293b;
    overflow-wrap: anywhere;
    padding-right: 20px;
}
.audit-event-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
    border: 1px solid #cbd5e1;
    background: #f1f5f9;
    color: #0f172a;
}
.audit-event-badge.ev-login {
    background: #dcfce7;
    color: #15803d;
    border-color: #86efac;
}
.audit-event-badge.ev-logout {
    background: #f1f5f9;
    color: #475569;
    border-color: #cbd5e1;
}
.audit-event-badge.ev-archive {
    background: #eff6ff;
    color: #1d4ed8;
    border-color: #bfdbfe;
}
.audit-event-badge.ev-visitor {
    background: #fef3c7;
    color: #b45309;
    border-color: #fde68a;
}
.audit-module-tag {
    display: inline-block;
    font-size: 12px;
    font-weight: 600;
    color: #334155;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 2px 8px;
    white-space: nowrap;
}

.audit-modal-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 22px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    flex-shrink: 0;
}
.audit-modal-foot-note {
    font-size: 12px;
    color: #64748b;
}
.audit-modal-foot-btn {
    height: 34px;
    padding: 0 18px;
    border-radius: 17px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1e293b;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.audit-modal-foot-btn:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}

@media print {
    .audit-modal-backdrop { display: none !important; }
}
</style>

<div class="audit-modal-backdrop" id="auditActionsModal" role="dialog" aria-modal="true" aria-labelledby="auditActionsTitle">
    <div class="audit-modal">
        <div class="audit-modal-head">
            <div class="audit-modal-head-left">
                <div class="audit-modal-icon" aria-hidden="true">&#128737;</div>
                <div>
                    <div class="audit-modal-title-row">
                        <span class="audit-modal-title" id="auditActionsTitle">Audit Actions</span>
                        <span class="audit-modal-guard-pill" id="auditActionsGuardPill"></span>
                    </div>
                    <div class="audit-modal-sub" id="auditActionsSub"></div>
                </div>
            </div>
            <button type="button" class="audit-modal-close" data-audit-close aria-label="Close">&times;</button>
        </div>
        <div class="audit-modal-body">
            <table class="audit-modal-table">
                <thead>
                    <tr>
                        <th class="col-num">#</th>
                        <th style="width:165px;">Date &amp; Time</th>
                        <th style="width:165px;">Event Type</th>
                        <th style="width:155px;">Module</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody id="auditActionsRows"></tbody>
            </table>
        </div>
        <div class="audit-modal-foot">
            <div class="audit-modal-foot-note" id="auditActionsFootNote"></div>
            <button type="button" class="audit-modal-foot-btn" data-audit-close>Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById("auditActionsModal");
    if (!modal) return;

    function esc(text) {
        const div = document.createElement("div");
        div.textContent = String(text == null ? "" : text);
        return div.innerHTML;
    }

    function eventBadgeClass(eventText) {
        const ev = String(eventText || "").toLowerCase();
        if (ev.indexOf("login") !== -1) return "ev-login";
        if (ev.indexOf("logout") !== -1) return "ev-logout";
        if (ev.indexOf("archive") !== -1 || ev.indexOf("reset") !== -1) return "ev-archive";
        if (ev.indexOf("visitor") !== -1) return "ev-visitor";
        return "";
    }

    function open(button) {
        let actions = [];
        try { actions = JSON.parse(button.getAttribute("data-actions") || "[]"); } catch (e) { actions = []; }
        const guardLabel = button.getAttribute("data-guard") || "Security Guard";
        const pillEl = document.getElementById("auditActionsGuardPill");
        if (pillEl) {
            pillEl.textContent = guardLabel;
            pillEl.style.display = guardLabel ? "inline-flex" : "none";
        }
        document.getElementById("auditActionsSub").textContent =
            actions.length + " recorded action(s) during " + <?php echo json_encode($periodDisplayLabel); ?> + " • Newest first";
        const footNote = document.getElementById("auditActionsFootNote");
        if (footNote) {
            footNote.textContent = "Showing " + actions.length + " audit event(s) for " + guardLabel;
        }
        document.getElementById("auditActionsRows").innerHTML = actions.length
            ? actions.map(function (a, idx) {
                const badgeCls = eventBadgeClass(a.event);
                return "<tr>" +
                    "<td class=\"col-num\">" + (idx + 1) + "</td>" +
                    "<td class=\"col-time\">" + esc(a.time) + "</td>" +
                    "<td><span class=\"audit-event-badge " + badgeCls + "\">" + esc(a.event) + "</span></td>" +
                    "<td><span class=\"audit-module-tag\">" + esc(a.module) + "</span></td>" +
                    "<td class=\"col-desc\">" + esc(a.description) + "</td>" +
                "</tr>";
            }).join("")
            : "<tr><td colspan=\"5\" style=\"text-align:center; padding:28px 16px; color:#64748b;\">No actions recorded for this period.</td></tr>";
        modal.classList.add("open");
        document.body.style.overflow = "hidden";
        const closeBtn = modal.querySelector("[data-audit-close]");
        if (closeBtn) closeBtn.focus();
    }

    function close() {
        modal.classList.remove("open");
        document.body.style.overflow = "";
    }

    // Delegated, so it keeps working after the section auto-refreshes
    document.addEventListener("click", function (e) {
        const button = e.target.closest(".audit-actions-link");
        if (button) { open(button); return; }
        if (e.target === modal || e.target.closest("[data-audit-close]")) close();
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && modal.classList.contains("open")) close();
    });

    // Live plate/guard search without losing input focus
    const reportSearchForm = document.getElementById("reportFilterForm");
    const reportSearchInput = reportSearchForm ? reportSearchForm.querySelector('input[name="search"]') : null;
    if (reportSearchForm && reportSearchInput) {
        let searchTimer = null;
        let activeCtrl = null;
        reportSearchInput.addEventListener("input", function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                const fd = new FormData(reportSearchForm);
                const params = new URLSearchParams();
                fd.forEach(function (val, key) {
                    if (String(val).trim() !== "") {
                        params.set(key, String(val));
                    }
                });
                const url = "report.php" + (params.toString() ? "?" + params.toString() : "");
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, "", url);
                }
                if (activeCtrl) {
                    activeCtrl.abort();
                }
                activeCtrl = new AbortController();
                fetch(url, { credentials: "same-origin", cache: "no-store", signal: activeCtrl.signal })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        const doc = new DOMParser().parseFromString(html, "text/html");
                        ["liveReportSummary", "liveReportPlateSearch", "liveReportVehicleSummary", "liveReportSection1", "liveReportSection2", "liveReportSection3", "liveReportExitAlerts"].forEach(function (id) {
                            const cur = document.getElementById(id);
                            const next = doc.getElementById(id);
                            if (cur && next) {
                                cur.innerHTML = next.innerHTML;
                            }
                        });
                    })
                    .catch(function () {});
            }, 300);
        });
    }

    // Keep Section 2 from shrinking when its list is swapped (Active <-> Archived,
    // or another page). A shorter list made the page shorter, the browser pulled
    // the page up, and the tabs jumped on screen. The card keeps the height of the
    // tallest list shown so far, so the tabs stay exactly where they are.
    function lockSection2Height(section) {
        if (!section) return;
        const current = section.offsetHeight;
        const locked = parseFloat(section.style.minHeight) || 0;
        if (current > locked) {
            section.style.minHeight = current + "px";
        }
    }

    // In-place smooth toggle for Scope Tabs (Active Logs vs Archived Data) in Section 2
    let isScopeSwitching = false;
    document.addEventListener("click", function (e) {
        const tab = e.target.closest(".scope-tab");
        if (!tab) return;

        const container = tab.closest(".scope-tabs");
        const section = tab.closest("#liveReportSection2") || document.getElementById("liveReportSection2");
        if (!section || !container) return;

        const href = tab.getAttribute("href");
        if (!href || tab.classList.contains("active") || isScopeSwitching) {
            e.preventDefault();
            return;
        }

        e.preventDefault();
        isScopeSwitching = true;
        lockSection2Height(section);

        const targetScope = tab.getAttribute("data-scope") || (href.indexOf("scope=archived") !== -1 ? "archived" : "active");

        // 1. Immediately animate sliding glider and active tab highlight smoothly
        container.setAttribute("data-active", targetScope);
        const allTabs = container.querySelectorAll(".scope-tab");
        allTabs.forEach(function (t) { t.classList.remove("active"); });
        tab.classList.add("active");

        const tableWrapper = section.querySelector(".table-wrapper");
        if (tableWrapper) {
            tableWrapper.classList.add("scope-loading");
        }

        fetch(href, { credentials: "same-origin", cache: "no-store" })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                const doc = new DOMParser().parseFromString(html, "text/html");
                const nextSection = doc.getElementById("liveReportSection2");
                if (nextSection && section) {
                    // Update subtitle & badge pill
                    const curSubtitle = section.querySelector(".section-subtitle");
                    const nextSubtitle = nextSection.querySelector(".section-subtitle");
                    if (curSubtitle && nextSubtitle) {
                        curSubtitle.innerHTML = nextSubtitle.innerHTML;
                    }
                    const curBadge = section.querySelector(".section-title .badge-pill");
                    const nextBadge = nextSection.querySelector(".section-title .badge-pill");
                    if (curBadge && nextBadge) {
                        curBadge.innerHTML = nextBadge.innerHTML;
                    }

                    // Update counts in tabs without destroying the .scope-tabs DOM container
                    const nextTabs = nextSection.querySelectorAll(".scope-tab");
                    nextTabs.forEach(function (nt) {
                        const sc = nt.getAttribute("data-scope");
                        const curCount = section.querySelector('.scope-tab[data-scope="' + sc + '"] .scope-tab-count');
                        const nextCount = nt.querySelector(".scope-tab-count");
                        if (curCount && nextCount) {
                            curCount.textContent = nextCount.textContent;
                        }
                    });

                    // Update table content with smooth fade
                    const curTableWrapper = section.querySelector(".table-wrapper");
                    const nextTableWrapper = nextSection.querySelector(".table-wrapper");
                    if (curTableWrapper && nextTableWrapper) {
                        curTableWrapper.innerHTML = nextTableWrapper.innerHTML;
                        curTableWrapper.classList.remove("scope-loading");
                        curTableWrapper.classList.add("section-swap-animating");
                        setTimeout(function () {
                            curTableWrapper.classList.remove("section-swap-animating");
                        }, 250);
                    }

                    // Update pagination
                    let curPagination = section.querySelector(".pagination");
                    const nextPagination = nextSection.querySelector(".pagination");
                    if (nextPagination) {
                        if (curPagination) {
                            curPagination.innerHTML = nextPagination.innerHTML;
                        } else {
                            section.insertAdjacentHTML("beforeend", nextPagination.outerHTML);
                        }
                    } else if (curPagination) {
                        curPagination.remove();
                    }
                }
                const nextSummary = doc.getElementById("liveReportSummary");
                const curSummary = document.getElementById("liveReportSummary");
                if (nextSummary && curSummary) {
                    curSummary.innerHTML = nextSummary.innerHTML;
                }
                const scopeMatch = href.match(/[?&]scope=([^&#]*)/);
                const scopeVal = scopeMatch ? decodeURIComponent(scopeMatch[1]) : "";
                document.querySelectorAll('input[name="scope"]').forEach(function (inp) {
                    inp.value = scopeVal;
                });
                if (window.history && window.history.pushState) {
                    window.history.pushState(null, "", href);
                }
            })
            .catch(function () {
                window.location.href = href;
            })
            .finally(function () {
                if (tableWrapper) {
                    tableWrapper.classList.remove("scope-loading");
                }
                isScopeSwitching = false;
            });
    });

    // In-place pagination for Section 2 (Vehicle Status Report & Data Archived)
    document.addEventListener("click", function (e) {
        const pageLink = e.target.closest("#liveReportSection2 .pagination a");
        if (!pageLink) return;

        const href = pageLink.getAttribute("href");
        if (!href) return;

        e.preventDefault();
        const section = document.getElementById("liveReportSection2");
        if (!section) {
            window.location.href = href;
            return;
        }
        lockSection2Height(section);

        const tableWrapper = section.querySelector(".table-wrapper");
        if (tableWrapper) {
            tableWrapper.classList.add("scope-loading");
        }

        fetch(href, { credentials: "same-origin", cache: "no-store" })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                const doc = new DOMParser().parseFromString(html, "text/html");
                const nextSection = doc.getElementById("liveReportSection2");
                if (nextSection && section) {
                    const curTableWrapper = section.querySelector(".table-wrapper");
                    const nextTableWrapper = nextSection.querySelector(".table-wrapper");
                    if (curTableWrapper && nextTableWrapper) {
                        curTableWrapper.innerHTML = nextTableWrapper.innerHTML;
                        curTableWrapper.classList.remove("scope-loading");
                        curTableWrapper.classList.add("section-swap-animating");
                        setTimeout(function () {
                            curTableWrapper.classList.remove("section-swap-animating");
                        }, 250);
                    }
                    let curPagination = section.querySelector(".pagination");
                    const nextPagination = nextSection.querySelector(".pagination");
                    if (nextPagination) {
                        if (curPagination) {
                            curPagination.innerHTML = nextPagination.innerHTML;
                        } else {
                            section.insertAdjacentHTML("beforeend", nextPagination.outerHTML);
                        }
                    } else if (curPagination) {
                        curPagination.remove();
                    }
                }
                if (window.history && window.history.pushState) {
                    window.history.pushState(null, "", href);
                }
            })
            .catch(function () {
                window.location.href = href;
            })
            .finally(function () {
                if (tableWrapper) {
                    tableWrapper.classList.remove("scope-loading");
                }
            });
    });

    // Seamless browser back/forward navigation
    window.addEventListener("popstate", function () {
        fetch(window.location.href, { credentials: "same-origin", cache: "no-store" })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                const doc = new DOMParser().parseFromString(html, "text/html");
                ["liveReportSummary", "liveReportPlateSearch", "liveReportVehicleSummary", "liveReportSection1", "liveReportSection2", "liveReportSection3", "liveReportExitAlerts"].forEach(function (id) {
                    const cur = document.getElementById(id);
                    const next = doc.getElementById(id);
                    if (cur && next) {
                        cur.innerHTML = next.innerHTML;
                    }
                });
            })
            .catch(function () {});
    });
})();
</script>

<script src="assets/js/download_format.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/download_format.js'); ?>"></script>
<script src="assets/js/auto_refresh.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/auto_refresh.js'); ?>"></script>

<script src="assets/js/logout_confirm.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/logout_confirm.js'); ?>"></script>
</body>
</html>
<?php $conn->close(); ?>

