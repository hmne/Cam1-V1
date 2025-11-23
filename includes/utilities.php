<?php

declare(strict_types=1);

/**
 * Shared Utilities Module
 *
 * Common functions used across the camera control system.
 * Follows PSR-12, Clean Code, and OWASP security standards.
 *
 * @category  Utilities
 * @package   CameraControl
 * @author    Net Storm
 * @license   Proprietary
 * @version   1.0.0
 * @standards PSR-12, OWASP, Clean Code
 */

// =============================================================================
// CONSTANTS
// =============================================================================

if (!defined('MAX_FILE_SIZE')) {
    define('MAX_FILE_SIZE', 1048576); // 1MB
}
if (!defined('MAX_UPLOAD_SIZE')) {
    define('MAX_UPLOAD_SIZE', 80000000); // 80MB
}
if (!defined('ERROR_LOG_FILE')) {
    define('ERROR_LOG_FILE', __DIR__ . '/../log/php_errors.log');
}
if (!defined('LOCK_TIMEOUT_SECONDS')) {
    define('LOCK_TIMEOUT_SECONDS', 5);
}
if (!defined('LOCK_RETRY_MICROSECONDS')) {
    define('LOCK_RETRY_MICROSECONDS', 100000);
}

// =============================================================================
// FILE CACHE (Performance optimization for frequently read files)
// =============================================================================

/**
 * @var array<string, array{data: string, time: int}> Simple file cache
 */
$_file_cache = [];
$_cache_ttl = 2; // seconds

// =============================================================================
// LOGGING FUNCTIONS
// =============================================================================

/**
 * Log error message with timestamp and context (thread-safe)
 *
 * @param string               $message Error message to log
 * @param string               $level   Log level (ERROR, WARNING, INFO, DEBUG)
 * @param array<string, mixed> $context Additional context data
 *
 * @return void
 */
function logMessage(string $message, string $level = 'ERROR', array $context = []): void
{
    $logDir = dirname(ERROR_LOG_FILE);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $timestamp = date('d/m/Y h:i:s A');
    $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: 'unknown';

    $contextStr = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    $logEntry = sprintf("[%s] [%s] [IP:%s] %s%s\n", $timestamp, $level, $ip, $message, $contextStr);

    // Thread-safe logging with file locking
    $fp = @fopen(ERROR_LOG_FILE, 'a');
    if ($fp !== false) {
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $logEntry);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    } else {
        // Fallback to error_log if file operations fail
        error_log($logEntry, 3, ERROR_LOG_FILE);
    }
}

// =============================================================================
// FILE OPERATIONS
// =============================================================================

/**
 * Safely read file contents with validation and fallback
 *
 * @param string $filePath     Path to file to read
 * @param string $defaultValue Default value if file cannot be read
 *
 * @return string File contents or default value
 */
function readFileSecure(string $filePath, string $defaultValue = ''): string
{
    if (!file_exists($filePath)) {
        return $defaultValue;
    }

    $fileSize = filesize($filePath);
    if ($fileSize === false || $fileSize === 0 || $fileSize > MAX_FILE_SIZE) {
        logMessage("File size invalid: $filePath (size: $fileSize)", 'WARNING');
        return $defaultValue;
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        logMessage("Failed to read file: $filePath", 'ERROR');
        return $defaultValue;
    }

    return trim($content);
}

/**
 * Atomically write data to file with lock
 *
 * @param string $filePath File path to write to
 * @param string $data     Data to write
 * @param bool   $append   Append mode (default: false)
 *
 * @return bool True on success, false on failure
 */
function writeFileAtomic(string $filePath, string $data, bool $append = false): bool
{
    $directory = dirname($filePath);
    if ($directory !== '.' && !is_dir($directory)) {
        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            logMessage("Failed to create directory: $directory", 'ERROR');
            return false;
        }
    }

    if ($append) {
        $handle = fopen($filePath, 'a');
        if ($handle === false) {
            logMessage("Failed to open file for append: $filePath", 'ERROR');
            return false;
        }

        if (!acquireFileLock($handle)) {
            fclose($handle);
            logMessage("Failed to acquire lock for append: $filePath", 'ERROR');
            return false;
        }

        $bytesWritten = fwrite($handle, $data);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $bytesWritten !== false;
    }

    // Atomic write using temp file
    $tempFile = $filePath . '.tmp.' . getmypid() . '.' . mt_rand();
    $handle = fopen($tempFile, 'w');

    if ($handle === false) {
        logMessage("Failed to create temp file: $tempFile", 'ERROR');
        return false;
    }

    if (!acquireFileLock($handle)) {
        fclose($handle);
        unlink($tempFile);
        logMessage("Failed to acquire lock: $filePath", 'ERROR');
        return false;
    }

    $bytesWritten = fwrite($handle, $data);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($bytesWritten === false) {
        unlink($tempFile);
        logMessage("Failed to write to temp file: $tempFile", 'ERROR');
        return false;
    }

    if (!rename($tempFile, $filePath)) {
        unlink($tempFile);
        logMessage("Failed to rename temp file: $tempFile -> $filePath", 'ERROR');
        return false;
    }

    chmod($filePath, 0644);
    return true;
}

/**
 * Acquire exclusive file lock with timeout
 *
 * @param resource $fileHandle File handle to lock
 *
 * @return bool True if lock acquired, false otherwise
 */
function acquireFileLock($fileHandle): bool
{
    $startTime = time();

    while ((time() - $startTime) < LOCK_TIMEOUT_SECONDS) {
        if (flock($fileHandle, LOCK_EX | LOCK_NB)) {
            return true;
        }
        usleep(LOCK_RETRY_MICROSECONDS);
    }

    return false;
}

// =============================================================================
// INPUT VALIDATION & SANITIZATION
// =============================================================================

/**
 * Sanitize and validate integer input with range checking
 *
 * @param mixed $value        Value to sanitize
 * @param int   $minValue     Minimum allowed value
 * @param int   $maxValue     Maximum allowed value
 * @param int   $defaultValue Default value if validation fails
 *
 * @return int Sanitized integer value
 */
function sanitizeInteger(mixed $value, int $minValue, int $maxValue, int $defaultValue): int
{
    if (!is_numeric($value)) {
        return $defaultValue;
    }

    $intValue = (int)$value;

    if ($intValue < $minValue || $intValue > $maxValue) {
        logMessage("Integer out of range: $intValue (min: $minValue, max: $maxValue)", 'WARNING');
        return $defaultValue;
    }

    return $intValue;
}

/**
 * Sanitize string input with whitelist validation
 *
 * @param mixed  $value          Value to sanitize
 * @param array  $allowedValues  Array of allowed values
 * @param string $defaultValue   Default value if not in whitelist
 *
 * @return string Sanitized string value
 */
function sanitizeStringWhitelist(mixed $value, array $allowedValues, string $defaultValue): string
{
    $stringValue = (string)$value;

    if (!in_array($stringValue, $allowedValues, true)) {
        logMessage("String not in whitelist: $stringValue", 'WARNING', ['allowed' => $allowedValues]);
        return $defaultValue;
    }

    return $stringValue;
}

/**
 * Validate and sanitize URL
 *
 * @param string $url URL to validate
 *
 * @return string|false Sanitized URL or false on failure
 */
function validateUrl(string $url)
{
    $sanitizedUrl = filter_var($url, FILTER_VALIDATE_URL);

    if ($sanitizedUrl === false) {
        logMessage("Invalid URL: $url", 'WARNING');
        return false;
    }

    // Additional security: ensure HTTP/HTTPS only
    $scheme = parse_url($sanitizedUrl, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true)) {
        logMessage("Invalid URL scheme: $scheme for URL: $url", 'WARNING');
        return false;
    }

    return $sanitizedUrl;
}

// =============================================================================
// OUTPUT FORMATTING
// =============================================================================

/**
 * Format file size in human-readable format
 *
 * @param int $sizeInBytes File size in bytes
 *
 * @return string Formatted file size with unit
 */
function formatFileSize(int $sizeInBytes): string
{
    if ($sizeInBytes <= 0) {
        return '0 Bytes';
    }

    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $power = floor(log($sizeInBytes, 1024));
    $power = max(0, min($power, count($units) - 1));

    $size = $sizeInBytes / pow(1024, $power);

    return round($size, 2) . ' ' . $units[$power];
}

/**
 * Escape HTML output securely
 *
 * @param string $text Text to escape
 *
 * @return string Escaped HTML
 */
function escapeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Send JSON response with proper headers and encoding
 *
 * @param array<string, mixed> $data       Data to encode
 * @param int                  $statusCode HTTP status code
 * @param bool                 $exit       Whether to exit after sending
 *
 * @return void
 */
function sendJsonResponse(array $data, int $statusCode = 200, bool $exit = true): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    sendNoCacheHeaders();

    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    if ($exit) {
        exit;
    }
}

// =============================================================================
// HTTP UTILITIES
// =============================================================================

/**
 * Send strict no-cache headers (for ALL responses)
 *
 * Prevents any caching by browser, proxy, or CDN
 *
 * @return void
 */
function sendNoCacheHeaders(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
}

/**
 * Validate admin token for protected endpoints
 *
 * @return void Exits with 403 if validation fails
 */
function validateAdminToken(): void
{
    // Start session only if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Debug: Log validation details
    $hasGetToken = isset($_GET['token']);
    $hasSessionToken = isset($_SESSION['admin_token']);
    $tokensMatch = $hasGetToken && $hasSessionToken && $_GET['token'] === $_SESSION['admin_token'];

    if (!$tokensMatch) {
        http_response_code(403);
        $reason = [];
        if (!$hasGetToken) $reason[] = 'missing GET token';
        if (!$hasSessionToken) $reason[] = 'missing SESSION token';
        if ($hasGetToken && $hasSessionToken && !$tokensMatch) $reason[] = 'token mismatch';

        logMessage(
            "Unauthorized access: " . implode(', ', $reason) .
            " from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            'WARNING'
        );

        echo json_encode([
            'error' => 'Unauthorized access',
            'debug' => $reason  // Temporary debug info
        ]);
        exit;
    }
}

/**
 * Fetch remote file with timeout
 *
 * @param string $url     URL to fetch
 * @param int    $timeout Timeout in seconds (default: 3)
 *
 * @return string|false File contents or false on failure
 */
function fetchRemoteFile(string $url, int $timeout = 3)
{
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'ignore_errors' => true
        ]
    ]);

    $result = file_get_contents($url, false, $context);

    if ($result === false) {
        logMessage("Failed to fetch remote file: $url", 'DEBUG');
        return false;
    }

    return $result;
}

/**
 * Send AJAX response headers
 *
 * @param string $contentType Content type (default: text/plain)
 *
 * @return void
 */
function sendAjaxHeaders(string $contentType = 'text/plain'): void
{
    header("Content-Type: $contentType; charset=UTF-8");
    sendNoCacheHeaders();
}

/**
 * Send security headers
 *
 * @return void
 */
function sendSecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
