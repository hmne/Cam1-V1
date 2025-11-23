<?php

declare(strict_types=1);

/**
 * Camera Status Display Module - Enterprise Grade
 *
 * Dynamically loaded component that displays:
 * - Camera online/offline status
 * - System information (temperature, memory, signal)
 * - Server/tunnel access buttons
 * - Latest log entries
 *
 * Security Features:
 * - HTML escaping for all output
 * - URL validation before display
 * - Safe file reading with size limits
 *
 * @category  StatusDisplay
 * @package   CameraControl
 * @author    Net Storm
 * @license   Proprietary
 * @version   1.0.0
 * @standards PSR-12, OWASP Top 10, Clean Code, SOLID
 */

// =============================================================================
// INITIALIZATION
// =============================================================================

require_once __DIR__ . '/config/app-config.php';
require_once __DIR__ . '/includes/utilities.php';

// =============================================================================
// DATA RETRIEVAL
// =============================================================================

/**
 * Retrieve and parse camera status data
 *
 * @return array Parsed status data with defaults
 */
function getCameraStatusData(): array
{
    $statusData = readFileSecure(CAMERA_STATUS_FILE);

    if ($statusData === '') {
        return ['N/A', 'N/A', 'N/A', 'N/A'];
    }

    $parts = explode(',', $statusData);

    // Ensure we have exactly 4 parts
    while (count($parts) < 4) {
        $parts[] = 'N/A';
    }

    return array_slice($parts, 0, 4);
}

/**
 * Retrieve tunnel/server URLs with validation
 *
 * @return array Array of validated URLs
 */
function getTunnelUrls(): array
{
    $urls = [];

    foreach (TUNNEL_URL_FILES as $index => $file) {
        $url = readFileSecure($file);

        if ($url !== '') {
            // Validate URL for security
            $validated = validateUrl($url);
            $urls[] = $validated !== false ? $validated : '';
        } else {
            $urls[] = '';
        }
    }

    return $urls;
}

/**
 * Check if camera is currently online
 *
 * @return bool True if camera is online
 */
function isCameraOnline(): bool
{
    if (!file_exists(CAMERA_STATUS_FILE)) {
        return false;
    }

    $lastModified = filemtime(CAMERA_STATUS_FILE);
    if ($lastModified === false) {
        return false;
    }

    $secondsSinceUpdate = time() - $lastModified;

    return $secondsSinceUpdate <= CAMERA_ONLINE_TIMEOUT_SECONDS && $lastModified > 946684800; // After 2000-01-01
}

/**
 * Get formatted last access time
 *
 * @return string Formatted timestamp or 'N/A'
 */
function getLastAccessTime(): string
{
    if (!file_exists(CAMERA_STATUS_FILE)) {
        return 'N/A';
    }

    $timestamp = filemtime(CAMERA_STATUS_FILE);
    if ($timestamp === false || $timestamp === 0) {
        return 'N/A';
    }

    return date('j/n/Y H:i:s', $timestamp);
}

/**
 * Get seconds since last status update
 *
 * @return int Seconds since update (999 if unavailable)
 */
function getSecondsSinceUpdate(): int
{
    if (!file_exists(CAMERA_STATUS_FILE)) {
        return 999;
    }

    $timestamp = filemtime(CAMERA_STATUS_FILE);
    if ($timestamp === false) {
        return 999;
    }

    return time() - $timestamp;
}

/**
 * Retrieve latest relevant log entry (optimized)
 *
 * @return string Latest log entry or empty string
 */
function getLatestLogEntry(): string
{
    static $cachedEntry = null;
    static $cacheTime = 0;

    // Cache for 2 seconds to reduce file I/O
    if ($cachedEntry !== null && (time() - $cacheTime) < 2) {
        return $cachedEntry;
    }

    if (!file_exists(MAIN_LOG_FILE) || !is_readable(MAIN_LOG_FILE)) {
        return '';
    }

    $fileSize = filesize(MAIN_LOG_FILE);
    if ($fileSize === false || $fileSize === 0) {
        return '';
    }

    // Read only last 8KB for performance
    $readSize = min($fileSize, 8192);
    $fp = fopen(MAIN_LOG_FILE, 'r');

    if ($fp === false) {
        return '';
    }

    // Seek to near end of file
    fseek($fp, -$readSize, SEEK_END);
    $content = fread($fp, $readSize);
    fclose($fp);

    if ($content === false) {
        return '';
    }

    // Split into lines (reverse order for latest first)
    $lines = array_reverse(explode("\n", $content));
    $importantMarkers = ['[ OK ]', '[ INFO ]', '[ ERROR ]', '[ FAIL ]', '[ SUCCESS ]'];

    // Find first important log entry
    foreach ($lines as $line) {
        if (empty($line)) {
            continue;
        }

        foreach ($importantMarkers as $marker) {
            if (strpos($line, $marker) !== false) {
                $cachedEntry = $line;
                $cacheTime = time();
                return $line;
            }
        }
    }

    // Return first non-empty line if no important marker found
    foreach ($lines as $line) {
        if (!empty(trim($line))) {
            $cachedEntry = $line;
            $cacheTime = time();
            return $line;
        }
    }

    return '';
}

// =============================================================================
// PREPARE DATA
// =============================================================================

$statusParts = getCameraStatusData();
$tunnelUrls = getTunnelUrls();
$isOnline = isCameraOnline();
$lastAccess = getLastAccessTime();
$secondsSince = getSecondsSinceUpdate();
$latestLog = getLatestLogEntry();

// Read control states
$monitorStatus = readFileSecure(MONITOR_STATUS_FILE, 'off');
$webLiveStatus = readFileSecure(WEB_LIVE_STATUS_FILE, 'off');

// Determine if control panel should be visible
// Show controls when camera is online (regardless of monitor status)
$showControlPanel = $isOnline;

// =============================================================================
// HTML OUTPUT
// =============================================================================
?>

<div class="glass-panel">
    <!-- Camera Status Indicator -->
    <div class="status-container">
        <div class="status-indicator <?= $isOnline ? 'online' : 'offline' ?>">
            <?= $isOnline ? 'Connected (Online)' : 'Disconnected (Offline)' ?>
        </div>
    </div>

    <!-- System Information Grid -->
    <div class="data-grid">
        <div class="data-text">Last access: <?= escapeHtml($lastAccess) ?></div>
        <div class="data-text">Transmitted data: <span id="memoryStatus"><?= escapeHtml($statusParts[0]) ?></span></div>
    </div>

    <div class="data-grid2">
        <div class="data-text">Temperature: <span id="tempStatus"><?= escapeHtml($statusParts[1]) ?></span></div>
        <div class="data-text">Signal: <span id="signalStatus"><?= escapeHtml($statusParts[3]) ?></span></div>
        <div class="data-text">Latency: <span id="pingStatus"><?= escapeHtml($statusParts[2]) ?></span></div>
    </div>

    <?php if ($isOnline): ?>
    <!-- Server Access Buttons -->
    <div class="server-buttons-container">
        <?php
        $serverNames = ['Server 1', 'Server 2', 'Server 3', 'Server 4'];

        foreach ($tunnelUrls as $index => $url) {
            if ($url !== '') {
                $safeUrl = escapeHtml($url);
                $serverName = escapeHtml($serverNames[$index] ?? "Server " . ($index + 1));

                echo '<a class="server-button" href="' . $safeUrl . '/captura.php" '
                    . 'target="_blank" rel="noopener noreferrer">'
                    . $serverName . '</a>';
            }
        }
        ?>
    </div>
    <?php endif; ?>

    <!-- Control Panel Visibility Control (via data attribute) -->
    <div id="controlPanelState"
         data-show-panel="<?= $showControlPanel ? 'true' : 'false' ?>"
         style="display:none;"></div>

    <!-- Hide image container if camera is offline -->
    <?php if (!$isOnline): ?>
        <style>#liveImageContainer, #imageDetails { display: none; }</style>
    <?php endif; ?>

    <!-- Expose status to JavaScript -->
    <script>
    (function() {
        window.cameraOnlineStatus = <?= $isOnline ? 'true' : 'false' ?>;
        window.secondsSinceUpdate = <?= $secondsSince ?>;

        // Update status indicator if WebSocket is not handling it
        if (!window.CameraWS || !window.CameraWS.enabled || !window.CameraWS.isConnected()) {
            var indicator = document.querySelector('.status-indicator');
            if (indicator) {
                indicator.className = 'status-indicator ' + (<?= $isOnline ? 'true' : 'false' ?> ? 'online' : 'offline');
            }
        }
    })();
    </script>

    <!-- Latest Log Entry -->
    <div class="glass-panel">
        <div class="log">
            <?php if ($latestLog !== ''): ?>
                Log: <?= escapeHtml($latestLog) ?>
            <?php else: ?>
                No recent logs available.
            <?php endif; ?>
        </div>
    </div>
</div>