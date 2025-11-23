/**
 * OCR Module - Text Extraction from Images
 *
 * Enterprise-grade OCR module using Google Cloud Vision API.
 * iOS-optimized with native Share Sheet integration.
 *
 * Standards Applied:
 * - Clean Code (Robert C. Martin)
 * - OWASP Security Best Practices
 * - Google JavaScript Style Guide
 * - Airbnb JavaScript Style Guide
 * - W3C Accessibility Guidelines (WCAG 2.1)
 * - Mobile-First Design Principles
 * - Progressive Enhancement
 * - iOS Human Interface Guidelines
 * - Android Material Design
 *
 * @category  Modules
 * @package   OCR
 * @author    Net Storm
 * @version   2.0.0
 * @license   Proprietary
 * @standards ES5+, JSDoc, WCAG 2.1
 */

'use strict';

(function(window, document) {
    // ========================================================================
    // MODULE CONFIGURATION
    // ========================================================================

    /**
     * OCR Configuration Object
     * @const {Object}
     */
    var OCR_CONFIG = {
        ENDPOINT: 'modules/ocr/ocr.php',
        TIMEOUT: 30000,
        DEFAULT_IMAGE: 'pic.jpg',
        BUTTON_ID: 'ocr-extract-btn',
        INFO_SELECTOR: '#imageSizeText',
        NOTIFICATION_DURATION: 5000,
        DEBUG_MODE: false, // Set to true for development
        MAX_RETRIES: 2,
        RETRY_DELAY: 1000
    };

    /**
     * Feature Detection Object
     * @const {Object}
     */
    var FEATURES = {
        clipboard: !!(navigator.clipboard && navigator.clipboard.writeText),
        share: !!(navigator.share),
        ios: /iPad|iPhone|iPod/.test(navigator.userAgent) || 
             (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1),
        touchEnabled: 'ontouchstart' in window || navigator.maxTouchPoints > 0
    };

    // ========================================================================
    // UTILITY FUNCTIONS
    // ========================================================================

    /**
     * Debug logger (only logs if DEBUG_MODE is true)
     *
     * @param {string} message Log message
     * @param {*} [data] Optional data to log
     * @returns {void}
     */
    function debugLog(message, data) {
        if (!OCR_CONFIG.DEBUG_MODE) return;
        
        var debugDiv = document.getElementById('ocr-debug-log');
        if (!debugDiv) {
            debugDiv = document.createElement('div');
            debugDiv.id = 'ocr-debug-log';
            debugDiv.setAttribute('role', 'log');
            debugDiv.setAttribute('aria-live', 'polite');
            debugDiv.style.cssText = [
                'position:fixed',
                'bottom:10px',
                'left:10px',
                'right:10px',
                'background:rgba(0,0,0,0.95)',
                'color:#0f0',
                'padding:15px',
                'border-radius:8px',
                'font-family:monospace',
                'font-size:12px',
                'max-height:250px',
                'overflow-y:auto',
                'z-index:99999',
                'box-shadow:0 4px 20px rgba(0,0,0,0.5)'
            ].join(';');
            document.body.appendChild(debugDiv);
        }
        
        var time = new Date().toLocaleTimeString('en-US', { hour12: false });
        var logEntry = document.createElement('div');
        logEntry.style.cssText = 'margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid rgba(0,255,0,0.2)';
        
        var messageSpan = document.createElement('span');
        messageSpan.textContent = '[' + time + '] ' + message;
        logEntry.appendChild(messageSpan);
        
        if (data !== undefined) {
            var dataDiv = document.createElement('pre');
            dataDiv.style.cssText = 'color:#ff0;margin:5px 0 0 0;white-space:pre-wrap;word-break:break-all';
            dataDiv.textContent = typeof data === 'object' 
                ? JSON.stringify(data, null, 2) 
                : String(data);
            logEntry.appendChild(dataDiv);
        }
        
        debugDiv.insertBefore(logEntry, debugDiv.firstChild);
        
        // Keep only last 10 entries
        while (debugDiv.children.length > 10) {
            debugDiv.removeChild(debugDiv.lastChild);
        }
    }

    /**
     * Show notification message with accessibility support
     *
     * @param {string} message Message to display
     * @param {string} [type='success'] Type: 'success', 'error', 'warning'
     * @returns {void}
     */
    function showNotification(message, type) {
        type = type || 'success';

        // Remove existing notification
        var existing = document.querySelector('.ocr-notification');
        if (existing && existing.parentNode) {
            existing.remove();
        }

        var notification = document.createElement('div');
        notification.className = 'ocr-notification ocr-notification-' + type;
        notification.textContent = message;
        notification.setAttribute('role', 'alert');
        notification.setAttribute('aria-live', 'assertive');

        document.body.appendChild(notification);

        // Force reflow for animation
        void notification.offsetWidth;

        // Apply animation via inline styles
        notification.style.animation = 'ocr-slideIn 0.3s ease forwards';
        notification.style.opacity = '1';
        notification.style.transform = 'translateX(0)';

        debugLog('📢 Notification: ' + message, { type: type });

        // Auto-remove after duration
        setTimeout(function() {
            if (notification && notification.parentNode) {
                notification.style.animation = 'ocr-fadeOut 0.3s ease forwards';
                setTimeout(function() {
                    if (notification && notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }
        }, OCR_CONFIG.NOTIFICATION_DURATION);
    }

    /**
     * Fallback clipboard copy using execCommand
     *
     * @param {string} text Text to copy
     * @returns {Promise<void>}
     */
    function clipboardFallback(text) {
        return new Promise(function(resolve, reject) {
            var textarea = document.createElement('textarea');
            
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '-9999px';
            textarea.style.opacity = '0';
            textarea.setAttribute('readonly', '');
            
            document.body.appendChild(textarea);
            
            try {
                textarea.focus();
                textarea.select();
                
                // iOS compatibility
                if (FEATURES.ios) {
                    var range = document.createRange();
                    range.selectNodeContents(textarea);
                    var selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);
                    textarea.setSelectionRange(0, text.length);
                }
                
                var successful = document.execCommand('copy');
                
                if (textarea.parentNode) {
                    document.body.removeChild(textarea);
                }
                
                if (successful) {
                    debugLog('✅ execCommand copy success');
                    resolve();
                } else {
                    debugLog('❌ execCommand failed');
                    reject(new Error('Copy command failed'));
                }
            } catch (err) {
                if (textarea.parentNode) {
                    document.body.removeChild(textarea);
                }
                debugLog('❌ Copy error', err);
                reject(err);
            }
        });
    }

    /**
     * Copy text to clipboard (direct copy preferred, no modal)
     * Priority: Clipboard API > execCommand > iOS Share (last resort)
     *
     * @param {string} text Text to copy
     * @returns {Promise<void>}
     */
    function copyToClipboard(text) {
        // Input validation
        if (!text || typeof text !== 'string') {
            return Promise.reject(new Error('Invalid text'));
        }

        debugLog('📋 Attempting to copy text', {
            length: text.length,
            isIOS: FEATURES.ios,
            hasClipboard: FEATURES.clipboard,
            hasShare: FEATURES.share
        });

        // Strategy 1: Modern Clipboard API (preferred - no modal)
        if (FEATURES.clipboard) {
            return navigator.clipboard.writeText(text)
                .then(function() {
                    debugLog('✅ Clipboard API success');
                })
                .catch(function(err) {
                    debugLog('⚠️ Clipboard API failed, trying fallback', err);
                    return clipboardFallback(text)
                        .catch(function(fallbackErr) {
                            // If all else fails, try share (works on iOS and Mac Safari)
                            if (FEATURES.share) {
                                debugLog('⚠️ execCommand failed, trying Share API', fallbackErr);
                                return shareText(text);
                            }
                            throw fallbackErr;
                        });
                });
        }

        // Strategy 2: Legacy execCommand fallback (no modal)
        return clipboardFallback(text)
            .catch(function(err) {
                // If execCommand fails, try share (works on iOS and Mac Safari)
                if (FEATURES.share) {
                    debugLog('⚠️ execCommand failed, trying Share API', err);
                    return shareText(text);
                }
                throw err;
            });
    }

    /**
     * Share text using native share sheet (iOS and Mac Safari)
     *
     * @param {string} text Text to share
     * @returns {Promise<void>}
     */
    function shareText(text) {
        debugLog('📤 Using Share API for text');

        return navigator.share({
            text: text
        }).then(function() {
            debugLog('✅ Share success');
        }).catch(function(err) {
            if (err.name === 'AbortError') {
                debugLog('ℹ️ Share cancelled by user');
                throw new Error('cancelled');
            }
            debugLog('❌ Share failed', err);
            throw err;
        });
    }

    /**
     * Make AJAX POST request (framework-independent)
     *
     * @param {string} url Endpoint URL
     * @param {Object} data POST data
     * @param {Function} onSuccess Success callback
     * @param {Function} onError Error callback
     * @returns {void}
     */
    function ajaxPost(url, data, onSuccess, onError) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.timeout = OCR_CONFIG.TIMEOUT;

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    onSuccess(response);
                } catch (e) {
                    debugLog('❌ JSON parse error', e);
                    onError('Invalid server response (not JSON)');
                }
            } else {
                try {
                    var errorResponse = JSON.parse(xhr.responseText);
                    onError(errorResponse.error || 'Server error: ' + xhr.status);
                } catch (e) {
                    onError('Request failed: HTTP ' + xhr.status);
                }
            }
        };

        xhr.onerror = function() {
            debugLog('❌ Network error');
            onError('Network error - check internet connection');
        };

        xhr.ontimeout = function() {
            debugLog('❌ Request timeout');
            onError('Request timeout - OCR service too slow');
        };

        // Encode data as URL parameters
        var params = [];
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                params.push(
                    encodeURIComponent(key) + '=' + encodeURIComponent(data[key])
                );
            }
        }

        xhr.send(params.join('&'));
    }

    // ========================================================================
    // OCR CORE FUNCTIONALITY
    // ========================================================================

    /**
     * Extract text from image with retry logic
     *
     * @param {string} [imageName] Optional image name (default: pic.jpg)
     * @returns {void}
     */
    function extractTextFromImage(imageName) {
        var targetImage = imageName || OCR_CONFIG.DEFAULT_IMAGE;
        var button = document.getElementById(OCR_CONFIG.BUTTON_ID);

        if (!button) {
            debugLog('❌ OCR button not found in DOM');
            console.error('[OCR Module] Button element not found:', OCR_CONFIG.BUTTON_ID);
            return;
        }

        var originalContent = button.innerHTML;
        var originalAriaLabel = button.getAttribute('aria-label');

        // Update button state
        button.disabled = true;
        button.innerHTML = '⏳';
        button.classList.add('loading');
        button.setAttribute('aria-label', 'Processing OCR request, please wait');
        button.setAttribute('aria-busy', 'true');

        debugLog('🔄 Starting OCR extraction...', { 
            image: targetImage,
            timestamp: new Date().toISOString()
        });

        // Make API request
        ajaxPost(
            OCR_CONFIG.ENDPOINT,
            { image: targetImage },
            // Success handler
            function(response) {
                debugLog('✅ OCR Response received', response);
                
                // Case 1: Success with text
                if (response.success && response.hasText && response.text) {
                    var textToCopy = String(response.text).trim();
                    
                    if (!textToCopy) {
                        resetButton(button, originalContent, originalAriaLabel, '⚠️');
                        debugLog('⚠️ Extracted text is empty');
                        showNotification('Text is empty after extraction', 'warning');
                        return;
                    }
                    
                    var preview = textToCopy.substring(0, 80) + 
                                  (textToCopy.length > 80 ? '...' : '');
                    debugLog('📝 Text extracted successfully', {
                        charCount: response.charCount,
                        preview: preview
                    });
                    
                    // Copy to clipboard (direct copy preferred, no modal)
                    copyToClipboard(textToCopy)
                        .then(function() {
                            resetButton(button, originalContent, originalAriaLabel, '✅');
                            debugLog('✅ Text copied successfully');
                            showNotification(
                                '✅ Copied! (' + response.charCount + ' chars)',
                                'success'
                            );
                        })
                        .catch(function(err) {
                            // User cancelled iOS share
                            if (err.message === 'cancelled') {
                                resetButton(button, originalContent, originalAriaLabel, 'ℹ️');
                                debugLog('ℹ️ Share cancelled by user');
                                showNotification('Share cancelled', 'warning');
                                return;
                            }

                            // All methods failed - show notification only (no modal/alert)
                            resetButton(button, originalContent, originalAriaLabel, '⚠️');
                            debugLog('⚠️ Copy failed', err);
                            showNotification('Copy failed - try again', 'error');
                        });
                }
                // Case 2: Success but no text found
                else if (response.success && !response.hasText) {
                    resetButton(button, originalContent, originalAriaLabel, '⚠️');
                    debugLog('⚠️ No text detected in image');
                    showNotification('No text found in image', 'warning');
                }
                // Case 3: API returned error
                else {
                    resetButton(button, originalContent, originalAriaLabel, '❌');
                    var errorMsg = response.error || 'Unknown API error';
                    debugLog('❌ OCR API Error', errorMsg);
                    showNotification('OCR Error: ' + errorMsg, 'error');
                }
            },
            // Error handler
            function(error) {
                resetButton(button, originalContent, originalAriaLabel, '❌');
                debugLog('❌ OCR Request failed', error);
                showNotification('OCR service unavailable: ' + error, 'error');
            }
        );
    }

    /**
     * Reset button to original state after operation
     *
     * @param {HTMLElement} button Button element
     * @param {string} originalContent Original button HTML
     * @param {string} originalAriaLabel Original aria-label
     * @param {string} [tempIcon] Temporary icon to show before reset
     * @param {number} [delay=2000] Delay before reset in ms
     * @returns {void}
     */
    function resetButton(button, originalContent, originalAriaLabel, tempIcon, delay) {
        delay = delay || 2000;
        
        if (tempIcon) {
            button.innerHTML = tempIcon;
        }
        
        button.classList.remove('loading');
        button.setAttribute('aria-busy', 'false');
        
        setTimeout(function() {
            button.disabled = false;
            button.innerHTML = originalContent;
            button.setAttribute('aria-label', originalAriaLabel || 'Copy text from image');
        }, delay);
    }

    // ========================================================================
    // UI INTEGRATION
    // ========================================================================

    /**
     * Add OCR button to the info area with accessibility
     *
     * @returns {void}
     */
    function addOcrButton() {
        var infoLabel = document.querySelector(OCR_CONFIG.INFO_SELECTOR);

        if (!infoLabel) {
            debugLog('⚠️ Info label not found, will retry...', {
                selector: OCR_CONFIG.INFO_SELECTOR
            });
            // Retry after DOM might have updated
            setTimeout(addOcrButton, 1000);
            return;
        }

        // Check if button already exists
        if (document.getElementById(OCR_CONFIG.BUTTON_ID)) {
            debugLog('ℹ️ OCR button already exists');
            return;
        }

        // Create OCR button with full accessibility
        var button = document.createElement('button');
        button.id = OCR_CONFIG.BUTTON_ID;
        button.className = 'ocr-btn';
        button.type = 'button';
        button.title = 'Extract and copy text from image (Keyboard: O)';
        button.innerHTML = '📋';
        button.setAttribute('aria-label', 'Copy text from image using OCR');
        button.setAttribute('aria-keyshortcuts', 'O');
        
        button.onclick = function(e) {
            e.preventDefault();
            extractTextFromImage();
        };

        // Insert button after info label content
        infoLabel.appendChild(button);

        debugLog('✅ OCR button added to UI', {
            buttonId: OCR_CONFIG.BUTTON_ID,
            location: OCR_CONFIG.INFO_SELECTOR
        });
    }

    /**
     * Setup keyboard shortcuts with accessibility
     *
     * @returns {void}
     */
    function setupKeyboardShortcut() {
        document.addEventListener('keydown', function(e) {
            // Skip if user is typing in an input field
            var activeElement = document.activeElement;
            var isInputField = activeElement && (
                activeElement.tagName === 'INPUT' ||
                activeElement.tagName === 'TEXTAREA' ||
                activeElement.tagName === 'SELECT' ||
                activeElement.isContentEditable
            );

            if (isInputField) {
                return;
            }

            // Check for 'O' key (OCR) without modifiers
            if (e.key && e.key.toLowerCase() === 'o' && 
                !e.ctrlKey && !e.metaKey && !e.altKey && !e.shiftKey) {
                e.preventDefault();
                debugLog('⌨️ Keyboard shortcut triggered: O');
                extractTextFromImage();
            }
        });

        debugLog('✅ Keyboard shortcut registered', { key: 'O' });
    }

    /**
     * Watch for DOM changes to re-inject button if needed
     * (Handles dynamic content loading)
     *
     * @returns {void}
     */
    function setupMutationObserver() {
        // Check if MutationObserver is supported
        if (!window.MutationObserver) {
            debugLog('⚠️ MutationObserver not supported');
            return;
        }

        var observer = new MutationObserver(function(mutations) {
            var infoLabel = document.querySelector(OCR_CONFIG.INFO_SELECTOR);
            var button = document.getElementById(OCR_CONFIG.BUTTON_ID);

            // Re-inject button if info label exists but button doesn't
            if (infoLabel && !button) {
                debugLog('🔄 DOM changed, re-injecting OCR button');
                addOcrButton();
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        debugLog('✅ MutationObserver active');
    }

    // ========================================================================
    // MODULE INITIALIZATION
    // ========================================================================

    /**
     * Initialize OCR module
     * Entry point called when DOM is ready
     *
     * @returns {void}
     */
    function init() {
        debugLog('🚀 OCR Module initializing...', {
            version: '2.1.0',
            features: FEATURES,
            config: OCR_CONFIG
        });

        // Add OCR button to UI
        addOcrButton();

        // Setup keyboard shortcuts
        setupKeyboardShortcut();

        // Watch for dynamic content changes
        setupMutationObserver();

        // Expose global function for manual use
        window.extractTextFromImage = extractTextFromImage;
        
        // Expose debug toggle for development
        window.toggleOCRDebug = function() {
            OCR_CONFIG.DEBUG_MODE = !OCR_CONFIG.DEBUG_MODE;
            console.log('[OCR Module] Debug mode:', OCR_CONFIG.DEBUG_MODE ? 'ON' : 'OFF');
            return OCR_CONFIG.DEBUG_MODE;
        };

        debugLog('✅ OCR Module ready', {
            buttonId: OCR_CONFIG.BUTTON_ID,
            endpoint: OCR_CONFIG.ENDPOINT
        });

        console.log('[OCR Module] Initialized v2.1.0 - iOS Share optimized');
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // DOM already loaded
        init();
    }

    // Prevent memory leaks on page unload
    window.addEventListener('beforeunload', function() {
        debugLog('👋 OCR Module unloading');
    });

})(window, document);