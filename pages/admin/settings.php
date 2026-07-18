<?php

/**
 * Admin Settings Page
 *
 * System configuration for scraping, notifications, and agents.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';

use Core\Auth;
use Core\Csrf;

Auth::requireAdmin();

$error = null;
$success = null;

// Get all settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key");
$settings = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::verify($_POST['csrf_token'] ?? '');

        $action = $_POST['action'] ?? '';

        if ($action === 'update_settings') {
            $updates = $_POST['settings'] ?? [];

            foreach ($updates as $key => $value) {
                // Validate key format
                if (!preg_match('/^[a-z_]+$/', $key)) {
                    continue;
                }

                // Update or insert
                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, updated_at)
                    VALUES (:key, :value, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");
                $stmt->execute(['key' => $key, 'value' => $value]);
                $settings[$key] = $value;
            }

            $success = 'บันทึกการตั้งค่าสำเร็จ';
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$csrfToken = Csrf::generate();

// Group settings for display
$settingGroups = [
    'scraping' => [
        'title' => 'การดึงข้อมูล (Scraping)',
        'icon' => 'spider',
        'settings' => [
            'cron_interval_minutes' => ['label' => 'ความถี่ Cron (นาที)', 'type' => 'number'],
            'rate_limit_per_minute_shopee' => ['label' => 'Rate Limit Shopee', 'type' => 'number'],
            'rate_limit_per_minute_lazada' => ['label' => 'Rate Limit Lazada', 'type' => 'number'],
            'rate_limit_per_minute_tiktok' => ['label' => 'Rate Limit TikTok', 'type' => 'number'],
            'rate_limit_per_minute_jib' => ['label' => 'Rate Limit JIB', 'type' => 'number'],
            'rate_limit_per_minute_banana' => ['label' => 'Rate Limit Banana', 'type' => 'number'],
            'rate_limit_per_minute_advice' => ['label' => 'Rate Limit Advice', 'type' => 'number'],
        ],
    ],
    'email' => [
        'title' => 'Email Notification',
        'icon' => 'envelope',
        'settings' => [
            'email_from_address' => ['label' => 'From Email', 'type' => 'email'],
            'email_from_name' => ['label' => 'From Name', 'type' => 'text'],
            'email_smtp_host' => ['label' => 'SMTP Host', 'type' => 'text'],
            'email_smtp_port' => ['label' => 'SMTP Port', 'type' => 'number'],
            'email_smtp_user' => ['label' => 'SMTP Username', 'type' => 'text'],
            'email_smtp_password' => ['label' => 'SMTP Password', 'type' => 'password'],
        ],
    ],
    'line' => [
        'title' => 'LINE Messaging API',
        'icon' => 'comment',
        'settings' => [
            'line_channel_access_token' => ['label' => 'Channel Access Token', 'type' => 'password'],
            'line_channel_secret' => ['label' => 'Channel Secret', 'type' => 'password'],
        ],
    ],
    'agent' => [
        'title' => 'Agent Pipeline',
        'icon' => 'robot',
        'settings' => [
            'agent_scraper_batch_size' => ['label' => 'Scraper Batch Size', 'type' => 'number'],
            'agent_cleaning_similarity_threshold' => ['label' => 'Similarity Threshold (0-1)', 'type' => 'text'],
            'agent_pricediff_significant_change_percent' => ['label' => 'Price Change Threshold (%)', 'type' => 'number'],
            'agent_dispatch_batch_delay_seconds' => ['label' => 'Dispatch Delay (วินาที)', 'type' => 'number'],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Price Tracker Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="../dashboard.php">
                <i class="fas fa-chart-line me-2"></i>Price Tracker
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="./master_products.php">Master Products</a>
                <a class="nav-link" href="./agent_monitor.php">Agents</a>
                <a class="nav-link active" href="./settings.php">Settings</a>
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-arrow-left me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-cog me-2"></i>System Settings</h4>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="update_settings">

            <?php foreach ($settingGroups as $groupKey => $group): ?>
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-<?= $group['icon'] ?> me-2"></i><?= $group['title'] ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($group['settings'] as $key => $config): ?>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?= $config['label'] ?></label>
                                    <input type="<?= $config['type'] ?>"
                                           name="settings[<?= $key ?>]"
                                           class="form-control"
                                           value="<?= htmlspecialchars($settings[$key] ?? '') ?>"
                                           <?= $config['type'] === 'number' ? 'min="0"' : '' ?>>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save me-2"></i>บันทึกการตั้งค่า
                </button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
