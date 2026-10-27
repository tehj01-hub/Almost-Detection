import re

with open("guard_dashboard.php", "r", encoding="utf-8") as f:
    content = f.read()

# 1. New Fast-Path AJAX Dispatcher
fast_ajax_block = '''$conn->set_charset("utf8mb4");

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
       1. SAVE VISITOR INFORMATION (INSTANT SAVE)
    --------------------------------------------------------- */
    if ($ajaxAction === 'save_visitor_info' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header("Content-Type: application/json; charset=utf-8");

        $logId = (int)($_POST['log_id'] ?? 0);
        $rawPlate = strtoupper(trim($_POST['plate_number'] ?? ''));
        $plateNormalized = preg_replace('/[^A-Z0-9 ]/', '', $rawPlate);
        $plateClean = trim(preg_replace('/\\s+/', ' ', $plateNormalized));
        $plateNoSpaces = str_replace(' ', '', $plateClean);
        $vehicleType = trim($_POST['vehicle_type'] ?? 'Car');
        if (!in_array($vehicleType, ['Car', 'Motorcycle', 'Truck'], true)) {
            $vehicleType = 'Car';
        }
        $purpose = trim($_POST['purpose'] ?? '');

        if ($plateClean === '' || $purpose === '') {
            echo json_encode([
                'success' => false,
                'message' => 'Please enter the Plate Number, Vehicle Type, and Purpose.'
            ]);
            $conn->close();
            exit();
        }

        // Allowed duration mapping
        $purposeDurationMap = [
            'Birthday - 12 Hours'          => 720,
            'Visiting Resident - 12 Hours' => 720,
            'Delivery - 20 Mins'           => 20,
            'Drop - off/on - 15 Mins'      => 15,
            'Service Maintenance - 8 Hours'=> 480,
        ];
        $allowedMinutes = $purposeDurationMap[$purpose] ?? null;
        if ($allowedMinutes === null) {
            $pLower = strtolower($purpose);
            if (strpos($pLower, 'birthday') !== false || strpos($pLower, 'visiting') !== false) {
                $allowedMinutes = 720;
            } elseif (strpos($pLower, 'delivery') !== false) {
                $allowedMinutes = 20;
            } elseif (strpos($pLower, 'drop') !== false) {
                $allowedMinutes = 15;
            } elseif (strpos($pLower, 'service') !== false || strpos($pLower, 'maintenance') !== false) {
                $allowedMinutes = 480;
            } else {
                $allowedMinutes = 720;
            }
        }

        // Step 1: Upsert into visitor_vehicles for today using indexed lookup
        $visitorVehicleId = null;
        $findVv = $conn->prepare("
            SELECT id
            FROM visitor_vehicles
            WHERE (plate_number = ? OR plate_number = ?)
              AND date_created >= CURDATE()
            ORDER BY id DESC
            LIMIT 1
        ");
        if ($findVv) {
            $findVv->bind_param("ss", $plateClean, $plateNoSpaces);
            $findVv->execute();
            $resVv = $findVv->get_result();
            if ($rowVv = $resVv->fetch_assoc()) {
                $visitorVehicleId = (int)$rowVv['id'];
            }
            $findVv->close();
        }

        if ($visitorVehicleId) {
            $updVv = $conn->prepare("
                UPDATE visitor_vehicles
                SET plate_number = ?,
                    vehicle_type = ?,
                    purpose = ?,
                    allowed_minutes = ?,
                    date_in = COALESCE(date_in, CURDATE()),
                    expected_time_out = DATE_ADD(COALESCE(time_in, NOW()), INTERVAL ? MINUTE)
                WHERE id = ?
            ");
            if ($updVv) {
                $updVv->bind_param("sssiii", $plateClean, $vehicleType, $purpose, $allowedMinutes, $allowedMinutes, $visitorVehicleId);
                $updVv->execute();
                $updVv->close();
            }
        } else {
            $insVv = $conn->prepare("
                INSERT INTO visitor_vehicles
                    (visitor_name, address_to_visit, vehicle_type, plate_number, purpose, allowed_minutes, status, date_in, time_in, expected_time_out, date_created)
                VALUES
                    ('Visitor', '-', ?, ?, ?, ?, 'IN', CURDATE(), NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())
            ");
            if ($insVv) {
                $insVv->bind_param("sssii", $vehicleType, $plateClean, $purpose, $allowedMinutes, $allowedMinutes);
                if ($insVv->execute()) {
                    $visitorVehicleId = (int)$conn->insert_id;
                }
                $insVv->close();
            }
        }

        // Step 2: Update existing vehicle_logs row (fast indexed lookup or primary key)
        $direction = 'IN';

        if ($logId <= 0) {
            $findLog = $conn->prepare("
                SELECT id, direction
                FROM vehicle_logs
                WHERE (plate_number = ? OR plate_number = ?)
                  AND vehicle_category = 'Visitor'
                  AND date_created >= CURDATE()
                ORDER BY id DESC
                LIMIT 1
            ");
            if ($findLog) {
                $findLog->bind_param("ss", $plateClean, $plateNoSpaces);
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
                    expected_time_out = DATE_ADD(date_created, INTERVAL ? MINUTE),
                    date_in = COALESCE(date_in, DATE(date_created)),
                    visitor_vehicle_id = ?
                WHERE id = ?
            ");
            if ($updLog) {
                $updLog->bind_param("sssiiii", $plateClean, $vehicleType, $purpose, $allowedMinutes, $allowedMinutes, $visitorVehicleId, $logId);
                $updLog->execute();
                $updLog->close();
            }
        } else {
            $insLog = $conn->prepare("
                INSERT INTO vehicle_logs
                    (plate_number, vehicle_type, vehicle_category, visitor_vehicle_id, visitor_name, purpose, allowed_minutes, expected_time_out, direction, recognition_method, date_in, date_created)
                VALUES
                    (?, ?, 'Visitor', ?, 'Visitor', ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 'IN', 'Manual', CURDATE(), NOW())
            ");
            if ($insLog) {
                $insLog->bind_param("ssisii", $plateClean, $vehicleType, $visitorVehicleId, $purpose, $allowedMinutes, $allowedMinutes);
                if ($insLog->execute()) {
                    $logId = (int)$conn->insert_id;
                }
                $insLog->close();
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

        echo json_encode([
            'success' => true,
            'log' => [
                'id' => $logId,
                'plate_number' => $plateClean,
                'vehicle_category' => 'Visitor',
                'vehicle_type' => $vehicleType,
                'purpose' => $purpose,
                'direction' => $direction
            ]
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
                vl.direction,
                vl.date_created
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

        // Overstay checks (only if visitors exist)
        $overdueVisitors = [];
        $dueSoonVisitors = [];

        $stmtOverstay = $conn->prepare("
            SELECT
                vv.id,
                vv.plate_number,
                vv.vehicle_type,
                vv.purpose,
                vv.allowed_minutes,
                DATE_FORMAT(vv.time_in, '%h:%i %p') AS time_in_formatted,
                DATE_FORMAT(vv.expected_time_out, '%h:%i %p') AS expected_time_out_formatted,
                TIMESTAMPDIFF(MINUTE, NOW(), vv.expected_time_out) AS minutes_remaining
            FROM visitor_vehicles vv
            WHERE vv.status = 'IN'
              AND vv.date_created >= CURDATE()
              AND vv.expected_time_out IS NOT NULL
            ORDER BY vv.expected_time_out ASC
        ");
        if ($stmtOverstay) {
            $stmtOverstay->execute();
            $resOverstay = $stmtOverstay->get_result();
            while ($rowO = $resOverstay->fetch_assoc()) {
                $rem = (int)$rowO['minutes_remaining'];
                if ($rem < 0) {
                    $rowO['minutes_overdue'] = abs($rem);
                    $overdueVisitors[] = $rowO;
                } elseif ($rem <= 15) {
                    $rowO['minutes_left'] = $rem;
                    $dueSoonVisitors[] = $rowO;
                }
            }
            $stmtOverstay->close();
        }

        echo json_encode([
            'success' => true,
            'new_logs' => $newLogs,
            'max_id' => $maxId,
            'total_registered' => $totalRegisteredToday,
            'total_visitors' => $totalVisitorsToday,
            'overdue_visitors' => $overdueVisitors,
            'due_soon_visitors' => $dueSoonVisitors
        ]);

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
$systemName = secsysGetSystemName($conn);
$systemIcon = secsysGetSystemIcon($conn);
'''

# Find pattern from $conn->set_charset("utf8mb4"); to end of old heartbeat block (exit();\n})
pattern1 = re.compile(
    r'\$conn->set_charset\("utf8mb4"\);.*?'
    r'/\* =========================================================\s*'
    r'INITIAL HEARTBEAT',
    re.DOTALL
)

if not pattern1.search(content):
    print("ERROR: pattern1 not found")
    sys.exit(1)

content = pattern1.sub(lambda m: fast_ajax_block + '\n\n/* =========================================================\n   INITIAL HEARTBEAT', content, count=1)

# 2. Remove the old duplicate save_visitor_info and live_feed block around line 487-781
pattern2 = re.compile(
    r'/\* =========================================================\s*'
    r'AJAX: SAVE VISITOR INFORMATION FORM.*?'
    r'/\* =========================================================\s*'
    r'RECENT VEHICLE ACTIVITY',
    re.DOTALL
)

if not pattern2.search(content):
    print("ERROR: pattern2 not found")
    sys.exit(1)

content = pattern2.sub('/* =========================================================\n   RECENT VEHICLE ACTIVITY', content, count=1)

# 3. Add isPollingLiveCaptures guard in JavaScript
old_poll_js = 'async function pollLiveCaptures() {'
new_poll_js = '''let isPollingLiveCaptures = false;
async function pollLiveCaptures() {
    if (isPollingLiveCaptures) return;
    isPollingLiveCaptures = true;'''

if old_poll_js not in content:
    print("ERROR: old_poll_js not found")
    sys.exit(1)

content = content.replace(old_poll_js, new_poll_js, 1)

# Add finally { isPollingLiveCaptures = false; } to pollLiveCaptures
# Look for setInterval(pollLiveCaptures, 1500);
pattern3 = re.compile(r'(\n\s*\}\s*catch\s*\(err\)\s*\{\s*\}\s*)(\n\s*setInterval\(pollLiveCaptures,\s*1500\);)', re.DOTALL)
if pattern3.search(content):
    content = pattern3.sub(r'\1 finally {\n        isPollingLiveCaptures = false;\n    }\2', content)

with open("guard_dashboard.php", "w", encoding="utf-8") as f:
    f.write(content)

print("SUCCESS: guard_dashboard.php patched successfully!")
