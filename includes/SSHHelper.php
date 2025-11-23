<?php

declare(strict_types=1);

// Load dependencies
require_once __DIR__ . '/utilities.php';
require_once __DIR__ . '/../config/app-config.php';

/**
 * SSH Helper Class
 *
 * Centralized SSH connection handler to eliminate code duplication
 * Used by: admin/reboot.php, admin/shutdown.php, admin/clean.php
 *
 * @category  Utilities
 * @package   CameraControl
 * @author    Net Storm
 * @license   Proprietary
 * @version   1.0.0
 * @standards PSR-12, Clean Code
 */

class SSHHelper
{
    /**
     * SSH connection resource
     * @var resource|null
     */
    private $connection = null;

    /**
     * SSH credentials
     * @var array
     */
    private array $credentials;

    /**
     * SSH server address
     * @var string
     */
    private string $address;

    /**
     * SSH server port
     * @var int
     */
    private int $port;

    /**
     * Constructor
     *
     * @throws Exception If credentials or SSH config unavailable
     */
    public function __construct()
    {
        $this->credentials = $this->loadCredentials();
        [$this->address, $this->port] = $this->loadSSHConfig();
    }

    /**
     * Load credentials from secure location
     *
     * @throws Exception If credentials not found
     * @return array Credentials array
     */
    private function loadCredentials(): array
    {
        $paths = [
            __DIR__ . '/.credentials.php',         // Same directory (testing only)
            '/files/home/.credentials.php',        // Hostinger production
            '/home/ns/.credentials.php'            // Local development
        ];

        foreach ($paths as $file) {
            if (file_exists($file)) {
                logMessage("Credentials loaded: $file", 'INFO');
                return require $file;
            }
        }

        logMessage("Credentials not found", 'ERROR');
        throw new Exception('Credentials unavailable');
    }

    /**
     * Load SSH tunnel configuration
     *
     * @throws Exception If SSH config unavailable or invalid
     * @return array [address, port]
     */
    private function loadSSHConfig(): array
    {
        if (!file_exists(SSH_CONNECTION_FILE)) {
            throw new Exception(CAMERA_DISPLAY_NAME . ' offline: SSH tunnel unavailable');
        }

        $config = readFileSecure(SSH_CONNECTION_FILE);

        if (!preg_match('/^([a-z0-9.-]+):(\d+)$/i', $config, $m)) {
            logMessage("Invalid SSH config: $config", 'ERROR');
            throw new Exception('SSH configuration malformed');
        }

        $port = (int)$m[2];

        if ($port < 1 || $port > 65535) {
            throw new Exception('SSH port out of range');
        }

        return [$m[1], $port];
    }

    /**
     * Establish SSH connection
     *
     * @return bool True on success, false on failure
     */
    public function connect(): bool
    {
        $this->connection = ssh2_connect($this->address, $this->port);

        if (!$this->connection) {
            logMessage("SSH connection failed to {$this->address}:{$this->port}", 'ERROR');
            return false;
        }

        // Authenticate
        if (!ssh2_auth_password(
            $this->connection,
            $this->credentials['ssh_username'],
            $this->credentials['ssh_password']
        )) {
            logMessage("SSH authentication failed for user: {$this->credentials['ssh_username']}", 'ERROR');
            $this->disconnect();
            return false;
        }

        logMessage("SSH connected successfully to {$this->address}:{$this->port}", 'INFO');
        return true;
    }

    /**
     * Execute command via SSH
     *
     * @param string $command Command to execute
     * @param bool   $wait    Wait for output (default: false for reboot/shutdown)
     *
     * @return string|bool Command output or true/false
     */
    public function executeCommand(string $command, bool $wait = false)
    {
        if (!$this->connection) {
            logMessage("Cannot execute command: No SSH connection", 'ERROR');
            return false;
        }

        $stream = ssh2_exec($this->connection, $command);

        if (!$stream) {
            logMessage("Failed to execute SSH command: $command", 'ERROR');
            return false;
        }

        logMessage("SSH command executed: $command", 'INFO');

        if ($wait) {
            stream_set_blocking($stream, true);
            $output = stream_get_contents($stream);
            fclose($stream);
            return $output;
        }

        // For commands like reboot/shutdown that disconnect
        fclose($stream);
        return true;
    }

    /**
     * Disconnect SSH connection
     *
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->connection) {
            ssh2_disconnect($this->connection);
            $this->connection = null;
            logMessage("SSH connection closed", 'INFO');
        }
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Get connection status
     *
     * @return bool True if connected
     */
    public function isConnected(): bool
    {
        return $this->connection !== null;
    }
}
