<?php
require_once __DIR__ . '/session_helper.php';
require_once __DIR__ . '/system_config.php';
secsysStartRoleSession('Administrator');

/* =========================================================
   DOMPDF
========================================================= */
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;


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
   HELPER FUNCTIONS
========================================================= */
function e($value)
{
    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

function normalizePlate($plate)
{
    $plate = strtoupper(trim($plate));

    return preg_replace(
        '/[^A-Z0-9]/',
        '',
        $plate
    );
}


/* =========================================================
   MESSAGE
========================================================= */
$message = "";
$message_type = "";


/* =========================================================
   CURRENT DATE
========================================================= */
$today = date("Y-m-d");

$displayDate = date(
    "l, F d, Y"
);


/* =========================================================
   AUTOMATIC FALLBACK ARCHIVE
   ---------------------------------------------------------
   Any visitor record older than today will be archived.
   This acts as a fallback if the midnight Task Scheduler
   did not run.
========================================================= */

$conn->begin_transaction();

try {

    /*
     * Move old visitor records into archive.
     */
    $archiveOld = $conn->prepare("
        INSERT INTO visitor_vehicles_archive
        (
            original_id,
            visitor_name,
            address_to_visit,
            vehicle_type,
            plate_number,
            purpose,
            id_type,
            status,
            time_in,
            time_out,
            original_date_created,
            archive_date,
            archived_at,
            archive_reason
        )
        SELECT
            id,
            visitor_name,
            address_to_visit,
            vehicle_type,
            plate_number,
            purpose,
            id_type,
            status,
            time_in,
            time_out,
            date_created,
            CURDATE(),
            NOW(),
            'Daily Archive'
        FROM visitor_vehicles
        WHERE DATE(date_created) < CURDATE()
        AND NOT EXISTS (
            SELECT 1
            FROM visitor_vehicles_archive a
            WHERE a.original_id = visitor_vehicles.id
        )
    ");

    if (!$archiveOld->execute()) {
        throw new Exception(
            "Failed to archive old visitor records."
        );
    }

    $archiveOld->close();


    /*
     * Delete old records from active visitor table.
     */
    $deleteOld = $conn->prepare("
        DELETE FROM visitor_vehicles
        WHERE DATE(date_created) < CURDATE()
    ");

    if (!$deleteOld->execute()) {
        throw new Exception(
            "Failed to remove archived visitor records."
        );
    }

    $deleteOld->close();

    $conn->commit();

} catch (Exception $e) {

    $conn->rollback();

    /*
     * Do not stop the page from loading.
     */
}


/* =========================================================
   SEARCH & TIME RANGE
========================================================= */
$search = trim(
    $_GET["search"] ?? ""
);
$time_from = (isset($_GET["time_from"]) && preg_match('/^\d{2}:\d{2}$/', trim($_GET["time_from"]))) ? trim($_GET["time_from"]) : "";
$time_to   = (isset($_GET["time_to"]) && preg_match('/^\d{2}:\d{2}$/', trim($_GET["time_to"]))) ? trim($_GET["time_to"]) : "";


/* =========================================================
   ARCHIVE BASIS & DATE / MONTH
========================================================= */
$show_archive = isset($_GET["show_archive"]) && $_GET["show_archive"] === "1";

$archive_basis = strtolower(trim($_GET["archive_basis"] ?? "daily"));
if (!in_array($archive_basis, ["daily", "monthly"], true)) {
    $archive_basis = "daily";
}

$archive_date =
    $_GET["archive_date"] ?? $today;

$date_object = DateTime::createFromFormat(
    "Y-m-d",
    $archive_date
);

if (
    !$date_object ||
    $date_object->format("Y-m-d") !== $archive_date
) {
    $archive_date = $today;
}

$current_year = (int)date("Y");
$archive_month = intval($_GET["archive_month"] ?? (int)date("n"));
if ($archive_month < 1 || $archive_month > 12) {
    $archive_month = (int)date("n");
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

if ($archive_basis === "monthly") {
    $archive_display_label = $month_names[$archive_month] . " " . $current_year;
} else {
    $archive_display_label = date("F d, Y", strtotime($archive_date));
}


/* =========================================================
   ARCHIVE PAGE
========================================================= */
$archive_page =
    intval(
        $_GET["archive_page"] ?? 1
    );

if ($archive_page < 1) {
    $archive_page = 1;
}

$archive_records_per_page = 5;


/* =========================================================
   GET CURRENT VISITOR RECORDS
========================================================= */

$visitors = [];

if ($search !== "") {

    $stmt = $conn->prepare("
        SELECT
            id,
            visitor_name,
            address_to_visit,
            vehicle_type,
            plate_number,
            purpose,
            id_type,
            status,
            time_in,
            time_out,
            date_created
        FROM visitor_vehicles
        WHERE
            visitor_name LIKE ?
            OR plate_number LIKE ?
            OR address_to_visit LIKE ?
            OR purpose LIKE ?
            OR id_type LIKE ?
            OR vehicle_type LIKE ?
        ORDER BY id DESC
    ");

    $search_param =
        "%" . $search . "%";

    $stmt->bind_param(
        "ssssss",
        $search_param,
        $search_param,
        $search_param,
        $search_param,
        $search_param,
        $search_param
    );

} else {

    $stmt = $conn->prepare("
        SELECT
            id,
            visitor_name,
            address_to_visit,
            vehicle_type,
            plate_number,
            purpose,
            id_type,
            status,
            time_in,
            time_out,
            date_created
        FROM visitor_vehicles
        ORDER BY id DESC
    ");
}

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    if ($time_from !== "" || $time_to !== "") {
        $tIn  = !empty($row["time_in"]) ? date("H:i", strtotime($row["time_in"])) : null;
        $tOut = !empty($row["time_out"]) ? date("H:i", strtotime($row["time_out"])) : null;
        $match = false;
        foreach ([$tIn, $tOut] as $t) {
            if ($t === null) continue;
            if ($time_from !== "" && $time_to !== "") {
                if ($time_from <= $time_to ? ($t >= $time_from && $t <= $time_to) : ($t >= $time_from || $t <= $time_to)) {
                    $match = true;
                }
            } elseif ($time_from !== "" && $t >= $time_from) {
                $match = true;
            } elseif ($time_to !== "" && $t <= $time_to) {
                $match = true;
            }
        }
        if (!$match) continue;
    }

    $visitors[] = $row;
}

$stmt->close();


/* =========================================================
   CURRENT PAGINATION
========================================================= */
$records_per_page = 6;

$current_page =
    intval(
        $_GET["page"] ?? 1
    );

if ($current_page < 1) {
    $current_page = 1;
}

$total_records =
    count($visitors);

$total_pages =
    max(
        1,
        ceil(
            $total_records /
            $records_per_page
        )
    );

if ($current_page > $total_pages) {
    $current_page = $total_pages;
}

$offset =
    (
        $current_page - 1
    ) *
    $records_per_page;

$visitors_page =
    array_slice(
        $visitors,
        $offset,
        $records_per_page
    );


/* =========================================================
   COUNT ARCHIVE (BY DATE ARCHIVED)
========================================================= */

if ($archive_basis === "monthly") {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM visitor_vehicles_archive
        WHERE MONTH(archived_at) = ?
          AND YEAR(archived_at) = ?
    ");

    $stmt->bind_param(
        "ii",
        $archive_month,
        $current_year
    );
} else {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM visitor_vehicles_archive
        WHERE DATE(archived_at) = ?
    ");

    $stmt->bind_param(
        "s",
        $archive_date
    );
}

$stmt->execute();

$count_result =
    $stmt->get_result();

$count_row =
    $count_result->fetch_assoc();

$total_archive_records =
    intval(
        $count_row["total"]
    );

$stmt->close();


/* =========================================================
   ARCHIVE PAGINATION
========================================================= */

$total_archive_pages =
    max(
        1,
        ceil(
            $total_archive_records /
            $archive_records_per_page
        )
    );

if (
    $archive_page >
    $total_archive_pages
) {
    $archive_page =
        $total_archive_pages;
}

$archive_offset =
    (
        $archive_page - 1
    ) *
    $archive_records_per_page;


/* =========================================================
   GET ARCHIVED VISITORS
========================================================= */

$archived_visitors = [];

if ($archive_basis === "monthly") {
    $stmt = $conn->prepare("
        SELECT
            archive_id,
            original_id,
            visitor_name,
            vehicle_type,
            plate_number,
            purpose,
            id_type,
            status,
            time_in,
            time_out,
            original_date_created,
            archive_date,
            archived_at,
            archive_reason
        FROM visitor_vehicles_archive
        WHERE MONTH(archived_at) = ?
          AND YEAR(archived_at) = ?
        ORDER BY archived_at DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->bind_param(
        "iiii",
        $archive_month,
        $current_year,
        $archive_records_per_page,
        $archive_offset
    );
} else {
    $stmt = $conn->prepare("
        SELECT
            archive_id,
            original_id,
            visitor_name,
            vehicle_type,
            plate_number,
            purpose,
            id_type,
            status,
            time_in,
            time_out,
            original_date_created,
            archive_date,
            archived_at,
            archive_reason
        FROM visitor_vehicles_archive
        WHERE DATE(archived_at) = ?
        ORDER BY archived_at DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->bind_param(
        "sii",
        $archive_date,
        $archive_records_per_page,
        $archive_offset
    );
}

$stmt->execute();

$archive_result =
    $stmt->get_result();

while (
    $row =
    $archive_result->fetch_assoc()
) {

    $archived_visitors[] =
        $row;
}

$stmt->close();


/* =========================================================
   PDF DOWNLOAD
========================================================= */

$download_type =
    $_GET["download"] ?? "";


/* =========================================================
   DOWNLOAD CURRENT VISITOR REPORT
========================================================= */

if (
    $download_type === "visitor"
) {

    $options =
        new Options();

    $options->set(
        "isRemoteEnabled",
        true
    );

    $options->set(
        "defaultFont",
        "Arial"
    );

    $dompdf =
        new Dompdf($options);

    $generated_date =
        date("F d, Y h:i A");


    $pdf_html = '
    <!DOCTYPE html>

    <html>

    <head>

        <meta charset="UTF-8">

        <style>

            @page {
                margin: 35px 35px;
            }

            body {
                font-family: Arial, Helvetica, sans-serif;
                color: #111827;
                font-size: 10px;
            }

            .header {
                text-align: center;
                border-bottom: 2px solid #222;
                padding-bottom: 12px;
                margin-bottom: 20px;
            }

            .organization {
                font-size: 18px;
                font-weight: bold;
                margin-bottom: 4px;
            }

            .location {
                font-size: 10px;
                color: #555;
                margin-bottom: 10px;
            }

            .report-title {
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 5px;
            }

            .generated {
                font-size: 9px;
                color: #666;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 15px;
            }

            th {
                background: #eeeeee;
                border: 1px solid #999;
                padding: 7px 4px;
                text-align: center;
                font-size: 8px;
            }

            td {
                border: 1px solid #cccccc;
                padding: 6px 4px;
                font-size: 8px;
                vertical-align: top;
            }

            .visitor-name {
                font-weight: bold;
            }

            .footer {
                margin-top: 25px;
                padding-top: 10px;
                border-top: 1px solid #cccccc;
                font-size: 8px;
                color: #666;
                text-align: center;
            }

        </style>

    </head>

    <body>

        <div class="header">

            <div class="organization">
                L. Surveillance
            </div>

            <div class="location">
                Lessandra Subdivision,
                Phase 2, Salinas 1,
                Bacoor, Cavite
            </div>

            <div class="report-title">
                Visitor Vehicle Report
            </div>

            <div class="generated">
                Generated:
                ' . e($generated_date) . '
            </div>

        </div>

        <table>

            <thead>

                <tr>

                    <th style="width:4%;">
                        #
                    </th>

                    <th style="width:16%;">
                        Address to Visit
                    </th>

                    <th style="width:16%;">
                        Purpose
                    </th>

                    <th style="width:12%;">
                        ID Type
                    </th>

                    <th style="width:12%;">
                        Vehicle Type
                    </th>

                    <th style="width:12%;">
                        Plate Number
                    </th>

                    <th style="width:10%;">
                        Date
                    </th>

                    <th style="width:9%;">
                        Time In
                    </th>

                    <th style="width:9%;">
                        Time Out
                    </th>

                </tr>

            </thead>

            <tbody>
    ';


    if (count($visitors) > 0) {

        $number = 1;

        foreach ($visitors as $visitor) {

            $logDate = "";
            $dtRef = !empty($visitor["time_in"]) ? $visitor["time_in"] : (!empty($visitor["time_out"]) ? $visitor["time_out"] : ($visitor["date_created"] ?? ""));
            if ($dtRef !== "") {
                $logDate = date("M d, Y", strtotime($dtRef));
            }

            $timeIn = "";

            if (
                !empty(
                    $visitor["time_in"]
                )
            ) {

                $timeIn =
                    date(
                        "h:i A",
                        strtotime(
                            $visitor["time_in"]
                        )
                    );
            }


            $timeOut = "";

            if (
                !empty(
                    $visitor["time_out"]
                )
            ) {

                $timeOut =
                    date(
                        "h:i A",
                        strtotime(
                            $visitor["time_out"]
                        )
                    );
            }


            $pdf_html .= '

                <tr>

                    <td style="text-align:center;">
                        ' . $number . '
                    </td>

                    <td>
                        ' . e(
                            $visitor["address_to_visit"]
                        ) . '
                    </td>

                    <td>
                        ' . e(
                            $visitor["purpose"]
                        ) . '
                    </td>

                    <td>
                        ' . e(
                            $visitor["id_type"]
                        ) . '
                    </td>

                    <td>
                        ' . e(
                            $visitor["vehicle_type"]
                        ) . '
                    </td>

                    <td>
                        ' . e(
                            $visitor["plate_number"]
                        ) . '
                    </td>

                    <td>
                        ' . e($logDate) . '
                    </td>

                    <td>
                        ' . e($timeIn) . '
                    </td>

                    <td>
                        ' . e($timeOut) . '
                    </td>

                </tr>

            ';

            $number++;
        }

    } else {

        $pdf_html .= '

            <tr>

                <td colspan="9"
                    style="text-align:center;">
                    No visitor records found.
                </td>

            </tr>

        ';
    }


    $pdf_html .= '

            </tbody>

        </table>


        <div class="footer">

            L. Surveillance Security System
            <br>
            Visitor Vehicle Monitoring Report

        </div>

    </body>

    </html>
    ';


    $dompdf->loadHtml(
        $pdf_html
    );

    $dompdf->setPaper(
        "A4",
        "landscape"
    );

    $dompdf->render();

    $dompdf->stream(
        "visitor_report_" .
        date("Y-m-d") .
        ".pdf",
        [
            "Attachment" => true
        ]
    );

    exit();
}


/* =========================================================
   DOWNLOAD VISITOR ARCHIVE REPORT
========================================================= */

if (
    $download_type === "visitor_archive"
) {

    $options =
        new Options();

    $options->set(
        "isRemoteEnabled",
        true
    );

    $options->set(
        "defaultFont",
        "Arial"
    );

    $dompdf =
        new Dompdf($options);

    $generated_date =
        date("F d, Y h:i A");


    /*
     * Get ALL archived records for
     * selected archive date or month.
     */
    $archive_download_rows = [];

    if ($archive_basis === "monthly") {
        $stmt = $conn->prepare("
            SELECT
                archive_id,
                original_id,
                visitor_name,
                address_to_visit,
                vehicle_type,
                plate_number,
                purpose,
                id_type,
                status,
                time_in,
                time_out,
                original_date_created,
                archive_date,
                archived_at,
                archive_reason
            FROM visitor_vehicles_archive
            WHERE MONTH(archived_at) = ?
              AND YEAR(archived_at) = ?
            ORDER BY archived_at DESC
        ");

        $stmt->bind_param(
            "ii",
            $archive_month,
            $current_year
        );
    } else {
        $stmt = $conn->prepare("
            SELECT
                archive_id,
                original_id,
                visitor_name,
                address_to_visit,
                vehicle_type,
                plate_number,
                purpose,
                id_type,
                status,
                time_in,
                time_out,
                original_date_created,
                archive_date,
                archived_at,
                archive_reason
            FROM visitor_vehicles_archive
            WHERE DATE(archived_at) = ?
            ORDER BY archived_at DESC
        ");

        $stmt->bind_param(
            "s",
            $archive_date
        );
    }

    $stmt->execute();

    $download_result =
        $stmt->get_result();

    while (
        $row =
        $download_result->fetch_assoc()
    ) {

        $archive_download_rows[] =
            $row;
    }

    $stmt->close();


    $pdf_html = '

    <!DOCTYPE html>

    <html>

    <head>

        <meta charset="UTF-8">

        <style>

            @page {
                margin: 35px 35px;
            }

            body {
                font-family: Arial, Helvetica, sans-serif;
                color: #111827;
                font-size: 10px;
            }

            .header {
                text-align: center;
                border-bottom: 2px solid #222;
                padding-bottom: 12px;
                margin-bottom: 20px;
            }

            .organization {
                font-size: 18px;
                font-weight: bold;
            }

            .location {
                font-size: 10px;
                color: #555;
                margin-top: 4px;
            }

            .report-title {
                font-size: 16px;
                font-weight: bold;
                margin-top: 10px;
            }

            .date {
                font-size: 10px;
                margin-top: 5px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            th {
                background: #eeeeee;
                border: 1px solid #999;
                padding: 7px 4px;
                font-size: 8px;
                text-align: center;
            }

            td {
                border: 1px solid #cccccc;
                padding: 6px 4px;
                font-size: 8px;
            }

            .footer {
                margin-top: 25px;
                border-top: 1px solid #ccc;
                padding-top: 10px;
                text-align: center;
                font-size: 8px;
                color: #666;
            }

        </style>

    </head>

    <body>

        <div class="header">

            <div class="organization">
                L. Surveillance
            </div>

            <div class="location">
                Lessandra Subdivision,
                Phase 2, Salinas 1,
                Bacoor, Cavite
            </div>

            <div class="report-title">
                Visitor Vehicle Archive Report
            </div>

            <div class="date">
                Archive Period:
                ' . e(
                    $archive_display_label
                ) . '
            </div>

            <div class="date">
                Generated:
                ' . e(
                    $generated_date
                ) . '
            </div>

        </div>

        <table>

            <thead>

                <tr>

                    <th>#</th>
                    <th>Address to Visit</th>
                    <th>Vehicle Type</th>
                    <th>Plate Number</th>
                    <th>Purpose</th>
                    <th>ID Type</th>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Time Out</th>

                </tr>

            </thead>

            <tbody>
    ';


    if (
        count(
            $archive_download_rows
        ) > 0
    ) {

        $number = 1;

        foreach (
            $archive_download_rows
            as $visitor
        ) {

            $logDate = "";
            $dtRef = !empty($visitor["time_in"]) ? $visitor["time_in"] : (!empty($visitor["time_out"]) ? $visitor["time_out"] : ($visitor["original_date_created"] ?? ($visitor["archive_date"] ?? "")));
            if ($dtRef !== "") {
                $logDate = date("M d, Y", strtotime($dtRef));
            }

            $timeIn = "";

            if (
                !empty(
                    $visitor["time_in"]
                )
            ) {

                $timeIn =
                    date(
                        "h:i A",
                        strtotime(
                            $visitor["time_in"]
                        )
                    );
            }


            $timeOut = "";

            if (
                !empty(
                    $visitor["time_out"]
                )
            ) {

                $timeOut =
                    date(
                        "h:i A",
                        strtotime(
                            $visitor["time_out"]
                        )
                    );
            }


            $pdf_html .= '

                <tr>

                    <td style="text-align:center;">
                        ' . $number . '
                    </td>

                    <td>
                        ' . e(
                            $visitor["address_to_visit"]
                        ) . '
                    </td>

                    <td>
                        ' . e(
                            $visitor["purpose"]
                        ) . '
                    </td>

                    <td>
                        ' . e(
                            $visitor["id_type"]
                        ) . '
                    </td>

                    <td>
                        ' . e(
                            $visitor["vehicle_type"]
                        ) . '
                    </td>

                    <td>
                        ' . e(
                            $visitor["plate_number"]
                        ) . '
                    </td>

                    <td>
                        ' . e(
                            $logDate
                        ) . '
                    </td>

                    <td>
                        ' . e(
                            $timeIn
                        ) . '
                    </td>

                    <td>
                        ' . e(
                            $timeOut
                        ) . '
                    </td>

                </tr>

            ';

            $number++;
        }

    } else {

        $pdf_html .= '

            <tr>

                <td
                    colspan="9"
                    style="text-align:center;"
                >
                    No archived visitor records
                    found for this date.
                </td>

            </tr>

        ';
    }


    $pdf_html .= '

            </tbody>

        </table>


        <div class="footer">

            L. Surveillance Security System
            <br>
            Visitor Vehicle Archive Report

        </div>

    </body>

    </html>

    ';


    $dompdf->loadHtml(
        $pdf_html
    );

    $dompdf->setPaper(
        "A4",
        "landscape"
    );

    $dompdf->render();

    $dompdf->stream(
        "visitor_archive_" .
        ($archive_basis === "monthly" ? strtolower($month_names[$archive_month]) . "_" . $current_year : $archive_date) .
        ".pdf",
        [
            "Attachment" => true
        ]
    );

    exit();
}


$conn->close();

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
    Visitor | <?php echo htmlspecialchars($systemName); ?>
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
        background .2s ease,
        color .2s ease;
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
        0 1px 3px
        rgba(0,0,0,.08);

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
    box-sizing: border-box;
}


/* =========================================================
   HEADER
========================================================= */

.header {
    margin-bottom: 16px;
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
   TOP ACTIONS
========================================================= */

.actions {
    display: flex;

    align-items: center;

    gap: 10px;

    flex-wrap: wrap;

    margin-bottom: 18px;
}

.search-form {
    display: flex;

    gap: 7px;
}

.search-input {
    width: 230px;

    height: 41px;

    border:
        1px solid #d5d5d5;

    border-radius: 22px;

    padding:
        0 16px;

    font-size: 13px;

    outline: none;

    background: white;
}

.search-input:focus {
    border-color: #2f80ed;
}

.btn {
    height: 41px;

    padding:
        0 22px;

    border-radius: 22px;

    border:
        1px solid #2f80ed;

    background: white;

    color: #176bd1;

    font-size: 13px;

    cursor: pointer;

    transition:
        background .2s ease,
        color .2s ease,
        transform .15s ease;
}

.btn:hover {
    transform: translateY(-1px);
}

.btn-primary {
    background: #2f80ed;

    color: white;
}

.btn-primary:hover {
    background: #176bd1;
}

.btn-danger {
    background: #ef4b3f;

    color: white;

    border-color: #ef4b3f;
}

.btn-warning {
    background: #fff;

    color: #176bd1;

    border-color: #2f80ed;
}


/* =========================================================
   REPORT BUTTONS
========================================================= */

.report-buttons {
    display: flex;

    gap: 10px;

    flex-wrap: wrap;
}

.report-buttons .btn {
    min-width: 145px;
}


/* =========================================================
   TABLE CARD
========================================================= */

.table-card {
    background: white;

    border:
        1px solid #e1e1e1;

    border-radius: 23px;

    box-shadow:
        0 3px 6px
        rgba(0,0,0,.14);

    overflow: hidden;
}

.table-header {
    padding:
        16px 20px 10px;
}

.table-title {
    font-size: 18px;

    font-weight: 500;
}

.table-subtitle {
    margin-top: 5px;

    font-size: 12px;

    color: #777;
}

.table-info {
    margin-top: 13px;

    font-size: 12px;

    color: #555;
}


/* =========================================================
   TABLE
========================================================= */

.table-wrapper {
    width: 100%;

    overflow-x: auto;
}

.vehicle-table {
    width: 100%;

    border-collapse: collapse;

    table-layout: fixed;
}

.vehicle-table th {
    height: 48px;

    font-size: 12px;

    font-weight: 500;

    border-bottom:
        1px solid #d8d8d8;

    text-align: center;
    vertical-align: middle;

    padding:
        8px 5px;
}

.vehicle-table td {
    height: 62px;

    font-size: 12px;

    border-bottom:
        1px solid #ddd;

    text-align: center;
    vertical-align: middle;

    padding:
        7px 5px;

    word-wrap: break-word;
}

.vehicle-table tbody tr:hover {
    background: #fafcff;
}


/* =========================================================
   COLUMN WIDTHS
========================================================= */

.vehicle-table th:nth-child(1),
.vehicle-table td:nth-child(1) {
    width: 15%;
}

.vehicle-table th:nth-child(2),
.vehicle-table td:nth-child(2) {
    width: 16%;
}

.vehicle-table th:nth-child(3),
.vehicle-table td:nth-child(3) {
    width: 14%;
}

.vehicle-table th:nth-child(4),
.vehicle-table td:nth-child(4) {
    width: 10%;
}

.vehicle-table th:nth-child(5),
.vehicle-table td:nth-child(5) {
    width: 10%;
}

.vehicle-table th:nth-child(6),
.vehicle-table td:nth-child(6) {
    width: 10%;
}

.vehicle-table th:nth-child(7),
.vehicle-table td:nth-child(7) {
    width: 12.5%;
}

.vehicle-table th:nth-child(8),
.vehicle-table td:nth-child(8) {
    width: 12.5%;
}


/* =========================================================
   PAGINATION
========================================================= */

.pagination {
    display: flex;

    justify-content: center;

    align-items: center;

    gap: 3px;

    padding:
        15px 10px;
}

.pagination a,
.pagination span {
    min-width: 27px;

    height: 27px;

    display: flex;

    align-items: center;

    justify-content: center;

    border:
        1px solid #bfcce0;

    text-decoration: none;

    color: #333;

    font-size: 12px;

    background: white;
}

.pagination .active {
    background: #9bb9e8;

    color: #111;

    font-weight: bold;
}


/* =========================================================
   ARCHIVE MODAL
========================================================= */

.modal {
    display: none;

    position: fixed;

    z-index: 2000;

    left: 0;
    top: 0;

    width: 100%;
    height: 100%;

    background:
        rgba(0,0,0,.45);

    padding: 30px;
}

.modal-content {
    width: 96%;

    max-width: 1250px;

    max-height: 90vh;

    overflow-y: auto;

    background: white;

    border-radius: 20px;

    margin: auto;

    padding: 22px;

    box-shadow:
        0 10px 35px
        rgba(0,0,0,.25);
}

.modal-header {
    display: flex;

    align-items: center;

    justify-content: space-between;

    margin-bottom: 15px;
}

.modal-title {
    font-size: 21px;

    font-weight: 500;
}

.close {
    font-size: 27px;

    cursor: pointer;

    color: #777;
}

.close:hover {
    color: #111;
}


/* =========================================================
   ARCHIVE CONTROLS
========================================================= */

.archive-controls {
    display: flex;

    align-items: center;

    gap: 10px;

    flex-wrap: wrap;

    margin-bottom: 15px;
}

.archive-date {
    height: 40px;

    border:
        1px solid #d5d5d5;

    border-radius: 20px;

    padding:
        0 14px;

    font-size: 13px;
}


/* =========================================================
   ARCHIVE TABLE
========================================================= */

.archive-table {
    width: 100%;

    border-collapse: collapse;

    min-width: 1050px;
}

.archive-table th {
    background: #f2f2f2;

    border:
        1px solid #ddd;

    padding: 9px;

    font-size: 11px;

    text-align: center;
    vertical-align: middle;
}

.archive-table td {
    border:
        1px solid #ddd;

    padding: 9px;

    font-size: 11px;

    text-align: center;
    vertical-align: middle;
}


/* =========================================================
   EMPTY
========================================================= */

.empty {
    text-align: center;

    color: #777;

    padding: 35px !important;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 800px) {

    .sidebar {
        width: 76px;

        padding:
            24px 8px;
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

        width:
            calc(100% - 76px);

        padding:
            25px 20px 30px;
    }
}

@media (max-width: 700px) {

    .main {
        padding:
            20px 14px 30px;
    }

    .search-form {
        width: 100%;
    }

    .search-input {
        width: 100%;
    }

    .actions {
        align-items: stretch;
    }

    .report-buttons {
        width: 100%;
    }

    .report-buttons .btn {
        flex: 1;
    }

    .table-card {
        border-radius: 15px;
    }

    .modal {
        padding: 10px;
    }

    .modal-content {
        width: 100%;
        padding: 15px;
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
        display: none !important;
    }

    .main {
        margin-left: 0 !important;

        width: 100% !important;

        padding: 20px !important;
    }

    .search-form,
    .report-buttons,
    .btn-create,
    .actions {
        display: none !important;
    }
}

@media print {

    body {
        background: white;
    }

    .sidebar,
    .actions,
    .modal,
    .pagination {
        display: none !important;
    }

    .main {
        margin-left: 0;

        width: 100%;

        padding: 20px;
    }

    .table-card {
        box-shadow: none;

        border: none;
    }

    .vehicle-table {
        font-size: 10px;
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

        <a href="superadmin_dashboard.php">

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


        <a
            href="visitor.php"
            class="active"
        >

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
            Visitor
        </h1>

        <p>
            <?= e($displayDate) ?>
        </p>

    </div>


    <!-- =================================================
         ACTIONS
    ================================================== -->

    <div class="actions">


        <!-- SEARCH -->

        <form
            method="GET"
            class="search-form"
        >

            <input
                type="text"
                name="search"
                class="search-input"
                placeholder="Search plate or vehicle type..."
                value="<?= e($search) ?>"
            >

            <div style="display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #d4d4d4; border-radius:20px; padding:0 12px; height:38px;">
                <span style="font-size:12px; color:#555; font-weight:600;">Hours:</span>
                <input type="time" name="time_from" value="<?= e($time_from) ?>" style="border:none; background:transparent; font-size:12px; outline:none;" title="From Time">
                <span style="font-size:12px; color:#888;">to</span>
                <input type="time" name="time_to" value="<?= e($time_to) ?>" style="border:none; background:transparent; font-size:12px; outline:none;" title="To Time">
            </div>

            <button
                type="submit"
                class="btn btn-primary"
            >
                Search
            </button>

        </form>


        <!-- REPORT BUTTONS -->

        <div class="report-buttons">

            <button
                type="button"
                class="btn btn-primary"
                onclick="printReport()"
            >
                🖨️ Print Report
            </button>


            <button
                type="button"
                class="btn"
                onclick="downloadReport()"
            >
                📥 Download PDF
            </button>


            <button
                type="button"
                class="btn"
                onclick="openArchive()"
            >
                📦 Archive
            </button>

        </div>

    </div>


    <!-- =================================================
         TABLE
    ================================================== -->

    <section class="table-card" id="liveVisitorTable" data-live>


        <div class="table-header">

            <div class="table-title">
                Visitor Vehicles
            </div>

            <div class="table-subtitle">
                Visitor vehicle records inside
                Lessandra Subdivision
            </div>

            <div class="table-info">

                <?= count($visitors) ?>
                visitor record(s)

            </div>

        </div>


        <div class="table-wrapper">

            <table class="vehicle-table">

                <thead>

                    <tr>

                        <th>
                            Vehicle Type
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


                <?php if (
                    count($visitors_page) > 0
                ): ?>


                    <?php foreach (
                        $visitors_page
                        as $visitor
                    ): ?>


                        <?php

                        $logDate = "—";
                        $dtRef = !empty($visitor["time_in"]) ? $visitor["time_in"] : (!empty($visitor["time_out"]) ? $visitor["time_out"] : ($visitor["date_created"] ?? ""));
                        if ($dtRef !== "") {
                            $logDate = date("M d, Y", strtotime($dtRef));
                        }

                        $timeIn = "";

                        if (
                            !empty(
                                $visitor["time_in"]
                            )
                        ) {

                            $timeIn =
                                date(
                                    "h:i A",
                                    strtotime(
                                        $visitor["time_in"]
                                    )
                                );
                        }


                        $timeOut = "";

                        if (
                            !empty(
                                $visitor["time_out"]
                            )
                        ) {

                            $timeOut =
                                date(
                                    "h:i A",
                                    strtotime(
                                        $visitor["time_out"]
                                    )
                                );
                        }

                        ?>


                        <tr>


                            <td>

                                <?= e(
                                    $visitor[
                                        "vehicle_type"
                                    ]
                                ) ?>

                            </td>


                            <td>

                                <?= e(
                                    $visitor[
                                        "plate_number"
                                    ]
                                ) ?>

                            </td>


                            <td>

                                <?= e(
                                    $logDate
                                ) ?>

                            </td>


                            <td>

                                <?= e(
                                    $timeIn
                                ) ?>

                            </td>


                            <td>

                                <?= $timeOut !== ""
                                    ? e($timeOut)
                                    : "—"
                                ?>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="5"
                            class="empty"
                        >

                            No visitor records found.

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>

            </table>

        </div>


        <!-- PAGINATION -->

        <div class="pagination">


            <?php if (
                $current_page > 1
            ): ?>

                <a
                    href="?page=<?= $current_page - 1 ?>&search=<?= urlencode($search) ?>"
                >
                    ‹
                </a>

            <?php endif; ?>


            <?php for (
                $p = 1;
                $p <= $total_pages;
                $p++
            ): ?>

                <a
                    href="?page=<?= $p ?>&search=<?= urlencode($search) ?>"
                    class="<?= $p === $current_page
                        ? "active"
                        : ""
                    ?>"
                >
                    <?= $p ?>
                </a>

            <?php endfor; ?>


            <?php if (
                $current_page < $total_pages
            ): ?>

                <a
                    href="?page=<?= $current_page + 1 ?>&search=<?= urlencode($search) ?>"
                >
                    ›
                </a>

            <?php endif; ?>


        </div>

    </section>


</main>


<!-- =====================================================
     ARCHIVE MODAL
====================================================== -->

<div
    id="archiveModal"
    class="modal"
    style="display: <?= $show_archive ? 'flex' : 'none' ?>;"
>

    <div class="modal-content">


        <div class="modal-header">

            <div>
                <div class="modal-title">
                    Visitor Archive
                </div>

                <div style="font-size:11px; color:#777; margin-top:4px;">
                    Archived visitor records for
                    <strong id="archiveDisplayLabel"><?= e($archive_display_label) ?></strong>
                </div>
            </div>

            <span
                class="close"
                onclick="closeArchive()"
            >
                ×
            </span>

        </div>


        <div
            class="archive-controls"
            style="
                display:flex;
                justify-content:space-between;
                align-items:center;
                gap:12px;
                flex-wrap:wrap;
                margin-bottom:15px;
            "
        >

            <!-- DAILY OR MONTHLY FILTER -->
            <div
                style="
                    display:flex;
                    align-items:center;
                    gap:10px;
                    flex-wrap:wrap;
                "
            >
                <input
                    type="hidden"
                    id="archiveBasis"
                    value="<?= e($archive_basis) ?>"
                >

                <div
                    style="
                        display:inline-flex;
                        background:#eef4fc;
                        border:1px solid #c8dcf7;
                        border-radius:22px;
                        padding:3px;
                        gap:4px;
                    "
                >
                    <button
                        type="button"
                        id="btnBasisDaily"
                        onclick="setArchiveBasis('daily')"
                        style="
                            border:none;
                            height:32px;
                            padding:0 16px;
                            border-radius:18px;
                            font-size:12px;
                            font-weight:600;
                            cursor:pointer;
                            background:<?= $archive_basis === 'daily' ? '#2f80ed' : 'transparent' ?>;
                            color:<?= $archive_basis === 'daily' ? '#ffffff' : '#1769d3' ?>;
                        "
                    >
                        📅 Daily
                    </button>

                    <button
                        type="button"
                        id="btnBasisMonthly"
                        onclick="setArchiveBasis('monthly')"
                        style="
                            border:none;
                            height:32px;
                            padding:0 16px;
                            border-radius:18px;
                            font-size:12px;
                            font-weight:600;
                            cursor:pointer;
                            background:<?= $archive_basis === 'monthly' ? '#2f80ed' : 'transparent' ?>;
                            color:<?= $archive_basis === 'monthly' ? '#ffffff' : '#1769d3' ?>;
                        "
                    >
                        🗓️ Monthly
                    </button>
                </div>

                <!-- DAILY CALENDAR PICKER -->
                <div
                    id="dailyPickerBox"
                    style="
                        display:<?= $archive_basis === 'daily' ? 'flex' : 'none' ?>;
                        align-items:center;
                        gap:8px;
                    "
                >
                    <span style="font-size:12px; color:#555;">
                        Select Date:
                    </span>

                    <input
                        type="date"
                        id="archiveDate"
                        class="archive-date"
                        value="<?= e($archive_date) ?>"
                        onchange="loadArchiveFilter()"
                    >
                </div>

                <!-- MONTHLY DROPDOWN (JANUARY - DECEMBER) -->
                <div
                    id="monthlyPickerBox"
                    style="
                        display:<?= $archive_basis === 'monthly' ? 'flex' : 'none' ?>;
                        align-items:center;
                        gap:8px;
                    "
                >
                    <span style="font-size:12px; color:#555;">
                        Select Month:
                    </span>

                    <select
                        id="archiveMonth"
                        class="archive-date"
                        onchange="loadArchiveFilter()"
                    >
                        <?php foreach ($month_names as $m_num => $m_name): ?>
                            <option
                                value="<?= (int)$m_num ?>"
                                <?= (int)$archive_month === (int)$m_num ? 'selected' : '' ?>
                            >
                                <?= e($m_name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div
                style="
                    display:flex;
                    justify-content:flex-end;
                    gap:8px;
                    flex-wrap:wrap;
                "
            >

                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="printArchive()"
                >
                    🖨️ Print Archive Report
                </button>

                <button
                    type="button"
                    class="btn"
                    onclick="downloadArchive()"
                >
                    📥 Download Archive PDF
                </button>

            </div>

        </div>


        <div class="table-wrapper">


            <table class="archive-table">

                <thead>

                    <tr>

                        <th>
                            Vehicle Type
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


                <?php if (
                    count(
                        $archived_visitors
                    ) > 0
                ): ?>


                    <?php foreach (
                        $archived_visitors
                        as $visitor
                    ): ?>


                        <tr>

                            <td>
                                <?= e(
                                    $visitor[
                                        "vehicle_type"
                                    ]
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $visitor[
                                        "plate_number"
                                    ]
                                ) ?>
                            </td>

                            <td>
                                <?php
                                $dtRef = !empty($visitor["time_in"]) ? $visitor["time_in"] : (!empty($visitor["time_out"]) ? $visitor["time_out"] : ($visitor["original_date_created"] ?? ($visitor["archive_date"] ?? "")));
                                echo $dtRef !== "" ? e(date("M d, Y", strtotime($dtRef))) : "—";
                                ?>
                            </td>

                            <td>

                                <?php

                                if (
                                    !empty(
                                        $visitor[
                                            "time_in"
                                        ]
                                    )
                                ) {

                                    echo e(
                                        date(
                                            "h:i A",
                                            strtotime(
                                                $visitor[
                                                    "time_in"
                                                ]
                                            )
                                        )
                                    );

                                } else {

                                    echo "—";
                                }

                                ?>

                            </td>

                            <td>

                                <?php

                                if (
                                    !empty(
                                        $visitor[
                                            "time_out"
                                        ]
                                    )
                                ) {

                                    echo e(
                                        date(
                                            "h:i A",
                                            strtotime(
                                                $visitor[
                                                    "time_out"
                                                ]
                                            )
                                        )
                                    );

                                } else {

                                    echo "—";
                                }

                                ?>

                            </td>

                        </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="5"
                            class="empty"
                        >

                            No archived visitor records
                            found for
                            <?= e($archive_display_label) ?>.

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>

            </table>

        </div>


        <!-- ARCHIVE PAGINATION -->

        <?php if ($total_archive_pages > 1): ?>

        <div class="pagination">


            <?php if (
                $archive_page > 1
            ): ?>

                <a
                    href="visitor.php?show_archive=1&archive_basis=<?= urlencode($archive_basis) ?>&archive_date=<?= urlencode($archive_date) ?>&archive_month=<?= (int)$archive_month ?>&archive_page=<?= $archive_page - 1 ?>"
                >
                    ‹
                </a>

            <?php endif; ?>


            <?php for (
                $p = 1;
                $p <= $total_archive_pages;
                $p++
            ): ?>

                <a
                    href="visitor.php?show_archive=1&archive_basis=<?= urlencode($archive_basis) ?>&archive_date=<?= urlencode($archive_date) ?>&archive_month=<?= (int)$archive_month ?>&archive_page=<?= $p ?>"
                    class="<?= $p === $archive_page
                        ? "active"
                        : ""
                    ?>"
                >
                    <?= $p ?>
                </a>

            <?php endfor; ?>


            <?php if (
                $archive_page <
                $total_archive_pages
            ): ?>

                <a
                    href="visitor.php?show_archive=1&archive_basis=<?= urlencode($archive_basis) ?>&archive_date=<?= urlencode($archive_date) ?>&archive_month=<?= (int)$archive_month ?>&archive_page=<?= $archive_page + 1 ?>"
                >
                    ›
                </a>

            <?php endif; ?>


        </div>

        <?php endif; ?>


    </div>

</div>


<script>

/* =========================================================
   PRINT CURRENT REPORT
========================================================= */

function printReport()
{
    window.print();
}


/* =========================================================
   DOWNLOAD CURRENT REPORT
========================================================= */

function downloadReport()
{
    const search =
        <?= json_encode($search) ?>;

    let url =
        "visitor.php?download=visitor";

    if (search !== "") {

        url +=
            "&search=" +
            encodeURIComponent(
                search
            );
    }

    window.location.href =
        url;
}


/* =========================================================
   OPEN ARCHIVE
========================================================= */

function openArchive()
{
    document.getElementById(
        "archiveModal"
    ).style.display = "flex";
}


/* =========================================================
   CLOSE ARCHIVE
========================================================= */

function closeArchive()
{
    document.getElementById(
        "archiveModal"
    ).style.display = "none";

    if (window.history && window.history.replaceState && window.location.search.includes("show_archive=1")) {
        const url = new URL(window.location.href);
        url.searchParams.delete("show_archive");
        url.searchParams.delete("archive_page");
        window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ""));
    }
}


/* =========================================================
   CLOSE WHEN CLICKING OUTSIDE / ESCAPE
========================================================= */

window.onclick =
    function(event)
    {
        const modal =
            document.getElementById(
                "archiveModal"
            );

        if (event.target === modal) {
            closeArchive();
        }
    };

document.addEventListener("keydown", function(event) {
    if (event.key === "Escape") {
        closeArchive();
    }
});


/* =========================================================
   SET ARCHIVE BASIS (DAILY OR MONTHLY)
========================================================= */

function setArchiveBasis(basis)
{
    const basisInput = document.getElementById("archiveBasis");
    const dailyBox = document.getElementById("dailyPickerBox");
    const monthlyBox = document.getElementById("monthlyPickerBox");
    const btnDaily = document.getElementById("btnBasisDaily");
    const btnMonthly = document.getElementById("btnBasisMonthly");

    if (basisInput) {
        basisInput.value = basis;
    }

    if (basis === "monthly") {
        if (dailyBox) dailyBox.style.display = "none";
        if (monthlyBox) monthlyBox.style.display = "flex";
        if (btnDaily) {
            btnDaily.style.background = "transparent";
            btnDaily.style.color = "#1769d3";
        }
        if (btnMonthly) {
            btnMonthly.style.background = "#2f80ed";
            btnMonthly.style.color = "#ffffff";
        }
    } else {
        if (dailyBox) dailyBox.style.display = "flex";
        if (monthlyBox) monthlyBox.style.display = "none";
        if (btnDaily) {
            btnDaily.style.background = "#2f80ed";
            btnDaily.style.color = "#ffffff";
        }
        if (btnMonthly) {
            btnMonthly.style.background = "transparent";
            btnMonthly.style.color = "#1769d3";
        }
    }

    loadArchiveFilter(1);
}


/* =========================================================
   LOAD ARCHIVE FILTER (AJAX IN-PLACE)
========================================================= */

function loadArchiveFilter(archivePage)
{
    const pageNum = archivePage || 1;

    const basis =
        document.getElementById("archiveBasis")
        ? document.getElementById("archiveBasis").value
        : "daily";

    const date =
        document.getElementById("archiveDate")
        ? document.getElementById("archiveDate").value
        : "";

    const month =
        document.getElementById("archiveMonth")
        ? document.getElementById("archiveMonth").value
        : "";

    const url =
        "visitor.php?show_archive=1" +
        "&archive_basis=" + encodeURIComponent(basis) +
        "&archive_date=" + encodeURIComponent(date) +
        "&archive_month=" + encodeURIComponent(month) +
        "&archive_page=" + encodeURIComponent(pageNum);

    fetch(url, { credentials: "same-origin" })
        .then(function(response) {
            return response.text();
        })
        .then(function(html) {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, "text/html");
            const newModal = doc.getElementById("archiveModal");
            const currentModal = document.getElementById("archiveModal");

            if (newModal && currentModal) {
                currentModal.innerHTML = newModal.innerHTML;
                currentModal.style.display = "flex";
                bindArchivePaginationLinks();
            }

            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, document.title, url);
            }
        })
        .catch(function() {
            window.location.href = url;
        });
}

function bindArchivePaginationLinks()
{
    const links = document.querySelectorAll("#archiveModal .pagination a");
    links.forEach(function(link) {
        link.addEventListener("click", function(e) {
            const href = link.getAttribute("href");
            if (!href) return;
            e.preventDefault();
            const params = new URLSearchParams(href.split("?")[1] || "");
            const ap = params.get("archive_page") || 1;
            loadArchiveFilter(ap);
        });
    });
}

document.addEventListener("DOMContentLoaded", bindArchivePaginationLinks);


/* =========================================================
   DOWNLOAD ARCHIVE
========================================================= */

function downloadArchive()
{
    const basis =
        document.getElementById("archiveBasis")
        ? document.getElementById("archiveBasis").value
        : "daily";

    const selectedDate =
        document.getElementById("archiveDate")
        ? document.getElementById("archiveDate").value
        : <?= json_encode($archive_date) ?>;

    const selectedMonth =
        document.getElementById("archiveMonth")
        ? document.getElementById("archiveMonth").value
        : "";

    const url =
        "visitor.php?download=visitor_archive" +
        "&archive_basis=" + encodeURIComponent(basis) +
        "&archive_date=" + encodeURIComponent(selectedDate) +
        "&archive_month=" + encodeURIComponent(selectedMonth);

    window.location.href =
        url;
}


/* =========================================================
   PRINT ARCHIVE
========================================================= */

function printArchive()
{
    const modal =
        document.getElementById(
            "archiveModal"
        );

    const table =
        modal.querySelector(
            ".archive-table"
        );

    const labelEl =
        document.getElementById("archiveDisplayLabel");

    const selectedLabel =
        labelEl
        ? labelEl.innerText.trim()
        : <?= json_encode($archive_display_label) ?>;

    const printWindow =
        window.open(
            "",
            "_blank",
            "width=1200,height=800"
        );

    printWindow.document.write(`
        <!DOCTYPE html>

        <html>

        <head>

            <title>
                Visitor Archive Report
            </title>

            <style>

                body {
                    font-family: Arial, sans-serif;
                    padding: 30px;
                    color: #111;
                }

                h1 {
                    text-align: center;
                    margin-bottom: 5px;
                }

                h2 {
                    text-align: center;
                    font-size: 15px;
                    font-weight: normal;
                    margin-bottom: 5px;
                }

                p {
                    text-align: center;
                    color: #555;
                    font-size: 12px;
                    margin-bottom: 20px;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                }

                th,
                td {
                    border: 1px solid #999;
                    padding: 7px;
                    font-size: 10px;
                    text-align: center;
                }

                th {
                    background: #eee;
                }

            </style>

        </head>

        <body>

            <h1>
                L. Surveillance
            </h1>

            <h2>
                Visitor Vehicle Archive Report
            </h2>

            <p>
                Archive Period:
                ${selectedLabel}
            </p>

            ${table.outerHTML}

        </body>

        </html>
    `);

    printWindow.document.close();

    printWindow.focus();

    printWindow.print();

    printWindow.close();
}

</script>

<!-- Live auto-refresh (no full page reload) -->
<script>window.AUTO_REFRESH_SECONDS = 5;</script>
<script src="auto_refresh.js"></script>

</body>

</html>