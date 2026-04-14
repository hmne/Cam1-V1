<?php

declare(strict_types=1);

/**
 * Camera Cleanup Controller
 *
 * Executes cleanup script on Raspberry Pi camera via SSH
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

    // Execute cleanup script
    $script = '/tmp/cleanup.sh';
    $result = $ssh->executeCommand("sudo bash $script", true);

    if ($result === false) {
        throw new Exception('Failed to execute cleanup command');
    }

    // Log success
    logMessage("Cleanup command executed successfully from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'INFO');

    // Disconnect
    $ssh->disconnect();

    // Return success using helper function
    sendJsonResponse([
        'success' => true,
        'message' => 'تم تنفيذ عملية التنظيف على ' . CAMERA_DISPLAY_NAME . ' بنجاح.',
        'output' => $result
    ], 200);

} catch (Exception $e) {
    // Log error
    logMessage("Cleanup failed: " . $e->getMessage(), 'ERROR');

    // Return error using helper function
    sendJsonResponse([
        'error' => 'Failed to execute cleanup',
        'message' => $e->getMessage()
    ], 500);
}
