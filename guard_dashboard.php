<?php
require_once __DIR__ . '/app/session_helper.php';
require_once __DIR__ . '/app/system_config.php';
secsysStartRoleSession('Security Guard');

/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION['username'])) {

    /* The guard session is empty. If the Administrator is
       logged in on this browser, send them back to their
       dashboard instead of treating them as a guard. */
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

if (empty($_SESSION['guard_id'])) {
    header("Location: logout.php?role=Security%20Guard");
    exit();
}


/* =========================================================
   DATABASE CONNECTION
   Database: security_system
========================================================= */

$conn = secsysConnectDb();

/* =========================================================
   TIME FOR ACTIVITY ROWS (alias: vl)
   Each row shows only its own time:
   - IN row:  time in  (its visit record, else when it was logged)
   - OUT row: time out (when the exit was logged)
   Returned pre-formatted, e.g. "6:06 AM".
========================================================= */
$activityTimeColumns = "
        CASE WHEN vl.direction = 'IN' THEN DATE_FORMAT(
            COALESCE((
                SELECT vld_own.time_in
                FROM vehicle_log_dates vld_own
                WHERE vld_own.vehicle_log_id = vl.id
                ORDER BY vld_own.id DESC
                LIMIT 1
            ), vl.date_created),
            '%l:%i %p'
        ) END AS time_in_label,
        CASE WHEN vl.direction = 'OUT' THEN DATE_FORMAT(vl.date_created, '%l:%i %p') END AS time_out_label
";

/** Time shown on an activity row: "In 6:34 AM" on IN rows, "Out 6:35 AM" on OUT rows. */
function guardActivityTimesHtml($direction, $timeInLabel, $timeOutLabel): string
{
    $isOut = strtoupper(trim((string)$direction)) === 'OUT';
    $label = $isOut ? 'Out' : 'In';
    $time  = trim((string)($isOut ? $timeOutLabel : $timeInLabel));
    $time  = $time !== '' ? $time : '—';

    return '<div class="activity-times" title="Time ' . $label . ': ' . htmlspecialchars($time, ENT_QUOTES) . '">'
        . '<span class="activity-time"><b>' . $label . '</b> <span class="time-value">' . htmlspecialchars($time) . '</span></span>'
        . '</div>';
}

/* =========================================================
   FAST-PATH AJAX DISPATCHER
   Bypasses heavy dashboard computations, schema checks, and
   releases PHP session locks immediately for instant response.
========================================================= */

$ajaxAction = $_POST['action'] ?? $_GET['action'] ?? '';

if ($ajaxAction !== '') {

    $guardID = trim($_SESSION['guard_id'] ?? '');
    $partnerGuardID = trim($_SESSION['partner_guard_id'] ?? '');
    // Release PHP session lock immediately so parallel requests never wait/block
    session_write_close();

    $today = date("Y-m-d");

    /* ---------------------------------------------------------
       0. ADMIN-ADDED CAMERAS (polled so new cameras appear live)
    --------------------------------------------------------- */
    if ($ajaxAction === 'extra_cameras') {
        header("Content-Type: application/json; charset=utf-8");
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        echo json_encode([
            'success' => true,
            'cameras' => secsysLoadExtraCameras($conn),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit();
    }

    /* ---------------------------------------------------------
       1. SAVE VISITOR INFORMATION (INSTANT SAVE)
    --------------------------------------------------------- */
    if ($ajaxAction === 'save_visitor_info' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header("Content-Type: application/json; charset=utf-8");

        $logId = (int)($_POST['log_id'] ?? 0);
        $pendingId = trim((string)($_POST['pending_id'] ?? ''));
        $rawPlate = strtoupper(trim($_POST['plate_number'] ?? ''));
        $plateNormalized = preg_replace('/[^A-Z0-9 ]/', '', $rawPlate);
        $plateClean = trim(preg_replace('/\s+/', ' ', $plateNormalized));
        $plateNoSpaces = str_replace(' ', '', $plateClean);
        $vehicleType = trim($_POST['vehicle_type'] ?? 'Car');
        $vehicleType = secsysNormalizeVehicleType($vehicleType);
        $purposeRaw = trim($_POST['purpose'] ?? '');
        $customMinutes = isset($_POST['custom_minutes']) && (int)$_POST['custom_minutes'] > 0
            ? max(1, min(2880, (int)$_POST['custom_minutes']))
            : null;
        $customPurposeText = trim($_POST['custom_purpose'] ?? '');

        if ($purposeRaw === '__CUSTOM__') {
            if ($customMinutes === null || $customMinutes <= 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Please enter a valid custom stay duration (in minutes or hours).'
                ]);
                $conn->close();
                exit();
            }
            $baseLabel = $customPurposeText !== '' ? $customPurposeText : 'Custom Visit';
            $purpose = $baseLabel . ' - ' . ucwords(secsysFormatDuration($customMinutes));
        } else {
            $purpose = $purposeRaw;
        }

        // ID Consent inputs
        $hasConsent = !empty($_POST['visitor_consent']) && ($_POST['visitor_consent'] === '1' || $_POST['visitor_consent'] === 1 || $_POST['visitor_consent'] === 'true');
        $idType = $hasConsent ? trim((string)($_POST['id_type'] ?? '')) : null;
        $idNumber = $hasConsent ? trim((string)($_POST['id_number'] ?? '')) : null;
        $visitorName = $hasConsent ? trim((string)($_POST['visitor_name'] ?? '')) : '';
        $addressToVisit = $hasConsent ? trim((string)($_POST['address_to_visit'] ?? '')) : '';

        $saveVisitorName = ($visitorName !== '') ? $visitorName : 'Visitor';
        $saveAddress = ($addressToVisit !== '') ? $addressToVisit : '-';
        $saveIdType = ($idType !== null && $idType !== '') ? $idType : null;
        $saveIdNumber = ($idNumber !== null && $idNumber !== '') ? $idNumber : null;

        if ($hasConsent) {
            if ($saveIdType === null || $saveIdNumber === null || $visitorName === '') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Please provide the ID Type, ID Number, and Name since the visitor consented to present ID.'
                ]);
                $conn->close();
                exit();
            }
        }

        if ($plateClean === '' || $purpose === '') {
            echo json_encode([
                'success' => false,
                'message' => 'Please enter the Plate Number, Vehicle Type, and Purpose.'
            ]);
            $conn->close();
            exit();
        }

        // Resolve duration & tailored reminder milestones via shared config
        $resolvedDuration = secsysResolveVisitorDuration($purpose, $customMinutes);
        $allowedMinutes = max(1, (int)$resolvedDuration['allowed_minutes']);
        $purpose = $resolvedDuration['purpose_display'];

        // Step 1: Upsert into visitor_vehicles for today using indexed lookup
        $visitorVehicleId = null;
        $visitorPrevStatus = 'IN';
        $findVv = $conn->prepare("
            SELECT id, status
            FROM visitor_vehicles
            WHERE (plate_number = ? OR REPLACE(UPPER(plate_number), ' ', '') = ?)
              AND (date_created >= CURDATE() OR date_in >= CURDATE() OR time_in >= CURDATE())
            ORDER BY id DESC
            LIMIT 1
        ");
        if ($findVv) {
            $findVv->bind_param("ss", $plateClean, $plateNoSpaces);
            $findVv->execute();
            $resVv = $findVv->get_result();
            if ($rowVv = $resVv->fetch_assoc()) {
                $visitorVehicleId = (int)$rowVv['id'];
                $visitorPrevStatus = strtoupper(trim((string)($rowVv['status'] ?? 'IN')));
            }
            $findVv->close();
        }

        if ($visitorVehicleId) {
            if ($visitorPrevStatus === 'OUT' || $logId <= 0) {
                // New arrival or returning visitor entering again -> start fresh time_in & timer
                $updVv = $conn->prepare("
                    UPDATE visitor_vehicles
                    SET plate_number = ?,
                        vehicle_type = ?,
                        purpose = ?,
                        allowed_minutes = ?,
                        visitor_name = ?,
                        address_to_visit = ?,
                        id_type = ?,
                        id_number = ?,
                        status = 'IN',
                        date_in = CURDATE(),
                        time_in = NOW(),
                        date_out = NULL,
                        time_out = NULL,
                        expected_time_out = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                    WHERE id = ?
                ");
            } else {
                $updVv = $conn->prepare("
                    UPDATE visitor_vehicles
                    SET plate_number = ?,
                        vehicle_type = ?,
                        purpose = ?,
                        allowed_minutes = ?,
                        visitor_name = ?,
                        address_to_visit = ?,
                        id_type = ?,
                        id_number = ?,
                        status = 'IN',
                        date_in = COALESCE(date_in, CURDATE()),
                        time_in = COALESCE(time_in, NOW()),
                        date_out = NULL,
                        time_out = NULL,
                        expected_time_out = DATE_ADD(COALESCE(time_in, NOW()), INTERVAL ? MINUTE)
                    WHERE id = ?
                ");
            }
            if ($updVv) {
                $updVv->bind_param("sssissssii", $plateClean, $vehicleType, $purpose, $allowedMinutes, $saveVisitorName, $saveAddress, $saveIdType, $saveIdNumber, $allowedMinutes, $visitorVehicleId);
                $updVv->execute();
                $updVv->close();
            }
        } else {
            $insVv = $conn->prepare("
                INSERT INTO visitor_vehicles
                    (visitor_name, address_to_visit, vehicle_type, plate_number, purpose, allowed_minutes, id_type, id_number, status, date_in, time_in, expected_time_out, date_created)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, 'IN', CURDATE(), NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())
            ");
            if ($insVv) {
                $insVv->bind_param("sssssissi", $saveVisitorName, $saveAddress, $vehicleType, $plateClean, $purpose, $allowedMinutes, $saveIdType, $saveIdNumber, $allowedMinutes);
                if ($insVv->execute()) {
                    $visitorVehicleId = (int)$conn->insert_id;
                }
                $insVv->close();
            }
        }

        // Step 2: Update existing unconfirmed vehicle_logs row or insert new confirmed entry
        $direction = 'IN';
        $recMethod = ($pendingId !== '') ? 'OCR' : 'Manual';

        if ($logId <= 0) {
            $plateAlnum = preg_replace('/[^A-Z0-9]/', '', $plateClean);
            $findLog = $conn->prepare("
                SELECT vl.id, vl.direction
                FROM vehicle_logs vl
                WHERE (
                      vl.plate_number = ?
                      OR vl.plate_number = ?
                      OR REGEXP_REPLACE(UPPER(vl.plate_number), '[^A-Z0-9]', '') = ?
                )
                  AND vl.vehicle_category = 'Visitor'
                  AND vl.direction = 'IN'
                  AND vl.date_out IS NULL
                  AND vl.date_created >= CURDATE()
                  AND NOT EXISTS (
                      SELECT 1 FROM vehicle_logs vl_out
                      WHERE REGEXP_REPLACE(UPPER(vl_out.plate_number), '[^A-Z0-9]', '') = ?
                        AND vl_out.direction = 'OUT'
                        AND vl_out.id > vl.id
                  )
                ORDER BY vl.id DESC
                LIMIT 1
            ");
            if ($findLog) {
                $findLog->bind_param("ssss", $plateClean, $plateNoSpaces, $plateAlnum, $plateAlnum);
                $findLog->execute();
                $resLog = $findLog->get_result();
                if ($rowLog = $resLog->fetch_assoc()) {
                    $logId = (int)$rowLog['id'];
                    $direction = $rowLog['direction'] ?: 'IN';
                }
                $findLog->close();
            }
        }

        if ($logId > 0) {
            $updLog = $conn->prepare("
                UPDATE vehicle_logs
                SET plate_number = ?,
                    vehicle_type = ?,
                    purpose = ?,
                    allowed_minutes = ?,
                    visitor_name = ?,
                    id_type = ?,
                    id_number = ?,
                    expected_time_out = DATE_ADD(date_created, INTERVAL ? MINUTE),
                    date_in = COALESCE(date_in, DATE(date_created)),
                    visitor_vehicle_id = ?
                WHERE id = ?
            ");
            if ($updLog) {
                $updLog->bind_param("sssisssiii", $plateClean, $vehicleType, $purpose, $allowedMinutes, $saveVisitorName, $saveIdType, $saveIdNumber, $allowedMinutes, $visitorVehicleId, $logId);
                $updLog->execute();
                $updLog->close();
            }
        } else {
            $insLog = $conn->prepare("
                INSERT INTO vehicle_logs
                    (plate_number, vehicle_type, vehicle_category, visitor_vehicle_id, visitor_name, id_type, id_number, purpose, allowed_minutes, expected_time_out, direction, recognition_method, date_in, date_created)
                VALUES
                    (?, ?, 'Visitor', ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 'IN', ?, CURDATE(), NOW())
            ");
            if ($insLog) {
                $insLog->bind_param("ssissssiis", $plateClean, $vehicleType, $visitorVehicleId, $saveVisitorName, $saveIdType, $saveIdNumber, $purpose, $allowedMinutes, $allowedMinutes, $recMethod);
                if ($insLog->execute()) {
                    $logId = (int)$conn->insert_id;
                }
                $insLog->close();
            }
        }

        // Step 2b: attach the YOLOv10 vehicle photo taken when this plate was read
        // (the detection server names it after the pending id)
        $pendingPhoto = secsysPendingVehiclePhotoPath($pendingId);
        if ($logId > 0 && $pendingPhoto !== null) {
            $setPhoto = $conn->prepare("UPDATE vehicle_logs SET vehicle_image = ? WHERE id = ? AND (vehicle_image IS NULL OR vehicle_image = '')");
            if ($setPhoto) {
                $setPhoto->bind_param("si", $pendingPhoto, $logId);
                $setPhoto->execute();
                $setPhoto->close();
            }
        }

        // Step 3: Fast update or insert into vehicle_log_dates
        if ($logId > 0) {
            $checkVld = $conn->prepare("SELECT id FROM vehicle_log_dates WHERE vehicle_log_id = ? LIMIT 1");
            if ($checkVld) {
                $checkVld->bind_param("i", $logId);
                $checkVld->execute();
                $resVld = $checkVld->get_result();
                if ($rowVld = $resVld->fetch_assoc()) {
                    $vldId = (int)$rowVld['id'];
                    $updVld = $conn->prepare("
                        UPDATE vehicle_log_dates
                        SET plate_number = ?, vehicle_type = ?, purpose = ?, allowed_minutes = ?,
                            expected_time_out = DATE_ADD(COALESCE(time_in, NOW()), INTERVAL ? MINUTE)
                        WHERE id = ?
                    ");
                    if ($updVld) {
                        $updVld->bind_param("sssiii", $plateClean, $vehicleType, $purpose, $allowedMinutes, $allowedMinutes, $vldId);
                        $updVld->execute();
                        $updVld->close();
                    }
                } else {
                    $insVld = $conn->prepare("
                        INSERT INTO vehicle_log_dates
                            (vehicle_log_id, plate_number, vehicle_category, vehicle_type, purpose, allowed_minutes, date_in, time_in, expected_time_out, status)
                        VALUES
                            (?, ?, 'Visitor', ?, ?, ?, CURDATE(), NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), 'IN')
                    ");
                    if ($insVld) {
                        $insVld->bind_param("isssii", $logId, $plateClean, $vehicleType, $purpose, $allowedMinutes, $allowedMinutes);
                        $insVld->execute();
                        $insVld->close();
                    }
                }
                $checkVld->close();
            }
        }

        // Mark pending visitor queue entry as saved so it is cleared from pending prompts
        secsysResolvePendingVisitor($pendingId, $plateClean, 'saved');

        $totalVisitorsToday = 0;
        $qVisTot = $conn->query("
            SELECT COALESCE(SUM(CASE WHEN vehicle_category = 'Visitor' AND direction = 'IN' THEN 1 ELSE 0 END), 0) AS vis_tot
            FROM vehicle_logs
            WHERE date_created >= CURDATE()
        ");
        if ($qVisTot && $rowVisTot = $qVisTot->fetch_assoc()) {
            $totalVisitorsToday = (int)$rowVisTot['vis_tot'];
        }

        // Time in / time out of the saved visit, for the activity row
        $savedTimeIn = '';
        $savedTimeOut = '';
        if ($logId > 0) {
            $stmtTimes = $conn->prepare("SELECT {$activityTimeColumns} FROM vehicle_logs vl WHERE vl.id = ? LIMIT 1");
            if ($stmtTimes) {
                $stmtTimes->bind_param("i", $logId);
                $stmtTimes->execute();
                $rowTimes = $stmtTimes->get_result()->fetch_assoc();
                $savedTimeIn = (string)($rowTimes['time_in_label'] ?? '');
                $savedTimeOut = (string)($rowTimes['time_out_label'] ?? '');
                $stmtTimes->close();
            }
        }

        $timerSnapshot = secsysFetchActiveVisitorTimers($conn);

        echo json_encode(array_merge([
            'success' => true,
            'total_visitors' => $totalVisitorsToday,
            'log' => [
                'id' => $logId,
                'plate_number' => $plateClean,
                'vehicle_category' => 'Visitor',
                'vehicle_type' => $vehicleType,
                'purpose' => $purpose,
                'allowed_minutes' => $allowedMinutes,
                'direction' => $direction,
                'visitor_name' => $saveVisitorName,
                'address_to_visit' => $saveAddress,
                'id_type' => $saveIdType ?? '',
                'id_number' => $saveIdNumber ?? '',
                'time_in_label' => $savedTimeIn,
                'time_out_label' => $savedTimeOut
            ]
        ], $timerSnapshot));

        $conn->close();
        exit();
    }

    /* ---------------------------------------------------------
       1B. CANCEL VISITOR MODAL (DISCARD WITHOUT SAVING TO DB)
    --------------------------------------------------------- */
    if ($ajaxAction === 'cancel_visitor_info' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header("Content-Type: application/json; charset=utf-8");

        $pendingId = trim((string)($_POST['pending_id'] ?? ''));
        $rawPlate = strtoupper(trim((string)($_POST['plate_number'] ?? '')));
        $logId = (int)($_POST['log_id'] ?? 0);
        $isAutoDetected = (int)($_POST['is_auto_detected'] ?? 0);

        // Mark pending detection as cancelled so it is never inserted into MySQL
        secsysResolvePendingVisitor($pendingId, $rawPlate, 'cancelled');

        // Safety-net cleanup if an unconfirmed auto-detected placeholder row existed
        if ($isAutoDetected === 1 && $logId > 0) {
            $chkStmt = $conn->prepare("
                SELECT id, visitor_vehicle_id, purpose, allowed_minutes
                FROM vehicle_logs
                WHERE id = ? AND vehicle_category = 'Visitor'
                LIMIT 1
            ");
            if ($chkStmt) {
                $chkStmt->bind_param("i", $logId);
                $chkStmt->execute();
                $chkRes = $chkStmt->get_result();
                if ($chkRow = $chkRes->fetch_assoc()) {
                    $purpLower = strtolower(trim((string)($chkRow['purpose'] ?? '')));
                    $isUnconfirmed = ($chkRow['allowed_minutes'] === null)
                        && ($purpLower === '' || $purpLower === 'visitor entry' || strpos($purpLower, 'needs review:') === 0);
                    if ($isUnconfirmed) {
                        $vvId = (int)($chkRow['visitor_vehicle_id'] ?? 0);
                        $delVld = $conn->prepare("DELETE FROM vehicle_log_dates WHERE vehicle_log_id = ?");
                        if ($delVld) {
                            $delVld->bind_param("i", $logId);
                            $delVld->execute();
                            $delVld->close();
                        }
                        $delVl = $conn->prepare("DELETE FROM vehicle_logs WHERE id = ?");
                        if ($delVl) {
                            $delVl->bind_param("i", $logId);
                            $delVl->execute();
                            $delVl->close();
                        }
                        if ($vvId > 0) {
                            $cntVv = $conn->prepare("SELECT COUNT(*) AS cnt FROM vehicle_logs WHERE visitor_vehicle_id = ?");
                            if ($cntVv) {
                                $cntVv->bind_param("i", $vvId);
                                $cntVv->execute();
                                $cntRow = $cntVv->get_result()->fetch_assoc();
                                $cntVv->close();
                                if ((int)($cntRow['cnt'] ?? 0) === 0) {
                                    $delVv = $conn->prepare("DELETE FROM visitor_vehicles WHERE id = ?");
                                    if ($delVv) {
                                        $delVv->bind_param("i", $vvId);
                                        $delVv->execute();
                                        $delVv->close();
                                    }
                                }
                            }
                        }
                    }
                }
                $chkStmt->close();
            }
        }

        echo json_encode([
            'success' => true,
            'cancelled' => true
        ]);
        $conn->close();
        exit();
    }

    /* ---------------------------------------------------------
       2. LIVE FEED (POLLING - ULTRA FAST)
    --------------------------------------------------------- */
    if ($ajaxAction === 'live_feed' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        header("Content-Type: application/json; charset=utf-8");

        $sinceId = (int)($_GET['since_id'] ?? 0);
        $newLogs = [];
        $maxId = $sinceId;

        $stmtNew = $conn->prepare("
            SELECT
                vl.id,
                vl.plate_number,
                vl.vehicle_category,
                vl.vehicle_type,
                COALESCE(NULLIF(vl.purpose, ''), vv.purpose, '') AS purpose,
                COALESCE(NULLIF(vl.visitor_name, ''), vv.visitor_name, '') AS visitor_name,
                COALESCE(vv.address_to_visit, '') AS address_to_visit,
                COALESCE(NULLIF(vl.id_type, ''), vv.id_type, '') AS id_type,
                COALESCE(NULLIF(vl.id_number, ''), vv.id_number, '') AS id_number,
                vl.direction,
                vl.date_created,
                {$activityTimeColumns}
            FROM vehicle_logs vl
            LEFT JOIN visitor_vehicles vv
                ON vl.visitor_vehicle_id = vv.id
            WHERE vl.date_created >= CURDATE()
              AND vl.id > ?
            ORDER BY vl.id ASC
            LIMIT 20
        ");

        if ($stmtNew) {
            $stmtNew->bind_param("i", $sinceId);
            $stmtNew->execute();
            $resNew = $stmtNew->get_result();
            while ($rowNew = $resNew->fetch_assoc()) {
                $rowNew['id'] = (int)$rowNew['id'];
                if ($rowNew['id'] > $maxId) {
                    $maxId = $rowNew['id'];
                }
                $newLogs[] = $rowNew;
            }
            $stmtNew->close();
        }

        // Fast count totals using index
        $totalRegisteredToday = 0;
        $totalVisitorsToday = 0;
        $qTotals = $conn->query("
            SELECT
                COALESCE(SUM(CASE WHEN vehicle_category = 'Registered' AND direction = 'IN' THEN 1 ELSE 0 END), 0) AS reg_tot,
                COALESCE(SUM(CASE WHEN vehicle_category = 'Visitor' AND direction = 'IN' THEN 1 ELSE 0 END), 0) AS vis_tot
            FROM vehicle_logs
            WHERE date_created >= CURDATE()
        ");
        if ($qTotals && $rowTot = $qTotals->fetch_assoc()) {
            $totalRegisteredToday = (int)$rowTot['reg_tot'];
            $totalVisitorsToday = (int)$rowTot['vis_tot'];
        }

        // Hourly breakdown for live vehicleChart update
        $registeredHourly = array_fill(0, 24, 0);
        $visitorHourly = array_fill(0, 24, 0);
        $motorcycleHourly = array_fill(0, 24, 0);
        $carHourly = array_fill(0, 24, 0);
        $qHourly = $conn->query("
            SELECT
                HOUR(date_created) AS log_hour,
                SUM(CASE WHEN vehicle_category = 'Registered' THEN 1 ELSE 0 END) AS reg_cnt,
                SUM(CASE WHEN vehicle_category = 'Visitor' THEN 1 ELSE 0 END) AS vis_cnt,
                SUM(CASE WHEN vehicle_type = 'Motorcycle' THEN 1 ELSE 0 END) AS moto_cnt,
                SUM(CASE WHEN vehicle_type = 'Car' THEN 1 ELSE 0 END) AS car_cnt
            FROM vehicle_logs
            WHERE date_created >= CURDATE()
            GROUP BY HOUR(date_created)
        ");
        if ($qHourly) {
            while ($hrRow = $qHourly->fetch_assoc()) {
                $h = (int)$hrRow['log_hour'];
                if ($h >= 0 && $h < 24) {
                    $registeredHourly[$h] = (int)$hrRow['reg_cnt'];
                    $visitorHourly[$h] = (int)$hrRow['vis_cnt'];
                    $motorcycleHourly[$h] = (int)$hrRow['moto_cnt'];
                    $carHourly[$h] = (int)$hrRow['car_cnt'];
                }
            }
        }

        // Second-precision per-duration reminders & overstay alarms
        $timerSnapshot = secsysFetchActiveVisitorTimers($conn);
        $pendingVisitors = secsysGetPendingVisitors();

        echo json_encode(array_merge([
            'success' => true,
            'new_logs' => $newLogs,
            'pending_visitors' => $pendingVisitors,
            'max_id' => $maxId,
            'total_registered' => $totalRegisteredToday,
            'total_visitors' => $totalVisitorsToday,
            'chart' => [
                'data' => [
                    $registeredHourly,
                    $visitorHourly,
                    $motorcycleHourly,
                    $carHourly
                ]
            ],
        ], $timerSnapshot));

        $conn->close();
        exit();
    }

    /* ---------------------------------------------------------
       3. GUARD HEARTBEAT
    --------------------------------------------------------- */
    if ($ajaxAction === 'heartbeat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header("Content-Type: application/json; charset=utf-8");

        if ($guardID === '') {
            echo json_encode(['success' => false, 'message' => 'Guard session not found.']);
            $conn->close();
            exit();
        }

        $heartbeat = $conn->prepare("
            UPDATE security_guard
            SET login_status = 'Active', last_seen = NOW()
            WHERE guard_id = ? AND status = 'Active'
            LIMIT 1
        ");

        if ($heartbeat) {
            $heartbeat->bind_param("s", $guardID);
            $heartbeat->execute();

            if ($partnerGuardID !== '') {
                $heartbeat->bind_param("s", $partnerGuardID);
                $heartbeat->execute();
            }

            $success = ($heartbeat->affected_rows >= 0);
            $heartbeat->close();
            echo json_encode(['success' => $success]);
        } else {
            echo json_encode(['success' => false]);
        }

        $conn->close();
        exit();
    }
}

/* =========================================================
   FULL DASHBOARD PAGE INITIALIZATION
   (Runs only on full page loads, not on fast AJAX requests)
========================================================= */

secsysEnsureSchema($conn);
$guardExtraCameras = secsysLoadExtraCameras($conn);
$systemName = secsysGetSystemName($conn);
$systemIcon = secsysGetSystemIcon($conn);


/* =========================================================
   INITIAL HEARTBEAT
   Immediately records the guard as online.
========================================================= */

$guardID = trim(
    $_SESSION['guard_id'] ?? ''
);
$partnerGuardID = trim(
    $_SESSION['partner_guard_id'] ?? ''
);
$primaryGuardName = trim($_SESSION['guard_full_name'] ?? $_SESSION['username'] ?? '');
$partnerGuardName = trim($_SESSION['partner_guard_name'] ?? '');

if ($guardID !== '') {

    $initialHeartbeat = $conn->prepare("
        UPDATE security_guard
        SET
            login_status = 'Active',
            last_seen = NOW()
        WHERE guard_id = ?
        LIMIT 1
    ");

    if ($initialHeartbeat) {

        $initialHeartbeat->bind_param(
            "s",
            $guardID
        );
        $initialHeartbeat->execute();

        if ($partnerGuardID !== '') {
            $initialHeartbeat->bind_param("s", $partnerGuardID);
            $initialHeartbeat->execute();
        }

        $initialHeartbeat->close();

    }

}


/* =========================================================
   TODAY'S DATE
========================================================= */

$today = date("Y-m-d");

$displayDate = date("l, F d, Y");


/* =========================================================
   DEFAULT STATISTICS
========================================================= */

$registeredMotorcycleIn = 0;
$registeredMotorcycleOut = 0;
$registeredCarIn = 0;
$registeredCarOut = 0;
$registeredTruckIn = 0;
$registeredTruckOut = 0;

$visitorMotorcycleIn = 0;
$visitorMotorcycleOut = 0;
$visitorCarIn = 0;
$visitorCarOut = 0;
$visitorTruckIn = 0;
$visitorTruckOut = 0;


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

        elseif ($type === 'truck') {

            if ($direction === 'IN') {
                $registeredTruckIn = $total;
            }

            if ($direction === 'OUT') {
                $registeredTruckOut = $total;
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

        elseif ($type === 'truck') {

            if ($direction === 'IN') {
                $visitorTruckIn = $total;
            }

            if ($direction === 'OUT') {
                $visitorTruckOut = $total;
            }
        }
    }
}

$stmt->close();


/* =========================================================
   TOTAL VEHICLES ENTERED TODAY
========================================================= */

$totalRegisteredToday =
    $registeredMotorcycleIn +
    $registeredCarIn +
    $registeredTruckIn;

$totalVisitorsToday =
    $visitorMotorcycleIn +
    $visitorCarIn +
    $visitorTruckIn;


/* =========================================================
   HOURLY GRAPH DATA
========================================================= */

$hours = [];

$registeredHourly = [];
$visitorHourly = [];
$motorcycleHourly = [];
$carHourly = [];


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
   RECENT VEHICLE ACTIVITY (STACK UP TO 5 PER CATEGORY)
========================================================= */

$recentVisitorVehicles = [];
$recentRegisteredVehicles = [];
$latestLogIdToday = 0;

$sqlMaxId = $conn->prepare("
    SELECT COALESCE(MAX(id), 0) AS max_id
    FROM vehicle_logs
    WHERE DATE(date_created) = ?
");
if ($sqlMaxId) {
    $sqlMaxId->bind_param("s", $today);
    $sqlMaxId->execute();
    $resMax = $sqlMaxId->get_result();
    if ($rowMax = $resMax->fetch_assoc()) {
        $latestLogIdToday = (int)$rowMax['max_id'];
    }
    $sqlMaxId->close();
}

$sqlRecentVisitors = "
    SELECT
        vl.id,
        vl.plate_number,
        vl.vehicle_category,
        vl.vehicle_type,
        COALESCE(NULLIF(vl.purpose, ''), vv.purpose, '') AS purpose,
        COALESCE(NULLIF(vl.visitor_name, ''), vv.visitor_name, '') AS visitor_name,
        COALESCE(vv.address_to_visit, '') AS address_to_visit,
        COALESCE(NULLIF(vl.id_type, ''), vv.id_type, '') AS id_type,
        COALESCE(NULLIF(vl.id_number, ''), vv.id_number, '') AS id_number,
        vl.direction,
        vl.date_created,
        {$activityTimeColumns}
    FROM vehicle_logs vl
    LEFT JOIN visitor_vehicles vv
        ON vl.visitor_vehicle_id = vv.id
    WHERE DATE(vl.date_created) = ?
      AND LOWER(TRIM(vl.vehicle_category)) = 'visitor'
    ORDER BY vl.id DESC
    LIMIT 5
";

$stmtRecentVis = $conn->prepare($sqlRecentVisitors);
if ($stmtRecentVis) {
    $stmtRecentVis->bind_param("s", $today);
    $stmtRecentVis->execute();
    $resRecentVis = $stmtRecentVis->get_result();
    while ($row = $resRecentVis->fetch_assoc()) {
        $recentVisitorVehicles[] = $row;
    }
    $stmtRecentVis->close();
}

$sqlRecentRegistered = "
    SELECT
        vl.id,
        vl.plate_number,
        vl.vehicle_category,
        vl.vehicle_type,
        vl.direction,
        vl.date_created,
        {$activityTimeColumns}
    FROM vehicle_logs vl
    WHERE DATE(vl.date_created) = ?
      AND LOWER(TRIM(vl.vehicle_category)) = 'registered'
    ORDER BY vl.id DESC
    LIMIT 5
";

$stmtRecentReg = $conn->prepare($sqlRecentRegistered);
if ($stmtRecentReg) {
    $stmtRecentReg->bind_param("s", $today);
    $stmtRecentReg->execute();
    $resRecentReg = $stmtRecentReg->get_result();
    while ($row = $resRecentReg->fetch_assoc()) {
        $recentRegisteredVehicles[] = $row;
    }
    $stmtRecentReg->close();
}

/* Initial vehicle logs today for seeding Notification Drawer */
$initialLogsToday = [];
$stmtInitialLogs = $conn->prepare("
    SELECT
        vl.id,
        vl.plate_number,
        vl.vehicle_category,
        vl.vehicle_type,
        COALESCE(NULLIF(vl.purpose, ''), vv.purpose, '') AS purpose,
        vl.direction,
        vl.date_created
    FROM vehicle_logs vl
    LEFT JOIN visitor_vehicles vv
        ON vl.visitor_vehicle_id = vv.id
    WHERE DATE(vl.date_created) = ?
    ORDER BY vl.id DESC
    LIMIT 30
");
if ($stmtInitialLogs) {
    $stmtInitialLogs->bind_param("s", $today);
    $stmtInitialLogs->execute();
    $resInit = $stmtInitialLogs->get_result();
    while ($rowInit = $resInit->fetch_assoc()) {
        $initialLogsToday[] = $rowInit;
    }
    $stmtInitialLogs->close();
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
    overflow: hidden;
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

    padding: 0 38px 40px;

    width: calc(100% - 212px);
    box-sizing: border-box;
}


/* =========================================================
   HEADER
========================================================= */

.header {

    min-height: 82px;

    border-bottom: 1px solid #e4e4e4;

    margin: 0 -38px 21px;

    padding: 0 38px;

    display: flex;

    align-items: center;

    justify-content: space-between;
}

/* =========================================================
   NOTIFICATION BUTTON, DRAWER, AND OVERSTAY REMINDER ALARM
========================================================= */

.header-right {
    display: flex;
    align-items: center;
    gap: 16px;
}

.btn-notif-bell {
    position: relative;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 50%;
    width: 44px;
    height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    transition: all 0.18s ease;
}

.btn-notif-bell:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    transform: translateY(-1px);
    box-shadow: 0 3px 6px rgba(0, 0, 0, 0.12);
}

.btn-notif-bell .bell-icon {
    font-size: 20px;
    line-height: 1;
}

.notif-badge {
    position: absolute;
    top: -3px;
    right: -3px;
    background: #ef4444;
    color: #ffffff;
    font-size: 10px;
    font-weight: 800;
    min-width: 18px;
    height: 18px;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    border: 2px solid #ffffff;
    box-sizing: border-box;
    animation: notifPulse 1.8s infinite;
}

@keyframes notifPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.18); }
}

/* OVERSTAY REMINDER ALERT BANNER */
.overstay-alert-banner {
    background: #fff1f2;
    border: 1px solid #fecdd3;
    border-left: 6px solid #e11d48;
    border-radius: 12px;
    padding: 12px 18px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    box-shadow: 0 3px 10px rgba(225, 29, 72, 0.12);
    animation: alertSlideDown 0.3s ease-out;
}

@keyframes alertSlideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}

.overstay-alert-content {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.overstay-alert-icon {
    font-size: 22px;
    animation: alertBounce 1s infinite alternate;
}

@keyframes alertBounce {
    from { transform: scale(1); }
    to { transform: scale(1.22); }
}

.overstay-alert-text {
    font-size: 13px;
    color: #881337;
    line-height: 1.4;
}

.overstay-alert-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.btn-banner-mute {
    background: #ffffff;
    border: 1px solid #dc2626;
    color: #dc2626;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
}
.btn-banner-mute:hover {
    background: #fee2e2;
}

.btn-view-overstay {
    background: #e11d48;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    padding: 7px 14px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s ease;
    white-space: nowrap;
}

.btn-view-overstay:hover {
    background: #be123c;
}

.btn-dismiss-alert {
    background: none;
    border: none;
    font-size: 20px;
    color: #9f1239;
    cursor: pointer;
    line-height: 1;
    padding: 2px 6px;
}

/* NOTIFICATION DRAWER */
.notif-drawer-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.45);
    backdrop-filter: blur(2px);
    z-index: 99998;
}

.notif-drawer-overlay.active {
    display: block;
}

.notif-drawer {
    position: fixed;
    top: 0;
    right: -430px;
    bottom: 0;
    width: 410px;
    max-width: 92vw;
    background: #ffffff;
    z-index: 99999;
    box-shadow: -6px 0 25px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    transition: right 0.28s cubic-bezier(0.16, 1, 0.3, 1);
}

.notif-drawer.open {
    right: 0;
}

.notif-drawer-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
}

.notif-drawer-title {
    font-size: 15px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}

.notif-drawer-count {
    background: #e2e8f0;
    color: #334155;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 9999px;
}

.btn-close-drawer {
    background: none;
    border: none;
    font-size: 24px;
    color: #64748b;
    cursor: pointer;
    line-height: 1;
}

.btn-close-drawer:hover {
    color: #0f172a;
}

.notif-tabs {
    display: flex;
    padding: 8px 14px;
    gap: 6px;
    background: #f1f5f9;
    border-bottom: 1px solid #e2e8f0;
    overflow-x: auto;
}

.notif-tab {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s ease;
}

.notif-tab.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}

.notif-actions-bar {
    padding: 8px 18px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #ffffff;
}

.btn-notif-action {
    background: none;
    border: none;
    color: #2563eb;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    padding: 3px 8px;
    border-radius: 4px;
}

.btn-notif-action:hover {
    background: #f1f5f9;
}

.btn-notif-action.text-danger {
    color: #dc2626;
}

.btn-notif-action.text-danger:hover {
    background: #fee2e2;
}

.notif-list {
    flex: 1;
    overflow-y: auto;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.notif-item {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 12px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    transition: all 0.15s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.notif-item.notif-in {
    border-left: 4px solid #16a34a;
    background: #f0fdf4;
}

.notif-item.notif-out {
    border-left: 4px solid #dc2626;
    background: #fef2f2;
}

.notif-item.notif-overdue {
    border-left: 4px solid #e11d48;
    background: #fff1f2;
    border-color: #fecdd3;
}

.notif-item.notif-duesoon {
    border-left: 4px solid #f59e0b;
    background: #fffbeb;
    border-color: #fde68a;
}

.notif-item.unread {
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.notif-item-icon {
    font-size: 18px;
    line-height: 1;
    margin-top: 2px;
}

.notif-item-body {
    flex: 1;
    min-width: 0;
}

.notif-item-title {
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    margin-bottom: 2px;
}

.notif-item-time {
    font-size: 10px;
    color: #64748b;
    font-weight: 500;
    white-space: nowrap;
}

.notif-item-sub {
    font-size: 12px;
    color: #475569;
    line-height: 1.3;
}

.notif-empty-state {
    text-align: center;
    padding: 40px 16px;
    color: #94a3b8;
    font-size: 13px;
}

.notif-empty-icon {
    font-size: 32px;
    margin-bottom: 8px;
}

.badge-overdue-pulse {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 7px;
    border-radius: 9999px;
    font-size: 9px;
    font-weight: 800;
    background: #e11d48;
    color: #ffffff;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    animation: overdueGlow 1.2s infinite alternate;
}

@keyframes overdueGlow {
    from { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0.4); }
    to { box-shadow: 0 0 0 4px rgba(225, 29, 72, 0); }
}

.badge-duesoon {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 7px;
    border-radius: 9999px;
    font-size: 9px;
    font-weight: 800;
    background: #f59e0b;
    color: #ffffff;
    letter-spacing: 0.5px;
}




.header-left {

    display: flex;

    flex-direction: column;

    justify-content: center;
}


.header h1 {

    font-size: 25px;

    font-weight: 500;

    line-height: 1.1;
}


.header p {

    font-size: 14px;

    color: #666;

    margin-top: 3px;
}


.clock {

    font-size: 32px;

    font-weight: 300;

    color: #444;

    letter-spacing: 0.5px;
}


/* =========================================================
   CAMERA GRID (2-CAMERA TAPO C500 SYSTEM)
========================================================= */

.camera-grid {

    display: grid;

    grid-template-columns:
        repeat(2, minmax(320px, 1fr));

    gap: 16px;

    width: 100%;

    max-width: 1050px;

    margin: 0 auto;
}

/* =========================================================
   CAMERA CARD
========================================================= */

.camera-card {

    background: #ffffff;

    border: 1px solid #dedede;

    border-radius: 19px;

    padding: 13px 15px 15px;

    box-shadow:
        0 2px 5px rgba(0, 0, 0, 0.14);

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


/* =========================================================
   CAMERA IMAGE
========================================================= */

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


/* =========================================================
   CAMERA STATUS
========================================================= */

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
.dir-badge-monitor {
    background: #e0f2fe !important;
    color: #0369a1 !important;
    border: 1px solid #7dd3fc !important;
}
.camera-card .camera-title {
    min-width: 0;
}
.camera-card .camera-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
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



/* =========================================================
   VEHICLE ACTIVITY (ALIGNED WITH CAMERA GRID)
========================================================= */

.activity-grid {

    display: grid;

    grid-template-columns:
        repeat(2, minmax(320px, 1fr));

    gap: 16px;

    width: 100%;

    max-width: 1050px;

    margin: 16px auto 0;
}


/* =========================================================
   ACTIVITY CARD
========================================================= */

.activity-card {

    background: #baf7b4;

    border: 1px solid #dedede;

    border-radius: 21px;

    padding: 13px 15px 15px;

    box-shadow:
        0 2px 5px rgba(0, 0, 0, 0.13);

    min-width: 0;
}

.activity-cards {

    background: #b3dcf4;

    border: 1px solid #dedede;

    border-radius: 21px;

    padding: 13px 15px 15px;

    box-shadow:
        0 2px 5px rgba(0, 0, 0, 0.13);

    min-width: 0;
}

.activity-header {

    display: flex;

    align-items: center;

    justify-content: center;

    margin-bottom: 8px;

    position: relative;

    min-height: 26px;
}

.activity-title {

    text-align: center;

    font-size: 15px;

    margin-bottom: 8px;

    font-weight: 600;
}

.activity-header .activity-title {

    margin-bottom: 0;
}

.btn-manual-add-visitor {
    position: absolute;
    right: 0;
    top: 50%;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    font-size: 11.5px;
    font-weight: 700;
    color: #ffffff;
    background: #0284c7;
    border: 1px solid #0369a1;
    border-radius: 6px;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(2, 132, 199, 0.25);
    transition: all 0.15s ease-in-out;
}

.btn-manual-add-visitor:hover {
    background: #0369a1;
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.35);
    transform: translateY(-50%) scale(1.02);
}

.btn-manual-add-visitor:active {
    transform: translateY(-50%) scale(0.98);
}

.activity-stack {

    display: flex;

    flex-direction: column;

    gap: 6px;
}


/* =========================================================
   VEHICLE ITEM (STACKED, MAX 5)
========================================================= */

.vehicle-item {
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

/* VEHICLES ENTERING (IN): Entire row is soft emerald green with bold green accent bar */
.vehicle-item.row-in {
    background: #dcfce7 !important;
    border: 1px solid #86efac !important;
    border-left: 5px solid #16a34a !important;
}

.vehicle-item.row-in:hover {
    background: #bbf7d0 !important;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(22, 163, 74, 0.18);
}

.vehicle-item.row-in .plate-text {
    color: #064e3b !important;
}

/* VEHICLES EXITING (OUT): Entire row is soft crimson/rose red with bold red accent bar */
.vehicle-item.row-out {
    background: #fee2e2 !important;
    border: 1px solid #fca5a5 !important;
    border-left: 5px solid #dc2626 !important;
}

.vehicle-item.row-out:hover {
    background: #fecaca !important;
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(220, 38, 38, 0.18);
}

.vehicle-item.row-out .plate-text {
    color: #881337 !important;
}

/* Fallback if no direction class */
.vehicle-item:not(.row-in):not(.row-out) {
    background: #e2e8f0;
    border: 1px solid #cbd5e1;
    border-left: 5px solid #64748b;
}

.vehicle-item.visitor-item-row {
    cursor: pointer;
}

.vehicle-item.visitor-item-out {
    cursor: pointer;
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


.vehicle-info {

    display: flex;

    flex-direction: column;

    min-width: 0;
}


.vehicle-plate {

    font-size: 12px;

    font-weight: 600;

    color: #111;

    display: flex;

    align-items: center;

    gap: 6px;
}


.vehicle-direction {

    font-size: 10px;

    color: #4b5563;
}

.vehicle-badges {

    display: flex;

    align-items: center;

    gap: 5px;

    flex-shrink: 0;
    overflow: hidden;
}

.purpose-pill {

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

.purpose-pill.needs-info {

    background: #fef3c7;

    color: #92400e;

    border: 1px solid #f59e0b;
}

/* Time In / Time Out on each activity row (replaced the "Captured" badge) */
.activity-times {

    display: flex;

    flex-direction: column;

    align-items: flex-end;

    gap: 1px;

    background: rgba(255, 255, 255, 0.78);

    border-radius: 8px;

    padding: 2px 7px;

    font-size: 9px;

    font-weight: 600;

    line-height: 1.25;

    color: #374151;

    white-space: nowrap;
}

.activity-times .activity-time b {

    display: inline-block;

    min-width: 20px;

    font-weight: 800;

    color: #6b7280;
}


/* =========================================================
   EMPTY ACTIVITY
========================================================= */

.empty-activity {

    text-align: center;

    color: #666;

    font-size: 11px;

    padding: 15px 5px;
}


/* =========================================================
   TOTAL CARDS (ALIGNED WITH CAMERA & ACTIVITY CARDS)
========================================================= */

.total-cards {

    display: grid;

    grid-template-columns:
        repeat(2, minmax(320px, 1fr));

    gap: 16px;

    width: 100%;

    max-width: 1050px;

    margin: 16px auto 0;
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

    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.10);

    min-width: 0;
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

@keyframes statNumberPulse {
    0% { transform: scale(1); }
    35% { transform: scale(1.22); color: #16a34a; font-weight: 700; text-shadow: 0 0 10px rgba(22, 163, 74, 0.4); }
    100% { transform: scale(1); }
}

.stat-number-updated {
    animation: statNumberPulse 0.9s cubic-bezier(0.175, 0.885, 0.32, 1.275) !important;
}


/* =========================================================
   VISITOR ARRIVAL NOTIFICATION TOAST & DUTY BADGE
========================================================= */

.duty-guards-bar {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 6px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 12px;
    color: #1e3a8a;
    font-weight: 600;
}

.duty-guards-bar a {
    color: #2563eb;
    text-decoration: none;
    font-weight: 700;
    margin-left: 4px;
    font-size: 11px;
}

.duty-guards-bar a:hover {
    text-decoration: underline;
}

.visitor-toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 11000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 370px;
    width: calc(100% - 40px);
    pointer-events: none;
}

.visitor-toast {
    pointer-events: auto;
    background: #ffffff;
    border-left: 5px solid #2563eb;
    border-radius: 14px;
    padding: 14px 16px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.22);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    animation: toastSlideIn 0.28s ease-out;
}

@keyframes toastSlideIn {
    from {
        opacity: 0;
        transform: translateX(40px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.visitor-toast-title {
    font-size: 13px;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 3px;
}

.visitor-toast-body {
    font-size: 12px;
    color: #334155;
    line-height: 1.4;
}

.visitor-toast-close {
    background: none;
    border: none;
    color: #64748b;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    padding: 0 2px;
}

/* =========================================================
   VISITOR INFORMATION INPUT MODAL
========================================================= */

.visitor-modal-overlay {

    display: none;

    position: fixed;

    top: 0;

    left: 0;

    right: 0;

    bottom: 0;

    background: rgba(15, 23, 42, 0.58);

    backdrop-filter: blur(3px);

    z-index: 10000;

    align-items: center;

    justify-content: center;

    padding: 16px;
}

.visitor-modal-overlay.active {

    display: flex;
}

.visitor-modal-box {

    background: #ffffff;

    border-radius: 18px;

    max-width: 480px;

    width: 95%;

    max-height: 92vh;

    display: flex;

    flex-direction: column;

    box-shadow: 0 14px 34px rgba(0, 0, 0, 0.26);

    overflow: hidden;

    animation: tapoModalIn 0.2s ease-out;
}

.visitor-modal-header {

    padding: 15px 20px;

    background: #eff6ff;

    border-bottom: 1px solid #dbeafe;

    display: flex;

    align-items: center;

    justify-content: space-between;

    flex-shrink: 0;
}

.visitor-modal-title {

    font-size: 15px;

    font-weight: 700;

    color: #1e3a8a;

    display: flex;

    align-items: center;

    gap: 8px;
}

.visitor-modal-badge {

    font-size: 10px;

    font-weight: 600;

    background: #dbeafe;

    color: #1d4ed8;

    padding: 2px 8px;

    border-radius: 10px;
}

.visitor-modal-body {

    padding: 18px 20px;

    overflow-y: auto;

    max-height: calc(92vh - 130px);
}

.visitor-form-group {

    margin-bottom: 14px;
}

.visitor-form-group label {

    display: block;

    font-size: 12px;

    font-weight: 600;

    color: #1f2937;

    margin-bottom: 5px;
}

.visitor-form-group input,
.visitor-form-group select {

    width: 100%;

    padding: 9px 12px;

    border: 1px solid #cbd5e1;

    border-radius: 9px;

    font-size: 13px;

    font-family: inherit;

    color: #111827;

    background: #fff;

    box-sizing: border-box;
}

.visitor-form-group input:focus,
.visitor-form-group select:focus {

    outline: none;

    border-color: #2563eb;

    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

.visitor-purpose-chips {

    display: flex;

    flex-wrap: wrap;

    gap: 6px;

    margin-top: 6px;
}

.visitor-purpose-chip {

    background: #f1f5f9;

    border: 1px solid #cbd5e1;

    border-radius: 12px;

    padding: 3px 9px;

    font-size: 11px;

    color: #334155;

    cursor: pointer;

    transition: all 0.12s ease;
}

.visitor-purpose-chip:hover {

    background: #dbeafe;

    border-color: #93c5fd;

    color: #1e40af;
}

.visitor-form-error {

    display: none;

    background: #fef2f2;

    border: 1px solid #fecaca;

    color: #b91c1c;

    font-size: 12px;

    padding: 8px 10px;

    border-radius: 8px;

    margin-bottom: 12px;
}

.visitor-modal-footer {

    padding: 12px 20px;

    background: #f8fafc;

    border-top: 1px solid #e5e7eb;

    display: flex;

    justify-content: flex-end;

    gap: 8px;

    flex-shrink: 0;
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

    margin: 18px auto 0;

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
   LARGE SCREEN
========================================================= */

@media (min-width: 1400px) {

    .main {

        padding-left: 45px;

        padding-right: 45px;
    }

    .header {

        margin-left: -45px;

        margin-right: -45px;

        padding-left: 45px;

        padding-right: 45px;
    }

    .camera-grid,
    .activity-grid,
    .total-cards,
    .chart-container {

        max-width: 1100px;
    }

    .camera-screen {

        height: 250px;
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

        padding-left: 25px;

        padding-right: 25px;
    }

    .header {

        margin-left: -25px;

        margin-right: -25px;

        padding-left: 25px;

        padding-right: 25px;
    }

    .camera-grid,
    .activity-grid,
    .total-cards,
    .chart-container {

        max-width: 850px;
    }

    .camera-screen {

        height: 210px;
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


    .logo-shield img {
    width: 100%;
    height: 100%;
    object-fit: cover;
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

        padding: 20px;
    }


    .header {

        margin-left: -20px;

        margin-right: -20px;

        padding-left: 20px;

        padding-right: 20px;
    }


    .clock {

        font-size: 25px;
    }


    .camera-grid {

        grid-template-columns: 1fr 1fr;
    }


    .activity-grid {

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

        padding: 15px 14px 30px;
    }


    .header {

        margin-left: -14px;

        margin-right: -14px;

        padding-left: 14px;

        padding-right: 14px;

        min-height: 75px;
    }


    .header h1 {

        font-size: 21px;
    }


    .header p {

        font-size: 11px;
    }


    .clock {

        font-size: 19px;
    }


    .camera-grid {

        grid-template-columns: 1fr;
    }


    .activity-grid {

        grid-template-columns: 1fr;
    }


    .total-cards {

        grid-template-columns: 1fr;
    }


    .camera-screen {

        height: 180px;
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



}


/* =========================================================
   PRINT
========================================================= */

@media print {

    body {

        background: white;
    }


    .sidebar {

        display: none;
    }


    .main {

        margin-left: 0;

        width: 100%;

        padding: 20px;
    }


    .header {

        margin: 0 0 20px;

        padding: 0;

        border-bottom: 1px solid #ddd;
    }





    .camera-card,
    .activity-card,
    .total-card,
    .chart-container {

        box-shadow: none;
    }
}

</style>

<!-- Enlarges the dashboard 25% on desktop (loaded after the page styles) -->
<link rel="stylesheet" href="assets/css/ui_scale.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/ui_scale.css'); ?>">

</head>


<body>


<!-- =====================================================
     SIDEBAR
====================================================== -->

<aside class="sidebar">


    <!-- LOGO -->

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
                Security Guard
            </div>

        </div>

    </div>


    <!-- NAVIGATION -->

    <nav class="nav">


        <!-- DASHBOARD -->

        <a href="guard_dashboard.php"
           class="active">

            <span class="nav-icon">
                🖥️
            </span>

            <span>
                Dashboard
            </span>

        </a>


        <!-- REGISTERED -->

        <a href="registered_logs.php">

            <span class="nav-icon">
                🚗
            </span>

            <span>
                Registered
            </span>

        </a>


        <!-- VISITOR -->

        <a href="visitor_logs.php">

            <span class="nav-icon">
                👥
            </span>

            <span>
                Visitor
            </span>

        </a>


    </nav>


    <!-- LOGOUT -->

    <a href="logout.php?role=Security%20Guard"
       class="logout">

        🚪 Logout

    </a>


</aside>



<!-- =====================================================
     MAIN CONTENT
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
                <?php echo htmlspecialchars($displayDate); ?>
            </p>

            <div class="duty-guards-bar">
                <span>🛡️ On Duty: <?php
                    echo htmlspecialchars($primaryGuardName !== '' ? $primaryGuardName : $guardID);
                    if ($partnerGuardID !== '') {
                        echo ' &amp; ' . htmlspecialchars($partnerGuardName !== '' ? $partnerGuardName : $partnerGuardID);
                    }
                ?></span>
                <a href="guard_select_partner.php" title="Select or switch co-duty guard">[Change Partner]</a>
            </div>

        </div>


        <div class="header-right">

            <button
                type="button"
                class="btn-notif-bell"
                id="btnNotifBell"
                onclick="toggleNotificationDrawer()"
                title="View Notifications & Overstay Reminders"
            >
                <span class="bell-icon">🔔</span>
                <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
            </button>

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

    <!-- OVERSTAY / DUE TIME REMINDER ALERT BANNER -->
    <div class="overstay-alert-banner" id="overstayAlertBanner" style="display:none;">
        <div class="overstay-alert-content">
            <span class="overstay-alert-icon">⚠️</span>
            <div class="overstay-alert-text" id="overstayAlertText">
                <strong>Visitor Time Limit Notice:</strong> 1 visitor vehicle has exceeded their allowed time!
            </div>
        </div>
        <div class="overstay-alert-actions">
            <button type="button" class="btn-banner-mute" id="bannerMuteBtn" onclick="toggleOverstayAlarmAudio()">🔊 Mute Alarm</button>
            <button type="button" class="btn-view-overstay" onclick="openNotificationDrawerWithTab('ALERT')">View Details</button>
            <button type="button" class="btn-dismiss-alert" onclick="dismissOverstayBanner()" aria-label="Dismiss">&times;</button>
        </div>
    </div>



    <!-- =================================================
         CAMERA SECTION (TAPO C500 - 2 CAMERAS)
    ================================================== -->

    <!-- Entrance & Exit cards are fixed; cameras added by the Administrator
         are appended after them by renderGuardExtraCameras() -->
    <div class="camera-grid" id="cameraGrid">


        <!-- =============================================
             ENTRANCE CAMERA (TAPO C500)
        ============================================== -->

        <div class="camera-card" id="cardEntranceCam">

            <div class="camera-card-header">

                <div class="camera-title">
                    <span id="entranceCameraTitle">Entrance Cam</span>
                    <span class="camera-tag dir-badge-in" style="font-size:11px; padding:3px 10px; font-weight:800;">🟢 IN (Entrance)</span>
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
                    src="http://localhost:5000/video/entrance"
                    alt="Entrance Stream"
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
                        Camera stream or AI detection server is not connected on this address.
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
                            ⚙️ Setup
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
                    <span id="exitCameraTitle">Exit Cam</span>
                    <span class="camera-tag dir-badge-out" style="font-size:11px; padding:3px 10px; font-weight:800;">🔴 OUT (Exit Only)</span>
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
                    src="http://localhost:5000/video/exit"
                    alt="Exit Stream"
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
                        Camera stream or AI detection server is not connected on this address.
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
                            ⚙️ Setup
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
         RECENT VEHICLE ACTIVITY (STACKED, MAX 5, NO REFRESH)
    ================================================== -->

    <div class="activity-grid" id="liveActivity">


        <!-- =============================================
             VISITOR (LEFT COLUMN - UNDER ENTRANCE CAM)
        ============================================== -->

        <div class="activity-cards">

            <div class="activity-header">

                <div class="activity-title">
                    Visitor
                </div>

                <button
                    type="button"
                    class="btn-manual-add-visitor"
                    onclick="openManualVisitorModal()"
                    title="Manually record a visitor"
                >
                    <span style="font-size: 13px; line-height: 1;">➕</span> Add Visitor
                </button>

            </div>

            <div class="activity-stack" id="visitorActivityStack">

                <?php if (!empty($recentVisitorVehicles)): ?>

                    <?php foreach ($recentVisitorVehicles as $vehicle):
                        $vLogId   = (int)($vehicle['id'] ?? 0);
                        $vPlate   = trim((string)($vehicle['plate_number'] ?? ''));
                        $vType    = trim((string)($vehicle['vehicle_type'] ?? 'Car'));
                        $vType = secsysNormalizeVehicleType($vType);
                        $vPurpose = trim((string)($vehicle['purpose'] ?? ''));
                        $vDir     = trim((string)($vehicle['direction'] ?? 'IN'));
                        $vName    = trim((string)($vehicle['visitor_name'] ?? ''));
                        $vAddr    = trim((string)($vehicle['address_to_visit'] ?? ''));
                        $vIdType  = trim((string)($vehicle['id_type'] ?? ''));
                        $vIdNum   = trim((string)($vehicle['id_number'] ?? ''));
                    ?>

                        <?php
                        $isOutVis = (strtoupper($vDir) === 'OUT');
                        ?>
                        <div
                            class="vehicle-item <?php echo $isOutVis ? 'row-out visitor-item-out' : 'row-in visitor-item-row'; ?>"
                            data-log-id="<?php echo $vLogId; ?>"
                            data-direction="<?php echo $vDir; ?>"
                            data-plate="<?php echo htmlspecialchars($vPlate, ENT_QUOTES); ?>"
                            data-vehicle-type="<?php echo htmlspecialchars($vType, ENT_QUOTES); ?>"
                            data-purpose="<?php echo htmlspecialchars($vPurpose, ENT_QUOTES); ?>"
                            data-visitor-name="<?php echo htmlspecialchars($vName, ENT_QUOTES); ?>"
                            data-address="<?php echo htmlspecialchars($vAddr, ENT_QUOTES); ?>"
                            data-id-type="<?php echo htmlspecialchars($vIdType, ENT_QUOTES); ?>"
                            data-id-number="<?php echo htmlspecialchars($vIdNum, ENT_QUOTES); ?>"
                            <?php if ($isOutVis): ?>
                                onclick="openVisitorExitModalFromRow(this)"
                                title="Visitor vehicle exited premises (Click to view exit details)"
                            <?php else: ?>
                                onclick="openVisitorModalFromRow(this)"
                                title="Click to enter or update visitor information"
                            <?php endif; ?>
                        >

                            <div class="vehicle-info">

                                <div class="vehicle-plate">
                                    <span class="plate-text"><?php echo secsysClickablePlateHtml($vPlate, ['log_id' => $vLogId]); ?></span>
                                </div>

                                <div class="vehicle-direction">
                                    <span class="type-text"><?php echo htmlspecialchars($vType); ?></span>
                                    <span class="dir-badge <?php echo $isOutVis ? 'dir-badge-out' : 'dir-badge-in'; ?>">
                                        <?php echo $isOutVis ? '🔴 OUT' : '🟢 IN'; ?>
                                    </span>
                                </div>

                            </div>

                            <div class="vehicle-badges">

                                <?php if (!$isOutVis): ?>
                                    <span class="vda-inline-timer" data-vda-plate="<?php echo htmlspecialchars($vPlate, ENT_QUOTES); ?>" data-vda-status="IN" data-vda-compact="1" style="display:none;"></span>
                                <?php endif; ?>

                                <?php if ($isOutVis): ?>
                                    <span class="purpose-pill" style="background:#fee2e2; color:#991b1b; border:1px solid #f87171;" title="<?php echo htmlspecialchars($vPurpose !== '' ? $vPurpose : 'Exited', ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars($vPurpose !== '' ? $vPurpose : 'Exited'); ?>
                                    </span>
                                <?php elseif ($vPurpose !== '' && strtolower($vPurpose) !== 'visitor entry'): ?>
                                    <span class="purpose-pill" title="<?php echo htmlspecialchars($vPurpose, ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars($vPurpose); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="purpose-pill needs-info">
                                        Input Info
                                    </span>
                                <?php endif; ?>

                                <?php echo guardActivityTimesHtml($vDir, $vehicle['time_in_label'] ?? '', $vehicle['time_out_label'] ?? ''); ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-activity" id="visitorEmptyMsg">
                        No visitor vehicle captured today.
                    </div>

                <?php endif; ?>

            </div>

        </div>



        <!-- =============================================
             REGISTERED (RIGHT COLUMN - UNDER EXIT CAM)
        ============================================== -->

        <div class="activity-card">

            <div class="activity-header" style="justify-content: center;">

                <div class="activity-title">
                    Registered
                </div>

            </div>

            <div class="activity-stack" id="registeredActivityStack">

                <?php if (!empty($recentRegisteredVehicles)): ?>

                    <?php foreach ($recentRegisteredVehicles as $vehicle):
                        $rLogId = (int)($vehicle['id'] ?? 0);
                        $rPlate = trim((string)($vehicle['plate_number'] ?? ''));
                        $rType  = trim((string)($vehicle['vehicle_type'] ?? ''));
                        $rDir   = trim((string)($vehicle['direction'] ?? 'IN'));
                        $rSub   = ($rType !== '' && strtolower($rType) !== 'unknown')
                            ? ($rType . ' • ' . $rDir)
                            : $rDir;
                    ?>

                        <?php
                        $isOutReg = (strtoupper($rDir) === 'OUT');
                        ?>
                        <div
                            class="vehicle-item <?php echo $isOutReg ? 'row-out' : 'row-in'; ?>"
                            data-log-id="<?php echo $rLogId; ?>"
                            data-direction="<?php echo $rDir; ?>"
                            data-plate="<?php echo htmlspecialchars($rPlate, ENT_QUOTES); ?>"
                        >

                            <div class="vehicle-info">

                                <div class="vehicle-plate">
                                    <span class="plate-text"><?php echo secsysClickablePlateHtml($rPlate, ['log_id' => $rLogId]); ?></span>
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

                                <?php echo guardActivityTimesHtml($rDir, $vehicle['time_in_label'] ?? '', $vehicle['time_out_label'] ?? ''); ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-activity" id="registeredEmptyMsg">
                        No registered vehicle captured today.
                    </div>

                <?php endif; ?>

            </div>

        </div>


    </div>



    <!-- =================================================
         TOTALS (ALIGNED UNDER VISITOR & REGISTERED CARDS)
    ================================================== -->

    <div class="total-cards" id="liveTotals">


        <!-- VISITOR (LEFT - ALIGNED WITH VISITOR ACTIVITY CARD ABOVE) -->

        <div class="total-card">

            <div class="total-label">

                Total Visitor Vehicles
                Entered Today

            </div>

            <div class="total-number" id="totalVisitorsCount">

                <?php
                echo $totalVisitorsToday;
                ?>

            </div>

        </div>


        <!-- REGISTERED (RIGHT - ALIGNED WITH REGISTERED ACTIVITY CARD ABOVE) -->

        <div class="total-card">

            <div class="total-label">

                Total Registered Vehicles
                Entered Today

            </div>

            <div class="total-number" id="totalRegisteredCount">

                <?php
                echo $totalRegisteredToday;
                ?>

            </div>

        </div>


    </div>



    <!-- =================================================
         VEHICLE CHART
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
                📹 Camera Setup (One or Two Cameras)
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

            <div class="tapo-form-group">
                <label for="cfgCameraMode">Number of cameras:</label>
                <select id="cfgCameraMode" onchange="updateCameraModeControls()" style="width:100%; padding:8px;">
                    <option value="single">1 camera - shared entrance and exit view</option>
                    <option value="dual">2 cameras - separate entrance and exit</option>
                </select>
            </div>
            <div class="tapo-form-group" id="singleDirectionControls">
                <label for="cfgSingleDirection">Shared-camera logging:</label>
                <select id="cfgSingleDirection" onchange="updateCameraModeControls()" style="width:100%; padding:8px;">
                    <option value="AUTO">Automatic IN / OUT on each return</option>
                    <option value="IN">IN - vehicles entering</option>
                    <option value="OUT">OUT - vehicles leaving</option>
                </select>
                <p class="tapo-help-text">Automatic: the first appearance records IN; after the plate leaves view and returns, an open visit records OUT. Keep the plate out of view for at least three processed frames before scanning it again. IN or OUT can also be selected manually.</p>
            </div>
            <p id="cameraSettingsError" role="alert" style="color:#b91c1c;" hidden></p>

            <!-- Quick Presets -->
            <div style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 12px; color: #1e293b; display: block; margin-bottom: 6px;">
                    ⚡ Quick Presets (1-Click Setup):
                </label>
                <div class="cam-presets-container">
                    <button type="button" class="btn-cam-preset" onclick="applyPresetDualTapo()">
                        📹 2x (RTSP)
                    </button>
                    <button type="button" class="btn-cam-preset" onclick="applyPresetTapoAndWebcam()">
                        🎥 Entrance + USB Exit
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
                        <span id="settingsEntranceTitle">Entrance / Shared Camera</span>
                        <span style="background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700;" id="settingsEntranceDirection">Direction: IN</span>
                    </div>
                    <span id="modalEntranceStatus" style="font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: #e2e8f0; color: #475569;">
                        Checking...
                    </span>
                </div>

                <div class="tapo-form-group" id="entranceEnabledRow">
                    <label style="display:block;"><input type="checkbox" id="cfgEntranceEnabled" checked> Enable entrance camera (automatic IN)</label>
                    <div class="tapo-help-text">Turn a camera off when it is not installed or not needed; its panel is hidden from the dashboard.</div>
                </div>

                <div class="tapo-form-group">
                    <label for="cfgEntranceSource">Hardware Source ( RTSP URL or USB Cam Index):</label>
                    <input
                        type="text"
                        id="cfgEntranceSource"
                        placeholder="rtsp://username:password@CAMERA_IP:554/stream1"
                    >
                    <div class="tapo-help-text">
                        For Tapo cameras: <code>rtsp://username:password@IP:554/stream2</code> | For USB webcam: <code>0</code>
                    </div>
                </div>

                <div class="tapo-form-group">
                    <label for="cfgEntranceZoom">Camera View / Lane Focus (Narrow Mode):</label>
                    <select id="cfgEntranceZoom" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff;">
                        <option value="1.35" selected>🔍 Narrow (Lane Focus 1.35x - Recommended for Plates)</option>
                        <option value="1.5">🔬 Extra Narrow (Tight Lane Focus 1.5x)</option>
                        <option value="1.2">🔎 Moderate Narrow (1.2x)</option>
                        <option value="1.0">🌐 Wide (Full Original Wide-Angle 1.0x)</option>
                    </select>
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
            <div class="cam-section-card" id="separateExitSettings">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <div style="font-weight: 700; font-size: 13px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span>Exit Camera</span>
                        <span style="background: #ffedd5; color: #c2410c; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700;">Direction: OUT</span>
                    </div>
                    <span id="modalExitStatus" style="font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: #e2e8f0; color: #475569;">
                        Checking...
                    </span>
                </div>

                <div class="tapo-form-group">
                    <label style="display:block; margin-bottom:10px;"><input type="checkbox" id="cfgExitEnabled" checked> Enable exit camera (automatic OUT)</label>
                    <label for="cfgExitSource">Hardware Source (RTSP URL or USB Cam Index):</label>
                    <input
                        type="text"
                        id="cfgExitSource"
                        placeholder="1"
                    >
                    <div class="tapo-help-text">
                        For local USB webcam: <code>1</code> (or <code>0</code>) | For RTSP: <code>rtsp://username:password@IP:554/stream2</code>
                    </div>
                </div>

                <div class="tapo-form-group">
                    <label for="cfgExitZoom">Camera View / Lane Focus (Narrow Mode):</label>
                    <select id="cfgExitZoom" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; background:#fff;">
                        <option value="1.35" selected>🔍 Narrow (Lane Focus 1.35x - Recommended for Plates)</option>
                        <option value="1.5">🔬 Extra Narrow (Tight Lane Focus 1.5x)</option>
                        <option value="1.2">🔎 Moderate Narrow (1.2x)</option>
                        <option value="1.0">🌐 Wide (Full Original Wide-Angle 1.0x)</option>
                    </select>
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
     VISITOR INFORMATION INPUT MODAL
========================================================= -->
<div class="visitor-toast-container" id="visitorToastContainer"></div>

<div class="visitor-modal-overlay" id="visitorInfoModal">

    <div class="visitor-modal-box">

        <div class="visitor-modal-header">

            <div class="visitor-modal-title">
                <span>🚘 Visitor Information Input</span>
                <span class="visitor-modal-badge" id="visitorModalBadge">Visitor</span>
            </div>

            <button
                type="button"
                class="tapo-modal-close"
                onclick="closeVisitorModal()"
                aria-label="Close"
            >
                &times;
            </button>

        </div>

        <form id="visitorInfoForm" onsubmit="saveVisitorInfo(event)">

            <div class="visitor-modal-body">

                <div class="visitor-form-error" id="visitorFormError"></div>

                <input type="hidden" id="visitorLogId" value="0">
                <input type="hidden" id="visitorPendingId" value="">
                <input type="hidden" id="visitorIsAutoDetected" value="0">

                <div class="visitor-form-group">

                    <label for="visitorPlateInput">Plate Number</label>

                    <input
                        type="text"
                        id="visitorPlateInput"
                        name="plate_number"
                        placeholder="e.g. NBC 1234"
                        style="text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;"
                        required
                    >

                </div>

                <div class="visitor-form-group">

                    <label for="visitorVehicleTypeInput">Vehicle Type</label>

                    <select id="visitorVehicleTypeInput" name="vehicle_type" required>
                        <option value="Car">Car</option>
                        <option value="Motorcycle">Motorcycle</option>
                        <option value="Truck">Truck</option>
                        <option value="Tricycle">Tricycle</option>
                        <option value="E-Bike">E-Bike</option>
                        <option value="Bike">Bike</option>
                    </select>

                </div>

                <div class="visitor-form-group" style="margin-bottom: 4px;">

                    <label for="visitorPurposeInput">Purpose &amp; Allowed Time</label>

                    <select id="visitorPurposeInput" name="purpose" onchange="onVisitorPurposeChange()" required>
                        <option value="" disabled selected>-- Select Purpose &amp; Time Limit --</option>
                        <option value="Drop - off/on - 15 Mins">Drop - off/on - 15 Mins</option>
                        <option value="Delivery - 20 Mins">Delivery - 20 Mins</option>
                        <option value="Service Maintenance - 8 Hours">Service Maintenance - 8 Hours</option>
                        <option value="Birthday - 12 Hours">Birthday - 12 Hours</option>
                        <option value="Visiting Resident - 12 Hours">Visiting Resident - 12 Hours</option>
                        <option value="__CUSTOM__">⏱️ Custom Duration / Other...</option>
                    </select>

                    <div class="visitor-purpose-chips">
                        <span class="visitor-purpose-chip" onclick="setQuickPurpose('Drop - off/on - 15 Mins')">Drop - off/on - 15 Mins</span>
                        <span class="visitor-purpose-chip" onclick="setQuickPurpose('Delivery - 20 Mins')">Delivery - 20 Mins</span>
                        <span class="visitor-purpose-chip" onclick="setQuickPurpose('Service Maintenance - 8 Hours')">Service Maintenance - 8 Hours</span>
                        <span class="visitor-purpose-chip" onclick="setQuickPurpose('Birthday - 12 Hours')">Birthday - 12 Hours</span>
                        <span class="visitor-purpose-chip" onclick="setQuickPurpose('Visiting Resident - 12 Hours')">Visiting Resident - 12 Hours</span>
                        <span class="visitor-purpose-chip" onclick="setQuickPurpose('__CUSTOM__')" style="background:#eff6ff; color:#1d4ed8; border-color:#93c5fd;">⏱️ Custom Timer</span>
                    </div>

                    <!-- Custom Purpose & Duration Input (visible when Custom Duration is selected) -->
                    <div id="customDurationFields" style="display:none; margin-top:10px; padding:10px 12px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:10px;">
                        <div style="margin-bottom:8px;">
                            <label for="visitorCustomPurposeInput" style="font-size:11px; color:#334155; font-weight:700; display:block; margin-bottom:4px;">Visit Purpose Label</label>
                            <input type="text" id="visitorCustomPurposeInput" placeholder="e.g. Pickup, Inquiry, Contractor..." style="width:100%; padding:7px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:12px;">
                        </div>
                        <div>
                            <label style="font-size:11px; color:#334155; font-weight:700; display:block; margin-bottom:4px;">Allowed Stay Duration</label>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <input type="number" id="visitorCustomDurationVal" min="1" max="1440" value="30" oninput="updateModalSchedulePreview()" style="width:90px; padding:7px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:13px; font-weight:700;">
                                <select id="visitorCustomDurationUnit" onchange="updateModalSchedulePreview()" style="flex:1; padding:7px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:12px; background:#fff;">
                                    <option value="minutes" selected>Minutes</option>
                                    <option value="hours">Hours</option>
                                </select>
                            </div>
                            <div style="display:flex; gap:5px; flex-wrap:wrap; margin-top:7px;">
                                <span class="visitor-purpose-chip" onclick="setQuickCustomMinutes(5)">5m</span>
                                <span class="visitor-purpose-chip" onclick="setQuickCustomMinutes(10)">10m</span>
                                <span class="visitor-purpose-chip" onclick="setQuickCustomMinutes(30)">30m</span>
                                <span class="visitor-purpose-chip" onclick="setQuickCustomMinutes(60)">1h</span>
                                <span class="visitor-purpose-chip" onclick="setQuickCustomMinutes(120)">2h</span>
                                <span class="visitor-purpose-chip" onclick="setQuickCustomMinutes(240)">4h</span>
                            </div>
                        </div>
                    </div>

                    <!-- Live Reminder & Overstay Alarm Schedule Preview -->
                    <div id="visitorSchedulePreview" style="display:none; margin-top:10px; padding:9px 12px; background:#fffbeb; border:1px solid #fde68a; border-left:4px solid #f59e0b; border-radius:8px; font-size:11.5px; color:#78350f; line-height:1.45;">
                    </div>

                </div>

                <!-- =============================================
                     VISITOR ID CONSENT & IDENTIFICATION (OPTIONAL)
                ============================================== -->
                <div class="visitor-form-group visitor-consent-section" style="margin-top: 14px; padding-top: 12px; border-top: 1px solid #e2e8f0;">

                    <label for="visitorConsentCheckbox" style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0; cursor:pointer; user-select:none;">
                        <span style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:#1e293b;">
                            <input
                                type="checkbox"
                                id="visitorConsentCheckbox"
                                name="visitor_consent"
                                value="1"
                                onchange="toggleVisitorConsent()"
                                style="width:17px; height:17px; cursor:pointer; accent-color:#0284c7;"
                            >
                            <span>Visitor consents to give ID</span>
                        </span>
                        <span style="font-size:11px; font-weight:500; color:#64748b; background:#f1f5f9; padding:2px 7px; border-radius:10px;">Optional</span>
                    </label>

                    <!-- ID Fill Box (visible only when consent is checked) -->
                    <div id="visitorIdFillBox" style="display:none; margin-top:10px; padding:12px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:10px; transition: all 0.2s ease;">

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px;">
                            <div>
                                <label for="visitorIdTypeInput" style="font-size:11.5px; color:#334155; font-weight:700; display:block; margin-bottom:4px;">ID Type</label>
                                <select id="visitorIdTypeInput" name="id_type" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:12.5px; background:#ffffff;">
                                    <option value="" disabled selected>-- Select ID --</option>
                                    <option value="PhilHealth">PhilHealth</option>
                                    <option value="National ID">National ID</option>
                                    <option value="SSS">SSS</option>
                                    <option value="Postal">Postal</option>
                                    <option value="PWD">PWD</option>
                                    <option value="Senior Citizen">Senior Citizen</option>
                                    <option value="Driver's License">Driver's License</option>
                                    <option value="TIN">TIN</option>
                                    <option value="Pag-Ibig">Pag-Ibig</option>
                                </select>
                            </div>

                            <div>
                                <label for="visitorIdNumberInput" style="font-size:11.5px; color:#334155; font-weight:700; display:block; margin-bottom:4px;">ID Number</label>
                                <input
                                    type="text"
                                    id="visitorIdNumberInput"
                                    name="id_number"
                                    placeholder="e.g. 12-3456789-0"
                                    style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:12.5px; background:#ffffff;"
                                >
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                            <div>
                                <label for="visitorNameInput" style="font-size:11.5px; color:#334155; font-weight:700; display:block; margin-bottom:4px;">Name</label>
                                <input
                                    type="text"
                                    id="visitorNameInput"
                                    name="visitor_name"
                                    placeholder="Visitor Full Name"
                                    style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:12.5px; background:#ffffff;"
                                >
                            </div>

                            <div>
                                <label for="visitorAddressInput" style="font-size:11.5px; color:#334155; font-weight:700; display:block; margin-bottom:4px;">Address</label>
                                <input
                                    type="text"
                                    id="visitorAddressInput"
                                    name="address_to_visit"
                                    placeholder="House / Unit / Address"
                                    style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:12.5px; background:#ffffff;"
                                >
                            </div>
                        </div>

                    </div>

                </div>

            </div>

            <div class="visitor-modal-footer">

                <button
                    type="button"
                    class="btn-tapo-cancel"
                    onclick="closeVisitorModal()"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn-tapo-save"
                    id="btnSaveVisitorInfo"
                >
                    Save Visitor Info
                </button>

            </div>

        </form>

    </div>

</div>

<!-- =========================================================
     VISITOR EXIT / DEPARTURE DETAILS MODAL (EXIT ONLY)
========================================================= -->
<div class="visitor-modal-overlay" id="visitorExitModal">

    <div class="visitor-modal-box" style="max-width: 480px;">

        <div class="visitor-modal-header" style="background: #fef2f2; border-bottom: 1px solid #fecaca;">

            <div class="visitor-modal-title" style="color: #991b1b;">
                <span>🚗 Visitor Departure (Exit Cam)</span>
                <span class="dir-badge dir-badge-out" style="font-size:11px; padding:3px 10px;">🔴 OUT</span>
            </div>

            <button
                type="button"
                class="tapo-modal-close"
                onclick="closeVisitorExitModal()"
                aria-label="Close"
            >
                &times;
            </button>

        </div>

        <div class="visitor-modal-body">

            <div style="background:#fef2f2; border-left:4px solid #dc2626; padding:12px; border-radius:6px; margin-bottom:14px;">
                <div style="font-weight:700; color:#991b1b; font-size:14px; margin-bottom:4px;">Vehicle Has Exited Premises</div>
                <div style="font-size:12px; color:#7f1d1d;">This visitor vehicle was recorded at Exit Camera (192.168.1.19). It has completed its visit and departed.</div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:14px;">
                <div class="cam-section-card" style="padding:10px; margin-bottom:0;">
                    <div style="font-size:11px; color:#6b7280; font-weight:600;">Plate Number</div>
                    <div id="exitModalPlate" style="font-size:16px; font-weight:800; color:#111827; letter-spacing:0.5px;">-</div>
                </div>
                <div class="cam-section-card" style="padding:10px; margin-bottom:0;">
                    <div style="font-size:11px; color:#6b7280; font-weight:600;">Vehicle Type</div>
                    <div id="exitModalType" style="font-size:16px; font-weight:700; color:#111827;">-</div>
                </div>
            </div>

            <div class="cam-section-card" style="padding:10px; margin-bottom:14px;">
                <div style="font-size:11px; color:#6b7280; font-weight:600;">Recorded Visit Purpose</div>
                <div id="exitModalPurpose" style="font-size:13px; font-weight:600; color:#374151; margin-top:3px;">None</div>
            </div>

            <div class="cam-section-card" style="padding:10px; margin-bottom:0; background:#f0fdf4; border-color:#bbf7d0;">
                <div style="font-size:11px; color:#15803d; font-weight:600;">Session Status</div>
                <div style="font-size:13px; font-weight:700; color:#15803d; margin-top:2px;">Closed / Completed in Database</div>
            </div>

        </div>

        <div class="visitor-modal-footer">

            <button
                type="button"
                class="btn-tapo-cancel"
                onclick="closeVisitorExitModal()"
                style="width: 100%; text-align: center; padding: 10px;"
            >
                Close Departure Details
            </button>

        </div>

    </div>

</div>




<script>

/* =========================================================
   VEHICLE TYPES (mirrors SECSYS_VEHICLE_TYPES in app/system_config.php)
========================================================= */
const SECSYS_VEHICLE_TYPES = <?php echo json_encode(SECSYS_VEHICLE_TYPES, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function normalizeVehicleTypeJs(value) {
    const v = String(value || "").trim().toLowerCase();
    const match = SECSYS_VEHICLE_TYPES.find(t => t.toLowerCase() === v);
    return match || "Car";
}

/* =========================================================
   LIVE CLOCK
========================================================= */

/* Live clocks (Internet + System) are driven by assets/js/dual_clock.js */

/* =========================================================
   GUARD HEARTBEAT
   Periodically refresh login_status and last_seen so the
   security information page always sees this guard as Active
   while the browser tab is open.
========================================================= */

function sendHeartbeat() {

    const formData = new FormData();

    formData.append("action", "heartbeat");

    fetch(window.location.pathname, {

        method: "POST",

        body: formData

    })
    .then(function (response) {
        return response.json();
    })
    .then(function (data) {
        /* No action needed; only used to keep the
           guard marked as Active/last_seen current. */
    })
    .catch(function () {
        /* Silently ignore transient network errors. */
    });

}

/* Send on load, then every 25 seconds. */
sendHeartbeat();

setInterval(sendHeartbeat, 25000);






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
   STACKED ACTIVITY CARDS (MAX 5) & VISITOR INPUT FORM
========================================================= */

const MAX_STACK_ITEMS = 5;
let lastSeenLogId = <?php echo (int)$latestLogIdToday; ?>;
const INITIAL_TODAY_LOGS = <?php echo json_encode($initialLogsToday ?? []); ?>;
const promptedVisitorLogIds = new Set();
const handledPendingVisitorIds = new Set();
let pendingVisitorModalQueue = [];

function escapeHtmlText(str) {
    const div = document.createElement("div");
    div.textContent = str == null ? "" : String(str);
    return div.innerHTML;
}

function playVisitorNotificationSound() {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = "sine";
        osc.frequency.setValueAtTime(880, ctx.currentTime);
        osc.frequency.setValueAtTime(1174.66, ctx.currentTime + 0.12);
        gain.gain.setValueAtTime(0.18, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.45);
    } catch (e) {}
}


function showVisitorExitNotification(plateNumber, vehicleType, purpose) {
    playVisitorNotificationSound();
    const container = document.getElementById("visitorToastContainer");
    if (!container) return;

    const toast = document.createElement("div");
    toast.className = "visitor-toast";
    toast.style.borderLeft = "4px solid #ef4444";
    toast.style.background = "#fff5f5";

    toast.innerHTML =
        '<div>' +
            '<div class="visitor-toast-title" style="color:#b91c1c;">🔴 Visitor Vehicle Exited</div>' +
            '<div class="visitor-toast-body">' +
                '<div>Plate: <strong>' + escapeHtmlText(plateNumber) + '</strong> (' + escapeHtmlText(vehicleType || "Car") + ')</div>' +
                '<div style="color:#7f1d1d; font-size:11px; margin-top:2px;">Gate: Exit Camera (192.168.1.19)</div>' +
            '</div>' +
        '</div>' +
        '<button type="button" class="visitor-toast-close" aria-label="Dismiss">&times;</button>';

    const closeBtn = toast.querySelector(".visitor-toast-close");
    if (closeBtn) {
        closeBtn.onclick = function() {
            toast.remove();
        };
    }

    container.appendChild(toast);

    setTimeout(function() {
        if (toast.parentNode) {
            toast.remove();
        }
    }, 6000);
}

function openVisitorExitModalFromRow(rowEl) {
    if (!rowEl) return;
    const plate = rowEl.getAttribute("data-plate") || "";
    const vType = rowEl.getAttribute("data-vehicle-type") || "Car";
    const purpose = rowEl.getAttribute("data-purpose") || "None recorded";

    const modal = document.getElementById("visitorExitModal");
    const pEl = document.getElementById("exitModalPlate");
    const tEl = document.getElementById("exitModalType");
    const prpEl = document.getElementById("exitModalPurpose");

    if (pEl) pEl.innerText = plate;
    if (tEl) tEl.innerText = vType;
    if (prpEl) prpEl.innerText = purpose || "None recorded";

    if (modal) modal.classList.add("active");
}

function closeVisitorExitModal() {
    const modal = document.getElementById("visitorExitModal");
    if (modal) modal.classList.remove("active");
}

function showVisitorArrivalNotification(plateNumber, vehicleType, purpose) {
    playVisitorNotificationSound();
    const container = document.getElementById("visitorToastContainer");
    if (!container) return;

    const toast = document.createElement("div");
    toast.className = "visitor-toast";
    const purposeLine = purpose
        ? "<div>Purpose: <strong>" + escapeHtmlText(purpose) + "</strong></div>"
        : "<div>Please input visitor purpose &amp; allowed time.</div>";

    toast.innerHTML =
        '<div>' +
            '<div class="visitor-toast-title">🔔 Visitor Vehicle Arrived!</div>' +
            '<div class="visitor-toast-body">' +
                '<div>Plate: <strong>' + escapeHtmlText(plateNumber) + '</strong> (' + escapeHtmlText(vehicleType || "Car") + ')</div>' +
                purposeLine +
            '</div>' +
        '</div>' +
        '<button type="button" class="visitor-toast-close" aria-label="Dismiss">&times;</button>';

    const closeBtn = toast.querySelector(".visitor-toast-close");
    if (closeBtn) {
        closeBtn.onclick = function() {
            toast.remove();
        };
    }

    container.appendChild(toast);

    setTimeout(function() {
        if (toast.parentNode) {
            toast.remove();
        }
    }, 8000);
}

function toggleVisitorConsent() {
    const consentCb = document.getElementById("visitorConsentCheckbox");
    const fillBox = document.getElementById("visitorIdFillBox");
    const idTypeSelect = document.getElementById("visitorIdTypeInput");
    if (!consentCb || !fillBox) return;

    if (consentCb.checked) {
        fillBox.style.display = "block";
        if (idTypeSelect && !idTypeSelect.value) {
            idTypeSelect.focus();
        }
    } else {
        fillBox.style.display = "none";
    }
}

function openManualVisitorModal() {
    openVisitorModal("", "Car", "", 0, false, "", {
        isManual: true,
        consent: false,
        idType: "",
        idNumber: "",
        visitorName: "",
        address: ""
    });
}

function openVisitorModal(plateNumber, vehicleType, purpose, logId, isAutoDetected, pendingId, extraInfo) {

    const modal = document.getElementById("visitorInfoModal");
    const plateInput = document.getElementById("visitorPlateInput");
    const typeSelect = document.getElementById("visitorVehicleTypeInput");
    const purposeInput = document.getElementById("visitorPurposeInput");
    const logIdInput = document.getElementById("visitorLogId");
    const pendingIdInput = document.getElementById("visitorPendingId");
    const isAutoInput = document.getElementById("visitorIsAutoDetected");
    const badge = document.getElementById("visitorModalBadge");
    const errBox = document.getElementById("visitorFormError");

    const consentCb = document.getElementById("visitorConsentCheckbox");
    const fillBox = document.getElementById("visitorIdFillBox");
    const idTypeSelect = document.getElementById("visitorIdTypeInput");
    const idNumberInput = document.getElementById("visitorIdNumberInput");
    const nameInput = document.getElementById("visitorNameInput");
    const addressInput = document.getElementById("visitorAddressInput");

    extraInfo = extraInfo || {};

    if (!modal) return;

    if (errBox) {
        errBox.style.display = "none";
        errBox.textContent = "";
    }

    if (logIdInput) {
        logIdInput.value = logId ? String(logId) : "0";
    }
    if (pendingIdInput) {
        pendingIdInput.value = pendingId ? String(pendingId) : "";
    }
    if (isAutoInput) {
        isAutoInput.value = isAutoDetected ? "1" : "0";
    }
    if (plateInput) {
        plateInput.value = (plateNumber || "").toUpperCase();
    }
    if (typeSelect) {
        typeSelect.value = normalizeVehicleTypeJs(vehicleType);
    }
    const isReviewHint = Boolean(purpose && purpose.toLowerCase().startsWith("needs review:"));
    if (purposeInput) {
        const cleanPurp = (purpose && purpose.toLowerCase() !== "visitor entry" && !isReviewHint) ? purpose : "";
        if (cleanPurp) {
            let matched = false;
            for (let i = 0; i < purposeInput.options.length; i++) {
                if (purposeInput.options[i].value === cleanPurp) {
                    matched = true;
                    break;
                }
            }
            if (!matched) {
                const opt = document.createElement("option");
                opt.value = cleanPurp;
                opt.textContent = cleanPurp;
                purposeInput.insertBefore(opt, purposeInput.options[purposeInput.options.length - 1]);
            }
            purposeInput.value = cleanPurp;
        } else {
            purposeInput.selectedIndex = 0;
        }
    }
    onVisitorPurposeChange();

    // ID Consent & Info fields
    const hasConsent = Boolean(
        extraInfo.consent ||
        extraInfo.idType ||
        extraInfo.idNumber ||
        (extraInfo.visitorName && extraInfo.visitorName.toLowerCase() !== "visitor")
    );
    if (consentCb) {
        consentCb.checked = hasConsent;
    }
    if (fillBox) {
        fillBox.style.display = hasConsent ? "block" : "none";
    }
    if (idTypeSelect) {
        idTypeSelect.value = extraInfo.idType || "";
    }
    if (idNumberInput) {
        idNumberInput.value = extraInfo.idNumber || "";
    }
    if (nameInput) {
        nameInput.value = (extraInfo.visitorName && extraInfo.visitorName.toLowerCase() !== "visitor")
            ? extraInfo.visitorName
            : "";
    }
    if (addressInput) {
        addressInput.value = (extraInfo.address && extraInfo.address !== "-")
            ? extraInfo.address
            : "";
    }

    if (badge) {
        if (extraInfo.isManual) {
            badge.textContent = "Manual Visitor Entry";
        } else {
            badge.textContent = isReviewHint
                ? "Verify Plate Reading"
                : (isAutoDetected ? "New Visitor Detected" : "Visitor Entry");
        }
    }

    modal.classList.add("active");

    setTimeout(function () {
        if (plateInput && !plateInput.value.trim()) {
            plateInput.focus();
        } else if (purposeInput) {
            purposeInput.focus();
        }
    }, 60);
}

function openVisitorModalFromRow(rowEl) {

    if (!rowEl) return;
    const logId = parseInt(rowEl.getAttribute("data-log-id") || "0", 10);
    const plate = rowEl.getAttribute("data-plate") || "";
    const vType = rowEl.getAttribute("data-vehicle-type") || "Car";
    const purpose = rowEl.getAttribute("data-purpose") || "";
    const vName = rowEl.getAttribute("data-visitor-name") || "";
    const vAddr = rowEl.getAttribute("data-address") || "";
    const vIdType = rowEl.getAttribute("data-id-type") || "";
    const vIdNum = rowEl.getAttribute("data-id-number") || "";
    const hasConsent = Boolean(vIdType || vIdNum || (vName && vName.toLowerCase() !== "visitor"));

    openVisitorModal(plate, vType, purpose, logId, false, "", {
        consent: hasConsent,
        idType: vIdType,
        idNumber: vIdNum,
        visitorName: vName,
        address: vAddr
    });
}

function closeVisitorModal(wasSaved) {

    const modal = document.getElementById("visitorInfoModal");
    if (!modal || !modal.classList.contains("active")) return;

    const logIdInput = document.getElementById("visitorLogId");
    const pendingIdInput = document.getElementById("visitorPendingId");
    const isAutoInput = document.getElementById("visitorIsAutoDetected");
    const plateInput = document.getElementById("visitorPlateInput");

    const logId = logIdInput ? parseInt(logIdInput.value || "0", 10) : 0;
    const pendingId = pendingIdInput ? pendingIdInput.value.trim() : "";
    const isAuto = isAutoInput && isAutoInput.value === "1";
    const plateVal = plateInput ? plateInput.value.trim().toUpperCase() : "";

    const consentCb = document.getElementById("visitorConsentCheckbox");
    const fillBox = document.getElementById("visitorIdFillBox");
    const idTypeSelect = document.getElementById("visitorIdTypeInput");
    const idNumberInput = document.getElementById("visitorIdNumberInput");
    const nameInput = document.getElementById("visitorNameInput");
    const addressInput = document.getElementById("visitorAddressInput");

    if (consentCb) consentCb.checked = false;
    if (fillBox) fillBox.style.display = "none";
    if (idTypeSelect) idTypeSelect.value = "";
    if (idNumberInput) idNumberInput.value = "";
    if (nameInput) nameInput.value = "";
    if (addressInput) addressInput.value = "";

    if (logIdInput) logIdInput.value = "0";
    if (pendingIdInput) pendingIdInput.value = "";
    if (isAutoInput) isAutoInput.value = "0";

    modal.classList.remove("active");

    // If the guard cancelled/dismissed an auto-detected visitor form without saving,
    // discard the pending detection so the plate number is NEVER stored in the database.
    if (!wasSaved && (pendingId !== "" || (isAuto && logId > 0))) {
        const cancelData = new FormData();
        cancelData.append("action", "cancel_visitor_info");
        cancelData.append("pending_id", pendingId);
        cancelData.append("plate_number", plateVal);
        cancelData.append("log_id", String(logId));
        cancelData.append("is_auto_detected", isAuto ? "1" : "0");

        fetch(window.location.pathname, {
            method: "POST",
            body: cancelData
        }).catch(function () {});

        if (isAuto && logId > 0) {
            const visStack = document.getElementById("visitorActivityStack");
            if (visStack) {
                const rowEl = visStack.querySelector('.vehicle-item[data-log-id="' + logId + '"]');
                if (rowEl) rowEl.remove();
                if (visStack.querySelectorAll(".vehicle-item").length === 0 && !visStack.querySelector(".empty-activity")) {
                    const emptyDiv = document.createElement("div");
                    emptyDiv.className = "empty-activity";
                    emptyDiv.id = "visitorEmptyMsg";
                    emptyDiv.textContent = "No visitor vehicle captured today.";
                    visStack.appendChild(emptyDiv);
                }
            }
        }

        const toastBox = document.getElementById("visitorToastContainer");
        if (toastBox && plateVal) {
            const toasts = toastBox.querySelectorAll(".visitor-toast");
            toasts.forEach(function (t) {
                if (t.textContent && t.textContent.toUpperCase().indexOf(plateVal) !== -1) {
                    t.remove();
                }
            });
        }
    }

    if (pendingVisitorModalQueue.length > 0) {
        const nextPv = pendingVisitorModalQueue.shift();
        setTimeout(function () {
            openVisitorModal(
                nextPv.plate_number || "",
                nextPv.vehicle_type || "Car",
                nextPv.purpose || "",
                0,
                true,
                nextPv.pending_id || ""
            );
        }, 150);
    }
}

function getCustomDurationMinutesFromModal() {
    const valEl = document.getElementById("visitorCustomDurationVal");
    const unitEl = document.getElementById("visitorCustomDurationUnit");
    const rawVal = valEl ? parseInt(valEl.value || "0", 10) : 0;
    if (!rawVal || rawVal <= 0) return 0;
    const isHours = unitEl && unitEl.value === "hours";
    return Math.min(2880, isHours ? rawVal * 60 : rawVal);
}

function updateModalSchedulePreview() {
    const purposeInput = document.getElementById("visitorPurposeInput");
    const previewEl = document.getElementById("visitorSchedulePreview");
    if (!purposeInput || !previewEl) return;

    const val = purposeInput.value || "";
    if (!val) {
        previewEl.style.display = "none";
        previewEl.innerHTML = "";
        return;
    }

    const customMins = val === "__CUSTOM__" ? getCustomDurationMinutesFromModal() : 0;
    if (val === "__CUSTOM__" && customMins <= 0) {
        previewEl.style.display = "block";
        previewEl.innerHTML = "⏱️ Enter a stay duration above to arm automatic reminders &amp; overstay alarm.";
        return;
    }

    if (window.VisitorDurationAlarm && typeof window.VisitorDurationAlarm.getDurationScheduleInfo === "function") {
        const info = window.VisitorDurationAlarm.getDurationScheduleInfo(val, customMins);
        const now = new Date();
        const dueDate = new Date(now.getTime() + info.minutes * 60000);
        const dueStr = dueDate.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
        previewEl.style.display = "block";
        previewEl.innerHTML =
            "<strong>🔔 Armed Schedule (" + escapeHtmlText(info.durationLabel) + "):</strong> " +
            escapeHtmlText(info.scheduleText) +
            " &bull; <strong>Expected Out:</strong> ~" + escapeHtmlText(dueStr);
    } else {
        previewEl.style.display = "none";
    }
}

function onVisitorPurposeChange() {
    const purposeInput = document.getElementById("visitorPurposeInput");
    const customBox = document.getElementById("customDurationFields");
    if (customBox && purposeInput) {
        customBox.style.display = purposeInput.value === "__CUSTOM__" ? "block" : "none";
    }
    updateModalSchedulePreview();
}

function setQuickCustomMinutes(mins) {
    const valEl = document.getElementById("visitorCustomDurationVal");
    const unitEl = document.getElementById("visitorCustomDurationUnit");
    if (!valEl || !unitEl) return;
    if (mins >= 60 && mins % 60 === 0) {
        valEl.value = String(mins / 60);
        unitEl.value = "hours";
    } else {
        valEl.value = String(mins);
        unitEl.value = "minutes";
    }
    updateModalSchedulePreview();
}

function setQuickPurpose(purposeText) {

    const purposeInput = document.getElementById("visitorPurposeInput");
    if (purposeInput) {
        purposeInput.value = purposeText;
        onVisitorPurposeChange();
        if (purposeText === "__CUSTOM__") {
            const customPurp = document.getElementById("visitorCustomPurposeInput");
            if (customPurp) customPurp.focus();
        } else {
            purposeInput.focus();
        }
    }
}

/* Time shown on an activity row: "In 6:34 AM" on IN rows, "Out 6:35 AM" on OUT rows. */
function activityTimesHtml(isOut, timeLabel) {
    const label = isOut ? "Out" : "In";
    const time = String(timeLabel || "").trim() || "—";
    return '<div class="activity-times" title="Time ' + label + ': ' + escapeHtmlText(time) + '">' +
        '<span class="activity-time"><b>' + label + '</b> <span class="time-value">' + escapeHtmlText(time) + '</span></span>' +
        '</div>';
}

/* Update a row's time in place when the row is refreshed (e.g. IN turns into OUT). */
function updateActivityTimes(rowEl, logData, isOut) {
    if (!rowEl) return;
    const badgesBox = rowEl.querySelector(".vehicle-badges");
    if (!badgesBox) return;

    const newTime = String((isOut ? logData.time_out_label : logData.time_in_label) || "").trim();
    const box = rowEl.querySelector(".activity-times");
    const shownLabel = box ? ((box.querySelector("b") || {}).textContent || "") : "";
    const sameDirection = shownLabel === (isOut ? "Out" : "In");

    // Nothing new to show for the same direction: keep the time already displayed
    if (box && sameDirection && !newTime) return;

    const html = activityTimesHtml(isOut, newTime);
    if (box) {
        box.outerHTML = html;
    } else {
        badgesBox.insertAdjacentHTML("beforeend", html);
    }
}

function stackVehicleItem(logData) {

    if (!logData || !logData.plate_number) return;

    const category = String(logData.vehicle_category || "").toLowerCase();
    const isVisitor = category === "visitor";
    const stackContainer = document.getElementById(
        isVisitor ? "visitorActivityStack" : "registeredActivityStack"
    );

    if (!stackContainer) return;

    // Remove empty placeholder if present
    const emptyMsg = stackContainer.querySelector(".empty-activity");
    if (emptyMsg) {
        emptyMsg.remove();
    }

    const logId = parseInt(logData.id || "0", 10);
    const plate = String(logData.plate_number || "").trim().toUpperCase();
    const rawType = String(logData.vehicle_type || "").trim();
    const vType = normalizeVehicleTypeJs(rawType);
    const purpose = String(logData.purpose || "").trim();
    const direction = String(logData.direction || "IN").trim().toUpperCase();

    // If category transitioned (e.g. Visitor was registered), purge from opposite stack immediately
    const otherStack = document.getElementById(
        isVisitor ? "registeredActivityStack" : "visitorActivityStack"
    );
    if (otherStack && plate) {
        const itemInOther = otherStack.querySelector('.vehicle-item[data-plate="' + plate + '"]');
        if (itemInOther) {
            itemInOther.remove();
        }
    }

    // Check if an item with this logId or same plate at top already exists to update in-place
    let existingItem = null;
    if (logId > 0) {
        existingItem = stackContainer.querySelector('.vehicle-item[data-log-id="' + logId + '"]');
    }
    if (!existingItem && plate) {
        existingItem = stackContainer.querySelector('.vehicle-item[data-plate="' + plate + '"]');
    }

    const isOut = direction === "OUT";

    if (existingItem) {
        existingItem.setAttribute("data-plate", plate);
        existingItem.setAttribute("data-vehicle-type", vType);
        existingItem.setAttribute("data-purpose", purpose);
        existingItem.setAttribute("data-direction", direction);
        if (logData.visitor_name) existingItem.setAttribute("data-visitor-name", logData.visitor_name);
        if (logData.address_to_visit) existingItem.setAttribute("data-address", logData.address_to_visit);
        if (logData.id_type) existingItem.setAttribute("data-id-type", logData.id_type);
        if (logData.id_number) existingItem.setAttribute("data-id-number", logData.id_number);

        // Update entire row color based on IN vs OUT
        existingItem.classList.remove("row-in", "row-out");
        existingItem.classList.add(isOut ? "row-out" : "row-in");

        const plateSpan = existingItem.querySelector(".plate-text");
        if (plateSpan) {
            plateSpan.innerHTML = '<a href="vehicle_photo.php?plate=' + encodeURIComponent(plate) + (logId > 0 ? '&ids=' + logId : '') + '" class="secsys-plate-link" data-plate="' + escapeHtmlText(plate) + '" data-ids="' + (logId > 0 ? logId : '') + '" onclick="return window.secsysOpenPlateCaptureModal ? window.secsysOpenPlateCaptureModal(event, this) : true;" title="Click to view YOLOv10 captured license plate &amp; vehicle image">' + escapeHtmlText(plate) + '</a>';
        }

        const subContainer = existingItem.querySelector(".vehicle-direction");
        if (subContainer) {
            const dirHtml = isOut
                ? '<span class="dir-badge dir-badge-out">🔴 OUT</span>'
                : '<span class="dir-badge dir-badge-in">🟢 IN</span>';
            const typeStr = isVisitor
                ? escapeHtmlText(vType)
                : ((rawType && rawType.toLowerCase() !== "unknown") ? escapeHtmlText(rawType) : "");
            subContainer.innerHTML = (typeStr ? '<span class="type-text">' + typeStr + '</span> ' : '') + dirHtml;
        }

        if (isVisitor) {
            const badgesBox = existingItem.querySelector(".vehicle-badges");
            let timerBadge = existingItem.querySelector("[data-vda-plate]");
            const pill = existingItem.querySelector(".purpose-pill");
            if (isOut) {
                existingItem.classList.remove("visitor-item-row");
                existingItem.classList.add("visitor-item-out");
                existingItem.onclick = function() { openVisitorExitModalFromRow(existingItem); };
                existingItem.setAttribute("title", "Visitor vehicle exited (Click to view exit details)");
                if (timerBadge) timerBadge.remove();
                if (pill) {
                    pill.className = "purpose-pill";
                    pill.style.background = "#fee2e2";
                    pill.style.color = "#991b1b";
                    pill.style.border = "1px solid #f87171";
                    pill.textContent = purpose !== "" ? purpose : "Exited";
                }
            } else {
                existingItem.classList.add("visitor-item-row");
                existingItem.classList.remove("visitor-item-out");
                existingItem.onclick = function() { openVisitorModalFromRow(existingItem); };
                existingItem.setAttribute("title", "Click to enter or update visitor information");
                if (!timerBadge && badgesBox) {
                    timerBadge = document.createElement("span");
                    timerBadge.className = "vda-inline-timer";
                    timerBadge.setAttribute("data-vda-compact", "1");
                    timerBadge.style.display = "none";
                    badgesBox.insertBefore(timerBadge, badgesBox.firstChild);
                }
                if (timerBadge) {
                    timerBadge.setAttribute("data-vda-plate", plate);
                    timerBadge.setAttribute("data-vda-status", "IN");
                }
                if (pill) {
                    pill.style.background = "";
                    pill.style.color = "";
                    pill.style.border = "";
                    if (purpose !== "" && purpose.toLowerCase() !== "visitor entry") {
                        pill.className = "purpose-pill";
                        pill.textContent = purpose;
                        pill.title = purpose;
                    } else {
                        pill.className = "purpose-pill needs-info";
                        pill.textContent = "Input Info";
                        pill.removeAttribute("title");
                    }
                }
            }
            if (window.VisitorDurationAlarm) {
                window.VisitorDurationAlarm.updateInlineTimerBadges();
            }
        }
        updateActivityTimes(existingItem, logData, isOut);
        return;
    }

    // Build new item element and prepend to top of stack
    const itemDiv = document.createElement("div");
    const rowColorClass = isOut ? "row-out" : "row-in";
    itemDiv.className = isVisitor
        ? ("vehicle-item " + rowColorClass + (isOut ? " visitor-item-out" : " visitor-item-row"))
        : ("vehicle-item " + rowColorClass);
    itemDiv.setAttribute("data-direction", direction);
    if (logId > 0) {
        itemDiv.setAttribute("data-log-id", String(logId));
    }
    itemDiv.setAttribute("data-plate", plate);

    if (isVisitor) {
        itemDiv.setAttribute("data-vehicle-type", vType);
        itemDiv.setAttribute("data-purpose", purpose);
        if (logData.visitor_name) itemDiv.setAttribute("data-visitor-name", logData.visitor_name);
        if (logData.address_to_visit) itemDiv.setAttribute("data-address", logData.address_to_visit);
        if (logData.id_type) itemDiv.setAttribute("data-id-type", logData.id_type);
        if (logData.id_number) itemDiv.setAttribute("data-id-number", logData.id_number);

        let pillHtml = "";
        let timerHtml = "";
        if (isOut) {
            itemDiv.setAttribute("title", "Visitor vehicle exited (Click to view exit details)");
            itemDiv.onclick = function () {
                openVisitorExitModalFromRow(itemDiv);
            };
            const label = purpose !== "" ? escapeHtmlText(purpose) : "Exited";
            pillHtml = '<span class="purpose-pill" style="background:#fee2e2; color:#991b1b; border:1px solid #f87171;">' + label + '</span>';
        } else {
            itemDiv.setAttribute("title", "Click to enter or update visitor information");
            itemDiv.onclick = function () {
                openVisitorModalFromRow(itemDiv);
            };
            timerHtml = '<span class="vda-inline-timer" data-vda-plate="' + escapeHtmlText(plate) + '" data-vda-status="IN" data-vda-compact="1" style="display:none;"></span>';
            pillHtml = (purpose !== "" && purpose.toLowerCase() !== "visitor entry")
                ? '<span class="purpose-pill" title="' + escapeHtmlText(purpose) + '">' + escapeHtmlText(purpose) + '</span>'
                : '<span class="purpose-pill needs-info">Input Info</span>';
        }

        const clickablePlateLink = '<a href="vehicle_photo.php?plate=' + encodeURIComponent(plate) + (logId > 0 ? '&ids=' + logId : '') + '" class="secsys-plate-link" data-plate="' + escapeHtmlText(plate) + '" data-ids="' + (logId > 0 ? logId : '') + '" onclick="return window.secsysOpenPlateCaptureModal ? window.secsysOpenPlateCaptureModal(event, this) : true;" title="Click to view YOLOv10 captured license plate &amp; vehicle image">' + escapeHtmlText(plate) + '</a>';

        itemDiv.innerHTML =
            '<div class="vehicle-info">' +
                '<div class="vehicle-plate"><span class="plate-text">' + clickablePlateLink + '</span></div>' +
                '<div class="vehicle-direction">' +
                    '<span class="type-text">' + escapeHtmlText(vType) + '</span> ' +
                    (isOut ? '<span class="dir-badge dir-badge-out">🔴 OUT</span>' : '<span class="dir-badge dir-badge-in">🟢 IN</span>') +
                '</div>' +
            '</div>' +
            '<div class="vehicle-badges">' +
                timerHtml +
                pillHtml +
                activityTimesHtml(isOut, isOut ? logData.time_out_label : logData.time_in_label) +
            '</div>';
    } else {
        const clickablePlateLink = '<a href="vehicle_photo.php?plate=' + encodeURIComponent(plate) + (logId > 0 ? '&ids=' + logId : '') + '" class="secsys-plate-link" data-plate="' + escapeHtmlText(plate) + '" data-ids="' + (logId > 0 ? logId : '') + '" onclick="return window.secsysOpenPlateCaptureModal ? window.secsysOpenPlateCaptureModal(event, this) : true;" title="Click to view YOLOv10 captured license plate &amp; vehicle image">' + escapeHtmlText(plate) + '</a>';

        itemDiv.innerHTML =
            '<div class="vehicle-info">' +
                '<div class="vehicle-plate"><span class="plate-text">' + clickablePlateLink + '</span></div>' +
                '<div class="vehicle-direction">' +
                    ((rawType && rawType.toLowerCase() !== "unknown") ? '<span class="type-text">' + escapeHtmlText(rawType) + '</span> ' : '') +
                    (isOut ? '<span class="dir-badge dir-badge-out">🔴 OUT</span>' : '<span class="dir-badge dir-badge-in">🟢 IN</span>') +
                '</div>' +
            '</div>' +
            '<div class="vehicle-badges">' +
                activityTimesHtml(isOut, isOut ? logData.time_out_label : logData.time_in_label) +
            '</div>';
    }

    // Prepend at the top of the stack
    stackContainer.insertBefore(itemDiv, stackContainer.firstChild);

    // Strictly cap displayed plate numbers to MAX_STACK_ITEMS (5)
    const items = stackContainer.querySelectorAll(".vehicle-item");
    if (items.length > MAX_STACK_ITEMS) {
        for (let i = MAX_STACK_ITEMS; i < items.length; i++) {
            items[i].remove();
        }
    }
}

async function saveVisitorInfo(e) {

    e.preventDefault();

    const plateInput = document.getElementById("visitorPlateInput");
    const typeSelect = document.getElementById("visitorVehicleTypeInput");
    const purposeInput = document.getElementById("visitorPurposeInput");
    const logIdInput = document.getElementById("visitorLogId");
    const pendingIdInput = document.getElementById("visitorPendingId");
    const errBox = document.getElementById("visitorFormError");
    const saveBtn = document.getElementById("btnSaveVisitorInfo");

    const plate = (plateInput ? plateInput.value.trim().toUpperCase() : "");
    const vType = (typeSelect ? typeSelect.value : "Car");
    const purpose = (purposeInput ? purposeInput.value.trim() : "");
    const logId = (logIdInput ? parseInt(logIdInput.value || "0", 10) : 0);
    const pendingId = (pendingIdInput ? pendingIdInput.value.trim() : "");
    const wasNewArrival = (logId <= 0);
    const customPurposeEl = document.getElementById("visitorCustomPurposeInput");
    const customPurpose = customPurposeEl ? customPurposeEl.value.trim() : "";
    const customMins = purpose === "__CUSTOM__" ? getCustomDurationMinutesFromModal() : 0;

    const consentCb = document.getElementById("visitorConsentCheckbox");
    const idTypeSelect = document.getElementById("visitorIdTypeInput");
    const idNumberInput = document.getElementById("visitorIdNumberInput");
    const nameInput = document.getElementById("visitorNameInput");
    const addressInput = document.getElementById("visitorAddressInput");

    const consentChecked = Boolean(consentCb && consentCb.checked);
    const idType = (idTypeSelect ? idTypeSelect.value.trim() : "");
    const idNumber = (idNumberInput ? idNumberInput.value.trim() : "");
    const visitorName = (nameInput ? nameInput.value.trim() : "");
    const addressToVisit = (addressInput ? addressInput.value.trim() : "");

    if (!plate || !purpose) {
        if (errBox) {
            errBox.textContent = "Please fill in the Plate Number, Vehicle Type, and Purpose.";
            errBox.style.display = "block";
        }
        return;
    }

    if (consentChecked) {
        if (!idType) {
            if (errBox) {
                errBox.textContent = "Please select an ID Type for the visitor.";
                errBox.style.display = "block";
            }
            if (idTypeSelect) idTypeSelect.focus();
            return;
        }
        if (!idNumber) {
            if (errBox) {
                errBox.textContent = "Please enter the ID Number.";
                errBox.style.display = "block";
            }
            if (idNumberInput) idNumberInput.focus();
            return;
        }
        if (!visitorName) {
            if (errBox) {
                errBox.textContent = "Please enter the visitor's Name.";
                errBox.style.display = "block";
            }
            if (nameInput) nameInput.focus();
            return;
        }
    }

    if (purpose === "__CUSTOM__" && customMins <= 0) {
        if (errBox) {
            errBox.textContent = "Please enter a valid custom stay duration (at least 1 minute).";
            errBox.style.display = "block";
        }
        return;
    }

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = "Saving...";
    }

    try {
        const formData = new FormData();
        formData.append("action", "save_visitor_info");
        formData.append("plate_number", plate);
        formData.append("vehicle_type", vType);
        formData.append("purpose", purpose);
        if (purpose === "__CUSTOM__") {
            formData.append("custom_minutes", String(customMins));
            formData.append("custom_purpose", customPurpose);
        }
        formData.append("log_id", String(logId));
        formData.append("pending_id", pendingId);
        formData.append("visitor_consent", consentChecked ? "1" : "0");
        if (consentChecked) {
            formData.append("id_type", idType);
            formData.append("id_number", idNumber);
            formData.append("visitor_name", visitorName);
            formData.append("address_to_visit", addressToVisit);
        }

        const res = await fetch(window.location.pathname, {
            method: "POST",
            body: formData
        });
        const data = await res.json();

        if (!data.success) {
            if (errBox) {
                errBox.textContent = data.message || "Failed to save visitor info.";
                errBox.style.display = "block";
            }
        } else {
            const savedLog = data.log || {};
            const resolvedId = parseInt(savedLog.id || data.log_id || logId || "0", 10);
            if (resolvedId > lastSeenLogId) {
                lastSeenLogId = resolvedId;
            }
            if (resolvedId > 0) {
                promptedVisitorLogIds.add(resolvedId);
            }

            const finalPlate = savedLog.plate_number || data.plate_number || plate;
            const finalType = savedLog.vehicle_type || data.vehicle_type || vType;
            const finalPurpose = savedLog.purpose || data.purpose || purpose;
            const finalDir = savedLog.direction || data.direction || "IN";
            const finalName = savedLog.visitor_name || (consentChecked ? visitorName : "Visitor");
            const finalAddr = savedLog.address_to_visit || (consentChecked ? addressToVisit : "-");
            const finalIdType = savedLog.id_type || (consentChecked ? idType : "");
            const finalIdNum = savedLog.id_number || (consentChecked ? idNumber : "");

            stackVehicleItem({
                id: resolvedId,
                plate_number: finalPlate,
                vehicle_type: finalType,
                vehicle_category: "Visitor",
                purpose: finalPurpose,
                direction: finalDir,
                visitor_name: finalName,
                address_to_visit: finalAddr,
                id_type: finalIdType,
                id_number: finalIdNum,
                time_in_label: savedLog.time_in_label || "",
                time_out_label: savedLog.time_out_label || ""
            });

            const visTotalEl = document.getElementById("totalVisitorsCount");
            if (visTotalEl && typeof data.total_visitors !== "undefined") {
                visTotalEl.textContent = String(data.total_visitors);
            }

            if (wasNewArrival) {
                const nameSnippet = (finalName && finalName.toLowerCase() !== "visitor") ? " (" + finalName + ")" : "";
                addNotification(
                    "IN",
                    "🟢 Vehicle Entered: " + finalPlate + nameSnippet,
                    "Visitor • " + finalType + (finalPurpose ? " • " + finalPurpose : "") + " (Entrance Cam)",
                    savedLog
                );
            }

            if (window.VisitorDurationAlarm) {
                window.VisitorDurationAlarm.ingestSnapshot(data, true);
            }

            closeVisitorModal(true);
            pollLiveCaptures();
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = "Network error while saving visitor information.";
            errBox.style.display = "block";
        }
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = "Save Visitor Info";
        }
    }
}

let isPollingLiveCaptures = false;
async function pollLiveCaptures() {
    if (isPollingLiveCaptures) return;
    isPollingLiveCaptures = true;

    try {
        const url = window.location.pathname + "?action=live_feed&since_id=" + encodeURIComponent(lastSeenLogId);
        const res = await fetch(url, { cache: "no-store" });
        const data = await res.json();

        if (!data || !data.success) return;

        // Feed second-precision per-duration reminders & overstay alarms to shared controller
        if (window.VisitorDurationAlarm) {
            window.VisitorDurationAlarm.ingestSnapshot(data, true);
        } else {
            handleOverstayReminders(data.overdue_visitors || [], data.due_soon_visitors || []);
        }

        // Update total counts smoothly with animation
        const visTotalEl = document.getElementById("totalVisitorsCount");
        const regTotalEl = document.getElementById("totalRegisteredCount");
        if (visTotalEl && typeof data.total_visitors !== "undefined") {
            const nextVis = String(data.total_visitors);
            if (visTotalEl.textContent.trim() !== nextVis) {
                visTotalEl.textContent = nextVis;
                visTotalEl.classList.remove("stat-number-updated");
                void visTotalEl.offsetWidth; // Force CSS animation reflow
                visTotalEl.classList.add("stat-number-updated");
            }
        }
        if (regTotalEl && typeof data.total_registered !== "undefined") {
            const nextReg = String(data.total_registered);
            if (regTotalEl.textContent.trim() !== nextReg) {
                regTotalEl.textContent = nextReg;
                regTotalEl.classList.remove("stat-number-updated");
                void regTotalEl.offsetWidth; // Force CSS animation reflow
                regTotalEl.classList.add("stat-number-updated");
            }
        }

        // Live update vehicleChart without browser reload
        if (typeof vehicleChart !== "undefined" && vehicleChart && data.chart && Array.isArray(data.chart.data)) {
            let chartChanged = false;
            data.chart.data.forEach((series, idx) => {
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

        // Process pending visitor arrivals (awaiting guard form confirmation before DB insert)
        if (Array.isArray(data.pending_visitors) && data.pending_visitors.length > 0) {
            for (let p = 0; p < data.pending_visitors.length; p++) {
                const pv = data.pending_visitors[p];
                const pvId = String(pv.pending_id || "");
                if (!pvId || handledPendingVisitorIds.has(pvId)) {
                    continue;
                }
                handledPendingVisitorIds.add(pvId);

                showVisitorArrivalNotification(
                    pv.plate_number || "",
                    pv.vehicle_type || "Car",
                    pv.purpose || ""
                );

                const modal = document.getElementById("visitorInfoModal");
                const modalOpen = modal && modal.classList.contains("active");
                if (!modalOpen) {
                    openVisitorModal(
                        pv.plate_number || "",
                        pv.vehicle_type || "Car",
                        pv.purpose || "",
                        0,
                        true,
                        pvId
                    );
                } else {
                    pendingVisitorModalQueue.push(pv);
                }
            }
        }

        if (Array.isArray(data.new_logs) && data.new_logs.length > 0) {
            for (let i = 0; i < data.new_logs.length; i++) {
                const item = data.new_logs[i];
                const id = parseInt(item.id || "0", 10);
                if (id > lastSeenLogId) {
                    lastSeenLogId = id;
                }

                // Stack plate number onto Visitor or Registered card (max 5)
                stackVehicleItem(item);

                // Add to Notification Center history
                const itemDir = String(item.direction || "IN").toUpperCase();
                const itemCat = String(item.vehicle_category || "Visitor");
                const itemPlate = String(item.plate_number || "Unknown");
                const itemType = String(item.vehicle_type || "Car");
                const itemPurpose = String(item.purpose || "");

                if (itemDir === "OUT") {
                    addNotification(
                        "OUT",
                        "🔴 Vehicle Exited: " + itemPlate,
                        itemCat + " • " + itemType + (itemPurpose ? " • " + itemPurpose : "") + " (Exit Cam)",
                        item
                    );
                } else {
                    addNotification(
                        "IN",
                        "🟢 Vehicle Entered: " + itemPlate,
                        itemCat + " • " + itemType + (itemPurpose ? " • " + itemPurpose : "") + " (Entrance Cam)",
                        item
                    );
                }

                // Show arrival notification & automatically activate Visitor Information Form when a Visitor arrives
                const isVisitor = String(item.vehicle_category || "").toLowerCase() === "visitor";
                const modal = document.getElementById("visitorInfoModal");
                const modalOpen = modal && modal.classList.contains("active");

                const isExiting = String(item.direction || "IN").toUpperCase() === "OUT";
                if (isVisitor && !promptedVisitorLogIds.has(id)) {
                    promptedVisitorLogIds.add(id);

                    if (isExiting) {
                        // Exiting visitor: Show Exit departure toast. DO NOT open arrival entry form!
                        showVisitorExitNotification(
                            item.plate_number || "",
                            item.vehicle_type || "Car",
                            item.purpose || ""
                        );
                    } else {
                        const hasConfirmedPurpose = itemPurpose !== ""
                            && itemPurpose.toLowerCase() !== "visitor entry"
                            && !itemPurpose.toLowerCase().startsWith("needs review:");
                        // Arriving visitor without confirmed info: Show Arrival toast & open the entry information form
                        if (!hasConfirmedPurpose) {
                            showVisitorArrivalNotification(
                                item.plate_number || "",
                                item.vehicle_type || "Car",
                                item.purpose || ""
                            );
                            if (!modalOpen) {
                                openVisitorModal(
                                    item.plate_number || "",
                                    item.vehicle_type || "Car",
                                    item.purpose || "",
                                    id,
                                    true,
                                    ""
                                );
                            }
                        }
                    }
                }
            }
        }
    } catch (e) {
        // Ignore transient network errors
    } finally {
        isPollingLiveCaptures = false;
    }
}

setInterval(pollLiveCaptures, 1500);


/* =========================================================
   DUAL CAMERA (ENTRANCE & EXIT) CONTROLLER
========================================================= */

const LOCAL_HOST = (window.location && window.location.hostname) ? window.location.hostname : "localhost";
const DEFAULT_ENTRANCE_URL = "http://" + LOCAL_HOST + ":5000/video/entrance";
const DEFAULT_EXIT_URL = "http://" + LOCAL_HOST + ":5000/video/exit";
const API_BASE_URL = "http://" + LOCAL_HOST + ":5000";

/* A camera address saved as localhost / 127.0.0.1 only works on the server PC.
   Point it at the address this page was opened with, so it works on any device. */
function followPageHost(url) {
    return String(url || "").replace(/^(https?:\/\/)(localhost|127\.0\.0\.1)(:5000)/i, "$1" + LOCAL_HOST + "$3");
}


/* =========================================================
   NOTIFICATION CENTER & OVERSTAY REMINDER ALARM CONTROLLER
========================================================= */

const NOTIF_STORAGE_KEY = "guard_dashboard_notifications_history";
let notificationsList = [];
let currentNotifFilter = "all";
let acknowledgedOverdueIds = new Set();
let lastOverstayAlarmSound = 0;

let alarmAudioMuted = localStorage.getItem("guard_alarm_muted") === "true";

function toggleOverstayAlarmAudio() {
    if (window.VisitorDurationAlarm) {
        window.VisitorDurationAlarm.toggleMute();
        alarmAudioMuted = localStorage.getItem("guard_alarm_muted") === "true";
        return;
    }
    alarmAudioMuted = !alarmAudioMuted;
    localStorage.setItem("guard_alarm_muted", alarmAudioMuted ? "true" : "false");
    updateAlarmSoundButton();
}

function updateAlarmSoundButton() {
    const btn = document.getElementById("btnToggleAlarmSound");
    if (btn) {
        btn.innerHTML = alarmAudioMuted ? "🔇 Alarm Muted" : "🔊 Sound On";
        btn.style.color = alarmAudioMuted ? "#ef4444" : "#16a34a";
    }
    const bannerMuteBtn = document.getElementById("bannerMuteBtn");
    if (bannerMuteBtn) {
        bannerMuteBtn.innerHTML = alarmAudioMuted ? "🔇 Unmute Alarm" : "🔊 Mute Alarm";
    }
}

function loadStoredNotifications() {
    try {
        const stored = localStorage.getItem(NOTIF_STORAGE_KEY);
        if (stored) {
            notificationsList = JSON.parse(stored);
        }
    } catch (e) {
        notificationsList = [];
    }

    // If storage is empty, seed with today's initial vehicle logs
    if ((!notificationsList || notificationsList.length === 0) && typeof INITIAL_TODAY_LOGS !== "undefined" && Array.isArray(INITIAL_TODAY_LOGS) && INITIAL_TODAY_LOGS.length > 0) {
        INITIAL_TODAY_LOGS.forEach(item => {
            const itemDir = String(item.direction || "IN").toUpperCase();
            const itemCat = String(item.vehicle_category || "Visitor");
            const itemPlate = String(item.plate_number || "Unknown");
            const itemType = String(item.vehicle_type || "Car");
            const itemPurpose = String(item.purpose || "");

            notificationsList.push({
                id: "notif_init_" + item.id,
                type: itemDir === "OUT" ? "OUT" : "IN",
                title: itemDir === "OUT" ? ("🔴 Vehicle Exited: " + itemPlate) : ("🟢 Vehicle Entered: " + itemPlate),
                sub: itemCat + " • " + itemType + (itemPurpose ? " • " + itemPurpose : "") + " (" + (itemDir === "OUT" ? "Exit Cam" : "Entrance Cam") + ")",
                time: item.date_created ? item.date_created.substr(11, 5) : "",
                timestamp: Date.now(),
                read: true,
                meta: item
            });
        });
        saveNotificationsToStorage();
    }

    updateNotificationUI();
    updateAlarmSoundButton();
}

function saveNotificationsToStorage() {
    try {
        // Keep at most 120 most recent notifications
        if (notificationsList.length > 120) {
            notificationsList = notificationsList.slice(0, 120);
        }
        localStorage.setItem(NOTIF_STORAGE_KEY, JSON.stringify(notificationsList));
    } catch (e) {}
}

function addNotification(type, title, subText, metaData) {
    const now = new Date();
    const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    const notif = {
        id: "notif_" + Date.now() + "_" + Math.random().toString(36).substr(2, 5),
        type: type, // 'IN', 'OUT', 'OVERDUE', 'DUE_SOON'
        title: title,
        sub: subText,
        time: timeStr,
        timestamp: Date.now(),
        read: false,
        meta: metaData || {}
    };

    notificationsList.unshift(notif);
    saveNotificationsToStorage();
    updateNotificationUI();
}

function updateNotificationUI() {
    const badge = document.getElementById("notifBadge");
    const drawerCount = document.getElementById("drawerTotalCount");
    const summaryText = document.getElementById("unreadSummaryText");
    const listContainer = document.getElementById("notifList");

    const unreadCount = notificationsList.filter(n => !n.read).length;

    if (badge) {
        if (unreadCount > 0) {
            badge.innerText = unreadCount > 99 ? "99+" : unreadCount;
            badge.style.display = "inline-flex";
        } else {
            badge.style.display = "none";
        }
    }

    if (drawerCount) {
        drawerCount.innerText = notificationsList.length;
    }

    if (summaryText) {
        summaryText.innerText = unreadCount + " unread";
    }

    if (!listContainer) return;

    // Filter items
    let filtered = notificationsList;
    if (currentNotifFilter === "IN") {
        filtered = notificationsList.filter(n => n.type === "IN");
    } else if (currentNotifFilter === "OUT") {
        filtered = notificationsList.filter(n => n.type === "OUT");
    } else if (currentNotifFilter === "ALERT") {
        filtered = notificationsList.filter(n => n.type === "OVERDUE" || n.type === "DUE_SOON");
    }

    if (filtered.length === 0) {
        listContainer.innerHTML =
            '<div class="notif-empty-state">' +
                '<div class="notif-empty-icon">📭</div>' +
                '<div>No ' + (currentNotifFilter === "all" ? "" : currentNotifFilter + " ") + 'notifications found.</div>' +
            '</div>';
        return;
    }

    let html = "";
    for (let i = 0; i < filtered.length; i++) {
        const item = filtered[i];
        let itemClass = "notif-item";
        let icon = "🔔";

        if (item.type === "IN") {
            itemClass += " notif-in";
            icon = "🟢";
        } else if (item.type === "OUT") {
            itemClass += " notif-out";
            icon = "🔴";
        } else if (item.type === "OVERDUE") {
            itemClass += " notif-overdue";
            icon = "⚠️";
        } else if (item.type === "DUE_SOON") {
            itemClass += " notif-duesoon";
            icon = "⏰";
        }

        if (!item.read) {
            itemClass += " unread";
        }

        html +=
            '<div class="' + itemClass + '" onclick="markNotificationSingleRead(\'' + item.id + '\')">' +
                '<div class="notif-item-icon">' + icon + '</div>' +
                '<div class="notif-item-body">' +
                    '<div class="notif-item-title">' +
                        '<span>' + escapeHtmlText(item.title) + '</span>' +
                        '<span class="notif-item-time">' + escapeHtmlText(item.time) + '</span>' +
                    '</div>' +
                    '<div class="notif-item-sub">' + escapeHtmlText(item.sub) + '</div>' +
                '</div>' +
            '</div>';
    }
    listContainer.innerHTML = html;
}

function filterNotifications(filterType) {
    currentNotifFilter = filterType;
    const tabs = document.querySelectorAll(".notif-tab");
    tabs.forEach(t => {
        if (t.getAttribute("data-filter") === filterType) {
            t.classList.add("active");
        } else {
            t.classList.remove("active");
        }
    });
    updateNotificationUI();
}

function markAllNotificationsAsRead() {
    notificationsList.forEach(n => n.read = true);
    saveNotificationsToStorage();
    updateNotificationUI();
}

function markNotificationSingleRead(id) {
    const item = notificationsList.find(n => n.id === id);
    if (item && !item.read) {
        item.read = true;
        saveNotificationsToStorage();
        updateNotificationUI();
    }
}

function clearAllNotifications() {
    const existing = document.getElementById("clearNotificationsDialog");
    if (existing) { existing.focus(); return; }
    const previousFocus = document.activeElement;
    const dialog = document.createElement("dialog");
    dialog.id = "clearNotificationsDialog";
    dialog.className = "vda-markout-dialog";
    dialog.setAttribute("aria-labelledby", "clearNotificationsTitle");
    dialog.setAttribute("aria-describedby", "clearNotificationsDescription");
    dialog.innerHTML =
        '<div class="vda-markout-icon" aria-hidden="true">&#128465;</div>' +
        '<h2 id="clearNotificationsTitle">Clear all notifications?</h2>' +
        '<p id="clearNotificationsDescription">This will clear all notification history, including entries, exits, and due alerts. Vehicle logs and active visitor timers will remain unchanged.</p>' +
        '<div class="vda-markout-actions">' +
            '<button type="button" class="vda-markout-cancel">Cancel</button>' +
            '<button type="button" class="vda-markout-confirm">Clear All</button>' +
        '</div>';
    const cancel = dialog.querySelector(".vda-markout-cancel");
    const confirm = dialog.querySelector(".vda-markout-confirm");
    cancel.addEventListener("click", () => dialog.close());
    confirm.addEventListener("click", () => {
        notificationsList = [];
        saveNotificationsToStorage();
        updateNotificationUI();
        dialog.close();
    });
    dialog.addEventListener("click", event => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right ||
            event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    dialog.addEventListener("close", () => {
        dialog.remove();
        if (previousFocus && previousFocus.isConnected) previousFocus.focus();
    });
    document.body.appendChild(dialog);
    dialog.showModal();
    cancel.focus();
}

function toggleNotificationDrawer() {
    const drawer = document.getElementById("notifDrawer");
    const overlay = document.getElementById("notifDrawerOverlay");
    if (!drawer) return;

    if (drawer.classList.contains("open")) {
        closeNotificationDrawer();
    } else {
        drawer.classList.add("open");
        if (overlay) overlay.classList.add("active");
        // Mark all as read when opening drawer
        markAllNotificationsAsRead();
    }
}

function closeNotificationDrawer() {
    const drawer = document.getElementById("notifDrawer");
    const overlay = document.getElementById("notifDrawerOverlay");
    if (drawer) drawer.classList.remove("open");
    if (overlay) overlay.classList.remove("active");
}

function openNotificationDrawerWithTab(filterType) {
    toggleNotificationDrawer();
    filterNotifications(filterType);
}

function dismissOverstayBanner() {
    const banner = document.getElementById("overstayAlertBanner");
    if (banner) banner.style.display = "none";
}

/* OVERSTAY ALARM CHIME (Web Audio API Synthesizer) */
function playOverstayAlarmSound() {
    if (alarmAudioMuted) return;
    const now = Date.now();
    // Throttle alarm to sound at most once every 30 seconds
    if (now - lastOverstayAlarmSound < 30000) return;
    lastOverstayAlarmSound = now;

    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();

        const playTone = (freq, start, duration) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = "sawtooth";
            osc.frequency.setValueAtTime(freq, ctx.currentTime + start);
            gain.gain.setValueAtTime(0.2, ctx.currentTime + start);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + start + duration);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(ctx.currentTime + start);
            osc.stop(ctx.currentTime + start + duration);
        };

        // Two-tone warning beep: 784 Hz (G5) -> 659 Hz (E5) -> 784 Hz (G5)
        playTone(784, 0.0, 0.15);
        playTone(659, 0.2, 0.15);
        playTone(784, 0.4, 0.25);
    } catch (e) {}
}

function handleOverstayReminders(overdueList, dueSoonList) {
    const banner = document.getElementById("overstayAlertBanner");
    const textEl = document.getElementById("overstayAlertText");

    if (Array.isArray(overdueList) && overdueList.length > 0) {
        // We have active overdue visitors!
        playOverstayAlarmSound();

        if (banner && textEl) {
            banner.style.display = "flex";
            const first = overdueList[0];
            const count = overdueList.length;
            const extra = count > 1 ? (" (and " + (count - 1) + " other" + (count > 2 ? "s" : "") + ")") : "";
            textEl.innerHTML =
                '<strong>⚠️ VISITOR OVERSTAY ALERT:</strong> Plate <strong>' + escapeHtmlText(first.plate_number) + '</strong> ' +
                'exceeded stay limit (' + escapeHtmlText(first.purpose || 'Visitor') + '). Due: ' + escapeHtmlText(first.expected_time_out_formatted) +
                ' &bull; <span style="font-weight:800; color:#b91c1c;">' + first.minutes_overdue + ' mins overdue!</span>' + extra;
        }

        // Add overdue notification to drawer if not already recorded recently
        overdueList.forEach(v => {
            const key = "overdue_" + v.id + "_" + v.plate_number;
            if (!acknowledgedOverdueIds.has(key)) {
                acknowledgedOverdueIds.add(key);
                addNotification(
                    "OVERDUE",
                    "⚠️ Overstay Alert: " + (v.plate_number || "Visitor"),
                    (v.vehicle_type || "Car") + " • " + (v.purpose || "Visitor") + " • Due at " + v.expected_time_out_formatted + " (" + v.minutes_overdue + " mins overdue)",
                    v
                );
            }
        });
    } else if (Array.isArray(dueSoonList) && dueSoonList.length > 0) {
        // Visitors due soon (within 15 minutes)
        if (banner && textEl && banner.style.display === "none") {
            const first = dueSoonList[0];
            banner.style.display = "flex";
            banner.style.background = "#fffbeb";
            banner.style.borderLeftColor = "#f59e0b";
            textEl.innerHTML =
                '<strong>⏰ VISITOR DUE SOON:</strong> Plate <strong>' + escapeHtmlText(first.plate_number) + '</strong> ' +
                'time limit expires in <strong>' + first.minutes_left + ' minutes</strong> (' + escapeHtmlText(first.expected_time_out_formatted) + ').';
        }

        dueSoonList.forEach(v => {
            const key = "duesoon_" + v.id + "_" + v.plate_number;
            if (!acknowledgedOverdueIds.has(key)) {
                acknowledgedOverdueIds.add(key);
                addNotification(
                    "DUE_SOON",
                    "⏰ Due Soon: " + (v.plate_number || "Visitor"),
                    (v.vehicle_type || "Car") + " • Time limit expires in " + v.minutes_left + " mins (at " + v.expected_time_out_formatted + ")",
                    v
                );
            }
        });
    } else {
        if (banner) banner.style.display = "none";
    }
}


/* =========================================================
   ADMIN-ADDED CAMERAS
   Cameras the Administrator adds on superadmin_dashboard.php are
   appended to the guard grid and kept in sync by polling.
========================================================= */
let guardExtraCameras = <?php echo json_encode($guardExtraCameras, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
let guardExtraCamerasSig = null;
const EXTRA_CAMERA_POLL_MS = 10000;

function camEscape(str) {
    return String(str === undefined || str === null ? "" : str)
        .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}

function getCamUrl(camKey) {
    const extra = guardExtraCameras.find(c => c.id === camKey);
    if (extra) {
        return extra.url;
    }
    const defaultUrl = camKey === "entrance" ? DEFAULT_ENTRANCE_URL : DEFAULT_EXIT_URL;
    return followPageHost(localStorage.getItem("tapo_" + camKey + "_url") || defaultUrl);
}

function buildGuardExtraCameraCard(cam) {
    const k = cam.id; // validated server-side: cam_[a-z0-9]+
    let badge;
    if (cam.direction === "IN") {
        badge = '<span class="camera-tag dir-badge-in" style="font-size:11px; padding:3px 10px; font-weight:800;">🟢 IN' + (cam.model ? ' · ' + camEscape(cam.model) : '') + '</span>';
    } else if (cam.direction === "OUT") {
        badge = '<span class="camera-tag dir-badge-out" style="font-size:11px; padding:3px 10px; font-weight:800;">🔴 OUT' + (cam.model ? ' · ' + camEscape(cam.model) : '') + '</span>';
    } else {
        badge = '<span class="camera-tag dir-badge-monitor" style="font-size:11px; padding:3px 10px; font-weight:800;">🔵 MONITOR' + (cam.model ? ' · ' + camEscape(cam.model) : '') + '</span>';
    }

    return '' +
        '<div class="camera-card camera-card-extra" id="card_' + k + '" data-cam-id="' + k + '">' +
            '<div class="camera-card-header">' +
                '<div class="camera-title">' +
                    '<span class="camera-name" title="' + camEscape(cam.name) + '">' + camEscape(cam.name) + '</span>' +
                    badge +
                '</div>' +
                '<button type="button" class="btn-cam-setup" onclick="reloadCamStream(\'' + k + '\')" title="Reconnect stream">🔄 Reconnect</button>' +
            '</div>' +
            '<div class="camera-screen">' +
                '<img id="' + k + 'CamFeed" alt="' + camEscape(cam.name) + ' Stream"' +
                    ' onload="onCamStreamLoad(\'' + k + '\')" onerror="onCamStreamError(\'' + k + '\')">' +
                '<div class="camera-offline" id="' + k + 'CamOffline" style="display:none;">' +
                    '<div class="camera-offline-icon">📹</div>' +
                    '<div class="camera-offline-title">' + camEscape(cam.name) + ' Offline</div>' +
                    '<div class="camera-offline-sub">Camera stream is not reachable. Ask the Administrator to check this camera\'s stream URL.</div>' +
                    '<div class="camera-offline-actions">' +
                        '<button type="button" class="btn-offline-retry" onclick="reloadCamStream(\'' + k + '\')">🔄 Reconnect</button>' +
                    '</div>' +
                '</div>' +
                '<div class="camera-status">' +
                    '<span class="status-dot offline" id="' + k + 'Dot"></span>' +
                    '<span id="' + k + 'StatusText">Connecting...</span>' +
                '</div>' +
            '</div>' +
        '</div>';
}

/* Re-renders only when the admin's list actually changed, so live
   streams of unchanged cameras are never interrupted. */
function renderGuardExtraCameras(list) {
    const grid = document.getElementById("cameraGrid");
    if (!grid) return;

    const sig = JSON.stringify(list || []);
    if (sig === guardExtraCamerasSig) return;
    guardExtraCamerasSig = sig;
    guardExtraCameras = list || [];

    const wantedIds = new Set(guardExtraCameras.map(c => c.id));
    grid.querySelectorAll(".camera-card-extra").forEach(card => {
        const id = card.getAttribute("data-cam-id");
        const cam = guardExtraCameras.find(c => c.id === id);
        const img = document.getElementById(id + "CamFeed");
        // Remove deleted cards, and cards whose details changed (rebuilt below)
        if (!wantedIds.has(id) || !img || card.getAttribute("data-sig") !== JSON.stringify(cam)) {
            if (img) img.src = ""; // close the MJPEG connection
            card.remove();
        }
    });

    guardExtraCameras.forEach(cam => {
        let card = document.getElementById("card_" + cam.id);
        if (!card) {
            grid.insertAdjacentHTML("beforeend", buildGuardExtraCameraCard(cam));
            card = document.getElementById("card_" + cam.id);
            card.setAttribute("data-sig", JSON.stringify(cam));
            const img = document.getElementById(cam.id + "CamFeed");
            if (img) img.src = cam.url;
        } else {
            grid.appendChild(card); // keep the admin's order
        }
    });
}

async function pollGuardExtraCameras() {
    try {
        const res = await fetch("guard_dashboard.php?action=extra_cameras", { cache: "no-store" });
        const data = await res.json();
        if (data && data.success && Array.isArray(data.cameras)) {
            renderGuardExtraCameras(data.cameras);
        }
    } catch (e) {
        // Keep current cards if the request fails
    }
}

function initTapoCameras() {
    // Saved localhost / 127.0.0.1 addresses follow the address this page was opened with
    const entranceUrl = followPageHost(localStorage.getItem("tapo_entrance_url") || DEFAULT_ENTRANCE_URL);
    const exitUrl = followPageHost(localStorage.getItem("tapo_exit_url") || DEFAULT_EXIT_URL);

    const entranceImg = document.getElementById("entranceCamFeed");
    const exitImg = document.getElementById("exitCamFeed");

    if (entranceImg) {
        entranceImg.src = entranceUrl;
    }
    if (exitImg) {
        exitImg.src = exitUrl;
    }

    renderGuardExtraCameras(guardExtraCameras);
    setInterval(pollGuardExtraCameras, EXTRA_CAMERA_POLL_MS);

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
    const offCard = document.getElementById(camKey === "entrance" ? "cardEntranceCam" : (camKey === "exit" ? "cardExitCam" : ""));
    if (text && !(offCard && offCard.dataset.cameraOff === "1")) {
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

    // A camera turned off in Camera Setup keeps its "Turned Off" screen
    const card = document.getElementById(camKey === "entrance" ? "cardEntranceCam" : (camKey === "exit" ? "cardExitCam" : ""));
    if (card && card.dataset.cameraOff === "1") return;

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

function updateCameraModeControls() {
    const single = document.getElementById("cfgCameraMode").value === "single";
    document.getElementById("singleDirectionControls").hidden = !single;
    document.getElementById("separateExitSettings").hidden = single;
    // With one shared camera, that camera is always on
    const entranceEnabledRow = document.getElementById("entranceEnabledRow");
    if (entranceEnabledRow) entranceEnabledRow.hidden = single;
    document.getElementById("settingsEntranceTitle").textContent = single ? "Shared Camera" : "Entrance Camera";
    document.getElementById("settingsEntranceDirection").textContent = "Direction: " + (single ? document.getElementById("cfgSingleDirection").value : "IN");
}

function updateCameraPanelTitles(mode, direction) {
    document.getElementById("entranceCameraTitle").textContent = mode === "single" ? "Shared Camera - " + (direction === "AUTO" ? "Automatic IN / OUT" : direction) : "Entrance Cam - IN";
    document.getElementById("exitCameraTitle").textContent = mode === "single" ? "Shared Camera - " + (direction === "AUTO" ? "Automatic IN / OUT" : direction) + " (same feed)" : "Exit Cam - OUT";
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

    document.getElementById("cameraSettingsError").hidden = true;
    // Fetch live hardware camera sources & connection state from Python server
    try {
        const res = await fetch(API_BASE_URL + "/api/cameras", { cache: "no-store" });
        const data = await res.json();
        if (data.success && data.cameras) {
            document.getElementById("cfgCameraMode").value = data.camera_mode || "dual";
            document.getElementById("cfgSingleDirection").value = data.single_direction || "IN";
            document.getElementById("cfgExitEnabled").checked = data.separate_exit_enabled !== false;
            document.getElementById("cfgEntranceEnabled").checked = !(data.cameras.entrance && data.cameras.entrance.enabled === false);
            updateCameraModeControls();
            if (data.cameras.entrance && inputEntranceSrc) {
                inputEntranceSrc.value = data.cameras.entrance.source || "";
                if (data.cameras.entrance.zoom_factor && document.getElementById("cfgEntranceZoom")) {
                    document.getElementById("cfgEntranceZoom").value = String(data.cameras.entrance.zoom_factor);
                }
                if (statusEnt) {
                    const isConn = data.cameras.entrance.connected;
                    statusEnt.innerText = isConn ? "🟢 Connected" : "🔴 Offline";
                    statusEnt.style.background = isConn ? "#dcfce7" : "#fee2e2";
                    statusEnt.style.color = isConn ? "#15803d" : "#dc2626";
                }
            }
            if (data.cameras.exit && inputExitSrc) {
                inputExitSrc.value = data.separate_exit_source || data.cameras.exit.source || "";
                if (data.cameras.exit.zoom_factor && document.getElementById("cfgExitZoom")) {
                    document.getElementById("cfgExitZoom").value = String(data.separate_exit_zoom || data.cameras.exit.zoom_factor);
                }
                if (statusExit) {
                    const isConn = data.cameras.exit.connected;
                    statusExit.innerText = data.separate_exit_enabled === false ? "Disabled - waiting for second camera" : (isConn ? "Connected" : "Offline");
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
    document.getElementById("cfgCameraMode").value = "dual";
    updateCameraModeControls();
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
    document.getElementById("cfgCameraMode").value = "dual";
    updateCameraModeControls();
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
    document.getElementById("cfgCameraMode").value = "dual";
    updateCameraModeControls();
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


    // Push hardware camera sources & view/zoom to Python detection server live
    const entSrcVal = inputEntranceSrc ? inputEntranceSrc.value.trim() : "";
    const exitSrcVal = inputExitSrc ? inputExitSrc.value.trim() : "";
    const entZoomVal = document.getElementById("cfgEntranceZoom") ? parseFloat(document.getElementById("cfgEntranceZoom").value) : 1.35;
    const exitZoomVal = document.getElementById("cfgExitZoom") ? parseFloat(document.getElementById("cfgExitZoom").value) : 1.35;

    const isDual = document.getElementById("cfgCameraMode").value === "dual";
    // One shared camera is always on; with two cameras either one can be turned off
    const entranceEnabled = isDual ? document.getElementById("cfgEntranceEnabled").checked : true;
    const exitEnabled = document.getElementById("cfgExitEnabled").checked;
    if (isDual && !entranceEnabled && !exitEnabled) {
        const error = document.getElementById("cameraSettingsError");
        error.textContent = "At least one camera must stay on. Turn one back on, or switch to one camera.";
        error.hidden = false;
        return;
    }

    let saved = null;
    try {
        const res = await fetch(API_BASE_URL + "/api/cameras", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                camera_mode: document.getElementById("cfgCameraMode").value,
                single_direction: document.getElementById("cfgSingleDirection").value,
                entrance_source: entSrcVal,
                entrance_enabled: entranceEnabled,
                ...(isDual ? { exit_source: exitSrcVal, exit_zoom: exitZoomVal, exit_enabled: exitEnabled } : {}),
                entrance_zoom: entZoomVal
            })
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.message || "Camera settings were not saved.");
        localStorage.setItem("tapo_entrance_url", entranceUrlVal);
        localStorage.setItem("tapo_exit_url", exitUrlVal);
        updateCameraPanelTitles(data.camera_mode, data.single_direction);
        saved = data;
    } catch (e) {
        const error = document.getElementById("cameraSettingsError");
        error.textContent = "Could not save camera settings: " + e.message;
        error.hidden = false;
        return;
    }

    closeTapoModal();

    applyCameraPanelVisibility(saved.camera_mode, saved.cameras, true);
}

/* Both camera panels always stay on the dashboard. A camera turned off in
   Camera Setup keeps its panel, showing a "Turned Off" screen (and stops
   streaming); turning it back on reconnects the live feed. */
function applyCameraPanelVisibility(mode, cameras, reloadVisible) {
    const cams = cameras || {};
    const single = mode === "single";
    ["entrance", "exit"].forEach(function (camKey) {
        const isOff = !single && !!(cams[camKey] && cams[camKey].enabled === false);
        const wasOff = setCameraPanelOff(camKey, isOff);
        if (!isOff && (wasOff || reloadVisible)) {
            reloadCamStream(camKey);
        }
    });
}

/* Switch one panel between its normal view and the "Turned Off" screen.
   Returns whether the panel was showing "Turned Off" before. */
function setCameraPanelOff(camKey, isOff) {
    const card = document.getElementById(camKey === "entrance" ? "cardEntranceCam" : "cardExitCam");
    const img = document.getElementById(camKey + "CamFeed");
    const offlineDiv = document.getElementById(camKey + "CamOffline");
    const dot = document.getElementById(camKey + "Dot");
    const text = document.getElementById(camKey + "StatusText");
    if (!card) return false;
    const wasOff = card.dataset.cameraOff === "1";
    if (isOff === wasOff) return wasOff;

    const title = offlineDiv ? offlineDiv.querySelector(".camera-offline-title") : null;
    const sub = offlineDiv ? offlineDiv.querySelector(".camera-offline-sub") : null;
    const retry = offlineDiv ? offlineDiv.querySelector(".btn-offline-retry") : null;
    const label = camKey === "entrance" ? "Entrance Cam" : "Exit Cam";

    if (isOff) {
        card.dataset.cameraOff = "1";
        if (title && !title.dataset.original) title.dataset.original = title.textContent;
        if (sub && !sub.dataset.original) sub.dataset.original = sub.textContent;
        if (img) {
            img.removeAttribute("src");          // close the MJPEG connection
            img.style.display = "none";
        }
        if (offlineDiv) offlineDiv.style.display = "flex";
        if (title) title.textContent = label + " Turned Off";
        if (sub) sub.textContent = "This camera is turned off in Camera Setup. Turn it back on there to use it.";
        if (retry) retry.style.display = "none";
        if (dot) dot.className = "status-dot offline";
        if (text) {
            text.innerText = "Turned off";
            text.style.color = "#64748b";
        }
    } else {
        delete card.dataset.cameraOff;
        if (title && title.dataset.original) title.textContent = title.dataset.original;
        if (sub && sub.dataset.original) sub.textContent = sub.dataset.original;
        if (retry) retry.style.display = "";
    }
    return wasOff;
}

async function checkDetectionServerStatus() {
    try {
        const res = await fetch(API_BASE_URL + "/api/status", { cache: "no-store" });
        const data = await res.json();
        if (data.success && data.cameras) {
            updateCameraPanelTitles(data.camera_mode, data.single_direction);
            applyCameraPanelVisibility(data.camera_mode, data.cameras, false);
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

    const visitorModal = document.getElementById("visitorInfoModal");
    if (visitorModal && e.target === visitorModal) {
        closeVisitorModal();
    }

    const exitModal = document.getElementById("visitorExitModal");
    if (exitModal && e.target === exitModal) {
        closeVisitorExitModal();
    }
});

document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") {
        const visitorModal = document.getElementById("visitorInfoModal");
        if (visitorModal && visitorModal.classList.contains("active")) {
            closeVisitorModal(false);
        }
    }
});

document.addEventListener("DOMContentLoaded", function() { initTapoCameras(); loadStoredNotifications(); pollLiveCaptures(); });

</script>

<!-- Shared Per-Duration Visitor Reminder & Overstay Alarm Engine -->
<script>window.SECSYS_ALARM_ROLE = "Security Guard";</script>
<script src="assets/js/visitor_duration_alarm.js"></script>

<!-- Live auto-refresh (no full page reload) -->
<script>window.AUTO_REFRESH_SECONDS = 5;</script>
<script src="assets/js/dual_clock.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/dual_clock.js'); ?>"></script>
<script src="assets/js/auto_refresh.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/auto_refresh.js'); ?>"></script>



<!-- =========================================================
     NOTIFICATION DRAWER (IN/OUT LOGS & OVERSTAY ALERTS)
========================================================= -->
<div class="notif-drawer-overlay" id="notifDrawerOverlay" onclick="closeNotificationDrawer()"></div>

<aside class="notif-drawer" id="notifDrawer">

    <div class="notif-drawer-header">
        <div class="notif-drawer-title">
            <span>🔔 Activity &amp; Alerts</span>
            <span class="notif-drawer-count" id="drawerTotalCount">0</span>
        </div>
        <button type="button" class="btn-close-drawer" onclick="closeNotificationDrawer()" aria-label="Close">&times;</button>
    </div>

    <div class="notif-tabs">
        <button type="button" class="notif-tab active" data-filter="all" onclick="filterNotifications('all')">All</button>
        <button type="button" class="notif-tab" data-filter="IN" onclick="filterNotifications('IN')">🟢 Entries</button>
        <button type="button" class="notif-tab" data-filter="OUT" onclick="filterNotifications('OUT')">🔴 Exits</button>
        <button type="button" class="notif-tab" data-filter="ALERT" onclick="filterNotifications('ALERT')">⚠️ Due Alerts</button>
    </div>

    <div class="notif-actions-bar">
        <span id="unreadSummaryText" style="font-size:11px; color:#64748b; font-weight:600;">0 unread</span>
        <div style="display:flex; gap:6px;">
            <button type="button" class="btn-notif-action" id="btnToggleAlarmSound" onclick="toggleOverstayAlarmAudio()">🔊 Sound On</button>
            <button type="button" class="btn-notif-action" onclick="markAllNotificationsAsRead()">Mark Read</button>
            <button type="button" class="btn-notif-action text-danger" onclick="clearAllNotifications()">Clear All</button>
        </div>
    </div>

    <div class="notif-list" id="notifList">
        <div class="notif-empty-state">
            <div class="notif-empty-icon">📭</div>
            <div>No activity notifications yet today.</div>
        </div>
    </div>

</aside>

<?php echo secsysYoloCaptureModalAssetsHtml(); ?>
</body>

</html>
