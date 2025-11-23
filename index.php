<?php

declare(strict_types=1);

/**
 * Camera Control Center - Main Interface
 *
 * Enterprise-grade camera management system with live streaming,
 * image capture, and remote monitoring capabilities.
 *
 * @category  CameraControl
 * @package   MainInterface
 * @author    Net Storm
 * @license   Proprietary
 * @version   1.0.0
 * @standards PSR-12, OWASP, Clean Code
 */

// =============================================================================
// INITIALIZATION
// =============================================================================

// Enable output compression for faster response
if (!ob_start('ob_gzhandler')) {
    ob_start();
}

// Try modern bootstrap (Composer + .env) - falls back gracefully
$bootstrapLoaded = false;
if (file_exists(__DIR__ . '/bootstrap.php')) {
    $bootstrapLoaded = require_once __DIR__ . '/bootstrap.php';
}

// Fallback to legacy config if bootstrap failed or not available
if (!$bootstrapLoaded) {
    require_once __DIR__ . '/config/app-config.php';
}

require_once __DIR__ . '/includes/utilities.php';

// Load module system (smart - hides modules on failure)
require_once __DIR__ . '/modules/module-loader.php';

// No caching
sendNoCacheHeaders();

// Security: Generate admin token for button protection
// Session options for enhanced security (from app-config.php settings)
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
        'use_only_cookies' => true
    ]);
}

if (!isset($_SESSION['admin_token'])) {
    $_SESSION['admin_token'] = bin2hex(random_bytes(32));
}

// Security Headers
sendSecurityHeaders();
header('Content-Type: text/html; charset=UTF-8');

// ============================================================================
// AJAX: Write Request Handler (Web Live Control)
// ============================================================================
if (isset($_POST['action']) && $_POST['action'] === 'write') {
    sendAjaxHeaders('text/plain');

    $file = $_POST['file'] ?? '';
    $data = $_POST['data'] ?? '';

    // Whitelist validation (support both relative and absolute paths)
    $allowed = [
        'tmp/web_live.tmp',
        'tmp/web_live_quality.tmp',
        'tmp/web_live_session.tmp',
        'tmp/web_live_previous.tmp',
        'tmp/monitor_heartbeat.tmp',
        WEB_LIVE_STATUS_FILE,
        WEB_LIVE_QUALITY_FILE
    ];
    if (!in_array($file, $allowed, true)) {
        http_response_code(403);
        logMessage("Forbidden file access attempt: $file", 'WARNING');
        exit('Forbidden file: ' . escapeHtml($file));
    }

    // Data validation
    if ($file === WEB_LIVE_STATUS_FILE || $file === 'tmp/web_live.tmp') {
        $data = sanitizeStringWhitelist($data, ['on', 'off'], 'off');
    } elseif ($file === 'tmp/web_live_previous.tmp') {
        $data = sanitizeStringWhitelist($data, ['on', 'off', ''], '');
    } elseif ($file === 'tmp/monitor_heartbeat.tmp') {
        $data = trim($data);
        if ($data !== '' && $data !== '0' && !ctype_digit($data)) {
            http_response_code(400);
            exit('Invalid heartbeat: expected timestamp');
        }
    } elseif ($file === 'tmp/web_live_session.tmp') {
        $data = trim($data);
        if ($data !== '' && !preg_match('/^\d+:[a-z0-9_]+$/i', $data)) {
            http_response_code(400);
            exit('Invalid session format');
        }
    } elseif ($file === WEB_LIVE_QUALITY_FILE || $file === 'tmp/web_live_quality.tmp') {
        $data = trim($data);
        $parts = preg_split('/\s+/', $data);

        if (count($parts) !== 3) {
            http_response_code(400);
            exit('Invalid format: expected "width height quality"');
        }

        $parts = array_map('intval', $parts);
        $width = $parts[0];
        $height = $parts[1];
        $quality = $parts[2];

        if ($width < 320 || $width > 2048 || $height < 240 || $height > 1536 || $quality < 1 || $quality > 100) {
            http_response_code(400);
            logMessage("Invalid quality values: w=$width h=$height q=$quality", 'WARNING');
            exit("Invalid quality values: w=$width h=$height q=$quality");
        }

        $data = "$width $height $quality";
    }

    // Write file atomically
    if (!writeFileAtomic($file, $data)) {
        http_response_code(500);
        exit('Write operation failed');
    }

    logMessage("File written: $file = $data", 'INFO');
    exit('OK');
}


// ============================================================================
// AJAX Handlers
// ============================================================================

// Check for new image
if (isset($_GET['check_new_image'])) {
    sendAjaxHeaders('text/plain');
    echo file_exists(IMAGE_CAPTURE_FILE) ? filemtime(IMAGE_CAPTURE_FILE) : '0';
    exit;
}

// Get image size
if (isset($_GET['get_image_size'])) {
    sendAjaxHeaders('text/plain');
    echo file_exists(IMAGE_CAPTURE_FILE) ? formatFileSize(filesize(IMAGE_CAPTURE_FILE)) : 'Unknown';
    exit;
}

// ============================================================================
// Settings Management
// ============================================================================

$settings = explode(' ', readFileSecure(CAMERA_SETTINGS_FILE, DEFAULT_CAMERA_SETTINGS));

// Handle settings update
if (isset($_POST['submit'])) {
    $rules = VALIDATION_RULES;
    $newSettings = [
        sanitizeInteger($_POST['res'] ?? $rules['resolution']['default'], $rules['resolution']['min'], $rules['resolution']['max'], $rules['resolution']['default']),
        sanitizeInteger($_POST['comp'] ?? $rules['compression']['default'], $rules['compression']['min'], $rules['compression']['max'], $rules['compression']['default']),
        sanitizeInteger($_POST['iso'] ?? $rules['iso']['default'], $rules['iso']['min'], $rules['iso']['max'], $rules['iso']['default']),
        sanitizeInteger($_POST['sat'] ?? $rules['saturation']['default'], $rules['saturation']['min'], $rules['saturation']['max'], $rules['saturation']['default']),
        sanitizeInteger($_POST['rot'] ?? $rules['rotation']['default'], $rules['rotation']['min'], $rules['rotation']['max'], $rules['rotation']['default']),
        sanitizeStringWhitelist($_POST['fx'] ?? 'none', ALLOWED_CAMERA_EFFECTS, 'none'),
        sanitizeInteger($_POST['enf'] ?? $rules['sharpness']['default'], $rules['sharpness']['min'], $rules['sharpness']['max'], $rules['sharpness']['default'])
    ];

    if (writeFileAtomic(CAMERA_SETTINGS_FILE, implode(' ', $newSettings))) {
        logMessage("Settings updated: " . implode(' ', $newSettings), 'INFO');
        if (file_exists(LIBRE_FLAG_FILE)) {
            unlink(LIBRE_FLAG_FILE);
        }
        $settings = $newSettings;
    }
}

// Initialize libre flag
if (!file_exists(LIBRE_FLAG_FILE)) {
    writeFileAtomic(LIBRE_FLAG_FILE, 'on');
}

// Handle capture request (direct write, no CURL)
if (isset($_POST['b1']) && $_POST['b1'] === 'inic') {
    if (!writeFileAtomic(CAPTURE_TRIGGER_FILE, 'on')) {
        http_response_code(500);
        logMessage("Failed to write capture trigger", 'ERROR');
        exit('ERROR: Failed to trigger capture');
    }

    logMessage("Capture triggered directly", 'INFO');
    exit('OK');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="user-scalable=yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= escapeHtml(CAMERA_DISPLAY_NAME) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#1a1a2e">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">

    <link rel="preconnect" href="<?= CAMERA_BASE_URL ?>">
    <link rel="dns-prefetch" href="<?= parse_url(CAMERA_BASE_URL, PHP_URL_HOST) ?>">

    <!-- Fonts: Load async to prevent render blocking -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap">
    </noscript>

    <link rel="shortcut icon" type="image/x-icon" href="assets/images/logo.ico">
    <title><?= CAMERA_DISPLAY_NAME ?> Control Center</title>
    <link rel="apple-touch-icon" href="assets/images/logo.ico">

    <!-- PWA Manifest -->
    <link rel="manifest" href="manifest.json">
    <meta name="msapplication-TileColor" content="#1a1a2e">
    <meta name="msapplication-config" content="assets/images/browserconfig.xml">
    <?php if (SPEED_MODE): ?>
    <!-- Speed Mode: Minified assets for maximum performance -->
    <link rel="stylesheet" href="assets/css/file.min.css?v=<?= file_exists('assets/css/file.min.css') ? filemtime('assets/css/file.min.css') : time() ?>">
    <?php else: ?>
    <!-- Normal Mode: Standard assets -->
    <link rel="stylesheet" href="assets/css/file.css?v=<?= file_exists('assets/css/file.css') ? filemtime('assets/css/file.css') : time() ?>">
    <?php endif; ?>

    <!-- Module CSS (loaded dynamically) -->
    <?= $moduleLoader->getCssIncludes() ?>

    <?php if (!ULTRA_PERFORMANCE): ?>
    <script src="assets/js/jquery-3.7.1.min.js"></script>
    <?php endif; ?>
</head>
<body>
    <?php if (SPEED_MODE): ?>
    <!-- Speed Mode: Static Background Only (no video) -->
    <?php else: ?>
    <!-- Normal Mode: Video Background -->
    <video class="bg-video" autoplay muted loop playsinline poster="assets/images/bg.png" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);min-width:100%;min-height:100%;width:auto;height:auto;z-index:-1;object-fit:cover;background-color:#0d1117;">
        <source src="assets/videos/bg-video.mp4" type="video/mp4">
    </video>
    <?php endif; ?>

    <div class="emoji-button-container">
        <button id="clearFilesButton" class="emoji-button glass" title="Clear Files">🧹</button>
    </div>

    <center>
        <h1 id="mainTitle" style="cursor:pointer;" title="Click to enable notifications"><?= CAMERA_DISPLAY_NAME ?> Control Center</h1>
        <button id="rebootButton" class="glass-button">Reboot</button>
        <button id="shutdownButton" class="glass-button">Shutdown</button>
        
        <!-- Plugin Display Area (Autonomous) -->
        <?php
        // Mark as included (prevents DOCTYPE output)
        $GLOBALS['_app_included'] = true;
        // Plugin loader (safe to delete - no core impact)
        @include_once __DIR__ . '/includes/plugins/plugins-loader.php';
        ?>
        
        <div id="id1"></div>
        
        <div class="glass-panel">
            <div id="myForm" style="display: none">
                <form method="post" action="<?= escapeHtml($_SERVER['PHP_SELF']) ?>">
                    <center><p>
                        <label for="web_live" class="ctl-label">Live</label>
                        <select name="web_live" id="webLiveSelect" class="ctl-select" onchange="toggleWebLive()">
                            <option value="off" selected>No</option>
                            <option value="on">Yes</option>
                        </select>
                        &nbsp;
                        
                        <label for="res" class="ctl-label">Resolution</label>
                        <select name="res" class="ctl-select">
                            <option <?= $settings[0] == 1 ? 'selected' : '' ?> value="1">1280x960</option>
                            <option <?= $settings[0] == 2 ? 'selected' : '' ?> value="2">1920x1440</option>
                            <option <?= $settings[0] == 3 ? 'selected' : '' ?> value="3">2592x1944</option>
                            <option <?= $settings[0] == 4 ? 'selected' : '' ?> value="4">3200x2400</option>
                        </select>
                        &nbsp;
                        
                        <label for="comp" class="ctl-label">Compression</label>
                        <select name="comp" class="ctl-select">
                            <option <?= $settings[1] == 25 ? 'selected' : '' ?> value="25">Low</option>
                            <option <?= $settings[1] == 20 ? 'selected' : '' ?> value="20">Medium</option>
                            <option <?= $settings[1] == 10 ? 'selected' : '' ?> value="10">High</option>
                            <option <?= $settings[1] == 5 ? 'selected' : '' ?> value="5">Very High</option>
                        </select>
                        &nbsp;
                        
                        <label for="iso" class="ctl-label">FPS</label>
                        <select name="iso" class="ctl-select">
                            <option <?= $settings[2] == 0 ? 'selected' : '' ?> value="0">Auto</option>
                            <option <?= $settings[2] == 33333 ? 'selected' : '' ?> value="33333">30</option>
                            <option <?= $settings[2] == 16666 ? 'selected' : '' ?> value="16666">60</option>
                            <option <?= $settings[2] == 8333 ? 'selected' : '' ?> value="8333">120</option>
                            <option <?= $settings[2] == 4166 ? 'selected' : '' ?> value="4166">240</option>
                            <option <?= $settings[2] == 2083 ? 'selected' : '' ?> value="2083">480</option>
                            <option <?= $settings[2] == 1042 ? 'selected' : '' ?> value="1042">960</option>
                        </select><br><br>
                        
                        <label for="sat" class="ctl-label">Image</label>
                        <select name="sat" class="ctl-select">
                            <option <?= $settings[3] == '-35' ? 'selected' : '' ?> value="-35">Color</option>
                            <option <?= $settings[3] == '-100' ? 'selected' : '' ?> value="-100">Gray</option>
                        </select>
                        &nbsp;
                        
                        <label for="rot" class="ctl-label">Rotation</label>
                        <select name="rot" class="ctl-select">
                            <option <?= $settings[4] == 270 ? 'selected' : '' ?> value="270">-90</option>
                            <option <?= $settings[4] == 0 ? 'selected' : '' ?> value="0">0</option>
                            <option <?= $settings[4] == 90 ? 'selected' : '' ?> value="90">90</option>
                            <option <?= $settings[4] == 180 ? 'selected' : '' ?> value="180">180</option>
                        </select>
                        &nbsp;
                        
                        <label for="fx" class="ctl-label">Effect</label>
                        <select name="fx" class="ctl-select">
                            <option <?= $settings[5] == 'none' ? 'selected' : '' ?> value="none">Normal</option>
                            <option <?= $settings[5] == 'negative' ? 'selected' : '' ?> value="negative">Negative</option>
                        </select>
                        &nbsp;
                        
                        <label for="enf" class="ctl-label">Sharpness</label>
                        <select name="enf" class="ctl-select">
                            <option <?= $settings[6] == '25' ? 'selected' : '' ?> value="25">Normal</option>
                            <option <?= $settings[6] == '75' ? 'selected' : '' ?> value="75">Medium</option>
                            <option <?= $settings[6] == '100' ? 'selected' : '' ?> value="100">High</option>
                        </select><br><br>
                        
                        <div id="webLiveContainer" class="glass-panel" style="display:none; margin-top:10px; margin-bottom:10px;">
                            <div id="liveFeed">
                                <img id="webLiveImage" style="width:100%; max-width:800px; border-radius:10px;" />
                            </div>
                            
                            <div id="liveQualityOptions" style="margin-top:10px;">
                                <label for="liveQuality" class="ctl-label">Feed Quality</label>
                                <select name="liveQuality" id="liveQuality" class="ctl-select" onchange="updateLiveQuality(true)">
                                    <option value="adaptive">Adaptive (Auto)</option>
                                    <option value="very-low" selected>Very Low (480p)</option>
                                    <option value="low">Low (640p)</option>
                                    <option value="medium">Medium (800p)</option>
                                    <option value="high">High (1024p)</option>
                                </select>
                            </div>
                        </div>
                        
                        <button id="myBut" onclick="captureImage()" type="button" class="glass-red-button">Capture</button>
                        <p class="capture-note">*Click to capture locally, without Server/Monitor open.</p>
                    </p></center>
                </form>
            </div>
        </div>
    </center>

    <!-- Camera Control JavaScript -->
    <script>
        // Pass PHP constants to JavaScript
        window.CAMERA_NAME = '<?= escapeHtml(CAMERA_DISPLAY_NAME) ?>';
        // Security token for admin actions
        window.ADMIN_TOKEN = '<?= $_SESSION['admin_token'] ?>';
        // Performance settings
        window.ENABLE_PAGE_VISIBILITY = <?= ENABLE_PAGE_VISIBILITY_OPTIMIZATION ? 'true' : 'false' ?>;
        window.JS_MODE = '<?= escapeHtml(JS_MODE) ?>';
    </script>
    <?php
    // Load JavaScript based on JS_MODE setting
    $jsFile = 'assets/js/camera-control.js'; // default: normal (jQuery)

    if (JS_MODE === 'ultra') {
        $jsFile = 'assets/js/camera-control-ultra.js';
        echo "<!-- Ultra Performance Mode: Vanilla JS, aggressive optimization -->\n    ";
    } elseif (JS_MODE === 'fast') {
        $jsFile = 'assets/js/camera-control-vanilla.js';
        echo "<!-- Fast Mode: Vanilla JS, no jQuery -->\n    ";
    } else {
        echo "<!-- Normal Mode: jQuery-based -->\n    ";
    }

    $version = file_exists($jsFile) ? filemtime($jsFile) : time();
    ?>
    <!-- Download Helper (shared across all JS modes) -->
    <script src="assets/js/download-helper.js?v=<?= file_exists('assets/js/download-helper.js') ? filemtime('assets/js/download-helper.js') : time() ?>"></script>
    
    <!-- Camera Control JavaScript (mode-specific) -->
    <script src="<?= $jsFile ?>?v=<?= $version ?>"></script>

    <!-- Adaptive Quality Controller -->
    <script src="assets/js/adaptive-quality.js?v=<?= file_exists('assets/js/adaptive-quality.js') ? filemtime('assets/js/adaptive-quality.js') : time() ?>"></script>

    <!-- Performance Optimizations -->
    <script src="assets/js/performance-optimizations.js?v=<?= file_exists('assets/js/performance-optimizations.js') ? filemtime('assets/js/performance-optimizations.js') : time() ?>"></script>

    <?php if (defined('WEBSOCKET_ENABLED') && WEBSOCKET_ENABLED): ?>
    <!-- WebSocket for Real-time Updates -->
    <script>
        // Override WebSocket server URL from config
        window.WEBSOCKET_SERVER_URL = '<?= escapeHtml(WEBSOCKET_SERVER_URL) ?>';
    </script>
    <script src="assets/js/websocket-client.js?v=<?= file_exists('assets/js/websocket-client.js') ? filemtime('assets/js/websocket-client.js') : time() ?>"></script>
    <?php endif; ?>

    <!-- Module JavaScript (loaded dynamically) -->
    <?= $moduleLoader->getJsIncludes() ?>

    <!-- Module Keybindings Configuration -->
    <?php if (!empty($moduleLoader->getLoadedModules())): ?>
    <script>
        window.MODULE_KEYBINDINGS = <?= $moduleLoader->getKeybindingsJson() ?>;
    </script>
    <?php endif; ?>

    <!-- Service Worker Registration -->
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('/cam1-V1/sw.js')
                .then(function(registration) {
                    console.log('[PWA] Service Worker registered:', registration.scope);
                })
                .catch(function(error) {
                    console.log('[PWA] Service Worker registration failed:', error);
                });
        });
    }
    </script>
</body>
</html>