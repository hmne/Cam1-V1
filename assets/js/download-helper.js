/**
 * Download Helper - Real iOS Device Optimized
 *
 * Different approaches for different scenarios:
 * - Real iOS devices: Aggressive download attempt
 * - Desktop Safari: Standard download
 *
 * @author    Net Storm
 * @version   1.0.0
 * @license   Proprietary
 */

'use strict';

(function(window, document) {
    // ========================================================================
    // CONFIGURATION
    // ========================================================================

    var DOWNLOAD_CONFIG = {
        DEFAULT_CAMERA_NAME: 'Camera',
        IMAGE_URL: 'pic.jpg',
        FILE_EXTENSION: '.jpg',
        NOTIFICATION_DURATION: 3000
    };

    /**
     * Show download notification
     */
    function showDownloadNotification(message, type) {
        type = type || 'success';

        var existing = document.querySelector('.download-notification');
        if (existing) existing.remove();

        var notification = document.createElement('div');
        notification.className = 'download-notification download-notification-' + type;
        notification.textContent = message;
        notification.style.cssText = [
            'position:fixed',
            'bottom:20px',
            'left:50%',
            'transform:translateX(-50%)',
            'background:' + (type === 'success' ? '#27ae60' : '#e74c3c'),
            'color:#fff',
            'padding:12px 24px',
            'border-radius:8px',
            'font-size:14px',
            'z-index:99999',
            'box-shadow:0 4px 12px rgba(0,0,0,0.3)',
            'animation:fadeInUp 0.3s ease'
        ].join(';');

        document.body.appendChild(notification);

        setTimeout(function() {
            if (notification && notification.parentNode) {
                notification.style.opacity = '0';
                setTimeout(function() {
                    if (notification.parentNode) notification.remove();
                }, 300);
            }
        }, DOWNLOAD_CONFIG.NOTIFICATION_DURATION);
    }

    // ========================================================================
    // DEVICE DETECTION
    // ========================================================================

    /**
     * Detect if running on REAL iOS device (not Mac)
     */
    function isRealIOSDevice() {
        var ua = navigator.userAgent;
        // Check for iPad/iPhone/iPod but NOT Macintosh
        return (/iPad|iPhone|iPod/.test(ua) && !window.MSStream) && 
               !(/Macintosh/.test(ua));
    }

    function isMacSafari() {
        var ua = navigator.userAgent;
        return /Macintosh/.test(ua) && /Safari/.test(ua);
    }

    // ========================================================================
    // UTILITY FUNCTIONS
    // ========================================================================

    function generateCacheBuster() {
        return Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    function generateFilename(baseName) {
        var cameraName = baseName || window.CAMERA_NAME || DOWNLOAD_CONFIG.DEFAULT_CAMERA_NAME;
        cameraName = cameraName.replace(/[^a-zA-Z0-9-]/g, '_');
        
        var now = new Date();
        var timestamp = [
            now.getFullYear(),
            String(now.getMonth() + 1).padStart(2, '0'),
            String(now.getDate()).padStart(2, '0'),
            '_',
            String(now.getHours()).padStart(2, '0'),
            String(now.getMinutes()).padStart(2, '0'),
            String(now.getSeconds()).padStart(2, '0')
        ].join('');
        
        return cameraName + '_' + timestamp + DOWNLOAD_CONFIG.FILE_EXTENSION;
    }

    // ========================================================================
    // METHOD 1: iOS SHARE (Best for iOS - Opens Share Sheet)
    // ========================================================================

    /**
     * Use Web Share API for iOS - opens native share sheet
     * User can then choose "Save Image" from the options
     */
    function downloadIOSShare(imageUrl, filename) {
        console.log('[Download] iOS Share method...');

        fetch(imageUrl + '?v=' + generateCacheBuster())
            .then(function(response) {
                if (!response.ok) throw new Error('Network error');
                return response.blob();
            })
            .then(function(blob) {
                // Check if Web Share API with files is supported
                if (navigator.canShare && navigator.share) {
                    var file = new File([blob], filename, { type: 'image/jpeg' });

                    // Check if we can share this file
                    if (navigator.canShare({ files: [file] })) {
                        console.log('[Download] Using Web Share API with file');

                        return navigator.share({
                            files: [file],
                            title: filename
                        }).then(function() {
                            console.log('[Download] ✅ Shared successfully');
                            showDownloadNotification('✅ Choose "Save Image" to save');
                        }).catch(function(err) {
                            if (err.name === 'AbortError') {
                                console.log('[Download] Share cancelled');
                                showDownloadNotification('Cancelled', 'error');
                            } else {
                                throw err;
                            }
                        });
                    }
                }

                // Fallback: Open in new tab with instructions
                console.log('[Download] Share API not available, opening in new tab');
                var url = URL.createObjectURL(blob);
                window.open(url, '_blank');
                showDownloadNotification('Long-press image → Save to Photos');

                setTimeout(function() {
                    URL.revokeObjectURL(url);
                }, 5000);
            })
            .catch(function(error) {
                console.error('[Download] Error:', error);
                showDownloadNotification('Download failed', 'error');
            });
    }

    // ========================================================================
    // METHOD 2: STANDARD DOWNLOAD (Desktop/Android)
    // ========================================================================

    function downloadStandard(imageUrl, filename) {
        console.log('[Download] Standard method...');

        fetch(imageUrl + '?v=' + generateCacheBuster())
            .then(function(response) {
                if (!response.ok) throw new Error('Network error');
                return response.blob();
            })
            .then(function(blob) {
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                link.download = filename;
                link.style.display = 'none';
                
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                setTimeout(function() {
                    URL.revokeObjectURL(url);
                    console.log('[Download] ✅ Complete');
                    showDownloadNotification('✅ Downloaded');
                }, 100);
            })
            .catch(function(error) {
                console.error('[Download] Error:', error);
                showDownloadNotification('Download failed', 'error');
            });
    }

    // ========================================================================
    // MAIN FUNCTION
    // ========================================================================

    /**
     * Smart download - adapts to device
     */
    window.saveImageToDevice = function(imageUrl, baseName) {
        imageUrl = imageUrl || DOWNLOAD_CONFIG.IMAGE_URL;
        var filename = generateFilename(baseName);

        var deviceType = isRealIOSDevice() ? 'Real iOS' : 
                        isMacSafari() ? 'Mac Safari' : 'Other';
        
        console.log('[Download] Device: ' + deviceType);
        console.log('[Download] File: ' + filename);

        if (isRealIOSDevice()) {
            // Real iPhone/iPad - use Share API (opens share sheet)
            downloadIOSShare(imageUrl, filename);
        } else {
            // Desktop/Android - standard method
            downloadStandard(imageUrl, filename);
        }
    };

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    var deviceInfo = isRealIOSDevice() ? 'Real iOS Device' : 
                     isMacSafari() ? 'Mac Safari' : 'Desktop/Android';
    
    console.log('[Download Helper] v1.0.0');
    console.log('[Download Helper] Detected: ' + deviceInfo);
    console.log('[Download Helper] User Agent: ' + navigator.userAgent.substring(0, 80) + '...');

})(window, document);