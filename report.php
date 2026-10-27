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
   DOMPDF
========================================================= */
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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

if (!function_exists('resolveActorLabel')) {
    function resolveActorLabel($actorRaw, $guardMap, $usernameToGuardId)
    {
        $actor = trim((string)$actorRaw);
        if ($actor === '') {
            return 'Security Guard (On Duty)';
        }
        if (isset($guardMap[$actor])) {
            return $guardMap[$actor]['full_name'] . ' (' . $actor . ')';
        }
        $lower = strtolower($actor);
        if (isset($usernameToGuardId[$lower])) {
            $gid = $usernameToGuardId[$lower];
            return $guardMap[$gid]['full_name'] . ' (' . $gid . ')';
        }
        if ($lower === 'admin' || $lower === 'administrator') {
            return 'Administrator (' . $actor . ')';
        }
        return $actor;
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
function buildReportSessions($result, $guardMap, $usernameToGuardId, $dutyPairs)
{
    $sessions = [];
    $openSessions = [];

    while ($row = $result->fetch_assoc()) {
        $plate = strtoupper(trim($row['plate_number'] ?? ''));
        $direction = strtoupper(trim($row['direction'] ?? 'IN'));
        $category = ucfirst(strtolower(trim($row['vehicle_category'] ?? 'Registered')));
        $vehicleType = !empty($row['vehicle_type']) ? $row['vehicle_type'] : 'Car';
        $dateCreated = $row['date_created'] ?? null;
        $archivedAt = $row['archived_at'] ?? null;
        $archivedByRaw = trim($row['archived_by'] ?? '');

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
                'purpose'      => $row['purpose'] ?? '',
                'time_in'      => $dateCreated,
                'time_out'     => null,
                'status'       => 'Inside',
                'archived_at'  => $archivedAt,
                'archived_by'  => $archivedByRaw,
                'actor_label'  => $actorLabel,
            ];
            $sessions[] = $session;
            $openSessions[$plate][] = count($sessions) - 1;
        } elseif ($direction === 'OUT') {
            $matched = false;
            if (isset($openSessions[$plate]) && count($openSessions[$plate]) > 0) {
                $idx = array_shift($openSessions[$plate]);
                $sessions[$idx]['time_out'] = $dateCreated;
                $sessions[$idx]['status'] = 'Outside';
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
                    'purpose'      => $row['purpose'] ?? '',
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

// Fetch archived vehicle logs within the selected period (by DATE(archived_at) or DATE(date_created))
$stmtArchLogs = $conn->prepare("
    SELECT *
    FROM vehicle_logs_archive
    WHERE (DATE(archived_at) BETWEEN ? AND ? OR DATE(date_created) BETWEEN ? AND ?)
    ORDER BY date_created ASC
");
$archivedSessions = [];
if ($stmtArchLogs) {
    $stmtArchLogs->bind_param("ssss", $periodStart, $periodEnd, $periodStart, $periodEnd);
    $stmtArchLogs->execute();
    $resArchLogs = $stmtArchLogs->get_result();
    $archivedSessions = buildReportSessions($resArchLogs, $guardMap, $usernameToGuardId, $dutyPairsThisPeriod);
    $stmtArchLogs->close();
}

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
        NULL AS archived_at, 'Active Log (Not Yet Archived)' AS archived_by
    FROM vehicle_logs
    WHERE DATE(date_created) BETWEEN ? AND ?
    ORDER BY date_created ASC
");
$currentPeriodSessions = [];
if ($stmtCurrLogs) {
    $stmtCurrLogs->bind_param("ss", $periodStart, $periodEnd);
    $stmtCurrLogs->execute();
    $resCurrLogs = $stmtCurrLogs->get_result();
    $currentPeriodSessions = buildReportSessions($resCurrLogs, $guardMap, $usernameToGuardId, $dutyPairsThisPeriod);
    $stmtCurrLogs->close();
}

// Combine or filter archived sessions based on search, status (Inside/Outside), category, and time range (time_from - time_to)
$filterReportSessions = function ($sessions) use ($searchQuery, $statusFilter, $categoryFilter, $timeFrom, $timeTo, $sortBy, $sortDir) {
    if ($searchQuery !== '') {
        $q = strtolower($searchQuery);
        $sessions = array_values(array_filter($sessions, function ($s) use ($q) {
            return stripos($s['plate_number'], $q) !== false
                || stripos($s['actor_label'], $q) !== false
                || stripos($s['vehicle_type'], $q) !== false
                || stripos($s['category'], $q) !== false;
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
$allCombinedSessions      = $filterReportSessions(array_merge($archivedSessions, $currentPeriodSessions));

$insideCount  = 0;
$outsideCount = 0;
foreach ($allCombinedSessions as $cs) {
    if (($cs['status'] ?? '') === 'Inside') {
        $insideCount++;
    } else {
        $outsideCount++;
    }
}

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
        'module'      => stripos($ga['activity_type'], 'Archive') !== false ? 'Archive Management' : 'Guard Authentication',
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
        'module'      => 'Duty Roster',
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
            'module'      => $aa['vehicle_category'] . ' Logs',
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
            'module'      => 'Registered Vehicles',
            'description' => "Archived registered " . ($ra['vehicle_type'] ?? 'Vehicle') . " (" . ($ra['plate_number'] ?? '') . ") at " . ($ra['address'] ?? 'N/A') . " — " . ($ra['archive_reason'] ?? 'Manual Archive') . ".",
        ];
    }
    $stmtRegArchAudit->close();
}

// Filter & sort audit trail newest first
if ($auditType !== '' && strtolower($auditType) !== 'all') {
    $atLower = strtolower($auditType);
    $auditTrail = array_values(array_filter($auditTrail, function ($item) use ($atLower) {
        return stripos($item['event_type'], $atLower) !== false || stripos($item['module'], $atLower) !== false;
    }));
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
        <title>Administrator Deep Report | <?php echo e($systemName); ?></title>
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
            .badge-out { color: #245b9e; font-weight: bold; }
        </style>
    </head>
    <body>
        <table class="header-table">
            <tr>
                <td>
                    <div class="system-title">L. Surveillance</div>
                    <div class="system-sub">Lessandra Subdivision Security System &bull; Administrator Deep Report</div>
                </td>
                <td>
                    <div class="report-title">Security, Archive &amp; Audit Trail Report</div>
                    <div class="report-period">Basis: ' . ucfirst($report_basis) . ' &bull; Period: ' . e($periodStr) . ' &bull; Generated: ' . e($generatedAt) . '</div>
                </td>
            </tr>
        </table>

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

        <div class="section-heading">2. Data Archived &amp; Vehicle Movement Report (' . count($allCombinedSessions) . ' records)</div>
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
                    <th>Status</th>
                    <th>Date Archived</th>
                </tr>
            </thead>
            <tbody>';

    if (empty($allCombinedSessions)) {
        $pdfHtml .= '<tr><td colspan="9" style="text-align:center;">No vehicle logs or archived records matching criteria.</td></tr>';
    } else {
        $idx = 1;
        foreach ($allCombinedSessions as $row) {
            $stClass = ($row['status'] === 'Inside') ? 'badge-in' : 'badge-out';
            $archDateStr = !empty($row['archived_at']) ? formatDateTime($row['archived_at']) : 'Active Log';
            $pdfHtml .= '<tr>
                <td>' . $idx++ . '</td>
                <td>' . e($row['actor_label']) . '</td>
                <td><strong>' . e($row['plate_number']) . '</strong></td>
                <td>' . e($row['vehicle_type']) . '</td>
                <td>' . e($row['category']) . '</td>
                <td>' . e(formatTime($row['time_in'])) . '</td>
                <td>' . e(formatTime($row['time_out'])) . '</td>
                <td class="' . $stClass . '">' . e($row['status']) . '</td>
                <td>' . e($archDateStr) . '</td>
            </tr>';
        }
    }

    $pdfHtml .= '</tbody></table>

        <div class="section-heading">3. Audit Trail for Deep Report (' . count($auditTrail) . ' events)</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:125px;">Date &amp; Time</th>
                    <th style="width:140px;">Security Guard / Actor</th>
                    <th style="width:95px;">Event Type</th>
                    <th style="width:95px;">Module</th>
                    <th>Deep Report Description</th>
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
    </body>
    </html>';

    $dompdf->loadHtml($pdfHtml);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream("Security_Report_" . $report_basis . "_" . $periodStart . ".pdf", ["Attachment" => 1]);
    exit();
}

/* =========================================================
   PAGINATION FOR ARCHIVED DATA & AUDIT TRAIL
========================================================= */
$perPage = 8;

// View mode for Section 2: 'archived' (default) or 'all' (Active + Archived)
$dataScope = (isset($_GET['scope']) && $_GET['scope'] === 'all') ? 'all' : 'archived';
$activeDataRows = ($dataScope === 'all') ? $allCombinedSessions : $filteredArchivedSessions;

$archPage = isset($_GET['ap']) ? max(1, (int)$_GET['ap']) : 1;
$totalArchRows = count($activeDataRows);
$totalArchPages = max(1, (int)ceil($totalArchRows / $perPage));
if ($archPage > $totalArchPages) {
    $archPage = $totalArchPages;
}
$pagedArchRows = array_slice($activeDataRows, ($archPage - 1) * $perPage, $perPage);

$auditPerPage = 10;
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
?>
<!DOCTYPE html>
<html lang="en">
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
    background: #ffffff;
    border: 1px solid #dedede;
    border-radius: 18px;
    padding: 16px 18px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.06);
}
.summary-label {
    font-size: 12px;
    color: #666;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.summary-value {
    font-size: 28px;
    font-weight: 600;
    color: #1478e8;
    margin-top: 6px;
}
.summary-sub {
    font-size: 11px;
    color: #777;
    margin-top: 4px;
}

/* =========================================================
   FILTER BAR
========================================================= */
.filter-card {
    background: #ffffff;
    border: 1px solid #dedede;
    border-radius: 18px;
    padding: 16px 20px;
    margin-bottom: 22px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
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
    background: #f7f9fc;
    border: 1px solid #cfd9e8;
    border-radius: 18px;
    padding: 0 12px;
    height: 36px;
    font-size: 12px;
    color: #333;
}
.filter-pill input,
.filter-pill select {
    border: none;
    background: transparent;
    font-size: 12px;
    color: #111;
    outline: none;
}

/* =========================================================
   SECTION CARDS & TABLES
========================================================= */
.section-card {
    background: #ffffff;
    border: 1px solid #dedede;
    border-radius: 20px;
    padding: 20px 22px;
    margin-bottom: 24px;
    box-shadow: 0 3px 6px rgba(0,0,0,0.06);
}
.section-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding-bottom: 12px;
    margin-bottom: 14px;
    border-bottom: 1px solid #eeeeee;
}
.section-title {
    font-size: 18px;
    font-weight: 600;
    color: #111;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-subtitle {
    font-size: 12px;
    color: #666;
    margin-top: 2px;
}
.badge-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #edf5ff;
    color: #2f80ed;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 12px;
}
.scope-tabs {
    display: inline-flex;
    background: #eef4fc;
    border: 1px solid #c8dcf7;
    border-radius: 18px;
    padding: 2px;
    gap: 4px;
}
.scope-tab {
    padding: 5px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    color: #1769d3;
}
.scope-tab.active {
    background: #2f80ed;
    color: #ffffff;
}

.table-wrapper { width: 100%; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: center; }
thead th {
    text-align: center;
    font-weight: 600;
    padding: 11px 12px;
    border-bottom: 2px solid #e2e8f0;
    background: #fafbfc;
    color: #334155;
    white-space: nowrap;
    vertical-align: middle;
}
tbody td {
    padding: 11px 12px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    text-align: center;
}
tbody tr:hover { background: #fbfdff; }
.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 3px 10px;
    border-radius: 14px;
    font-size: 11px;
    font-weight: 600;
}
.status-inside { background: #e7f7ed; color: #16733b; border: 1px solid #bde5ca; }
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
}

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
    border: 1px solid #d4d4d4;
    border-radius: 6px;
    background: white;
    font-size: 12px;
    color: #333;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.pagination a:hover { background: #f0f4fa; border-color: #2f80ed; }
.pagination .active { background: #2f80ed; border-color: #2f80ed; color: white; font-weight: bold; }

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
            <div class="logo-subtitle">Administrator</div>
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
        </div>

        <div class="header-actions">
            <a href="<?php echo e(reportUrl(['download' => 'pdf'])); ?>" class="btn btn-primary">
                📥 Download Deep Report PDF
            </a>
            <button type="button" class="btn btn-outline" onclick="window.print()">
                🖨️ Print Deep Report
            </button>
        </div>
    </div>

    <!-- SUMMARY STAT CARDS -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">Active Guards (<?php echo $report_basis === 'monthly' ? 'Monthly' : 'Daily'; ?>)</div>
            <div class="summary-value"><?php echo count($activePeriodGuardsList); ?></div>
            <div class="summary-sub"><?php echo count($dutyPairsThisPeriod); ?> duty pairing session(s)</div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Data Archived on Period</div>
            <div class="summary-value"><?php echo count($filteredArchivedSessions); ?></div>
            <div class="summary-sub">Filtered by latest archive date</div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Inside vs Outside Status</div>
            <div class="summary-value" style="font-size:22px;">
                <span style="color:#16733b;"><?php echo $insideCount; ?> Inside</span>
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
                    <div style="display:inline-flex; background:#eef4fc; border:1px solid #c8dcf7; border-radius:18px; padding:2px; gap:3px;">
                        <button type="button" id="btnBasisDaily" onclick="setReportBasis('daily')"
                                style="border:none; height:28px; padding:0 14px; border-radius:16px; font-size:12px; font-weight:600; cursor:pointer; background:<?php echo $report_basis === 'daily' ? '#2f80ed' : 'transparent'; ?>; color:<?php echo $report_basis === 'daily' ? '#ffffff' : '#1769d3'; ?>;">
                            Daily
                        </button>
                        <button type="button" id="btnBasisMonthly" onclick="setReportBasis('monthly')"
                                style="border:none; height:28px; padding:0 14px; border-radius:16px; font-size:12px; font-weight:600; cursor:pointer; background:<?php echo $report_basis === 'monthly' ? '#2f80ed' : 'transparent'; ?>; color:<?php echo $report_basis === 'monthly' ? '#ffffff' : '#1769d3'; ?>;">
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

    <!-- =====================================================
         SECTION 1: SECURITY GUARDS ACTIVE ON PERIOD
    ====================================================== -->
    <section class="section-card">
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
                        <?php foreach ($activePeriodGuardsList as $wg): ?>
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
                                <td><?php echo (int)$wg['activity_count']; ?> event(s)</td>
                                <td style="color:#64748b; font-size:12px;"><?php echo e(formatDateTime($wg['last_seen'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- =====================================================
         SECTION 2: DATA THEY ARCHIVE (WITH INSIDE/OUTSIDE & HOUR RANGE)
    ====================================================== -->
    <section class="section-card">
        <div class="section-top">
            <div>
                <div class="section-title">
                    <span>&#128452; Data Archived by Security Guards &amp; Vehicle Status Report</span>
                    <span class="badge-pill"><?php echo $totalArchRows; ?> Record(s)</span>
                </div>
                <div class="section-subtitle">
                    Looked up by the date archived (latest first). Includes database <strong>Inside / Outside</strong> status and <strong>Time In – Time Out</strong> hour range filtering.
                </div>
            </div>

            <div class="scope-tabs">
                <a href="<?php echo e(reportUrl(['scope' => 'archived', 'ap' => 1])); ?>" class="scope-tab <?php echo $dataScope === 'archived' ? 'active' : ''; ?>">
                    Archived Data Only (<?php echo count($filteredArchivedSessions); ?>)
                </a>
                <a href="<?php echo e(reportUrl(['scope' => 'all', 'ap' => 1])); ?>" class="scope-tab <?php echo $dataScope === 'all' ? 'active' : ''; ?>">
                    All Logs (Active + Archived) (<?php echo count($allCombinedSessions); ?>)
                </a>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:42px;">#</th>
                        <th>Archived / Handled By</th>
                        <th>Plate Number</th>
                        <th>Vehicle Type</th>
                        <th>Category</th>
                        <th>Log Date</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Status (DB)</th>
                        <th>Date Archived</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pagedArchRows)): ?>
                        <tr>
                            <td colspan="10" class="empty-cell">
                                No archived or vehicle log data found matching your selected period, status, or hour range.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $rowNum = ($archPage - 1) * $perPage + 1; ?>
                        <?php foreach ($pagedArchRows as $ar): ?>
                            <?php
                                $logDt = !empty($ar['time_in']) ? $ar['time_in'] : ($ar['time_out'] ?? $ar['archived_at']);
                                $isInside = ($ar['status'] ?? '') === 'Inside';
                            ?>
                            <tr>
                                <td style="color:#777;"><?php echo $rowNum++; ?></td>
                                <td style="font-weight:600; color:#1e293b;"><?php echo e($ar['actor_label']); ?></td>
                                <td><span class="plate-tag"><?php echo e($ar['plate_number']); ?></span></td>
                                <td><?php echo e($ar['vehicle_type']); ?></td>
                                <td><?php echo e($ar['category']); ?></td>
                                <td style="color:#555; font-size:12px;"><?php echo e(formatShortDate($logDt)); ?></td>
                                <td><?php echo e(formatTime($ar['time_in'])); ?></td>
                                <td><?php echo e(formatTime($ar['time_out'])); ?></td>
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
    <section class="section-card">
        <div class="section-top">
            <div>
                <div class="section-title">
                    <span>&#128269; Audit Trail for Deep Report</span>
                    <span class="badge-pill"><?php echo $totalAuditRows; ?> Event(s)</span>
                </div>
                <div class="section-subtitle">
                    Chronological security audit trail of guard logins, logouts, co-duty pairings, and data archiving operations
                </div>
            </div>

            <form method="GET" action="report.php" style="display:flex; align-items:center; gap:8px;">
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
                        <th>Deep Audit Description</th>
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

</main>

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
        if (btnDaily) { btnDaily.style.background = 'transparent'; btnDaily.style.color = '#1769d3'; }
        if (btnMonthly) { btnMonthly.style.background = '#2f80ed'; btnMonthly.style.color = '#ffffff'; }
    } else {
        if (dailyBox) dailyBox.style.display = 'inline-flex';
        if (monthlyBox) monthlyBox.style.display = 'none';
        if (btnDaily) { btnDaily.style.background = '#2f80ed'; btnDaily.style.color = '#ffffff'; }
        if (btnMonthly) { btnMonthly.style.background = 'transparent'; btnMonthly.style.color = '#1769d3'; }
    }

    const form = document.getElementById('reportFilterForm');
    if (form) form.submit();
}
</script>

</body>
</html>
<?php $conn->close(); ?>

