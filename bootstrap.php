<?php

declare(strict_types=1);

/**
 * Application Bootstrap File
 *
 * Initializes the application environment and loads core dependencies.
 * This file is loaded before the main application configuration.
 *
 * Standards Applied:
 * - PSR-12: PHP Coding Standards
 * - Twelve-Factor App: III. Config (Environment-based configuration)
 * - OWASP: Security best practices
 * - Clean Code: Single Responsibility Principle
 *
 * @category  Bootstrap
 * @package   CameraControl
 * @author    Net Storm
 * @license   MIT
 * @version   1.0.0
 * @standards PSR-12, Twelve-Factor App, OWASP Top 10
 */

// =============================================================================
// ENVIRONMENT DETECTION
// =============================================================================

/**
 * Detect if running in CLI mode
 *
 * @return bool True if running in command line interface
 */
function isCli(): bool
{
    return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
}

/**
 * Detect if running in development environment
 *
 * @return bool True if development environment
 */
function isDevelopment(): bool
{
    return getenv('APP_ENV') === 'development' ||
           (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] === 'localhost');
}

// =============================================================================
// ERROR HANDLING CONFIGURATION
// =============================================================================

/**
 * Configure error handling based on environment
 * Production: Log errors only
 * Development: Display errors for debugging
 */
if (isDevelopment()) {
    // Development: Show all errors
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    // Production: Hide errors from users, log internally
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
}

// Set custom error log path
$logDir = __DIR__ . '/log';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

// =============================================================================
// .ENV FILE LOADING (Twelve-Factor App: III. Config)
// =============================================================================

/**
 * Load environment variables from .env file
 * Implements Twelve-Factor App methodology for configuration
 *
 * @param string $envFile Path to .env file
 *
 * @return bool True if loaded successfully
 */
function loadEnvFile(string $envFile): bool
{
    if (!file_exists($envFile) || !is_readable($envFile)) {
        error_log("[Bootstrap] .env file not found or not readable: $envFile");
        return false;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        error_log("[Bootstrap] Failed to read .env file: $envFile");
        return false;
    }

    $loaded = 0;
    foreach ($lines as $lineNumber => $line) {
        // Skip comments and empty lines
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Parse KEY=VALUE format
        if (strpos($line, '=') === false) {
            error_log("[Bootstrap] Invalid .env line format at line " . ($lineNumber + 1) . ": $line");
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Remove quotes from value
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // Set environment variable (don't overwrite existing)
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            $loaded++;
        }
    }

    error_log("[Bootstrap] Loaded $loaded environment variables from .env");
    return true;
}

// Load .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    loadEnvFile($envFile);
} else {
    error_log("[Bootstrap] WARNING: .env file not found. Using default configuration.");
}

// =============================================================================
// SECURITY HEADERS (OWASP Security)
// =============================================================================

/**
 * Set security headers for HTTP responses
 * Implements OWASP security best practices
 *
 * @return void
 */
function setSecurityHeaders(): void
{
    // Only set headers in web context, not CLI
    if (isCli()) {
        return;
    }

    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');

    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // Enable XSS filter in browsers
    header('X-XSS-Protection: 1; mode=block');

    // Control referrer information
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Content Security Policy (CSP) - Basic policy
    // TODO: Customize based on your requirements
    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",  // Allow inline scripts (needed for current implementation)
        "style-src 'self' 'unsafe-inline'",  // Allow inline styles
        "img-src 'self' data: blob:",  // Allow images from self, data URIs, and blobs
        "font-src 'self' data:",
        "connect-src 'self' wss: ws:",  // Allow WebSocket connections
        "media-src 'self'",
        "object-src 'none'",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'"
    ]);
    header("Content-Security-Policy: $csp");

    // HTTPS Strict Transport Security (if using HTTPS)
    if (
        !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ||
        (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    ) {
        // Enable HSTS for 1 year (31536000 seconds)
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

// Apply security headers
setSecurityHeaders();

// =============================================================================
// SESSION MANAGEMENT (OWASP A07:2021 - Authentication Failures)
// =============================================================================

/**
 * Initialize secure session with hardened settings
 * Implements OWASP session management best practices
 *
 * @return void
 */
function initializeSecureSession(): void
{
    // Only initialize session in web context
    if (isCli() || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Session security settings
    ini_set('session.use_strict_mode', '1');       // Reject uninitialized session IDs
    ini_set('session.use_only_cookies', '1');      // No session ID in URLs
    ini_set('session.cookie_httponly', '1');       // Prevent JavaScript access
    ini_set('session.cookie_samesite', 'Strict');  // CSRF protection

    // Use secure cookies if HTTPS is enabled
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');

    // Session lifetime from environment or default to 1 hour
    $sessionLifetime = (int)(getenv('SESSION_LIFETIME') ?: 3600);
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.cookie_lifetime', (string)$sessionLifetime);

    // Custom session name (don't use default PHPSESSID)
    session_name('CAM_SESSION');

    // Start session
    if (!session_start()) {
        error_log('[Bootstrap] Failed to start session');
    }

    // Regenerate session ID periodically (every 15 minutes)
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 900) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }

    // Generate CSRF token if not exists
    if (!isset($_SESSION['admin_token'])) {
        $_SESSION['admin_token'] = bin2hex(random_bytes(32));
    }
}

// Initialize session
initializeSecureSession();

// =============================================================================
// PERFORMANCE OPTIMIZATIONS
// =============================================================================

/**
 * Configure PHP performance settings
 *
 * @return void
 */
function configurePerformance(): void
{
    // Output buffering for better performance
    if (!ob_get_level()) {
        ob_start();
    }

    // Realpath cache (reduces filesystem calls)
    ini_set('realpath_cache_size', '4096k');
    ini_set('realpath_cache_ttl', '600');

    // Memory limit from environment or default to 256M
    $memoryLimit = getenv('PHP_MEMORY_LIMIT') ?: '256M';
    ini_set('memory_limit', $memoryLimit);

    // Max execution time from environment or default to 30 seconds
    $maxExecutionTime = getenv('PHP_MAX_EXECUTION_TIME') ?: '30';
    ini_set('max_execution_time', $maxExecutionTime);

    // Enable OPcache if available and configured
    if (
        function_exists('opcache_get_status') &&
        (getenv('PHP_OPCACHE_ENABLED') === 'true' || !getenv('PHP_OPCACHE_ENABLED'))
    ) {
        // OPcache settings are typically configured in php.ini
        // Just verify it's enabled
        $opcacheStatus = @opcache_get_status();
        if ($opcacheStatus && !empty($opcacheStatus['opcache_enabled'])) {
            error_log('[Bootstrap] OPcache is enabled');
        }
    }
}

// Apply performance optimizations
configurePerformance();

// =============================================================================
// TIMEZONE CONFIGURATION
// =============================================================================

/**
 * Set application timezone
 *
 * @return void
 */
function configureTimezone(): void
{
    $timezone = getenv('APP_TIMEZONE') ?: 'UTC';

    if (!date_default_timezone_set($timezone)) {
        error_log("[Bootstrap] Failed to set timezone: $timezone, using UTC");
        date_default_timezone_set('UTC');
    }
}

// Set timezone
configureTimezone();

// =============================================================================
// AUTOLOADER (PSR-4 style if needed)
// =============================================================================

/**
 * Simple autoloader for custom classes
 * Can be replaced with Composer autoloader if available
 *
 * @param string $className Class name to load
 *
 * @return void
 */
spl_autoload_register(function (string $className): void {
    // Convert namespace to file path
    // Example: App\Services\Camera -> includes/Services/Camera.php
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/includes/';

    // Check if class uses our namespace
    $len = strlen($prefix);
    if (strncmp($prefix, $className, $len) !== 0) {
        return;
    }

    // Get relative class name
    $relativeClass = substr($className, $len);

    // Convert to file path
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    // Load file if exists
    if (file_exists($file)) {
        require $file;
    }
});

// =============================================================================
// BOOTSTRAP COMPLETE
// =============================================================================

error_log('[Bootstrap] Application bootstrap completed successfully');

// Continue to main configuration (app-config.php will be loaded by index.php)
