<?php

declare(strict_types=1);

/**
 * Network Monitor Plugin - Enhanced Display
 *
 * Displays real-time network information with rich status indicators
 * STANDALONE PLUGIN - No dependencies, safe to delete with zero impact
 *
 * @category  Plugin
 * @package   NetworkMonitor
 * @author    Net Storm
 * @license   Proprietary
 * @version   1.0.0
 * @standards PSR-12, OWASP, Clean Code, Standalone Architecture
 *
 * Plugin Metadata (for plugins-loader.php):
 * @plugin-name Network Monitor
 * @plugin-version 2.0.0
 * @plugin-description Real-time network monitoring with smart switching
 * @plugin-author Net Storm
 * @plugin-script scripts/shnetmon_
 * @plugin-data-sources tmp/netmon/status.tmp
 * @plugin-visibility 600
 * @plugin-container pluginNetworkMonitor
 * @plugin-enabled true
 */

/**
 * Plugin-specific safe file reader (standalone, no dependencies)
 *
 * @param string $file File path
 * @return string File contents or empty string
 */
function netmon_read_file(string $file): string
{
    if (!file_exists($file) || !is_readable($file)) {
        return '';
    }

    $size = @filesize($file);
    if ($size === false || $size === 0 || $size > 1048576) {
        return '';
    }

    $content = @file_get_contents($file);
    return ($content !== false) ? trim($content) : '';
}

/**
 * Plugin-specific HTML escaper (standalone, no dependencies)
 *
 * @param string $text Text to escape
 * @return string Escaped HTML
 */
function netmon_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Render plugin status bar in index.php
 * This function is called conditionally - safe if plugin deleted
 */
function render_plugin_status_bar(): void
{
    // Status file path
    $netmon_status_file = __DIR__ . '/../../../tmp/netmon/status.tmp';

    // Read network monitor status using plugin's standalone function
    $status = netmon_read_file($netmon_status_file);
    if ($status === '') {
        return;
    }

    // Parse status: SSID|Signal|Speed|Latency|Quality|State
    $parts = explode('|', $status);
    if (count($parts) < 6) {
        return;
    }

    [$ssid, $signal, $speed, $latency, $quality, $original_state] = $parts;

    // Check file age to determine state
    $netmon_age = 999;
    if (file_exists($netmon_status_file)) {
        $netmon_mtime = @filemtime($netmon_status_file);
        if ($netmon_mtime !== false) {
            $netmon_age = time() - $netmon_mtime;
        }
    }

    // Determine state based on file age only
    if ($netmon_age <= 18) {
        // Fresh (< 18s) - Camera is online and connected
        $state = 'Connected';
    } elseif ($netmon_age <= 45) {
        // 18-45s - Connection lost
        $state = 'Disconnected';
    } else {
        // > 45s - Show last known info
        $state = 'Last Known';
    }

    // Note: If camera status is offline while plugin shows Connected,
    // it indicates core scripts (sync.sh) have issues

    // Sanitize for display using plugin's standalone function
    $ssid = netmon_escape($ssid);
    $signal = netmon_escape($signal);
    $speed = netmon_escape($speed);
    $latency = netmon_escape($latency);
    $quality = netmon_escape($quality);
    $state = netmon_escape($state);

    // Determine status indicators
    $status_icon = match($state) {
        'Connected' => '🟢',
        'Switching' => '🟠',
        'Failed' => '🔴',
        'Disconnected' => '🔴',
        'Last Known' => '⚪',
        default => '⚪'
    };

    $status_color = match($state) {
        'Connected' => '#4CAF50',
        'Switching' => '#FF9800',
        'Failed' => '#F44336',
        'Disconnected' => '#F44336',
        'Last Known' => '#9E9E9E',
        default => '#607D8B'
    };

    $quality_color = match($quality) {
        'Excellent' => '#4CAF50',
        'Good' => '#8BC34A',
        'Fair' => '#FFC107',
        'Poor' => '#F44336',
        default => '#9E9E9E'
    };

    ?>

    <!-- Network Monitor Plugin Container (ID from manifest.json) -->
    <div id="pluginNetworkMonitor">

    <!-- Plugin Inline Styles (Optimized for minimal HTTP requests) -->
    <style>
    .netmon-container{transition:all .3s ease;background:rgba(255,255,255,.1);border-radius:10px;backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(20px);padding:5px 35px;margin:18px auto;border:1px solid rgba(255,255,255,.3);color:#fff;max-width:830px;min-width:730px;box-shadow:0 4px 6px rgba(0,0,0,.1);display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:nowrap;will-change:transform;box-sizing:border-box}
    @supports (-webkit-touch-callout:none){@media (max-width:768px){.netmon-container{background:rgba(0,0,0,.5);backdrop-filter:none;-webkit-backdrop-filter:none}}}
    .netmon-status{display:flex;align-items:center;gap:10px;margin:0;padding:0}
    .netmon-status .status-dot{width:12px;height:12px;border-radius:50%;animation:statusPulse 1s ease-in-out infinite;will-change:opacity,box-shadow;margin:0;padding:0;display:block}
    @keyframes statusPulse{0%{opacity:1;box-shadow:0 0 10px rgba(255,255,255,.3)}50%{opacity:.7;box-shadow:0 0 20px rgba(255,255,255,.5)}100%{opacity:1;box-shadow:0 0 10px rgba(255,255,255,.3)}}
    .netmon-status .status-text{font-weight:600;color:#fff;font-size:16px;text-transform:uppercase;letter-spacing:.8px;text-shadow:0 1px 2px rgba(0,0,0,.2);margin:0;padding:0}
    .netmon-network{display:flex;align-items:center;gap:10px;margin:0;padding:0}
    .netmon-network .network-label{color:rgba(255,255,255,.7);font-size:14px;font-weight:500;letter-spacing:.3px;margin:0;padding:0}
    .netmon-network .network-value{color:#fff;font-weight:600;font-size:16px;text-shadow:0 1px 2px rgba(0,0,0,.2);margin:0;padding:0}
    .netmon-quality{display:flex;align-items:center;gap:10px;margin:0;padding:0}
    .netmon-quality .quality-badge{padding:5px 12px;color:#fff;border-radius:6px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;box-shadow:0 2px 4px rgba(0,0,0,.2);text-shadow:0 1px 2px rgba(0,0,0,.2)}
    .netmon-quality .quality-signal{color:rgba(255,255,255,.8);font-size:14px;font-family:'Courier New',monospace;font-weight:600}
    .netmon-metric{display:flex;align-items:center;gap:10px;margin:0;padding:7px 14px;background:rgba(0,0,0,.25);border-radius:8px;transition:all .3s cubic-bezier(.4,0,.2,1);will-change:transform,background}
    .netmon-metric:hover{background:rgba(0,0,0,.35);transform:translateY(-2px);box-shadow:0 4px 8px rgba(0,0,0,.2)}
    .netmon-metric .metric-label{color:rgba(255,255,255,.7);font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin:0;padding:0}
    .netmon-metric .metric-value{color:#fff;font-weight:700;font-size:16px;font-family:'Courier New',monospace;min-width:70px;text-align:right;text-shadow:0 1px 2px rgba(0,0,0,.2);margin:0;padding:0}
    @media (max-width:768px){.netmon-container{max-width:95%;min-width:auto;margin:16px auto;padding:5px 15px;gap:20px}.netmon-status .status-text{font-size:15px}.netmon-network .network-value,.netmon-metric .metric-value{font-size:15px}}
    @media (max-width:480px){.netmon-container{margin:12px auto;padding:5px 15px;gap:16px;flex-wrap:wrap;justify-content:center}.netmon-network,.netmon-quality{display:none}.netmon-status,.netmon-metric{flex:1 1 auto}.netmon-status .status-text,.netmon-metric .metric-value{font-size:14px}}
    @media (prefers-reduced-motion:reduce){.netmon-status .status-dot{animation:none}.netmon-container,.netmon-metric{transition:none}}
    @media (prefers-contrast:more){.netmon-container{border:2px solid rgba(255,255,255,.6)}.netmon-status .status-text,.netmon-network .network-value,.netmon-metric .metric-value{text-shadow:none;font-weight:700}}
    </style>

    <!-- Network Monitor -->
    <div class="netmon-container">
        <!-- Status Indicator -->
        <div class="netmon-status">
            <span class="status-dot" style="background-color: <?= $status_color ?>;"></span>
            <span class="status-text"><?= $state ?></span>
        </div>

        <!-- Wi-Fi Network Name -->
        <div class="netmon-network">
            <span class="network-label">Wi-Fi:</span>
            <span class="network-value"><?= $ssid ?></span>
        </div>

        <!-- Quality Badge -->
        <div class="netmon-quality">
            <span class="quality-badge" style="background-color: <?= $quality_color ?>;">
                <?= $quality ?>
            </span>
            <span class="quality-signal"><?= $signal ?> dBm</span>
        </div>

        <!-- Upload Speed -->
        <div class="netmon-metric">
            <span class="metric-label">Upload:</span>
            <span class="metric-value"><?= $speed ?> KB/s</span>
        </div>
    </div>

    <!-- Plugin Inline JavaScript (Updates status without re-rendering) -->
    <script>
    (function() {
        'use strict';
        if (window.NETMON_LOADED) return;
        window.NETMON_LOADED = true;

        let lastSSID = "";
        let updateInterval = null;

        function checkFileAge(filepath, callback) {
            fetch(filepath + '?t=' + Date.now(), {
                method: 'HEAD',
                cache: 'no-store'
            })
            .then(response => {
                const lastModified = response.headers.get('Last-Modified');
                if (lastModified) {
                    const fileTime = new Date(lastModified).getTime() / 1000;
                    const now = Date.now() / 1000;
                    const age = now - fileTime;
                    callback(age);
                } else {
                    callback(999);
                }
            })
            .catch(() => callback(999));
        }

        function updateNetworkStatus() {
            const container = document.getElementById('pluginNetworkMonitor');
            if (!container || container.style.display === 'none') return;

            // Fetch network data
            fetch('tmp/netmon/status.tmp?t=' + Date.now(), {
                method: 'GET',
                cache: 'no-store'
            })
            .then(response => response.ok ? response.text() : Promise.reject())
            .then(data => {
                const parts = data.trim().split('|');
                if (parts.length < 6) return;

                const [ssid, signal, speed, latency, quality, originalState] = parts;

                // Check netmon file age
                checkFileAge('tmp/netmon/status.tmp', function(netmonAge) {
                    let state = originalState;
                    let statusColor = '#607D8B';

                    // Determine state based on file age only
                    if (netmonAge <= 18) {
                        // Fresh (< 18s) - Camera is online and connected
                        state = 'Connected';
                        statusColor = '#4CAF50';
                    } else if (netmonAge <= 45) {
                        // 18-45s - Connection lost
                        state = 'Disconnected';
                        statusColor = '#F44336';
                    } else {
                        // > 45s - Show last known info
                        state = 'Last Known';
                        statusColor = '#9E9E9E';
                    }

                    // Update DOM elements
                    const statusDot = container.querySelector('.status-dot');
                    const statusText = container.querySelector('.status-text');
                    const networkValue = container.querySelector('.network-value');
                    const signalValue = container.querySelector('.quality-signal');
                    const speedValue = container.querySelector('.metric-value');

                    if (statusDot) statusDot.style.backgroundColor = statusColor;
                    if (statusText) statusText.textContent = state.toUpperCase();
                    if (networkValue) {
                        // Check for network change (reload page)
                        if (lastSSID === "" && networkValue) {
                            lastSSID = networkValue.textContent;
                        }
                        if (networkValue && ssid !== lastSSID && lastSSID !== "") {
                            console.log('[NetMon] Network changed: ' + lastSSID + ' → ' + ssid);
                            location.reload();
                        }
                        networkValue.textContent = ssid;
                    }
                    if (signalValue) signalValue.textContent = signal + ' dBm';
                    if (speedValue) speedValue.textContent = speed + ' KB/s';
                });
            })
            .catch(() => {
                // Silent fail - keep showing last known data
            });
        }

        function startMonitoring() {
            setTimeout(updateNetworkStatus, 2000);
            updateInterval = setInterval(updateNetworkStatus, 2000); // Update every 2s
        }

        window.addEventListener('beforeunload', function() {
            if (updateInterval) clearInterval(updateInterval);
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', startMonitoring);
        } else {
            startMonitoring();
        }
    })();
    </script>

    </div><!-- End pluginNetworkMonitor container -->

    <?php
}

// Function is now called explicitly from mode.php
// No auto-execution - ensures single render only
