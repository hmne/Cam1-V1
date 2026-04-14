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
 * @version   2.0.0
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
    // Initialize SSH connection
    $ssh = new SSHHelper();

    if (!$ssh->connect()) {
        throw new Exception('Failed to establish SSH connection');
    }

    // Execute reboot command
    $result = $ssh->executeCommand('sudo reboot', false);

    if ($result === false) {
        throw new Exception('Failed to execute reboot command');
    }

    // Log success
    logMessage("Reboot command sent successfully from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'INFO');

    // Disconnect
    $ssh->disconnect();

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
