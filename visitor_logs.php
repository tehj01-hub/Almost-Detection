<?php

require_once __DIR__ . '/session_helper.php';
require_once __DIR__ . '/system_config.php';

/* =========================================================
   START SECURITY GUARD SESSION
========================================================= */
secsysStartRoleSession('Security Guard');

/* =========================================================
   LOGIN CHECK
========================================================= */
if (!isset($_SESSION['username'])) {
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
   TODAY'S DATE
========================================================= */
$today = date("Y-m-d");
$displayDate = date("l, F d, Y");

/* =========================================================
   HELPER FUNCTIONS
========================================================= */
function e($value)
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function formatTime($datetime)
{
    if (!$datetime) {
        return "-";
    }
    return date("h:i A", strtotime($datetime));
}

function formatLogDate($log)
{
    $dt = !empty($log['time_in']) ? $log['time_in'] : (!empty($log['time_out']) ? $log['time_out'] : ($log['log_date'] ?? null));
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

/* =========================================================
   BUILD LOG SESSIONS
   Groups IN and OUT vehicle movements into complete sessions
========================================================= */
function buildVisitorSessions($result)
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
        $direction = strtoupper(trim($row['direction'] ?? 'IN'));
        $name = !empty($row['visitor_name']) ? $row['visitor_name'] : (!empty($row['joined_visitor_name']) ? $row['joined_visitor_name'] : (!empty($row['name']) ? $row['name'] : 'Visitor'));
        $vehicleType = !empty($row['vehicle_type']) ? $row['vehicle_type'] : 'Vehicle';
        $logId = isset($row['id']) ? (int)$row['id'] : 0;
        $dateCreated = $row['date_created'] ?? null;
        $archivedAt = $row['archived_at'] ?? null;
        $purpose = !empty($row['purpose']) ? $row['purpose'] : '';

        if ($direction === "IN") {
            $session = [
                "ids" => $logId > 0 ? [$logId] : [],
                "name" => $name,
                "plate_number" => $plate,
                "vehicle_type" => $vehicleType,
                "time_in" => $dateCreated,
                "time_out" => null,
                "status" => "Inside",
                "purpose" => $purpose,
                "address" => $row['address_to_visit'] ?? "",
                "category" => "Visitor",
                "log_date" => $dateCreated ? date("Y-m-d", strtotime($dateCreated)) : date("Y-m-d"),
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
                if ($archivedAt && (!$sessions[$sessionIndex]['archived_at'] || strtotime($archivedAt) > strtotime($sessions[$sessionIndex]['archived_at']))) {
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
                    "purpose" => $purpose,
                    "address" => $row['address_to_visit'] ?? "",
                    "category" => "Visitor",
                    "log_date" => $dateCreated ? date("Y-m-d", strtotime($dateCreated)) : date("Y-m-d"),
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
   SEARCH / FILTER / SORT FUNCTION
========================================================= */
function filterAndSortLogs($sessions, $searchQuery, $statusFilter, $sortBy, $sortDir, $timeFrom = '', $timeTo = '')
{
    if ($searchQuery !== '') {
        $q = strtolower($searchQuery);
        $sessions = array_values(array_filter($sessions, function ($s) use ($q) {
            return stripos($s['name'], $q) !== false || stripos($s['plate_number'], $q) !== false;
        }));
    }

    if ($statusFilter !== '' && $statusFilter !== 'all') {
        $sf = strtolower($statusFilter);
        $sessions = array_values(array_filter($sessions, function ($s) use ($sf) {
            return strtolower($s['status']) === $sf;
        }));
    }

    if ($timeFrom !== '' || $timeTo !== '') {
        $sessions = array_values(array_filter($sessions, function ($s) use ($timeFrom, $timeTo) {
            return matchesTimeRange($s['time_in'] ?? null, $s['time_out'] ?? null, $timeFrom, $timeTo);
        }));
    }

    $validSortCols = ['name', 'plate_number', 'time_in', 'time_out', 'status', 'date'];
    if ($sortBy !== '' && in_array($sortBy, $validSortCols) && count($sessions) > 0) {
        usort($sessions, function ($a, $b) use ($sortBy, $sortDir) {
            if ($sortBy === 'name') {
                $cmp = strcasecmp($a['name'], $b['name']);
            } elseif ($sortBy === 'plate_number') {
                $cmp = strcasecmp($a['plate_number'], $b['plate_number']);
            } elseif ($sortBy === 'time_in') {
                $dtA = $a['time_in'] ?: '1000-01-01';
                $dtB = $b['time_in'] ?: '1000-01-01';
                $cmp = strcmp($dtA, $dtB);
            } elseif ($sortBy === 'time_out') {
                $dtA = $a['time_out'] ?: '1000-01-01';
                $dtB = $b['time_out'] ?: '1000-01-01';
                $cmp = strcmp($dtA, $dtB);
            } elseif ($sortBy === 'status') {
                $cmp = strcasecmp($a['status'], $b['status']);
            } elseif ($sortBy === 'date') {
                $dtA = $a['time_in'] ?: ($a['time_out'] ?: '1000-01-01');
                $dtB = $b['time_in'] ?: ($b['time_out'] ?: '1000-01-01');
                $cmp = strcmp($dtA, $dtB);
            } else {
                $cmp = 0;
            }

            return $sortDir === 'desc' ? -$cmp : $cmp;
        });
    }

    return $sessions;
}

/* =========================================================
   ACTION: ARCHIVE SELECTED LOGS (MANUAL)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_selected') {
    $selectedGroups = $_POST['selected_logs'] ?? [];
    $allIds = [];

    foreach ($selectedGroups as $group) {
        $ids = explode(",", $group);
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $allIds[] = $id;
            }
        }
    }

    $allIds = array_values(array_unique($allIds));
    $actorId = trim($_SESSION['guard_id'] ?? ($_SESSION['username'] ?? 'Security Guard'));

    if (count($allIds) > 0) {
        $conn->begin_transaction();
        try {
            $placeholders = implode(",", array_fill(0, count($allIds), "?"));
            $types = "s" . str_repeat("i", count($allIds));

            $sqlArchive = "
                INSERT INTO vehicle_logs_archive
                (
                    id, plate_number, vehicle_type, vehicle_category,
                    registered_vehicle_id, visitor_vehicle_id, visitor_name,
                    purpose, direction, recognition_method, date_created, archived_at, archived_by
                )
                SELECT
                    id, plate_number, vehicle_type, vehicle_category,
                    registered_vehicle_id, visitor_vehicle_id, visitor_name,
                    purpose, direction, recognition_method, date_created, NOW(), ?
                FROM vehicle_logs
                WHERE id IN ($placeholders) AND vehicle_category = 'Visitor'
                ON DUPLICATE KEY UPDATE
                    archived_at = NOW(),
                    archived_by = VALUES(archived_by)
            ";

            $stmt = $conn->prepare($sqlArchive);
            $stmt->bind_param($types, $actorId, ...$allIds);
            $stmt->execute();
            $stmt->close();

            $sqlDelete = "
                DELETE FROM vehicle_logs
                WHERE id IN ($placeholders) AND vehicle_category = 'Visitor'
            ";
            $stmt = $conn->prepare($sqlDelete);
            $stmt->bind_param(str_repeat("i", count($allIds)), ...$allIds);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            secsysLogActivity($conn, $actorId, 'Archive', "Archived " . count($allIds) . " visitor vehicle log record(s).");
            header("Location: visitor_logs.php?archived=success&count=" . count($allIds));
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            header("Location: visitor_logs.php?archived=error");
            exit();
        }
    } else {
        header("Location: visitor_logs.php?archived=none");
        exit();
    }
}

/* =========================================================
   ACTION: ARCHIVE ALL LOGS
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_all') {
    $actorId = trim($_SESSION['guard_id'] ?? ($_SESSION['username'] ?? 'Security Guard'));
    $conn->begin_transaction();
    try {
        $sqlArchiveAll = "
            INSERT INTO vehicle_logs_archive
            (
                id, plate_number, vehicle_type, vehicle_category,
                registered_vehicle_id, visitor_vehicle_id, visitor_name,
                purpose, direction, recognition_method, date_created, archived_at, archived_by
            )
            SELECT
                id, plate_number, vehicle_type, vehicle_category,
                registered_vehicle_id, visitor_vehicle_id, visitor_name,
                purpose, direction, recognition_method, date_created, NOW(), ?
            FROM vehicle_logs
            WHERE vehicle_category = 'Visitor'
            ON DUPLICATE KEY UPDATE
                archived_at = NOW(),
                archived_by = VALUES(archived_by)
        ";
        $stmt = $conn->prepare($sqlArchiveAll);
        $stmt->bind_param("s", $actorId);
        $stmt->execute();
        $archivedCount = $stmt->affected_rows;
        $stmt->close();

        $conn->query("DELETE FROM vehicle_logs WHERE vehicle_category = 'Visitor'");
        $conn->commit();
        secsysLogActivity($conn, $actorId, 'Archive', "Archived all active visitor vehicle logs (" . max(0, $archivedCount) . " record(s)).");

        header("Location: visitor_logs.php?archived=all_success&count=" . max(0, $archivedCount));
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: visitor_logs.php?archived=error");
        exit();
    }
}

/* =========================================================
   GET REQUEST PARAMETERS
========================================================= */
$searchQuery  = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$timeFrom     = isset($_GET['time_from']) && preg_match('/^\d{2}:\d{2}$/', trim($_GET['time_from'])) ? trim($_GET['time_from']) : '';
$timeTo       = isset($_GET['time_to']) && preg_match('/^\d{2}:\d{2}$/', trim($_GET['time_to'])) ? trim($_GET['time_to']) : '';
$sortBy       = isset($_GET['sort']) ? trim($_GET['sort']) : 'time_in';
$sortDir      = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'asc' : 'desc';

// Archive parameters
$viewArchiveMode = isset($_GET['view_archive']) && $_GET['view_archive'] === '1';
$archiveBasis    = isset($_GET['archive_basis']) && in_array($_GET['archive_basis'], ['month', 'day']) ? $_GET['archive_basis'] : 'day';
$archiveDay      = isset($_GET['archive_day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['archive_day']) ? $_GET['archive_day'] : date("Y-m-d");
$currentYear     = date("Y");
if (isset($_GET['archive_month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['archive_month'])) {
    $archiveMonth = $_GET['archive_month'];
} elseif (isset($_GET['archive_month']) && (int)$_GET['archive_month'] >= 1 && (int)$_GET['archive_month'] <= 12) {
    $archiveMonth = sprintf("%04d-%02d", (int)$currentYear, (int)$_GET['archive_month']);
} else {
    $archiveMonth = date("Y-m");
}
$selectedMonthNum = (int)substr($archiveMonth, 5, 2);
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

/* =========================================================
   DAILY 12:00 AM RESET & FETCH ACTIVE VISITOR LOGS
========================================================= */
secsysAutoArchivePastLogs($conn);

$sqlActive = "
    SELECT
        vl.*,
        vv.visitor_name AS joined_visitor_name,
        vv.address_to_visit
    FROM vehicle_logs vl
    LEFT JOIN visitor_vehicles vv
        ON (vl.visitor_vehicle_id = vv.id OR vl.plate_number = vv.plate_number)
    WHERE vl.vehicle_category = 'Visitor'
      AND DATE(vl.date_created) = CURDATE()
    ORDER BY vl.date_created DESC
";

$resActive = $conn->query($sqlActive);
$allActiveLogs = [];

if ($resActive) {
    $allActiveLogs = buildVisitorSessions($resActive);
}

if (empty($allActiveLogs)) {
    $sqlTodaySummary = "
        SELECT
            vv.visitor_name,
            vv.plate_number,
            vv.vehicle_type,
            vv.purpose,
            vv.address_to_visit,
            (
                SELECT MAX(vl_in.date_created)
                FROM vehicle_logs vl_in
                WHERE vl_in.plate_number = vv.plate_number
                  AND vl_in.vehicle_category = 'Visitor'
                  AND vl_in.direction = 'IN'
                  AND DATE(vl_in.date_created) = CURDATE()
            ) AS time_in,
            (
                SELECT MAX(vl_out.date_created)
                FROM vehicle_logs vl_out
                WHERE vl_out.plate_number = vv.plate_number
                  AND vl_out.vehicle_category = 'Visitor'
                  AND vl_out.direction = 'OUT'
                  AND DATE(vl_out.date_created) = CURDATE()
            ) AS time_out
        FROM visitor_vehicles vv
        WHERE vv.status = 'IN'
    ";
    $resSummary = $conn->query($sqlTodaySummary);
    if ($resSummary) {
        while ($row = $resSummary->fetch_assoc()) {
            if (!empty($row['time_in']) || !empty($row['time_out'])) {
                $status = 'Outside';
                if (!empty($row['time_in'])) {
                    if (empty($row['time_out']) || strtotime($row['time_in']) > strtotime($row['time_out'])) {
                        $status = 'Inside';
                    }
                }
                $allActiveLogs[] = [
                    'ids' => [],
                    'name' => $row['visitor_name'],
                    'plate_number' => $row['plate_number'],
                    'vehicle_type' => $row['vehicle_type'] ?? 'Vehicle',
                    'time_in' => $row['time_in'],
                    'time_out' => $row['time_out'],
                    'status' => $status,
                    'purpose' => $row['purpose'] ?? '',
                    'address' => $row['address_to_visit'] ?? '',
                    'category' => 'Visitor',
                    'log_date' => date("Y-m-d")
                ];
            }
        }
    }
}

$filteredActiveLogs = filterAndSortLogs($allActiveLogs, $searchQuery, $statusFilter, $sortBy, $sortDir, $timeFrom, $timeTo);

/* =========================================================
   FETCH ARCHIVED VISITOR LOGS (BY DATE ARCHIVED)
========================================================= */
$archivedLogs = [];
$archiveSubtitle = "";

if ($archiveBasis === 'day') {
    $stmtArchive = $conn->prepare("
        SELECT va.*, vv.visitor_name AS joined_visitor_name, vv.address_to_visit
        FROM vehicle_logs_archive va
        LEFT JOIN visitor_vehicles vv
            ON (va.visitor_vehicle_id = vv.id OR va.plate_number = vv.plate_number)
        WHERE va.vehicle_category = 'Visitor'
          AND DATE(va.date_created) = ?
        ORDER BY va.date_created DESC
    ");
    $stmtArchive->bind_param("s", $archiveDay);
    $archiveSubtitle = date("F d, Y", strtotime($archiveDay));
} else {
    $stmtArchive = $conn->prepare("
        SELECT va.*, vv.visitor_name AS joined_visitor_name, vv.address_to_visit
        FROM vehicle_logs_archive va
        LEFT JOIN visitor_vehicles vv
            ON (va.visitor_vehicle_id = vv.id OR va.plate_number = vv.plate_number)
        WHERE va.vehicle_category = 'Visitor'
          AND DATE_FORMAT(va.date_created, '%Y-%m') = ?
        ORDER BY va.date_created DESC
    ");
    $stmtArchive->bind_param("s", $archiveMonth);
    $archiveSubtitle = date("F Y", strtotime($archiveMonth . "-01"));
}

$stmtArchive->execute();
$resArchive = $stmtArchive->get_result();
$archivedLogs = buildVisitorSessions($resArchive);
$stmtArchive->close();

$archivedLogs = filterAndSortLogs($archivedLogs, $searchQuery, $statusFilter, $sortBy, $sortDir, $timeFrom, $timeTo);

/* =========================================================
   PDF DOWNLOAD ENGINE (DOMPDF)
========================================================= */
if (isset($_GET['download'])) {
    $downloadType = $_GET['download'];

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');

    $dompdf = new Dompdf($options);
    $generatedAt = date("F d, Y h:i A");

    if ($downloadType === 'pdf') {
        $reportTitle = "Visitor Vehicle Logs Report";
        $reportDate = $displayDate;
        $records = $filteredActiveLogs;
        $pdfFilename = "Visitor_Logs_" . date("Y-m-d") . ".pdf";
    } elseif ($downloadType === 'archive_pdf') {
        $reportTitle = "Archived Visitor Vehicle Logs Report";
        $reportDate = "Archive Period: " . $archiveSubtitle;
        $records = $archivedLogs;
        $pdfFilename = "Visitor_Logs_Archive_" . ($archiveBasis === 'day' ? $archiveDay : $archiveMonth) . ".pdf";
    } else {
        $records = [];
        $reportTitle = "Report";
        $reportDate = "";
        $pdfFilename = "Report.pdf";
    }

    $pdfHtml = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . e($reportTitle) . '</title>
        <style>
            @page { margin: 30px 35px; }
            body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; margin: 0; padding: 0; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; border-bottom: 2px solid #1478e8; padding-bottom: 10px; }
            .logo-shield { width: 34px; height: 38px; background: #1478e8; color: #fff; text-align: center; font-size: 20px; font-weight: bold; line-height: 38px; }
            .system-title { font-size: 18px; font-weight: bold; color: #111; }
            .system-sub { font-size: 11px; color: #555; }
            .report-title-box { text-align: right; }
            .report-title { font-size: 16px; font-weight: bold; color: #1478e8; margin-bottom: 3px; }
            .report-date { font-size: 11px; color: #666; }
            .meta-bar { background: #f2f6fc; border-left: 4px solid #1478e8; padding: 8px 12px; margin-bottom: 16px; font-size: 11px; }
            .data-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .data-table th { background: #1478e8; color: #ffffff; font-size: 11px; font-weight: bold; text-align: left; padding: 8px 10px; border: 1px solid #1478e8; }
            .data-table td { font-size: 11px; padding: 7px 10px; border: 1px solid #dddddd; }
            .data-table tr:nth-child(even) { background: #f9fbfd; }
            .status-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: bold; }
            .status-inside { background: #dcfce7; color: #15803d; border: 1px solid #86efac; font-weight: 700; }
            .status-outside { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-weight: 700; }
            .empty-row { text-align: center; padding: 25px; color: #777; font-style: italic; }
            .footer { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 10px; color: #888; text-align: center; border-top: 1px solid #eee; padding-top: 5px; }
        </style>
    </head>
    <body>
        <table class="header-table">
            <tr>
                <td style="width: 45px; vertical-align: middle;">
                    <div class="logo-shield">&#10003;</div>
                </td>
                <td style="vertical-align: middle;">
                    <div class="system-title">' . e($systemName) . '</div>
                    <div class="system-sub">Security Management System &bull; Security Guard Report</div>
                </td>
                <td class="report-title-box" style="vertical-align: middle;">
                    <div class="report-title">' . e($reportTitle) . '</div>
                    <div class="report-date">' . e($reportDate) . '</div>
                </td>
            </tr>
        </table>

        <div class="meta-bar">
            <strong>Generated Date:</strong> ' . e($generatedAt) . ' &nbsp;|&nbsp;
            <strong>Total Records:</strong> ' . count($records) . ' &nbsp;|&nbsp;
            <strong>Generated By:</strong> ' . e($_SESSION['username'] ?? 'Security Guard') . '
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th>Plate Number</th>
                    <th>Vehicle Type</th>
                    <th>Purpose</th>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th style="width: 70px;">Status</th>
                </tr>
            </thead>
            <tbody>';


    if (empty($records)) {
        $pdfHtml .= '<tr><td colspan="8" class="empty-row">No visitor vehicle logs found.</td></tr>';
    } else {
        $i = 1;
        foreach ($records as $row) {
            $purposeStr = !empty($row['purpose']) ? $row['purpose'] : '-';
            $dateStr = formatLogDate($row);
            $timeInStr = $row['time_in'] ? date("h:i A", strtotime($row['time_in'])) : "-";
            $timeOutStr = $row['time_out'] ? date("h:i A", strtotime($row['time_out'])) : "-";
            $statusClass = $row['status'] === 'Inside' ? 'status-inside' : 'status-outside';

            $pdfHtml .= '<tr>
                <td style="text-align: center;">' . $i++ . '</td>
                <td>' . e($row['plate_number']) . '</td>
                <td>' . e($row['vehicle_type']) . '</td>
                <td>' . e($purposeStr) . '</td>
                <td>' . e($dateStr) . '</td>
                <td>' . e($timeInStr) . '</td>
                <td>' . e($timeOutStr) . '</td>
                <td><span class="status-tag ' . $statusClass . '">' . e($row['status']) . '</span></td>
            </tr>';
        }
    }

    $pdfHtml .= '</tbody>
        </table>

        <div class="footer">
            ' . e($systemName) . ' Security System &bull; Confidential &bull; Generated on ' . e($generatedAt) . '
        </div>
    </body>
    </html>';

    $dompdf->loadHtml($pdfHtml);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream($pdfFilename, ["Attachment" => 1]);
    exit();
}

/* =========================================================
   PAGINATION SETUP (ACTIVE LOGS)
========================================================= */
$itemsPerPage = 8;
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$totalRecords = count($filteredActiveLogs);
$totalPages = max(1, ceil($totalRecords / $itemsPerPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $itemsPerPage;
$displayActiveLogs = array_slice($filteredActiveLogs, $offset, $itemsPerPage);

/* =========================================================
   PAGINATION SETUP (ARCHIVED LOGS)
========================================================= */
$archivePage = isset($_GET['ap']) ? max(1, (int)$_GET['ap']) : 1;
$totalArchiveRecords = count($archivedLogs);
$totalArchivePages = max(1, ceil($totalArchiveRecords / $itemsPerPage));
if ($archivePage > $totalArchivePages) {
    $archivePage = $totalArchivePages;
}
$archiveOffset = ($archivePage - 1) * $itemsPerPage;
$displayArchivedLogs = array_slice($archivedLogs, $archiveOffset, $itemsPerPage);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Visitor Logs | <?php echo e($systemName); ?></title>
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
   SIDEBAR
========================================================= */
.sidebar {
    position: fixed; top: 0; left: 0; width: 212px; height: 100vh;
    background: #ffffff; border-right: 1px solid #dddddd;
    padding: 32px 14px; z-index: 1000; display: flex; flex-direction: column;
    font-family: Arial, Helvetica, sans-serif; box-sizing: border-box;
}
.logo { display: flex; align-items: center; gap: 10px; padding-left: 3px; margin-bottom: 38px; font-family: Arial, Helvetica, sans-serif; }
.logo-shield {
    width: 34px; height: 38px; background: #1478e8;
    clip-path: polygon(50% 0%, 90% 15%, 90% 58%, 75% 82%, 50% 100%, 25% 82%, 10% 58%, 10% 15%);
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 18px; font-weight: bold; flex-shrink: 0; font-family: Arial, Helvetica, sans-serif;
}
.logo-text { line-height: 17px; font-family: Arial, Helvetica, sans-serif; }
.logo-title { font-size: 17px; font-weight: 500; white-space: nowrap; color: #111; font-family: Arial, Helvetica, sans-serif; }
.logo-subtitle { font-size: 12px; color: #444; font-family: Arial, Helvetica, sans-serif; }
.nav { display: flex; flex-direction: column; gap: 7px; width: 100%; font-family: Arial, Helvetica, sans-serif; }
.nav a {
    width: 100%; min-height: 43px; display: flex; align-items: center; gap: 12px;
    padding: 0 14px; border-radius: 22px; text-decoration: none; color: #111;
    font-size: 16px; font-family: Arial, Helvetica, sans-serif;
    transition: background 0.2s ease, color 0.2s ease; box-sizing: border-box;
}
.nav a:hover { background: #edf5ff; }
.nav a.active { background: #2f80ed; color: white; }
.nav-icon { width: 21px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; font-family: Arial, Helvetica, sans-serif; }
.logout {
    margin-top: auto; width: 100%; min-height: 38px; border: 1px solid #dddddd;
    border-radius: 18px; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    display: flex; align-items: center; justify-content: center; color: #111;
    text-decoration: none; font-size: 15px; font-family: Arial, Helvetica, sans-serif;
    transition: background 0.2s ease; box-sizing: border-box;
}
.logout:hover { background: #f3f3f3; }

/* =========================================================
   MAIN CONTENT
========================================================= */
.main {
    margin-left: 212px; min-height: 100vh; padding: 32px 38px 40px;
    width: calc(100% - 212px); box-sizing: border-box;
}
.header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.header h1 { font-size: 25px; font-weight: 500; line-height: 1.1; }
.header p { font-size: 14px; color: #666; margin-top: 3px; }

/* =========================================================
   NOTICES & ALERTS
========================================================= */
.notice { padding: 10px 16px; border-radius: 12px; font-size: 13px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; }
.notice.success { background: #e7f7ed; color: #16733b; border: 1px solid #bde5ca; }
.notice.error { background: #ffeaea; color: #a52222; border: 1px solid #f0bcbc; }
.notice.info { background: #edf5ff; color: #245b9e; border: 1px solid #c9ddf7; }

/* =========================================================
   CONTROLS & ACTION BAR
========================================================= */
.action-card {
    background: #ffffff; border: 1px solid #dedede; border-radius: 18px;
    padding: 14px 18px; box-shadow: 0 2px 4px rgba(0,0,0,0.06);
    margin-bottom: 18px; display: flex; flex-direction: column; gap: 12px;
}
.toolbar-top { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.filter-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.search-input-box {
    display: flex; align-items: center; background: #f7f9fc; border: 1px solid #cfd9e8;
    border-radius: 20px; padding: 0 12px; height: 36px; min-width: 220px;
}
.search-input-box input { border: none; outline: none; background: transparent; font-size: 13px; width: 100%; color: #111; }
.custom-select {
    height: 36px; border: 1px solid #cfd9e8; border-radius: 18px; padding: 0 12px;
    background: #f7f9fc; font-size: 13px; color: #333; outline: none; cursor: pointer;
}
.custom-select:focus { border-color: #2f80ed; }
.btn-group { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.btn { height: 36px; padding: 0 16px; border: none; border-radius: 18px; font-size: 13px; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; transition: background 0.2s ease, opacity 0.2s ease; box-sizing: border-box; }
.btn-primary { background: #2f80ed; color: white; }
.btn-primary:hover { background: #176bd1; }
.btn-secondary { background: #ffffff; color: #2f80ed; border: 1px solid #2f80ed; }
.btn-secondary:hover { background: #edf5ff; }
.btn-archive { background: #6c5ce7; color: white; }
.btn-archive:hover { background: #5b4cc4; }
.btn-danger { background: #e74c3c; color: white; }
.btn-danger:hover { background: #c0392b; }
.btn-outline { background: white; border: 1px solid #cfd9e8; color: #444; }
.btn-outline:hover { background: #f4f6fa; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* =========================================================
   TABLE CARD
========================================================= */
.table-card {
    width: 100%; background: #ffffff; border: 1px solid #dedede;
    border-radius: 20px; box-shadow: 0 3px 6px rgba(0,0,0,0.06);
    padding: 18px 20px; margin-bottom: 20px; overflow: hidden;
}
.table-header-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #eee; flex-wrap: wrap; gap: 8px; }
.table-title { font-size: 16px; font-weight: 600; color: #222; display: flex; align-items: center; gap: 8px; }
.badge-count { background: #edf5ff; color: #2f80ed; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: bold; }
.table-wrapper { width: 100%; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: center; }
thead th { text-align: center; font-weight: 600; padding: 10px 12px; border-bottom: 2px solid #e0e0e0; background: #fafbfc; color: #444; white-space: nowrap; vertical-align: middle; }
tbody td { padding: 11px 12px; border-bottom: 1px solid #f0f0f0; white-space: nowrap; vertical-align: middle; text-align: center; }
tbody tr:hover { background: #fbfdff; }
tbody tr:last-child td { border-bottom: none; }
.checkbox-col { width: 38px; text-align: center; }
.checkbox-custom { width: 15px; height: 15px; cursor: pointer; accent-color: #2f80ed; }
.status-badge { display: inline-flex; align-items: center; justify-content: center; margin: 0 auto; padding: 3px 10px; border-radius: 14px; font-size: 11px; font-weight: 600; }
.status-inside { background: #dcfce7; color: #15803d; border: 1px solid #86efac; font-weight: 700; }
.status-outside { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-weight: 700; }
.empty-cell { text-align: center; padding: 36px 12px; color: #888; font-size: 13px; }

/* =========================================================
   PAGINATION
========================================================= */
.pagination { display: flex; justify-content: center; align-items: center; gap: 5px; margin-top: 16px; }
.pagination a, .pagination span { min-width: 28px; height: 28px; padding: 0 6px; border: 1px solid #d4d4d4; border-radius: 6px; background: white; font-size: 12px; color: #333; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
.pagination a:hover { background: #f0f4fa; border-color: #2f80ed; }
.pagination .active { background: #2f80ed; border-color: #2f80ed; color: white; font-weight: bold; }
.pagination .disabled { opacity: 0.4; cursor: not-allowed; }

/* =========================================================
   ARCHIVE MODAL / VIEWER
========================================================= */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.45); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; }
.modal-overlay.open { display: flex; }
.modal-content { background: #ffffff; width: 100%; max-width: 1050px; max-height: 90vh; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.25); display: flex; flex-direction: column; overflow: hidden; }
.modal-header { padding: 18px 24px; border-bottom: 1px solid #e5e5e5; display: flex; align-items: center; justify-content: space-between; background: #fafbfc; }
.modal-header h2 { font-size: 18px; font-weight: 600; color: #111; display: flex; align-items: center; gap: 8px; }
.close-btn { background: transparent; border: none; font-size: 24px; line-height: 1; color: #777; cursor: pointer; }
.close-btn:hover { color: #111; }
.modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }
.archive-filter-bar { display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px 16px; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.archive-mode-toggle { display: flex; align-items: center; background: #e2e8f0; border-radius: 18px; padding: 2px; }
.archive-mode-btn { padding: 6px 14px; border: none; background: transparent; border-radius: 16px; font-size: 12px; font-weight: 500; color: #555; cursor: pointer; }
.archive-mode-btn.active { background: #ffffff; color: #2f80ed; box-shadow: 0 1px 3px rgba(0,0,0,0.1); font-weight: 600; }
.date-selector-container { display: flex; align-items: center; gap: 8px; }
.date-picker-input { height: 34px; border: 1px solid #cbd5e1; border-radius: 14px; padding: 0 10px; font-size: 13px; color: #333; background: white; }

/* =========================================================
   RESPONSIVE MEDIA QUERIES
========================================================= */
@media (max-width: 800px) {
    .sidebar { width: 76px; padding: 24px 8px; }
    .logo { justify-content: center; padding: 0; }
    .logo-text { display: none; }
    .nav a { justify-content: center; padding: 0; }
    .nav a span:not(.nav-icon) { display: none; }
    .logout { font-size: 0; }
    .logout::before { content: "⇥"; font-size: 20px; }
    .main { margin-left: 76px; width: calc(100% - 76px); padding: 24px 18px 30px; }
}
@media (max-width: 600px) {
    .main { padding: 16px 12px 25px; }
    .header h1 { font-size: 20px; }
    .search-input-box { min-width: 100%; }
    .btn { width: 100%; }
}

/* =========================================================
   PRINT STYLES
========================================================= */
@media print {
    .sidebar, .action-card, .btn-group, .pagination, .modal-overlay, .notice { display: none !important; }
    .main { margin-left: 0 !important; width: 100% !important; padding: 0 !important; }
    .table-card { box-shadow: none; border: 1px solid #ccc; padding: 0; }
}
</style>
</head>

<body>




<!-- =====================================================
     SIDEBAR
===================================================== -->
<aside class="sidebar">
    <div class="logo">
        <div class="logo-shield" style="overflow:hidden;">
            <?php if (!empty($systemIcon)): ?>
                <img src="<?php echo e($systemIcon); ?>" alt="Icon" style="width:100%; height:100%; object-fit:cover;">
            <?php else: ?>
                &#10003;
            <?php endif; ?>
        </div>
        <div class="logo-text">
            <div class="logo-title"><?php echo e($systemName); ?></div>
            <div class="logo-subtitle">Security Guard</div>
        </div>
    </div>

    <nav class="nav">
        <a href="guard_dashboard.php">
            <span class="nav-icon">🖥️</span>
            <span>Dashboard</span>
        </a>
        <a href="registered_logs.php">
            <span class="nav-icon">🚗</span>
            <span>Registered</span>
        </a>
        <a href="visitor_logs.php" class="active">
            <span class="nav-icon">👥</span>
            <span>Visitor</span>
        </a>
    </nav>

    <a href="logout.php?role=Security%20Guard" class="logout"><span class="nav-icon">🚪</span><span>Logout</span></a>
</aside>

<!-- =====================================================
     MAIN CONTENT
===================================================== -->
<main class="main">

    <!-- HEADER -->
    <div class="header">
        <div>
            <h1>Visitor Vehicle Logs</h1>
            <p><?php echo htmlspecialchars($displayDate); ?> &bull; <span style="font-size:12px; color:#4b5563; font-weight:500;">(Resets daily at 12:00 AM)</span></p>
        </div>

        <div class="btn-group">
            <a href="visitor_logs.php?download=pdf<?php echo $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : ''; ?><?php echo $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $timeFrom !== '' ? '&time_from=' . urlencode($timeFrom) : ''; ?><?php echo $timeTo !== '' ? '&time_to=' . urlencode($timeTo) : ''; ?><?php echo $sortBy !== '' ? '&sort=' . urlencode($sortBy) . '&dir=' . urlencode($sortDir) : ''; ?>" class="btn btn-primary" title="Download Current Logs as PDF">📥 Download PDF</a>
            <button type="button" class="btn btn-secondary" onclick="openArchiveModal()">📦 View Archives</button>
            <button type="button" class="btn btn-outline" onclick="window.print()">🖨️ Print</button>
        </div>
    </div>

    <!-- NOTICES -->
    <?php if (isset($_GET['archived'])): ?>
        <?php if ($_GET['archived'] === 'success'): ?>
            <div class="notice success">
                <span>&#10004; Selected visitor logs (<?php echo (int)($_GET['count'] ?? 1); ?>) have been moved to archive successfully.</span>
                <a href="visitor_logs.php" style="text-decoration:none; color:inherit; font-weight:bold;">&times;</a>
            </div>
        <?php elseif ($_GET['archived'] === 'all_success'): ?>
            <div class="notice success">
                <span>&#10004; All active visitor vehicle logs (<?php echo (int)($_GET['count'] ?? 0); ?>) have been moved to archive successfully.</span>
                <a href="visitor_logs.php" style="text-decoration:none; color:inherit; font-weight:bold;">&times;</a>
            </div>
        <?php elseif ($_GET['archived'] === 'none'): ?>
            <div class="notice info">
                <span>&#9888; No logs were selected for archiving. Please select rows using the checkboxes.</span>
                <a href="visitor_logs.php" style="text-decoration:none; color:inherit; font-weight:bold;">&times;</a>
            </div>
        <?php elseif ($_GET['archived'] === 'error'): ?>
            <div class="notice error">
                <span>&#9888; An error occurred while archiving the logs. Please try again.</span>
                <a href="visitor_logs.php" style="text-decoration:none; color:inherit; font-weight:bold;">&times;</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>


    <!-- =================================================
         SEARCH, FILTER & ARCHIVE CONTROLS
    ================================================== -->
    <div class="action-card">
        <form method="GET" action="visitor_logs.php" id="filterForm" class="toolbar-top">
            <div class="filter-group">
                <div class="search-input-box">
                    <span style="color:#888; margin-right:6px;">&#128269;</span>
                    <input type="text" name="search" id="searchInput" placeholder="Search plate number..." value="<?php echo e($searchQuery); ?>">
                    <?php if ($searchQuery !== ''): ?>
                        <a href="visitor_logs.php" style="text-decoration:none; color:#999; font-size:14px; margin-left:4px;">&times;</a>
                    <?php endif; ?>
                </div>

                <select name="status" class="custom-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Statuses</option>
                    <option value="Inside" <?php echo $statusFilter === 'Inside' ? 'selected' : ''; ?>>Inside</option>
                    <option value="Outside" <?php echo $statusFilter === 'Outside' ? 'selected' : ''; ?>>Outside</option>
                </select>

                <select name="sort" class="custom-select" onchange="document.getElementById('filterForm').submit()">
                    <option value="time_in" <?php echo $sortBy === 'time_in' && $sortDir === 'desc' ? 'selected' : ''; ?>>Time In (Newest)</option>
                    <option value="time_in" <?php echo $sortBy === 'time_in' && $sortDir === 'asc' ? 'selected' : ''; ?>>Time In (Oldest)</option>
                    <option value="plate_number" <?php echo $sortBy === 'plate_number' ? 'selected' : ''; ?>>Plate Number</option>
                    <option value="status" <?php echo $sortBy === 'status' ? 'selected' : ''; ?>>Status (Inside/Outside)</option>
                </select>
                <input type="hidden" name="dir" value="<?php echo e($sortDir); ?>">

                <div style="display:flex; align-items:center; gap:6px; background:#f7f9fc; border:1px solid #cfd9e8; border-radius:18px; padding:0 12px; height:36px;">
                    <span style="font-size:12px; color:#555; font-weight:600;">Hours:</span>
                    <input type="time" name="time_from" value="<?php echo e($timeFrom); ?>" title="Time In / From Hour" style="border:none; background:transparent; font-size:12px; color:#222; outline:none;" onchange="document.getElementById('filterForm').submit()">
                    <span style="font-size:12px; color:#888;">to</span>
                    <input type="time" name="time_to" value="<?php echo e($timeTo); ?>" title="Time Out / To Hour" style="border:none; background:transparent; font-size:12px; color:#222; outline:none;" onchange="document.getElementById('filterForm').submit()">
                    <?php if ($timeFrom !== '' || $timeTo !== ''): ?>
                        <a href="visitor_logs.php?<?php echo http_build_query(array_filter(['search' => $searchQuery, 'status' => $statusFilter, 'sort' => $sortBy, 'dir' => $sortDir])); ?>" style="text-decoration:none; color:#999; font-size:14px;" title="Clear time range">&times;</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="btn-group">
                <button type="button" class="btn btn-archive" id="archiveSelectedBtn" onclick="submitArchiveSelected()" disabled>
                    📦 Archive Selected (<span id="selectedCount">0</span>)
                </button>
                <button type="button" class="btn btn-danger" onclick="confirmArchiveAll()">
                    📦 Archive All Logs
                </button>
            </div>
        </form>
    </div>

    <!-- =================================================
         MAIN LOGS TABLE
    ================================================== -->
    <div class="table-card" id="liveActiveLogs" data-live>
        <div class="table-header-bar">
            <div class="table-title">
                <span>Active Visitor Logs</span>
                <span class="badge-count"><?php echo count($filteredActiveLogs); ?> total</span>
            </div>
            <div style="font-size:12px; color:#777;">
                Showing <?php echo count($displayActiveLogs); ?> of <?php echo count($filteredActiveLogs); ?> records
            </div>
        </div>

        <form method="POST" action="visitor_logs.php" id="archiveForm">
            <input type="hidden" name="action" id="archiveActionInput" value="archive_selected">

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th class="checkbox-col">
                                <input type="checkbox" id="selectAllCheckbox" class="checkbox-custom" onclick="toggleSelectAll(this)">
                            </th>
                            <th>Plate Number</th>
                            <th>Vehicle Type</th>
                            <th>Purpose</th>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Stay Timer</th>
                        </tr>
                    </thead>
                    <tbody id="activeLogsTableBody">
                        <?php if (empty($displayActiveLogs)): ?>
                            <tr>
                                <td colspan="8" class="empty-cell">
                                    &#128269; No visitor vehicle logs found matching your criteria.
                                    <?php if ($searchQuery !== '' || $statusFilter !== '' || $timeFrom !== '' || $timeTo !== ''): ?>
                                        <br><a href="visitor_logs.php" style="color:#2f80ed; font-size:12px; margin-top:6px; display:inline-block;">Clear Filters</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($displayActiveLogs as $log): ?>
                                <?php
                                    $logIdsStr = !empty($log['ids']) ? implode(",", $log['ids']) : "";
                                    $hasIds = !empty($log['ids']);
                                    $isInsideRow = (($log['status'] ?? '') === 'Inside');
                                ?>
                                <tr>
                                    <td class="checkbox-col">
                                        <input type="checkbox" name="selected_logs[]" value="<?php echo e($logIdsStr); ?>" class="checkbox-custom row-checkbox" onchange="updateSelectedCount()" <?php echo !$hasIds ? 'disabled title="No direct raw log ID"' : ''; ?>>
                                    </td>
                                    <td>
                                        <span style="font-family:monospace; font-size:13px; font-weight:600; background:#f0f4f8; padding:2px 6px; border-radius:4px;">
                                            <?php echo htmlspecialchars($log['plate_number']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['vehicle_type']); ?></td>
                                    <td style="color:#555;"><?php echo htmlspecialchars($log['purpose'] ?? '-'); ?></td>
                                    <td style="color:#555; font-size:12px;"><?php echo htmlspecialchars(formatLogDate($log)); ?></td>
                                    <td><?php echo formatTime($log['time_in']); ?></td>
                                    <td><?php echo formatTime($log['time_out']); ?></td>
                                    <td>
                                        <span class="vda-inline-timer" data-vda-plate="<?php echo e($log['plate_number']); ?>" data-vda-status="<?php echo $isInsideRow ? 'IN' : 'OUT'; ?>">
                                            <?php echo $isInsideRow ? '⏱ Active' : '✓ Completed'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>


        <!-- ACTIVE PAGINATION -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="visitor_logs.php?p=<?php echo ($page - 1); ?><?php echo $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : ''; ?><?php echo $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $sortBy !== '' ? '&sort=' . urlencode($sortBy) . '&dir=' . urlencode($sortDir) : ''; ?>">&lsaquo;</a>
                <?php else: ?>
                    <span class="disabled">&lsaquo;</span>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="visitor_logs.php?p=<?php echo $i; ?><?php echo $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : ''; ?><?php echo $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $sortBy !== '' ? '&sort=' . urlencode($sortBy) . '&dir=' . urlencode($sortDir) : ''; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="visitor_logs.php?p=<?php echo ($page + 1); ?><?php echo $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : ''; ?><?php echo $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $sortBy !== '' ? '&sort=' . urlencode($sortBy) . '&dir=' . urlencode($sortDir) : ''; ?>">&rsaquo;</a>
                <?php else: ?>
                    <span class="disabled">&rsaquo;</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- =====================================================
     ARCHIVES MODAL VIEWER
===================================================== -->
<div class="modal-overlay <?php echo $viewArchiveMode ? 'open' : ''; ?>" id="archiveModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>
                <span>📦 Visitor Vehicle Archives</span>
                <span style="font-size:13px; font-weight:normal; color:#666;">(<?php echo e($archiveSubtitle); ?>)</span>
            </h2>
            <button type="button" class="close-btn" onclick="closeArchiveModal()">&times;</button>
        </div>

        <div class="modal-body">
            <form method="GET" action="visitor_logs.php" id="archiveFilterForm" class="archive-filter-bar" onsubmit="event.preventDefault(); loadArchiveLogsFilter(1);">
                <input type="hidden" name="view_archive" value="1">
                <input type="hidden" name="archive_basis" id="archiveBasisInput" value="<?php echo e($archiveBasis); ?>">

                <div class="archive-mode-toggle">
                    <button type="button" class="archive-mode-btn <?php echo $archiveBasis === 'day' ? 'active' : ''; ?>" onclick="setArchiveBasis('day', this)">📅 Daily</button>
                    <button type="button" class="archive-mode-btn <?php echo $archiveBasis === 'month' ? 'active' : ''; ?>" onclick="setArchiveBasis('month', this)">🗓️ Monthly</button>
                </div>

                <div class="date-selector-container">
                    <div id="dayPickerContainer" style="<?php echo $archiveBasis === 'day' ? 'display:flex; align-items:center; gap:6px;' : 'display:none;'; ?>">
                        <label style="font-size:12px; color:#555;">Select Date:</label>
                        <input type="date" name="archive_day" id="archiveDayInput" class="date-picker-input" value="<?php echo e($archiveDay); ?>" onchange="loadArchiveLogsFilter(1)">
                    </div>

                    <div id="monthPickerContainer" style="<?php echo $archiveBasis === 'month' ? 'display:flex; align-items:center; gap:6px;' : 'display:none;'; ?>">
                        <label style="font-size:12px; color:#555;">Select Month:</label>
                        <select name="archive_month" id="archiveMonthInput" class="date-picker-input" onchange="loadArchiveLogsFilter(1)">
                            <?php foreach ($monthNames as $mNum => $mName): ?>
                                <option value="<?php echo sprintf('%04d-%02d', (int)$currentYear, (int)$mNum); ?>" <?php echo (int)$selectedMonthNum === (int)$mNum ? 'selected' : ''; ?>>
                                    <?php echo e($mName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="btn-group">
                    <a href="visitor_logs.php?download=archive_pdf&archive_basis=<?php echo e($archiveBasis); ?>&archive_month=<?php echo e($archiveMonth); ?>&archive_day=<?php echo e($archiveDay); ?><?php echo $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : ''; ?>" class="btn btn-primary" style="height:34px; font-size:12px;">
                        📥 Download Archive PDF
                    </a>
                </div>
            </form>

<!-- ARCHIVE TABLE -->
            <div class="table-card" style="box-shadow:none; border:1px solid #e2e8f0; padding:12px 16px;">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:35px;">#</th>
                                <th>Plate Number</th>
                                <th>Vehicle Type</th>
                                <th>Purpose</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Date Archived</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($displayArchivedLogs)): ?>
                                <tr>
                                    <td colspan="7" class="empty-cell">
                                        &#128193; No archived visitor logs found for <?php echo e($archiveSubtitle); ?>.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $idx = $archiveOffset + 1; ?>
                                <?php foreach ($displayArchivedLogs as $alog): ?>
                                    <tr>
                                        <td style="color:#777;"><?php echo $idx++; ?></td>
                                        <td>
                                            <span style="font-family:monospace; font-size:13px; font-weight:600; background:#f0f4f8; padding:2px 6px; border-radius:4px;">
                                                <?php echo htmlspecialchars($alog['plate_number']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($alog['vehicle_type']); ?></td>
                                        <td style="color:#555;"><?php echo htmlspecialchars($alog['purpose'] ?? '-'); ?></td>
                                        <td><?php echo formatTime($alog['time_in']); ?></td>
                                        <td><?php echo formatTime($alog['time_out']); ?></td>
                                        <td style="color:#666; font-size:12px;">
                                            <?php echo htmlspecialchars(!empty($alog['archived_at']) ? date("M d, Y h:i A", strtotime($alog['archived_at'])) : formatLogDate($alog)); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
<!-- ARCHIVE PAGINATION -->
                <?php if ($totalArchivePages > 1): ?>
                    <div class="pagination">
                        <?php if ($archivePage > 1): ?>
                            <a href="visitor_logs.php?view_archive=1&archive_basis=<?php echo e($archiveBasis); ?>&archive_month=<?php echo e($archiveMonth); ?>&archive_day=<?php echo e($archiveDay); ?>&ap=<?php echo ($archivePage - 1); ?>">&lsaquo;</a>
                        <?php else: ?>
                            <span class="disabled">&lsaquo;</span>
                        <?php endif; ?>

                        <?php for ($j = 1; $j <= $totalArchivePages; $j++): ?>
                            <?php if ($j === $archivePage): ?>
                                <span class="active"><?php echo $j; ?></span>
                            <?php else: ?>
                                <a href="visitor_logs.php?view_archive=1&archive_basis=<?php echo e($archiveBasis); ?>&archive_month=<?php echo e($archiveMonth); ?>&archive_day=<?php echo e($archiveDay); ?>&ap=<?php echo $j; ?>"><?php echo $j; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($archivePage < $totalArchivePages): ?>
                            <a href="visitor_logs.php?view_archive=1&archive_basis=<?php echo e($archiveBasis); ?>&archive_month=<?php echo e($archiveMonth); ?>&archive_day=<?php echo e($archiveDay); ?>&ap=<?php echo ($archivePage + 1); ?>">&rsaquo;</a>
                        <?php else: ?>
                            <span class="disabled">&rsaquo;</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
/* --- Archive Modal Open / Close --- */
function openArchiveModal() {
    document.getElementById("archiveModal").classList.add("open");
}

function closeArchiveModal() {
    document.getElementById("archiveModal").classList.remove("open");
    if (window.location.search.includes("view_archive=1")) {
        const url = new URL(window.location.href);
        url.searchParams.delete("view_archive");
        url.searchParams.delete("ap");
        window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ""));
    }
}

/* --- Toggle Archive Basis (Month vs Day) --- */
function setArchiveBasis(basis, btn) {
    document.getElementById("archiveBasisInput").value = basis;
    if (basis === 'month') {
        document.getElementById("monthPickerContainer").style.display = "flex";
        document.getElementById("dayPickerContainer").style.display = "none";
    } else {
        document.getElementById("monthPickerContainer").style.display = "none";
        document.getElementById("dayPickerContainer").style.display = "flex";
    }
    document.querySelectorAll(".archive-mode-btn").forEach(b => b.classList.remove("active"));
    if (btn) btn.classList.add("active");
    loadArchiveLogsFilter(1);
}

function loadArchiveLogsFilter(archivePage) {
    const ap = archivePage || 1;
    const basis = document.getElementById("archiveBasisInput") ? document.getElementById("archiveBasisInput").value : "day";
    const day = document.getElementById("archiveDayInput") ? document.getElementById("archiveDayInput").value : "";
    const month = document.getElementById("archiveMonthInput") ? document.getElementById("archiveMonthInput").value : "";
    const url = "visitor_logs.php?view_archive=1&archive_basis=" + encodeURIComponent(basis) + "&archive_day=" + encodeURIComponent(day) + "&archive_month=" + encodeURIComponent(month) + "&ap=" + encodeURIComponent(ap);

    fetch(url, { credentials: "same-origin" })
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, "text/html");
            const newModal = doc.getElementById("archiveModal");
            const currentModal = document.getElementById("archiveModal");
            if (newModal && currentModal) {
                currentModal.innerHTML = newModal.innerHTML;
                currentModal.classList.add("open");
                bindVisitorArchiveLinks();
            }
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, document.title, url);
            }
        })
        .catch(() => {
            window.location.href = url;
        });
}

function bindVisitorArchiveLinks() {
    document.querySelectorAll("#archiveModal .pagination a").forEach(link => {
        link.addEventListener("click", function(e) {
            const href = link.getAttribute("href");
            if (!href) return;
            e.preventDefault();
            const params = new URLSearchParams(href.split("?")[1] || "");
            loadArchiveLogsFilter(params.get("ap") || 1);
        });
    });
}
document.addEventListener("DOMContentLoaded", bindVisitorArchiveLinks);

/* --- Row Checkbox Selection & Counter --- */
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll(".row-checkbox:not(:disabled)");
    checkboxes.forEach(cb => cb.checked = master.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checked = document.querySelectorAll(".row-checkbox:checked");
    const countSpan = document.getElementById("selectedCount");
    const archiveBtn = document.getElementById("archiveSelectedBtn");
    const master = document.getElementById("selectAllCheckbox");
    const all = document.querySelectorAll(".row-checkbox:not(:disabled)");

    if (countSpan) countSpan.textContent = checked.length;
    if (archiveBtn) archiveBtn.disabled = (checked.length === 0);

    if (master && all.length > 0) {
        master.checked = (checked.length === all.length);
    }
}

/* --- Submit Selected Archives --- */
function submitArchiveSelected() {
    const checked = document.querySelectorAll(".row-checkbox:checked");
    if (checked.length === 0) {
        alert("Please select at least one log entry to archive.");
        return;
    }

    if (confirm(`Are you sure you want to archive the selected ${checked.length} visitor log(s)?`)) {
        document.getElementById("archiveActionInput").value = "archive_selected";
        document.getElementById("archiveForm").submit();
    }
}

/* --- Confirm & Archive All --- */
function confirmArchiveAll() {
    if (confirm("Are you sure you want to archive ALL active visitor vehicle logs?\nThis will move all active visitor logs into the permanent archive.")) {
        document.getElementById("archiveActionInput").value = "archive_all";
        document.getElementById("archiveForm").submit();
    }
}

document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") closeArchiveModal();
});
</script>

<!-- Shared Per-Duration Visitor Reminder & Overstay Alarm Engine -->
<script>window.SECSYS_ALARM_ROLE = "Security Guard";</script>
<script src="visitor_duration_alarm.js"></script>

<!-- Live auto-refresh (no full page reload) -->
<script>window.AUTO_REFRESH_SECONDS = 5;</script>
<script src="auto_refresh.js"></script>

</body>
</html>
<?php $conn->close(); ?>
