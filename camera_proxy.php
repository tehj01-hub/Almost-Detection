<?php
/**
 * Single-Port Camera & LPR API Reverse Proxy (Port 80 -> 127.0.0.1:5000)
 *
 * Allows forwarding ONLY Apache Port 80 (via VS Code Port Forwarding, Dev Tunnels,
 * ngrok, Cloudflare Tunnel, or LAN IP) while seamlessly serving both:
 *   1. Live MJPEG video feeds (/video/entrance, /video/exit, /video, /video_feed)
 *   2. Detection server JSON APIs (/api/status, /api/cameras, /api/detections, etc.)
 *
 * Security & Concurrency Guarantees:
 *   - Requires an authenticated Security Guard or Administrator session.
 *   - Releases PHP session locks immediately (read_and_close) so MJPEG streams
 *     never block page navigation or AJAX polling.
 *   - Strict path whitelist pinned to 127.0.0.1:5000 (prevents SSRF).
 */

require_once __DIR__ . '/app/session_helper.php';

/* =========================================================
   1. AUTHENTICATE WITHOUT HOLDING A SESSION LOCK
========================================================= */
@session_cache_limiter('');

$requestedRole = trim((string)($_GET['role'] ?? ''));
$rolesToTry = ($requestedRole === 'Administrator')
    ? ['Administrator', 'Security Guard']
    : ['Security Guard', 'Administrator'];

$isAuthenticated = false;

foreach ($rolesToTry as $roleCandidate) {
    $cookieName = secsysRoleSessionName($roleCandidate);
    $sid = secsysSessionIdFromCookie($cookieName);
    if ($sid === null) {
        continue;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name($cookieName);
    session_id($sid);
    @session_start([
        'read_and_close' => true,
        'use_cookies'    => 0,
    ]);

    if (!empty($_SESSION['username'])) {
        $isAuthenticated = true;
        break;
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!$isAuthenticated) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized session. Please log in first.',
    ]);
    exit();
}

/* =========================================================
   2. VALIDATE & WHITELIST UPSTREAM PATH (PINNED TO :5000)
========================================================= */
$UPSTREAM_HOST = '127.0.0.1';
$UPSTREAM_PORT = 5000;

$rawPath = trim((string)($_GET['path'] ?? ($_SERVER['PATH_INFO'] ?? '')));
$parsedPath = @parse_url($rawPath);
$cleanPath = '/' . ltrim((string)($parsedPath['path'] ?? ''), '/');

$streamPaths = [
    '/video',
    '/video/entrance',
    '/video/exit',
    '/video_feed',
];

$apiPaths = [
    '/api/status',
    '/api/cameras',
    '/api/detections',
    '/api/refresh_registered',
    '/api/reload',
    '/api/reload_code',
    '/api/openvino_test',
    '/api/detect',
];

$isStream = in_array($cleanPath, $streamPaths, true);
$isApi = in_array($cleanPath, $apiPaths, true);

if (!$isStream && !$isApi) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'success' => false,
        'message' => 'Unsupported camera proxy endpoint.',
    ]);
    exit();
}

// Forward optional extra query params (excluding proxy-internal params)
$forwardQueryParams = [];
if (!empty($parsedPath['query'])) {
    parse_str($parsedPath['query'], $embeddedParams);
    if (is_array($embeddedParams)) {
        $forwardQueryParams = array_merge($forwardQueryParams, $embeddedParams);
    }
}
foreach ($_GET as $k => $v) {
    if (in_array($k, ['path', 'role', 't', '_'], true)) {
        continue;
    }
    $forwardQueryParams[$k] = $v;
}

$upstreamTarget = $cleanPath;
if (!empty($forwardQueryParams)) {
    $upstreamTarget .= '?' . http_build_query($forwardQueryParams);
}

/* =========================================================
   3. MJPEG LIVE STREAM RELAY (/video/*)
========================================================= */
if ($isStream) {
    @set_time_limit(0);
    ignore_user_abort(false);

    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', 'off');
    @ini_set('implicit_flush', '1');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    ob_implicit_flush(true);

    $errno = 0;
    $errstr = '';
    // Loopback connects succeed instantly when the server is up; a closed
    // port takes ~2s to be refused on Windows, so keep the wait short.
    $fp = @fsockopen($UPSTREAM_HOST, $UPSTREAM_PORT, $errno, $errstr, 0.75);

    if (!$fp) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo 'Camera detection server offline';
        exit();
    }

    stream_set_timeout($fp, 8);

    // Use HTTP/1.0 so Flask/Werkzeug streams raw multipart frames without chunked encoding
    $httpReq = "GET {$upstreamTarget} HTTP/1.0\r\n"
             . "Host: {$UPSTREAM_HOST}:{$UPSTREAM_PORT}\r\n"
             . "Connection: close\r\n\r\n";

    if (@fwrite($fp, $httpReq) === false) {
        @fclose($fp);
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Failed to request upstream camera stream';
        exit();
    }

    $statusLine = @fgets($fp, 1024);
    if ($statusLine === false || !preg_match('#^HTTP/\d+\.\d+\s+200\b#i', $statusLine)) {
        @fclose($fp);
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Upstream camera stream unavailable';
        exit();
    }

    $upstreamContentType = 'multipart/x-mixed-replace; boundary=frame';
    while (!feof($fp)) {
        $headerLine = @fgets($fp, 2048);
        if ($headerLine === false) {
            break;
        }
        $trimmedHeader = rtrim($headerLine, "\r\n");
        if ($trimmedHeader === '') {
            break; // End of HTTP response headers
        }
        if (stripos($trimmedHeader, 'Content-Type:') === 0) {
            $ctValue = trim(substr($trimmedHeader, strlen('Content-Type:')));
            if ($ctValue !== '') {
                $upstreamContentType = $ctValue;
            }
        }
    }

    header('Content-Type: ' . $upstreamContentType);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Accel-Buffering: no');

    stream_set_timeout($fp, 15);

    while (!feof($fp) && !connection_aborted()) {
        $chunk = @fread($fp, 16384);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) {
                break;
            }
            usleep(5000);
            continue;
        }
        echo $chunk;
        @flush();
    }

    @fclose($fp);
    exit();
}

/* =========================================================
   4. JSON API RELAY (/api/*)
========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
    exit();
}

$headers = [
    "Host: {$UPSTREAM_HOST}:{$UPSTREAM_PORT}",
    'Accept: application/json',
    'Connection: close',
];

$rawBody = '';
if ($method === 'POST') {
    $rawBody = (string)file_get_contents('php://input');
    $incomingContentType = trim((string)($_SERVER['CONTENT_TYPE'] ?? 'application/json'));
    if ($incomingContentType === '') {
        $incomingContentType = 'application/json';
    }
    $headers[] = 'Content-Type: ' . $incomingContentType;
    $headers[] = 'Content-Length: ' . strlen($rawBody);
}

$httpOptions = [
    'method'           => $method,
    'header'           => implode("\r\n", $headers) . "\r\n",
    'timeout'          => 4.0,
    'ignore_errors'    => true,
    'protocol_version' => 1.0,
];

if ($method === 'POST') {
    $httpOptions['content'] = $rawBody;
}

$ctx = stream_context_create(['http' => $httpOptions]);
$upstreamUrl = "http://{$UPSTREAM_HOST}:{$UPSTREAM_PORT}{$upstreamTarget}";

// Quick reachability check: a closed port on Windows takes ~2s to refuse,
// which would block every 5-second status poll while the server is off.
$probe = @fsockopen($UPSTREAM_HOST, $UPSTREAM_PORT, $probeErrno, $probeErrstr, 0.75);
if ($probe) {
    fclose($probe);
    $responseBody = @file_get_contents($upstreamUrl, false, $ctx);
} else {
    $responseBody = false;
}

if ($responseBody === false) {
    // Keep 5-second background /api/status polling silent in browser DevTools when offline
    if ($cleanPath === '/api/status') {
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'offline' => true,
            'message' => 'AI detection server (localhost:5000) is offline.',
        ]);
        exit();
    }

    http_response_code(503);
    echo json_encode([
        'success' => false,
        'offline' => true,
        'message' => 'AI detection server (localhost:5000) is offline. Please start detect_plate_server.py.',
    ]);
    exit();
}

$upstreamStatus = 200;
if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
    if (preg_match('#^HTTP/\d+\.\d+\s+(\d{3})#i', $http_response_header[0], $m)) {
        $upstreamStatus = (int)$m[1];
    }
}

// Gracefully handle lightweight stream scripts (e.g. camera_stream.py) that lack /api/status
if ($cleanPath === '/api/status' && $upstreamStatus === 404) {
    http_response_code(200);
    echo json_encode([
        'success'            => false,
        'offline'            => false,
        'status_unsupported' => true,
        'message'            => 'Stream server on port 5000 does not expose /api/status.',
    ]);
    exit();
}

http_response_code($upstreamStatus);
echo $responseBody;
