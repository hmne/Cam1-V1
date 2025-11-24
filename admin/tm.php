<?php

declare(strict_types=1);

/**
 * Tunnel Manager (tm.php)
 *
 * Monitor and manage active tunnels (Cloudflare, ngrok, Bore, PageKite).
 * Provides real-time status, traffic monitoring, and tunnel control.
 *
 * Standards Applied:
 * - PSR-12: PHP Coding Standards
 * - OWASP Top 10: Security best practices
 * - Clean Code: Single Responsibility Principle
 * - Material Design: UI/UX patterns
 *
 * @category  Admin
 * @package   CameraControl
 * @author    Net Storm
 * @license   MIT
 * @version   1.0.0
 * @security  Requires admin authentication
 */

// Load configuration
require_once __DIR__ . '/../config/app-config.php';
require_once __DIR__ . '/../includes/utilities.php';

// Security: Validate admin token
validateAdminToken();

// Send security headers
sendSecurityHeaders();
sendNoCacheHeaders();

// =============================================================================
// FUNCTIONS
// =============================================================================

/**
 * Get tunnel URLs from status files
 *
 * @return array<string, string|null> Array of tunnel URLs
 */
function getTunnelUrls(): array
{
    $tunnels = [
        'tunnel1' => TUNNEL_URL_FILES[0] ?? null,
        'tunnel2' => TUNNEL_URL_FILES[1] ?? null,
        'tunnel3' => TUNNEL_URL_FILES[2] ?? null,
        'tunnel4' => TUNNEL_URL_FILES[3] ?? null,
    ];

    $urls = [];
    foreach ($tunnels as $name => $file) {
        if ($file && file_exists($file)) {
            $url = trim(file_get_contents($file));
            $urls[$name] = !empty($url) ? $url : null;
        } else {
            $urls[$name] = null;
        }
    }

    return $urls;
}

/**
 * Check tunnel health status
 *
 * @param string|null $url Tunnel URL to check
 *
 * @return array{status: string, responseTime: int|null, error: string|null}
 */
function checkTunnelHealth(?string $url): array
{
    if ($url === null || $url === '') {
        return ['status' => 'inactive', 'responseTime' => null, 'error' => 'No URL'];
    }

    $startTime = microtime(true);
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'method' => 'HEAD'
        ]
    ]);

    $result = @file_get_contents($url, false, $context);
    $responseTime = (int)((microtime(true) - $startTime) * 1000);

    if ($result === false) {
        return [
            'status' => 'error',
            'responseTime' => $responseTime,
            'error' => 'Connection failed'
        ];
    }

    // Check HTTP response code
    if (isset($http_response_header[0])) {
        preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
        $httpCode = (int)($matches[1] ?? 0);

        if ($httpCode >= 200 && $httpCode < 400) {
            return ['status' => 'active', 'responseTime' => $responseTime, 'error' => null];
        }

        return [
            'status' => 'warning',
            'responseTime' => $responseTime,
            'error' => "HTTP $httpCode"
        ];
    }

    return ['status' => 'unknown', 'responseTime' => $responseTime, 'error' => null];
}

/**
 * Detect tunnel provider from URL
 *
 * @param string|null $url Tunnel URL
 *
 * @return string Tunnel provider name
 */
function detectTunnelProvider(?string $url): string
{
    if ($url === null || $url === '') {
        return 'Unknown';
    }

    if (str_contains($url, 'trycloudflare.com') || str_contains($url, 'cfargotunnel.com')) {
        return 'Cloudflare';
    }
    if (str_contains($url, 'ngrok')) {
        return 'ngrok';
    }
    if (str_contains($url, 'bore.pub')) {
        return 'Bore';
    }
    if (str_contains($url, 'pagekite')) {
        return 'PageKite';
    }

    return 'Custom';
}

// =============================================================================
// DATA COLLECTION
// =============================================================================

$tunnelUrls = getTunnelUrls();
$tunnelStatus = [];

foreach ($tunnelUrls as $name => $url) {
    $health = checkTunnelHealth($url);
    $provider = detectTunnelProvider($url);

    $tunnelStatus[$name] = [
        'url' => $url,
        'provider' => $provider,
        'status' => $health['status'],
        'responseTime' => $health['responseTime'],
        'error' => $health['error']
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tunnel Manager - Camera Control</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #333;
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            border-radius: 10px;
            padding: 20px 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .header h1 {
            color: #667eea;
            margin-bottom: 10px;
        }

        .tunnel-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .tunnel-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }

        .tunnel-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }

        .tunnel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .tunnel-name {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-active {
            background: #4ade80;
            color: white;
        }

        .status-inactive {
            background: #94a3b8;
            color: white;
        }

        .status-error {
            background: #ef4444;
            color: white;
        }

        .status-warning {
            background: #f59e0b;
            color: white;
        }

        .tunnel-info {
            margin-top: 15px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .info-label {
            color: #64748b;
            font-size: 14px;
        }

        .info-value {
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        .tunnel-url {
            margin-top: 15px;
            padding: 10px;
            background: #f8fafc;
            border-radius: 5px;
            word-break: break-all;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #667eea;
        }

        .tunnel-url a {
            color: #667eea;
            text-decoration: none;
        }

        .tunnel-url a:hover {
            text-decoration: underline;
        }

        .no-tunnel {
            color: #94a3b8;
            font-style: italic;
        }

        .footer {
            background: white;
            border-radius: 10px;
            padding: 15px 30px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .back-button {
            display: inline-block;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.2s;
        }

        .back-button:hover {
            background: #5568d3;
        }

        @media (max-width: 768px) {
            .tunnel-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🌐 Tunnel Manager</h1>
            <p>Monitor and manage active tunnel connections</p>
        </div>

        <div class="tunnel-grid">
            <?php foreach ($tunnelStatus as $name => $data): ?>
                <div class="tunnel-card">
                    <div class="tunnel-header">
                        <span class="tunnel-name"><?= strtoupper(str_replace('tunnel', 'Tunnel ', $name)) ?></span>
                        <span class="status-badge status-<?= escapeHtml($data['status']) ?>">
                            <?= escapeHtml($data['status']) ?>
                        </span>
                    </div>

                    <div class="tunnel-info">
                        <div class="info-row">
                            <span class="info-label">Provider</span>
                            <span class="info-value"><?= escapeHtml($data['provider']) ?></span>
                        </div>

                        <?php if ($data['responseTime'] !== null): ?>
                            <div class="info-row">
                                <span class="info-label">Response Time</span>
                                <span class="info-value"><?= escapeHtml((string)$data['responseTime']) ?> ms</span>
                            </div>
                        <?php endif; ?>

                        <?php if ($data['error'] !== null): ?>
                            <div class="info-row">
                                <span class="info-label">Error</span>
                                <span class="info-value" style="color: #ef4444;">
                                    <?= escapeHtml($data['error']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tunnel-url">
                        <?php if ($data['url'] !== null): ?>
                            <a href="<?= escapeHtml($data['url']) ?>" target="_blank" rel="noopener noreferrer">
                                <?= escapeHtml($data['url']) ?>
                            </a>
                        <?php else: ?>
                            <span class="no-tunnel">No active tunnel</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="footer">
            <a href="clear.php?token=<?= escapeHtml($_SESSION['admin_token'] ?? '') ?>" class="back-button">
                ← Back to Control Panel
            </a>
        </div>
    </div>

    <script>
        // Auto-refresh every 10 seconds
        setTimeout(() => {
            location.reload();
        }, 10000);
    </script>
</body>
</html>
