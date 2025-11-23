<?php

declare(strict_types=1);

/**
 * Camera Reboot Controller
 *
 * Executes reboot command on Raspberry Pi camera via SSH
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
    // Write reboot trigger file with IP and timestamp for logging
    // Format: IP|TIMESTAMP
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $timestamp = date('H:i:s');
    $triggerData = $clientIP . '|' . $timestamp;

    $rebootFile = __DIR__ . '/../tmp/reboot.tmp';
    $result = writeFileAtomic($rebootFile, $triggerData);

    if (!$result) {
        throw new Exception('Failed to write reboot trigger file');
    }

    // Log success
    logMessage("Reboot trigger created by IP: {$clientIP} at {$timestamp}", 'INFO');

    // Return success using helper function
    sendJsonResponse([
        'success' => true,
        'message' => 'تم إرسال أمر إعادة التشغيل إلى ' . CAMERA_DISPLAY_NAME . ' بنجاح.'
    ], 200);

} catch (Exception $e) {
    // Log error
    logMessage("Reboot failed: " . $e->getMessage(), 'ERROR');

    // Return error using helper function
    sendJsonResponse([
        'error' => 'Failed to reboot camera',
        'message' => $e->getMessage()
    ], 500);
}
