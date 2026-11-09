<?php
/**
 * Internet Time API
 *
 * Returns the current time taken from the internet (NTP time servers, with an
 * HTTPS "Date" header fallback) so the dashboards can show an Internet clock
 * next to the unit's own System clock.
 *
 * The measured offset (internet time - this server's clock) is cached for a
 * few minutes, so time servers are queried at most once per cache window no
 * matter how many dashboards are open.
 *
 * Response:
 *   { success, epoch_ms, source, server_offset_ms, cached, timezone }
 *   epoch_ms = internet time (Unix ms) at the moment this response was built.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const ITIME_CACHE_SECONDS = 300;        // re-sync with the internet every 5 minutes
const ITIME_FAIL_RETRY_SECONDS = 30;    // after a failure, retry sooner
const ITIME_NTP_SERVERS = ['time.google.com', 'time.windows.com', 'pool.ntp.org'];
const ITIME_HTTP_SOURCES = ['https://www.google.com', 'https://www.cloudflare.com'];

$cacheFile = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'secsys_internet_time.json';

/**
 * Query one NTP server (SNTP v3, client mode). Returns offset in ms or null.
 */
function itimeQueryNtp(string $host): ?float
{
    $sock = @fsockopen('udp://' . $host, 123, $errno, $errstr, 1.5);
    if (!$sock) {
        return null;
    }
    stream_set_timeout($sock, 2);

    $t0 = microtime(true);
    $packet = chr(0x1B) . str_repeat(chr(0), 47); // LI=0, VN=3, Mode=3 (client)
    if (@fwrite($sock, $packet) !== 48) {
        fclose($sock);
        return null;
    }
    $data = @fread($sock, 48);
    $t3 = microtime(true);
    fclose($sock);

    if ($data === false || strlen($data) < 48) {
        return null;
    }

    $words = unpack('N12', $data);
    // Words 11-12 = transmit timestamp (seconds since 1900 + 32-bit fraction)
    $seconds = (float)sprintf('%u', $words[11]);
    $fraction = (float)sprintf('%u', $words[12]) / 4294967296.0;
    if ($seconds <= 0) {
        return null;
    }
    $serverUnix = ($seconds - 2208988800.0) + $fraction;

    // Assume the reply was sent halfway through the round trip.
    $localMid = ($t0 + $t3) / 2.0;
    $offsetMs = ($serverUnix - $localMid) * 1000.0;

    // Reject absurd answers (bad packet / kiss-of-death) beyond +-10 years.
    if (abs($offsetMs) > 315360000000) {
        return null;
    }
    return $offsetMs;
}

/**
 * Fallback: read the "Date" header of a well-known HTTPS site (1 s precision).
 */
function itimeQueryHttp(string $url): ?float
{
    $ctx = stream_context_create([
        'http' => ['method' => 'HEAD', 'timeout' => 3, 'ignore_errors' => true,
                   'header' => "User-Agent: SecSys-Clock/1.0\r\n"],
    ]);
    $t0 = microtime(true);
    $headers = @get_headers($url, true, $ctx);
    $t1 = microtime(true);
    if (!$headers) {
        return null;
    }
    $date = $headers['Date'] ?? $headers['date'] ?? null;
    if (is_array($date)) {
        $date = end($date);
    }
    $ts = $date ? strtotime((string)$date) : false;
    if (!$ts) {
        return null;
    }
    // Date header is truncated to the second: add 0.5 s to centre the error.
    return (($ts + 0.5) - (($t0 + $t1) / 2.0)) * 1000.0;
}

function itimeMeasure(): ?array
{
    foreach (ITIME_NTP_SERVERS as $host) {
        $offset = itimeQueryNtp($host);
        if ($offset !== null) {
            return ['offset_ms' => $offset, 'source' => 'NTP ' . $host];
        }
    }
    foreach (ITIME_HTTP_SOURCES as $url) {
        $offset = itimeQueryHttp($url);
        if ($offset !== null) {
            return ['offset_ms' => $offset, 'source' => 'HTTPS ' . parse_url($url, PHP_URL_HOST)];
        }
    }
    return null;
}

/* ---------------------------------------------------------
   Use cached offset when fresh, otherwise re-measure.
--------------------------------------------------------- */
$now = microtime(true);
$cache = null;
if (is_file($cacheFile)) {
    $cache = json_decode((string)@file_get_contents($cacheFile), true);
}

$cacheAge = is_array($cache) ? ($now - (float)($cache['measured_at'] ?? 0)) : INF;
$cacheValid = is_array($cache) && !empty($cache['ok']) && $cacheAge < ITIME_CACHE_SECONDS;
$recentFailure = is_array($cache) && empty($cache['ok']) && $cacheAge < ITIME_FAIL_RETRY_SECONDS;
$force = isset($_GET['force']);

$cached = true;
if ((!$cacheValid && !$recentFailure) || $force) {
    $cached = false;
    $result = itimeMeasure();
    $cache = $result
        ? ['ok' => true, 'offset_ms' => $result['offset_ms'], 'source' => $result['source'], 'measured_at' => $now]
        : ['ok' => false, 'measured_at' => $now,
           // keep the last good offset so a short outage doesn't blank the clock
           'last_offset_ms' => $cache['offset_ms'] ?? ($cache['last_offset_ms'] ?? null),
           'last_source' => $cache['source'] ?? ($cache['last_source'] ?? null),
           'last_good_at' => (!empty($cache['ok']) ? ($cache['measured_at'] ?? null) : ($cache['last_good_at'] ?? null))];
    @file_put_contents($cacheFile, json_encode($cache), LOCK_EX);
}

$tz = 'Asia/Manila';

if (!empty($cache['ok'])) {
    $offset = (float)$cache['offset_ms'];
    echo json_encode([
        'success'          => true,
        'epoch_ms'         => round(microtime(true) * 1000.0 + $offset, 1),
        'source'           => $cache['source'],
        'server_offset_ms' => round($offset, 1),
        'cached'           => $cached,
        'synced_at'        => (int)$cache['measured_at'],
        'timezone'         => $tz,
    ]);
    exit();
}

// Internet unreachable: keep ticking from the last good sync if we have one.
if (isset($cache['last_offset_ms']) && $cache['last_offset_ms'] !== null) {
    $offset = (float)$cache['last_offset_ms'];
    echo json_encode([
        'success'          => true,
        'stale'            => true,
        'epoch_ms'         => round(microtime(true) * 1000.0 + $offset, 1),
        'source'           => (string)($cache['last_source'] ?? 'last sync'),
        'server_offset_ms' => round($offset, 1),
        'cached'           => true,
        'synced_at'        => (int)($cache['last_good_at'] ?? 0),
        'timezone'         => $tz,
    ]);
    exit();
}

echo json_encode([
    'success'  => false,
    'offline'  => true,
    'message'  => 'Could not reach internet time servers.',
    'timezone' => $tz,
]);
