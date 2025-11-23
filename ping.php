<?php

declare(strict_types=1);

/**
 * Enhanced Network Status Monitor
 * Provides formatted view of network connectivity logs for headless device
 */

// Load dependencies
require_once __DIR__ . '/includes/utilities.php';
require_once __DIR__ . '/config/app-config.php';

// No caching
sendNoCacheHeaders();

// Set error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Log file
$log_file = "log/ping.txt";

// Check if this is just a stats update request
if (isset($_GET['stats_only'])) {
    $status = get_network_status();
    include('templates/status_card.php');
    exit;
}

// Check if number of lines was specified
$lines = sanitizeInteger($_GET['lines'] ?? 200, 1, 1000, 200);

/**
 * Get status color based on message content (optimized)
 *
 * @param string $message Log message
 * @return string Color hex code
 */
function get_status_color(string $message): string
{
    static $patterns = [
        '#4CAF50' => ['Internet OK', 'Excellent', 'Very Good'],     // Green
        '#8BC34A' => ['Good'],                                      // Light Green
        '#FFC107' => ['Regular'],                                   // Amber
        '#F44336' => ['Bad', 'Fail Internet', 'ERROR', 'FAIL']     // Red
    ];

    $messageLower = strtolower($message);

    foreach ($patterns as $color => $keywords) {
        foreach ($keywords as $keyword) {
            if (stripos($messageLower, strtolower($keyword)) !== false) {
                return $color;
            }
        }
    }

    return '#607D8B'; // Default Blue Grey
}

/**
 * Get network stats with local and remote fallback
 *
 * @return array Network status data
 */
function get_network_status(): array
{
    // First try to get system_info.tmp (new format from shchecker_)
    $system_file = 'tmp/system_info.tmp';
    $status_file = 'tmp/status.tmp';
    $status_data = '';

    // Try remote file first
    $remote_system_file = CAMERA_BASE_URL . '/tmp/system_info.tmp';
    $remote_status = fetchRemoteFile($remote_system_file);

    if ($remote_status !== false && $remote_status !== '') {
        // New format: uptime=up 3 days|load=0.12 0.14 0.09|disk=24%|temp=42.3C|mem=512/925MB
        $parts = explode('|', $remote_status);
        $data = [];
        
        foreach ($parts as $part) {
            [$key, $value] = explode('=', $part, 2);
            $data[$key] = $value;
        }
        
        return [
            'memory' => isset($data['mem']) ? $data['mem'] : 'N/A',
            'temperature' => isset($data['temp']) ? $data['temp'] : 'N/A',
            'load' => isset($data['load']) ? $data['load'] : 'N/A',
            'uptime' => isset($data['uptime']) ? $data['uptime'] : 'N/A',
            'disk' => isset($data['disk']) ? $data['disk'] : 'N/A',
            'last_update' => date('d/m/Y h:i:s A')
        ];
    }
    
    // Try local new format
    if (file_exists($system_file)) {
        $system_data = readFileSecure($system_file);
        $parts = explode('|', $system_data);
        $data = [];
        
        foreach ($parts as $part) {
            if (strpos($part, '=') !== false) {
                [$key, $value] = explode('=', $part, 2);
                $data[$key] = $value;
            }
        }
        
        return [
            'memory' => isset($data['mem']) ? $data['mem'] : 'N/A',
            'temperature' => isset($data['temp']) ? $data['temp'] : 'N/A',
            'load' => isset($data['load']) ? $data['load'] : 'N/A',
            'uptime' => isset($data['uptime']) ? $data['uptime'] : 'N/A',
            'disk' => isset($data['disk']) ? $data['disk'] : 'N/A',
            'last_update' => date('d/m/Y h:i:s A')
        ];
    }
    
    // Fallback to old format
    $remote_status_file = CAMERA_BASE_URL . '/tmp/status.tmp';
    $remote_status = fetchRemoteFile($remote_status_file);

    if ($remote_status !== false && $remote_status !== '') {
        // Parse data (old format: "Memory,Temperature,Ping,WiFi Quality")
        $parts = explode(',', $remote_status);
        
        return [
            'memory' => isset($parts[0]) ? $parts[0] : 'N/A',
            'temperature' => isset($parts[1]) ? $parts[1] : 'N/A',
            'ping' => isset($parts[2]) ? $parts[2] : 'N/A',
            'wifi_quality' => isset($parts[3]) ? $parts[3] : 'N/A',
            'last_update' => date('d/m/Y h:i:s A')
        ];
    }
    
    // Final local fallback
    if (file_exists($status_file)) {
        $status_data = readFileSecure($status_file);
        // Parse data (old format: "Memory,Temperature,Ping,WiFi Quality")
        $parts = explode(',', $status_data);
        
        return [
            'memory' => isset($parts[0]) ? $parts[0] : 'N/A',
            'temperature' => isset($parts[1]) ? $parts[1] : 'N/A',
            'ping' => isset($parts[2]) ? $parts[2] : 'N/A',
            'wifi_quality' => isset($parts[3]) ? $parts[3] : 'N/A',
            'last_update' => date('d/m/Y h:i:s A')
        ];
    }
    
    // No data found
    return [
        'memory' => 'N/A',
        'temperature' => 'N/A',
        'ping' => 'N/A',
        'wifi_quality' => 'N/A',
        'load' => 'N/A',
        'uptime' => 'N/A',
        'disk' => 'N/A',
        'last_update' => 'No Data'
    ];
}

/**
 * Read log file and return last N lines
 *
 * @param string $file Log file path
 * @param int $lines Number of lines to read
 * @return array Log lines
 */
function read_log_lines(string $file, int $lines): array
{
    if (!file_exists($file)) {
        return ["No log file found"];
    }
    
    $log_content = file($file);
    if (empty($log_content)) {
        return ["Log file is empty"];
    }
    
    return array_slice($log_content, -$lines);
}

// Get log content
$log_entries = read_log_lines($log_file, $lines);
$status = get_network_status();

/**
 * Format timestamps for better readability
 *
 * @param string $line Log line
 * @return string Formatted line with HTML timestamp
 */
function format_timestamp(string $line): string
{
    // Match common date/time patterns
    if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $matches)) {
        $timestamp = $matches[1];
        $message = str_replace($timestamp, "<span class='timestamp'>$timestamp</span>", $line);
        return $message;
    }
    return $line;
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Status - Headless Monitor</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            margin-bottom: 20px;
            color: #444;
        }
        .status-card {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            padding: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .status-item {
            flex: 1;
            min-width: 150px;
            padding: 10px;
            border-radius: 5px;
            background-color: #f9f9f9;
            text-align: center;
        }
        .status-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        .status-value {
            font-size: 24px;
            font-weight: bold;
        }
        .log-container {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            max-height: 600px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 13px;
            line-height: 1.5;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .log-entry {
            padding: 6px 10px;
            margin-bottom: 4px;
            border-radius: 4px;
            border-left: 4px solid #ddd;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .log-entry:hover {
            background-color: #f5f5f5;
        }
        .timestamp {
            color: #666;
            font-weight: bold;
        }
        .controls {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }
        button, select {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            background-color: #4CAF50;
            color: white;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover {
            background-color: #45a049;
        }
        select {
            background-color: #fff;
            color: #333;
            border: 1px solid #ddd;
        }
        
        @media (max-width: 768px) {
            .status-card {
                flex-direction: column;
                gap: 10px;
            }
            .status-item {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Network Status Monitor</h1>
        
        <div class="status-card">
            <div class="status-item">
                <div class="status-label">Memory</div>
                <div class="status-value"><?php echo escapeHtml($status['memory']); ?></div>
            </div>

            <div class="status-item">
                <div class="status-label">Temperature</div>
                <div class="status-value"><?php echo escapeHtml($status['temperature']); ?></div>
            </div>
            
            <?php if (isset($status['load'])): ?>
            <div class="status-item">
                <div class="status-label">CPU Load</div>
                <div class="status-value"><?php echo escapeHtml($status['load']); ?></div>
            </div>
            <?php else: ?>
            <div class="status-item">
                <div class="status-label">Ping</div>
                <div class="status-value"><?php echo escapeHtml($status['ping'] ?? 'N/A'); ?></div>
            </div>
            <?php endif; ?>

            <?php if (isset($status['disk'])): ?>
            <div class="status-item">
                <div class="status-label">Disk Usage</div>
                <div class="status-value"><?php echo escapeHtml($status['disk']); ?></div>
            </div>
            <?php else: ?>
            <div class="status-item">
                <div class="status-label">WiFi Quality</div>
                <div class="status-value"><?php echo escapeHtml($status['wifi_quality'] ?? 'N/A'); ?></div>
            </div>
            <?php endif; ?>

            <?php if (isset($status['uptime'])): ?>
            <div class="status-item">
                <div class="status-label">Uptime</div>
                <div class="status-value"><?php echo escapeHtml($status['uptime']); ?></div>
            </div>
            <?php endif; ?>

            <div class="status-item">
                <div class="status-label">Last Update</div>
                <div class="status-value" style="font-size: 14px;"><?php echo escapeHtml($status['last_update']); ?></div>
            </div>
        </div>
        
        <div class="controls">
            <button onclick="window.location.reload()">Refresh</button>
            
            <select onchange="window.location.href='?lines='+this.value">
                <option value="50" <?php echo ($lines == 50) ? 'selected' : ''; ?>>50 entries</option>
                <option value="100" <?php echo ($lines == 100) ? 'selected' : ''; ?>>100 entries</option>
                <option value="200" <?php echo ($lines == 200) ? 'selected' : ''; ?>>200 entries</option>
                <option value="500" <?php echo ($lines == 500) ? 'selected' : ''; ?>>500 entries</option>
                <option value="1000" <?php echo ($lines == 1000) ? 'selected' : ''; ?>>1000 entries</option>
            </select>
            
            <button onclick="downloadLog()">Download Log</button>
        </div>
        
        <div class="log-container" id="logContainer">
            <?php foreach ($log_entries as $entry): ?>
                <?php 
                    $entry = htmlspecialchars($entry);
                    $entry = format_timestamp($entry);
                    $color = get_status_color($entry);
                ?>
                <div class="log-entry" style="border-left-color: <?php echo $color; ?>">
                    <?php echo $entry; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script>
        // Auto-scroll to bottom on load
        window.onload = function() {
            var logContainer = document.getElementById('logContainer');
            logContainer.scrollTop = logContainer.scrollHeight;
        };
        
        // Function to download log
        function downloadLog() {
            var logContent = document.getElementById('logContainer').innerText;
            var blob = new Blob([logContent], { type: 'text/plain' });
            var url = URL.createObjectURL(blob);
            
            var a = document.createElement('a');
            a.href = url;
            a.download = 'network_log_<?php echo date("d-m-Y"); ?>.txt';
            document.body.appendChild(a);
            a.click();
            
            setTimeout(function() {
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            }, 0);
        }
        
        // Auto-refresh the page dynamically 
        var refreshCount = 0;
        var refreshInterval = 15000; // Start with 15 second refreshes
        
        function adaptiveRefresh() {
            // Update stats without full page reload
            fetch('ping.php?stats_only=1&' + Date.now())
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(html => {
                    // Extract and update the status card
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = html;
                    const newStatusCard = tempDiv.querySelector('.status-card');
                    if (newStatusCard) {
                        document.querySelector('.status-card').innerHTML = newStatusCard.innerHTML;
                    }
                    
                    // Every 4 cycles (1 minute at 15 seconds), refresh the logs too
                    refreshCount++;
                    if (refreshCount >= 4) {
                        refreshCount = 0;
                        window.location.reload(); // Full refresh to get updated logs
                    }
                })
                .catch(error => {
                    console.error('Error updating stats:', error);
                    refreshCount = 0;
                    setTimeout(window.location.reload, 1000); // Fall back to full refresh on error
                });
            
            // Set the next refresh
            setTimeout(adaptiveRefresh, refreshInterval);
        }
        
        // Start the adaptive refresh cycle
        setTimeout(adaptiveRefresh, refreshInterval);
    </script>
</body>
</html>