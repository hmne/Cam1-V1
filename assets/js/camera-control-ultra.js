/**
 * Camera Control - Ultra Performance Edition
 *
 * Vanilla JavaScript - No jQuery dependency
 * Aggressive memory management
 * Optimized for 24/7 live streaming
 *
 * @version 5.0.0
 * @author Net Storm
 */

'use strict';

(function(window, document) {
    // ========================================================================
    // CONFIGURATION - OPTIMIZED FOR SPEED
    // ========================================================================
    const CONFIG = {
        CAM: window.CAMERA_NAME || 'Camera',
        STATUS_UPDATE_INTERVAL: 2000,      // 2 sec
        LIVE_UPDATE_INTERVAL: 1000,        // 1 sec (faster updates)
        CAPTURE_CHECK_INTERVAL: 50,        // 50ms (very fast polling)
        CAPTURE_MAX_WAIT: 15000,           // 15 sec max wait
        OFFLINE_THRESHOLD: 7,
        // Aggressive memory management
        MAX_IMAGE_OBJECTS: 3,              // Keep only 3 images max
        CLEANUP_INTERVAL: 10000            // Clean every 10 sec
    };

    // ========================================================================
    // STATE
    // ========================================================================
    const state = {
        statusInterval: null,
        liveInterval: null,
        cleanupInterval: null,
        isLiveActive: false,
        captureLock: false,
        liveErrorCount: 0,
        imagePool: [],
        previousOnlineStatus: null,
        notificationsEnabled: false,
        lastImageTimestamp: 0
    };

    // ========================================================================
    // DOM CACHE (avoid repeated lookups)
    // ========================================================================
    const DOM = {};

    function cacheDOMElements() {
        DOM.statusContainer = document.getElementById('id1');
        DOM.liveSelect = document.getElementById('webLiveSelect');
        DOM.liveContainer = document.getElementById('webLiveContainer');
        DOM.liveImage = document.getElementById('webLiveImage');
        DOM.captureButton = document.getElementById('myBut');
        DOM.qualitySelect = document.getElementById('liveQuality');
    }

    // ========================================================================
    // MEMORY MANAGEMENT - AGGRESSIVE
    // ========================================================================

    function cleanupImages() {
        while (state.imagePool.length > CONFIG.MAX_IMAGE_OBJECTS) {
            const oldImg = state.imagePool.shift();
            if (oldImg) {
                oldImg.onload = null;
                oldImg.onerror = null;
                oldImg.src = 'data:,'; // Clear immediately
            }
        }
    }

    function createImage() {
        const img = new Image();
        state.imagePool.push(img);
        cleanupImages();
        return img;
    }

    // ========================================================================
    // HTTP REQUESTS - FAST & LIGHTWEIGHT
    // ========================================================================

    function fetchText(url) {
        return fetch(url, {
            method: 'GET',
            cache: 'no-store',
            headers: { 'Cache-Control': 'no-cache' }
        }).then(r => r.text());
    }

    function fetchHTML(url) {
        return fetch(url, {
            method: 'GET',
            cache: 'no-store'
        }).then(r => r.text());
    }

    function postData(url, data) {
        const formData = new URLSearchParams(data);
        return fetch(url, {
            method: 'POST',
            body: formData,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        }).then(r => r.text());
    }

    // ========================================================================
    // BROWSER NOTIFICATIONS
    // ========================================================================

    function requestNotificationPermission() {
        if (!('Notification' in window)) return;

        if (Notification.permission === 'granted') {
            state.notificationsEnabled = true;
        } else if (Notification.permission !== 'denied') {
            Notification.requestPermission().then(p => {
                state.notificationsEnabled = (p === 'granted');
            });
        }
    }

    function sendNotification(title, body) {
        if (!state.notificationsEnabled) return;
        if (document.visibilityState === 'visible' && document.hasFocus()) return;

        const n = new Notification(title, {
            body: body,
            icon: 'assets/images/logo.ico',
            tag: CONFIG.CAM + '-status',
            renotify: true
        });

        setTimeout(() => n.close(), 10000);
        n.onclick = () => { window.focus(); n.close(); };
    }

    function checkStatusChange(isOnline) {
        if (state.previousOnlineStatus === null) {
            state.previousOnlineStatus = isOnline;
            return;
        }

        if (isOnline !== state.previousOnlineStatus) {
            if (isOnline) {
                sendNotification('🟢 ' + CONFIG.CAM + ' Connected', 'Camera is online');
            } else {
                sendNotification('🔴 ' + CONFIG.CAM + ' Disconnected', 'Camera connection lost!');
            }
            state.previousOnlineStatus = isOnline;
        }
    }

    // ========================================================================
    // STATUS MONITORING
    // ========================================================================

    function updateStatus() {
        fetchHTML('mode.php?t=' + Date.now())
            .then(html => {
                if (DOM.statusContainer) {
                    DOM.statusContainer.innerHTML = html;
                }

                const isOnline = window.cameraOnlineStatus || false;
                const secondsSince = window.secondsSinceUpdate || 999;
                const actuallyOnline = isOnline && secondsSince <= CONFIG.OFFLINE_THRESHOLD;

                checkStatusChange(actuallyOnline);

                // Auto-manage live stream
                if (!actuallyOnline && state.isLiveActive) {
                    stopLiveStream();
                }
            })
            .catch(() => {
                window.cameraOnlineStatus = false;
            });
    }

    function startStatusMonitoring() {
        updateStatus();
        state.statusInterval = setInterval(updateStatus, CONFIG.STATUS_UPDATE_INTERVAL);
    }

    // ========================================================================
    // LIVE STREAMING - OPTIMIZED
    // ========================================================================

    const QUALITY_PRESETS = {
        'very-low': [480, 360, 8],
        'low': [640, 480, 16],
        'medium': [800, 600, 24],
        'high': [1024, 768, 32]
    };

    function updateLiveImage() {
        if (!state.isLiveActive || !DOM.liveImage) return;

        const img = createImage();
        const cacheBuster = Date.now() + '_' + Math.random().toString(36).substr(2, 5);

        img.onload = function() {
            if (state.isLiveActive && DOM.liveImage) {
                DOM.liveImage.src = this.src;
                state.liveErrorCount = 0;
            }
            this.onload = null;
        };

        img.onerror = function() {
            state.liveErrorCount++;
            if (state.liveErrorCount > 5) {
                stopLiveStream();
            }
            this.onerror = null;
        };

        img.src = 'live.jpg?v=' + cacheBuster;
    }

    function startLiveStream() {
        if (state.isLiveActive) return;

        const quality = DOM.qualitySelect ? DOM.qualitySelect.value : 'very-low';
        const preset = QUALITY_PRESETS[quality] || QUALITY_PRESETS['very-low'];
        const qualityString = preset.join(' ');

        postData('index.php', {
            action: 'write',
            file: 'tmp/web_live.tmp',
            data: 'on'
        });

        postData('index.php', {
            action: 'write',
            file: 'tmp/web_live_quality.tmp',
            data: qualityString
        });

        state.isLiveActive = true;
        state.liveErrorCount = 0;

        if (DOM.liveContainer) {
            DOM.liveContainer.style.display = 'block';
        }

        // Start fast updates
        updateLiveImage();
        state.liveInterval = setInterval(updateLiveImage, CONFIG.LIVE_UPDATE_INTERVAL);

        console.log('[' + CONFIG.CAM + '] 🟢 Live stream started');
    }

    function stopLiveStream() {
        if (!state.isLiveActive) return;

        postData('index.php', {
            action: 'write',
            file: 'tmp/web_live.tmp',
            data: 'off'
        });

        state.isLiveActive = false;

        if (state.liveInterval) {
            clearInterval(state.liveInterval);
            state.liveInterval = null;
        }

        if (DOM.liveContainer) {
            DOM.liveContainer.style.display = 'none';
        }

        // Clear image to free memory
        if (DOM.liveImage) {
            DOM.liveImage.src = 'data:,';
        }

        console.log('[' + CONFIG.CAM + '] 🔴 Live stream stopped');
    }

    window.toggleWebLive = function() {
        if (!DOM.liveSelect) return;

        if (DOM.liveSelect.value === 'on') {
            startLiveStream();
        } else {
            stopLiveStream();
        }
    };

    window.updateLiveQuality = function() {
        if (!state.isLiveActive || !DOM.qualitySelect) return;

        const quality = DOM.qualitySelect.value;
        const preset = QUALITY_PRESETS[quality] || QUALITY_PRESETS['very-low'];
        const qualityString = preset.join(' ');

        postData('index.php', {
            action: 'write',
            file: 'tmp/web_live_quality.tmp',
            data: qualityString
        });
    };

    // ========================================================================
    // IMAGE CAPTURE - ULTRA FAST
    // ========================================================================

    window.captureImage = function() {
        if (state.captureLock) return;
        if (!DOM.captureButton) return;

        state.captureLock = true;
        const originalText = DOM.captureButton.textContent;
        const wasLiveActive = state.isLiveActive;
        const beforeTime = Date.now();

        // Stop live stream during capture
        if (wasLiveActive) {
            stopLiveStream();
            if (DOM.liveSelect) DOM.liveSelect.value = 'off';
        }

        DOM.captureButton.textContent = 'Capturing...';
        DOM.captureButton.disabled = true;

        // Trigger capture
        postData('index.php', { b1: 'inic' })
            .then(() => {
                console.log('[' + CONFIG.CAM + '] 📸 Capture triggered');
                pollForNewImage(beforeTime, wasLiveActive, originalText);
            })
            .catch(() => {
                DOM.captureButton.textContent = originalText;
                DOM.captureButton.disabled = false;
                state.captureLock = false;
            });
    };

    function pollForNewImage(beforeTime, wasLiveActive, originalText) {
        const startPoll = Date.now();

        function check() {
            if (Date.now() - startPoll > CONFIG.CAPTURE_MAX_WAIT) {
                finishCapture(originalText, wasLiveActive);
                return;
            }

            fetchText('index.php?check_new_image=1&t=' + Date.now())
                .then(timestamp => {
                    const ts = parseInt(timestamp, 10) * 1000;

                    if (ts > beforeTime && ts !== state.lastImageTimestamp) {
                        state.lastImageTimestamp = ts;
                        const captureTime = ((Date.now() - beforeTime) / 1000).toFixed(2);
                        displayCapturedImage(ts, captureTime);
                        finishCapture(originalText, wasLiveActive);
                    } else {
                        setTimeout(check, CONFIG.CAPTURE_CHECK_INTERVAL);
                    }
                })
                .catch(() => {
                    setTimeout(check, CONFIG.CAPTURE_CHECK_INTERVAL);
                });
        }

        check();
    }

    function displayCapturedImage(timestamp, captureTime) {
        const img = createImage();
        const cacheBuster = Date.now() + '_' + Math.random().toString(36).substr(2, 5);

        img.onload = function() {
            // Get size
            fetchText('index.php?get_image_size=1&t=' + Date.now())
                .then(size => {
                    const container = document.getElementById('liveImageContainer') ||
                                     document.getElementById('webLiveContainer');

                    if (container) {
                        container.innerHTML = '<img src="' + this.src + '" style="max-width:100%; border-radius:10px;" />';
                        container.style.display = 'block';
                    }

                    let details = document.getElementById('imageDetails');
                    if (!details) {
                        details = document.createElement('div');
                        details.id = 'imageDetails';
                        details.style.cssText = 'text-align:center; margin-top:10px; font-size:14px;';
                        if (container) container.after(details);
                    }
                    details.innerHTML = '📷 ' + size + ' | ⏱️ ' + captureTime + 's';
                })
                .catch(() => {});

            this.onload = null;
        };

        img.src = 'pic.jpg?v=' + cacheBuster;
    }

    function finishCapture(originalText, wasLiveActive) {
        if (DOM.captureButton) {
            DOM.captureButton.textContent = originalText;
            DOM.captureButton.disabled = false;
        }
        state.captureLock = false;

        // Restore live stream
        if (wasLiveActive) {
            setTimeout(() => {
                if (DOM.liveSelect) DOM.liveSelect.value = 'on';
                startLiveStream();
            }, 500);
        }
    }

    // ========================================================================
    // KEYBOARD SHORTCUTS
    // ========================================================================

    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
            return;
        }

        // Space = Capture
        if (e.keyCode === 32) {
            e.preventDefault();
            window.captureImage();
        }

        // L = Toggle Live
        if (e.keyCode === 76) {
            if (DOM.liveSelect) {
                DOM.liveSelect.value = DOM.liveSelect.value === 'on' ? 'off' : 'on';
                window.toggleWebLive();
            }
        }
    });

    // ========================================================================
    // ADMIN BUTTONS
    // ========================================================================

    function setupAdminButtons() {
        const rebootBtn = document.getElementById('rebootButton');
        const shutdownBtn = document.getElementById('shutdownButton');
        const clearBtn = document.getElementById('clearFilesButton');

        if (rebootBtn) {
            rebootBtn.onclick = function() {
                if (confirm('Reboot camera system?')) {
                    fetch('admin/reboot.php?token=' + window.ADMIN_TOKEN);
                }
            };
        }

        if (shutdownBtn) {
            shutdownBtn.onclick = function() {
                if (confirm('Shutdown camera system?')) {
                    fetch('admin/shutdown.php?token=' + window.ADMIN_TOKEN);
                }
            };
        }

        if (clearBtn) {
            clearBtn.onclick = function() {
                if (confirm('Clear temporary files?')) {
                    fetch('admin/clear.php?token=' + window.ADMIN_TOKEN);
                }
            };
        }
    }

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    function init() {
        cacheDOMElements();
        requestNotificationPermission();
        setupAdminButtons();
        startStatusMonitoring();

        // Aggressive memory cleanup
        state.cleanupInterval = setInterval(cleanupImages, CONFIG.CLEANUP_INTERVAL);

        // Check initial live state
        fetchText('tmp/web_live.tmp?t=' + Date.now())
            .then(data => {
                if (data.trim() === 'on' && DOM.liveSelect) {
                    DOM.liveSelect.value = 'on';
                    startLiveStream();
                }
            })
            .catch(() => {});

        console.log('[' + CONFIG.CAM + '] ⚡ Ultra Performance Mode Active');
    }

    // Start when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})(window, document);
