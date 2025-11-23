/**
 * Camera Control Center - Client-Side Controller
 *
 * Enterprise-grade JavaScript module for camera management interface.
 * Handles live streaming, image capture, status monitoring, and user interactions.
 *
 * Performance Optimizations:
 * - Memory leak prevention with automatic cleanup
 * - Strong cache busting for images (always fresh)
 * - Optional Page Visibility API (configurable via PHP)
 * - Automatic cleanup of Image objects
 *
 * @category  CameraControl
 * @package   Frontend
 * @author    Net Storm
 * @license   Proprietary
 * @version   1.0.0 - Performance & Memory Optimized
 * @standards ES5+, JSDoc, Clean Code
 *
 * Features:
 * - Live video streaming with quality control
 * - Remote image capture with conflict prevention
 * - Real-time status monitoring
 * - Automatic reconnection handling
 * - Offline detection and recovery
 * - Keyboard shortcuts (Space: capture, L: toggle live)
 * - Memory leak prevention
 * - Strong cache busting
 *
 * Dependencies:
 * - jQuery 3.7.1+
 *
 * Security:
 * - CSRF protection via server-side validation
 * - Input sanitization
 * - Connection state management
 */

'use strict';

(function($, window, document) {
    // ========================================================================
    // CONFIGURATION
    // ========================================================================

    /**
     * Application configuration
     * @type {Object}
     */
    const CONFIG = {
        CAM: window.CAMERA_NAME || 'Camera',
        STATUS_UPDATE_INTERVAL: 2000,
        LIVE_UPDATE_INTERVAL: 1500,
        CAPTURE_CHECK_FAST: 10,
        CAPTURE_CHECK_SLOW: 150,
        CAPTURE_MAX_ATTEMPTS: 300,
        OFFLINE_THRESHOLD: 7,
        LIVE_ERROR_THRESHOLD: 7,
        CAPTURE_RESTORE_DELAY: 500,
        LIVE_START_DELAY: 800,
        // Memory management
        MAX_IMAGE_OBJECTS: 5,
        CLEANUP_INTERVAL: 30000, // 30 seconds
        // Page Visibility (from PHP config)
        ENABLE_PAGE_VISIBILITY: window.ENABLE_PAGE_VISIBILITY !== false
    };

    /**
     * Quality presets for live streaming
     * @type {Object}
     */
    const QUALITY_PRESETS = {
        'very-low': [480, 360, 8],
        'low': [640, 480, 16],
        'medium': [800, 600, 24],
        'high': [1024, 768, 32]
    };

    // ========================================================================
    // STATE MANAGEMENT
    // ========================================================================

    /**
     * Application state
     * @type {Object}
     */
    const state = {
        statusInterval: null,
        webLiveInterval: null,
        sessionHeartbeatInterval: null,
        cleanupInterval: null,
        isLiveActive: false,
        lastOnlineTime: Date.now(),
        wasLiveBeforeOffline: false,
        captureLock: false,
        liveErrorCount: 0,
        currentQuality: null,
        statusRetryCount: 0,
        firstLoad: true,
        sessionId: null,
        // Smart error logging
        consecutiveStatusErrors: 0,
        lastStatusErrorLogged: 0,
        wasStatusOffline: false,
        // Memory management
        imageObjects: [],
        isPageVisible: true,
        lastCacheBuster: 0,
        // Browser notifications
        previousOnlineStatus: null,
        notificationsEnabled: false
    };

    // ========================================================================
    // BROWSER NOTIFICATIONS
    // ========================================================================
    // NOTE: When WebSocket is active, notifications come from websocket-client.js
    // based on VPS events (camera_online/camera_offline). This is more accurate
    // because it reflects the actual WebSocket connection state.
    // HTTP fallback mode uses these functions based on status.tmp file age.

    /**
     * Check browser notification permission status (don't request - needs user gesture)
     */
    function checkNotificationPermission() {
        if (!('Notification' in window)) {
            console.log(`[${CONFIG.CAM}] Browser does not support notifications`);
            return;
        }

        if (Notification.permission === 'granted') {
            state.notificationsEnabled = true;
            console.log(`[${CONFIG.CAM}] 🔔 Notifications enabled`);
        } else {
            state.notificationsEnabled = false;
            console.log(`[${CONFIG.CAM}] 🔔 Notification permission: ${Notification.permission}`);
        }
    }

    // Cooldown for notifications
    var lastBrowserNotificationTime = 0;
    var BROWSER_NOTIFICATION_COOLDOWN = 30000; // 30 seconds

    /**
     * Send browser notification for status change
     * @param {string} title - Notification title
     * @param {string} body - Notification body
     */
    function sendBrowserNotification(title, body) {
        if (!state.notificationsEnabled || Notification.permission !== 'granted') {
            return;
        }

        // Don't notify if page is visible and focused
        if (document.visibilityState === 'visible' && document.hasFocus()) {
            return;
        }

        // Cooldown check - prevent spam
        var now = Date.now();
        if (now - lastBrowserNotificationTime < BROWSER_NOTIFICATION_COOLDOWN) {
            console.log(`[${CONFIG.CAM}] Notification skipped (cooldown)`);
            return;
        }
        lastBrowserNotificationTime = now;

        var notification = new Notification(title, {
            body: body,
            icon: 'assets/images/logo.ico',
            tag: CONFIG.CAM + '-status',
            renotify: false // Don't spam
        });

        setTimeout(function() {
            notification.close();
        }, 10000);

        notification.onclick = function() {
            window.focus();
            notification.close();
        };
    }

    /**
     * Check and notify on camera status change
     * @param {boolean} isOnline - Current online status
     */
    function checkStatusAndNotify(isOnline) {
        if (state.previousOnlineStatus === null) {
            state.previousOnlineStatus = isOnline;
            return;
        }

        if (isOnline !== state.previousOnlineStatus) {
            if (isOnline) {
                sendBrowserNotification(
                    '🟢 ' + CONFIG.CAM + ' Connected',
                    'Camera is now online and ready'
                );
            } else {
                sendBrowserNotification(
                    '🔴 ' + CONFIG.CAM + ' Disconnected',
                    'Camera connection lost! Please check.'
                );
            }
            state.previousOnlineStatus = isOnline;
        }
    }

    // ========================================================================
    // MEMORY MANAGEMENT
    // ========================================================================

    /**
     * Cleanup old Image objects to prevent memory leaks
     */
    function cleanupImageObjects() {
        if (state.imageObjects.length > CONFIG.MAX_IMAGE_OBJECTS) {
            const toRemove = state.imageObjects.length - CONFIG.MAX_IMAGE_OBJECTS;
            const removed = state.imageObjects.splice(0, toRemove);

            removed.forEach(function(img) {
                if (img) {
                    img.onload = null;
                    img.onerror = null;
                    img.src = '';
                }
            });

            console.log(`[${CONFIG.CAM}] 🧹 Cleaned up ${toRemove} old Image objects`);
        }
    }

    /**
     * Create tracked Image object with automatic cleanup
     */
    function createTrackedImage() {
        const img = new Image();
        state.imageObjects.push(img);
        cleanupImageObjects();
        return img;
    }

    /**
     * Start periodic memory cleanup
     */
    function startMemoryCleanup() {
        if (state.cleanupInterval) clearInterval(state.cleanupInterval);

        state.cleanupInterval = setInterval(function() {
            cleanupImageObjects();
        }, CONFIG.CLEANUP_INTERVAL);
    }

    /**
     * Generate strong cache buster
     * Ensures unique value even if called multiple times in same millisecond
     */
    function generateCacheBuster() {
        const now = Date.now();

        if (now === state.lastCacheBuster) {
            state.lastCacheBuster = now + 1;
        } else {
            state.lastCacheBuster = now;
        }

        return state.lastCacheBuster + '_' + Math.random().toString(36).substr(2, 9);
    }

    // ========================================================================
    // PAGE VISIBILITY API (OPTIONAL)
    // ========================================================================

    /**
     * Handle page visibility changes (if enabled)
     */
    function handleVisibilityChange() {
        if (!CONFIG.ENABLE_PAGE_VISIBILITY) return;

        if (document.hidden) {
            state.isPageVisible = false;
            console.log(`[${CONFIG.CAM}] 👁️ Page hidden - reducing activity`);

            if (state.isLiveActive && state.webLiveInterval) {
                clearInterval(state.webLiveInterval);
                // Slower refresh when hidden
                state.webLiveInterval = setInterval(updateWebLiveImage, CONFIG.LIVE_UPDATE_INTERVAL * 3);
            }
        } else {
            state.isPageVisible = true;
            console.log(`[${CONFIG.CAM}] 👁️ Page visible - resuming normal activity`);

            if (state.isLiveActive) {
                if (state.webLiveInterval) clearInterval(state.webLiveInterval);
                startLiveUpdates();
            }

            loadCameraStatus();
        }
    }

    /**
     * Setup Page Visibility API (if enabled)
     */
    function setupVisibilityAPI() {
        if (!CONFIG.ENABLE_PAGE_VISIBILITY) return;

        if (typeof document.hidden !== 'undefined') {
            document.addEventListener('visibilitychange', handleVisibilityChange);
        }
    }

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    $(document).ready(function() {
        initializeApp();
        setupEventHandlers();
        startStatusMonitoring();
        setupCleanupOnExit();
        setupVisibilityAPI();
        startMemoryCleanup();
    });

    function initializeApp() {
        $.ajaxPrefilter(function(options) {
            options.async = true;
            options.cache = false;
            if (!options.timeout) {
                options.timeout = 5000;
            }
        });

        // Check notification permission status (don't request - needs user gesture)
        checkNotificationPermission();

        $.ajax({
            url: 'tmp/web_live.tmp',
            type: 'GET',
            async: true,
            cache: false,
            success: function(data) {
                const serverState = data.trim() === 'on' ? 'on' : 'off';
                $('#webLiveSelect').val(serverState);
                if (serverState === 'on') {
                    toggleWebLive();
                }
            },
            error: function() {
                $('#webLiveSelect').val('off');
            }
        });

        loadCameraStatus();
    }

    function setupEventHandlers() {
        setupKeyboardShortcuts();
        setupButtonHandlers();
    }

    function setupKeyboardShortcuts() {
        $(document).keydown(function(e) {
            if ($(e.target).is('input, textarea, select')) return;

            if (e.keyCode === 32) {
                e.preventDefault();
                $('#myBut').click();
            }

            if (e.keyCode === 76) {
                const currentState = $('#webLiveSelect').val();
                const newState = currentState === 'on' ? 'off' : 'on';
                $('#webLiveSelect').val(newState).change();
            }
        });
    }

    function setupButtonHandlers() {
        $('#rebootButton').click(function() {
            if (confirm('Are you sure you want to reboot the camera?')) {
                const $button = $(this);
                $button.prop('disabled', true).text('Rebooting...');

                $.get('admin/reboot.php', { token: window.ADMIN_TOKEN })
                    .done(function(response) {
                        const data = typeof response === 'string' ? JSON.parse(response) : response;
                        alert(data.message || data.error || 'Operation completed');
                    })
                    .fail(function(xhr) {
                        const error = xhr.responseJSON || {};
                        alert(error.error || 'Failed to reboot camera');
                    })
                    .always(function() {
                        $button.prop('disabled', false).text('Reboot');
                    });
            }
        });

        $('#shutdownButton').click(function() {
            const message = 'If you turn off the camera, it will not work unless you turn it off and then on again via the switch.\nAre you sure?';
            if (confirm(message)) {
                const $button = $(this);
                $button.prop('disabled', true).text('Shutting down...');

                $.get('admin/shutdown.php', { token: window.ADMIN_TOKEN })
                    .done(function(response) {
                        const data = typeof response === 'string' ? JSON.parse(response) : response;
                        alert(data.message || data.error || 'Operation completed');
                    })
                    .fail(function(xhr) {
                        const error = xhr.responseJSON || {};
                        alert(error.error || 'Failed to shutdown camera');
                    })
                    .always(function() {
                        $button.prop('disabled', false).text('Shutdown');
                    });
            }
        });

        $('#clearFilesButton').click(function() {
            window.location.href = 'admin/clear.php';
        });
    }

    function setupCleanupOnExit() {
        $(window).on('beforeunload', function() {
            if (state.statusInterval) clearInterval(state.statusInterval);
            if (state.webLiveInterval) clearInterval(state.webLiveInterval);
            if (state.sessionHeartbeatInterval) clearInterval(state.sessionHeartbeatInterval);
            if (state.cleanupInterval) clearInterval(state.cleanupInterval);

            state.imageObjects.forEach(function(img) {
                if (img) {
                    img.onload = null;
                    img.onerror = null;
                    img.src = '';
                }
            });
            state.imageObjects = [];

            if (state.isLiveActive) {
                $.post('index.php', {
                    action: 'write',
                    file: 'tmp/web_live.tmp',
                    data: 'off'
                });
            }
        });
    }

    // ========================================================================
    // STATUS MONITORING
    // ========================================================================

    function checkSessionConflict() {
        if (!state.isLiveActive) return;

        $.ajax({
            url: 'tmp/web_live_session.tmp?t=' + Date.now(),
            type: 'GET',
            cache: false,
            timeout: 3000,
            success: function(data) {
                const trimmed = $.trim(data);
                if (!trimmed) return;

                const parts = trimmed.split(':');
                const sessionTimestamp = parseInt(parts[0]) || 0;
                const sessionId = parts[1] || '';

                if (sessionId && sessionId !== state.sessionId) {
                    const now = Date.now();
                    const age = now - sessionTimestamp;

                    if (age < 30000) {
                        console.log(`[${CONFIG.CAM}] Live stream opened elsewhere - stopping here`);
                        $('#webLiveSelect').val('off');
                        stopLiveStream();
                    }
                }
            },
            error: function() {}
        });
    }

    function startStatusMonitoring() {
        if (state.statusInterval) clearInterval(state.statusInterval);

        state.statusInterval = setInterval(function() {
            loadCameraStatus();
            checkBrowserConnection();
            checkSessionConflict();
        }, CONFIG.STATUS_UPDATE_INTERVAL);
    }

    function updateControlPanelVisibility() {
        const $stateElement = $('#controlPanelState');
        if ($stateElement.length) {
            const shouldShow = $stateElement.data('show-panel') === true || $stateElement.data('show-panel') === 'true';
            const $form = $('#myForm');
            if ($form.length) {
                $form.css('display', shouldShow ? 'block' : 'none');
            }
        }
    }

    function loadCameraStatus() {
        $.ajax({
            url: 'mode.php?t=' + generateCacheBuster(),
            type: 'GET',
            async: true,
            cache: false,
            timeout: 5000,
            success: function(response) {
                state.statusRetryCount = 0;
                state.firstLoad = false;

                // Log recovery if we were in error state
                if (state.consecutiveStatusErrors > 0) {
                    console.log(`[${CONFIG.CAM}] ✅ Connection restored after ${state.consecutiveStatusErrors} errors`);
                    state.consecutiveStatusErrors = 0;
                    state.wasStatusOffline = false;
                }

                $('#id1').html(response);
                updateControlPanelVisibility();
                manageLiveStreamBasedOnStatus();
            },
            error: function(xhr) {
                if (state.statusRetryCount < 2) {
                    state.statusRetryCount++;
                    setTimeout(loadCameraStatus, 200);
                    return;
                }

                if (!state.firstLoad) {
                    $('#id1').html('<span class="camera-offline">Camera Offline</span>');
                }

                // Smart error logging - only log on state changes or periodically
                state.consecutiveStatusErrors++;
                const shouldLog = (
                    state.consecutiveStatusErrors === 1 ||  // First error
                    state.consecutiveStatusErrors % 10 === 0 ||  // Every 10th error
                    xhr.status !== state.lastStatusErrorLogged  // Different error type
                );

                if (shouldLog) {
                    if (state.consecutiveStatusErrors === 1) {
                        console.warn(`[${CONFIG.CAM}] ⚠️ mode.php connection lost - HTTP ${xhr.status}`);
                    } else {
                        console.warn(`[${CONFIG.CAM}] ⚠️ Still offline (${state.consecutiveStatusErrors} attempts) - HTTP ${xhr.status}`);
                    }
                    state.lastStatusErrorLogged = xhr.status;
                }

                window.cameraOnlineStatus = false;
                window.secondsSinceUpdate = 999;
                state.statusRetryCount = 0;
                state.firstLoad = false;
                state.wasStatusOffline = true;
                manageLiveStreamBasedOnStatus();
            }
        });
    }

    function manageLiveStreamBasedOnStatus() {
        const isOnline = window.cameraOnlineStatus || false;
        const secondsSince = window.secondsSinceUpdate || 999;
        const now = Date.now();
        const isActuallyOnline = isOnline && secondsSince <= CONFIG.OFFLINE_THRESHOLD;

        // Send browser notification if status changed
        // ONLY if WebSocket is NOT connected (HTTP fallback mode)
        // When WebSocket is active, notifications come from websocket-client.js based on VPS events
        const useHttpNotifications = !window.CameraWS || !window.CameraWS.enabled || !window.CameraWS.isConnected();
        if (useHttpNotifications) {
            checkStatusAndNotify(isActuallyOnline);
        }

        if (isActuallyOnline) {
            state.lastOnlineTime = now;

            if (state.wasLiveBeforeOffline && !state.isLiveActive) {
                console.log(`[${CONFIG.CAM}] 🔄 Camera back online - restarting live stream`);
                state.wasLiveBeforeOffline = false;
                $('#webLiveSelect').val('on');
                startLiveStream();
            }
        } else {
            const offlineTime = (now - state.lastOnlineTime) / 1000;

            if (offlineTime > CONFIG.OFFLINE_THRESHOLD && state.isLiveActive) {
                console.log(`[${CONFIG.CAM}] ⏸️ Camera offline for ${offlineTime.toFixed(0)}s`);
                state.wasLiveBeforeOffline = true;
                stopLiveStreamSilent();
            }
        }
    }

    function checkBrowserConnection() {
        if (!window.navigator.onLine) {
            alert('Web browser without internet connection\n\nTry to:\nCheck network cables, modem and router\nReconnect to a Wi-Fi network');
        }
    }

    // ========================================================================
    // IMAGE CAPTURE
    // ========================================================================

    window.captureImage = function() {
        if (state.captureLock) {
            console.log(`[${CONFIG.CAM}] ⚠️ Capture already in progress`);
            return;
        }

        const currentLiveState = $('#webLiveSelect').val();
        let wasLiveActive = false;

        if (currentLiveState === 'on' && state.isLiveActive) {
            wasLiveActive = true;
            console.log(`[${CONFIG.CAM}] 🛑 Stopping live for capture`);
            state.isLiveActive = false;
            if (state.webLiveInterval) clearInterval(state.webLiveInterval);
            $('#webLiveSelect').val('off');

            // PRIMARY: Try WebSocket first
            if (window.CameraWS && window.CameraWS.enabled && window.CameraWS.isConnected()) {
                window.CameraWS.stopLive();
            } else {
                // FALLBACK: Use HTTP
                $.post('index.php', {
                    action: 'write',
                    file: 'tmp/web_live.tmp',
                    data: 'off'
                });
            }
        }

        state.captureLock = true;
        const $button = $('#myBut');
        const originalText = $button.text();
        $button.prop('disabled', true).text('Capturing...').addClass('blinking');

        const beforeCaptureTime = Date.now() / 1000;

        const formData = {
            res: $('select[name="res"]').val(),
            comp: $('select[name="comp"]').val(),
            iso: $('select[name="iso"]').val(),
            sat: $('select[name="sat"]').val(),
            rot: $('select[name="rot"]').val(),
            fx: $('select[name="fx"]').val(),
            enf: $('select[name="enf"]').val(),
            b1: 'inic',
            submit: 'submit'
        };

        // Use HTTP for capture - fast, reliable, battle-tested
        console.log(`[${CONFIG.CAM}] 📸 Triggering capture via HTTP`);
        $.post('index.php', formData)
            .done(function(response) {
                if (response === 'BUSY') {
                    console.warn(`[${CONFIG.CAM}] ⚠️ Camera busy`);
                    resetCaptureButton($button, originalText);
                    restoreLiveStreamIfNeeded(wasLiveActive);
                    alert('Camera is busy. Please wait and try again.');
                    return;
                }
                // Start polling for new image
                checkForNewImage($button, originalText, beforeCaptureTime, 0, wasLiveActive);
            })
            .fail(function(error) {
                console.error(`[${CONFIG.CAM}] ❌ Capture request failed:`, error);
                resetCaptureButton($button, originalText);
                restoreLiveStreamIfNeeded(wasLiveActive);
                alert('Failed to capture image. Please try again.');
            });
    };

    function checkForNewImage($button, originalText, beforeCaptureTime, attempts, wasLiveActive) {
        if (attempts >= CONFIG.CAPTURE_MAX_ATTEMPTS) {
            resetCaptureButton($button, originalText);
            restoreLiveStreamIfNeeded(wasLiveActive);
            alert('Image capture timed out. The Camera may be busy or offline.');
            return;
        }

        $.get('index.php?check_new_image&t=' + generateCacheBuster())
            .done(function(data) {
                if (data !== '0') {
                    const imageTimestamp = parseInt(data, 10);

                    if (imageTimestamp > beforeCaptureTime) {
                        const captureTime = (imageTimestamp - beforeCaptureTime).toFixed(2);
                        console.log(`[${CONFIG.CAM}] ✅ Image received in ${captureTime}s`);

                        displayNewImage(imageTimestamp, captureTime);
                        resetCaptureButton($button, originalText);

                        if (wasLiveActive) {
                            setTimeout(function() {
                                $('#webLiveSelect').val('on');
                                toggleWebLive();
                            }, CONFIG.CAPTURE_RESTORE_DELAY);
                        }
                    } else {
                        setTimeout(function() {
                            checkForNewImage($button, originalText, beforeCaptureTime, attempts + 1, wasLiveActive);
                        }, CONFIG.CAPTURE_CHECK_SLOW);
                    }
                } else {
                    setTimeout(function() {
                        checkForNewImage($button, originalText, beforeCaptureTime, attempts + 1, wasLiveActive);
                    }, CONFIG.CAPTURE_CHECK_FAST);
                }
            })
            .fail(function() {
                setTimeout(function() {
                    checkForNewImage($button, originalText, beforeCaptureTime, attempts + 1, wasLiveActive);
                }, CONFIG.CAPTURE_CHECK_SLOW);
            });
    }

    function displayNewImage(timestamp, captureTime) {
        const cacheBuster = timestamp + '_' + generateCacheBuster();
        const isUpdate = $('#ImageContainer').length > 0;

        if (!isUpdate) {
            const $imageContainer = $(`
                <div id="ImageContainer" class="glass-panel">
                    <img id="Image" alt="Captured Image" loading="eager" class="captured-image">
                </div>
                <div id="imageDetails" class="glass-panel image-details-panel">
                    <span id="imageSizeText" class="image-size-text">Loading image size...</span>
                </div>
            `);

            $('form').closest('.glass-panel').after($imageContainer);
        } else {
            $('#imageSizeText').html('Loading image size...');
        }

        const newImage = createTrackedImage();
        let retryCount = 0;
        const maxRetries = 3;

        newImage.onload = function() {
            $('#Image').attr('src', this.src);
            $('#ImageContainer, #imageDetails').fadeIn('fast');

            $.get('index.php?get_image_size=1&t=' + generateCacheBuster())
                .done(function(sizeData) {
                    $('#imageSizeText').addClass('data-text').html(
                        'Image size: ' + sizeData +
                        ' <span class="capture-time">Time: ' + captureTime + 's</span>' +
                        ' <button onclick="saveImageToDevice()" class="save-btn" title="Save to device (S)">💾</button>'
                    );
                })
                .fail(function() {
                    $('#imageSizeText').html(
                        'Image size: Unknown' +
                        ' <span class="capture-time">Time: ' + captureTime + 's</span>' +
                        ' <button onclick="saveImageToDevice()" class="save-btn" title="Save to device (S)">💾</button>'
                    );
                });
        };

        newImage.onerror = function() {
            retryCount++;
            if (retryCount <= maxRetries) {
                $('#imageSizeText').text('Loading image... (' + retryCount + '/' + maxRetries + ')');
                setTimeout(function() {
                    newImage.src = 'pic.jpg?v=' + generateCacheBuster() + '_retry' + retryCount;
                }, 1000);
            } else {
                $('#imageSizeText').text('Error: Could not load image');
            }
        };

        newImage.src = 'pic.jpg?v=' + cacheBuster;
    }

    function resetCaptureButton($button, originalText) {
        state.captureLock = false;
        $button.prop('disabled', false).text(originalText).removeClass('blinking');
    }

    function restoreLiveStreamIfNeeded(wasLiveActive) {
        if (wasLiveActive) {
            $('#webLiveSelect').val('on');
            toggleWebLive();
        }
    }

    // ========================================================================
    // LIVE STREAM MANAGEMENT
    // ========================================================================

    window.toggleWebLive = function() {
        const selectedState = $('#webLiveSelect').val();
        if (selectedState === 'on') {
            startLiveStream();
        } else {
            stopLiveStream();
        }
    };

    function sendSessionHeartbeat() {
        if (!state.isLiveActive || !state.sessionId) return;
        const timestamp = Date.now();
        const data = timestamp + ':' + state.sessionId;
        $.post('index.php', {action: 'write', file: 'tmp/web_live_session.tmp', data: data});
    }

    function startSessionHeartbeat() {
        state.sessionId = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        sendSessionHeartbeat();
        if (state.sessionHeartbeatInterval) clearInterval(state.sessionHeartbeatInterval);
        state.sessionHeartbeatInterval = setInterval(sendSessionHeartbeat, 10000);
    }

    function stopSessionHeartbeat() {
        if (state.sessionHeartbeatInterval) {
            clearInterval(state.sessionHeartbeatInterval);
            state.sessionHeartbeatInterval = null;
        }
        state.sessionId = null;
    }

    function startLiveStream() {
        console.log(`[${CONFIG.CAM}] 🎥 Starting live stream...`);
        state.isLiveActive = true;
        startSessionHeartbeat();
        $('#webLiveContainer').fadeIn();
        $('#webLiveImage').attr('src', 'buffer.jpg');

        if (!$('#loadingIndicator').length) {
            $('#liveFeed').append(
                '<div id="loadingIndicator" class="loading-overlay">' +
                    '<div class="spinner"></div>' +
                    '<div class="loading-text">Starting live stream...</div>' +
                '</div>'
            );
        }

        const savedQuality = localStorage.getItem('preferredQuality');
        $('#liveQuality').val(savedQuality || 'very-low');
        const quality = savedQuality || 'very-low';

        updateLiveQuality(false);

        // PRIMARY: Try WebSocket first (instant!)
        if (window.CameraWS && window.CameraWS.enabled && window.CameraWS.isConnected()) {
            console.log(`[${CONFIG.CAM}] ⚡ Using WebSocket for live start`);
            window.CameraWS.startLive(quality);
        }

        // ALWAYS use HTTP fallback (fail-safe backup)
        console.log(`[${CONFIG.CAM}] 🔄 Using HTTP fallback for live start`);
        $.post('index.php', {
            action: 'write',
            file: 'tmp/web_live.tmp',
            data: 'on'
        })
        .done(function(response) {
            if (response.trim() === 'OK') {
                console.log(`[${CONFIG.CAM}] ▶️ Live stream ready (HTTP)`);
                setTimeout(function() {
                    $('#loadingIndicator').remove();
                    startLiveUpdates();
                }, CONFIG.LIVE_START_DELAY);
            } else {
                $('#loadingIndicator').remove();
                alert('Failed to start live stream: ' + response);
            }
        })
        .fail(function(xhr, status, error) {
            $('#loadingIndicator').remove();
            alert('Failed to start live stream: ' + error);
        });
    }

    function stopLiveStream() {
        console.log(`[${CONFIG.CAM}] ⏹️ Live stream OFF`);
        state.isLiveActive = false;
        stopSessionHeartbeat();
        if (state.webLiveInterval) clearInterval(state.webLiveInterval);
        $('#webLiveContainer').fadeOut();
        $('#loadingIndicator').remove();

        // PRIMARY: Try WebSocket first (instant!)
        if (window.CameraWS && window.CameraWS.enabled && window.CameraWS.isConnected()) {
            console.log(`[${CONFIG.CAM}] ⚡ Using WebSocket for live stop`);
            window.CameraWS.stopLive();
        }

        // ALWAYS use HTTP fallback (fail-safe backup)
        console.log(`[${CONFIG.CAM}] 🔄 Using HTTP fallback for live stop`);
        $.post('index.php', {
            action: 'write',
            file: 'tmp/web_live.tmp',
            data: 'off'
        });
    }

    function stopLiveStreamSilent() {
        console.log(`[${CONFIG.CAM}] ⏸️ Live stream paused`);
        state.isLiveActive = false;
        stopSessionHeartbeat();
        if (state.webLiveInterval) clearInterval(state.webLiveInterval);
        $('#webLiveImage').attr('src', 'buffer.jpg');
        $('#loadingIndicator').remove();
    }

    function startLiveUpdates() {
        if (state.webLiveInterval) clearInterval(state.webLiveInterval);

        updateWebLiveImage();

        state.webLiveInterval = setInterval(function() {
            if (state.isLiveActive && $('#webLiveSelect').val() === 'on') {
                updateWebLiveImage();
            } else {
                clearInterval(state.webLiveInterval);
                state.webLiveInterval = null;
            }
        }, CONFIG.LIVE_UPDATE_INTERVAL);
    }

    function updateWebLiveImage() {
        if ($('#webLiveSelect').val() !== 'on' || !state.isLiveActive) {
            return;
        }

        const img = createTrackedImage();

        img.onload = function() {
            $('#webLiveImage').attr('src', this.src);

            if (state.liveErrorCount > 0) {
                console.log(`[${CONFIG.CAM}] ✅ Live stream recovered`);
                state.liveErrorCount = 0;
            }
        };

        img.onerror = function() {
            state.liveErrorCount++;
            console.warn(`[${CONFIG.CAM}] ⚠️ Live image load failed (${state.liveErrorCount})`);

            if (state.liveErrorCount >= CONFIG.LIVE_ERROR_THRESHOLD) {
                console.error(`[${CONFIG.CAM}] ❌ Live stream timeout`);
                stopLiveStreamSilent();
                state.liveErrorCount = 0;
            }
        };

        img.src = 'live.jpg?v=' + generateCacheBuster();
    }

    window.updateLiveQuality = function(forceUpdate) {
        const quality = $('#liveQuality').val();
        const preset = QUALITY_PRESETS[quality] || QUALITY_PRESETS['very-low'];
        const width = preset[0];
        const height = preset[1];
        const qualityValue = preset[2];
        const data = width + ' ' + height + ' ' + qualityValue;

        if (!forceUpdate && state.currentQuality === data) {
            return;
        }

        state.currentQuality = data;
        localStorage.setItem('preferredQuality', quality);

        $.post('index.php', {
            action: 'write',
            file: 'tmp/web_live_quality.tmp',
            data: data
        })
        .done(function(response) {
            if (response.trim() === 'OK') {
                console.log(`[${CONFIG.CAM}] ✅ Quality: ${width}x${height} q=${qualityValue}`);

                if (forceUpdate && state.webLiveInterval && state.isLiveActive) {
                    setTimeout(function() {
                        $('#webLiveImage').attr('src', 'buffer.jpg');
                        setTimeout(function() {
                            startLiveUpdates();
                        }, 500);
                    }, 500);
                }
            }
        })
        .fail(function(xhr, status, error) {
            console.error(`[${CONFIG.CAM}] ❌ Quality update failed: ${status}`);
        });
    };

    // ========================================================================
    // SAVE IMAGE TO DEVICE
    // ========================================================================

    // ========================================================================
    // KEYBOARD SHORTCUTS
    // ========================================================================

    $(document).on('keydown', function(e) {
        // Don't trigger shortcuts when typing in input fields
        if ($(e.target).is('input, textarea, select')) {
            return;
        }

        switch (e.key.toLowerCase()) {
            case 'c':
                // C = Capture
                e.preventDefault();
                window.captureImage();
                console.log(`[${CONFIG.CAM}] ⌨️ Keyboard shortcut: Capture (C)`);
                break;

            case 's':
                // S = Save
                e.preventDefault();
                window.saveImageToDevice();
                console.log(`[${CONFIG.CAM}] ⌨️ Keyboard shortcut: Save (S)`);
                break;

            case 'l':
                // L = Toggle Live
                e.preventDefault();
                const currentState = $('#webLiveSelect').val();
                $('#webLiveSelect').val(currentState === 'on' ? 'off' : 'on');
                window.toggleWebLive();
                console.log(`[${CONFIG.CAM}] ⌨️ Keyboard shortcut: Toggle Live (L)`);
                break;

            case 'r':
                // R = Refresh status
                e.preventDefault();
                loadCameraStatus();
                console.log(`[${CONFIG.CAM}] ⌨️ Keyboard shortcut: Refresh (R)`);
                break;
        }
    });

})(jQuery, window, document);