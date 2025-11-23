<?php

declare(strict_types=1);

/**
 * Plugin Loader - Autonomous Configuration Manager
 *
 * Smart Architecture:
 * 1. Auto-discovers plugins from directories
 * 2. Generates manifest.json dynamically
 * 3. Provides whitelist for app-config.php
 * 4. Loads/unloads plugins intelligently
 * 5. 100% isolated - safe to delete
 *
 * Dual Mode Operation:
 * - Mode 1 (Normal): Outputs JavaScript manager
 * - Mode 2 (AJAX ?plugin=name): Returns plugin HTML
 *
 * Security Logic (File Naming):
 * - Website files: NO extension (scripts/shbattery_) - prevents direct execution
 * - Camera files: WITH extension (battery.sh) - enables execution
 * - This applies to ALL project files (.php, .sh, etc.)
 *
 * Note: manifest.json regenerates automatically every hour.
 * To regenerate manually: php -r "require 'plugins-loader.php'; generate_manifest();"
 *
 * @category  Core
 * @package   PluginSystem
 * @author    Net Storm
 * @license   Proprietary
 * @version   1.0.0
 */

// =============================================================================
// CONFIGURATION & DISCOVERY FUNCTIONS
// =============================================================================

/**
 * Extract plugin metadata from PHP file comments
 *
 * @param string $file_path Path to PHP file
 * @return array Plugin metadata
 */
function extract_plugin_metadata(string $file_path): array
{
    if (!file_exists($file_path)) {
        return [];
    }

    $content = @file_get_contents($file_path);
    if ($content === false) {
        return [];
    }

    $metadata = [
        'name' => '',
        'version' => '1.0.0',
        'description' => '',
        'author' => 'Net Storm',
        'data_sources' => [],
        'visibility_after_offline' => 600,
        'container_id' => '',
        'enabled' => true
    ];

    // Extract from PHPDoc comments
    if (preg_match('/@plugin-name\s+(.+)$/m', $content, $matches)) {
        $metadata['name'] = trim($matches[1]);
    }

    if (preg_match('/@plugin-version\s+(.+)$/m', $content, $matches)) {
        $metadata['version'] = trim($matches[1]);
    }

    if (preg_match('/@plugin-description\s+(.+)$/m', $content, $matches)) {
        $metadata['description'] = trim($matches[1]);
    }

    if (preg_match('/@plugin-author\s+(.+)$/m', $content, $matches)) {
        $metadata['author'] = trim($matches[1]);
    }

    if (preg_match('/@plugin-data-sources\s+(.+)$/m', $content, $matches)) {
        $sources = array_map('trim', explode(',', $matches[1]));
        $metadata['data_sources'] = array_filter($sources);
    }

    if (preg_match('/@plugin-visibility\s+(\d+)$/m', $content, $matches)) {
        $metadata['visibility_after_offline'] = (int)$matches[1];
    }

    if (preg_match('/@plugin-container\s+(.+)$/m', $content, $matches)) {
        $metadata['container_id'] = trim($matches[1]);
    }

    if (preg_match('/@plugin-enabled\s+(true|false)$/m', $content, $matches)) {
        $metadata['enabled'] = $matches[1] === 'true';
    }

    if (preg_match('/@plugin-script\s+(.+)$/m', $content, $matches)) {
        $metadata['script'] = trim($matches[1]);
    } else {
        $metadata['script'] = ''; // Will be auto-detected
    }

    return $metadata;
}

/**
 * Auto-discover plugins from directories
 *
 * @return array Discovered plugins configuration
 */
function discover_plugins(): array
{
    $plugins = [];
    $plugin_dir = __DIR__;

    // Scan PHP files in php/ directory
    $php_files = glob($plugin_dir . '/php/*.php');
    if ($php_files === false) {
        return [];
    }

    foreach ($php_files as $php_file) {
        $plugin_id = basename($php_file, '.php');

        // Extract metadata from PHP file
        $metadata = extract_plugin_metadata($php_file);

        // Find corresponding script in scripts/
        // Priority 1: Use @plugin-script from metadata if specified
        if (!empty($metadata['script'])) {
            $script_path = $metadata['script'];
            $script_file = $plugin_dir . '/' . $script_path;
        } else {
            // Priority 2: Auto-detect (battery-monitor → shbattery_)
            // IMPORTANT: Script names WITHOUT extension (security logic)
            //   Website: scripts/shbattery_  (no .sh - prevents direct execution)
            //   Camera:  /tmp/battery.sh     (with .sh - for execution)
            $short_name = explode('-', $plugin_id)[0];
            $script_name = 'sh' . $short_name . '_';  // ← NO extension!
            $script_file = $plugin_dir . '/scripts/' . $script_name;
            $script_path = file_exists($script_file) ? 'scripts/' . $script_name : '';
        }

        // Auto-generate container ID if not specified
        $container_id = $metadata['container_id'];
        if (empty($container_id)) {
            // Convert plugin-id to pluginId
            $container_id = 'plugin' . str_replace('-', '', ucwords($plugin_id, '-'));
        }

        $plugins[$plugin_id] = [
            'enabled' => $metadata['enabled'],
            'load' => true,
            'name' => $metadata['name'] ?: ucwords(str_replace('-', ' ', $plugin_id)),
            'version' => $metadata['version'],
            'description' => $metadata['description'],
            'author' => $metadata['author'],
            'php' => 'php/' . basename($php_file),
            'script' => file_exists($script_file) ? $script_path : '',
            'css' => '',
            'js' => '',
            'dependencies' => [],
            'data_sources' => $metadata['data_sources'],
            'visibility_after_offline' => $metadata['visibility_after_offline'],
            'container_id' => $container_id
        ];
    }

    return $plugins;
}

/**
 * Create placeholder files for plugin data sources and logs
 * Prevents 404 errors when plugin not yet active on camera
 * Smart system: Auto-generates ALL files that plugin might need
 *
 * @param array $plugins Discovered plugins
 * @return void
 */
function create_placeholder_files(array $plugins): void
{
    // Get absolute base directory
    // Try APP_ROOT first (if app-config loaded), fallback to realpath
    if (defined('APP_ROOT')) {
        $base_dir = APP_ROOT . '/';
    } else {
        $base_dir = realpath(__DIR__ . '/../../') . '/';
    }

    foreach ($plugins as $plugin_id => $config) {
        // Skip disabled plugins
        if (!$config['enabled']) {
            continue;
        }

        // 1. Create placeholder for each data source (tmp files)
        foreach ($config['data_sources'] as $data_source) {
            $file_path = $base_dir . $data_source;
            create_placeholder_file($file_path, '0|0|false|0', 0);
        }

        // 2. Auto-generate log files based on plugin directory structure
        // Pattern: log/{plugin_dir}/*.log
        // Example: tmp/batmon/status.tmp → log/batmon/alerts.log
        foreach ($config['data_sources'] as $data_source) {
            // Extract plugin directory from data source
            // tmp/batmon/status.tmp → batmon
            if (preg_match('#^tmp/([^/]+)/#', $data_source, $matches)) {
                $plugin_dir = $matches[1];

                // Create common log files for this plugin
                $log_files = [
                    "log/{$plugin_dir}/alerts.log",
                    "log/{$plugin_dir}/errors.log",
                    "log/{$plugin_dir}/debug.log"
                ];

                foreach ($log_files as $log_file) {
                    $file_path = $base_dir . $log_file;
                    create_placeholder_file($file_path, '', 0);
                }
            }
        }
    }
}

/**
 * Helper function to create a single placeholder file
 *
 * @param string $file_path Full path to file
 * @param string $content File content (empty for logs)
 * @param int $timestamp Unix timestamp (0 = epoch)
 * @return bool Success status
 */
function create_placeholder_file(string $file_path, string $content, int $timestamp): bool
{
    // Skip if file already exists
    if (file_exists($file_path)) {
        return true;
    }

    // Create directory if needed
    $dir = dirname($file_path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("[PluginLoader] Failed to create directory: {$dir}");
            return false;
        }
    }

    // Create placeholder file
    $result = file_put_contents($file_path, $content);
    if ($result === false) {
        error_log("[PluginLoader] Failed to create file: {$file_path}");
        return false;
    }

    // CRITICAL: Do NOT set old timestamp!
    // If we set timestamp to 1970, plugins will NEVER show up!
    // Let files have current timestamp - they'll be empty until camera updates them
    // JavaScript will handle missing/stale files with exponential backoff

    return true;
}

/**
 * Generate or update manifest.json
 *
 * @return array Generated manifest
 */
function generate_manifest(): array
{
    $plugins = discover_plugins();

    $manifest = [
        'plugins' => $plugins,
        'manifest_version' => '2.0.0',
        'last_updated' => date('Y-m-d H:i:s'),
        'auto_generated' => true,
        'generator' => 'plugins-loader.php v2.0.0'
    ];

    // Write to manifest.json
    $manifest_file = __DIR__ . '/manifest.json';
    $json_content = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (file_put_contents($manifest_file, $json_content) === false) {
        error_log("[PluginLoader] Failed to write manifest.json");
    }

    // Create placeholder files to prevent 404 errors
    create_placeholder_files($plugins);

    return $manifest;
}

/**
 * Get plugin data files for whitelist
 * This function is called by app-config.php
 *
 * @return array List of files that plugins need access to
 */
function get_plugin_files(): array
{
    $plugins = discover_plugins();
    $files = [];

    foreach ($plugins as $plugin_id => $config) {
        if (!$config['enabled']) {
            continue;
        }

        // Add data sources
        if (!empty($config['data_sources'])) {
            $files = array_merge($files, $config['data_sources']);
        }

        // Add log files (pattern: log/{plugin_id}*.log)
        $files[] = 'log/' . $plugin_id . '.log';
        $files[] = 'log/' . str_replace('-', '_', $plugin_id) . '.log';
    }

    // Remove duplicates
    return array_unique($files);
}

/**
 * Load manifest (with auto-generation if missing/old)
 * Smart system: regenerates if manifest OR essential files are missing
 *
 * @return array|null Manifest data
 */
function load_manifest(): ?array
{
    $manifest_file = __DIR__ . '/manifest.json';
    $must_regenerate = false;

    // Check if manifest exists and is recent (< 1 hour)
    if (file_exists($manifest_file)) {
        $age = time() - filemtime($manifest_file);

        if ($age < 3600) { // 1 hour
            $content = @file_get_contents($manifest_file);
            if ($content !== false) {
                $manifest = @json_decode($content, true);

                if ($manifest !== null && isset($manifest['plugins'])) {
                    // Verify essential plugin files exist
                    $base_dir = defined('APP_ROOT') ? APP_ROOT : realpath(__DIR__ . '/../../');

                    foreach ($manifest['plugins'] as $plugin) {
                        if (!empty($plugin['data_sources'])) {
                            $first_source = $base_dir . '/' . $plugin['data_sources'][0];
                            if (!file_exists($first_source)) {
                                // Critical file missing - must regenerate
                                $must_regenerate = true;
                                break;
                            }
                        }
                    }

                    if (!$must_regenerate) {
                        return $manifest;
                    }
                }
            }
        }
    }

    // Generate new manifest (missing, old, or files missing)
    return generate_manifest();
}

// =============================================================================
// MODE 2: AJAX - Return Plugin HTML
// =============================================================================

if (isset($_GET['plugin'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $plugin_name = $_GET['plugin'];

    // Load manifest
    $manifest = load_manifest();
    if ($manifest === null) {
        http_response_code(500);
        exit('Error: Cannot load manifest');
    }

    if (!isset($manifest['plugins'][$plugin_name])) {
        http_response_code(404);
        exit('Error: Plugin not found');
    }

    $plugin_config = $manifest['plugins'][$plugin_name];

    // Check if load is enabled
    if (isset($plugin_config['load']) && $plugin_config['load'] === false) {
        http_response_code(403);
        exit('Error: Plugin load disabled');
    }

    // Check if enabled
    if (!$plugin_config['enabled']) {
        http_response_code(403);
        exit('Error: Plugin disabled');
    }

    // Check if has PHP file
    if (!isset($plugin_config['php'])) {
        http_response_code(404);
        exit('Error: No PHP file');
    }

    // Load plugin
    $plugin_file = __DIR__ . '/' . $plugin_config['php'];

    if (!file_exists($plugin_file)) {
        http_response_code(404);
        exit('Error: Plugin file not found');
    }

    // Render plugin (capture output)
    ob_start();
    include $plugin_file;

    // Call render function if exists (try multiple naming conventions)
    $render_functions = [
        'render_plugin_status_bar',
        'render_' . str_replace('-', '_', $plugin_name) . '_plugin',
        'render_plugin'
    ];

    foreach ($render_functions as $func) {
        if (function_exists($func)) {
            $func();
            break;
        }
    }

    $output = ob_get_clean();

    // Return HTML
    echo $output;
    exit;
}

// =============================================================================
// MODE 1: NORMAL - Output JavaScript Manager
// =============================================================================

// CRITICAL: 100% AUTONOMOUS - Plugin Loader decides when to output HTML
// Only output HTML/JS when rendering the FULL page (not AJAX, POST, or function calls)

// Rule 1: NEVER output on POST requests (form submissions, AJAX writes)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    return; // Silent exit
}

// Rule 2: NEVER output on AJAX requests (check for common AJAX indicators)
$isAjax = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || isset($_GET['check_new_image'])
  || isset($_GET['get_image_size'])
  || isset($_GET['plugin']);

if ($isAjax) {
    return; // Silent exit
}

// Rule 3: NEVER output if called for function purposes only
if (isset($GLOBALS['_plugin_loader_silent']) && $GLOBALS['_plugin_loader_silent'] === true) {
    return; // Silent exit
}

// Rule 4: NEVER output if called from API endpoints
$scriptName = basename($_SERVER['SCRIPT_FILENAME']);
$forbiddenScripts = ['mode.php', 'reboot.php', 'shutdown.php', 'clear.php'];
if (in_array($scriptName, $forbiddenScripts, true)) {
    return; // Silent exit
}

// Rule 5: Only output if we actually have plugins to load
$manifest = load_manifest();

// If no manifest, silent exit (no error spam)
if ($manifest === null || !isset($manifest['plugins']) || count($manifest['plugins']) === 0) {
    return; // Silent exit - nothing to load
}

// ✅ ALL CHECKS PASSED - Safe to output Plugin Manager HTML/JS

// If accessed directly (not included), add DOCTYPE
$is_direct_access = !isset($GLOBALS['_app_included']);
if ($is_direct_access) {
    echo '<!DOCTYPE html>' . "\n";
}

?>

<!-- Plugin Container (Autonomous - Managed by JavaScript) -->
<div id="pluginContainer" style="display:none;"></div>

<script>
/**
 * Autonomous Plugin Manager - Smart Configuration
 * Dynamically loads plugins based on auto-generated manifest
 */
(function() {
    'use strict';

    if (window.PLUGIN_LOADER_ACTIVE) return;
    window.PLUGIN_LOADER_ACTIVE = true;

    const PLUGIN_CONFIG = <?= json_encode($manifest['plugins']) ?>;
    const CHECK_INTERVAL = 2000; // Check every 2 seconds
    const CAMERA_STATUS_FILE = 'tmp/status.tmp';
    const LOADER_URL = 'includes/plugins/plugins-loader.php';

    let loadedPlugins = {}; // Track which plugins are loaded
    let fileCache = {}; // Cache file existence checks (path -> {exists: bool, age: number, timestamp: number})
    let failedFiles = {}; // Track failed files with backoff (path -> {attempts: number, retryAfter: timestamp})

    if (window.DEBUG_PLUGINS) {
        console.log('[PluginLoader] Manifest auto-generated:', <?= json_encode($manifest['auto_generated']) ?>);
        console.log('[PluginLoader] Plugins discovered:', Object.keys(PLUGIN_CONFIG));
    }

    /**
     * Check file age via HEAD request with smart caching and exponential backoff
     * Prevents 404 spam by caching failures and backing off retry attempts
     */
    function checkFileAge(filepath, callback) {
        const now = Date.now();

        // Check if file has failed recently - use exponential backoff
        if (failedFiles[filepath]) {
            const retryAfter = failedFiles[filepath].retryAfter || 0;
            if (now < retryAfter) {
                // Still in backoff period - don't retry yet
                callback(999);
                return;
            }
        }

        // Check cache (valid for 5 seconds for successful checks)
        if (fileCache[filepath]) {
            const cacheAge = now - fileCache[filepath].timestamp;
            if (cacheAge < 5000) { // 5 second cache
                if (fileCache[filepath].exists) {
                    callback(fileCache[filepath].age || 0);
                    return;
                } else {
                    callback(999);
                    return;
                }
            }
        }

        // Perform actual check with silent error handling
        fetch(filepath + '?t=' + now, {
            method: 'HEAD',
            cache: 'no-store'
        })
        .then(response => {
            if (!response.ok) {
                // File doesn't exist (404) or other error
                throw new Error('HTTP ' + response.status);
            }

            const lastModified = response.headers.get('Last-Modified');
            if (lastModified) {
                const fileTime = new Date(lastModified).getTime() / 1000;
                const nowSec = now / 1000;
                const age = nowSec - fileTime;

                // Cache successful result
                fileCache[filepath] = {
                    exists: true,
                    age: age,
                    timestamp: now
                };

                // Clear any failure tracking
                delete failedFiles[filepath];

                callback(age);
            } else {
                // No Last-Modified header - treat as fresh
                fileCache[filepath] = {
                    exists: true,
                    age: 0,
                    timestamp: now
                };
                delete failedFiles[filepath];
                callback(0);
            }
        })
        .catch(() => {
            // File doesn't exist or network error
            // Calculate exponential backoff: 10s, 20s, 40s, 80s, 160s, 300s (max 5 min)
            const attempts = failedFiles[filepath]?.attempts || 0;
            const backoffSeconds = Math.min(300, Math.pow(2, attempts) * 10);
            const retryAfter = now + (backoffSeconds * 1000);

            failedFiles[filepath] = {
                attempts: attempts + 1,
                retryAfter: retryAfter
            };

            // Cache failure
            fileCache[filepath] = {
                exists: false,
                timestamp: now
            };

            if (window.DEBUG_PLUGINS) {
                console.log('[PluginLoader] File not found: ' + filepath + ', retry in ' + backoffSeconds + 's');
            }

            // Silently return "file too old" without console errors
            callback(999);
        });
    }

    /**
     * Get camera status
     */
    function getCameraStatus(callback) {
        checkFileAge(CAMERA_STATUS_FILE, function(age) {
            const isOnline = age <= 7;
            callback(isOnline, age);
        });
    }

    /**
     * Check if plugin should be visible
     */
    function shouldShowPlugin(pluginConfig, callback) {
        // Get plugin's data sources
        const dataSources = pluginConfig.data_sources || [];

        if (dataSources.length === 0) {
            // No data sources - always show if enabled
            callback(pluginConfig.enabled);
            return;
        }

        // Check plugin file age
        checkFileAge(dataSources[0], function(pluginAge) {
            // Check camera age
            getCameraStatus(function(isOnline, cameraAge) {
                const visibilityDuration = pluginConfig.visibility_after_offline || 600;

                // Plugin file must be fresh (< visibility duration)
                const pluginFresh = pluginAge <= visibilityDuration;

                // Camera must be healthy (online or recently offline)
                const cameraHealthy = cameraAge <= visibilityDuration;

                // Show if: plugin fresh AND camera healthy
                const shouldShow = pluginFresh && cameraHealthy;

                if (window.DEBUG_PLUGINS) {
                    console.log('[PluginLoader] Plugin age: ' + pluginAge.toFixed(1) + 's, Camera age: ' + cameraAge.toFixed(1) + 's, Show: ' + shouldShow);
                }

                callback(shouldShow);
            });
        });
    }

    /**
     * Load plugin via AJAX and inject into DOM
     */
    function loadPlugin(pluginName, pluginConfig) {
        if (loadedPlugins[pluginName]) {
            // Already loaded, just show it
            const container = document.getElementById(pluginConfig.container_id || 'plugin' + pluginName);
            if (container) {
                container.style.display = '';
            }
            return;
        }

        // Check if already being loaded (prevent duplicate requests)
        if (loadedPlugins[pluginName] === 'loading') {
            return;
        }

        // Mark as loading
        loadedPlugins[pluginName] = 'loading';

        console.log('[PluginLoader] Loading plugin: ' + pluginName);

        // Fetch plugin HTML from same file
        fetch(LOADER_URL + '?plugin=' + encodeURIComponent(pluginName) + '&t=' + Date.now(), {
            method: 'GET',
            cache: 'no-store'
        })
        .then(response => response.ok ? response.text() : Promise.reject('HTTP ' + response.status))
        .then(html => {
            // Check if HTML is empty (plugin returned nothing)
            if (!html || html.trim() === '') {
                console.warn('[PluginLoader] Plugin returned empty HTML: ' + pluginName);
                loadedPlugins[pluginName] = false; // Reset status
                return;
            }

            // Inject into plugin container
            const pluginContainer = document.getElementById('pluginContainer');
            if (!pluginContainer) {
                console.error('[PluginLoader] Plugin container not found');
                loadedPlugins[pluginName] = false; // Reset status
                return;
            }

            // Show container
            pluginContainer.style.display = '';

            // Append plugin HTML
            const wrapper = document.createElement('div');
            wrapper.innerHTML = html;
            pluginContainer.appendChild(wrapper);

            // Execute scripts in the HTML
            const scripts = wrapper.querySelectorAll('script');
            scripts.forEach(oldScript => {
                const newScript = document.createElement('script');
                if (oldScript.src) {
                    newScript.src = oldScript.src;
                } else {
                    newScript.textContent = oldScript.textContent;
                }
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });

            // Mark as loaded
            loadedPlugins[pluginName] = true;

            console.log('[PluginLoader] Plugin loaded: ' + pluginName);
        })
        .catch(error => {
            console.error('[PluginLoader] Failed to load plugin ' + pluginName + ':', error);
            loadedPlugins[pluginName] = false; // Reset status on error
        });
    }

    /**
     * Unload plugin (remove from DOM)
     */
    function unloadPlugin(pluginName, pluginConfig) {
        if (!loadedPlugins[pluginName]) {
            return; // Not loaded
        }

        console.log('[PluginLoader] Unloading plugin: ' + pluginName);

        const containerId = pluginConfig.container_id || ('plugin' + pluginName);
        const container = document.getElementById(containerId);

        if (container) {
            container.remove();
        }

        loadedPlugins[pluginName] = false;

        // Hide main container if no plugins loaded
        const anyLoaded = Object.values(loadedPlugins).some(loaded => loaded);
        if (!anyLoaded) {
            const pluginContainer = document.getElementById('pluginContainer');
            if (pluginContainer) {
                pluginContainer.style.display = 'none';
            }
        }
    }

    /**
     * Update plugin visibility
     */
    function updatePlugins() {
        for (const pluginName in PLUGIN_CONFIG) {
            const config = PLUGIN_CONFIG[pluginName];

            // Skip if load is disabled (for boot script control)
            if (config.load === false) continue;

            // Skip disabled plugins
            if (!config.enabled) continue;

            // Check if plugin should be visible based on its own files
            shouldShowPlugin(config, function(shouldShow) {
                if (shouldShow) {
                    // Plugin should be visible - load if not loaded
                    loadPlugin(pluginName, config);
                } else {
                    // Plugin should be hidden - unload if loaded
                    unloadPlugin(pluginName, config);
                }
            });
        }
    }

    // Initial update
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updatePlugins);
    } else {
        updatePlugins();
    }

    // Periodic updates (sync with mode.php refresh)
    setInterval(updatePlugins, CHECK_INTERVAL);

    console.log('[PluginLoader] Autonomous plugin manager active v2.0.0');
})();
</script>
