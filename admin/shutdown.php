<?php

declare(strict_types=1);

/**
 * Camera Shutdown Controller
 *
 * Executes shutdown command on Raspberry Pi camera via SSH
 *
 * @category  Admin
 * @package   CameraControl
 * @author    Net Storm
 * @license   Proprietary
 * @version   1.0.0
 * @standards PSR-12, OWASP, Clean Code
 */

// Load dependencies
require_once __DIR__ . '/../includes/utilities.php';
require_once __DIR__ . '/../includes/SSHHelper.php';
require_once __DIR__ . '/../config/app-config.php';

// No caching
sendNoCacheHeaders();
header('Content-Type: application/json; charset=UTF-8');

// Security: Token-based protection
validateAdminToken();

try {
    // Write shutdown trigger file with IP and timestamp for logging
    // Format: IP|TIMESTAMP
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $timestamp = date('H:i:s');
    $triggerData = $clientIP . '|' . $timestamp;

    $shutdownFile = __DIR__ . '/../tmp/shutdown.tmp';
    $result = writeFileAtomic($shutdownFile, $triggerData);

    if (!$result) {
        throw new Exception('Failed to write shutdown trigger file');
    }

    // Log success
    logMessage("Shutdown trigger created by IP: {$clientIP} at {$timestamp}", 'INFO');

    // Return success using helper function
    sendJsonResponse([
        'success' => true,
        'message' => 'تم إرسال أمر إيقاف التشغيل إلى ' . CAMERA_DISPLAY_NAME . ' بنجاح.'
    ], 200);

} catch (Exception $e) {
    // Log error
    logMessage("Shutdown failed: " . $e->getMessage(), 'ERROR');

    // Return error using helper function
    sendJsonResponse([
        'error' => 'Failed to shutdown camera',
        'message' => $e->getMessage()
    ], 500);
}
