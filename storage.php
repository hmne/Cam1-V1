<?php

declare(strict_types=1);

/**
 * Unified Storage API - Enterprise Grade
 *
 * Centralized endpoint for file operations:
 * - Text/data file writing (POST: file + data)
 * - Image file uploading (POST: upfile)
 *
 * @category  API
 * @package   Storage
 * @author    Net Storm
 * @license   Proprietary
 * @version   2.0.0
 * @standards PSR-12, OWASP, Clean Code, Atomic Operations
 */

// =============================================================================
// INITIALIZATION
// =============================================================================

require_once __DIR__ . '/config/app-config.php';
require_once __DIR__ . '/includes/utilities.php';

// Security headers
sendSecurityHeaders();
header('Content-Type: text/plain; charset=UTF-8');

// =============================================================================
// VALIDATION FUNCTIONS
// =============================================================================

/**
 * Validate file path against whitelist (enhanced security)
 *
 * @param string $path     File path to validate
 * @param bool   $isUpload Whether this is an upload operation
 *
 * @return bool True if valid, false otherwise
 */
function validateFilePath(string $path, bool $isUpload = false): bool
{
    // Check for directory traversal attempts and null bytes
    if (strpos($path, '..') !== false ||
        strpos($path, "\0") !== false ||
        strpos($path, '../') !== false ||
        strpos($path, '..\\') !== false) {
        logMessage("Directory traversal attempt: $path", 'WARNING');
        return false;
    }

    // Prevent absolute path manipulation
    if (strpos($path, '/') === 0 || preg_match('/^[a-z]:/i', $path)) {
        logMessage("Absolute path attempt: $path", 'WARNING');
        return false;
    }

    if ($isUpload) {
        $dir = dirname($path);
        $dirName = ($dir === '.') ? '' : $dir;
        return in_array($dirName, ALLOWED_UPLOAD_DIRECTORIES, true);
    }

    // Check against file whitelist
    if (!in_array($path, ALLOWED_WRITABLE_FILES, true)) {
        logMessage("File not in whitelist: $path", 'WARNING');
        return false;
    }

    return true;
}

/**
 * Handle file upload (optimized for speed)
 *
 * @return void Exits with HTTP status code
 */
function handleUpload(): void
{
    if (!isset($_FILES['upfile'])) {
        http_response_code(400);
        logMessage("No file in upload request", 'WARNING');
        exit("Error: No file uploaded\n");
    }

    $file = $_FILES['upfile'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        logMessage("Upload error code: " . $file['error'], 'WARNING');
        exit("Error: Upload failed\n");
    }

    // Sanitize filename (security: remove path components)
    $targetName = basename($_GET['name'] ?? $file['name']);
    $targetName = preg_replace('/[^a-zA-Z0-9._-]/', '', $targetName);

    // Ensure filename is not empty after sanitization
    if (empty($targetName)) {
        $targetName = 'upload_' . time() . '.jpg';
    }

    // Ensure .jpg extension for security
    $allowedExtensions = ALLOWED_FILE_EXTENSIONS;
    $extension = strtolower(pathinfo($targetName, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        $targetName .= '.jpg';
    }

    // Validate directory (security: whitelist check)
    if (!validateFilePath($targetName, true)) {
        http_response_code(403);
        logMessage("Invalid upload path: $targetName", 'WARNING');
        exit("Error: Invalid path\n");
    }

    // Validate file size
    $size = $file['size'];
    if ($size <= 0 || $size > MAX_UPLOADABLE_FILE_SIZE) {
        http_response_code(400);
        logMessage("Invalid file size: $size bytes", 'WARNING');
        exit("Error: Invalid size\n");
    }

    // Move uploaded file (direct for speed)
    if (!move_uploaded_file($file['tmp_name'], $targetName)) {
        $lastError = error_get_last();
        logMessage("Failed to move uploaded file: " . ($lastError['message'] ?? 'unknown'), 'ERROR');
        http_response_code(500);
        exit("Error: Failed to save\n");
    }

    chmod($targetName, 0644);
    logMessage("File uploaded: $targetName (" . formatFileSize($size) . ")", 'INFO');
    http_response_code(200);
    exit("OK\n");
}

/**
 * Handle data write request
 *
 * @return void Exits with HTTP status code
 */
function handleWrite(): void
{
    $file = $_POST['file'] ?? '';
    $data = $_POST['data'] ?? '';

    if (empty($file)) {
        http_response_code(400);
        logMessage("No file specified in write request", 'WARNING');
        exit("Error: No file specified\n");
    }

    // Backward compatibility: add directory prefix if missing
    if (strpos($file, '/') === false) {
        if (substr($file, -4) === '.txt' || substr($file, -4) === '.log') {
            $file = 'log/' . $file;
        } elseif (substr($file, -4) === '.tmp') {
            $file = 'tmp/' . $file;
        }
    }

    // Validate file path
    if (!validateFilePath($file, false)) {
        http_response_code(403);
        exit("Error: Forbidden file path\n");
    }

    // Determine if append mode should be used
    // Auto-detect: all .log and .txt files in log/ directory
    $append = in_array($file, APPEND_MODE_FILES, true)
           || (strpos($file, 'log/') === 0 && (substr($file, -4) === '.log' || substr($file, -4) === '.txt'));

    if ($append) {
        $data .= PHP_EOL;
    }

    // Write file atomically
    if (writeFileAtomic($file, $data, $append)) {
        logMessage("Data written to: $file (" . strlen($data) . " bytes)", 'INFO');
        http_response_code(200);
        exit("OK\n");
    }

    http_response_code(500);
    exit("Error: Failed to write file\n");
}

// =============================================================================
// MAIN EXECUTION
// =============================================================================

if (isset($_FILES['upfile'])) {
    // Handle file upload request
    handleUpload();
} elseif (isset($_POST['file'])) {
    // Handle data write request
    handleWrite();
} else {
    // Invalid request - no recognized operation
    http_response_code(400);
    logMessage("Invalid API request - no operation specified", 'WARNING');
    exit("Error: Invalid request\n");
}
