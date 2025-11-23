<?php

declare(strict_types=1);

/**
 * Unified Status API Endpoint
 *
 * Returns all camera status data in a single JSON response.
 * Optimized for performance with caching and minimal I/O.
 *
 * @category  API
 * @package   CameraControl
 * @author    Net Storm
 * @version   1.0.0
 */

// Performance: Start output buffering with compression
if (!ob_start('ob_gzhandler')) {
    ob_start();
}

require_once __DIR__ . '/../config/app-config.php';
require_once __DIR__ . '/../includes/utilities.php';

// Headers
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

// =============================================================================
// CACHING LAYER (APCu if available, otherwise static)
// =============================================================================

$useApcu = function_exists('apcu_fetch');
$cachePrefix = 'cam1-V1_';
$cacheTtl = 1; // 1 second cache

/**
 * Get cached value or compute it
 */
function getCached(string $key, callable $compute, int $ttl = 1)
{
    global $useApcu, $cachePrefix;

    if ($useApcu) {
        $cached = apcu_fetch($cachePrefix . $key);
        if ($cached !== false) {
            return $cached;
        }

        $value = $compute();
        apcu_store($cachePrefix . $key, $value, $ttl);
        return $value;
    }

    // Fallback: no caching
    return $compute();
}

// =============================================================================
// DATA COLLECTION (Optimized)
// =============================================================================

$response = [];

// Camera online status (check file modification time once)
$statusFile = CAMERA_STATUS_FILE;
$statusMtime = @filemtime($statusFile);
$now = time();

$isOnline = false;
$secondsSince = 999;

if ($statusMtime !== false) {
    $secondsSince = $now - $statusMtime;
    $isOnline = ($secondsSince <= CAMERA_ONLINE_TIMEOUT_SECONDS) && ($statusMtime > 946684800);
}

$response['online'] = $isOnline;
$response['secondsSince'] = $secondsSince;
$response['timestamp'] = $now;

// Status data (cached)
$response['status'] = getCached('status_data', function () use ($statusFile) {
    $content = @file_get_contents($statusFile);
    if ($content === false || $content === '') {
        return ['N/A', 'N/A', 'N/A', 'N/A'];
    }

    $parts = explode(',', trim($content));
    while (count($parts) < 4) {
        $parts[] = 'N/A';
    }

    return array_slice($parts, 0, 4);
});

// Last access time
$response['lastAccess'] = $statusMtime ? date('j/n/Y H:i:s', $statusMtime) : 'N/A';

// Tunnel URLs (cached for 5 seconds)
$response['urls'] = getCached('tunnel_urls', function () {
    $urls = [];
    foreach (TUNNEL_URL_FILES as $file) {
        $url = @file_get_contents($file);
        if ($url !== false && $url !== '') {
            $url = trim($url);
            // Basic URL validation
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $urls[] = $url;
            }
        }
    }
    return $urls;
}, 5);

// Battery status (if plugin exists)
$batteryFile = __DIR__ . '/../tmp/batmon/status.tmp';
if (file_exists($batteryFile)) {
    $batteryContent = @file_get_contents($batteryFile);
    if ($batteryContent !== false) {
        $parts = explode('|', trim($batteryContent));
        if (count($parts) >= 4) {
            $response['battery'] = [
                'percent' => (float) $parts[0],
                'voltage' => (float) $parts[1],
                'charging' => $parts[2] === 'true',
            ];
        }
    }
}

// Network status (if plugin exists)
$netmonFile = __DIR__ . '/../tmp/netmon/status.tmp';
if (file_exists($netmonFile)) {
    $netContent = @file_get_contents($netmonFile);
    if ($netContent !== false) {
        $parts = explode('|', trim($netContent));
        if (count($parts) >= 6) {
            $response['network'] = [
                'ssid' => $parts[0],
                'signal' => $parts[1],
                'speed' => $parts[2],
                'latency' => $parts[3],
                'quality' => $parts[4],
            ];
        }
    }
}

// Live stream status
$webLiveFile = WEB_LIVE_STATUS_FILE;
$response['liveActive'] = (@file_get_contents($webLiveFile) === 'on');

// Output JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
