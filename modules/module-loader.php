<?php
declare(strict_types=1);

/**
 * Module Loader - Dynamic module loading system
 *
 * Loads modules based on manifest.json configuration.
 * Each module can provide JS, CSS, and keybindings.
 * Core keybindings have priority over module keybindings.
 *
 * @category  Modules
 * @package   ModuleLoader
 * @author    Net Storm
 * @version   1.0.0
 */

// Prevent direct access
if (!defined('CAMERA_ID')) {
    exit('Direct access not allowed');
}

/**
 * Module Loader Class
 */
class ModuleLoader
{
    /** @var string Base path for modules */
    private string $basePath;

    /** @var array Manifest data */
    private array $manifest = [];

    /** @var array Loaded modules */
    private array $loadedModules = [];

    /** @var array CSS files to include */
    private array $cssFiles = [];

    /** @var array JS files to include */
    private array $jsFiles = [];

    /** @var array Module keybindings */
    private array $keybindings = [];

    /** @var array Core keybindings (have priority) */
    private array $coreKeybindings = ['c', 's', 'l', 'r', ' '];

    /**
     * Constructor
     *
     * @param string $basePath Path to modules directory
     */
    public function __construct(string $basePath = '')
    {
        $this->basePath = $basePath ?: __DIR__;
        $this->loadManifest();
    }

    /**
     * Load manifest.json
     *
     * @return void
     */
    private function loadManifest(): void
    {
        $manifestPath = $this->basePath . '/manifest.json';

        if (!file_exists($manifestPath)) {
            error_log('[ModuleLoader] manifest.json not found');
            return;
        }

        $content = file_get_contents($manifestPath);
        if ($content === false) {
            error_log('[ModuleLoader] Failed to read manifest.json');
            return;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[ModuleLoader] Invalid JSON in manifest.json: ' . json_last_error_msg());
            return;
        }

        $this->manifest = $data;
    }

    /**
     * Load all enabled modules
     *
     * @return void
     */
    public function loadModules(): void
    {
        if (empty($this->manifest['modules'])) {
            return;
        }

        // Sort modules by priority (lower number = higher priority)
        $modules = $this->manifest['modules'];
        uasort($modules, function ($a, $b) {
            return ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100);
        });

        foreach ($modules as $moduleId => $moduleConfig) {
            if (!($moduleConfig['enabled'] ?? false)) {
                continue;
            }

            $this->loadModule($moduleId, $moduleConfig);
        }
    }

    /**
     * Load a single module
     *
     * @param string $moduleId Module identifier
     * @param array $config Module configuration
     * @return bool Success status
     */
    private function loadModule(string $moduleId, array $config): bool
    {
        // Check requirements (files/constants must exist)
        if (!$this->checkRequirements($config['requires'] ?? [])) {
            // Silent fail - requirements not met, hide module
            return false;
        }

        // Run init.php if exists
        $initFile = $this->basePath . '/' . ($config['files']['init'] ?? '');
        $moduleConfig = [];

        if (!empty($config['files']['init']) && file_exists($initFile)) {
            $initResult = $this->runModuleInit($initFile);

            if ($initResult === false) {
                // Silent fail - init returned false
                return false;
            }

            // If init.php returns array, it's module config with health checks
            if (is_array($initResult)) {
                $moduleConfig = $initResult;

                // Check module health (API keys, etc.)
                if (!$this->checkModuleHealth($moduleId, $moduleConfig)) {
                    // Silent fail - module not healthy
                    return false;
                }
            }
        }

        // Add CSS file
        if (!empty($config['files']['css'])) {
            $cssPath = $this->basePath . '/' . $config['files']['css'];
            if (file_exists($cssPath)) {
                $this->cssFiles[$moduleId] = 'modules/' . $config['files']['css'];
            }
        }

        // Add JS file
        if (!empty($config['files']['js'])) {
            $jsPath = $this->basePath . '/' . $config['files']['js'];
            if (file_exists($jsPath)) {
                $this->jsFiles[$moduleId] = 'modules/' . $config['files']['js'];
            }
        }

        // Register keybindings (skip if conflicts with core)
        if (!empty($config['keybindings'])) {
            foreach ($config['keybindings'] as $key => $action) {
                $keyLower = strtolower($key);
                if (!in_array($keyLower, $this->coreKeybindings, true)) {
                    $this->keybindings[$keyLower] = [
                        'module' => $moduleId,
                        'action' => $action
                    ];
                }
            }
        }

        $this->loadedModules[$moduleId] = $config;
        return true;
    }

    /**
     * Check module requirements
     *
     * @param array $requirements List of required files/constants
     * @return bool All requirements met
     */
    private function checkRequirements(array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            // Check if it's a file path
            if (strpos($requirement, '/') !== false || strpos($requirement, '.') !== false) {
                $filePath = dirname($this->basePath) . '/' . $requirement;
                if (!file_exists($filePath)) {
                    return false;
                }
            }
            // Check if it's a constant
            elseif (!defined($requirement)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Run module initialization script
     *
     * @param string $initFile Path to init.php
     * @return bool|array Success status or module config array
     */
    private function runModuleInit(string $initFile): bool|array
    {
        try {
            $result = include $initFile;
            return $result;
        } catch (Throwable $e) {
            // Silent fail - don't log to avoid spam
            return false;
        }
    }

    /**
     * Check module health (API keys, dependencies, etc.)
     *
     * @param string $moduleId Module identifier
     * @param array $moduleConfig Module config from init.php
     * @return bool Module is healthy and should be loaded
     */
    private function checkModuleHealth(string $moduleId, array $moduleConfig): bool
    {
        // Check if module has health check defined
        if (isset($moduleConfig['health_check']) && is_callable($moduleConfig['health_check'])) {
            try {
                $healthy = call_user_func($moduleConfig['health_check']);
                if (!$healthy) {
                    // Silent fail - module not healthy
                    return false;
                }
            } catch (Throwable $e) {
                // Health check failed - don't load
                return false;
            }
        }

        // Check for required config values
        if (!empty($moduleConfig['required_config'])) {
            foreach ($moduleConfig['required_config'] as $configKey) {
                // Check if constant is defined and not empty
                if (!defined($configKey) || empty(constant($configKey))) {
                    return false;
                }
            }
        }

        // Check for required files
        if (!empty($moduleConfig['required_files'])) {
            foreach ($moduleConfig['required_files'] as $file) {
                $filePath = dirname($this->basePath) . '/' . $file;
                if (!file_exists($filePath)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get CSS link tags for all loaded modules
     *
     * @return string HTML link tags
     */
    public function getCssIncludes(): string
    {
        $html = '';
        foreach ($this->cssFiles as $moduleId => $cssPath) {
            $version = filemtime($this->basePath . '/' . str_replace('modules/', '', $cssPath)) ?: time();
            $html .= sprintf(
                '    <link rel="stylesheet" href="%s?v=%d" data-module="%s">%s',
                escapeHtml($cssPath),
                $version,
                escapeHtml($moduleId),
                "\n"
            );
        }
        return $html;
    }

    /**
     * Get JS script tags for all loaded modules
     *
     * @return string HTML script tags
     */
    public function getJsIncludes(): string
    {
        $html = '';
        foreach ($this->jsFiles as $moduleId => $jsPath) {
            $version = filemtime($this->basePath . '/' . str_replace('modules/', '', $jsPath)) ?: time();
            $html .= sprintf(
                '    <script src="%s?v=%d" defer data-module="%s"></script>%s',
                escapeHtml($jsPath),
                $version,
                escapeHtml($moduleId),
                "\n"
            );
        }
        return $html;
    }

    /**
     * Get keybindings configuration for JavaScript
     *
     * @return string JSON encoded keybindings
     */
    public function getKeybindingsJson(): string
    {
        return json_encode($this->keybindings, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get loaded modules list
     *
     * @return array List of loaded module IDs
     */
    public function getLoadedModules(): array
    {
        return array_keys($this->loadedModules);
    }

    /**
     * Check if a specific module is loaded
     *
     * @param string $moduleId Module identifier
     * @return bool Module is loaded
     */
    public function isModuleLoaded(string $moduleId): bool
    {
        return isset($this->loadedModules[$moduleId]);
    }
}

// Initialize module loader
$moduleLoader = new ModuleLoader(__DIR__);
$moduleLoader->loadModules();