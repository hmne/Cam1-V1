/**
 * WebSocket Client with Automatic Fallback
 *
 * Features:
 * - Automatic fallback to HTTP mode if WebSocket fails
 * - Seamless integration with existing camera-control.js
 * - No interference with existing functionality
 *
 * @version 1.0.0
 * @author Net Storm
 */

'use strict';

(function(window, document) {
    // ==========================================================================
    // CONFIG
    // ==========================================================================

    var CONFIG = {
        SERVER: window.WEBSOCKET_SERVER_URL || 'wss://193.160.119.136:8443',
        MAX_FAILURES: 3,        // After 3 failures, use HTTP mode
        RETRY_AFTER: 60000,     // Try WebSocket again after 60s
        RECONNECT_DELAY: 2000,
        MAX_RECONNECT_DELAY: 30000
    };

    // OFFLINE DETECTION LOGIC (Hybrid Approach):
    // PRIMARY: WebSocket disconnect = camera offline (immediate, clean)
    // SECONDARY: VPS detects frozen camera (connected but no status for 30s)
    // RESULT: No flickering from timing issues, professional state management

    // ==========================================================================
    // STATE
    // ==========================================================================

    var state = {
        ws: null,
        connected: false,
        failureCount: 0,
        httpMode: false,        // true = using HTTP fallback
        reconnectTimer: null,
        reconnectDelay: CONFIG.RECONNECT_DELAY
    };

    // ==========================================================================
    // SAFETY CHECK
    // ==========================================================================

    // If no server configured, don't do anything
    if (!CONFIG.SERVER) {
        console.log('[WS] Not configured - using HTTP mode');
        window.CameraWS = { enabled: false };
        return;
    }

    // ==========================================================================
    // FALLBACK LOGIC
    // ==========================================================================

    function switchToHttpMode() {
        state.httpMode = true;
        console.log('[WS] Switching to HTTP mode (fallback)');
        trigger('ws:fallback');

        // Try WebSocket again later
        setTimeout(function() {
            state.httpMode = false;
            state.failureCount = 0;
            console.log('[WS] Retrying WebSocket connection...');
            connect();
        }, CONFIG.RETRY_AFTER);
    }

    // ==========================================================================
    // CONNECTION
    // ==========================================================================

    function connect() {
        if (state.httpMode) {
            return;  // In HTTP mode, don't connect
        }

        if (state.ws && state.ws.readyState === WebSocket.OPEN) {
            return;  // Already connected
        }

        console.log('[WS] Connecting to', CONFIG.SERVER);

        try {
            state.ws = new WebSocket(CONFIG.SERVER);

            state.ws.onopen = function() {
                console.log('[WS] Connected');
                state.connected = true;
                state.failureCount = 0;  // Reset on success
                state.reconnectDelay = CONFIG.RECONNECT_DELAY;

                // Identify as browser
                send({ type: 'identify', role: 'browser' });
                trigger('ws:connected');
            };

            state.ws.onmessage = function(event) {
                try {
                    var msg = JSON.parse(event.data);
                    handleMessage(msg);
                } catch (err) {
                    console.error('[WS] Parse error:', err);
                }
            };

            state.ws.onclose = function() {
                console.log('[WS] Disconnected');
                state.connected = false;
                state.failureCount++;

                if (state.failureCount >= CONFIG.MAX_FAILURES) {
                    switchToHttpMode();
                } else {
                    trigger('ws:disconnected');
                    scheduleReconnect();
                }
            };

            state.ws.onerror = function(err) {
                console.error('[WS] Error:', err);
                state.failureCount++;
            };

        } catch (err) {
            console.error('[WS] Connection failed:', err);
            state.failureCount++;

            if (state.failureCount >= CONFIG.MAX_FAILURES) {
                switchToHttpMode();
            } else {
                scheduleReconnect();
            }
        }
    }

    function scheduleReconnect() {
        if (state.reconnectTimer) {
            clearTimeout(state.reconnectTimer);
        }

        if (state.httpMode) {
            return;  // Don't reconnect in HTTP mode
        }

        state.reconnectTimer = setTimeout(function() {
            connect();
            state.reconnectDelay = Math.min(
                state.reconnectDelay * 1.5,
                CONFIG.MAX_RECONNECT_DELAY
            );
        }, state.reconnectDelay);
    }

    function send(data) {
        if (state.ws && state.ws.readyState === WebSocket.OPEN) {
            state.ws.send(JSON.stringify(data));
            return true;
        }
        return false;
    }

    // ==========================================================================
    // MESSAGE HANDLER
    // ==========================================================================

    function handleMessage(msg) {
        console.log('[WS] Received:', msg.type);

        switch (msg.type) {
            case 'init':
                trigger('ws:init', msg.status);
                updateStatus(msg.status);
                break;

            case 'status':
                updateStatus(msg.status);
                break;

            case 'camera_online':
                trigger('camera:online');
                // Notification based on VPS WebSocket state (most accurate)
                // This is the PRIMARY source of truth when WebSocket is enabled
                notify('Camera Connected', 'Camera is now online');
                break;

            case 'camera_offline':
                trigger('camera:offline');
                // Notification based on VPS WebSocket disconnect (most accurate)
                // This means camera lost connection to VPS (no network or powered off)
                notify('Camera Disconnected', 'Connection lost');
                break;

            case 'camera_frozen':
                trigger('camera:frozen');
                notify('Camera Not Responding', 'Connected but not sending updates');
                break;

            case 'capture_started':
                trigger('capture:started', { id: msg.id });
                setCaptureUI(true);
                break;

            case 'capture_done':
                trigger('capture:done', {
                    id: msg.id,
                    data: msg.data,
                    duration: msg.duration
                });
                setCaptureUI(false);
                // Display base64 image directly
                if (msg.data) {
                    setImage('data:image/jpeg;base64,' + msg.data);
                }
                notify('Capture Complete', 'Image captured in ' + msg.duration + 'ms');
                break;

            case 'capture_timeout':
                trigger('capture:timeout', { id: msg.id });
                setCaptureUI(false);
                break;

            case 'live_frame':
                trigger('live:frame', { data: msg.data });
                // Display base64 live frame directly
                if (msg.data) {
                    setLiveImage('data:image/jpeg;base64,' + msg.data);
                }
                break;

            case 'live_status':
                trigger('live:status', { active: msg.active });
                break;

            case 'server_live_changed':
                trigger('server_live:changed', { state: msg.state });
                handleServerLiveChange(msg.state);
                break;

            case 'reboot_ack':
                trigger('reboot:ack', { executing: msg.executing });
                console.log('[WS] Reboot acknowledged by camera');
                break;

            case 'shutdown_ack':
                trigger('shutdown:ack', { executing: msg.executing });
                console.log('[WS] Shutdown acknowledged by camera');
                break;

            case 'error':
                console.error('[WS] Server error:', msg.message);
                break;
        }
    }

    // ==========================================================================
    // UI UPDATES (optional - won't break if elements don't exist)
    // ==========================================================================

    function updateStatus(status) {
        if (!status) return;

        var parts = (status.data || 'N/A,N/A,N/A,N/A').split(',');

        var memEl = document.getElementById('memoryStatus');
        var tempEl = document.getElementById('tempStatus');
        var pingEl = document.getElementById('pingStatus');
        var sigEl = document.getElementById('signalStatus');

        if (memEl) memEl.textContent = parts[0] || 'N/A';
        if (tempEl) tempEl.textContent = parts[1] || 'N/A';
        if (pingEl) pingEl.textContent = parts[2] || 'N/A';
        if (sigEl) sigEl.textContent = parts[3] || 'N/A';

        var indicator = document.querySelector('.status-indicator');
        if (indicator) {
            indicator.className = 'status-indicator ' + (status.online ? 'online' : 'offline');
            indicator.textContent = status.online ? 'Connected (Online)' : 'Disconnected (Offline)';
        }

        window.cameraStatus = status;
    }

    function setCaptureUI(capturing) {
        var btn = document.getElementById('takePicBtn');
        if (!btn) return;

        if (capturing) {
            btn.disabled = true;
            btn.textContent = 'Capturing...';
        } else {
            btn.disabled = false;
            btn.textContent = 'Capture Image';
        }
    }

    function setImage(url) {
        var img = document.getElementById('pic');
        if (img) img.src = url;
    }

    function setLiveImage(url) {
        var img = document.getElementById('liveImg');
        if (img) img.src = url;
    }

    // ==========================================================================
    // SERVER LIVE HANDLER
    // ==========================================================================

    function handleServerLiveChange(state) {
        console.log('[WS] Server live changed:', state);

        if (state === 'on') {
            // Server live is ON - reload page to hide controls
            console.log('[WS] Server live activated - reloading page');
            notify('Server Live Active', 'Controls disabled - live active from server');
            setTimeout(function() {
                window.location.reload();
            }, 500);
        } else {
            // Server live is OFF - reload page to show controls
            console.log('[WS] Server live deactivated - reloading page');
            setTimeout(function() {
                window.location.reload();
            }, 500);
        }
    }

    // ==========================================================================
    // NOTIFICATIONS
    // ==========================================================================

    var lastNotificationTime = 0;
    var NOTIFICATION_COOLDOWN = 30000; // 30 seconds cooldown between notifications

    function notify(title, body) {
        // Don't notify if page is visible and focused
        if (document.visibilityState === 'visible' && document.hasFocus()) {
            return;
        }

        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }

        // Cooldown check - prevent spam
        var now = Date.now();
        if (now - lastNotificationTime < NOTIFICATION_COOLDOWN) {
            console.log('[WS] Notification skipped (cooldown)');
            return;
        }
        lastNotificationTime = now;

        var n = new Notification(title, {
            body: body,
            icon: 'assets/images/logo.ico',
            tag: 'camera',
            renotify: false // Don't renotify for same tag
        });

        setTimeout(function() { n.close(); }, 8000);
        n.onclick = function() {
            window.focus();
            n.close();
        };
    }

    // ==========================================================================
    // NOTIFICATION PERMISSION
    // ==========================================================================

    /**
     * Check if device is iOS
     */
    function isIOS() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) ||
               (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    /**
     * Check if running as PWA (added to home screen)
     */
    function isPWA() {
        return window.matchMedia('(display-mode: standalone)').matches ||
               window.navigator.standalone === true;
    }

    /**
     * Request notification permission (must be called from user gesture)
     */
    function requestNotificationPermission() {
        if (!('Notification' in window)) {
            console.log('[WS] Notifications not supported');
            alert('متصفحك لا يدعم التنبيهات');
            return;
        }

        if (Notification.permission === 'granted') {
            console.log('[WS] Notifications already granted');
            alert('✅ التنبيهات مفعلة بالفعل');
            return;
        }

        if (Notification.permission === 'denied') {
            console.log('[WS] Notifications denied');
            alert('❌ التنبيهات مرفوضة. فعلها من إعدادات المتصفح');
            return;
        }

        // iOS Safari special handling
        if (isIOS() && !isPWA()) {
            alert('📱 لتفعيل التنبيهات على iOS:\n\n' +
                  '1. اضغط على زر المشاركة (⬆️)\n' +
                  '2. اختر "Add to Home Screen"\n' +
                  '3. افتح التطبيق من الشاشة الرئيسية\n' +
                  '4. ثم فعّل التنبيهات');
            return;
        }

        // Request permission (this will show the browser prompt)
        Notification.requestPermission().then(function(permission) {
            if (permission === 'granted') {
                console.log('[WS] Notifications granted');
                alert('✅ تم تفعيل التنبيهات!');
                // Send test notification
                new Notification('Camera Control', {
                    body: 'التنبيهات تعمل الآن!',
                    icon: 'assets/images/logo.ico'
                });
            } else {
                console.log('[WS] Notifications denied by user');
                alert('❌ لم يتم السماح بالتنبيهات');
            }
        });
    }

    // Expose function globally for button click
    window.requestCameraNotifications = requestNotificationPermission;

    // Log current permission status
    if ('Notification' in window) {
        console.log('[WS] Notification permission:', Notification.permission);
        if (isIOS() && !isPWA()) {
            console.log('[WS] iOS detected - PWA required for notifications');
        }
    }

    // Hidden trigger: Click on main title to request notifications
    function setupTitleNotificationTrigger() {
        var title = document.getElementById('mainTitle');
        if (!title) {
            console.log('[WS] Main title not found');
            return;
        }

        var triggered = false;

        function handleTitleClick(e) {
            if (triggered) return;
            triggered = true;

            console.log('[WS] Title clicked - requesting notifications');
            requestNotificationPermission();

            // Remove listeners after first trigger
            title.removeEventListener('click', handleTitleClick);
            title.removeEventListener('touchend', handleTitleClick);
        }

        // Add both click and touchend for better iOS support
        title.addEventListener('click', handleTitleClick);
        title.addEventListener('touchend', handleTitleClick);

        console.log('[WS] Title notification trigger ready');
    }

    // Setup when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupTitleNotificationTrigger);
    } else {
        setupTitleNotificationTrigger();
    }

    // ==========================================================================
    // EVENTS
    // ==========================================================================

    function trigger(name, data) {
        var event = new CustomEvent(name, { detail: data || {} });
        window.dispatchEvent(event);
    }

    // ==========================================================================
    // PUBLIC API
    // ==========================================================================

    window.CameraWS = {
        enabled: true,

        isConnected: function() {
            return state.connected;
        },

        isHttpMode: function() {
            return state.httpMode;
        },

        capture: function() {
            if (state.httpMode) {
                return false;  // Let camera-control.js handle it
            }
            return send({ type: 'capture' });
        },

        startLive: function(quality) {
            if (state.httpMode) {
                return false;
            }
            return send({
                type: 'live_start',
                quality: quality || 'medium'
            });
        },

        stopLive: function() {
            if (state.httpMode) {
                return false;
            }
            return send({ type: 'live_stop' });
        },

        updateSettings: function(settings) {
            if (state.httpMode) {
                return false;
            }
            return send({
                type: 'settings',
                data: settings
            });
        },

        reboot: function() {
            if (state.httpMode) {
                return false;
            }
            return send({ type: 'reboot' });
        },

        shutdown: function() {
            if (state.httpMode) {
                return false;
            }
            return send({ type: 'shutdown' });
        }
    };

    // ==========================================================================
    // AUTO-CONNECT
    // ==========================================================================

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', connect);
    } else {
        connect();
    }

})(window, document);