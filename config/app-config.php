<?php

declare(strict_types=1);

/**
 * Application Configuration
 *
 * Centralized configuration for the camera control system.
 * Defines all constants, settings, and environment variables.
 *
 * Security Logic (File Naming):
 * - Website files: NO extension (scripts/shbattery_) - prevents direct execution
 * - Camera files: WITH extension (battery.sh) - enables execution
 * - Example: shboot_ (website) → boot.sh (camera)
 *
 * Performance Optimizations:
 * - Session security hardening
 * - Output buffering enabled
 * - Realpath cache configured
 * - For OPcache: Enable in php.ini (opcache.enable=1, opcache.memory_consumption=128)
 *
 * @category  Configuration
 * @package   CameraControl
 * @author    Net Storm
 * @license   Proprietary
 * @version   1.0.0
 * @standards PSR-12, Twelve-Factor App, OWASP Top 10
 */

// =============================================================================
// ENVIRONMENT CONFIGURATION
// =============================================================================

// Error Handling (Production-safe)
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../log/php_errors.log');

// Performance: Session handling optimization
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_secure', '0'); // Set to '1' if using HTTPS

// Performance: Output buffering
ini_set('output_buffering', '4096');
ini_set('implicit_flush', '0');

// Performance: Realpath cache (increase for better performance)
ini_set('realpath_cache_size', '4096k');
ini_set('realpath_cache_ttl', '600');

// Timezone
date_default_timezone_set('Asia/Kuwait');

// =============================================================================
// APPLICATION CONSTANTS
// =============================================================================

// Camera Identity
define('CAMERA_ID', 'cam1-V1');
define('CAMERA_DISPLAY_NAME', 'N.S-Cam1-V1');
define('CAMERA_BASE_URL', 'http://XX.com/' . CAMERA_ID);

// Performance Settings
// SPEED_MODE: true = static background image only (faster), false = video background (slower)
define('SPEED_MODE', false);

// JS_MODE: JavaScript engine to use
// 'normal' = jQuery-based (camera-control.js) - Most compatible
// 'fast'   = Vanilla JS (camera-control-vanilla.js) - Faster, no jQuery
// 'ultra'  = Ultra Performance (camera-control-ultra.js) - Fastest, aggressive optimization
define('JS_MODE', 'normal');

// Backward compatibility - DO NOT USE, use JS_MODE instead
define('ULTRA_PERFORMANCE', JS_MODE === 'ultra');

// WEBSOCKET_ENABLED: true = Use WebSocket for real-time updates, false = HTTP polling
// Requires WebSocket server running on VPS (see websocket/INSTALL.md)
define('WEBSOCKET_ENABLED', true);  // Set to true after configuring VPS

// WebSocket Server URL (update with your VPS IP)
define('WEBSOCKET_SERVER_URL', 'wss://193.160.119.136:8443');

// =============================================================================
// FILE SYSTEM PATHS
// =============================================================================

// Base Directories
define('APP_ROOT', dirname(__DIR__));
define('TMP_DIR', APP_ROOT . '/tmp');
define('LOG_DIR', APP_ROOT . '/log');

// Image Files
define('IMAGE_CAPTURE_FILE', APP_ROOT . '/pic.jpg');
define('IMAGE_LIVE_FILE', APP_ROOT . '/live.jpg');

// Configuration Files
define('CAMERA_SETTINGS_FILE', TMP_DIR . '/var.tmp');
define('CAPTURE_TRIGGER_FILE', TMP_DIR . '/onoff.tmp');
define('LIBRE_FLAG_FILE', TMP_DIR . '/libre.tmp');

// Status Files
define('CAMERA_STATUS_FILE', TMP_DIR . '/status.tmp');
define('NETWORK_STATUS_FILE', TMP_DIR . '/network.tmp');
define('MONITOR_STATUS_FILE', TMP_DIR . '/monitor.tmp');

// Live Stream Control
define('WEB_LIVE_STATUS_FILE', TMP_DIR . '/web_live.tmp');
define('WEB_LIVE_QUALITY_FILE', TMP_DIR . '/web_live_quality.tmp');
define('LIVE_HEARTBEAT_FILE', TMP_DIR . '/live_heartbeat.tmp');

// Tunnel/Server URLs
define('TUNNEL_URL_FILES', [
    TMP_DIR . '/url.tmp',
    TMP_DIR . '/url2.tmp',
    TMP_DIR . '/url3.tmp',
    TMP_DIR . '/url4.tmp'
]);

// SSH Access
define('SSH_CONNECTION_FILE', TMP_DIR . '/ssh2.tmp');

// System Info
// (Removed unused constants: SYSTEM_INFO_FILE, IP_ADDRESS_FILE, TIMEZONE_FILE)

// Log Files
define('MAIN_LOG_FILE', LOG_DIR . '/log.txt');
define('PING_LOG_FILE', LOG_DIR . '/ping.txt');
define('ERROR_LOG_FILE', LOG_DIR . '/php_errors.log');

// =============================================================================
// SECURITY CONSTANTS
// =============================================================================

// File Operation Limits
define('MAX_READABLE_FILE_SIZE', 1048576);      // 1 MB
define('MAX_UPLOADABLE_FILE_SIZE', 80000000);   // 80 MB

// File Locking
define('FILE_LOCK_TIMEOUT_SECONDS', 5);
define('FILE_LOCK_RETRY_MICROSECONDS', 100000); // 100ms

// Allowed Directories (Whitelist)
define('ALLOWED_UPLOAD_DIRECTORIES', ['tmp', 'log', '']);

// Allowed File Extensions (Whitelist)
define('ALLOWED_FILE_EXTENSIONS', ['jpg', 'jpeg', 'tmp', 'txt', 'log']);

// =============================================================================
// DYNAMIC PLUGIN FILES (Loaded from plugins-loader.php)
// =============================================================================

// Get plugin files dynamically (if plugins system exists)
// CRITICAL: Read manifest.json directly (don't load plugins-loader.php)
// This prevents "file already loaded" issue when index.php includes it
$plugin_files = [];
$manifest_file = __DIR__ . '/../includes/plugins/manifest.json';
if (file_exists($manifest_file)) {
    $manifest = @json_decode(file_get_contents($manifest_file), true);
    if ($manifest && isset($manifest['plugins'])) {
        foreach ($manifest['plugins'] as $plugin) {
            if (!empty($plugin['data_sources'])) {
                $plugin_files = array_merge($plugin_files, $plugin['data_sources']);
            }
        }
    }
}

// Complete File Whitelist (for write operations)
define('ALLOWED_WRITABLE_FILES', array_merge([
    // Status files
    'tmp/status.tmp',
    'tmp/libre.tmp',
    'tmp/onoff.tmp',
    'tmp/monitor.tmp',
    'tmp/var.tmp',
    'tmp/network.tmp',
    'tmp/web_live.tmp',
    'tmp/web_live_quality.tmp',
    'tmp/web_live_session.tmp',
    'tmp/web_live_previous.tmp',
    'tmp/live_heartbeat.tmp',
    'tmp/monitor_heartbeat.tmp',
    'tmp/system_info.tmp',
    'tmp/ip.tmp',
    'tmp/timezone.tmp',
    'tmp/camera_heartbeat.tmp',
    'tmp/shutdown.tmp',
    'tmp/reboot.tmp',

    // Tunnel URLs
    'tmp/url.tmp',
    'tmp/url2.tmp',
    'tmp/url3.tmp',
    'tmp/url4.tmp',
    'tmp/ssh2.tmp',

    // Log files
    'log/log.txt',
    'log/ping.txt',
    'log/combined.log',
    'log/sync.log',

    // Image files
    'pic.jpg',
    'test.jpg',
    'live.jpg'
], $plugin_files)); // ← Plugin files added dynamically!

// Files that should be appended (not overwritten)
// Note: Plugin log files are auto-detected by pattern (log/*.log)
define('APPEND_MODE_FILES', [
    'log/log.txt',
    'log/ping.txt',
    'log/combined.log'
]);

// =============================================================================
// CAMERA SETTINGS
// =============================================================================

// Default Camera Settings (resolution compression iso saturation rotation effect sharpness)
define('DEFAULT_CAMERA_SETTINGS', '3 5 33333 -35 0 none 100');

// Camera Status Timeout (HTTP fallback mode only)
// WebSocket mode uses connection state (no timeout needed)
// HTTP mode: Status file older than 15 seconds = offline
define('CAMERA_ONLINE_TIMEOUT_SECONDS', 15);

// =============================================================================
// LIVE STREAM SETTINGS
// =============================================================================

// Quality Presets (width height quality)
define('LIVE_QUALITY_PRESETS', [
    'very-low' => [480, 360, 8],
    'low'      => [640, 480, 16],
    'medium'   => [800, 600, 24],
    'high'     => [1024, 768, 32]
]);

// Default Live Stream Quality
define('DEFAULT_LIVE_QUALITY', 'very-low');

// Page Visibility Optimization
// When enabled: Reduces updates when browser tab is hidden (saves battery on mobile)
// When disabled: Continuous updates even when tab is hidden
// Recommended: true for mobile, false for dedicated monitoring screens
define('ENABLE_PAGE_VISIBILITY_OPTIMIZATION', true);

// =============================================================================
// HTTP/AJAX SETTINGS
// =============================================================================

// AJAX Timeout
define('AJAX_TIMEOUT_MILLISECONDS', 5000); // 5 seconds

// Status Update Interval
define('STATUS_UPDATE_INTERVAL_MS', 2000); // 2 seconds

// Live Stream Update Interval
define('LIVE_STREAM_UPDATE_INTERVAL_MS', 1500); // 1.5 seconds

// =============================================================================
// VALIDATION RULES
// =============================================================================

// Camera Settings Validation Rules
define('VALIDATION_RULES', [
    'resolution' => ['min' => 1, 'max' => 4, 'default' => 3],
    'compression' => ['min' => 5, 'max' => 25, 'default' => 5],
    'iso' => ['min' => 0, 'max' => 33333, 'default' => 33333],
    'saturation' => ['min' => -100, 'max' => 100, 'default' => -35],
    'rotation' => ['min' => 0, 'max' => 270, 'default' => 0],
    'sharpness' => ['min' => 25, 'max' => 100, 'default' => 100],
]);

// Allowed Effects
define('ALLOWED_CAMERA_EFFECTS', ['none', 'negative']);

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Get application root directory
 *
 * @return string Absolute path to application root
 */
function getApplicationRoot(): string
{
    return APP_ROOT;
}

/**
 * Get environment variable with fallback
 *
 * @param string $key     Environment variable name
 * @param mixed  $default Default value if not found
 *
 * @return mixed Environment variable value or default
 */
function env(string $key, $default = null)
{
    $value = getenv($key);
    return ($value !== false) ? $value : $default;
}

/**
 * Ensure core files exist
 * Creates essential system files on first run to prevent 404 errors
 * Called automatically by app-config.php
 *
 * @return void
 */
function ensure_core_files(): void
{
    // Core directories
    $dirs = ['tmp', 'log'];

    // Core files with default values (minimal - only essentials)
    $files = [
        'tmp/status.tmp' => '0',
        'tmp/web_live.tmp' => 'off',
        'tmp/web_live_quality.tmp' => '640 480 80',
        'tmp/live_heartbeat.tmp' => '0',
        'log/log.txt' => '',
    ];

    // Create directories
    foreach ($dirs as $dir) {
        $path = APP_ROOT . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    // Create files
    foreach ($files as $file => $content) {
        $path = APP_ROOT . '/' . $file;

        if (!file_exists($path)) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($path, $content);

            // Files created with current timestamp
            // JavaScript will handle offline detection via file age checking
        }
    }
}

// Auto-initialize core files
// Smart check: only run if essential files are missing OR enough time passed
$essential_missing = !file_exists(APP_ROOT . '/tmp/status.tmp') ||
                     !file_exists(APP_ROOT . '/tmp/web_live.tmp') ||
                     !file_exists(APP_ROOT . '/log/log.txt');

$init_marker = APP_ROOT . '/tmp/status.tmp';
$time_passed = !file_exists($init_marker) || (time() - filemtime($init_marker)) > 3600;

if ($essential_missing || $time_passed) {
    try {
        ensure_core_files();
    } catch (Throwable $e) {
        error_log("[AppConfig] Init failed: " . $e->getMessage());
    }
}