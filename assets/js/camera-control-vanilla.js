/**
 * Camera Control Center - Client-Side Controller (Vanilla JS)
 *
 * Pure JavaScript implementation - NO JQUERY
 * Optimized for performance and minimal overhead
 *
 * @category  CameraControl
 * @package   Frontend
 * @author    Net Storm
 * @license   Proprietary
 * @version   5.0.0 - Vanilla JS Rewrite
 * @standards ES6+, JSDoc, Clean Code
 *
 * Features:
 * - Live video streaming with quality control
 * - Remote image capture with conflict prevention
 * - Real-time status monitoring
 * - Automatic reconnection handling
 * - Offline detection and recovery
 * - Keyboard shortcuts (Space: capture, L: toggle live)
 *
 * Dependencies:
 * - NONE - Pure Vanilla JavaScript
 *
 * Security:
 * - CSRF protection via server-side validation
 * - Input sanitization
 * - Connection state management
 */

'use strict';

(function(window, document) {
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
        CAPTURE_CHECK_FAST: 25,
        CAPTURE_CHECK_SLOW: 200,
        CAPTURE_MAX_ATTEMPTS: 200,
        OFFLINE_THRESHOLD: 7,
        LIVE_ERROR_THRESHOLD: 7,
        CAPTURE_RESTORE_DELAY: 500,
        LIVE_START_DELAY: 800
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
        isLiveActive: false,
        lastOnlineTime: Date.now(),
        wasLiveBeforeOffline: false,
        captureLock: false,
        liveErrorCount: 0,
        currentQuality: null,
        statusRetryCount: 0,
        firstLoad: true,
        sessionId: null
    };

    // ========================================================================
    // UTILITY FUNCTIONS
    // ========================================================================

    /**
     * Helper: querySelector wrapper
     */
    const $ = (selector) => document.querySelector(selector);
    const $$ = (selector) => document.querySelectorAll(selector);

    /**
     * Execute scripts from HTML fragment (innerHTML doesn't execute them)
     */
    function executeScripts(container) {
        const scripts = container.querySelectorAll('script');
        scripts.forEach(script => {
            const newScript = document.createElement('script');
            if (script.src) {
                newScript.src = script.src;
            } else {
                newScript.textContent = script.textContent;
            }
            // Execute immediately
            script.parentNode.replaceChild(newScript, script);
        });
    }

    /**
     * AJAX request helper using fetch API
     */
    function ajax(options) {
        const defaults = {
            method: 'GET',
            headers: {},
            timeout: 5000,
            cache: false
        };

        const config = { ...defaults, ...options };

        // Add cache busting
        if (config.cache === false && config.method === 'GET') {
            const separator = config.url.includes('?') ? '&' : '?';
            config.url += separator + 't=' + Date.now();
        }

        // Prepare fetch options
        const fetchOptions = {
            method: config.method,
            headers: config.headers
        };

        // Handle POST data
        if (config.data) {
            if (config.method === 'POST') {
                if (typeof config.data === 'object') {
                    const formData = new URLSearchParams();
                    for (const key in config.data) {
                        formData.append(key, config.data[key]);
                    }
                    fetchOptions.body = formData;
                } else {
                    fetchOptions.body = config.data;
                }
            }
        }

        // Timeout promise
        const timeoutPromise = new Promise((_, reject) =>
            setTimeout(() => reject(new Error('Request timeout')), config.timeout)
        );

        // Fetch with timeout
        return Promise.race([
            fetch(config.url, fetchOptions),
            timeoutPromise
        ])
        .then(response => {
            if (!response.ok && config.error) {
                config.error({ status: response.status });
                return null;
            }
            return config.dataType === 'json' ? response.json() : response.text();
        })
        .then(data => {
            if (data !== null && config.success) {
                config.success(data);
            }
            if (config.complete) {
                config.complete();
            }
            return data;
        })
        .catch(error => {
            if (config.error) {
                config.error({ status: 0, error: error.message });
            }
            if (config.complete) {
                config.complete();
            }
        });
    }

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    /**
     * Initialize application on document ready
     */
    function initializeApp() {
        // Check server state and session to handle refresh
        ajax({
            url: 'tmp/web_live.tmp',
            method: 'GET',
            success: function(data) {
                const serverState = data.trim() === 'on' ? 'on' : 'off';
                const select = $('#webLiveSelect');
                if (select) select.value = serverState;

                // If server says ON, check if it's our session (refresh case)
                if (serverState === 'on') {
                    ajax({
                        url: 'tmp/web_live_session.tmp',
                        method: 'GET',
                        success: function(sessionData) {
                            if (sessionData) {
                                const parts = sessionData.trim().split(':');
                                const sessionTime = parseInt(parts[0]) || 0;
                                const now = Date.now();

                                // If session is recent (< 60s), start live (refresh case)
                                if (now - sessionTime < 60000) {
                                    console.log(`[${CONFIG.CAM}] Session active - starting live`);
                                    toggleWebLive();
                                }
                            }
                        }
                    });
                }
            },
            error: function() {
                const select = $('#webLiveSelect');
                if (select) select.value = 'off';
            }
        });

        // Load initial camera status
        loadCameraStatus();
    }

    /**
     * Setup all event handlers
     */
    function setupEventHandlers() {
        setupKeyboardShortcuts();
        setupButtonHandlers();
    }

    /**
     * Setup keyboard shortcuts
     */
    function setupKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
            // Ignore if typing in input fields
            const target = e.target;
            if (target.matches('input, textarea, select')) return;

            // Space key: Capture image
            if (e.keyCode === 32) {
                e.preventDefault();
                captureImage();
            }

            // L key: Toggle live stream
            if (e.keyCode === 76) {
                const select = $('#webLiveSelect');
                if (select) {
                    const currentState = select.value;
                    const newState = currentState === 'on' ? 'off' : 'on';
                    select.value = newState;
                    toggleWebLive();
                }
            }
        });
    }

    /**
     * Setup button click handlers
     */
    function setupButtonHandlers() {
        // Live stream toggle handler
        const webLiveSelect = $('#webLiveSelect');
        if (webLiveSelect) {
            webLiveSelect.addEventListener('change', function() {
                toggleWebLive();
            });
        }

        // Live quality change handler
        const liveQualitySelect = $('#liveQuality');
        if (liveQualitySelect) {
            liveQualitySelect.addEventListener('change', function() {
                updateLiveQuality(true);
            });
        }

        // Capture button handler
        const captureButton = $('#myBut');
        if (captureButton) {
            captureButton.addEventListener('click', function() {
                captureImage();
            });
        }

        // Reboot button
        const rebootButton = $('#rebootButton');
        if (rebootButton) {
            rebootButton.addEventListener('click', function() {
                if (confirm('Are you sure you want to reboot the camera?')) {
                    this.disabled = true;
                    this.textContent = 'Rebooting...';

                    ajax({
                        url: 'admin/reboot.php?token=' + encodeURIComponent(window.ADMIN_TOKEN),
                        method: 'GET',
                        dataType: 'json',
                        success: (data) => {
                            alert(data.message || data.error || 'Operation completed');
                        },
                        error: (xhr) => {
                            alert(xhr.error || 'Failed to reboot camera');
                        },
                        complete: () => {
                            this.disabled = false;
                            this.textContent = 'Reboot';
                        }
                    });
                }
            });
        }

        // Shutdown button
        const shutdownButton = $('#shutdownButton');
        if (shutdownButton) {
            shutdownButton.addEventListener('click', function() {
                const message = 'If you turn off the camera, it will not work unless you turn it off and then on again via the switch.\nAre you sure?';
                if (confirm(message)) {
                    this.disabled = true;
                    this.textContent = 'Shutting down...';

                    ajax({
                        url: 'admin/shutdown.php?token=' + encodeURIComponent(window.ADMIN_TOKEN),
                        method: 'GET',
                        dataType: 'json',
                        success: (data) => {
                            alert(data.message || data.error || 'Operation completed');
                        },
                        error: (xhr) => {
                            alert(xhr.error || 'Failed to shutdown camera');
                        },
                        complete: () => {
                            this.disabled = false;
                            this.textContent = 'Shutdown';
                        }
                    });
                }
            });
        }

        // Clear files button
        const clearButton = $('#clearFilesButton');
        if (clearButton) {
            clearButton.addEventListener('click', function() {
                window.location.href = 'admin/clear.php';
            });
        }
    }

    /**
     * Setup cleanup handler for page exit
     */
    function setupCleanupOnExit() {
        window.addEventListener('beforeunload', function() {
            // Clear intervals
            if (state.statusInterval) clearInterval(state.statusInterval);
            if (state.webLiveInterval) clearInterval(state.webLiveInterval);
            if (state.sessionHeartbeatInterval) clearInterval(state.sessionHeartbeatInterval);

            // Turn off live stream on server
            if (state.isLiveActive) {
                const formData = new URLSearchParams();
                formData.append('action', 'write');
                formData.append('file', 'tmp/web_live.tmp');
                formData.append('data', 'off');
                navigator.sendBeacon('index.php', formData);
            }
        });
    }

    // ========================================================================
    // STATUS MONITORING
    // ========================================================================

    /**
     * Check session conflict - Mutual Exclusion (Two-Way Switch)
     * If another client/server opened live stream → stop our stream
     * This prevents multiple simultaneous streams (Pi can't handle it)
     */
    function checkSessionConflict() {
        if (!state.isLiveActive) return;

        ajax({
            url: 'tmp/web_live_session.tmp',
            method: 'GET',
            timeout: 3000,
            success: function(data) {
                const trimmed = data.trim();
                if (!trimmed) return;

                const parts = trimmed.split(':');
                const sessionTimestamp = parseInt(parts[0]) || 0;
                const sessionId = parts[1] || '';

                if (sessionId && sessionId !== state.sessionId) {
                    const now = Date.now();
                    const age = now - sessionTimestamp;

                    if (age < 30000) {
                        console.log(`[${CONFIG.CAM}] Live stream opened elsewhere - stopping here (session conflict)`);

                        const select = $('#webLiveSelect');
                        if (select) select.value = 'off';
                        stopLiveStream();
                    }
                }
            },
            error: function() {
            }
        });
    }

    /**
     * Start periodic status monitoring
     */
    function startStatusMonitoring() {
        if (state.statusInterval) clearInterval(state.statusInterval);

        state.statusInterval = setInterval(function() {
            loadCameraStatus();
            checkBrowserConnection();
            checkSessionConflict(); // Mutual Exclusion - Two-Way Switch
        }, CONFIG.STATUS_UPDATE_INTERVAL);
    }

    /**
     * Load and display camera status
     */
    function loadCameraStatus() {
        ajax({
            url: 'mode.php',
            method: 'GET',
            timeout: 5000,
            success: function(response) {
                state.statusRetryCount = 0;
                state.firstLoad = false;
                const container = $('#id1');
                if (container) {
                    container.innerHTML = response;
                    // Execute scripts that came with the HTML
                    executeScripts(container);
                }
                manageLiveStreamBasedOnStatus();
            },
            error: function(xhr) {
                if (state.statusRetryCount < 2) {
                    state.statusRetryCount++;
                    setTimeout(loadCameraStatus, 200);
                    return;
                }

                if (!state.firstLoad) {
                    const container = $('#id1');
                    if (container) {
                        container.innerHTML = '<span class="camera-offline">Camera Offline</span>';
                    }
                }
                console.log(`[${CONFIG.CAM}] mode.php load error - HTTP ${xhr.status}`);
                window.cameraOnlineStatus = false;
                window.secondsSinceUpdate = 999;
                state.statusRetryCount = 0;
                state.firstLoad = false;
                manageLiveStreamBasedOnStatus();
            }
        });
    }

    /**
     * Manage live stream based on camera online/offline status
     */
    function manageLiveStreamBasedOnStatus() {
        const isOnline = window.cameraOnlineStatus || false;
        const secondsSince = window.secondsSinceUpdate || 999;
        const now = Date.now();

        if (isOnline && secondsSince <= CONFIG.OFFLINE_THRESHOLD) {
            // Camera is online
            state.lastOnlineTime = now;

            // Restart live stream if it was active before going offline
            if (state.wasLiveBeforeOffline && !state.isLiveActive) {
                console.log(`[${CONFIG.CAM}] 🔄 Camera back online - restarting live stream`);
                state.wasLiveBeforeOffline = false;
                const select = $('#webLiveSelect');
                if (select) select.value = 'on';
                startLiveStream();
            }
        } else {
            // Camera is offline or connection lost
            const offlineTime = (now - state.lastOnlineTime) / 1000;

            if (offlineTime > CONFIG.OFFLINE_THRESHOLD && state.isLiveActive) {
                console.log(`[${CONFIG.CAM}] ⏸️ Camera offline for ${offlineTime.toFixed(0)}s - stopping live stream`);
                state.wasLiveBeforeOffline = true;
                stopLiveStreamSilent();
            }
        }
    }

    /**
     * Check browser internet connection
     */
    function checkBrowserConnection() {
        if (!window.navigator.onLine) {
            alert('Web browser without internet connection\n\nTry to:\nCheck network cables, modem and router\nReconnect to a Wi-Fi network');
        }
    }

    // ========================================================================
    // IMAGE CAPTURE
    // ========================================================================

    /**
     * Capture image from camera
     * Global function exposed for onclick handlers
     */
    window.captureImage = function() {
        if (state.captureLock) {
            console.log(`[${CONFIG.CAM}] ⚠️ captureImage() ignored: capture already in progress`);
            return;
        }

        const select = $('#webLiveSelect');
        const currentLiveState = select ? select.value : 'off';
        let wasLiveActive = false;

        // CRITICAL: Stop live stream BEFORE capture to avoid camera resource conflict
        if (currentLiveState === 'on' && state.isLiveActive) {
            wasLiveActive = true;
            console.log(`[${CONFIG.CAM}] 🛑 Stopping live stream for capture...`);
            state.isLiveActive = false;
            if (state.webLiveInterval) clearInterval(state.webLiveInterval);
            if (select) select.value = 'off';

            ajax({
                url: 'index.php',
                method: 'POST',
                data: {
                    action: 'write',
                    file: 'tmp/web_live.tmp',
                    data: 'off'
                }
            });
        }

        // Lock capture to prevent concurrent requests
        state.captureLock = true;
        const button = $('#myBut');
        const originalText = button ? button.textContent : 'Capture';

        if (button) {
            button.disabled = true;
            button.textContent = 'Capturing...';
            button.classList.add('blinking');
        }

        const beforeCaptureTime = Date.now() / 1000;

        // Collect camera settings from form
        const formData = {
            res: $('select[name="res"]')?.value,
            comp: $('select[name="comp"]')?.value,
            iso: $('select[name="iso"]')?.value,
            sat: $('select[name="sat"]')?.value,
            rot: $('select[name="rot"]')?.value,
            fx: $('select[name="fx"]')?.value,
            enf: $('select[name="enf"]')?.value,
            b1: 'inic',
            submit: 'submit'
        };

        // Send capture request
        ajax({
            url: 'index.php',
            method: 'POST',
            data: formData,
            success: function(response) {
                if (response === 'BUSY') {
                    console.warn(`[${CONFIG.CAM}] ⚠️ Server responded BUSY`);
                    resetCaptureButton(button, originalText);
                    restoreLiveStreamIfNeeded(wasLiveActive);
                    alert('Camera is busy. Please wait and try again.');
                    return;
                }
                checkForNewImage(button, originalText, beforeCaptureTime, 0, wasLiveActive);
            },
            error: function(error) {
                console.error(`[${CONFIG.CAM}] ❌ Capture POST failed:`, error);
                resetCaptureButton(button, originalText);
                restoreLiveStreamIfNeeded(wasLiveActive);
                alert('Failed to capture image. Please try again.');
            }
        });
    };

    /**
     * Check for new captured image (recursive polling)
     */
    function checkForNewImage(button, originalText, beforeCaptureTime, attempts, wasLiveActive) {
        if (attempts >= CONFIG.CAPTURE_MAX_ATTEMPTS) {
            console.warn(`[${CONFIG.CAM}] ⏱️ Timeout after ${attempts} attempts`);
            resetCaptureButton(button, originalText);
            restoreLiveStreamIfNeeded(wasLiveActive);
            alert('Image capture timed out. The Camera may be busy or offline. Please try again.');
            return;
        }

        ajax({
            url: 'index.php?check_new_image',
            method: 'GET',
            success: function(data) {
                if (data !== '0') {
                    const imageTimestamp = parseInt(data, 10);

                    if (imageTimestamp > beforeCaptureTime) {
                        const captureTime = (imageTimestamp - beforeCaptureTime).toFixed(2);
                        console.log(`[${CONFIG.CAM}] ✅ Image received in ${captureTime}s (timestamp: ${imageTimestamp})`);

                        displayNewImage(imageTimestamp, captureTime);
                        resetCaptureButton(button, originalText);

                        // Restore live stream AFTER image is received
                        if (wasLiveActive) {
                            console.log(`[${CONFIG.CAM}] ▶️ Restarting live stream...`);
                            setTimeout(function() {
                                const select = $('#webLiveSelect');
                                if (select) select.value = 'on';
                                toggleWebLive();
                            }, CONFIG.CAPTURE_RESTORE_DELAY);
                        }
                    } else {
                        setTimeout(function() {
                            checkForNewImage(button, originalText, beforeCaptureTime, attempts + 1, wasLiveActive);
                        }, CONFIG.CAPTURE_CHECK_SLOW);
                    }
                } else {
                    setTimeout(function() {
                        checkForNewImage(button, originalText, beforeCaptureTime, attempts + 1, wasLiveActive);
                    }, CONFIG.CAPTURE_CHECK_FAST);
                }
            },
            error: function() {
                setTimeout(function() {
                    checkForNewImage(button, originalText, beforeCaptureTime, attempts + 1, wasLiveActive);
                }, CONFIG.CAPTURE_CHECK_SLOW);
            }
        });
    }

    /**
     * Display newly captured image
     */
    function displayNewImage(timestamp, captureTime) {
        // Generate unique cache buster
        const cacheBuster = timestamp + '_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

        // Check if containers already exist
        let imageContainer = $('#ImageContainer');
        let imageDetails = $('#imageDetails');
        let img = $('#Image');

        const isUpdate = !!(imageContainer && imageDetails && img);

        // Create or update image container
        if (!imageContainer) {
            imageContainer = document.createElement('div');
            imageContainer.id = 'ImageContainer';
            imageContainer.className = 'glass-panel';
            imageContainer.innerHTML = `
                <img id="Image" alt="Captured Image" loading="eager" class="captured-image">
            `;
        }

        // Create or update details container
        if (!imageDetails) {
            imageDetails = document.createElement('div');
            imageDetails.id = 'imageDetails';
            imageDetails.className = 'glass-panel image-details-panel';
        }

        // Update loading message
        imageDetails.innerHTML = `
            <p id="imageSizeText" class="image-size-text">Loading image size...</p>
        `;

        // Insert containers if they don't exist
        if (!isUpdate) {
            const form = $('form');
            if (form) {
                const panel = form.closest('.glass-panel');
                if (panel) {
                    panel.insertAdjacentElement('afterend', imageContainer);
                    imageContainer.insertAdjacentElement('afterend', imageDetails);
                }
            }
        }

        // Preload image with retry mechanism
        const newImage = new Image();
        let retryCount = 0;
        const maxRetries = 3;

        newImage.onload = function() {
            img = $('#Image');
            if (img) {
                img.src = this.src;
                imageContainer.style.display = 'block';
                imageDetails.style.display = 'block';
            }

            // Fetch and display image size + capture time
            ajax({
                url: 'index.php?get_image_size=1',
                method: 'GET',
                success: function(sizeData) {
                    const sizeText = $('#imageSizeText');
                    if (sizeText) {
                        sizeText.className = 'data-text';
                        sizeText.innerHTML = `Image size: ${sizeData} <span class="capture-time">Image size: ${captureTime}s</span>`;
                    }
                },
                error: function() {
                    const sizeText = $('#imageSizeText');
                    if (sizeText) {
                        sizeText.innerHTML = `Image size: Unknown <span class="capture-time">Image size: ${captureTime}s</span>`;
                    }
                }
            });
        };

        newImage.onerror = function() {
            retryCount++;
            const sizeText = $('#imageSizeText');
            if (retryCount <= maxRetries) {
                if (sizeText) {
                    sizeText.textContent = 'Loading image... (' + retryCount + '/' + maxRetries + ')';
                }
                setTimeout(function() {
                    newImage.src = 'pic.jpg?v=' + Date.now() + '_retry' + retryCount;
                }, 1000);
            } else {
                if (sizeText) {
                    sizeText.textContent = 'Error: Could not load image';
                }
            }
        };

        newImage.src = 'pic.jpg?v=' + cacheBuster;
    }

    /**
     * Reset capture button to original state
     */
    function resetCaptureButton(button, originalText) {
        state.captureLock = false;
        if (button) {
            button.disabled = false;
            button.textContent = originalText;
            button.classList.remove('blinking');
        }
    }

    /**
     * Restore live stream if it was active before capture
     */
    function restoreLiveStreamIfNeeded(wasLiveActive) {
        if (wasLiveActive) {
            const select = $('#webLiveSelect');
            if (select) select.value = 'on';
            toggleWebLive();
        }
    }

    // ========================================================================
    // LIVE STREAM MANAGEMENT
    // ========================================================================

    /**
     * Toggle live stream on/off
     * Global function exposed for onchange handlers
     */
    window.toggleWebLive = function() {
        const select = $('#webLiveSelect');
        if (!select) return;

        const selectedState = select.value;
        if (selectedState === 'on') {
            startLiveStream();
        } else {
            stopLiveStream();
        }
    };

    /**
     * Send session heartbeat to keep live active
     */
    function sendSessionHeartbeat() {
        if (!state.isLiveActive || !state.sessionId) return;

        const timestamp = Date.now();
        const data = timestamp + ':' + state.sessionId;

        ajax({
            url: 'index.php',
            method: 'POST',
            data: {
                action: 'write',
                file: 'tmp/web_live_session.tmp',
                data: data
            }
        });
    }

    /**
     * Start session heartbeat interval
     */
    function startSessionHeartbeat() {
        state.sessionId = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        sendSessionHeartbeat();

        if (state.sessionHeartbeatInterval) {
            clearInterval(state.sessionHeartbeatInterval);
        }

        state.sessionHeartbeatInterval = setInterval(sendSessionHeartbeat, 10000);
    }

    /**
     * Stop session heartbeat interval
     */
    function stopSessionHeartbeat() {
        if (state.sessionHeartbeatInterval) {
            clearInterval(state.sessionHeartbeatInterval);
            state.sessionHeartbeatInterval = null;
        }
        state.sessionId = null;
    }

    /**
     * Start live video streaming
     */
    function startLiveStream() {
        console.log(`[${CONFIG.CAM}] 🎥 Starting live stream...`);
        state.isLiveActive = true;
        startSessionHeartbeat();

        const container = $('#webLiveContainer');
        const image = $('#webLiveImage');

        if (container) container.style.display = 'block';
        if (image) image.src = 'buffer.jpg';

        // Show loading indicator
        const liveFeed = $('#liveFeed');
        if (liveFeed && !$('#loadingIndicator')) {
            const indicator = document.createElement('div');
            indicator.id = 'loadingIndicator';
            indicator.className = 'loading-overlay';
            indicator.innerHTML = `
                <div class="spinner"></div>
                <div class="loading-text">Starting live stream...</div>
            `;
            liveFeed.appendChild(indicator);
        }

        // Restore saved quality preference
        const savedQuality = localStorage.getItem('preferredQuality');
        const qualitySelect = $('#liveQuality');
        if (qualitySelect) {
            qualitySelect.value = savedQuality || 'very-low';
        }

        // Update quality settings
        updateLiveQuality(false);

        // Send live stream start signal to server
        ajax({
            url: 'index.php',
            method: 'POST',
            data: {
                action: 'write',
                file: 'tmp/web_live.tmp',
                data: 'on'
            },
            success: function(response) {
                if (response.trim() === 'OK') {
                    console.log(`[${CONFIG.CAM}] ▶️ Live stream ready`);
                    setTimeout(function() {
                        const indicator = $('#loadingIndicator');
                        if (indicator) indicator.remove();
                        startLiveUpdates();
                    }, CONFIG.LIVE_START_DELAY);
                } else {
                    console.error(`[${CONFIG.CAM}] ❌ Failed to start: ${response}`);
                    const indicator = $('#loadingIndicator');
                    if (indicator) indicator.remove();
                    alert('Failed to start live stream: ' + response);
                }
            },
            error: function(xhr) {
                console.error(`[${CONFIG.CAM}] ❌ Failed to start:`, xhr.error);
                const indicator = $('#loadingIndicator');
                if (indicator) indicator.remove();
                alert('Failed to start live stream: ' + xhr.error);
            }
        });
    }

    /**
     * Stop live stream (user action)
     */
    function stopLiveStream() {
        console.log(`[${CONFIG.CAM}] ⏹️ Live stream OFF (user stopped)`);
        state.isLiveActive = false;
        stopSessionHeartbeat();
        if (state.webLiveInterval) clearInterval(state.webLiveInterval);

        const container = $('#webLiveContainer');
        const indicator = $('#loadingIndicator');

        if (container) container.style.display = 'none';
        if (indicator) indicator.remove();

        ajax({
            url: 'index.php',
            method: 'POST',
            data: {
                action: 'write',
                file: 'tmp/web_live.tmp',
                data: 'off'
            }
        });
    }

    /**
     * Stop live stream silently (auto-stop due to connection loss)
     */
    function stopLiveStreamSilent() {
        console.log(`[${CONFIG.CAM}] ⏸️ Live stream paused (connection timeout)`);
        state.isLiveActive = false;
        stopSessionHeartbeat();
        if (state.webLiveInterval) clearInterval(state.webLiveInterval);

        const image = $('#webLiveImage');
        const indicator = $('#loadingIndicator');

        if (image) image.src = 'buffer.jpg';
        if (indicator) indicator.remove();
    }

    /**
     * Start periodic live image updates
     */
    function startLiveUpdates() {
        if (state.webLiveInterval) clearInterval(state.webLiveInterval);

        // Initial update
        updateWebLiveImage();

        // Periodic updates
        state.webLiveInterval = setInterval(function() {
            const select = $('#webLiveSelect');
            if (state.isLiveActive && select && select.value === 'on') {
                updateWebLiveImage();
            } else {
                clearInterval(state.webLiveInterval);
                state.webLiveInterval = null;
            }
        }, CONFIG.LIVE_UPDATE_INTERVAL);
    }

    /**
     * Update live stream image
     */
    function updateWebLiveImage() {
        // Safety check: ensure live is still active
        const select = $('#webLiveSelect');
        if (!select || select.value !== 'on' || !state.isLiveActive) {
            return;
        }

        const img = new Image();

        img.onload = function() {
            const liveImage = $('#webLiveImage');
            if (liveImage) liveImage.src = this.src;

            // Reset error counter on success
            if (state.liveErrorCount > 0) {
                console.log(`[${CONFIG.CAM}] ✅ Live stream recovered`);
                state.liveErrorCount = 0;
            }
        };

        img.onerror = function() {
            state.liveErrorCount++;
            console.warn(`[${CONFIG.CAM}] ⚠️ Live image load failed (attempt ${state.liveErrorCount})`);

            // Stop after too many failures
            if (state.liveErrorCount >= CONFIG.LIVE_ERROR_THRESHOLD) {
                console.error(`[${CONFIG.CAM}] ❌ Live stream timeout - stopping after ${CONFIG.LIVE_ERROR_THRESHOLD} failed attempts`);
                stopLiveStreamSilent();
                state.liveErrorCount = 0;
            }
        };

        img.src = 'live.jpg?' + Date.now();
    }

    /**
     * Update live stream quality
     * Global function exposed for onchange handlers
     */
    window.updateLiveQuality = function(forceUpdate) {
        const qualitySelect = $('#liveQuality');
        if (!qualitySelect) return;

        const quality = qualitySelect.value;
        const preset = QUALITY_PRESETS[quality] || QUALITY_PRESETS['very-low'];
        const width = preset[0];
        const height = preset[1];
        const qualityValue = preset[2];
        const data = width + ' ' + height + ' ' + qualityValue;

        // Skip update if quality hasn't changed and not forced
        if (!forceUpdate && state.currentQuality === data) {
            return;
        }

        state.currentQuality = data;
        localStorage.setItem('preferredQuality', quality);

        ajax({
            url: 'index.php',
            method: 'POST',
            data: {
                action: 'write',
                file: 'tmp/web_live_quality.tmp',
                data: data
            },
            success: function(response) {
                if (response.trim() === 'OK') {
                    console.log(`[${CONFIG.CAM}] ✅ Quality set: ${width}x${height} q=${qualityValue}`);

                    // Restart live feed if quality was changed manually
                    if (forceUpdate && state.webLiveInterval && state.isLiveActive) {
                        setTimeout(function() {
                            const liveImage = $('#webLiveImage');
                            if (liveImage) liveImage.src = 'buffer.jpg';
                            setTimeout(function() {
                                startLiveUpdates();
                            }, 500);
                        }, 500);
                    }
                } else {
                    console.error(`[${CONFIG.CAM}] ❌ Server response: ${response}`);
                }
            },
            error: function(xhr) {
                console.error(`[${CONFIG.CAM}] ❌ Quality update failed:`, xhr.error);
            }
        });
    };

    // ========================================================================
    // STARTUP
    // ========================================================================

    /**
     * DOMContentLoaded event - start application
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initializeApp();
            setupEventHandlers();
            startStatusMonitoring();
            setupCleanupOnExit();
        });
    } else {
        // DOM already loaded
        initializeApp();
        setupEventHandlers();
        startStatusMonitoring();
        setupCleanupOnExit();
    }

})(window, document);
