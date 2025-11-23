<?php

declare(strict_types=1);

// Load dependencies
require_once __DIR__ . '/includes/utilities.php';
require_once __DIR__ . '/config/app-config.php';

// No caching
sendNoCacheHeaders();

// Set error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Default log file
$default_log_file = "log/log.txt";
$log_file = $default_log_file;

// Allowed log files
$allowed_logs = [
    "log.txt",
    "ping.txt",
    "aasw.log",
    "combined.log",
    "tunel.log",
    "tunel2.log",
    "tunel3.log",
    "tunel4.log",
    "cloud.log",
    "tunnel_manager.log",
    "rc_local.log",
    "live.log",
    "sync.log"
];

// Validate and sanitize log file selection
$requested_file = sanitizeStringWhitelist($_GET['file'] ?? 'log.txt', $allowed_logs, 'log.txt');
$log_file = "log/" . $requested_file;

// Try alternative locations if file is not found
if (!file_exists($log_file)) {
    // Try remote server
    $remote_file = CAMERA_BASE_URL . "/log/" . $requested_file;
    $remote_content = fetchRemoteFile($remote_file);

    if ($remote_content !== false && $remote_content !== '') {
        // Cache the remote content locally for viewing
        writeFileAtomic($log_file, $remote_content);
    }
}

// Validate and sanitize lines parameter
$lines = sanitizeInteger($_GET['lines'] ?? 500, 1, 1000, 500);

// Check if a filter was specified
$filter = "";
if (isset($_GET['filter']) && !empty($_GET['filter'])) {
    $filter = trim($_GET['filter']);
}

/**
 * Enhanced function to get last N lines of file with remote fallback
 *
 * @param string $file Log file path
 * @param int $lines Number of lines to read
 * @param string $filter Filter text
 * @return array Log lines
 */
function tail(string $file, int $lines = 100, string $filter = ""): array
{
    $file_contents = array();
    $remote_tried = false;
    
    // Try local file first
    if (file_exists($file)) {
        $file_contents = file($file);
    }
    
    // If local file doesn't exist or is empty, try remote file in log/ directory
    if (empty($file_contents)) {
        $remote_file = CAMERA_BASE_URL . "/log/" . basename($file);
        $remote_content = fetchRemoteFile($remote_file);

        if ($remote_content !== false) {
            // Create local directory if needed
            $dir = dirname($file);
            if (!file_exists($dir)) {
                @mkdir($dir, 0755, true);
            }

            // Save remote content locally
            writeFileAtomic($file, $remote_content);
            $file_contents = explode("\n", $remote_content);
            $remote_tried = true;
        }
    }
    
    // If we still don't have content, return error
    if (empty($file_contents)) {
        if ($remote_tried) {
            return array("ERROR: Log file not found locally or remotely");
        } else {
            return array("ERROR: Log file not found");
        }
    }
    
    // Check if the array is empty (the file might exist but be empty)
    if (count($file_contents) === 0 || (count($file_contents) === 1 && empty($file_contents[0]))) {
        return array("LOG EMPTY");
    }
    
    // Apply filtering if requested
    if (!empty($filter)) {
        $file_contents = array_filter($file_contents, function($line) use ($filter) {
            return stripos($line, $filter) !== false;
        });
        $file_contents = array_values($file_contents); // Reindex array
    }
    
    // Get last N lines
    return array_slice($file_contents, -$lines);
}

// Get log content
$log_content = tail($log_file, $lines, $filter);

// Determine log type based on filename
$log_type = "system";
if (strpos($log_file, "tunel") !== false) {
    $log_type = "tunnel";
} elseif (strpos($log_file, "ping") !== false) {
    $log_type = "network";
} elseif (strpos($log_file, "aasw") !== false) {
    $log_type = "wifi";
}

/**
 * Format log line based on type
 *
 * @param string $line Log line
 * @param string $type Log type (tunnel, network, wifi, system)
 * @return string Formatted HTML line
 */
function format_log_line(string $line, string $type): string
{
    $line = escapeHtml($line);
    
    // Apply type-specific formatting
    switch ($type) {
        case "tunnel":
            // Highlight tunnel status changes
            $line = preg_replace('/\[(ERROR|WARNING)\]/', '<span class="error">[$1]</span>', $line);
            $line = preg_replace('/\[(INFO|DEBUG)\]/', '<span class="info">[$1]</span>', $line);
            $line = preg_replace('/(starting|established|running)/i', '<span class="success">$1</span>', $line);
            $line = preg_replace('/(failed|error|not found)/i', '<span class="error">$1</span>', $line);
            break;
            
        case "network":
            // Highlight network status
            $line = preg_replace('/(Internet OK)/i', '<span class="success">$1</span>', $line);
            $line = preg_replace('/(Fail Internet)/i', '<span class="error">$1</span>', $line);
            break;
            
        case "wifi":
            // Highlight WiFi connection info
            $line = preg_replace('/(connected to)/i', '<span class="success">$1</span>', $line);
            $line = preg_replace('/(disconnected|failed)/i', '<span class="error">$1</span>', $line);
            break;
            
        default: // system logs
            // Highlight status indicators
            $line = preg_replace('/\[ OK \]/', '<span class="success">[ OK ]</span>', $line);
            $line = preg_replace('/\[ ERROR \]/', '<span class="error">[ ERROR ]</span>', $line);
            $line = preg_replace('/\[ RETRY \]/', '<span class="warning">[ RETRY ]</span>', $line);
            $line = preg_replace('/\[ FAIL \]/', '<span class="error">[ FAIL ]</span>', $line);
            $line = preg_replace('/\[ SYSTEM \]/', '<span class="info">[ SYSTEM ]</span>', $line);
            break;
    }
    
    return $line;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - Headless Monitor</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 {
            color: #444;
            margin-bottom: 20px;
        }
        .log-container {
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 1.5;
            height: 65vh;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: monospace;
        }
        .log-line {
            margin: 2px 0;
            padding: 2px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .controls {
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .control-group {
            margin-bottom: 10px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        select, input[type="number"], input[type="text"] {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 150px;
        }
        button {
            padding: 8px 15px;
            background-color: #4CAF50;
            border: none;
            color: white;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        .actions {
            margin-top: 20px;
        }
        .success { color: #4CAF50; font-weight: bold; }
        .error { color: #f44336; font-weight: bold; }
        .warning { color: #ff9800; font-weight: bold; }
        .info { color: #2196F3; font-weight: bold; }
        
        @media (max-width: 768px) {
            .controls {
                flex-direction: column;
            }
            select, input[type="number"], input[type="text"] {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>System Logs</h1>
        
        <div class="controls">
            <form method="GET" action="">
                <div class="control-group">
                    <label for="file">Log File:</label>
                    <select id="file" name="file" onchange="this.form.submit()">
                        <option value="log.txt" <?php echo ($log_file == "log/log.txt") ? 'selected' : ''; ?>>System Log</option>
                        <option value="ping.txt" <?php echo ($log_file == "log/ping.txt") ? 'selected' : ''; ?>>Network Ping</option>
                        <option value="aasw.log" <?php echo ($log_file == "log/aasw.log") ? 'selected' : ''; ?>>WiFi Switch</option>
                        <option value="combined.log" <?php echo ($log_file == "log/combined.log") ? 'selected' : ''; ?>>Combined</option>
                        <option value="tunel.log" <?php echo ($log_file == "log/tunel.log") ? 'selected' : ''; ?>>Tunnel 1</option>
                        <option value="tunel2.log" <?php echo ($log_file == "log/tunel2.log") ? 'selected' : ''; ?>>Tunnel 2</option>
                        <option value="tunel3.log" <?php echo ($log_file == "log/tunel3.log") ? 'selected' : ''; ?>>Tunnel 3</option>
                        <option value="tunel4.log" <?php echo ($log_file == "log/tunel4.log") ? 'selected' : ''; ?>>Tunnel 4</option>
                        <option value="cloud.log" <?php echo ($log_file == "log/cloud.log") ? 'selected' : ''; ?>>Cloudflared</option>
                        <option value="live.log" <?php echo ($log_file == "log/live.log") ? 'selected' : ''; ?>>Live Stream</option>
                        <option value="sync.log" <?php echo ($log_file == "log/sync.log") ? 'selected' : ''; ?>>Sync</option>
                        <option value="rc_local.log" <?php echo ($log_file == "log/rc_local.log") ? 'selected' : ''; ?>>Startup</option>
                    </select>
                </div>
                
                <div class="control-group">
                    <label for="lines">Lines:</label>
                    <input type="number" id="lines" name="lines" min="10" max="1000" value="<?php echo $lines; ?>">
                </div>
                
                <div class="control-group">
                    <label for="filter">Filter:</label>
                    <input type="text" id="filter" name="filter" value="<?php echo htmlspecialchars($filter); ?>" placeholder="Filter text...">
                </div>
                
                <div class="control-group">
                    <label>&nbsp;</label>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        
        <div class="log-container" id="logContent">
            <?php foreach ($log_content as $line): ?>
                <div class="log-line"><?php echo format_log_line($line, $log_type); ?></div>
            <?php endforeach; ?>
        </div>
        
        <div class="actions">
            <button id="refresh">Refresh Logs</button>
            <button id="downloadLogs">Download Logs</button>
            <button id="clearFilter" <?php echo empty($filter) ? 'disabled' : ''; ?>>Clear Filter</button>
        </div>
    </div>
    
    <script>
        document.getElementById('refresh').addEventListener('click', function() {
            // Reload the page to refresh logs
            window.location.reload();
        });
        
        document.getElementById('downloadLogs').addEventListener('click', function() {
            // Get log content
            const logContent = document.getElementById('logContent').innerText;
            const fileName = "<?php echo basename($log_file); ?>";
            
            // Create a blob and download
            const blob = new Blob([logContent], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = fileName;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
        
        document.getElementById('clearFilter').addEventListener('click', function() {
            // Clear filter and submit form
            document.getElementById('filter').value = '';
            document.querySelector('form').submit();
        });
        
        // Auto-scroll to bottom of logs on page load
        window.onload = function() {
            const logContainer = document.getElementById('logContent');
            logContainer.scrollTop = logContainer.scrollHeight;
        };
    </script>
</body>
</html>