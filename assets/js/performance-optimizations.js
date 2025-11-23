/**
 * Performance Optimizations for Camera Control
 *
 * Enhances existing camera-control.js with:
 * - Unified API calls (single request instead of multiple)
 * - RequestAnimationFrame for smooth updates
 * - Optimized DOM operations
 * - Better memory management
 *
 * @version 1.0.0
 * @author Net Storm
 */

'use strict';

(function(window, document) {
    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    const PERF_CONFIG = {
        USE_UNIFIED_API: true,           // Use single API endpoint
        USE_RAF_UPDATES: true,           // Use requestAnimationFrame
        BATCH_DOM_UPDATES: true,         // Batch DOM operations
        PRELOAD_IMAGES: true,            // Preload next image
        DEBOUNCE_RESIZE: 250,            // Resize debounce ms
    };

    // =========================================================================
    // UNIFIED API
    // =========================================================================

    /**
     * Fetch all status data in one request
     */
    async function fetchUnifiedStatus() {
        try {
            const response = await fetch('api/status.php?t=' + Date.now(), {
                method: 'GET',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return await response.json();
        } catch (error) {
            console.error('[Perf] API error:', error);
            return null;
        }
    }

    /**
     * Update UI with unified data
     */
    function updateFromUnifiedData(data) {
        if (!data) return;

        // Update global status
        window.cameraOnlineStatus = data.online;
        window.secondsSinceUpdate = data.secondsSince;

        // Update battery if available
        if (data.battery && window.updateBatteryDisplay) {
            window.updateBatteryDisplay(data.battery);
        }

        // Update network if available
        if (data.network && window.updateNetworkDisplay) {
            window.updateNetworkDisplay(data.network);
        }
    }

    // =========================================================================
    // RAF-BASED UPDATES
    // =========================================================================

    let pendingUpdates = [];
    let rafId = null;

    /**
     * Schedule DOM update for next animation frame
     */
    function scheduleUpdate(fn) {
        if (!PERF_CONFIG.USE_RAF_UPDATES) {
            fn();
            return;
        }

        pendingUpdates.push(fn);

        if (!rafId) {
            rafId = requestAnimationFrame(() => {
                const updates = pendingUpdates;
                pendingUpdates = [];
                rafId = null;

                // Execute all pending updates
                updates.forEach(update => {
                    try {
                        update();
                    } catch (e) {
                        console.error('[Perf] Update error:', e);
                    }
                });
            });
        }
    }

    // =========================================================================
    // DOM OPTIMIZATIONS
    // =========================================================================

    // Cache frequently accessed elements
    const elementCache = new Map();

    /**
     * Get cached element
     */
    function getElement(id) {
        if (!elementCache.has(id)) {
            elementCache.set(id, document.getElementById(id));
        }
        return elementCache.get(id);
    }

    /**
     * Clear element cache (call on dynamic content updates)
     */
    function clearElementCache() {
        elementCache.clear();
    }

    /**
     * Batch multiple DOM updates
     */
    function batchUpdate(updates) {
        if (!PERF_CONFIG.BATCH_DOM_UPDATES) {
            updates.forEach(([el, prop, value]) => {
                if (el) el[prop] = value;
            });
            return;
        }

        // Use DocumentFragment for better performance
        scheduleUpdate(() => {
            updates.forEach(([el, prop, value]) => {
                if (el) el[prop] = value;
            });
        });
    }

    // =========================================================================
    // IMAGE PRELOADING
    // =========================================================================

    const imagePreloader = {
        cache: new Map(),
        maxCache: 3,

        preload(url) {
            if (!PERF_CONFIG.PRELOAD_IMAGES) return;

            const img = new Image();
            img.src = url;

            this.cache.set(url, img);

            // Cleanup old entries
            if (this.cache.size > this.maxCache) {
                const firstKey = this.cache.keys().next().value;
                this.cache.delete(firstKey);
            }
        },

        get(url) {
            return this.cache.get(url);
        }
    };

    // =========================================================================
    // DEBOUNCE & THROTTLE
    // =========================================================================

    function debounce(fn, delay) {
        let timeoutId;
        return function(...args) {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function throttle(fn, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                fn.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }

    // =========================================================================
    // INTERSECTION OBSERVER (Lazy Loading)
    // =========================================================================

    let observer = null;

    function setupLazyLoading() {
        if (!('IntersectionObserver' in window)) return;

        observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                        observer.unobserve(img);
                    }
                }
            });
        }, {
            rootMargin: '50px'
        });
    }

    // =========================================================================
    // MEMORY MANAGEMENT
    // =========================================================================

    /**
     * Force garbage collection hint
     */
    function cleanupMemory() {
        // Clear old image objects
        imagePreloader.cache.clear();

        // Clear element cache
        elementCache.clear();

        // Hint to GC (not guaranteed)
        if (window.gc) {
            window.gc();
        }
    }

    // Cleanup every 60 seconds
    setInterval(cleanupMemory, 60000);

    // =========================================================================
    // PERFORMANCE MONITORING
    // =========================================================================

    const perfMonitor = {
        marks: new Map(),

        start(label) {
            this.marks.set(label, performance.now());
        },

        end(label) {
            const start = this.marks.get(label);
            if (start) {
                const duration = performance.now() - start;
                this.marks.delete(label);

                if (duration > 100) {
                    console.warn(`[Perf] Slow operation: ${label} took ${duration.toFixed(2)}ms`);
                }

                return duration;
            }
            return 0;
        }
    };

    // =========================================================================
    // OVERRIDE MODE.PHP LOADING
    // =========================================================================

    // Store original function if exists
    const originalLoadCameraStatus = window.loadCameraStatus;

    if (PERF_CONFIG.USE_UNIFIED_API) {
        // Override with optimized version
        window.loadCameraStatus = async function() {
            perfMonitor.start('statusUpdate');

            const data = await fetchUnifiedStatus();

            if (data) {
                // Update from unified API
                updateFromUnifiedData(data);

                // Still load mode.php for HTML (but less critical now)
                fetch('mode.php?t=' + Date.now())
                    .then(r => r.text())
                    .then(html => {
                        const container = getElement('id1');
                        if (container) {
                            scheduleUpdate(() => {
                                container.innerHTML = html;
                                clearElementCache();

                                // Execute inline scripts
                                const scripts = container.getElementsByTagName('script');
                                for (const script of Array.from(scripts)) {
                                    const newScript = document.createElement('script');
                                    newScript.text = script.text;
                                    document.head.appendChild(newScript);
                                    newScript.remove();
                                }
                            });
                        }
                    })
                    .catch(() => {});
            } else if (originalLoadCameraStatus) {
                // Fallback to original
                originalLoadCameraStatus();
            }

            perfMonitor.end('statusUpdate');
        };
    }

    // =========================================================================
    // RESIZE HANDLER (Debounced)
    // =========================================================================

    window.addEventListener('resize', debounce(() => {
        // Handle any resize-dependent operations
        clearElementCache();
    }, PERF_CONFIG.DEBOUNCE_RESIZE));

    // =========================================================================
    // VISIBILITY CHANGE (Reduce work when hidden)
    // =========================================================================

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            // Page hidden - reduce activity
            console.log('[Perf] Page hidden - reducing activity');
        } else {
            // Page visible - resume
            console.log('[Perf] Page visible - resuming');
            clearElementCache();
        }
    });

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    window.PerfOptimizations = {
        fetchUnifiedStatus,
        scheduleUpdate,
        batchUpdate,
        getElement,
        clearElementCache,
        imagePreloader,
        debounce,
        throttle,
        perfMonitor,
        cleanupMemory,

        // Config
        isEnabled: () => PERF_CONFIG.USE_UNIFIED_API
    };

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    document.addEventListener('DOMContentLoaded', () => {
        setupLazyLoading();
        console.log('[Perf] Performance optimizations loaded');
    });

})(window, document);
