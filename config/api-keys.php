<?php
/**
 * API Keys Configuration
 *
 * SECURITY CRITICAL: This file contains sensitive API credentials.
 * Must be gitignored and never committed to version control.
 *
 * Setup Instructions:
 * 1. Get your API Key from Google Cloud Console
 * 2. Paste your key below
 * 3. Restrict your API key for security
 * 4. Never commit this file to Git
 *
 * Standards Applied:
 * - Twelve-Factor App (Environment Configuration)
 * - OWASP Security Guidelines
 * - Principle of Least Privilege
 * - Defense in Depth
 * - Secure Configuration Management
 *
 * @category  Configuration
 * @package   Security
 * @author    Net Storm
 * @copyright 2025 Net Storm
 * @license   Proprietary
 * @version   1.0.0
 * @security  CRITICAL - Contains API credentials
 */

declare(strict_types=1);

// Security: Prevent direct access
if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

// =============================================================================
// GOOGLE CLOUD VISION API
// =============================================================================

/**
 * Google Cloud Vision API Key
 *
 * How to Get Your API Key:
 * 1. Go to: https://console.cloud.google.com/
 * 2. Create a new project (or select existing)
 * 3. Enable "Cloud Vision API"
 * 4. Go to: APIs & Services → Credentials
 * 5. Click: + CREATE CREDENTIALS → API key
 * 6. Copy the key (starts with AIza...)
 * 7. Paste it below between the quotes
 *
 * IMPORTANT: Restrict Your API Key for Security!
 *
 * Application Restrictions:
 * - Type: HTTP referrers (websites)
 * - Add: https://XX.com/*
 * - Add: http://XX.com/* (if using HTTP)
 *
 * API Restrictions:
 * - Restrict key to: Cloud Vision API only
 * - DO NOT select "Don't restrict key"
 *
 * @var string
 */
define('GOOGLE_VISION_API_KEY', 'XX');

// =============================================================================
// OCR CONFIGURATION
// =============================================================================

/**
 * Enable OCR feature
 * Automatically enabled if API key is set
 *
 * @var bool
 */
define(
    'OCR_ENABLED',
    GOOGLE_VISION_API_KEY !== 'YOUR_API_KEY_HERE' && 
    !empty(GOOGLE_VISION_API_KEY) &&
    strlen(GOOGLE_VISION_API_KEY) > 20
);

/**
 * Maximum file size for OCR processing
 * Google Vision API limit: 4MB per request
 *
 * @var int Bytes
 */
define('OCR_MAX_FILE_SIZE', 4 * 1024 * 1024); // 4MB

/**
 * Language hints for better OCR accuracy
 * Supports BCP-47 language codes
 *
 * Common codes:
 * - 'en': English
 * - 'ar': Arabic
 * - 'es': Spanish
 * - 'fr': French
 * - 'de': German
 * - 'zh': Chinese
 * - 'ja': Japanese
 *
 * @var array<int, string>
 */
define('OCR_LANGUAGE_HINTS', ['en', 'ar']);

/**
 * OCR timeout (seconds)
 *
 * @var int
 */
define('OCR_TIMEOUT', 30);

/**
 * Enable OCR request logging
 *
 * @var bool
 */
define('OCR_ENABLE_LOGGING', true);

/**
 * OCR rate limiting (requests per hour per IP)
 * Set to 0 to disable rate limiting
 *
 * @var int
 */
define('OCR_RATE_LIMIT', 60); // 60 requests/hour

// =============================================================================
// VALIDATION
// =============================================================================

/**
 * Validate API key format
 * Google API keys start with "AIza" and are 39 characters long
 */
if (OCR_ENABLED && !preg_match('/^AIza[0-9A-Za-z_-]{35}$/', GOOGLE_VISION_API_KEY)) {
    error_log('[OCR Config] WARNING: API key format looks invalid');
    
    // Don't block execution, but log warning
    if (defined('APP_DEBUG') && APP_DEBUG) {
        trigger_error(
            'Google Vision API key format is invalid. Should start with "AIza" and be 39 characters.',
            E_USER_WARNING
        );
    }
}

/**
 * Security check: Ensure this file is gitignored
 */
$gitignorePath = __DIR__ . '/../.gitignore';
if (file_exists($gitignorePath)) {
    $gitignoreContent = file_get_contents($gitignorePath);
    if ($gitignoreContent !== false && 
        strpos($gitignoreContent, 'api-keys.php') === false) {
        error_log('[OCR Config] SECURITY WARNING: api-keys.php is not in .gitignore!');
    }
}

// =============================================================================
// ADDITIONAL API KEYS (Future Use)
// =============================================================================

/**
 * Reserved for future API integrations
 * Examples: AWS Rekognition, Azure Computer Vision, etc.
 */

// Uncomment and configure if needed:
// define('AWS_ACCESS_KEY_ID', '');
// define('AWS_SECRET_ACCESS_KEY', '');
// define('AZURE_COMPUTER_VISION_KEY', '');
// define('AZURE_COMPUTER_VISION_ENDPOINT', '');

// =============================================================================
// END OF CONFIGURATION
// =============================================================================

// Log successful configuration load
if (OCR_ENABLE_LOGGING && OCR_ENABLED) {
    error_log('[OCR Config] API keys loaded successfully');
}