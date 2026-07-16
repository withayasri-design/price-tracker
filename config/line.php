<?php

/**
 * LINE Messaging API Configuration
 *
 * Get your credentials from LINE Developers Console:
 * https://developers.line.biz/console/
 *
 * 1. Create a Messaging API channel
 * 2. Get Channel Access Token (long-lived)
 * 3. Get Channel Secret
 * 4. Set webhook URL to: https://yourdomain.com/api/notifications/line_webhook.php
 */

declare(strict_types=1);

// Load from environment or database
function getLineConfig(PDO $pdo = null): array
{
    // Try to get from database first (system_settings)
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("
                SELECT setting_key, setting_value
                FROM system_settings
                WHERE setting_key IN ('line_channel_access_token', 'line_channel_secret', 'line_liff_id')
            ");
            $stmt->execute();
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            if (!empty($settings['line_channel_access_token'])) {
                return [
                    'channel_access_token' => $settings['line_channel_access_token'],
                    'channel_secret' => $settings['line_channel_secret'] ?? '',
                    'liff_id' => $settings['line_liff_id'] ?? '',
                ];
            }
        } catch (PDOException $e) {
            // Fall through to env/defaults
        }
    }

    // Fall back to environment variables
    return [
        'channel_access_token' => $_ENV['LINE_CHANNEL_ACCESS_TOKEN'] ?? getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '',
        'channel_secret' => $_ENV['LINE_CHANNEL_SECRET'] ?? getenv('LINE_CHANNEL_SECRET') ?: '',
        'liff_id' => $_ENV['LINE_LIFF_ID'] ?? getenv('LINE_LIFF_ID') ?: '',
    ];
}

/**
 * Check if LINE integration is configured.
 */
function isLineConfigured(PDO $pdo = null): bool
{
    $config = getLineConfig($pdo);
    return !empty($config['channel_access_token']);
}

/**
 * Get LINE Login URL for OAuth.
 *
 * @param string $redirectUri Callback URL after login
 * @param string $state CSRF state token
 * @return string LINE Login URL
 */
function getLineLoginUrl(string $redirectUri, string $state): string
{
    // Note: LINE Login requires separate LIFF/Login channel
    // This is a basic implementation - may need adjustment based on actual LINE setup

    $clientId = $_ENV['LINE_LOGIN_CHANNEL_ID'] ?? getenv('LINE_LOGIN_CHANNEL_ID') ?: '';

    $params = [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'scope' => 'profile openid',
    ];

    return 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query($params);
}
