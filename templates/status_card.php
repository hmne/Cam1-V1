<?php

/**
 * Status Card Template
 *
 * Reusable template component for displaying camera status information.
 * Used by ping.php and other monitoring pages.
 *
 * Standards Applied:
 * - PSR-12: PHP Coding Standards
 * - DRY: Don't Repeat Yourself (reusable component)
 * - Clean Code: Separation of Concerns (presentation layer)
 * - OWASP: XSS prevention with proper escaping
 *
 * @category  Templates
 * @package   CameraControl
 * @author    Net Storm
 * @license   MIT
 * @version   1.0.0
 */

declare(strict_types=1);

// Security: Prevent direct access
if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

/**
 * Render status card HTML
 *
 * @param array{
 *   title: string,
 *   value: string|int|float,
 *   icon: string,
 *   status: string,
 *   description?: string,
 *   trend?: string
 * } $data Status card data
 *
 * @return void
 */
function renderStatusCard(array $data): void
{
    // Validate required fields
    if (!isset($data['title'], $data['value'], $data['icon'], $data['status'])) {
        error_log('[StatusCard] Missing required fields: ' . json_encode(array_keys($data)));
        return;
    }

    // Extract and sanitize data
    $title = escapeHtml($data['title']);
    $value = escapeHtml((string)$data['value']);
    $icon = escapeHtml($data['icon']);
    $status = escapeHtml($data['status']);
    $description = isset($data['description']) ? escapeHtml($data['description']) : '';
    $trend = isset($data['trend']) ? escapeHtml($data['trend']) : '';

    // Determine status class
    $statusClass = match ($status) {
        'online', 'active', 'good', 'success' => 'status-online',
        'offline', 'inactive', 'error', 'critical' => 'status-offline',
        'warning', 'degraded', 'slow' => 'status-warning',
        default => 'status-unknown'
    };

    // Determine trend indicator
    $trendIcon = match ($trend) {
        'up', 'increasing' => '↑',
        'down', 'decreasing' => '↓',
        'stable', 'steady' => '→',
        default => ''
    };

    ?>
    <div class="status-card <?= $statusClass ?>" data-status="<?= $status ?>">
        <div class="status-card-header">
            <span class="status-icon"><?= $icon ?></span>
            <h3 class="status-title"><?= $title ?></h3>
        </div>
        <div class="status-card-body">
            <div class="status-value">
                <?= $value ?>
                <?php if ($trendIcon): ?>
                    <span class="trend-indicator trend-<?= $trend ?>"><?= $trendIcon ?></span>
                <?php endif; ?>
            </div>
            <?php if ($description): ?>
                <p class="status-description"><?= $description ?></p>
            <?php endif; ?>
        </div>
        <div class="status-card-footer">
            <span class="status-badge status-badge-<?= $status ?>"><?= ucfirst($status) ?></span>
        </div>
    </div>
    <?php
}

/**
 * Helper function to safely escape HTML
 * Wrapper around htmlspecialchars with secure defaults
 *
 * @param string $text Text to escape
 *
 * @return string Escaped HTML
 */
function escapeHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}
