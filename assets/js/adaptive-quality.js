/**
 * Adaptive Quality Controller for Live Stream
 *
 * Automatically adjusts video quality based on network speed.
 * Works with camera-control.js without modifying it.
 *
 * @version 1.0.0
 * @author Net Storm
 */

'use strict';

(function(window) {
    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    const CONFIG = {
        // Quality levels in order (lowest to highest)
        QUALITY_LEVELS: ['very-low', 'low', 'medium', 'high'],

        // Thresholds (milliseconds)
        FAST_THRESHOLD: 400,     // Below this = can increase quality
        SLOW_THRESHOLD: 1200,    // Above this = should decrease quality

        // Timing
        SAMPLES_BEFORE_ADJUST: 5,  // Check after N frames
        MIN_ADJUST_INTERVAL: 5000, // Don't adjust more often than 5s

        // Quality presets [width, height, quality]
        PRESETS: {
            'very-low': [480, 360, 8],
            'low': [640, 480, 16],
            'medium': [800, 600, 24],
            'high': [1024, 768, 32]
        }
    };

    // =========================================================================
    // STATE
    // =========================================================================

    const state = {
        enabled: false,
        currentLevel: 0,           // Index in QUALITY_LEVELS
        loadTimes: [],             // Recent frame load times
        lastAdjustTime: 0,
        frameCount: 0
    };

    // =========================================================================
    // ADAPTIVE LOGIC
    // =========================================================================

    /**
     * Record frame load time
     */
    function recordLoadTime(ms) {
        if (!state.enabled) return;

        state.loadTimes.push(ms);
        state.frameCount++;

        // Keep only recent samples
        if (state.loadTimes.length > CONFIG.SAMPLES_BEFORE_ADJUST) {
            state.loadTimes.shift();
        }

        // Check if should adjust
        if (state.loadTimes.length >= CONFIG.SAMPLES_BEFORE_ADJUST) {
            checkAndAdjust();
        }
    }

    /**
     * Calculate average load time
     */
    function getAverageLoadTime() {
        if (state.loadTimes.length === 0) return 0;

        const sum = state.loadTimes.reduce((a, b) => a + b, 0);
        return sum / state.loadTimes.length;
    }

    /**
     * Check and adjust quality if needed
     */
    function checkAndAdjust() {
        const now = Date.now();

        // Don't adjust too frequently
        if (now - state.lastAdjustTime < CONFIG.MIN_ADJUST_INTERVAL) {
            return;
        }

        const avgTime = getAverageLoadTime();
        const currentQuality = CONFIG.QUALITY_LEVELS[state.currentLevel];

        // Decide action
        if (avgTime < CONFIG.FAST_THRESHOLD) {
            // Fast connection - try to increase quality
            if (state.currentLevel < CONFIG.QUALITY_LEVELS.length - 1) {
                state.currentLevel++;
                applyQuality();
                state.lastAdjustTime = now;
                state.loadTimes = [];  // Reset samples

                console.log(`[Adaptive] Increased to ${CONFIG.QUALITY_LEVELS[state.currentLevel]} (avg: ${avgTime.toFixed(0)}ms)`);
            }
        } else if (avgTime > CONFIG.SLOW_THRESHOLD) {
            // Slow connection - decrease quality
            if (state.currentLevel > 0) {
                state.currentLevel--;
                applyQuality();
                state.lastAdjustTime = now;
                state.loadTimes = [];  // Reset samples

                console.log(`[Adaptive] Decreased to ${CONFIG.QUALITY_LEVELS[state.currentLevel]} (avg: ${avgTime.toFixed(0)}ms)`);
            }
        }
    }

    /**
     * Apply current quality level
     */
    function applyQuality() {
        const quality = CONFIG.QUALITY_LEVELS[state.currentLevel];
        const preset = CONFIG.PRESETS[quality];
        const data = `${preset[0]} ${preset[1]} ${preset[2]}`;

        // Write to server
        if (typeof jQuery !== 'undefined') {
            $.post('index.php', {
                action: 'write',
                file: 'tmp/web_live_quality.tmp',
                data: data
            });
        } else {
            fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'write',
                    file: 'tmp/web_live_quality.tmp',
                    data: data
                })
            });
        }

        // Update UI (don't trigger onchange)
        const select = document.getElementById('liveQuality');
        if (select && select.value === 'adaptive') {
            // Show current level in console
            console.log(`[Adaptive] Quality: ${quality} (${preset[0]}x${preset[1]})`);
        }
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    window.AdaptiveQuality = {
        /**
         * Enable adaptive quality
         */
        enable: function() {
            state.enabled = true;
            state.currentLevel = 0;  // Start with lowest
            state.loadTimes = [];
            state.frameCount = 0;
            state.lastAdjustTime = Date.now();

            // Apply initial quality
            applyQuality();

            console.log('[Adaptive] Enabled - starting at very-low');
        },

        /**
         * Disable adaptive quality
         */
        disable: function() {
            state.enabled = false;
            console.log('[Adaptive] Disabled');
        },

        /**
         * Check if enabled
         */
        isEnabled: function() {
            return state.enabled;
        },

        /**
         * Record frame load time (call from live stream code)
         */
        recordLoadTime: recordLoadTime,

        /**
         * Get current stats
         */
        getStats: function() {
            return {
                enabled: state.enabled,
                currentQuality: CONFIG.QUALITY_LEVELS[state.currentLevel],
                averageLoadTime: getAverageLoadTime(),
                frameCount: state.frameCount
            };
        },

        /**
         * Reset to lowest quality
         */
        reset: function() {
            state.currentLevel = 0;
            state.loadTimes = [];
            if (state.enabled) {
                applyQuality();
            }
        }
    };

    // =========================================================================
    // INTEGRATION WITH CAMERA-CONTROL.JS
    // =========================================================================

    // Override updateLiveQuality if exists
    const originalUpdateLiveQuality = window.updateLiveQuality;

    window.updateLiveQuality = function(forceUpdate) {
        const select = document.getElementById('liveQuality');
        if (!select) return;

        const quality = select.value;

        if (quality === 'adaptive') {
            // Enable adaptive mode
            window.AdaptiveQuality.enable();
        } else {
            // Manual mode - disable adaptive
            window.AdaptiveQuality.disable();

            // Call original function
            if (originalUpdateLiveQuality) {
                originalUpdateLiveQuality(forceUpdate);
            } else {
                // Fallback: write quality directly
                const preset = CONFIG.PRESETS[quality] || CONFIG.PRESETS['very-low'];
                const data = `${preset[0]} ${preset[1]} ${preset[2]}`;

                if (typeof jQuery !== 'undefined') {
                    $.post('index.php', {
                        action: 'write',
                        file: 'tmp/web_live_quality.tmp',
                        data: data
                    });
                } else {
                    fetch('index.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'write',
                            file: 'tmp/web_live_quality.tmp',
                            data: data
                        })
                    });
                }
            }
        }

        // Save preference
        localStorage.setItem('preferredQuality', quality);
    };

    // =========================================================================
    // MONKEY-PATCH LIVE IMAGE LOADING
    // =========================================================================

    // Hook into image loading to measure time
    document.addEventListener('DOMContentLoaded', function() {
        const liveImage = document.getElementById('webLiveImage');
        if (!liveImage) return;

        let loadStartTime = 0;

        // Create a MutationObserver to detect src changes
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'src') {
                    loadStartTime = Date.now();
                }
            });
        });

        observer.observe(liveImage, { attributes: true });

        // Listen for load event
        liveImage.addEventListener('load', function() {
            if (loadStartTime > 0) {
                const loadTime = Date.now() - loadStartTime;
                window.AdaptiveQuality.recordLoadTime(loadTime);
                loadStartTime = 0;
            }
        });
    });

})(window);
