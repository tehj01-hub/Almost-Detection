<?php
/* =========================================================
   YOLOv10 PLATE & VEHICLE CAPTURE VIEWER
   Serves and displays the YOLOv10 license plate crop image,
   the YOLOv10 vehicle capture image, the plate number, and
   vehicle details for a clicked license plate or log entry.
   Usage:
     - Viewer / Modal HTML: vehicle_photo.php?ids=12,13&plate=ABC+1234
     - JSON API for modal:  vehicle_photo.php?format=json&ids=12,13&plate=ABC+1234
     - Raw vehicle image:   vehicle_photo.php?raw=vehicle&file=log_12.jpg
     - Raw plate image:     vehicle_photo.php?raw=plate&file=20261109_080202_...jpg
========================================================= */

require_once __DIR__ . '/app/session_helper.php';
require_once __DIR__ . '/app/system_config.php';

if (($_GET['format'] ?? '') === 'json') {
    @ini_set('display_errors', '0');
    ob_start();
}

// Either role may view (only open role sessions that have a cookie present)
$signedIn = false;
foreach (['Administrator', 'Security Guard'] as $role) {
    $cookieName = secsysRoleSessionName($role);
    if (secsysSessionIdFromCookie($cookieName) === null) {
        continue;
    }
    secsysStartRoleSession($role);
    if (!empty($_SESSION['username'])) {
        $signedIn = true;
        break;
    }
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!$signedIn) {
    if (($_GET['format'] ?? '') === 'json') {
        if (ob_get_length()) {
            ob_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Please log in to view YOLOv10 captures.']);
        exit;
    }
    http_response_code(403);
    exit('Please log in to view vehicle photos.');
}

/**
 * Normalize a plate string to uppercase alphanumeric characters for matching.
 */
function secsysNormPlateKey(string $plate): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($plate)));
}

/**
 * Stream a verified local JPEG file with caching headers.
 */
function secsysStreamJpegFile(string $baseDirPath, string $fileName): void
{
    $baseDir = realpath($baseDirPath);
    if ($baseDir === false || !preg_match('/^[A-Za-z0-9_.-]+\.(jpg|jpeg|png)$/i', $fileName)) {
        http_response_code(404);
        exit('Capture image not found.');
    }
    $fullPath = realpath($baseDir . DIRECTORY_SEPARATOR . $fileName);
    if ($fullPath === false || strpos($fullPath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($fullPath)) {
        http_response_code(404);
        exit('Capture image not found.');
    }
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($fullPath);
    exit;
}

// 1. Handle direct raw image streaming requests
$rawMode = strtolower(trim((string)($_GET['raw'] ?? '')));
$rawFile = trim((string)($_GET['file'] ?? ''));
if ($rawMode === 'vehicle' && $rawFile !== '') {
    secsysStreamJpegFile(__DIR__ . '/uploads/vehicle_captures', basename($rawFile));
}
if ($rawMode === 'plate' && $rawFile !== '') {
    secsysStreamJpegFile(__DIR__ . '/uploads/plate_captures', basename($rawFile));
}

// 2. Parse query parameters (ids and/or plate)
$ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
$ids = array_slice($ids, 0, 15);
$plateParam = trim((string)($_GET['plate'] ?? ''));

if (!$ids && $plateParam === '') {
    if (($_GET['format'] ?? '') === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No license plate or vehicle log selected.']);
        exit;
    }
    http_response_code(400);
    exit('No license plate or vehicle log selected.');
}

if (($_GET['format'] ?? '') === 'json') {
    @ini_set('display_errors', '0');
}

$conn = secsysConnectDb(true);

$matchedLogs = [];
$exactIdsMap = [];

// 3. Look up requested log IDs in active and archive tables
if ($ids && $conn instanceof mysqli) {
    foreach ($ids as $idVal) {
        $exactIdsMap[(int)$idVal] = true;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    foreach (['vehicle_logs', 'vehicle_logs_archive'] as $table) {
        try {
            $stmt = $conn->prepare("
                SELECT id, plate_number, vehicle_type, vehicle_category, direction,
                       date_in, date_out, date_created, vehicle_image, visitor_name, purpose
                FROM `$table`
                WHERE id IN ($placeholders)
                ORDER BY id DESC
            ");
            if ($stmt) {
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $matchedLogs[] = $row;
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            // Ignore table/column differences
        }
    }
}

$plateDisplay = $plateParam;
if ($plateDisplay === '' && !empty($matchedLogs)) {
    $plateDisplay = (string)($matchedLogs[0]['plate_number'] ?? '');
}
$normPlate = secsysNormPlateKey($plateDisplay);

// 4. Also look up recent logs for this normalized plate so clicks by plate number
//    (or visits where the photo is on an earlier/paired log row) resolve cleanly.
if ($normPlate !== '' && $conn instanceof mysqli) {
    foreach (['vehicle_logs', 'vehicle_logs_archive'] as $table) {
        try {
            $stmt = $conn->prepare("
                SELECT id, plate_number, vehicle_type, vehicle_category, direction,
                       date_in, date_out, date_created, vehicle_image, visitor_name, purpose
                FROM `$table`
                WHERE REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') = ?
                ORDER BY id DESC
                LIMIT 12
            ");
            if ($stmt) {
                $stmt->bind_param('s', $normPlate);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $already = false;
                    foreach ($matchedLogs as $existing) {
                        if ((int)$existing['id'] === (int)$row['id'] && ($existing['date_created'] ?? '') === ($row['date_created'] ?? '')) {
                            $already = true;
                            break;
                        }
                    }
                    if (!$already) {
                        $matchedLogs[] = $row;
                    }
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            // Ignore table/column differences
        }
    }
}

// 5. Gather vehicle metadata from matched logs, registered_vehicles, or visitor_vehicles
$vehicleType = '';
$vehicleCategory = '';
$direction = '';
$timestamp = '';
$ownerOrVisitor = '';
$vehicleModelInfo = '';

foreach ($matchedLogs as $logRow) {
    if ($plateDisplay === '' && !empty($logRow['plate_number'])) {
        $plateDisplay = (string)$logRow['plate_number'];
    }
    if ($vehicleType === '' && !empty($logRow['vehicle_type']) && strtolower($logRow['vehicle_type']) !== 'unknown') {
        $vehicleType = (string)$logRow['vehicle_type'];
    }
    if ($vehicleCategory === '' && !empty($logRow['vehicle_category'])) {
        $vehicleCategory = (string)$logRow['vehicle_category'];
    }
    if ($direction === '' && !empty($logRow['direction'])) {
        $direction = strtoupper((string)$logRow['direction']);
    }
    if ($timestamp === '') {
        $timestamp = (string)($logRow['date_out'] ?: ($logRow['date_in'] ?: ($logRow['date_created'] ?? '')));
    }
    if ($ownerOrVisitor === '' && !empty($logRow['visitor_name'])) {
        $ownerOrVisitor = (string)$logRow['visitor_name'];
    }
}

if ($normPlate !== '' && $conn instanceof mysqli) {
    try {
        $regStmt = $conn->prepare("
            SELECT owner_name, vehicle_type, brand, model, address, plate_number
            FROM registered_vehicles
            WHERE REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') = ?
            LIMIT 1
        ");
        if ($regStmt) {
            $regStmt->bind_param('s', $normPlate);
            $regStmt->execute();
            $regRow = $regStmt->get_result()->fetch_assoc();
            $regStmt->close();
            if ($regRow) {
                if ($plateDisplay === '') {
                    $plateDisplay = (string)$regRow['plate_number'];
                }
                if ($vehicleType === '' && !empty($regRow['vehicle_type'])) {
                    $vehicleType = (string)$regRow['vehicle_type'];
                }
                if ($vehicleCategory === '') {
                    $vehicleCategory = 'Registered';
                }
                if ($ownerOrVisitor === '' && !empty($regRow['owner_name'])) {
                    $ownerOrVisitor = (string)$regRow['owner_name'];
                }
                $parts = array_filter([
                    trim((string)($regRow['brand'] ?? '')),
                    trim((string)($regRow['model'] ?? '')),
                ]);
                if ($parts) {
                    $vehicleModelInfo = implode(' • ', $parts);
                }
            }
        }
    } catch (\Throwable $e) {
        // Ignore schema differences
    }

    if ($ownerOrVisitor === '' || $vehicleType === '') {
        try {
            $visStmt = $conn->prepare("
                SELECT visitor_name, vehicle_type, plate_number
                FROM visitor_vehicles
                WHERE REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            if ($visStmt) {
                $visStmt->bind_param('s', $normPlate);
                $visStmt->execute();
                $visRow = $visStmt->get_result()->fetch_assoc();
                $visStmt->close();
                if ($visRow) {
                    if ($vehicleType === '' && !empty($visRow['vehicle_type'])) {
                        $vehicleType = (string)$visRow['vehicle_type'];
                    }
                    if ($vehicleCategory === '') {
                        $vehicleCategory = 'Visitor';
                    }
                    if ($ownerOrVisitor === '' && !empty($visRow['visitor_name'])) {
                        $ownerOrVisitor = (string)$visRow['visitor_name'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore schema differences
        }
    }
}

// 6. Find YOLOv10 Vehicle Capture files and Plate Capture files
$vehCaptureDir = __DIR__ . '/uploads/vehicle_captures';
$plateCaptureDir = __DIR__ . '/uploads/plate_captures';

$exactVehicleFiles = [];
$fallbackVehicleFiles = [];

foreach ($matchedLogs as $logRow) {
    $rowId = (int)($logRow['id'] ?? 0);
    $isExactId = isset($exactIdsMap[$rowId]);
    $candidates = [];
    if (!empty($logRow['vehicle_image'])) {
        $candidates[] = basename((string)$logRow['vehicle_image']);
    }
    if ($rowId > 0) {
        $candidates[] = 'log_' . $rowId . '.jpg';
    }
    foreach ($candidates as $cand) {
        if (preg_match('/^[A-Za-z0-9_.-]+\.jpg$/i', $cand) && is_file($vehCaptureDir . '/' . $cand)) {
            if ($isExactId) {
                $exactVehicleFiles[$cand] = $logRow;
            } else {
                $fallbackVehicleFiles[$cand] = $logRow;
            }
        }
    }
}

// Also check pending_visitors.json if this visitor arrival was just captured
if ($normPlate !== '' && is_file(__DIR__ . '/uploads/pending_visitors.json')) {
    $pvRaw = @file_get_contents(__DIR__ . '/uploads/pending_visitors.json');
    $pvData = is_string($pvRaw) ? json_decode($pvRaw, true) : null;
    if (is_array($pvData['items'] ?? null)) {
        foreach (array_reverse($pvData['items']) as $pvItem) {
            $pvNorm = secsysNormPlateKey((string)($pvItem['normalized'] ?? $pvItem['plate_number'] ?? ''));
            $pvId = (string)($pvItem['pending_id'] ?? '');
            if ($pvNorm === $normPlate && $pvId !== '') {
                $cand = $pvId . '.jpg';
                if (is_file($vehCaptureDir . '/' . $cand) && !isset($exactVehicleFiles[$cand])) {
                    $fallbackVehicleFiles[$cand] = $pvItem;
                }
            }
        }
    }
}

// Scan uploads/plate_captures/*.json (newest first) to find matching YOLOv10 plate crops
$exactPlateCaptures = [];
$plateMatchedCaptures = [];

if (is_dir($plateCaptureDir)) {
    $jsonFiles = glob($plateCaptureDir . '/*.json') ?: [];
    rsort($jsonFiles);
    $jsonFiles = array_slice($jsonFiles, 0, 600);

    foreach ($jsonFiles as $jFile) {
        $rawJson = @file_get_contents($jFile);
        if (!is_string($rawJson) || $rawJson === '') {
            continue;
        }
        $meta = json_decode($rawJson, true);
        if (!is_array($meta)) {
            continue;
        }
        $imgFile = !empty($meta['image'])
            ? basename((string)$meta['image'])
            : (basename($jFile, '.json') . '.jpg');
        if (!is_file($plateCaptureDir . '/' . $imgFile)) {
            continue;
        }

        $metaVehFile = !empty($meta['vehicle_image']) ? basename((string)$meta['vehicle_image']) : '';
        $metaPlateNorm = secsysNormPlateKey((string)($meta['plate'] ?? $meta['display_text'] ?? ''));

        $meta['plate_file'] = $imgFile;
        $meta['vehicle_file'] = ($metaVehFile !== '' && is_file($vehCaptureDir . '/' . $metaVehFile)) ? $metaVehFile : '';

        if ($metaVehFile !== '' && isset($exactVehicleFiles[$metaVehFile])) {
            $exactPlateCaptures[] = $meta;
        } elseif ($normPlate !== '' && $metaPlateNorm === $normPlate) {
            $plateMatchedCaptures[] = $meta;
            if ($meta['vehicle_file'] !== '' && !isset($exactVehicleFiles[$meta['vehicle_file']]) && !isset($fallbackVehicleFiles[$meta['vehicle_file']])) {
                $fallbackVehicleFiles[$meta['vehicle_file']] = $meta;
            }
        }
    }
}

// Select primary YOLOv10 plate capture and primary YOLOv10 vehicle capture
$selectedPlateMeta = null;
if (!empty($exactPlateCaptures)) {
    $selectedPlateMeta = $exactPlateCaptures[0];
} elseif (!empty($plateMatchedCaptures)) {
    $selectedPlateMeta = $plateMatchedCaptures[0];
}

$selectedPlateFile = $selectedPlateMeta['plate_file'] ?? '';
$selectedVehicleFile = '';

if (!empty($selectedPlateMeta['vehicle_file']) && isset($exactVehicleFiles[$selectedPlateMeta['vehicle_file']])) {
    $selectedVehicleFile = $selectedPlateMeta['vehicle_file'];
} elseif (!empty($exactVehicleFiles)) {
    $selectedVehicleFile = (string)array_key_first($exactVehicleFiles);
} elseif (!empty($selectedPlateMeta['vehicle_file'])) {
    $selectedVehicleFile = $selectedPlateMeta['vehicle_file'];
} elseif (!empty($fallbackVehicleFiles)) {
    $selectedVehicleFile = (string)array_key_first($fallbackVehicleFiles);
}

// Backfill metadata from the YOLOv10 capture JSON if DB rows did not have them
if ($selectedPlateMeta) {
    if ($plateDisplay === '' && !empty($selectedPlateMeta['display_text'])) {
        $plateDisplay = (string)$selectedPlateMeta['display_text'];
    }
    if ($vehicleType === '' && !empty($selectedPlateMeta['vehicle_type'])) {
        $vehicleType = (string)$selectedPlateMeta['vehicle_type'];
    }
    if ($vehicleCategory === '' && !empty($selectedPlateMeta['category'])) {
        $vehicleCategory = (string)$selectedPlateMeta['category'];
    }
    if ($direction === '' && !empty($selectedPlateMeta['direction'])) {
        $direction = strtoupper((string)$selectedPlateMeta['direction']);
    }
    if ($timestamp === '' && !empty($selectedPlateMeta['timestamp'])) {
        $timestamp = (string)$selectedPlateMeta['timestamp'];
    }
}

$cameraName = (string)($selectedPlateMeta['camera'] ?? '');
$confidence = isset($selectedPlateMeta['confidence']) ? round(((float)$selectedPlateMeta['confidence']) * 100, 1) : null;

// Legacy raw=1 support
if ($rawMode === '1' || $rawMode === 'true') {
    if ($selectedVehicleFile !== '') {
        secsysStreamJpegFile($vehCaptureDir, $selectedVehicleFile);
    }
    if ($selectedPlateFile !== '') {
        secsysStreamJpegFile($plateCaptureDir, $selectedPlateFile);
    }
    http_response_code(404);
    exit('No capture photo is available for this log yet.');
}

// Build list of all captures for this visit/plate (up to 6) so the user can switch if both IN and OUT exist
$allCaptures = [];
$seenPairs = [];
foreach (array_merge($exactPlateCaptures, $plateMatchedCaptures) as $cap) {
    $pFile = (string)($cap['plate_file'] ?? '');
    $vFile = (string)($cap['vehicle_file'] ?? '');
    if ($vFile === '' && $selectedVehicleFile !== '') {
        $vFile = $selectedVehicleFile;
    }
    $pairKey = $pFile . '|' . $vFile;
    if (isset($seenPairs[$pairKey])) {
        continue;
    }
    $seenPairs[$pairKey] = true;
    $tsRaw = (string)($cap['timestamp'] ?? '');
    $allCaptures[] = [
        'plate_image_url'   => $pFile !== '' ? ('vehicle_photo.php?raw=plate&file=' . rawurlencode($pFile)) : null,
        'vehicle_image_url' => $vFile !== '' ? ('vehicle_photo.php?raw=vehicle&file=' . rawurlencode($vFile)) : null,
        'direction'         => strtoupper((string)($cap['direction'] ?? $direction)),
        'camera'            => (string)($cap['camera'] ?? ''),
        'timestamp'         => $tsRaw !== '' ? date('M d, Y h:i:s A', strtotime($tsRaw)) : '',
        'confidence'        => isset($cap['confidence']) ? round(((float)$cap['confidence']) * 100, 1) : null,
    ];
    if (count($allCaptures) >= 6) {
        break;
    }
}

$plateImageUrl = $selectedPlateFile !== ''
    ? ('vehicle_photo.php?raw=plate&file=' . rawurlencode($selectedPlateFile))
    : null;
$vehicleImageUrl = $selectedVehicleFile !== ''
    ? ('vehicle_photo.php?raw=vehicle&file=' . rawurlencode($selectedVehicleFile))
    : null;

$formattedTime = '';
if ($timestamp !== '') {
    $tsInt = strtotime($timestamp);
    $formattedTime = $tsInt ? date('M d, Y h:i:s A', $tsInt) : $timestamp;
}

$payload = [
    'ok'                 => true,
    'plate_number'       => $plateDisplay !== '' ? $plateDisplay : 'UNKNOWN',
    'vehicle_type'       => $vehicleType !== '' ? $vehicleType : 'Vehicle',
    'category'           => $vehicleCategory !== '' ? $vehicleCategory : 'Vehicle',
    'direction'          => $direction,
    'camera'             => $cameraName,
    'confidence'         => $confidence,
    'timestamp'          => $formattedTime,
    'owner_or_visitor'   => $ownerOrVisitor,
    'vehicle_model_info' => $vehicleModelInfo,
    'plate_image_url'    => $plateImageUrl,
    'vehicle_image_url'  => $vehicleImageUrl,
    'captures'           => $allCaptures,
];

if (($_GET['format'] ?? '') === 'json') {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function ePhoto(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YOLOv10 Capture — <?php echo ePhoto($payload['plate_number']); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .capture-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            max-width: 960px;
            width: 100%;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6);
        }
        .capture-header {
            padding: 20px 24px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-bottom: 1px solid #334155;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .plate-hero {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .plate-badge-large {
            background: #f8fafc;
            color: #0f172a;
            border: 3px solid #38bdf8;
            border-radius: 10px;
            padding: 8px 18px;
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 24px;
            font-weight: 900;
            letter-spacing: 2px;
            box-shadow: 0 4px 12px rgba(56, 189, 248, 0.25);
        }
        .meta-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .meta-pill {
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            background: #334155;
            color: #e2e8f0;
        }
        .meta-pill.yolo {
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.35);
        }
        .meta-pill.dir-in {
            background: rgba(34, 197, 94, 0.18);
            color: #4ade80;
            border: 1px solid rgba(34, 197, 94, 0.4);
        }
        .meta-pill.dir-out {
            background: rgba(239, 68, 68, 0.18);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.4);
        }
        .capture-grid {
            display: grid;
            grid-template-columns: 1fr 1.4fr;
            gap: 20px;
            padding: 24px;
        }
        @media (max-width: 768px) {
            .capture-grid { grid-template-columns: 1fr; }
        }
        .capture-panel {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .panel-title {
            padding: 12px 16px;
            background: #162032;
            border-bottom: 1px solid #334155;
            font-size: 13px;
            font-weight: 700;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .panel-body {
            flex: 1;
            min-height: 240px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: #090d16;
        }
        .panel-body img {
            max-width: 100%;
            max-height: 380px;
            object-fit: contain;
            border-radius: 8px;
            border: 1px solid #1e293b;
        }
        .plate-crop-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            width: 100%;
        }
        .plate-crop-box img {
            max-height: 160px;
            width: auto;
            border: 2px solid #38bdf8;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
        }
        .plate-readout {
            width: 100%;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 13px;
            color: #cbd5e1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .readout-item span {
            display: block;
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 700;
        }
        .readout-item strong {
            color: #f8fafc;
            font-size: 14px;
        }
        .empty-capture {
            color: #64748b;
            text-align: center;
            font-size: 13px;
            padding: 30px 16px;
        }
    </style>
</head>
<body>
    <div class="capture-card">
        <div class="capture-header">
            <div class="plate-hero">
                <div class="plate-badge-large"><?php echo ePhoto($payload['plate_number']); ?></div>
                <div>
                    <div style="font-size:16px; font-weight:700; color:#f8fafc;">
                        <?php echo ePhoto($payload['vehicle_type']); ?> • <?php echo ePhoto($payload['category']); ?>
                    </div>
                    <?php if ($payload['owner_or_visitor'] !== ''): ?>
                        <div style="font-size:13px; color:#94a3b8; margin-top:2px;">
                            <?php echo ePhoto($payload['owner_or_visitor']); ?>
                            <?php echo $payload['vehicle_model_info'] !== '' ? ' (' . ePhoto($payload['vehicle_model_info']) . ')' : ''; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="meta-pills">
                <span class="meta-pill yolo">YOLOv10 Detection</span>
                <?php if ($payload['direction'] !== ''): ?>
                    <span class="meta-pill <?php echo $payload['direction'] === 'OUT' ? 'dir-out' : 'dir-in'; ?>">
                        <?php echo ePhoto($payload['direction']); ?>
                    </span>
                <?php endif; ?>
                <?php if ($payload['timestamp'] !== ''): ?>
                    <span class="meta-pill"><?php echo ePhoto($payload['timestamp']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="capture-grid">
            <!-- YOLOv10 License Plate Capture -->
            <div class="capture-panel">
                <div class="panel-title">
                    <span>🔍 YOLOv10 License Plate Capture</span>
                    <?php if ($payload['confidence'] !== null): ?>
                        <span style="color:#38bdf8;"><?php echo ePhoto((string)$payload['confidence']); ?>% Conf</span>
                    <?php endif; ?>
                </div>
                <div class="panel-body">
                    <div class="plate-crop-box">
                        <?php if ($payload['plate_image_url'] !== null): ?>
                            <img src="<?php echo ePhoto($payload['plate_image_url']); ?>" alt="YOLOv10 License Plate Crop">
                        <?php else: ?>
                            <div class="empty-capture">No YOLOv10 license plate crop recorded for this entry.</div>
                        <?php endif; ?>

                        <div class="plate-readout">
                            <div class="readout-item">
                                <span>Plate Number</span>
                                <strong><?php echo ePhoto($payload['plate_number']); ?></strong>
                            </div>
                            <div class="readout-item">
                                <span>Vehicle Type</span>
                                <strong><?php echo ePhoto($payload['vehicle_type']); ?></strong>
                            </div>
                            <div class="readout-item">
                                <span>Category</span>
                                <strong><?php echo ePhoto($payload['category']); ?></strong>
                            </div>
                            <div class="readout-item">
                                <span>Camera / Gate</span>
                                <strong><?php echo ePhoto($payload['camera'] !== '' ? $payload['camera'] : ($payload['direction'] !== '' ? $payload['direction'] : 'Gate Cam')); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- YOLOv10 Vehicle Capture -->
            <div class="capture-panel">
                <div class="panel-title">
                    <span>🚗 YOLOv10 Vehicle Capture</span>
                    <span><?php echo ePhoto($payload['vehicle_type']); ?></span>
                </div>
                <div class="panel-body">
                    <?php if ($payload['vehicle_image_url'] !== null): ?>
                        <img src="<?php echo ePhoto($payload['vehicle_image_url']); ?>" alt="YOLOv10 Vehicle Capture">
                    <?php else: ?>
                        <div class="empty-capture">No YOLOv10 vehicle photo recorded for this entry.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
