<?php

declare(strict_types=1);

/**
 * Battery Monitor Plugin - Graphical Display with Multi-Level Alerts
 *
 * Displays real-time battery with SVG graphic and three-level alert system
 * STANDALONE PLUGIN - No dependencies, safe to delete with zero impact
 *
 * Features:
 * - SVG battery graphic with percentage inside
 * - Charging indicator (lightning bolt)
 * - Three alert levels: LOW (20%), WARNING (15%), CRITICAL (10%)
 * - Smart flashing animations (15%: pulsing, 10%: continuous)
 * - Optional sound alerts synchronized with visual alerts
 * - Arabic alert messages
 *
 * @category  Plugin
 * @package   BatteryMonitor
 * @author    Net Storm
 * @license   Proprietary
 * @version   1.0.0
 * @standards PSR-12, OWASP, Clean Code, Standalone Architecture
 *
 * Plugin Metadata (for plugins-loader.php):
 * @plugin-name Battery Monitor
 * @plugin-version 1.0.0
 * @plugin-description Real-time PiSugar battery monitoring with graphical display
 * @plugin-author Net Storm
 * @plugin-script scripts/shbattery_
 * @plugin-data-sources tmp/batmon/status.tmp, tmp/batmon/alert.tmp
 * @plugin-visibility 360
 * @plugin-container pluginBatteryMonitor
 * @plugin-enabled true
 */

/**
 * Plugin-specific HTML escaper (standalone, no dependencies)
 *
 * @param string $text Text to escape
 * @return string Escaped HTML
 */
function battery_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Check if battery plugin should be available
 * Returns false if Pi Sugar not detected or data unavailable
 *
 * @return bool Plugin should be shown
 */
function battery_plugin_available(): bool
{
    $status_file = __DIR__ . '/../../../tmp/batmon/status.tmp';

    // Check if file exists
    if (!file_exists($status_file)) {
        return false;
    }

    // Check if file is fresh (within 10 minutes)
    $age = time() - filemtime($status_file);
    if ($age > 600) {
        return false;
    }

    // Check if file has valid content
    $content = @file_get_contents($status_file);
    if ($content === false || $content === '' || $content === '0|0|false|0') {
        // Placeholder content - no real data yet
        return false;
    }

    return true;
}

/**
 * Read battery status from tmp file
 *
 * @return array|null Battery data array or null if unavailable
 */
function battery_read_status(): ?array
{
    // Quick availability check first
    if (!battery_plugin_available()) {
        return null;
    }

    $status_file = __DIR__ . '/../../../tmp/batmon/status.tmp';

    // Read file content
    $content = @file_get_contents($status_file);
    if ($content === false || $content === '') {
        return null;
    }

    // Parse data (format: percentage|voltage|charging|timestamp)
    $parts = explode('|', trim($content));
    if (count($parts) < 4) {
        return null;
    }

    [$percentage, $voltage, $charging, $timestamp] = $parts;

    // Validate data types
    if (!is_numeric($percentage) || !is_numeric($voltage) || !is_numeric($timestamp)) {
        return null;
    }

    // Check if data is fresh (within last 10 minutes)
    $age = time() - (int)$timestamp;
    if ($age > 600) {
        // Data too old - device might be offline
        return null;
    }

    return [
        'percentage' => (float)$percentage,
        'voltage' => (float)$voltage,
        'charging' => ($charging === 'true'),
        'timestamp' => (int)$timestamp,
        'age' => $age
    ];
}

/**
 * Render battery monitor plugin in index.php
 * This function is called conditionally - safe if plugin deleted
 *
 * @return void
 */
function render_battery_monitor_plugin(): void
{
    // Read battery data from status file
    $battery_data = battery_read_status();

    // If no data available, don't render (fail silently)
    if ($battery_data === null) {
        error_log("[BatteryMonitor] No data - file missing or invalid");
        return;
    }

    error_log("[BatteryMonitor] Rendering with: " . json_encode($battery_data));

    $percentage = $battery_data['percentage'];
    $charging = $battery_data['charging'];

    // Determine alert level (matching shbattery_ thresholds)
    $alert_level = 'normal';
    $alert_message = '';

    if (!$charging) {
        if ($percentage <= 10) {
            $alert_level = 'critical';
            $alert_message = 'البطارية منخفضة جداً - قم بشحن الكاميرا';
        } elseif ($percentage <= 15) {
            $alert_level = 'warning';
            $alert_message = 'تحذير: مستوى البطارية منخفض';
        } elseif ($percentage <= 20) {
            $alert_level = 'low';
            $alert_message = 'البطارية منخفضة';
        }
    }

    // Sanitize for display
    $percentage_safe = battery_escape(number_format($percentage, 1));
    $alert_message_safe = battery_escape($alert_message);

    ?>

    <!-- Battery Monitor Plugin Container (ID from manifest.json) -->
    <div id="pluginBatteryMonitor">

    <!-- Plugin Inline Styles (Optimized for minimal HTTP requests) -->
    <style>
    .battery-wrapper{position:fixed;top:50px;left:75px;z-index:99;transition:all .3s ease;background:transparent;border:none;padding:0;color:#fff;display:flex;flex-direction:column;align-items:center;gap:5px;will-change:transform;box-sizing:border-box;transform:scale(0.5);transform-origin:top left}
    @supports (-webkit-touch-callout:none){@media (max-width:768px){.battery-wrapper{background:rgba(0,0,0,.5);backdrop-filter:none;-webkit-backdrop-filter:none}}}
    .battery-container{position:relative;width:200px;height:80px;border:8px solid rgba(76,175,80,.4);border-radius:12px;background:rgba(0,0,0,.3);overflow:visible}
    .battery-container::after{content:"";position:absolute;right:-24px;top:22px;width:16px;height:36px;background:rgba(76,175,80,.5);border-bottom-right-radius:12px;border-top-right-radius:12px}
    .battery-fill{position:absolute;top:6px;left:6px;height:calc(100% - 12px);background:linear-gradient(90deg,#4CAF50,#81C784);border-radius:6px;transition:width .5s ease}
    .battery-percentage{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#fff;font-size:40px;font-weight:700;font-family:Arial,sans-serif;text-shadow:0 2px 6px rgba(0,0,0,.8);z-index:10}
    .battery-lightning{position:absolute;top:50%;left:8px;transform:translateY(-50%);opacity:0;transition:opacity .3s ease;font-size:32px;z-index:11}
    .battery-alert-message{color:#fff;font-size:14px;font-weight:600;text-align:center;min-height:20px;margin:5px 0;padding:6px 12px;border-radius:6px;background:rgba(0,0,0,.3);opacity:0;transition:opacity .3s ease}
    .battery-alert-message.visible{opacity:1}
    .battery-alert-message.critical{background:rgba(244,67,54,.8)}
    .battery-alert-message.warning{background:rgba(255,152,0,.8)}
    .battery-alert-message.low{background:rgba(244,67,54,.6)}
    .alert-critical .battery-container{border-color:rgba(244,67,54,.6)}
    .alert-critical .battery-container::after{background:rgba(244,67,54,.6)}
    .alert-critical .battery-fill{background:linear-gradient(90deg,#F44336,#EF5350)}
    .alert-warning .battery-container{border-color:rgba(255,152,0,.6)}
    .alert-warning .battery-container::after{background:rgba(255,152,0,.6)}
    .alert-warning .battery-fill{background:linear-gradient(90deg,#FF9800,#FFB74D)}
    .alert-low .battery-container{border-color:rgba(244,67,54,.5)}
    .alert-low .battery-container::after{background:rgba(244,67,54,.5)}
    .alert-low .battery-fill{background:linear-gradient(90deg,#F44336,#EF5350)}
    .charging .battery-container{border-color:rgba(33,150,243,.6);animation:pulse-border 2s ease-in-out infinite}
    .charging .battery-container::after{background:rgba(33,150,243,.6)}
    .charging .battery-fill{background:linear-gradient(90deg,#2196F3,#64B5F6);animation:charging-animation 2s ease-in-out infinite}
    .charging .battery-lightning{opacity:1}
    .flash-pulse{animation:flashPulse 5s ease-in-out infinite}
    .flash-continuous{animation:flashContinuous .8s ease-in-out infinite}
    @keyframes flashPulse{0%{opacity:1}5%{opacity:0}10%{opacity:1}15%{opacity:0}20%{opacity:1}25%{opacity:1}100%{opacity:1}}
    @keyframes flashContinuous{0%{opacity:1}50%{opacity:0}100%{opacity:1}}
    @keyframes pulse-border{0%,100%{border-color:rgba(33,150,243,.4)}50%{border-color:rgba(33,150,243,.8)}}
    @keyframes charging-animation{0%{opacity:.8}50%{opacity:1}100%{opacity:.8}}
    @media (max-width:768px){.battery-wrapper{max-width:95%;min-width:auto;margin:15px auto;padding:10px 20px}.battery-container{width:180px;height:70px}.battery-percentage{font-size:28px}}
    @media (max-width:480px){.battery-wrapper{margin:12px auto;padding:10px 15px}.battery-container{width:160px;height:60px}.battery-percentage{font-size:24px}.battery-alert-message{font-size:12px}}
    @media (prefers-reduced-motion:reduce){.battery-wrapper,.battery-fill,.battery-lightning,.battery-alert-message{transition:none}.flash-pulse,.flash-continuous,.pulse-border,.charging-animation{animation:none}}
    @media (prefers-contrast:more){.battery-wrapper{border:2px solid rgba(255,255,255,.7)}.battery-container{border-width:4px}}
    </style>

    <!-- Battery Monitor -->
    <div class="battery-wrapper" id="battery-display" data-alert="<?= $alert_level ?>" data-charging="<?= $charging ? 'true' : 'false' ?>">
        <div class="battery-container">
            <div class="battery-fill" id="battery-fill-bar" style="width:<?= $percentage_safe ?>%"></div>
            <div class="battery-percentage" id="battery-percentage-text"><?= $percentage_safe ?>%</div>
            <div class="battery-lightning" id="battery-lightning">⚡</div>
        </div>
        <div class="battery-alert-message <?= $alert_level ?> <?= $alert_message ? 'visible' : '' ?>" id="battery-alert-msg"><?= $alert_message_safe ?></div>
    </div>

    <!-- Plugin Inline JavaScript (Updates status without re-rendering) -->
    <script>
    (function() {
        'use strict';
        if (window.BATTERY_PLUGIN_LOADED) return;
        window.BATTERY_PLUGIN_LOADED = true;

        const UPDATE_INTERVAL = 5000;  // 5 seconds
        const STATUS_FILE_URL = 'tmp/batmon/status.tmp';

        // Alert state tracking
        let lastAlertLevel = 'normal';
        let lastAlertTime = 0;
        let alertShown = {
            'low': false,
            'warning': false,
            'critical': false
        };

        // Sound effect (data URI - minimal inline base64 beep)
        const ALERT_SOUND = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSuBzvLZizcIG2i77eefTRAMUKjj8LdjHAU7k9r0y3krBSl+zPLaizsKGGS46+ahUBELTKXh8bllHQU2jdXzz38pBSh+zPLaizsKGGO46+ahUBEKTKXh8bllHQY2jdXzz38pBSh+zPLaizsKGGS46+ahUBELTKXh8bllHQU2jdXzz38pBSh+zPLaizsKGGO46+ahUBEKTKXh8bllHQY2jdXzz38pBSh+zPLaizsKGGS46+ahUBELTKXh8bllHQU2jdXzz38pBSh+zPLaizsKGGO46+ahUBEKTKXh8bllHQY2jdXzz38pBSh+zPLaizsKGGS46+ahUBELTKXh8bllHQU2jdXzz38pBSh+zPLaizsKGGO46+ahUBEKTKXh8bllHQY2jdXzz38pBSh+zPLaizsKGGS46+ahUBELTKXh8bllHQU2jdXzz38pBSh+zPLaizsKGGO46+ahUBEKTKXh8bllHQY2jdXzz38pBSh+zPLaizsKGGS46+ahUBELTKXh8bllHQU2jdXzz38pBQ==');
        ALERT_SOUND.volume = 0.3;

        let updateInterval = null;
        let lastUpdateTime = 0;

        function shouldUpdate() {
            const now = Date.now();
            if (now - lastUpdateTime < 3000) {
                return false;
            }
            lastUpdateTime = now;
            return true;
        }

        function fetchBatteryStatus() {
            if (!document.getElementById('pluginBatteryMonitor')) {
                cleanup();
                return;
            }

            if (!shouldUpdate()) {
                return;
            }

            fetch(STATUS_FILE_URL + '?t=' + Date.now(), {
                method: 'GET',
                cache: 'no-cache'
            })
            .then(response => response.ok ? response.text() : Promise.reject())
            .then(data => parseAndUpdate(data.trim()))
            .catch(() => {
                if (window.DEBUG_PLUGINS) {
                    console.error('[Battery] Fetch error');
                }
            });
        }

        function parseAndUpdate(data) {
            const parts = data.split('|');
            if (parts.length < 4) return;

            const percentage = parseFloat(parts[0]);
            const voltage = parseFloat(parts[1]);
            const charging = parts[2] === 'true';

            if (isNaN(percentage)) return;

            updateDisplay(percentage, charging);
        }

        function updateDisplay(percentage, charging) {
            const display = document.getElementById('battery-display');
            const fillBar = document.getElementById('battery-fill-bar');
            const percentText = document.getElementById('battery-percentage-text');
            const alertMsg = document.getElementById('battery-alert-msg');

            if (!display || !fillBar || !percentText || !alertMsg) return;

            // Update percentage text
            percentText.textContent = percentage.toFixed(1) + '%';

            // Calculate fill width (max 100% minus padding)
            const fillWidth = Math.max(2, Math.min(100, percentage));
            fillBar.style.width = 'calc(' + fillWidth + '% - 12px)';

            // Determine alert level
            let alertLevel = 'normal';
            let alertMessage = '';
            let flashClass = '';

            if (charging) {
                alertLevel = 'charging';
                display.classList.add('charging');
                display.classList.remove('alert-critical', 'alert-warning', 'alert-low');
                display.classList.remove('flash-pulse', 'flash-continuous');

                // Reset alert flags when charging
                alertShown = { 'low': false, 'warning': false, 'critical': false };
            } else {
                display.classList.remove('charging');

                if (percentage <= 10) {
                    alertLevel = 'critical';
                    alertMessage = 'البطارية منخفضة جداً - قم بشحن الكاميرا';
                    flashClass = 'flash-continuous';
                    display.classList.add('alert-critical');
                    display.classList.remove('alert-warning', 'alert-low');
                } else if (percentage <= 15) {
                    alertLevel = 'warning';
                    alertMessage = 'تحذير: مستوى البطارية منخفض';
                    flashClass = 'flash-pulse';
                    display.classList.add('alert-warning');
                    display.classList.remove('alert-critical', 'alert-low');
                } else if (percentage <= 20) {
                    alertLevel = 'low';
                    alertMessage = 'البطارية منخفضة';
                    display.classList.add('alert-low');
                    display.classList.remove('alert-critical', 'alert-warning');
                } else {
                    display.classList.remove('alert-critical', 'alert-warning', 'alert-low');
                    display.classList.remove('flash-pulse', 'flash-continuous');

                    // Reset alert flags when battery is OK
                    alertShown = { 'low': false, 'warning': false, 'critical': false };
                }

                // Apply flashing
                if (flashClass) {
                    display.classList.remove('flash-pulse', 'flash-continuous');
                    setTimeout(() => display.classList.add(flashClass), 10);
                } else {
                    display.classList.remove('flash-pulse', 'flash-continuous');
                }
            }

            // Update alert message
            if (alertMessage) {
                alertMsg.textContent = alertMessage;
                alertMsg.className = 'battery-alert-message ' + alertLevel + ' visible';

                // Play sound for new alerts (with cooldown)
                const now = Date.now();
                if (!alertShown[alertLevel] && (now - lastAlertTime > 5000)) {
                    playAlertSound();
                    alertShown[alertLevel] = true;
                    lastAlertTime = now;
                }
            } else {
                alertMsg.className = 'battery-alert-message';
                alertMsg.textContent = '';
            }

            // Track alert level changes
            lastAlertLevel = alertLevel;
        }

        function playAlertSound() {
            try {
                // Only play if user has interacted (browser autoplay policy)
                ALERT_SOUND.currentTime = 0;
                ALERT_SOUND.play().catch(() => {
                    // Silent fail - autoplay blocked
                });
            } catch (e) {
                // Ignore errors
            }
        }

        function startPolling() {
            if (updateInterval) clearInterval(updateInterval);

            fetchBatteryStatus();
            updateInterval = setInterval(fetchBatteryStatus, UPDATE_INTERVAL);
        }

        function cleanup() {
            if (updateInterval) {
                clearInterval(updateInterval);
                updateInterval = null;
            }
            window.BATTERY_PLUGIN_LOADED = false;
        }

        window.addEventListener('beforeunload', cleanup);

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', startPolling);
        } else {
            startPolling();
        }
    })();
    </script>

    </div><!-- End pluginBatteryMonitor container -->

    <?php
}

// Function is now called explicitly from plugins-loader.php
// No auto-execution - ensures single render only