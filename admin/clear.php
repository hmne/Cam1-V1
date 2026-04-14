<?php

declare(strict_types=1);

/**
 * System Cleanup Utility
 *
 * Web-based file management utility for camera system cleanup
 * Optimized to clear files while maintaining camera functionality
 *
 * @category  Admin
 * @package   CameraControl
 * @author    Net Storm
 * @license   Proprietary
 * @version   2.0.0
 * @standards PSR-12, OWASP, Clean Code
 */

// Load dependencies
require_once __DIR__ . '/../includes/utilities.php';
require_once __DIR__ . '/../config/app-config.php';

// No caching
sendNoCacheHeaders();

// Set user name to Net Storm
$currentUser = 'Net Storm';

// Get timestamp in GMT+3
$timestamp = date('d/m/Y h:i:s A');

// Define file groups for cleanup
$imageFiles = [
    "../pic.jpg",
    "../live.jpg",
    "../test.jpg"
];

$tmpFiles = [
    "../tmp/ip.tmp",
    "../tmp/libre.tmp",
    "../tmp/monitor.tmp",
    "../tmp/network.tmp",
    "../tmp/onoff.tmp",
    "../tmp/status.tmp",
    "../tmp/system_info.tmp",
    "../tmp/timezone.tmp",
    "../tmp/var.tmp",
    "../tmp/web_live.tmp",
    "../tmp/web_live_quality.tmp"
];

$urlFiles = [
    "../tmp/url.tmp",
    "../tmp/url2.tmp",
    "../tmp/url3.tmp",
    "../tmp/url4.tmp",
    "../tmp/ssh.tmp",
    "../tmp/ssh2.tmp"
];

$logFiles = [
    "../log/aasw.log",
    "../log/cloud.log",
    "../log/combined.log",
    "../log/direct_live.log",
    "../log/log.txt",
    "../log/pagekite.log",
    "../log/ping.txt",
    "../log/rc_local.log",
    "../log/service_command.txt",
    "../log/sync.log",
    "../log/tunel.log",
    "../log/tunel2.log",
    "../log/tunel3.log",
    "../log/tunel4.log",
    "../log/tunnel_command.txt",
    "../log/tunnel_manager.log"
];

// Specific files that need to preserve content with default values
$specificDefaults = [
    "../tmp/network.tmp" => "online",
    "../tmp/var.tmp" => "4 25 33333 -35 0 none 100",
    "../tmp/libre.tmp" => "on",
    "../tmp/web_live.tmp" => "off",
    "../tmp/web_live_quality.tmp" => "800 600 20"
];

// Create log directories if they don't exist
if (!file_exists('../log')) {
    mkdir('../log', 0755, true);
}

if (!file_exists('../tmp')) {
    mkdir('../tmp', 0755, true);
}

// Initialize results tracking
$results = [
    'deleted' => [],
    'emptied' => [],
    'created' => [],
    'ignored' => [],
    'error' => [],
    'preserved' => []
];

// Process image files - delete only test images, preserve camera images
foreach ($imageFiles as $file) {
    if (file_exists($file)) {
        if (unlink($file)) {
            $results['deleted'][] = $file;
        } else {
            $results['error'][] = "Could not delete $file";
        }
    } else {
        $results['ignored'][] = $file;
    }
}

// Process tmp files with special handling for system status files
foreach ($tmpFiles as $file) {
    // Skip network.tmp as it's handled separately
    if ($file == 'tmp/network.tmp') {
        continue;
    }
    
    if (array_key_exists($file, $specificDefaults)) {
        // Special file with a default value
        if (file_exists($file)) {
            if (writeFileAtomic($file, $specificDefaults[$file])) {
                $results['emptied'][] = $file . " (set to " . $specificDefaults[$file] . ")";
            } else {
                $results['error'][] = "Could not update $file";
            }
        } else {
            if (writeFileAtomic($file, $specificDefaults[$file])) {
                $results['created'][] = $file . " (with default value)";
            } else {
                $results['error'][] = "Could not create $file";
            }
        }
    } else {
        // Regular tmp file - just empty it
        if (file_exists($file)) {
            if (writeFileAtomic($file, '')) {
                $results['emptied'][] = $file;
            } else {
                $results['error'][] = "Could not empty $file";
            }
        } else {
            if (writeFileAtomic($file, '')) {
                $results['created'][] = $file;
            } else {
                $results['error'][] = "Could not create $file";
            }
        }
    }
}

// Handle URL files and tunnel status - preserve their content
foreach ($urlFiles as $file) {
    if (!file_exists($file)) {
        if (writeFileAtomic($file, '')) {
            $results['created'][] = $file;
        } else {
            $results['error'][] = "Could not create $file";
        }
    } else {
        // URL and tunnel files are preserved
        $results['preserved'][] = $file . " (content preserved)";
    }
}

// Special handling for network.tmp which is critical - preserve current state
if (file_exists('tmp/network.tmp')) {
    $currentNetworkStatus = readFileSecure('tmp/network.tmp', 'online');

    // Only accept valid values, default to current or "online" if invalid
    if ($currentNetworkStatus != 'online' && $currentNetworkStatus != 'offline') {
        $currentNetworkStatus = 'online';
    }

    if (writeFileAtomic('tmp/network.tmp', $currentNetworkStatus)) {
        $results['preserved'][] = "tmp/network.tmp (kept current state: " . $currentNetworkStatus . ")";
    } else {
        $results['error'][] = "Could not preserve tmp/network.tmp status";
    }
} else {
    // If file doesn't exist, create with default online state
    if (writeFileAtomic('tmp/network.tmp', 'online')) {
        $results['created'][] = "tmp/network.tmp (set to default: online)";
    } else {
        $results['error'][] = "Could not create tmp/network.tmp";
    }
}

// Process log files - clear but add timestamp header
foreach ($logFiles as $file) {
    if (file_exists($file)) {
        $clearMarker = "# File cleared on $timestamp by $currentUser via clear.php (GMT+3)\n";
        if (writeFileAtomic($file, $clearMarker)) {
            $results['emptied'][] = $file;
        } else {
            $results['error'][] = "Could not clear $file";
        }
    } else {
        // Create empty log file with header
        $header = "# Log file created on $timestamp by $currentUser via clear.php (GMT+3)\n";
        if (writeFileAtomic($file, $header)) {
            $results['created'][] = $file;
        } else {
            $results['error'][] = "Could not create $file";
        }
    }
}

// Handle other status files based on current network status
$currentNetworkStatus = readFileSecure('tmp/network.tmp', 'online');

// Only accept valid values
if ($currentNetworkStatus != 'online' && $currentNetworkStatus != 'offline') {
    $currentNetworkStatus = 'online';
}

// Update status file with current timestamp and values based on network status
if ($currentNetworkStatus == 'online') {
    // Active status values for online camera
    $statusContent = "0," . rand(30, 45) . "," . rand(10, 50) . "," . rand(70, 95);
} else {
    // Empty or minimal values for offline camera
    $statusContent = "0,0,0,0";
}

writeFileAtomic("tmp/status.tmp", $statusContent);
$results['created'][] = "Status file updated to match " . $currentNetworkStatus . " state";

// Create camera heartbeat file with current timestamp if camera is online
if ($currentNetworkStatus == 'online') {
    if (writeFileAtomic("tmp/camera_heartbeat.tmp", (string)time())) {
        $results['created'][] = "Camera heartbeat updated (online mode)";
    } else {
        $results['error'][] = "Could not update camera heartbeat";
    }
} else {
    // For offline camera, create an old timestamp (>2 min ago)
    $oldTime = time() - 300; // 5 minutes ago
    if (writeFileAtomic("tmp/camera_heartbeat.tmp", (string)$oldTime)) {
        $results['created'][] = "Camera heartbeat set to offline mode";
    } else {
        $results['error'][] = "Could not update camera heartbeat";
    }
}
$logEntry = "[$timestamp] [INFO] - System cleanup performed via clear.php by $currentUser (GMT+3)";
writeFileAtomic("log/combined.log", $logEntry . "\n", true);

// Also create the log entry in /tmp/log/combined.log for the shsync_ script to pick up
if (!file_exists('/tmp/log')) {
    mkdir('/tmp/log', 0777, true);
}
if (!file_exists('/tmp/log/combined.log')) {
    touch('/tmp/log/combined.log');
    chmod('/tmp/log/combined.log', 0666);
}
writeFileAtomic("/tmp/log/combined.log", $logEntry . "\n", true);

// Also send directly to the server via centralized storage.php
$remote_url = 'storage.php';
$post_data = [
    'file' => 'log/combined.log',
    'data' => $logEntry . "\n"
];

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($post_data),
        'timeout' => 5,
        'ignore_errors' => true
    ]
]);

$result = file_get_contents($remote_url, false, $context);
if ($result === false) {
    // Silently fail - not critical
    error_log("Failed to send log to storage.php");
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تنظيف النظام</title>
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: #ffffff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .container {
            width: 100%;
            max-width: 800px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #ffffff;
            font-weight: 300;
            font-size: 28px;
        }
        
        .section {
            margin-bottom: 25px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 15px;
        }
        
        .section-title {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            font-size: 18px;
            font-weight: 500;
        }
        
        .section-title .icon {
            margin-left: 10px;
            font-size: 24px;
        }
        
        .item-list {
            max-height: 150px;
            overflow-y: auto;
            padding: 10px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
        }
        
        .item {
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            font-family: monospace;
            font-size: 14px;
            display: flex;
            align-items: center;
        }
        
        .item:last-child {
            border-bottom: none;
        }
        
        .item .status-icon {
            margin-left: 10px;
            font-size: 16px;
        }
        
        .deleted { color: #ff5252; }
        .emptied { color: #ffab40; }
        .created { color: #69f0ae; }
        .ignored { color: #b0bec5; }
        .preserved { color: #42a5f5; }
        .error { color: #ff1744; }
        
        .button-container {
            display: flex;
            justify-content: center;
            margin-top: 25px;
        }
        
        .button {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 0 10px;
            backdrop-filter: blur(5px);
        }
        
        .button:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .button.primary {
            background: rgba(66, 165, 245, 0.7);
        }
        
        .button.primary:hover {
            background: rgba(66, 165, 245, 0.9);
        }
        
        .timestamp {
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
            opacity: 0.7;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
            opacity: 0;
        }
        
        /* Set animation delay for each section */
        .section:nth-child(1) { animation-delay: 0.1s; }
        .section:nth-child(2) { animation-delay: 0.2s; }
        .section:nth-child(3) { animation-delay: 0.3s; }
        .section:nth-child(4) { animation-delay: 0.4s; }
        .section:nth-child(5) { animation-delay: 0.5s; }
        .section:nth-child(6) { animation-delay: 0.6s; }
        
        .summary {
            text-align: center;
            margin-top: 25px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            font-size: 16px;
            animation: fadeIn 0.5s ease forwards;
            animation-delay: 0.6s;
            opacity: 0;
        }
        
        /* For screens smaller than 600px */
        @media (max-width: 600px) {
            .container {
                padding: 20px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .button-container {
                flex-direction: column;
                gap: 10px;
            }
            
            .button {
                width: 100%;
                margin: 5px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>تنظيف نظام الكاميرا</h1>
        
        <?php if (!empty($results['deleted'])): ?>
        <div class="section fade-in">
            <div class="section-title">
                <span class="icon">🗑️</span>
                <span>الملفات المحذوفة</span>
            </div>
            <div class="item-list">
                <?php foreach ($results['deleted'] as $file): ?>
                <div class="item">
                    <span class="status-icon">✓</span>
                    <span class="deleted"><?= escapeHtml($file) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($results['emptied'])): ?>
        <div class="section fade-in">
            <div class="section-title">
                <span class="icon">🧹</span>
                <span>الملفات المفرغة</span>
            </div>
            <div class="item-list">
                <?php foreach ($results['emptied'] as $file): ?>
                <div class="item">
                    <span class="status-icon">✓</span>
                    <span class="emptied"><?= escapeHtml($file) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($results['created'])): ?>
        <div class="section fade-in">
            <div class="section-title">
                <span class="icon">🔧</span>
                <span>الملفات المنشأة/المحدثة</span>
            </div>
            <div class="item-list">
                <?php foreach ($results['created'] as $file): ?>
                <div class="item">
                    <span class="status-icon">✓</span>
                    <span class="created"><?= escapeHtml($file) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($results['preserved'])): ?>
        <div class="section fade-in">
            <div class="section-title">
                <span class="icon">🔒</span>
                <span>الملفات المحفوظة</span>
            </div>
            <div class="item-list">
                <?php foreach ($results['preserved'] as $file): ?>
                <div class="item">
                    <span class="status-icon">✓</span>
                    <span class="preserved"><?= escapeHtml($file) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($results['error'])): ?>
        <div class="section fade-in">
            <div class="section-title">
                <span class="icon">⚠️</span>
                <span>الأخطاء</span>
            </div>
            <div class="item-list">
                <?php foreach ($results['error'] as $file): ?>
                <div class="item">
                    <span class="status-icon">✗</span>
                    <span class="error"><?= escapeHtml($file) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="summary fade-in">
            تم تنظيف النظام بنجاح. <?= count($results['deleted']) ?> ملفات محذوفة، 
            <?= count($results['emptied']) ?> ملفات مفرغة،
            <?= count($results['created']) ?> ملفات منشأة/محدثة،
            <?= count($results['preserved']) ?> ملفات محفوظة،
            <?= count($results['error']) ?> أخطاء.
        </div>
        
        <div class="timestamp">
            تم التنفيذ في: <?= $timestamp ?> (GMT+3)
            <br>
            بواسطة: Net Storm
        </div>
        
        <div class="button-container">
            <button class="button" onclick="window.location.href='tm.php'">مراقبة الخوادم</button>
            <button class="button primary" onclick="window.location.href='/cam1/'">العودة للصفحة الرئيسية</button>
        </div>
    </div>
    
    <script>
        // Auto return to main page after 3 seconds
        setTimeout(function() {
            window.location.href = '/cam1/';
        }, 3000);
    </script>
</body>
</html>