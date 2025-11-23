<?php
/**
 * OCR Module Initialization
 *
 * Module loader file that registers the OCR module with the system.
 * Follows PSR-12 coding standards and best practices.
 *
 * Standards Applied:
 * - PSR-12: Extended Coding Style
 * - PSR-4: Autoloading Standard
 * - SOLID Principles
 * - Security Best Practices (OWASP Top 10)
 * - Clean Code Principles
 * - Twelve-Factor App Methodology
 * - Semantic Versioning
 * - Documentation Standards (PHPDoc)
 * - Error Handling Best Practices
 * - Dependency Injection Pattern
 *
 * @category  Modules
 * @package   OCR
 * @author    Net Storm
 * @copyright 2025 Net Storm
 * @license   Proprietary
 * @version   1.0.0
 * @link      https://github.com/hmne/cam1-v1
 * @since     1.0.0
 */

declare(strict_types=1);

$validContext = defined('MODULE_LOADER_ACTIVE') || 
                isset($GLOBALS['_app_included']) ||
                defined('APP_ROOT');

if (!$validContext) {
    if (php_sapi_name() !== 'cli' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        http_response_code(403);
        exit('Module must be loaded through the application');
    }
}

/**
 * OCR Module Registration
 *
 * Returns module configuration array for the module loader.
 * This file is executed by the module loader system.
 *
 * @return array<string, mixed> Module configuration
 */
return [
    // =========================================================================
    // MODULE IDENTITY
    // =========================================================================
    
    /**
     * Module unique identifier (lowercase, no spaces)
     * @var string
     */
    'id' => 'ocr',
    
    /**
     * Module display name
     * @var string
     */
    'name' => 'OCR Text Extraction',
    
    /**
     * Module description
     * @var string
     */
    'description' => 'Extract text from images using Google Cloud Vision API',
    
    /**
     * Module version (Semantic Versioning)
     * @var string
     */
    'version' => '2.0.0',
    
    /**
     * Module author
     * @var string
     */
    'author' => 'Net Storm',
    
    /**
     * Module status
     * @var bool
     */
    'enabled' => true,
    
    /**
     * Module priority (lower = loads earlier)
     * Used for load order dependency management
     * @var int
     */
    'priority' => 10,
    
    // =========================================================================
    // MODULE ASSETS
    // =========================================================================
    
    /**
     * CSS files to load (relative to module directory)
     * @var array<int, string>
     */
    'css' => [
        'ocr.css'
    ],
    
    /**
     * JavaScript files to load (relative to module directory)
     * @var array<int, string>
     */
    'js' => [
        'ocr.js'
    ],
    
    /**
     * PHP files to include (relative to module directory)
     * Optional: For modules that need backend processing
     * @var array<int, string>
     */
    'php' => [
        // No PHP includes needed - OCR uses standalone ocr.php
    ],
    
    // =========================================================================
    // MODULE DEPENDENCIES
    // =========================================================================
    
    /**
     * Required modules (must be loaded before this module)
     * @var array<int, string>
     */
    'requires' => [
        // No dependencies
    ],
    
    /**
     * Conflicting modules (cannot be loaded with this module)
     * @var array<int, string>
     */
    'conflicts' => [
        // No conflicts
    ],
    
    /**
     * PHP version requirement (Semantic Versioning)
     * @var string
     */
    'php_version' => '>=8.0.0',
    
    // =========================================================================
    // HEALTH CHECK (Smart Module System)
    // =========================================================================

    /**
     * Health check function - determines if module should load
     * Returns false = module hidden completely (no errors, no UI)
     * @var callable
     */
    'health_check' => function(): bool {
        // Check 1: API key file exists
        $apiKeyFile = dirname(__DIR__, 2) . '/config/api-keys.php';
        if (!file_exists($apiKeyFile)) {
            return false;
        }

        // Check 2: API key constant is defined and not empty
        if (!defined('GOOGLE_VISION_API_KEY') || empty(GOOGLE_VISION_API_KEY)) {
            // Try to load the file
            @include_once $apiKeyFile;
            if (!defined('GOOGLE_VISION_API_KEY') || empty(GOOGLE_VISION_API_KEY)) {
                return false;
            }
        }

        // Check 3: ocr.php exists
        $ocrEndpoint = __DIR__ . '/ocr.php';
        if (!file_exists($ocrEndpoint)) {
            return false;
        }

        return true;
    },

    /**
     * Required config constants (must be defined and non-empty)
     * @var array<int, string>
     */
    'required_config' => [
        // 'GOOGLE_VISION_API_KEY' // Checked in health_check instead
    ],

    /**
     * Required files (must exist)
     * @var array<int, string>
     */
    'required_files' => [
        'config/api-keys.php',
        'modules/ocr/ocr.php'
    ],

    // =========================================================================
    // MODULE CONFIGURATION
    // =========================================================================

    /**
     * Module-specific configuration
     * Accessible via $moduleLoader->getModuleConfig('ocr')
     * @var array<string, mixed>
     */
    'config' => [
        /**
         * Enable debug mode (shows console logs)
         * @var bool
         */
        'debug' => false,

        /**
         * API endpoint (relative to site root)
         * @var string
         */
        'endpoint' => 'modules/ocr/ocr.php',

        /**
         * Button selector target
         * @var string
         */
        'button_target' => '#imageSizeText',

        /**
         * Notification duration (milliseconds)
         * @var int
         */
        'notification_duration' => 5000,

        /**
         * Request timeout (milliseconds)
         * @var int
         */
        'timeout' => 30000,

        /**
         * Supported image formats
         * @var array<int, string>
         */
        'supported_formats' => ['jpg', 'jpeg', 'png'],

        /**
         * Maximum file size (bytes)
         * @var int
         */
        'max_file_size' => 4194304, // 4MB
    ],
    
    // =========================================================================
    // KEYBOARD SHORTCUTS
    // =========================================================================
    
    /**
     * Keyboard shortcuts configuration
     * @var array<string, mixed>
     */
    'keybindings' => [
        [
            /**
             * Shortcut key
             * @var string
             */
            'key' => 'o',
            
            /**
             * Shortcut description
             * @var string
             */
            'description' => 'Extract text from image (OCR)',
            
            /**
             * JavaScript function to call
             * @var string
             */
            'action' => 'extractTextFromImage',
            
            /**
             * Modifier keys required (empty = no modifiers)
             * Options: 'ctrl', 'alt', 'shift', 'meta'
             * @var array<int, string>
             */
            'modifiers' => [],
            
            /**
             * Prevent default browser action
             * @var bool
             */
            'preventDefault' => true,
        ]
    ],
    
    // =========================================================================
    // MODULE METADATA
    // =========================================================================
    
    /**
     * Module category for organization
     * @var string
     */
    'category' => 'tools',
    
    /**
     * Module tags for search/filtering
     * @var array<int, string>
     */
    'tags' => [
        'ocr',
        'text-extraction',
        'vision-api',
        'google-cloud',
        'image-processing'
    ],
    
    /**
     * Module icon (emoji or CSS class)
     * @var string
     */
    'icon' => '📋',
    
    /**
     * Module homepage/documentation URL
     * @var string
     */
    'url' => 'https://github.com/hmne/cam1-v1/tree/main/modules/ocr',
    
    /**
     * Module license
     * @var string
     */
    'license' => 'Proprietary',
    
    /**
     * Module update URL (for future auto-update feature)
     * @var string
     */
    'update_url' => '',
    
    // =========================================================================
    // SYSTEM INTEGRATION
    // =========================================================================
    
    /**
     * Data sources (files that module reads/writes)
     * Used for security whitelist in app-config.php
     * @var array<int, string>
     */
    'data_sources' => [
        // OCR reads images but doesn't create files
        // No data sources needed
    ],
    
    /**
     * API endpoints provided by this module
     * @var array<int, string>
     */
    'endpoints' => [
        'modules/ocr/ocr.php' // Standalone OCR proxy endpoint
    ],
    
    /**
     * Permissions required by this module
     * @var array<int, string>
     */
    'permissions' => [
        'read_images',    // Read access to pic.jpg, live.jpg
        'network_access'  // CURL access to Google Cloud Vision API
    ],
    
    // =========================================================================
    // LIFECYCLE HOOKS
    // =========================================================================
    
    /**
     * Initialization callback (called when module loads)
     * @var callable|null
     */
    'on_init' => null,
    
    /**
     * Activation callback (called when module is enabled)
     * @var callable|null
     */
    'on_activate' => null,
    
    /**
     * Deactivation callback (called when module is disabled)
     * @var callable|null
     */
    'on_deactivate' => null,
    
    /**
     * Uninstall callback (called when module is removed)
     * @var callable|null
     */
    'on_uninstall' => null,
    
    // =========================================================================
    // FEATURE FLAGS
    // =========================================================================
    
    /**
     * Module feature flags
     * @var array<string, bool>
     */
    'features' => [
        /**
         * Enable clipboard API support
         * @var bool
         */
        'clipboard_api' => true,
        
        /**
         * Enable iOS modal fallback
         * @var bool
         */
        'ios_modal' => true,
        
        /**
         * Enable keyboard shortcuts
         * @var bool
         */
        'keyboard_shortcuts' => true,
        
        /**
         * Enable debug logging
         * @var bool
         */
        'debug_logging' => false,
        
        /**
         * Enable accessibility features
         * @var bool
         */
        'accessibility' => true,
        
        /**
         * Enable mutation observer (dynamic content detection)
         * @var bool
         */
        'mutation_observer' => true,
    ],
    
    // =========================================================================
    // COMPATIBILITY
    // =========================================================================
    
    /**
     * Compatible JavaScript modes
     * @var array<int, string>
     */
    'js_modes' => [
        'normal',  // jQuery mode
        'fast',    // Vanilla JS mode
        'ultra'    // Ultra performance mode
    ],
    
    /**
     * Compatible browsers
     * @var array<string, string>
     */
    'browsers' => [
        'chrome'  => '>=90',
        'firefox' => '>=88',
        'safari'  => '>=14',
        'edge'    => '>=90',
        'opera'   => '>=76',
        'ios'     => '>=14',
        'android' => '>=90',
    ],
    
    /**
     * Mobile support
     * @var bool
     */
    'mobile_support' => true,
    
    /**
     * Tablet support
     * @var bool
     */
    'tablet_support' => true,
    
    /**
     * Touch support
     * @var bool
     */
    'touch_support' => true,
];