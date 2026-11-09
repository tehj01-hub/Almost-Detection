<?php
require_once __DIR__ . '/app/session_helper.php';
require_once __DIR__ . '/app/system_config.php';
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
$conn = secsysConnectDb();
secsysEnsureSchema($conn);
$systemName = secsysGetSystemName($conn);
$systemIcon = secsysGetSystemIcon($conn);

/* Extra owner fields the Administrator added (Add Field in the owner form) */
require_once __DIR__ . '/app/owner_fields.php';
$ownerFields = secsysOwnerFields($conn);

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && in_array(($_POST["action"] ?? ""), ["owner_field_add", "owner_field_rename", "owner_field_remove"], true)
) {
    header("Content-Type: application/json; charset=utf-8");
    $fieldAction = $_POST["action"];
    if ($fieldAction === "owner_field_add") {
        $res = secsysOwnerFieldAdd($conn, (string)($_POST["label"] ?? ""));
        $logText = 'Added the owner field "' . ($res['field']['label'] ?? '') . '" on the Registered page.';
    } elseif ($fieldAction === "owner_field_rename") {
        $res = secsysOwnerFieldRename($conn, (string)($_POST["key"] ?? ""), (string)($_POST["label"] ?? ""));
        $logText = 'Renamed the owner field "' . ($res['old'] ?? '') . '" on the Registered page.';
    } else {
        $res = secsysOwnerFieldRemove($conn, (string)($_POST["key"] ?? ""));
        $logText = 'Removed the owner field "' . ($res['label'] ?? '') . '" from the Registered page (saved values are kept).';
    }
    if ($res['ok']) {
        secsysLogActivity($conn, (string)$_SESSION['username'], 'Registered Fields Update', $logText);
    }
    echo json_encode(['success' => $res['ok'], 'message' => $res['message'], 'field' => $res['field'] ?? null]);
    exit();
}

/* =========================================================
   HELPER
========================================================= */
function normalizePlate($plate)
{
    $plate = strtoupper(trim($plate));

    return preg_replace(
        '/[^A-Z0-9]/',
        '',
        $plate
    );
}

function e($value)
{
    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

/* =========================================================
   MESSAGE
========================================================= */
$message = trim($_GET["msg"] ?? "");
$message_type = trim($_GET["msg_type"] ?? "");
$highlight_plate = trim($_GET["highlight"] ?? "");

/* =========================================================
   SEARCH
========================================================= */
$search = trim($_GET["search"] ?? "");

/* =========================================================
   ADD VEHICLE
========================================================= */
if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["action"] ?? "") === "add"
) {

    $owner_name   = ""; // owner name field removed from the UI (column kept NOT NULL)
    $extra_info   = secsysOwnerExtraEncode(secsysOwnerExtraFromPost($ownerFields, $_POST["extra"] ?? []));
    $brand        = trim($_POST["brand"] ?? "");
    $plate_number = normalizePlate($_POST["plate_number"] ?? "");
    $model        = trim($_POST["model"] ?? "");
    $address      = trim($_POST["address"] ?? "");
    $vehicle_type = trim($_POST["vehicle_type"] ?? "");
    if (!in_array($vehicle_type, SECSYS_VEHICLE_TYPES, true)) {
        $vehicle_type = "";
    }

    if (
        $brand === ""
        || $plate_number === ""
        || $model === ""
        || $address === ""
        || $vehicle_type === ""
    ) {

        $message = "Please complete all required fields.";
        $message_type = "error";

    } else {

        $check = $conn->prepare("
            SELECT id
            FROM registered_vehicles
            WHERE plate_number = ?
            LIMIT 1
        ");

        $check->bind_param(
            "s",
            $plate_number
        );

        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {

            $message =
                "This plate number is already registered.";

            $message_type = "error";

        } else {

            $status = "Active";

            $stmt = $conn->prepare("
                INSERT INTO registered_vehicles
                (
                    owner_name,
                    brand,
                    vehicle_type,
                    model,
                    address,
                    plate_number,
                    extra_info,
                    status,
                    date_created
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                )
            ");

            $stmt->bind_param(
                "ssssssss",
                $owner_name,
                $brand,
                $vehicle_type,
                $model,
                $address,
                $plate_number,
                $extra_info,
                $status
            );

            if ($stmt->execute()) {

                $newId = (int)$conn->insert_id;
                $message = "Registered vehicle added successfully.";
                $message_type = "success";

                // Immediately upgrade any vehicle logs today from Visitor to Registered
                $normPlateNoSpace = str_replace(' ', '', $plate_number);
                $updVl = $conn->prepare("
                    UPDATE vehicle_logs
                    SET vehicle_category = 'Registered',
                        registered_vehicle_id = ?,
                        visitor_vehicle_id = NULL
                    WHERE (REPLACE(UPPER(plate_number), ' ', '') = ? OR REPLACE(UPPER(plate_number), ' ', '') = ?)
                      AND date_created >= CURDATE()
                ");
                if ($updVl) {
                    $updVl->bind_param("iss", $newId, $plate_number, $normPlateNoSpace);
                    $updVl->execute();
                    $updVl->close();
                }

                $updVld = $conn->prepare("
                    UPDATE vehicle_log_dates
                    SET vehicle_category = 'Registered'
                    WHERE (REPLACE(UPPER(plate_number), ' ', '') = ? OR REPLACE(UPPER(plate_number), ' ', '') = ?)
                      AND date_in >= CURDATE()
                ");
                if ($updVld) {
                    $updVld->bind_param("ss", $plate_number, $normPlateNoSpace);
                    $updVld->execute();
                    $updVld->close();
                }

                // Notify python detection server to invalidate registered plates cache
                $ctx = stream_context_create(['http' => ['timeout' => 0.4]]);
                @file_get_contents("http://localhost:5000/api/refresh_registered", false, $ctx) ?: @file_get_contents("http://127.0.0.1:5000/api/refresh_registered", false, $ctx);

                $isAjax = (
                    isset($_POST['ajax']) ||
                    isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                );
                if ($isAjax) {
                    header("Content-Type: application/json; charset=utf-8");
                    echo json_encode([
                        'success' => true,
                        'message' => $message,
                        'vehicle' => [
                            'id' => $newId,
                            'brand' => $brand,
                            'model' => $model,
                            'plate_number' => $plate_number,
                            'vehicle_type' => $vehicle_type,
                            'address' => $address
                        ]
                    ]);
                    exit();
                }

                header("Location: registered.php?msg=" . urlencode($message) . "&msg_type=" . urlencode($message_type) . "&highlight=" . urlencode($plate_number));
                exit();

            } else {

                $message =
                    "Failed to add registered vehicle.";

                $message_type = "error";
            }

            $stmt->close();
        }

        $check->close();
    }

    if (isset($_POST['ajax']) || isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['success' => false, 'message' => $message]);
        exit();
    }
}

/* =========================================================
   UPDATE VEHICLE
========================================================= */
if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["action"] ?? "") === "update"
) {

    $id = intval($_POST["id"] ?? 0);

    $brand        = trim($_POST["brand"] ?? "");
    $plate_number = normalizePlate($_POST["plate_number"] ?? "");
    $model        = trim($_POST["model"] ?? "");
    $address      = trim($_POST["address"] ?? "");
    $vehicle_type = trim($_POST["vehicle_type"] ?? "");
    if (!in_array($vehicle_type, SECSYS_VEHICLE_TYPES, true)) {
        $vehicle_type = "";
    }

    if (
        $id <= 0
        || $brand === ""
        || $plate_number === ""
        || $model === ""
        || $address === ""
        || $vehicle_type === ""
    ) {

        $message =
            "Please complete all required fields.";

        $message_type = "error";

    } else {

        $check = $conn->prepare("
            SELECT id
            FROM registered_vehicles
            WHERE plate_number = ?
            AND id != ?
            LIMIT 1
        ");

        $check->bind_param(
            "si",
            $plate_number,
            $id
        );

        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {

            $message =
                "Another registered vehicle already uses this plate number.";

            $message_type = "error";

        } else {

            // Keep values of fields that were removed; update the active ones from the form
            $existingExtra = [];
            $exStmt = $conn->prepare("SELECT extra_info FROM registered_vehicles WHERE id = ?");
            if ($exStmt) {
                $exStmt->bind_param("i", $id);
                $exStmt->execute();
                $existingExtra = secsysOwnerExtraDecode($exStmt->get_result()->fetch_assoc()['extra_info'] ?? null);
                $exStmt->close();
            }
            $extra_info = secsysOwnerExtraEncode(secsysOwnerExtraFromPost($ownerFields, $_POST["extra"] ?? [], $existingExtra));

            $stmt = $conn->prepare("
                UPDATE registered_vehicles
                SET
                    brand = ?,
                    plate_number = ?,
                    model = ?,
                    address = ?,
                    vehicle_type = ?,
                    extra_info = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "ssssssi",
                $brand,
                $plate_number,
                $model,
                $address,
                $vehicle_type,
                $extra_info,
                $id
            );

            if ($stmt->execute()) {

                $message = "Registered vehicle updated successfully.";
                $message_type = "success";

                // Immediately sync vehicle logs today with updated metadata
                $normPlateNoSpace = str_replace(' ', '', $plate_number);
                $updVl = $conn->prepare("
                    UPDATE vehicle_logs
                    SET vehicle_category = 'Registered',
                        registered_vehicle_id = ?,
                        visitor_vehicle_id = NULL
                    WHERE (REPLACE(UPPER(plate_number), ' ', '') = ? OR REPLACE(UPPER(plate_number), ' ', '') = ?)
                      AND date_created >= CURDATE()
                ");
                if ($updVl) {
                    $updVl->bind_param("iss", $id, $plate_number, $normPlateNoSpace);
                    $updVl->execute();
                    $updVl->close();
                }

                // Notify python detection server
                $ctx = stream_context_create(['http' => ['timeout' => 0.4]]);
                @file_get_contents("http://localhost:5000/api/refresh_registered", false, $ctx) ?: @file_get_contents("http://127.0.0.1:5000/api/refresh_registered", false, $ctx);

                $isAjax = (
                    isset($_POST['ajax']) ||
                    isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                );
                if ($isAjax) {
                    header("Content-Type: application/json; charset=utf-8");
                    echo json_encode([
                        'success' => true,
                        'message' => $message,
                        'vehicle' => [
                            'id' => $id,
                            'brand' => $brand,
                            'model' => $model,
                            'plate_number' => $plate_number,
                            'vehicle_type' => $vehicle_type,
                            'address' => $address
                        ]
                    ]);
                    exit();
                }

                header("Location: registered.php?msg=" . urlencode($message) . "&msg_type=" . urlencode($message_type) . "&highlight=" . urlencode($plate_number));
                exit();

            } else {

                $message =
                    "Failed to update registered vehicle.";

                $message_type = "error";
            }

            $stmt->close();
        }

        $check->close();
    }

    if (isset($_POST['ajax']) || isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['success' => false, 'message' => $message]);
        exit();
    }
}

/* =========================================================
   ARCHIVE SINGLE VEHICLE
========================================================= */
if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["action"] ?? "") === "archive_single"
) {

    $vehicle_id =
        intval($_POST["vehicle_id"] ?? 0);

    if ($vehicle_id <= 0) {

        $message =
            "Invalid vehicle selected.";

        $message_type = "error";

    } else {

        $stmt = $conn->prepare("
            INSERT INTO registered_vehicles_archive
            (
                original_id,
                owner_name,
                brand,
                plate_number,
                model,
                address,
                vehicle_type,
                extra_info,
                status,
                original_date_created,
                archive_date,
                archived_at,
                archive_reason
            )
            SELECT
                id,
                owner_name,
                brand,
                plate_number,
                model,
                address,
                vehicle_type,
                extra_info,
                'Inactive',
                date_created,
                CURDATE(),
                NOW(),
                'Manual Archive'
            FROM registered_vehicles
            WHERE id = ?
        ");

        $stmt->bind_param(
            "i",
            $vehicle_id
        );

        if (
            $stmt->execute()
            && $stmt->affected_rows > 0
        ) {

            $stmt->close();

            $delete = $conn->prepare("
                DELETE FROM registered_vehicles
                WHERE id = ?
            ");

            $delete->bind_param(
                "i",
                $vehicle_id
            );

            if ($delete->execute()) {

                $message =
                    "Vehicle archived successfully.";

                $message_type = "success";

            } else {

                $message =
                    "Vehicle was archived but could not be removed from active records.";

                $message_type = "error";
            }

            $delete->close();

        } else {

            $message =
                "Vehicle could not be archived.";

            $message_type = "error";

            $stmt->close();
        }
    }
}

/* =========================================================
   ARCHIVE SELECTED OWNERS
========================================================= */
if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_POST["action"] ?? "") === "archive_selected"
) {

    $owner_groups =
        $_POST["owner_groups"] ?? [];

    if (
        !is_array($owner_groups)
        || count($owner_groups) === 0
    ) {

        $message =
            "Please select at least one owner to archive.";

        $message_type = "error";

    } else {

        $success_count = 0;

        $conn->begin_transaction();

        try {

            foreach ($owner_groups as $group) {

                $decoded =
                    json_decode(
                        $group,
                        true
                    );

                if (
                    !is_array($decoded)
                    || empty($decoded["address"])
                ) {
                    continue;
                }

                $address =
                    $decoded["address"];

                $archive = $conn->prepare("
                    INSERT INTO registered_vehicles_archive
                    (
                        original_id,
                        owner_name,
                        brand,
                        plate_number,
                        model,
                        address,
                        vehicle_type,
                        extra_info,
                        status,
                        original_date_created,
                        archive_date,
                        archived_at,
                        archive_reason
                    )
                    SELECT
                        id,
                        owner_name,
                        brand,
                        plate_number,
                        model,
                        address,
                        vehicle_type,
                        extra_info,
                        'Inactive',
                        date_created,
                        CURDATE(),
                        NOW(),
                        'Manual Archive'
                    FROM registered_vehicles
                    WHERE address = ?
                ");

                $archive->bind_param(
                    "s",
                    $address
                );

                $archive->execute();

                $success_count +=
                    $archive->affected_rows;

                $archive->close();

                $delete = $conn->prepare("
                    DELETE FROM registered_vehicles
                    WHERE address = ?
                ");

                $delete->bind_param(
                    "s",
                    $address
                );

                $delete->execute();

                $delete->close();
            }

            $conn->commit();

            if ($success_count > 0) {

                $message =
                    $success_count .
                    " vehicle(s) archived successfully.";

                $message_type = "success";

            } else {

                $message =
                    "No vehicles were archived.";

                $message_type = "error";
            }

        } catch (Exception $e) {

            $conn->rollback();

            $message =
                "Archive operation failed.";

            $message_type = "error";
        }
    }
}

/* =========================================================
   ARCHIVE FILTER (DAILY OR MONTHLY)
========================================================= */
$archive_basis =
    (isset($_GET["archive_basis"]) && $_GET["archive_basis"] === "monthly")
    ? "monthly"
    : "daily";

$archive_date =
    $_GET["archive_date"] ?? date("Y-m-d");

$date_object =
    DateTime::createFromFormat(
        "Y-m-d",
        $archive_date
    );

if (
    !$date_object
    || $date_object->format("Y-m-d") !== $archive_date
) {

    $archive_date =
        date("Y-m-d");
}

$archive_month =
    isset($_GET["archive_month"])
    ? intval($_GET["archive_month"])
    : intval(date("n"));

if ($archive_month < 1 || $archive_month > 12) {
    $archive_month = intval(date("n"));
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

$show_archive =
    isset($_GET["show_archive"])
    || isset($_GET["archive_basis"])
    || isset($_GET["archive_date"])
    || isset($_GET["archive_month"])
    || isset($_GET["archive_page"])
    || isset($_GET["archive_q"]);

$archive_display_label =
    $archive_basis === "monthly"
    ? $month_names[$archive_month] . " " . date("Y")
    : date("F d, Y", strtotime($archive_date));

/* =========================================================
   GET ALL ACTIVE VEHICLES
========================================================= */

$all_vehicles = [];

if ($search !== "") {

    $stmt = $conn->prepare("
        SELECT
            id,
            owner_name,
            brand,
            plate_number,
            model,
            address,
            vehicle_type,
            status,
            date_created,
            extra_info
        FROM registered_vehicles
        WHERE plate_number LIKE ?
           OR brand LIKE ?
           OR model LIKE ?
           OR address LIKE ?
           OR extra_info LIKE ?
        ORDER BY address ASC, id DESC
    ");

    $search_param =
        "%" . $search . "%";

    $stmt->bind_param(
        "sssss",
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
            owner_name,
            brand,
            plate_number,
            model,
            address,
            vehicle_type,
            status,
            date_created,
            extra_info
        FROM registered_vehicles
        ORDER BY address ASC, id DESC
    ");
}

$stmt->execute();

$result =
    $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    // Extra owner fields as [field key => value] (only the active fields are shown)
    $row["extra"] = secsysOwnerExtraDecode($row["extra_info"] ?? null);
    unset($row["extra_info"]);

    $all_vehicles[] =
        $row;
}

$stmt->close();

/* =========================================================
   DAILY / MONTHLY VIEW (by registration date)
   All (default) | Daily = registered on that day | Monthly
========================================================= */
$listPeriod = secsysListPeriod('all', true);

if ($listPeriod['basis'] !== 'all') {
    $all_vehicles = array_values(array_filter(
        $all_vehicles,
        function ($vehicle) use ($listPeriod) {
            return secsysPeriodMatches($vehicle['date_created'] ?? null, $listPeriod);
        }
    ));
}

/* =========================================================
   GROUP VEHICLES BY ADDRESS
========================================================= */

$owner_groups = [];

foreach ($all_vehicles as $vehicle) {

    $group_key =
        $vehicle["address"];

    if (!isset($owner_groups[$group_key])) {

        $owner_groups[$group_key] = [
            "owner_name" =>
                "",

            "address" =>
                $vehicle["address"],

            "vehicles" =>
                []
        ];
    }

    $owner_groups[$group_key]["vehicles"][] =
        $vehicle;
}

// Bring highlighted newly added vehicle address group to the top of page 1
if (!empty($highlight_plate)) {
    $highlighted_grp = null;
    $highlighted_k = null;
    $normH = str_replace(' ', '', strtoupper($highlight_plate));
    foreach ($owner_groups as $k => $grp) {
        foreach ($grp["vehicles"] as $v) {
            $normV = str_replace(' ', '', strtoupper($v["plate_number"]));
            if ($normV === $normH) {
                $highlighted_grp = $grp;
                $highlighted_k = $k;
                break 2;
            }
        }
    }
    if ($highlighted_grp !== null && $highlighted_k !== null) {
        unset($owner_groups[$highlighted_k]);
        $owner_groups = [$highlighted_k => $highlighted_grp] + $owner_groups;
    }
}

/* =========================================================
   OWNER PAGINATION
========================================================= */

$records_per_page = 5;

$current_page =
    intval(
        $_GET["page"] ?? 1
    );

if ($current_page < 1) {
    $current_page = 1;
}

$total_records =
    count($owner_groups);

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

$owner_groups_page =
    array_slice(
        $owner_groups,
        $offset,
        $records_per_page,
        true
    );

/* =========================================================
   ARCHIVE PAGINATION
========================================================= */

$archive_records_per_page = 5;

$archive_page =
    intval(
        $_GET["archive_page"] ?? 1
    );

if ($archive_page < 1) {
    $archive_page = 1;
}

/* =========================================================
   COUNT ARCHIVE
========================================================= */

/* Archive filter: date / month + optional search text (archive_q) */
$archive_search = secsysArchiveSearchFromRequest();
if ($archive_basis === "monthly") {
    $archive_filter_sql = "MONTH(archive_date) = ?";
    $archive_filter_types = "i";
    $archive_filter_args = [$archive_month];
} else {
    $archive_filter_sql = "archive_date = ?";
    $archive_filter_types = "s";
    $archive_filter_args = [$archive_date];
}
if ($archive_search !== "") {
    $archive_like = "%" . $archive_search . "%";
    $archive_filter_sql .= " AND (plate_number LIKE ? OR owner_name LIKE ? OR brand LIKE ? OR model LIKE ? OR address LIKE ? OR vehicle_type LIKE ?)";
    $archive_filter_types .= "ssssss";
    for ($i = 0; $i < 6; $i++) {
        $archive_filter_args[] = $archive_like;
    }
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM registered_vehicles_archive WHERE " . $archive_filter_sql);
$stmt->bind_param($archive_filter_types, ...$archive_filter_args);

$stmt->execute();

$count_archive_result =
    $stmt->get_result();

$count_archive_row =
    $count_archive_result->fetch_assoc();

$total_archive_records =
    intval(
        $count_archive_row["total"]
    );

$stmt->close();

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
   GET ARCHIVE
========================================================= */

$archived_vehicles = [];

$archive_select_args = array_merge($archive_filter_args, [$archive_records_per_page, $archive_offset]);
$stmt = $conn->prepare("SELECT archive_id, original_id, owner_name, brand, plate_number, model, address, vehicle_type, status, original_date_created, archive_date, archived_at, archive_reason FROM registered_vehicles_archive WHERE " . $archive_filter_sql . " ORDER BY archived_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param($archive_filter_types . "ii", ...$archive_select_args);

$stmt->execute();

$archive_result =
    $stmt->get_result();

while ($row = $archive_result->fetch_assoc()) {

    $archived_vehicles[] =
        $row;
}

$stmt->close();

/* =========================================================
   PDF DOWNLOAD HANDLER
========================================================= */

$download_type =
    $_GET["download"] ?? "";

/* =========================================================
   DOWNLOAD REGISTERED VEHICLES PDF
========================================================= */

if ($download_type === "registered") {

    $options = new Options();

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
                margin: 35px 40px;
            }

            body {
                font-family: Arial, Helvetica, sans-serif;
                color: #111827;
                font-size: 11px;
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
                margin-bottom: 12px;
            }

            .report-title {
                font-size: 17px;
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
                padding: 8px 5px;
                text-align: center;
                font-size: 9px;
            }

            td {
                border: 1px solid #cccccc;
                padding: 7px 5px;
                font-size: 9px;
                vertical-align: top;
            }

            .owner-name {
                font-weight: bold;
            }

            .vehicle {
                margin-bottom: 6px;
            }

            .vehicle:last-child {
                margin-bottom: 0;
            }

            .vehicle-number {
                font-weight: bold;
            }

            .plate {
                font-weight: bold;
            }

            .footer {
                margin-top: 30px;
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
                Registered Vehicle Report
            </div>

            <div class="generated">
                Generated:
                ' . e($generated_date) . '
            </div>

        </div>

        <table>

            <thead>

                <tr>

                    <th style="width:5%;">
                        #
                    </th>

                    <th style="width:50%;">
                        Vehicle
                    </th>

                    <th style="width:12%;">
                        Vehicle Type
                    </th>

                    <th style="width:28%;">
                        Address
                    </th>
    ';

    // Extra owner fields added by the Administrator
    foreach ($ownerFields as $ownerField) {
        $pdf_html .= '<th>' . e($ownerField['label']) . '</th>';
    }

    $pdf_html .= '

                </tr>

            </thead>

            <tbody>
    ';

    if (
        count($owner_groups_page) > 0
    ) {

        $pdf_number = 1;

        foreach (
            $owner_groups_page
            as $group
        ) {

            $pdf_html .= '

                <tr>

                    <td style="text-align:center;">
                        ' . $pdf_number . '
                    </td>

                    <td>
            ';

            foreach (
                $group["vehicles"]
                as $vehicle_index => $vehicle
            ) {

                $pdf_html .= '

                    <div class="vehicle">

                        <span class="vehicle-number">
                            Vehicle ' .
                            ($vehicle_index + 1) .
                            ':
                        </span>

                        ' .
                        e(
                            $vehicle["brand"]
                        ) .
                        ' ' .
                        e(
                            $vehicle["model"]
                        ) .
                        '

                        <br>

                        <span class="plate">
                            Plate:
                        </span>

                        ' .
                        e(
                            $vehicle["plate_number"]
                        ) .
                        '

                    </div>

                ';
            }

            $pdf_html .= '

                    </td>

                    <td style="text-align:center;">
            ';

            $types = [];

            foreach (
                $group["vehicles"]
                as $vehicle
            ) {

                $types[] =
                    e(
                        $vehicle[
                            "vehicle_type"
                        ]
                    );
            }

            $pdf_html .=
                implode(
                    "<br>",
                    $types
                );

            $pdf_html .= '

                    </td>

                    <td>
                        ' .
                        e(
                            $group["address"]
                        ) .
                        '
                    </td>
            ';

            foreach ($ownerFields as $ownerField) {
                $ownerValue = secsysOwnerGroupValue($group["vehicles"], $ownerField['key']);
                $pdf_html .= '<td>' . ($ownerValue !== '' ? e($ownerValue) : '-') . '</td>';
            }

            $pdf_html .= '

                </tr>

            ';

            $pdf_number++;
        }

    } else {

        $pdf_html .= '

            <tr>

                <td
                    colspan="' . (5 + count($ownerFields)) . '"
                    style="
                        text-align:center;
                        padding:20px;
                    "
                >
                    No registered vehicles found.
                </td>

            </tr>

        ';
    }

    $pdf_html .= '

            </tbody>

        </table>

        <div class="footer">

            L. Surveillance -
            Registered Vehicle Management System

        </div>

    </body>

    </html>

    ';

    // CSV option: same rows as the PDF
    require_once __DIR__ . '/app/print_report.php';
    if (secsysWantsCsvDownload()) {
        secsysStreamCsvFromHtml($pdf_html, "registered_vehicle_report_" . date("Y-m-d"));
        exit();
    }

    $dompdf->loadHtml(
        $pdf_html
    );

    $dompdf->setPaper(
        "A4",
        "portrait"
    );

    $dompdf->render();

    $dompdf->stream(
        "registered_vehicle_report_" .
        date("Y-m-d") .
        ".pdf",
        [
            "Attachment" => true
        ]
    );

    exit();
}

/* =========================================================
   DOWNLOAD ARCHIVE VEHICLES PDF
========================================================= */

if ($download_type === "archive") {

    $options = new Options();

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

    $formatted_archive_date =
        $archive_display_label;

    $pdf_html = '

    <!DOCTYPE html>

    <html>

    <head>

        <meta charset="UTF-8">

        <style>

            @page {
                margin: 35px 40px;
            }

            body {
                font-family: Arial, Helvetica, sans-serif;
                color: #111827;
                font-size: 11px;
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
                margin-bottom: 12px;
            }

            .report-title {
                font-size: 17px;
                font-weight: bold;
                margin-bottom: 5px;
            }

            .archive-date {
                font-size: 11px;
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
                padding: 8px 5px;
                text-align: center;
                font-size: 9px;
            }

            td {
                border: 1px solid #cccccc;
                padding: 7px 5px;
                font-size: 9px;
                vertical-align: middle;
            }

            .vehicle {
                font-weight: bold;
            }

            .plate {
                font-weight: bold;
            }

            .footer {
                margin-top: 30px;
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
                Registered Vehicle Archive Report
            </div>

            <div class="archive-date">
                Archive Date:
                <strong>
                    ' .
                    e(
                        $formatted_archive_date
                    ) .
                    '
                </strong>
            </div>

            <div class="generated">
                Generated:
                ' .
                e(
                    $generated_date
                ) .
                '
            </div>

        </div>

        <table>

            <thead>

                <tr>

                    <th style="width:41%;">
                        Vehicle
                    </th>

                    <th style="width:16%;">
                        Plate Number
                    </th>

                    <th style="width:12%;">
                        Vehicle Type
                    </th>

                    <th style="width:19%;">
                        Address
                    </th>

                    <th style="width:12%;">
                        Archived
                    </th>

                </tr>

            </thead>

            <tbody>
    ';

    if (
        count(
            $archived_vehicles
        ) > 0
    ) {

        foreach (
            $archived_vehicles
            as $archive
        ) {

            $pdf_html .= '

                <tr>

                    <td>

                        <div class="vehicle">

                            ' .
                            e(
                                $archive[
                                    "brand"
                                ]
                            ) .
                            '

                            ' .
                            e(
                                $archive[
                                    "model"
                                ]
                            ) .
                            '

                        </div>

                    </td>

                    <td class="plate">
                        ' .
                        e(
                            $archive[
                                "plate_number"
                            ]
                        ) .
                        '
                    </td>

                    <td style="text-align:center;">
                        ' .
                        e(
                            $archive[
                                "vehicle_type"
                            ]
                        ) .
                        '
                    </td>

                    <td>
                        ' .
                        e(
                            $archive[
                                "address"
                            ]
                        ) .
                        '
                    </td>

                    <td style="text-align:center;">
                        ' .
                        e(
                            date(
                                "M d, Y h:i A",
                                strtotime(
                                    $archive[
                                        "archived_at"
                                    ]
                                )
                            )
                        ) .
                        '
                    </td>

                </tr>

            ';
        }

    } else {

        $pdf_html .= '

            <tr>

                <td
                    colspan="6"
                    style="
                        text-align:center;
                        padding:20px;
                    "
                >
                    No archived vehicles found
                    for this date.
                </td>

            </tr>

        ';
    }

    $pdf_html .= '

            </tbody>

        </table>

        <div class="footer">

            L. Surveillance -
            Registered Vehicle Management System

        </div>

    </body>

    </html>

    ';

    // CSV option: same rows as the PDF
    require_once __DIR__ . '/app/print_report.php';
    if (secsysWantsCsvDownload()) {
        secsysStreamCsvFromHtml($pdf_html, "registered_vehicle_archive_" . $archive_date);
        exit();
    }

    $dompdf->loadHtml(
        $pdf_html
    );

    $dompdf->setPaper(
        "A4",
        "portrait"
    );

    $dompdf->render();

    $dompdf->stream(
        "registered_vehicle_archive_" .
        $archive_date .
        ".pdf",
        [
            "Attachment" => true
        ]
    );

    exit();
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
    Registered Vehicles | <?php echo htmlspecialchars($systemName); ?>
</title>

<style>

/* =========================================================
   RESET
========================================================= */

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f7f7f7;
    color: #111;
    min-height: 100vh;
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
   MAIN
========================================================= */

.main {
    margin-left: 212px;
    width: calc(100% - 212px);
    min-height: 100vh;
    padding: 32px 38px 40px;
    box-sizing: border-box;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 14px;
}

.page-title {
    font-size: 23px;
    font-weight: 400;
    margin-bottom: 2px;
}

.page-date {
    font-size: 12px;
    color: #666;
}

/* =========================================================
   TOP ACTIONS
========================================================= */

.top-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.btn {
    border: none;
    height: 39px;
    padding: 0 28px;
    border-radius: 22px;
    font-size: 12px;
    cursor: pointer;
    transition: 0.2s;
}

.btn-primary {
    background: #2f80ed;
    color: white;
}

.btn-primary:hover {
    background: #1769d3;
}

.btn-secondary {
    background: white;
    color: #1769d3;
    border: 1px solid #2f80ed;
}

.btn-secondary:hover {
    background: #edf5ff;
}

.btn-danger {
    background: #e74c3c;
    color: white;
}

.btn-danger:hover {
    background: #c0392b;
}

.btn-warning {
    background: #f39c12;
    color: white;
}

.btn-warning:hover {
    background: #d68910;
}

.add-btn {
    min-width: 150px;
}

.archive-btn {
    min-width: 110px;
}

/* =========================================================
   SEARCH
========================================================= */

.search-form {
    display: flex;
    align-items: center;
    gap: 7px;
}

.search-input {
    height: 39px;
    width: 220px;
    border: 1px solid #d2d2d2;
    border-radius: 22px;
    padding: 0 15px;
    font-size: 12px;
    outline: none;
    background: white;
}

.search-input:focus {
    border-color: #2f80ed;
    box-shadow:
        0 0 0 2px
        rgba(47,128,237,0.10);
}

.search-btn {
    min-width: 75px;
    padding: 0 18px;
}

.clear-search-btn {
    height: 39px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    padding: 0 18px;
}

/* =========================================================
   ARCHIVE DATE
========================================================= */

.archive-date-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
}

.archive-date-label {
    font-size: 12px;
    color: #555;
}

.archive-date {
    height: 39px;
    border: 1px solid #2f80ed;
    border-radius: 22px;
    padding: 0 13px;
    font-size: 12px;
    color: #333;
    background: white;
    cursor: pointer;
}

/* =========================================================
   TABLE CARD
========================================================= */

.table-card {
    background: white;
    border-radius: 25px;
    border: 1px solid #e1e1e1;
    box-shadow:
        0 4px 4px
        rgba(0,0,0,0.18);
    overflow: visible;
    width: 100%;
    margin-bottom: 28px;
}

.table-section-title {
    padding: 15px 20px 4px;
    font-size: 17px;
    font-weight: 400;
}

.table-section-subtitle {
    padding: 0 20px 12px;
    font-size: 11px;
    color: #777;
}

/* =========================================================
   TABLE
========================================================= */

.table-wrapper {
    overflow-x: auto;
}

.vehicle-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.vehicle-table th {
    font-size: 12px;
    font-weight: 500;
    padding: 10px 6px;
    border-bottom: 1px solid #bbbbbb;
    white-space: nowrap;
    text-align: center;
    vertical-align: middle;
}

.vehicle-table td {
    font-size: 12px;
    padding: 11px 6px;
    border-bottom: 1px solid #d1d1d1;
    text-align: center;
    vertical-align: middle;
}

.vehicle-table tr:last-child td {
    border-bottom: none;
}

.vehicle-table tbody tr:hover {
    background: #f7faff;
}

/* =========================================================
   CHECKBOX
========================================================= */

.vehicle-checkbox,
#selectAll {
    width: 14px;
    height: 14px;
    cursor: pointer;
    accent-color: #2f80ed;
}

/* =========================================================
   COLUMNS
========================================================= */

.checkbox-col {
    width: 42px;
}

.name-col {
    width: 19%;
}

.vehicle-col {
    width: 27%;
}

.address-col {
    width: 18%;
}

.type-col {
    width: 13%;
}

.status-col {
    width: 9%;
}

.action-col {
    width: 14%;
}

.plate-col {
    width: 16%;
}

/* Extra owner fields added by the Administrator */
.owner-extra-col {
    width: 150px;
    white-space: normal !important;
    word-break: break-word;
}

/* =========================================================
   MAIN VEHICLE DISPLAY
========================================================= */

.main-vehicle {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
}

.main-vehicle strong {
    font-weight: 500;
}

.vehicle-detail-small {
    font-size: 11px;
    color: #666;
}

.vehicle-more-wrapper {
    position: relative;
    display: inline-block;
    margin-top: 5px;
}

/* =========================================================
   VIEW MORE BUTTON
========================================================= */

.view-more-btn {
    border: none;
    background: #edf5ff;
    color: #1769d3;
    border-radius: 14px;
    padding: 5px 12px;
    font-size: 10px;
    cursor: pointer;
    transition: 0.2s;
}

.view-more-btn:hover {
    background: #2f80ed;
    color: white;
}

/* =========================================================
   HOVER PREVIEW
========================================================= */

.vehicle-preview {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    width: 260px;
    background: white;
    border: 1px solid #d9d9d9;
    border-radius: 12px;
    box-shadow:
        0 7px 22px
        rgba(0,0,0,0.18);

    padding: 12px;
    text-align: left;

    opacity: 0;
    visibility: hidden;
    pointer-events: none;

    transition:
        opacity 0.15s ease,
        visibility 0.15s ease;

    z-index: 50;
}

.vehicle-more-wrapper:hover .vehicle-preview {
    opacity: 1;
    visibility: visible;
}

.preview-title {
    font-size: 11px;
    font-weight: bold;
    color: #333;
    margin-bottom: 8px;
}

.preview-item {
    border-top: 1px solid #eeeeee;
    padding: 7px 0;
    font-size: 10px;
    line-height: 1.5;
}

.preview-item:first-of-type {
    border-top: none;
}

.preview-plate {
    font-weight: bold;
    color: #1769d3;
}

.preview-hint {
    margin-top: 8px;
    font-size: 9px;
    color: #888;
    text-align: center;
}

/* =========================================================
   SELECTION INFO
========================================================= */

.selection-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 20px 12px;
    font-size: 11px;
    color: #777;
}

.selection-info strong {
    color: #2f80ed;
}

/* =========================================================
   EMPTY
========================================================= */

.empty-row td {
    padding: 35px 10px;
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
    padding: 12px 0 15px;
}

.page-link {
    min-width: 28px;
    height: 28px;
    padding: 0 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #b5c7e6;
    border-radius: 6px;
    background: white;
    color: #333;
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
}

.page-link.active {
    background: #2f80ed;
    border-color: #2f80ed;
    color: #fff;
    font-weight: bold;
}

.page-link:hover:not(.active) {
    background: #e8f0ff;
}

/* =========================================================
   MESSAGE
========================================================= */

.message {
    padding: 11px 15px;
    border-radius: 9px;
    margin-bottom: 15px;
    font-size: 13px;
}

.message.success {
    background: #eaf8ef;
    color: #207a3b;
    border: 1px solid #bde5c9;
}

.message.error {
    background: #fff0ef;
    color: #b52b21;
    border: 1px solid #f1c0bb;
}

/* =========================================================
   ARCHIVE PANEL
========================================================= */

.archive-panel {
    display: none;
    margin-top: 5px;
}

.archive-panel.show {
    display: block;
}

.archive-heading {
    font-size: 17px;
    font-weight: 400;
    padding: 15px 20px 4px;
}

.archive-subheading {
    font-size: 11px;
    color: #777;
    padding: 0 20px 12px;
}

/* =========================================================
   MODALS
========================================================= */

.modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
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
    width: 450px;
    max-width: 95%;
    max-height: 90vh;
    overflow-y: auto;
    background: white;
    border-radius: 18px;
    padding: 24px;
    box-shadow:
        0 10px 35px
        rgba(0,0,0,0.25);

    animation:
        modalIn 0.18s ease-out;
}

.modal-large {
    width: 760px;
}

.modal-archive {
    width: 96%;
    max-width: 1250px;
    border-radius: 20px;
    padding: 22px;
}

@keyframes modalIn {

    from {
        transform: translateY(-15px);
        opacity: 0;
    }

    to {
        transform: translateY(0);
        opacity: 1;
    }
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

.modal-owner {
    font-size: 12px;
    color: #777;
    margin-top: 4px;
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

/* =========================================================
   VEHICLE MANAGEMENT POPUP
========================================================= */

.vehicle-management-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.management-vehicle {
    border: 1px solid #dddddd;
    border-radius: 13px;
    padding: 15px;
    background: #fafafa;
}

.management-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
}

.management-number {
    font-size: 11px;
    color: #888;
    margin-bottom: 4px;
}

.management-title {
    font-size: 15px;
    font-weight: 500;
}

.management-plate {
    color: #1769d3;
    font-size: 12px;
    font-weight: bold;
    margin-top: 3px;
}

.management-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 7px 15px;
    margin-top: 12px;
}

.management-detail {
    font-size: 11px;
    color: #555;
}

.management-detail strong {
    color: #222;
}

.management-actions {
    display: flex;
    gap: 7px;
    margin-top: 13px;
    justify-content: flex-end;
}

.small-action {
    height: 30px;
    padding: 0 15px;
    font-size: 10px;
}

/* =========================================================
   FORM
========================================================= */

.form-group {
    margin-bottom: 14px;
}

.form-label {
    display: block;
    font-size: 12px;
    margin-bottom: 6px;
    color: #333;
}

.form-input,
.form-select {
    width: 100%;
    height: 40px;
    border: 1px solid #d2d2d2;
    border-radius: 8px;
    padding: 0 12px;
    font-size: 13px;
    outline: none;
    background: white;
}

.form-input:focus,
.form-select:focus {
    border-color: #2f80ed;
    box-shadow:
        0 0 0 2px
        rgba(47,128,237,0.10);
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .search-input {
        width: 180px;
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

    .vehicle-table {
        min-width: 850px;
    }

    .table-wrapper {
        overflow-x: auto;
    }
}

@media (max-width: 650px) {

    .main {
        padding: 20px 14px 30px;
    }

    .top-actions {
        align-items: stretch;
    }

    .search-form {
        width: 100%;
    }

    .search-input {
        flex: 1;
        width: auto;
    }

    .archive-date-wrapper {
        width: 100%;
    }

    .archive-date {
        flex: 1;
    }

    .management-details {
        grid-template-columns: 1fr;
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

    .top-actions,
    .search-form,
    .archive-date-wrapper,
    .btn-create,
    .btn-export {
        display: none !important;
    }
}

</style>

<link rel="stylesheet" href="assets/css/period_filter.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/period_filter.css'); ?>">

<script src="assets/js/print_report.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/print_report.js'); ?>"></script>
<script src="assets/js/archive_search.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/archive_search.js'); ?>"></script>

<!-- Same look as the Security Guard dashboard for every Administrator page -->
<link rel="stylesheet" href="assets/css/admin_uniform.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/admin_uniform.css'); ?>">
<!-- Enlarges the page 25% (logs +25% more) on laptops/desktops for easier reading (loaded after the page styles) -->
<link rel="stylesheet" href="assets/css/ui_scale.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/ui_scale.css'); ?>">

</head>

<body>

<!-- =====================================================
     SIDEBAR
===================================================== -->

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


        <a href="logs.php">

            <span class="nav-icon">
                📋
            </span>

            <span>
                Logs
            </span>

        </a>


        <a href="registered.php"
           class="active">

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
===================================================== -->

<main class="main">

    <header class="page-header">

        <div>

            <h1 class="page-title">
                Registered
            </h1>

            <div class="page-date">

                <?php
                echo date("l, F d, Y");
                ?>

            </div>

        </div>

    </header>

    <?php if ($message !== ""): ?>

        <div class="message <?php echo e($message_type); ?>">

            <?php echo e($message); ?>

        </div>

    <?php endif; ?>

    <!-- =================================================
         ACTIONS
    ================================================= -->

    <div class="top-actions">

        <form
            method="GET"
            action="registered.php"
            class="search-form"
        >
            <?php echo secsysPeriodHiddenInputs($listPeriod); ?>

            <input
                type="text"
                name="search"
                class="search-input"
                placeholder="Search plate, brand, model or address..."
                value="<?php echo e($search); ?>"
            >

            <button
                type="submit"
                class="btn btn-primary search-btn"
            >
                Search
            </button>

            <?php if ($search !== ""): ?>

                <a
                    href="registered.php"
                    class="btn btn-secondary clear-search-btn"
                >
                    Clear
                </a>

            <?php endif; ?>

        </form>

        <button
            type="button"
            class="btn btn-primary add-btn"
            onclick="openAddModal()"
        >
            ➕ Add New Owner
        </button>

        <button
            type="button"
            class="btn btn-primary"
            onclick="openUpdateModal()"
        >
            🔄 Update
        </button>

        <button
            type="button"
            class="btn btn-primary"
            onclick="printCurrentVehicles()"
        >
            🖨️ Print Report
        </button>

        <button
            type="button"
            class="btn btn-secondary"
            onclick="downloadCurrentVehicles(this)"
        >
            📥 Download ▾
        </button>

        <button
            type="button"
            class="btn btn-secondary archive-btn"
            onclick="toggleArchive()"
        >
            📦 Archive
        </button>

    </div>

    <!-- =================================================
         REGISTERED TABLE
    ================================================= -->

    <!-- DAILY / MONTHLY VIEW (by registration date) -->
    <?php echo secsysRenderPeriodFilter($listPeriod, 'registered.php', ['search' => $search]); ?>

    <div class="table-card" id="liveRegisteredTable" data-live>

        <div class="table-section-title">
            Registered Vehicles
        </div>

        <div class="table-section-subtitle">
            Registered vehicle owners inside Lessandra Subdivision
        </div>

        <?php if ($search !== ""): ?>

            <div
                style="
                    padding:0 20px 12px;
                    font-size:11px;
                    color:#555;
                "
            >

                Search results for:

                <strong>
                    <?php echo e($search); ?>
                </strong>

            </div>

        <?php endif; ?>

        <div class="selection-info">

            <span>

                <strong id="selectedCount">
                    0
                </strong>

                record(s) selected

            </span>

            <button
                type="button"
                class="btn btn-danger"
                style="
                    height:30px;
                    padding:0 17px;
                    font-size:11px;
                "
                onclick="archiveSelected()"
            >
                Archive Selected
            </button>

        </div>

        <div class="table-wrapper">

            <table class="vehicle-table"<?php if ($ownerFields): ?> style="min-width: <?php echo 760 + 150 * count($ownerFields); ?>px;"<?php endif; ?>>

                <thead>

                    <tr>

                        <th class="checkbox-col">

                            <!-- <input
                                type="checkbox"
                                id="selectAll"
                                title="Select all"
                            > -->

                        </th>

                        <th class="vehicle-col">
                            Vehicle
                        </th>

                        <th class="type-col">
                            Vehicle Type
                        </th>

                        <th class="address-col">
                            Address
                        </th>

                        <?php foreach ($ownerFields as $ownerField): ?>
                            <th class="owner-extra-col">
                                <?php echo e($ownerField['label']); ?>
                            </th>
                        <?php endforeach; ?>

                        <th class="action-col">
                            Action
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php if (count($owner_groups_page) > 0): ?>

                    <?php foreach (
                        $owner_groups_page
                        as $group_key => $group
                    ): ?>

                        <?php

                        $group_vehicles =
                            $group["vehicles"];

                        $first_vehicle =
                            $group_vehicles[0];

                        $vehicle_count =
                            count($group_vehicles);

                        $group_json =
                            json_encode(
                                [
                                    "address" =>
                                        $group["address"],

                                    "vehicles" =>
                                        $group_vehicles
                                ],
                                JSON_HEX_TAG |
                                JSON_HEX_APOS |
                                JSON_HEX_QUOT |
                                JSON_HEX_AMP
                            );

                        ?>

                        <tr>

                            <td>

                                <input
                                    type="checkbox"
                                    class="vehicle-checkbox owner-checkbox"
                                    data-group="<?php
                                        echo e($group_json);
                                    ?>"
                                >

                            </td>

                            <td>

                                <div class="main-vehicle">

                                    <strong>

                                        <?php
                                        echo e(
                                            $first_vehicle["brand"]
                                        );
                                        ?>

                                        <?php
                                        echo e(
                                            $first_vehicle["model"]
                                        );
                                        ?>

                                    </strong>

                                    <div class="vehicle-detail-small">

                                        <?php echo secsysClickablePlateHtml($first_vehicle["plate_number"], $first_vehicle); ?>

                                    </div>

                                    <?php if ($vehicle_count >= 2): ?>

                                        <div class="vehicle-more-wrapper">

                                            <button
                                                type="button"
                                                class="view-more-btn"
                                                onclick='openVehicleManager(<?php
                                                    echo $group_json;
                                                ?>)'
                                            >
                                                View More
                                                (<?php
                                                    echo $vehicle_count;
                                                ?>)
                                            </button>

                                            <div class="vehicle-preview">

                                                <div class="preview-title">

                                                    <?php
                                                    echo e(
                                                        $group["address"]
                                                    );
                                                    ?>

                                                    —
                                                    <?php
                                                    echo $vehicle_count;
                                                    ?>
                                                    Vehicles

                                                </div>

                                                <?php
                                                foreach (
                                                    $group_vehicles
                                                    as $index => $preview
                                                ):
                                                ?>

                                                    <div class="preview-item">

                                                        <div>

                                                            <strong>
                                                                <?php
                                                                echo
                                                                    $index + 1;
                                                                ?>.
                                                                <?php
                                                                echo e(
                                                                    $preview[
                                                                        "brand"
                                                                    ]
                                                                );
                                                                ?>

                                                                <?php
                                                                echo e(
                                                                    $preview[
                                                                        "model"
                                                                    ]
                                                                );
                                                                ?>
                                                            </strong>

                                                        </div>

                                                        <div
                                                            class="preview-plate"
                                                        >

                                                            <?php echo secsysClickablePlateHtml($preview["plate_number"], $preview); ?>

                                                        </div>

                                                        <div>

                                                            <?php
                                                            echo e(
                                                                $preview[
                                                                    "vehicle_type"
                                                                ]
                                                            );
                                                            ?>

                                                        </div>

                                                    </div>

                                                <?php endforeach; ?>

                                                <div class="preview-hint">

                                                    Click View More
                                                    to manage vehicles

                                                </div>

                                            </div>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </td>

                            <td>

                                <?php
                                echo e(
                                    $first_vehicle[
                                        "vehicle_type"
                                    ]
                                );
                                ?>

                                <?php if ($vehicle_count > 1): ?>

                                    <div
                                        style="
                                            font-size:10px;
                                            color:#777;
                                            margin-top:3px;
                                        "
                                    >

                                        + <?php
                                        echo
                                            $vehicle_count - 1;
                                        ?> more

                                    </div>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?php
                                echo e(
                                    $group["address"]
                                );
                                ?>

                            </td>

                            <?php foreach ($ownerFields as $ownerField): ?>
                                <?php $ownerValue = secsysOwnerGroupValue($group_vehicles, $ownerField['key']); ?>
                                <td class="owner-extra-col"><?php echo $ownerValue !== '' ? e($ownerValue) : '<span style="color:#94a3b8;">—</span>'; ?></td>
                            <?php endforeach; ?>

                            <td>

                                <?php if ($vehicle_count >= 2): ?>

                                    <button
                                        type="button"
                                        class="btn btn-primary"
                                        style="
                                            height:30px;
                                            padding:0 14px;
                                            font-size:10px;
                                        "
                                        onclick='openVehicleManager(<?php
                                            echo $group_json;
                                        ?>)'
                                    >
                                        Manage
                                    </button>

                                <?php else: ?>

                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        style="
                                            height:30px;
                                            padding:0 14px;
                                            font-size:10px;
                                        "
                                        onclick='openSingleVehicle(<?php
                                            echo json_encode(
                                                $first_vehicle,
                                                JSON_HEX_TAG |
                                                JSON_HEX_APOS |
                                                JSON_HEX_QUOT |
                                                JSON_HEX_AMP
                                            );
                                        ?>)'
                                    >
                                        View
                                    </button>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr class="empty-row">

                        <td colspan="<?php echo 5 + count($ownerFields); ?>">

                            <?php if ($search !== ""): ?>

                                No registered vehicle found
                                for "<?php echo e($search); ?>".

                            <?php else: ?>

                                No registered vehicles found.

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <?php if ($total_records > 0): ?>

            <div class="pagination">

                <?php if ($current_page > 1): ?>

                    <a
                        class="page-link"
                        href="registered.php?<?php echo secsysPeriodQuery($listPeriod) !== '' ? e(secsysPeriodQuery($listPeriod)) . '&amp;' : ''; ?>search=<?php
                            echo urlencode($search);
                        ?>&page=<?php
                            echo $current_page - 1;
                        ?>"
                    >
                        ‹
                    </a>

                <?php endif; ?>

                <?php
                for (
                    $i = 1;
                    $i <= $total_pages;
                    $i++
                ):
                ?>

                    <a
                        class="page-link <?php
                            echo
                                $i === $current_page
                                ? "active"
                                : "";
                        ?>"
                        href="registered.php?<?php echo secsysPeriodQuery($listPeriod) !== '' ? e(secsysPeriodQuery($listPeriod)) . '&amp;' : ''; ?>search=<?php
                            echo urlencode($search);
                        ?>&page=<?php
                            echo $i;
                        ?>"
                    >

                        <?php echo $i; ?>

                    </a>

                <?php endfor; ?>

                <?php if ($current_page < $total_pages): ?>

                    <a
                        class="page-link"
                        href="registered.php?<?php echo secsysPeriodQuery($listPeriod) !== '' ? e(secsysPeriodQuery($listPeriod)) . '&amp;' : ''; ?>search=<?php
                            echo urlencode($search);
                        ?>&page=<?php
                            echo $current_page + 1;
                        ?>"
                    >
                        ›
                    </a>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

</main>

</div>

<!-- =====================================================
     ARCHIVE MODAL
===================================================== -->

<div
    class="modal <?php echo $show_archive ? 'show' : ''; ?>"
    id="archivePanel"
>

    <div class="modal-content modal-archive">

        <div class="modal-header" style="margin-bottom: 14px;">

            <div>

                <div class="modal-title">
                    Registered Vehicle Archive
                </div>

                <div class="archive-subheading" style="padding: 4px 0 0;">

                    Archived vehicles for

                    <strong>
                        <?php echo e($archive_display_label); ?>
                    </strong>

                </div>

            </div>

            <button
                type="button"
                class="close-modal"
                onclick="closeArchive()"
            >
                ×
            </button>

        </div>

        <div class="table-card" style="margin-bottom: 0; padding-top: 14px;">

            <div
                style="
                    padding:0 20px 14px;
                    display:flex;
                    justify-content:space-between;
                    align-items:center;
                    gap:12px;
                    flex-wrap:wrap;
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
                        value="<?php echo e($archive_basis); ?>"
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
                                background:<?php echo $archive_basis === 'daily' ? '#2f80ed' : 'transparent'; ?>;
                                color:<?php echo $archive_basis === 'daily' ? '#ffffff' : '#1769d3'; ?>;
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
                                background:<?php echo $archive_basis === 'monthly' ? '#2f80ed' : 'transparent'; ?>;
                                color:<?php echo $archive_basis === 'monthly' ? '#ffffff' : '#1769d3'; ?>;
                            "
                        >
                            🗓️ Monthly
                        </button>
                    </div>

                    <!-- DAILY CALENDAR PICKER -->
                    <div
                        id="dailyPickerBox"
                        style="
                            display:<?php echo $archive_basis === 'daily' ? 'flex' : 'none'; ?>;
                            align-items:center;
                            gap:8px;
                        "
                    >
                        <span class="archive-date-label">
                            Select Date:
                        </span>

                        <input
                            type="date"
                            id="archiveDate"
                            class="archive-date"
                            value="<?php echo e($archive_date); ?>"
                            onchange="loadArchiveFilter()"
                        >
                    </div>

                    <!-- MONTHLY DROPDOWN (JANUARY - DECEMBER) -->
                    <div
                        id="monthlyPickerBox"
                        style="
                            display:<?php echo $archive_basis === 'monthly' ? 'flex' : 'none'; ?>;
                            align-items:center;
                            gap:8px;
                        "
                    >
                        <span class="archive-date-label">
                            Select Month:
                        </span>

                        <select
                            id="archiveMonth"
                            class="archive-date"
                            onchange="loadArchiveFilter()"
                        >
                            <?php foreach ($month_names as $m_num => $m_name): ?>
                                <option
                                    value="<?php echo (int)$m_num; ?>"
                                    <?php echo (int)$archive_month === (int)$m_num ? 'selected' : ''; ?>
                                >
                                    <?php echo e($m_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
<!-- SEARCH INSIDE THE ARCHIVE -->
<div class="archive-search-box">
    <span class="archive-search-icon" aria-hidden="true">🔍</span>
    <input type="search" id="archiveSearch" class="archive-search-input" data-reload="loadArchiveFilter" placeholder="Search plate, owner, brand, model or address..." value="<?php echo e(secsysArchiveSearchFromRequest()); ?>" autocomplete="off" aria-label="Search the archive">
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
                        onclick="printArchiveVehicles()"
                    >
                        🖨️ Print Archive Report
                    </button>

                    <button
                        type="button"
                        class="btn btn-secondary"
                        onclick="downloadArchiveVehicles(this)"
                    >
                        📥 Download Archive ▾
                    </button>

                </div>

            </div>

            <div class="table-wrapper">

                <table class="vehicle-table">

                    <thead>

                        <tr>

                            <th class="vehicle-col">
                                Vehicle
                            </th>

                            <th class="plate-col">
                                Plate Number
                            </th>

                            <th class="type-col">
                                Vehicle Type
                            </th>

                            <th class="address-col">
                                Address
                            </th>

                            <th class="status-col">
                                Archived
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (count($archived_vehicles) > 0): ?>

                        <?php foreach (
                            $archived_vehicles
                            as $archive
                        ): ?>

                            <tr>

                                <td>

                                    <?php
                                    echo e(
                                        $archive["brand"]
                                    );
                                    ?>

                                    <?php
                                    echo e(
                                        $archive["model"]
                                    );
                                    ?>

                                    <div
                                        style="
                                            font-size:10px;
                                            color:#1769d3;
                                            margin-top:3px;
                                        "
                                    >

                                        <?php echo secsysClickablePlateHtml($archive["plate_number"], $archive); ?>

                                    </div>

                                </td>

                                <td>

                                    <?php echo secsysClickablePlateHtml($archive["plate_number"], $archive); ?>

                                </td>

                                <td>

                                    <?php
                                    echo e(
                                        $archive[
                                            "vehicle_type"
                                        ]
                                    );
                                    ?>

                                </td>

                                <td>

                                    <?php
                                    echo e(
                                        $archive["address"]
                                    );
                                    ?>

                                </td>

                                <td>

                                    <?php
                                    echo date(
                                        "M d, Y h:i A",
                                        strtotime(
                                            $archive[
                                                "archived_at"
                                            ]
                                        )
                                    );
                                    ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr class="empty-row">

                            <td colspan="5">

                                No archived vehicles found
                                for <?php echo e($archive_display_label); ?>.

                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <?php if ($total_archive_pages > 1): ?>

                <div class="pagination">

                    <?php if ($archive_page > 1): ?>

                        <a
                            class="page-link"
                            href="registered.php?show_archive=1&archive_q=<?php echo urlencode($archive_search); ?>&archive_basis=<?php
                                echo urlencode($archive_basis);
                            ?>&archive_date=<?php
                                echo urlencode($archive_date);
                            ?>&archive_month=<?php
                                echo (int)$archive_month;
                            ?>&archive_page=<?php
                                echo $archive_page - 1;
                            ?>"
                        >
                            ‹
                        </a>

                    <?php endif; ?>

                    <?php
                    for (
                        $i = 1;
                        $i <= $total_archive_pages;
                        $i++
                    ):
                    ?>

                        <a
                            class="page-link <?php
                                echo
                                    $i === $archive_page
                                    ? "active"
                                    : "";
                            ?>"
                            href="registered.php?show_archive=1&archive_q=<?php echo urlencode($archive_search); ?>&archive_basis=<?php
                                echo urlencode($archive_basis);
                            ?>&archive_date=<?php
                                echo urlencode($archive_date);
                            ?>&archive_month=<?php
                                echo (int)$archive_month;
                            ?>&archive_page=<?php
                                echo $i;
                            ?>"
                        >

                            <?php echo $i; ?>

                        </a>

                    <?php endfor; ?>

                    <?php if (
                        $archive_page <
                        $total_archive_pages
                    ): ?>

                        <a
                            class="page-link"
                            href="registered.php?show_archive=1&archive_q=<?php echo urlencode($archive_search); ?>&archive_basis=<?php
                                echo urlencode($archive_basis);
                            ?>&archive_date=<?php
                                echo urlencode($archive_date);
                            ?>&archive_month=<?php
                                echo (int)$archive_month;
                            ?>&archive_page=<?php
                                echo $archive_page + 1;
                            ?>"
                        >
                            ›
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- =====================================================
     VEHICLE MANAGEMENT MODAL
===================================================== -->

<div
    class="modal"
    id="vehicleManagerModal"
>

    <div class="modal-content modal-large">

        <div class="modal-header">

            <div>

                <div class="modal-title">
                    Vehicle Management
                </div>

                <div
                    class="modal-owner"
                    id="managerOwner"
                >
                </div>

            </div>

            <button
                type="button"
                class="close-modal"
                onclick="closeModal('vehicleManagerModal')"
            >
                ×
            </button>

        </div>

        <div
            class="vehicle-management-list"
            id="managementVehicleList"
        >
        </div>

        <div class="modal-actions">

            <button
                type="button"
                class="btn btn-secondary"
                onclick="closeModal('vehicleManagerModal')"
            >
                Close
            </button>

        </div>

    </div>

</div>

<!-- =====================================================
     ADD / RENAME OWNER FIELD MODAL (opens on top of the owner form)
===================================================== -->

<div
    class="modal"
    id="ownerFieldModal"
    style="z-index: 10050;"
>

    <div class="modal-content owner-field-modal-content">

        <div class="modal-header">

            <div class="modal-title" id="ownerFieldModalTitle">
                Add New Field
            </div>

            <button
                type="button"
                class="close-modal"
                onclick="closeModal('ownerFieldModal')"
                aria-label="Close"
            >
                ×
            </button>

        </div>

        <form id="ownerFieldForm" onsubmit="return submitOwnerFieldModal(event)" autocomplete="off">

            <div class="owner-field-hint" id="ownerFieldModalHint">
                The new field is added to the owner form for every owner and shows as a column in the Registered list.
            </div>

            <div class="form-group">
                <label class="form-label" for="ownerFieldLabelInput">
                    Field Name
                </label>
                <input
                    type="text"
                    id="ownerFieldLabelInput"
                    class="form-input"
                    maxlength="40"
                    placeholder="Example: Owner Name"
                    required
                >
            </div>

            <div class="owner-field-suggestions" id="ownerFieldSuggestions">
                <span>Quick picks:</span>
                <button type="button" onclick="pickOwnerFieldSuggestion(this)">Owner Name</button>
                <button type="button" onclick="pickOwnerFieldSuggestion(this)">Contact Number</button>
                <button type="button" onclick="pickOwnerFieldSuggestion(this)">Email</button>
                <button type="button" onclick="pickOwnerFieldSuggestion(this)">Sticker / RFID No.</button>
                <button type="button" onclick="pickOwnerFieldSuggestion(this)">Remarks</button>
            </div>

            <div class="owner-field-error" id="ownerFieldModalError" hidden></div>

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('ownerFieldModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                    id="ownerFieldModalSave"
                >
                    Add Field
                </button>

            </div>

        </form>

    </div>

</div>

<!-- =====================================================
     ADD MODAL
===================================================== -->

<div
    class="modal"
    id="addModal"
>

    <div class="modal-content">

        <div class="modal-header">

            <div class="modal-title">
                Add New Vehicle
            </div>

            <button
                type="button"
                class="close-modal"
                onclick="closeModal('addModal')"
            >
                ×
            </button>

        </div>

        <form
            method="POST"
            action="registered.php"
        >

            <input
                type="hidden"
                name="action"
                value="add"
            >

            <div class="form-group">

                <label class="form-label">
                    Brand
                </label>

                <input
                    type="text"
                    name="brand"
                    class="form-input"
                    placeholder="Example: Toyota"
                    required
                >

            </div>

            <div class="form-group">

                <label class="form-label">
                    Plate Number
                </label>

                <input
                    type="text"
                    name="plate_number"
                    class="form-input"
                    placeholder="Example: ABC-1234"
                    required
                >

            </div>

            <div class="form-group">

                <label class="form-label">
                    Model
                </label>

                <input
                    type="text"
                    name="model"
                    class="form-input"
                    placeholder="Example: Vios"
                    required
                >

            </div>

            <div class="form-group">

                <label class="form-label">
                    Vehicle Type
                </label>

                <select
                    name="vehicle_type"
                    class="form-select"
                    required
                >

                    <option value="">
                        -- Select Vehicle Type --
                    </option>

                    <?php foreach (SECSYS_VEHICLE_TYPES as $vtOption): ?>
                    <option value="<?php echo htmlspecialchars($vtOption); ?>">
                        <?php echo htmlspecialchars($vtOption); ?>
                    </option>
                    <?php endforeach; ?>

                </select>

            </div>

            <div class="form-group">

                <label class="form-label">
                    Address
                </label>

                <input
                    type="text"
                    name="address"
                    class="form-input"
                    placeholder="Example: Block 1 Lot 2"
                    required
                >

            </div>

            <!-- Extra owner fields (added by the Administrator with "Add Field") -->
            <div class="owner-extra-fields" data-owner-fields>
                <?php foreach ($ownerFields as $ownerField): ?>
                    <div class="form-group owner-extra-group" data-field-key="<?php echo e($ownerField['key']); ?>">
                        <label class="form-label owner-extra-label">
                            <span class="owner-extra-name"><?php echo e($ownerField['label']); ?></span>
                            <span class="owner-extra-tools">
                                <button type="button" title="Rename this field" onclick="renameOwnerField('<?php echo e($ownerField['key']); ?>')">✎</button>
                                <button type="button" title="Remove this field" onclick="removeOwnerField('<?php echo e($ownerField['key']); ?>')">✕</button>
                            </span>
                        </label>
                        <input type="text" name="extra[<?php echo e($ownerField['key']); ?>]" class="form-input" data-extra-key="<?php echo e($ownerField['key']); ?>" maxlength="255" placeholder="Optional">
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="owner-add-field-btn" onclick="addOwnerField(this)">
                ➕ Add Field
            </button>

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('addModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Save Vehicle
                </button>

            </div>

        </form>

    </div>

</div>

<!-- =====================================================
     UPDATE MODAL
===================================================== -->

<div
    class="modal"
    id="updateModal"
>

    <div class="modal-content">

        <div class="modal-header">

            <div class="modal-title">
                Update Registered Vehicle
            </div>

            <button
                type="button"
                class="close-modal"
                onclick="closeModal('updateModal')"
            >
                ×
            </button>

        </div>

        <form
            method="POST"
            action="registered.php"
        >

            <input
                type="hidden"
                name="action"
                value="update"
            >

            <input
                type="hidden"
                name="id"
                id="update_id"
            >

            <div class="form-group">

                <label class="form-label">
                    Brand
                </label>

                <input
                    type="text"
                    name="brand"
                    id="update_brand"
                    class="form-input"
                    required
                >

            </div>

            <div class="form-group">

                <label class="form-label">
                    Plate Number
                </label>

                <input
                    type="text"
                    name="plate_number"
                    id="update_plate_number"
                    class="form-input"
                    required
                >

            </div>

            <div class="form-group">

                <label class="form-label">
                    Model
                </label>

                <input
                    type="text"
                    name="model"
                    id="update_model"
                    class="form-input"
                    required
                >

            </div>

            <div class="form-group">

                <label class="form-label">
                    Vehicle Type
                </label>

                <select
                    name="vehicle_type"
                    id="update_vehicle_type"
                    class="form-select"
                    required
                >

                    <?php foreach (SECSYS_VEHICLE_TYPES as $vtOption): ?>
                    <option value="<?php echo htmlspecialchars($vtOption); ?>">
                        <?php echo htmlspecialchars($vtOption); ?>
                    </option>
                    <?php endforeach; ?>

                </select>

            </div>

            <div class="form-group">

                <label class="form-label">
                    Address
                </label>

                <input
                    type="text"
                    name="address"
                    id="update_address"
                    class="form-input"
                    required
                >

            </div>

            <!-- Extra owner fields (added by the Administrator with "Add Field") -->
            <div class="owner-extra-fields" data-owner-fields>
                <?php foreach ($ownerFields as $ownerField): ?>
                    <div class="form-group owner-extra-group" data-field-key="<?php echo e($ownerField['key']); ?>">
                        <label class="form-label owner-extra-label">
                            <span class="owner-extra-name"><?php echo e($ownerField['label']); ?></span>
                            <span class="owner-extra-tools">
                                <button type="button" title="Rename this field" onclick="renameOwnerField('<?php echo e($ownerField['key']); ?>')">✎</button>
                                <button type="button" title="Remove this field" onclick="removeOwnerField('<?php echo e($ownerField['key']); ?>')">✕</button>
                            </span>
                        </label>
                        <input type="text" name="extra[<?php echo e($ownerField['key']); ?>]" class="form-input" data-extra-key="<?php echo e($ownerField['key']); ?>" maxlength="255" placeholder="Optional">
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="owner-add-field-btn" onclick="addOwnerField(this)">
                ➕ Add Field
            </button>

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('updateModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Save Changes
                </button>

            </div>

        </form>

    </div>

</div>

<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

/* =========================================================
   MODAL
========================================================= */

function openAddModal()
{
    document
        .getElementById("addModal")
        .classList
        .add("show");
}

function closeModal(id)
{
    document
        .getElementById(id)
        .classList
        .remove("show");
}

/* =========================================================
   CLOSE OUTSIDE
========================================================= */

window.addEventListener(
    "click",
    function(event)
    {
        if (
            event.target.classList.contains("modal")
        ) {
            if (event.target.id === "archivePanel") {
                closeArchive();
            } else {
                event.target.classList.remove("show");
            }
        }
    }
);

document.addEventListener(
    "keydown",
    function(event)
    {
        if (event.key === "Escape") {
            closeArchive();
        }
    }
);

/* =========================================================
   VEHICLE MANAGEMENT
========================================================= */

function openVehicleManager(group)
{
    const modal =
        document.getElementById(
            "vehicleManagerModal"
        );

    const owner =
        document.getElementById(
            "managerOwner"
        );

    const list =
        document.getElementById(
            "managementVehicleList"
        );

    owner.textContent =
        group.address;

    list.innerHTML = "";

    group.vehicles.forEach(
        function(vehicle, index)
        {

            const item =
                document.createElement("div");

            item.className =
                "management-vehicle";

            item.innerHTML = `

                <div class="management-top">

                    <div>

                        <div class="management-number">

                            Vehicle ${index + 1}

                        </div>

                        <div class="management-title">

                            ${escapeHTML(
                                vehicle.brand
                            )}

                            ${escapeHTML(
                                vehicle.model
                            )}

                        </div>

                        <div class="management-plate">

                            <a href="vehicle_photo.php?plate=${encodeURIComponent(vehicle.plate_number || '')}" class="secsys-plate-link" data-plate="${escapeHTML(vehicle.plate_number)}" onclick="return window.secsysOpenPlateCaptureModal ? window.secsysOpenPlateCaptureModal(event, this) : true;" title="Click to view YOLOv10 captured license plate &amp; vehicle image">${escapeHTML(
                                vehicle.plate_number
                            )}</a>

                        </div>

                    </div>

                </div>

                <div class="management-details">

                    <div class="management-detail">

                        <strong>Brand:</strong>

                        ${escapeHTML(
                            vehicle.brand
                        )}

                    </div>

                    <div class="management-detail">

                        <strong>Model:</strong>

                        ${escapeHTML(
                            vehicle.model
                        )}

                    </div>

                    <div class="management-detail">

                        <strong>Vehicle Type:</strong>

                        ${escapeHTML(
                            vehicle.vehicle_type
                        )}

                    </div>

                    <div class="management-detail">

                        <strong>Address:</strong>

                        ${escapeHTML(
                            vehicle.address
                        )}

                    </div>

                    ${ownerExtraDetailsHtml(vehicle)}

                </div>

                <div class="management-actions">

                    <button
                        type="button"
                        class="btn btn-primary small-action"
                        onclick='editVehicle(${JSON.stringify(vehicle)})'
                    >
                        Edit
                    </button>

                    <button
                        type="button"
                        class="btn btn-danger small-action"
                        onclick="archiveSingleVehicle(${vehicle.id})"
                    >
                        Archive
                    </button>

                </div>

            `;

            list.appendChild(item);

        }
    );

    modal.classList.add("show");
}

/* =========================================================
   SINGLE VEHICLE VIEW
========================================================= */

function openSingleVehicle(vehicle)
{
    openVehicleManager({
        address:
            vehicle.address,

        vehicles: [
            vehicle
        ]
    });
}

/* =========================================================
   EDIT VEHICLE
========================================================= */

function editVehicle(vehicle)
{
    closeModal(
        "vehicleManagerModal"
    );

    document.getElementById(
        "update_id"
    ).value =
        vehicle.id;

    document.getElementById(
        "update_brand"
    ).value =
        vehicle.brand;

    document.getElementById(
        "update_plate_number"
    ).value =
        vehicle.plate_number;

    document.getElementById(
        "update_model"
    ).value =
        vehicle.model;

    document.getElementById(
        "update_address"
    ).value =
        vehicle.address;

    document.getElementById(
        "update_vehicle_type"
    ).value =
        vehicle.vehicle_type;

    // Extra owner fields
    document.querySelectorAll("#updateModal [data-extra-key]").forEach(function (input) {
        const key = input.getAttribute("data-extra-key");
        input.value = (vehicle.extra && vehicle.extra[key]) ? vehicle.extra[key] : "";
    });

    document
        .getElementById(
            "updateModal"
        )
        .classList
        .add("show");
}

/* =========================================================
   UPDATE BUTTON
========================================================= */

function openUpdateModal()
{
    const selected =
        document.querySelectorAll(
            ".owner-checkbox:checked"
        );

    if (selected.length !== 1) {

        alert(
            "Please select exactly one owner to update."
        );

        return;
    }

    try {

        const group =
            JSON.parse(
                selected[0]
                    .getAttribute(
                        "data-group"
                    )
            );

        if (
            group.vehicles.length > 1
        ) {

            alert(
                "This owner has multiple vehicles. " +
                "Please click View More and choose " +
                "which vehicle you want to edit."
            );

            return;
        }

        editVehicle(
            group.vehicles[0]
        );

    } catch (error) {

        console.error(error);

        alert(
            "Unable to load vehicle information."
        );
    }
}

/* =========================================================
   SELECT ALL
========================================================= */

const selectAll =
    document.getElementById(
        "selectAll"
    );

// The "select all" box is hidden in the table header; without this check the
// rest of the page script stopped here and nothing after it worked.
if (selectAll) selectAll.addEventListener(
    "change",
    function()
    {

        const checkboxes =
            document.querySelectorAll(
                ".owner-checkbox"
            );

        checkboxes.forEach(
            function(checkbox)
            {

                checkbox.checked =
                    selectAll.checked;

            }
        );

        updateSelectedCount();

    }
);

/* =========================================================
   INDIVIDUAL CHECKBOX
========================================================= */

document
    .querySelectorAll(
        ".owner-checkbox"
    )
    .forEach(
        function(checkbox)
        {

            checkbox.addEventListener(
                "change",
                function()
                {

                    updateSelectedCount();

                    updateSelectAll();

                }
            );

        }
    );

/* =========================================================
   COUNT
========================================================= */

function updateSelectedCount()
{
    const selected =
        document.querySelectorAll(
            ".owner-checkbox:checked"
        );

    document.getElementById(
        "selectedCount"
    ).textContent =
        selected.length;
}

/* =========================================================
   SELECT ALL STATE
========================================================= */

function updateSelectAll()
{
    if (!selectAll) {
        return;
    }

    const checkboxes =
        document.querySelectorAll(
            ".owner-checkbox"
        );

    const checked =
        document.querySelectorAll(
            ".owner-checkbox:checked"
        );

    if (checkboxes.length === 0) {

        selectAll.checked =
            false;

        selectAll.indeterminate =
            false;

        return;
    }

    selectAll.checked =
        checked.length ===
        checkboxes.length;

    selectAll.indeterminate =
        checked.length > 0 &&
        checked.length <
        checkboxes.length;
}

/* =========================================================
   ARCHIVE SINGLE
========================================================= */

function archiveSingleVehicle(id)
{
    const confirmed =
        confirm(
            "Are you sure you want to archive this vehicle?"
        );

    if (!confirmed) {
        return;
    }

    const form =
        document.createElement("form");

    form.method = "POST";
    form.action = "registered.php";

    const action =
        document.createElement("input");

    action.type = "hidden";
    action.name = "action";
    action.value = "archive_single";

    form.appendChild(action);

    const vehicleId =
        document.createElement("input");

    vehicleId.type = "hidden";
    vehicleId.name = "vehicle_id";
    vehicleId.value = id;

    form.appendChild(vehicleId);

    document.body.appendChild(form);

    form.submit();
}

/* =========================================================
   ARCHIVE SELECTED OWNERS
========================================================= */

function archiveSelected()
{
    const selected =
        document.querySelectorAll(
            ".owner-checkbox:checked"
        );

    if (selected.length === 0) {

        alert(
            "Please select at least one owner to archive."
        );

        return;
    }

    const confirmed =
        confirm(
            "Are you sure you want to archive all vehicles " +
            "belonging to the " +
            selected.length +
            " selected owner(s)?"
        );

    if (!confirmed) {
        return;
    }

    const form =
        document.createElement("form");

    form.method = "POST";
    form.action = "registered.php";

    const action =
        document.createElement("input");

    action.type = "hidden";
    action.name = "action";
    action.value = "archive_selected";

    form.appendChild(action);

    selected.forEach(
        function(checkbox)
        {

            const input =
                document.createElement("input");

            input.type = "hidden";

            input.name =
                "owner_groups[]";

            input.value =
                checkbox.getAttribute(
                    "data-group"
                );

            form.appendChild(input);

        }
    );

    document.body.appendChild(form);

    form.submit();
}

/* =========================================================
   ARCHIVE MODAL
========================================================= */

function toggleArchive()
{
    const panel =
        document.getElementById(
            "archivePanel"
        );

    panel.classList.add(
        "show"
    );
}

function closeArchive()
{
    const panel =
        document.getElementById(
            "archivePanel"
        );

    if (panel) {
        panel.classList.remove(
            "show"
        );
    }

    if (window.history && window.history.replaceState && window.location.search.includes("show_archive=1")) {
        const url = new URL(window.location.href);
        url.searchParams.delete("show_archive");
        url.searchParams.delete("archive_page");
        window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ""));
    }
}

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
   LOAD ARCHIVE FILTER (AJAX IN-PLACE — NO PAGE JUMP)
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
        "registered.php?show_archive=1" +
        "&archive_basis=" + encodeURIComponent(basis) +
        "&archive_date=" + encodeURIComponent(date) +
        "&archive_month=" + encodeURIComponent(month) +
        "&archive_q=" + encodeURIComponent(window.ArchiveSearch ? ArchiveSearch.value("archiveSearch") : "") + "&archive_page=" + encodeURIComponent(pageNum);

    fetch(url, { credentials: "same-origin" })
        .then(function(response) {
            return response.text();
        })
        .then(function(html) {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, "text/html");
            const newPanel = doc.getElementById("archivePanel");
            const currentPanel = document.getElementById("archivePanel");

            if (newPanel && currentPanel) {
                if (window.ArchiveSearch) ArchiveSearch.capture();
currentPanel.innerHTML = newPanel.innerHTML;
if (window.ArchiveSearch) ArchiveSearch.restore();
                currentPanel.classList.add("show");
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
    const links = document.querySelectorAll("#archivePanel .pagination .page-link");
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
   PRINT CURRENT
========================================================= */

function printCurrentVehicles()
{
    /* All vehicles matching the current search / Daily-Monthly filter */
    const groups = <?php
        $printGroups = [];
        foreach ($owner_groups as $group) {
            $printGroups[] = [
                'address'  => (string)$group['address'],
                'extra'    => array_map(function ($f) use ($group) {
                    return secsysOwnerGroupValue($group['vehicles'], $f['key']);
                }, $ownerFields),
                'vehicles' => array_map(function ($v) {
                    return [
                        'vehicle' => trim(($v['brand'] ?? '') . ' ' . ($v['model'] ?? '')),
                        'plate'   => (string)($v['plate_number'] ?? ''),
                        'type'    => (string)($v['vehicle_type'] ?? ''),
                        'date'    => !empty($v['date_created']) ? date('M d, Y', strtotime($v['date_created'])) : '-',
                    ];
                }, $group['vehicles']),
            ];
        }
        echo json_encode($printGroups, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>;

    if (!window.SecsysPrint) { window.print(); return; }
    const c = function (text) { return { text: text, cls: "center" }; };

    const rows = [];
    groups.forEach(function (group) {
        group.vehicles.forEach(function (v) {
            rows.push([c(rows.length + 1), group.address].concat(group.extra.map(function (x) { return x || "-"; }), [v.vehicle, c(v.plate), c(v.type), c(v.date)]));
        });
    });

    const meta = ["Showing: " + <?php echo json_encode($listPeriod['label']); ?>];
    <?php if ($search !== ""): ?>
    meta.push("Search: " + <?php echo json_encode($search); ?>);
    <?php endif; ?>
    meta.push("Total Owners / Addresses: " + groups.length + "  |  Total Vehicles: " + rows.length);

    SecsysPrint.print({
        title: "Registered Vehicle Report",
        meta: meta,
        body: SecsysPrint.table(
            [["#", 5], ["Address", 25]].concat(OWNER_FIELDS.map(function (f) { return [f.label, 14]; }), [["Vehicle", 22], ["Plate Number", 16], ["Vehicle Type", 14], ["Date Registered", 18]]),
            rows,
            "No registered vehicles found."
        ),
        footer: "Registered Vehicle Monitoring Report"
    });
}

/* =========================================================
   DOWNLOAD CURRENT REGISTERED VEHICLES PDF
========================================================= */

function downloadCurrentVehicles(btn)
{
    const search =
        <?php
        echo json_encode(
            $search
        );
        ?>;

    const page =
        <?php
        echo (int)$current_page;
        ?>;

    let url =
        "registered.php?download=registered";

    const periodQuery =
        <?php echo json_encode(secsysPeriodQuery($listPeriod)); ?>;

    if (periodQuery !== "") {
        url += "&" + periodQuery;
    }

    if (search !== "") {

        url +=
            "&search=" +
            encodeURIComponent(
                search
            );
    }

    url +=
        "&page=" +
        encodeURIComponent(
            page
        );

    SecsysDownload.choose(url, btn);   // pick PDF or CSV
}

/* =========================================================
   PRINT ARCHIVE
========================================================= */

function printArchiveVehicles()
{
    if (!window.SecsysPrint) { window.print(); return; }
    const c = function (text) { return { text: text, cls: "center" }; };

    const rows = [];
    document.querySelectorAll("#archivePanel .vehicle-table tbody tr").forEach(function (row) {
        const cells = row.querySelectorAll("td");
        if (cells.length < 5) return;      // "No archived vehicles" row
        // first cell = "Brand Model" + plate on a second line -> keep the vehicle name only
        const vehicle = (cells[0].childNodes[0] ? cells[0].childNodes[0].textContent : cells[0].innerText)
            .replace(/\s+/g, " ").trim();
        rows.push([
            c(rows.length + 1),
            vehicle,
            c(cells[1].innerText.trim()),
            c(cells[2].innerText.trim()),
            cells[3].innerText.trim(),
            c(cells[4].innerText.trim())
        ]);
    });

    const subheading = document.querySelector("#archivePanel .archive-subheading strong");
    const period = subheading ? subheading.innerText.trim() : <?php echo json_encode($archive_display_label); ?>;
    const meta = ["Archive Period: " + period];
    const q = window.ArchiveSearch ? ArchiveSearch.value("archiveSearch") : "";
    if (q !== "") meta.push("Search: " + q);
    meta.push("Records on this page: " + rows.length);

    SecsysPrint.print({
        title: "Registered Vehicle Archive Report",
        meta: meta,
        body: SecsysPrint.table(
            [["#", 5], ["Vehicle", 22], ["Plate Number", 15], ["Vehicle Type", 13], ["Address", 25], ["Date Archived", 20]],
            rows,
            "No archived vehicles found."
        ),
        footer: "Registered Vehicle Archive Report"
    });
}

/* =========================================================
   DOWNLOAD ARCHIVE VEHICLES PDF
========================================================= */

function downloadArchiveVehicles(btn)
{
    const basis =
        document.getElementById("archiveBasis")
        ? document.getElementById("archiveBasis").value
        : "daily";

    const selectedDate =
        document.getElementById(
            "archiveDate"
        ).value;

    const selectedMonth =
        document.getElementById("archiveMonth")
        ? document.getElementById("archiveMonth").value
        : "";

    const url =
        "registered.php?download=archive" + "&archive_q=" + encodeURIComponent(window.ArchiveSearch ? ArchiveSearch.value("archiveSearch") : "") + 
        "&archive_basis=" +
        encodeURIComponent(basis) +
        "&archive_date=" +
        encodeURIComponent(
            selectedDate
        ) +
        "&archive_month=" +
        encodeURIComponent(
            selectedMonth
        ) +
        "&archive_page=" +
        <?php
        echo (int)$archive_page;
        ?>;

    SecsysDownload.choose(url, btn);   // pick PDF or CSV
}

/* =========================================================
   PRINT WINDOW
========================================================= */

function openPrintWindow(
    title,
    report
)
{
    const printWindow =
        window.open(
            "",
            "_blank",
            "width=900,height=700"
        );

    if (!printWindow) {

        alert(
            "Please allow pop-ups to print the report."
        );

        return;
    }

    printWindow.document.write(`

        <!DOCTYPE html>

        <html>

        <head>

            <title>
                ${escapeHTML(title)}
            </title>

            <style>

                body {

                    font-family:
                        Arial,
                        Helvetica,
                        sans-serif;

                    margin: 45px;

                    color: #111;

                }

                h1 {

                    font-size: 24px;

                    font-weight: 400;

                    margin-bottom: 5px;

                }

                .date {

                    color: #666;

                    font-size: 13px;

                    margin-bottom: 30px;

                }

                .vehicle-item {

                    border-bottom:
                        1px solid #ddd;

                    padding:
                        12px 0;

                    line-height: 1.6;

                    font-size: 14px;

                }

                .report-footer {

                    margin-top: 40px;

                    border-top:
                        1px solid #ddd;

                    padding-top: 15px;

                    font-size: 12px;

                    color: #666;

                }

                @media print {

                    body {
                        margin: 25px;
                    }

                }

            </style>

        </head>

        <body>

            <h1>
                ${escapeHTML(title)}
            </h1>

            <div class="date">

                Generated:
                ${new Date().toLocaleString()}

            </div>

            ${report}

            <div class="report-footer">

                L. Surveillance -
                Lessandra Subdivision,
                Phase 2, Salinas 1,
                Bacoor, Cavite

            </div>

        </body>

        </html>

    `);

    printWindow.document.close();

    printWindow.focus();

    setTimeout(
        function()
        {
            printWindow.print();
        },
        300
    );
}

/* =========================================================
   ESCAPE HTML
========================================================= */

function escapeHTML(value)
{
    return String(value)

        .replace(
            /&/g,
            "&amp;"
        )

        .replace(
            /</g,
            "&lt;"
        )

        .replace(
            />/g,
            "&gt;"
        )

        .replace(
            /"/g,
            "&quot;"
        )

        .replace(
            /'/g,
            "&#039;"
        );
}

/* =========================================================
   INITIALIZE
========================================================= */

updateSelectedCount();

updateSelectAll();

/* =========================================================
   ASYNC AJAX ADD & UPDATE MODAL SUBMISSIONS
   Ensures newly added plate numbers appear immediately without
   full page reloads or form resubmission prompts.
========================================================= */
/* =========================================================
   EXTRA OWNER FIELDS ("Add Field" in the owner forms)
========================================================= */
let OWNER_FIELDS = <?php echo json_encode($ownerFields, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;

function ownerExtraDetailsHtml(vehicle)
{
    return OWNER_FIELDS.map(function (f) {
        const value = (vehicle.extra && vehicle.extra[f.key]) ? vehicle.extra[f.key] : "—";
        return '<div class="management-detail"><strong>' + escapeHTML(f.label) + ':</strong> ' + escapeHTML(value) + '</div>';
    }).join("");
}

function ownerFieldGroupHtml(field)
{
    const k = escapeHTML(field.key);
    return '<div class="form-group owner-extra-group" data-field-key="' + k + '">' +
        '<label class="form-label owner-extra-label">' +
            '<span class="owner-extra-name">' + escapeHTML(field.label) + '</span>' +
            '<span class="owner-extra-tools">' +
                '<button type="button" title="Rename this field" onclick="renameOwnerField(\'' + k + '\')">✎</button>' +
                '<button type="button" title="Remove this field" onclick="removeOwnerField(\'' + k + '\')">✕</button>' +
            '</span>' +
        '</label>' +
        '<input type="text" name="extra[' + k + ']" class="form-input" data-extra-key="' + k + '" maxlength="255" placeholder="Optional">' +
    '</div>';
}

async function ownerFieldRequest(params)
{
    const body = new URLSearchParams(params);
    const res = await fetch("registered.php", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded", "X-Requested-With": "XMLHttpRequest" },
        body: body.toString()
    });
    return res.json();
}

/* Add Field / Rename modal: { mode: "add" | "rename", key, form } */
let ownerFieldModalState = { mode: "add", key: "", form: null };

function openOwnerFieldModal(mode, key, form)
{
    ownerFieldModalState = { mode: mode, key: key || "", form: form || null };
    const field = OWNER_FIELDS.find(function (f) { return f.key === key; });
    const input = document.getElementById("ownerFieldLabelInput");
    const isRename = mode === "rename";

    document.getElementById("ownerFieldModalTitle").textContent = isRename ? "Rename Field" : "Add New Field";
    document.getElementById("ownerFieldModalHint").textContent = isRename
        ? 'Rename "' + (field ? field.label : "") + '". What was already entered for every owner stays the same.'
        : "The new field is added to the owner form for every owner and shows as a column in the Registered list.";
    document.getElementById("ownerFieldModalSave").textContent = isRename ? "Save Name" : "Add Field";
    document.getElementById("ownerFieldSuggestions").hidden = isRename;
    showOwnerFieldError("");
    input.value = isRename && field ? field.label : "";

    document.getElementById("ownerFieldModal").classList.add("show");
    setTimeout(function () { input.focus(); input.select(); }, 60);
}

function showOwnerFieldError(message)
{
    const box = document.getElementById("ownerFieldModalError");
    box.textContent = message || "";
    box.hidden = !message;
}

function pickOwnerFieldSuggestion(btn)
{
    const input = document.getElementById("ownerFieldLabelInput");
    input.value = btn.textContent.trim();
    showOwnerFieldError("");
    input.focus();
}

// Typing a new name clears the last error
document.addEventListener("input", function (event) {
    if (event.target && event.target.id === "ownerFieldLabelInput") {
        showOwnerFieldError("");
    }
});

async function submitOwnerFieldModal(event)
{
    event.preventDefault();
    const label = document.getElementById("ownerFieldLabelInput").value.trim();
    const saveBtn = document.getElementById("ownerFieldModalSave");
    const state = ownerFieldModalState;

    if (!label) {
        showOwnerFieldError("Please enter a name for the field.");
        return false;
    }

    saveBtn.disabled = true;
    const original = saveBtn.textContent;
    saveBtn.textContent = state.mode === "rename" ? "Saving..." : "Adding...";

    try {
        if (state.mode === "rename") {
            const data = await ownerFieldRequest({ action: "owner_field_rename", key: state.key, label: label });
            if (!data || !data.success) {
                showOwnerFieldError((data && data.message) || "Could not rename the field.");
                return false;
            }
            const field = OWNER_FIELDS.find(function (f) { return f.key === state.key; });
            if (field) field.label = label;
            document.querySelectorAll('[data-field-key="' + state.key + '"] .owner-extra-name').forEach(function (el) {
                el.textContent = label;
            });
        } else {
            const data = await ownerFieldRequest({ action: "owner_field_add", label: label });
            if (!data || !data.success) {
                showOwnerFieldError((data && data.message) || "Could not add the field.");
                return false;
            }
            const field = data.field;
            if (!OWNER_FIELDS.some(function (f) { return f.key === field.key; })) {
                OWNER_FIELDS.push(field);
            }
            // Add the input to both owner forms (Add New Owner and Update)
            document.querySelectorAll("[data-owner-fields]").forEach(function (box) {
                if (!box.querySelector('[data-field-key="' + field.key + '"]')) {
                    box.insertAdjacentHTML("beforeend", ownerFieldGroupHtml(field));
                }
            });
            closeModal("ownerFieldModal");
            const newInput = state.form ? state.form.querySelector('[data-extra-key="' + field.key + '"]') : null;
            if (newInput) newInput.focus();
            return false;
        }
        closeModal("ownerFieldModal");
    } catch (e) {
        showOwnerFieldError("Could not save. Please check the connection and try again.");
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = original;
    }
    return false;
}

function addOwnerField(btn)
{
    openOwnerFieldModal("add", "", btn ? btn.closest("form") : null);
}

// Esc closes the Add / Rename Field window first (the owner form underneath stays open)
document.addEventListener("keydown", function (event) {
    const modal = document.getElementById("ownerFieldModal");
    if (event.key === "Escape" && modal && modal.classList.contains("show")) {
        event.stopImmediatePropagation();
        closeModal("ownerFieldModal");
    }
}, true);

function renameOwnerField(key)
{
    openOwnerFieldModal("rename", key, null);
}

async function removeOwnerField(key)
{
    const field = OWNER_FIELDS.find(function (f) { return f.key === key; });
    if (!confirm('Remove the field "' + (field ? field.label : "") + '" from the owner form and list?\n\nWhat was already entered is kept; adding a field with the same name brings it back.')) return;
    const data = await ownerFieldRequest({ action: "owner_field_remove", key: key });
    if (!data || !data.success) {
        alert((data && data.message) || "Could not remove the field.");
        return;
    }
    OWNER_FIELDS = OWNER_FIELDS.filter(function (f) { return f.key !== key; });
    document.querySelectorAll('[data-field-key="' + key + '"]').forEach(function (el) { el.remove(); });
}

document.addEventListener("DOMContentLoaded", function() {
    const addForm = document.querySelector("#addModal form");
    if (addForm) {
        addForm.addEventListener("submit", async function(e) {
            e.preventDefault();
            const submitBtn = addForm.querySelector('button[type="submit"]');
            const orig = submitBtn ? submitBtn.textContent : "Save Vehicle";
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = "Saving...";
            }
            try {
                const formData = new FormData(addForm);
                formData.append("ajax", "1");
                const res = await fetch("registered.php", {
                    method: "POST",
                    body: formData,
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                });
                const data = await res.json();
                if (data.success) {
                    closeModal("addModal");
                    addForm.reset();
                    window.location.href = "registered.php?msg=" + encodeURIComponent(data.message) + "&msg_type=success&highlight=" + encodeURIComponent(data.vehicle.plate_number);
                } else {
                    alert(data.message || "Failed to add vehicle.");
                }
            } catch (err) {
                addForm.submit();
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = orig;
                }
            }
        });
    }

    const updateForm = document.querySelector("#updateModal form");
    if (updateForm) {
        updateForm.addEventListener("submit", async function(e) {
            e.preventDefault();
            const submitBtn = updateForm.querySelector('button[type="submit"]');
            const orig = submitBtn ? submitBtn.textContent : "Save Changes";
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = "Updating...";
            }
            try {
                const formData = new FormData(updateForm);
                formData.append("ajax", "1");
                const res = await fetch("registered.php", {
                    method: "POST",
                    body: formData,
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                });
                const data = await res.json();
                if (data.success) {
                    closeModal("updateModal");
                    window.location.href = "registered.php?msg=" + encodeURIComponent(data.message) + "&msg_type=success&highlight=" + encodeURIComponent(data.vehicle.plate_number);
                } else {
                    alert(data.message || "Failed to update vehicle.");
                }
            } catch (err) {
                updateForm.submit();
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = orig;
                }
            }
        });
    }
});

</script>

<style>
.owner-extra-label { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.owner-extra-tools { display: inline-flex; gap: 4px; }
.owner-extra-tools button {
    border: 1px solid #cbd5e1; background: #fff; color: #475569; border-radius: 6px;
    width: 24px; height: 22px; font-size: 11px; line-height: 1; cursor: pointer; padding: 0;
}
.owner-extra-tools button:hover { background: #f1f5f9; color: #0f172a; }
.owner-add-field-btn {
    display: inline-flex; align-items: center; gap: 6px; margin: 2px 0 14px; padding: 7px 14px;
    border: 1px dashed #60a5fa; background: #eff6ff; color: #1d4ed8; border-radius: 8px;
    font-size: 12.5px; font-weight: 700; cursor: pointer;
}
.owner-add-field-btn:hover { background: #dbeafe; }
.owner-field-modal-content { max-width: 440px; }
.owner-field-hint { font-size: 12.5px; color: #475569; line-height: 1.45; margin-bottom: 12px; }
.owner-field-suggestions { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: -4px 0 12px; font-size: 12px; color: #64748b; }
.owner-field-suggestions button {
    border: 1px solid #cbd5e1; background: #f8fafc; color: #0f172a; border-radius: 999px;
    padding: 3px 10px; font-size: 11.5px; cursor: pointer;
}
.owner-field-suggestions button:hover { background: #e0f2fe; border-color: #7dd3fc; }
.owner-field-error {
    background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px;
    padding: 8px 10px; font-size: 12.5px; margin-bottom: 10px;
}
</style>

<!-- Live auto-refresh (no full page reload) -->
<script>window.AUTO_REFRESH_SECONDS = 5;</script>
<script src="assets/js/download_format.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/download_format.js'); ?>"></script>
<script src="assets/js/auto_refresh.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/auto_refresh.js'); ?>"></script>

<?php echo secsysYoloCaptureModalAssetsHtml(); /* clickable plate styles + viewer, also for plates added live */ ?>
<script src="assets/js/logout_confirm.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/logout_confirm.js'); ?>"></script>
</body>

</html>

<?php

$conn->close();

?>