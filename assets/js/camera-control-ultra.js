/**
 * Camera Control - Ultra Performance Edition
 *
 * Vanilla JavaScript - No jQuery dependency
 * Aggressive memory management
 * Optimized for 24/7 live streaming
 *
 * @version 1.0.0
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
        LIVE_START_DELAY: 800,             // 800ms delay for camera to start
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
        sessionHeartbeatInterval: null,
        sessionId: null,
        isLiveActive: false,
        captureLock: false,
        liveErrorCount: 0,
        imagePool: [],
        previousOnlineStatus: null,
        notificationsEnabled: false,
        lastImageTimestamp: 0,
        lastOnlineTime: Date.now(),
        wasLiveBeforeOffline: false
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
        // Only cleanup images that are no longer in use
        // Don't cleanup during load - wait for onload/onerror to finish
        const maxImages = CONFIG.MAX_IMAGE_OBJECTS;

        while (state.imagePool.length > maxImages) {
            const oldImg = state.imagePool.shift();
            if (oldImg) {
                // Only cleanup if handlers are already cleared (image finished loading)
                if (!oldImg.onload && !oldImg.onerror) {
                    oldImg.src = 'data:,';
                }
            }
        }
    }

    function createImage() {
        const img = new Image();
        state.imagePool.push(img);
        // Don't cleanup here - cleanup in background interval only
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

    function updateControlPanelVisibility() {
        const stateElement = document.getElementById('controlPanelState');
        if (stateElement) {
            const shouldShow = stateElement.getAttribute('data-show-panel') === 'true';
            const form = document.getElementById('myForm');
            if (form) {
                form.style.display = shouldShow ? 'block' : 'none';
            }
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

                    // CRITICAL: Execute inline scripts (innerHTML doesn't execute them!)
                    const scripts = DOM.statusContainer.getElementsByTagName('script');
                    for (let i = 0; i < scripts.length; i++) {
                        const oldScript = scripts[i];
                        const newScript = document.createElement('script');
                        newScript.text = oldScript.text;
                        document.head.appendChild(newScript).parentNode.removeChild(newScript);
                    }

                    updateControlPanelVisibility();
                }
                manageLiveStreamBasedOnStatus();
            })
            .catch(() => {
                window.cameraOnlineStatus = false;
                window.secondsSinceUpdate = 999;
                manageLiveStreamBasedOnStatus();
            });
    }

    function manageLiveStreamBasedOnStatus() {
        const isOnline = window.cameraOnlineStatus || false;
        const secondsSince = window.secondsSinceUpdate || 999;
        const now = Date.now();
        const isActuallyOnline = isOnline && secondsSince <= CONFIG.OFFLINE_THRESHOLD;

        // Debug log for troubleshooting
        if (state.isLiveActive) {
            console.log('[' + CONFIG.CAM + '] 📊 Status: online=' + isOnline + ', secondsSince=' + secondsSince + ', actuallyOnline=' + isActuallyOnline);
        }

        // Send browser notification if status changed
        checkStatusChange(isActuallyOnline);

        if (isActuallyOnline) {
            state.lastOnlineTime = now;

            // Restart live stream if it was active before going offline
            if (state.wasLiveBeforeOffline && !state.isLiveActive) {
                console.log('[' + CONFIG.CAM + '] 🔄 Camera back online - restarting live stream');
                state.wasLiveBeforeOffline = false;
                if (DOM.liveSelect) DOM.liveSelect.value = 'on';
                startLiveStream();
            }
        } else {
            const offlineTime = (now - state.lastOnlineTime) / 1000;

            // Only pause if offline for more than threshold AND live is active
            if (offlineTime > CONFIG.OFFLINE_THRESHOLD && state.isLiveActive) {
                console.log('[' + CONFIG.CAM + '] ⏸️ Camera offline for ' + offlineTime.toFixed(0) + 's - pausing live stream');
                state.wasLiveBeforeOffline = true;
                stopLiveStreamSilent(); // Silent stop - keep container visible
            }
        }
    }

    function startStatusMonitoring() {
        updateStatus();
        state.statusInterval = setInterval(updateStatus, CONFIG.STATUS_UPDATE_INTERVAL);
    }

    // ========================================================================
    // SESSION HEARTBEAT (Required for camera to keep live active)
    // ========================================================================

    function sendSessionHeartbeat() {
        if (!state.isLiveActive || !state.sessionId) return;

        const timestamp = Date.now();
        const data = timestamp + ':' + state.sessionId;

        postData('index.php', {
            action: 'write',
            file: 'tmp/web_live_session.tmp',
            data: data
        });
    }

    function startSessionHeartbeat() {
        state.sessionId = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        sendSessionHeartbeat();

        if (state.sessionHeartbeatInterval) {
            clearInterval(state.sessionHeartbeatInterval);
        }
        state.sessionHeartbeatInterval = setInterval(sendSessionHeartbeat, 10000);
    }

    function stopSessionHeartbeat() {
        if (state.sessionHeartbeatInterval) {
            clearInterval(state.sessionHeartbeatInterval);
            state.sessionHeartbeatInterval = null;
        }
        state.sessionId = null;
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
        if (!state.isLiveActive || !DOM.liveImage) {
            return;
        }

        // Double check select value
        if (DOM.liveSelect && DOM.liveSelect.value !== 'on') {
            stopLiveStream();
            return;
        }

        // Create new Image to preload, then assign to visible element
        const img = new Image();
        const cacheBuster = Date.now() + '_' + Math.random().toString(36).substr(2, 5);

        img.onload = function() {
            if (state.isLiveActive && DOM.liveImage) {
                // Update the visible image with the loaded src
                DOM.liveImage.src = this.src;
                state.liveErrorCount = 0;
            }
            // Clear handler to prevent memory leaks
            this.onload = null;
            this.onerror = null;
        };

        img.onerror = function() {
            state.liveErrorCount++;
            if (state.liveErrorCount <= 3) {
                console.warn('[' + CONFIG.CAM + '] ⚠️ Live image load failed (' + state.liveErrorCount + '/15)');
            }
            // More tolerance for errors (live.jpg may not exist yet)
            if (state.liveErrorCount > 15) {
                console.error('[' + CONFIG.CAM + '] ❌ Live stream timeout after 15 failures');
                // Use silent stop - don't hide container, just pause updates
                stopLiveStreamSilent();
            }
            // Clear handler
            this.onload = null;
            this.onerror = null;
        };

        // Preload the image
        img.src = 'live.jpg?t=' + cacheBuster;
    }

    function startLiveStream() {
        if (state.isLiveActive) return;

        const quality = DOM.qualitySelect ? DOM.qualitySelect.value : 'very-low';
        const preset = QUALITY_PRESETS[quality] || QUALITY_PRESETS['very-low'];
        const qualityString = preset.join(' ');

        console.log('[' + CONFIG.CAM + '] 🎥 Starting live stream...');

        // Start session heartbeat IMMEDIATELY (camera needs this!)
        state.isLiveActive = true;
        startSessionHeartbeat();

        // Show container immediately with loading indicator
        if (DOM.liveContainer) {
            DOM.liveContainer.style.display = 'block';

            // Add loading indicator
            if (!document.getElementById('loadingIndicator')) {
                const liveFeed = document.getElementById('liveFeed');
                if (liveFeed) {
                    const loadingDiv = document.createElement('div');
                    loadingDiv.id = 'loadingIndicator';
                    loadingDiv.className = 'loading-overlay';
                    loadingDiv.innerHTML = '<div class="spinner"></div><div class="loading-text">Starting live stream...</div>';
                    liveFeed.appendChild(loadingDiv);
                }
            }
        }
        if (DOM.liveImage) {
            DOM.liveImage.src = 'buffer.jpg'; // Show buffer image while waiting
        }

        // Send live signal to server
        postData('index.php', {
            action: 'write',
            file: 'tmp/web_live.tmp',
            data: 'on'
        }).then(function(response) {
            if (response.trim() === 'OK') {
                console.log('[' + CONFIG.CAM + '] ▶️ Live stream signal sent, waiting for ' + CONFIG.CAM + '...');

                // Also send quality
                postData('index.php', {
                    action: 'write',
                    file: 'tmp/web_live_quality.tmp',
                    data: qualityString
                });

                // Wait for camera to start sending images
                setTimeout(function() {
                    // Remove loading indicator
                    const indicator = document.getElementById('loadingIndicator');
                    if (indicator) indicator.remove();

                    state.liveErrorCount = 0;

                    // Start fast updates after delay
                    updateLiveImage();
                    state.liveInterval = setInterval(updateLiveImage, CONFIG.LIVE_UPDATE_INTERVAL);

                    console.log('[' + CONFIG.CAM + '] 🟢 Live stream started');
                }, CONFIG.LIVE_START_DELAY);
            } else {
                // Remove loading indicator on error
                const indicator = document.getElementById('loadingIndicator');
                if (indicator) indicator.remove();

                console.error('[' + CONFIG.CAM + '] ❌ Server rejected: ' + response);
                stopLiveStream();
                if (DOM.liveSelect) DOM.liveSelect.value = 'off';
            }
        }).catch(function() {
            // Remove loading indicator on error
            const indicator = document.getElementById('loadingIndicator');
            if (indicator) indicator.remove();

            console.error('[' + CONFIG.CAM + '] ❌ Failed to start live stream');
            stopLiveStream();
            if (DOM.liveSelect) DOM.liveSelect.value = 'off';
        });
    }

    function stopLiveStream() {
        console.log('[' + CONFIG.CAM + '] 🔴 Stopping live stream...');

        // Always send off signal to server (even if already stopped by timeout)
        postData('index.php', {
            action: 'write',
            file: 'tmp/web_live.tmp',
            data: 'off'
        });

        state.isLiveActive = false;
        state.wasLiveBeforeOffline = false; // User explicitly stopped
        stopSessionHeartbeat();

        if (state.liveInterval) {
            clearInterval(state.liveInterval);
            state.liveInterval = null;
        }

        // Remove loading indicator if present
        const indicator = document.getElementById('loadingIndicator');
        if (indicator) indicator.remove();

        // Hide live container when stopping
        if (DOM.liveContainer) {
            DOM.liveContainer.style.display = 'none';
        }

        // Clear image to stop showing old frame
        if (DOM.liveImage) {
            DOM.liveImage.src = 'data:,';
        }

        state.liveErrorCount = 0;
        console.log('[' + CONFIG.CAM + '] 🔴 Live stream stopped');
    }

    // Silent stop - pause updates but keep container visible (for timeout recovery)
    function stopLiveStreamSilent() {
        console.log('[' + CONFIG.CAM + '] ⏸️ Live stream paused (timeout)');

        state.isLiveActive = false;
        stopSessionHeartbeat();

        if (state.liveInterval) {
            clearInterval(state.liveInterval);
            state.liveInterval = null;
        }

        // Remove loading indicator if present
        const indicator = document.getElementById('loadingIndicator');
        if (indicator) indicator.remove();

        // Keep container visible, show buffer image
        if (DOM.liveImage) {
            DOM.liveImage.src = 'buffer.jpg';
        }

        state.liveErrorCount = 0;
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

        // Pause live stream during capture (don't hide container)
        if (wasLiveActive) {
            stopLiveStreamSilent(); // Just pause, keep container visible
            postData('index.php', {
                action: 'write',
                file: 'tmp/web_live.tmp',
                data: 'off'
            });
        }

        DOM.captureButton.textContent = 'Capturing...';
        DOM.captureButton.disabled = true;
        DOM.captureButton.classList.add('blinking'); // Add blinking animation

        // Get form values for capture settings
        const getSelectValue = function(name) {
            const el = document.querySelector('select[name="' + name + '"]');
            return el ? el.value : '';
        };

        // Trigger capture with all camera settings
        const formData = {
            res: getSelectValue('res'),
            comp: getSelectValue('comp'),
            iso: getSelectValue('iso'),
            sat: getSelectValue('sat'),
            rot: getSelectValue('rot'),
            fx: getSelectValue('fx'),
            enf: getSelectValue('enf'),
            b1: 'inic',
            submit: 'submit'
        };

        postData('index.php', formData)
            .then(function(response) {
                if (response === 'BUSY') {
                    DOM.captureButton.textContent = originalText;
                    DOM.captureButton.disabled = false;
                    DOM.captureButton.classList.remove('blinking');
                    state.captureLock = false;
                    alert('Camera is busy. Please wait and try again.');
                    return;
                }
                console.log('[' + CONFIG.CAM + '] 📸 Capture triggered');
                pollForNewImage(beforeTime, wasLiveActive, originalText);
            })
            .catch(function() {
                DOM.captureButton.textContent = originalText;
                DOM.captureButton.disabled = false;
                DOM.captureButton.classList.remove('blinking');
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
                    // Create separate container for captured image (like Normal JS)
                    let imageContainer = document.getElementById('ImageContainer');
                    let imageDetails = document.getElementById('imageDetails');

                    if (!imageContainer) {
                        imageContainer = document.createElement('div');
                        imageContainer.id = 'ImageContainer';
                        imageContainer.className = 'glass-panel';

                        const capturedImg = document.createElement('img');
                        capturedImg.id = 'Image';
                        capturedImg.alt = 'Captured Image';
                        capturedImg.className = 'captured-image';
                        imageContainer.appendChild(capturedImg);

                        imageDetails = document.createElement('div');
                        imageDetails.id = 'imageDetails';
                        imageDetails.className = 'glass-panel image-details-panel';

                        const sizeText = document.createElement('span');
                        sizeText.id = 'imageSizeText';
                        sizeText.className = 'image-size-text';
                        imageDetails.appendChild(sizeText);

                        // Add save button
                        const saveBtn = document.createElement('button');
                        saveBtn.className = 'save-btn';
                        saveBtn.title = 'Save to device (S)';
                        saveBtn.style.marginLeft = '10px';
                        saveBtn.textContent = '💾';
                        saveBtn.onclick = function() {
                            if (typeof window.saveImageToDevice === 'function') {
                                window.saveImageToDevice();
                            }
                        };
                        imageDetails.appendChild(saveBtn);

                        // Insert after form panel
                        const formPanel = document.querySelector('form');
                        if (formPanel) {
                            const panel = formPanel.closest('.glass-panel');
                            if (panel) {
                                panel.insertAdjacentElement('afterend', imageContainer);
                                imageContainer.insertAdjacentElement('afterend', imageDetails);
                            }
                        }
                    }

                    // Update captured image
                    const capturedImg = document.getElementById('Image');
                    if (capturedImg) {
                        capturedImg.src = this.src;
                    }

                    const sizeText = document.getElementById('imageSizeText');
                    if (sizeText) {
                        sizeText.innerHTML = 'Image size: ' + size + ' <span class="capture-time">Time: ' + captureTime + 's</span>';
                    }

                    imageContainer.style.display = 'block';
                    imageDetails.style.display = 'block';
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
            DOM.captureButton.classList.remove('blinking'); // Remove blinking animation
        }
        state.captureLock = false;

        // Restore live stream if it was active
        if (wasLiveActive) {
            setTimeout(function() {
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

        // C = Capture
        if (e.keyCode === 67) {
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

        // S = Save to device
        if (e.keyCode === 83) {
            e.preventDefault();
            if (typeof window.saveImageToDevice === 'function') {
                window.saveImageToDevice();
                console.log(`[${CONFIG.CAM}] ⌨️ Save (S)`);
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
                    fetch('admin/reboot.php?token=' + window.ADMIN_TOKEN)
                        .then(function(r) { return r.text(); })
                        .then(function(text) {
                            alert('Reboot command sent: ' + text);
                            console.log('[' + CONFIG.CAM + '] 🔄 Reboot: ' + text);
                        })
                        .catch(function() {
                            alert('Failed to send reboot command');
                        });
                }
            };
        }

        if (shutdownBtn) {
            shutdownBtn.onclick = function() {
                if (confirm('Shutdown camera system?')) {
                    fetch('admin/shutdown.php?token=' + window.ADMIN_TOKEN)
                        .then(function(r) { return r.text(); })
                        .then(function(text) {
                            alert('Shutdown command sent: ' + text);
                            console.log('[' + CONFIG.CAM + '] ⏹️ Shutdown: ' + text);
                        })
                        .catch(function() {
                            alert('Failed to send shutdown command');
                        });
                }
            };
        }

        if (clearBtn) {
            clearBtn.onclick = function() {
                if (confirm('Clear temporary files?')) {
                    fetch('admin/clear.php?token=' + window.ADMIN_TOKEN)
                        .then(function(r) { return r.text(); })
                        .then(function(text) {
                            alert('Files cleared: ' + text);
                            console.log('[' + CONFIG.CAM + '] 🧹 Clear: ' + text);
                        })
                        .catch(function() {
                            alert('Failed to clear files');
                        });
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