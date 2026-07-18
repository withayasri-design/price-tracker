<?php
/**
 * Notification Test Page
 *
 * Test LINE and Email notifications before production use.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';

use Core\Auth;
use Core\Csrf;

Auth::requireAdmin();

$csrfToken = Csrf::generate();
$result = null;
$resultType = '';

// Get current settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get admin user for testing
$adminUser = $pdo->query("
    SELECT user_id, email, full_name, line_user_id, notify_email, notify_line
    FROM users WHERE role = 'admin' LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Handle test submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::verify($_POST['csrf_token'] ?? '');

        $testType = $_POST['test_type'] ?? '';

        switch ($testType) {
            case 'email':
                $toEmail = $_POST['to_email'] ?? $adminUser['email'];
                $subject = $_POST['subject'] ?? 'Price Tracker Test Email';
                $message = $_POST['message'] ?? 'This is a test email from Price Tracker.';

                // Check if EmailNotifier exists
                $emailNotifierPath = __DIR__ . '/../../modules/notification/EmailNotifier.php';
                if (!file_exists($emailNotifierPath)) {
                    throw new Exception('EmailNotifier module not found.');
                }

                require_once $emailNotifierPath;

                $notifier = new \Modules\Notification\EmailNotifier($pdo);
                $sent = $notifier->sendRaw($toEmail, $subject, $message);

                if ($sent) {
                    $result = "Test email sent successfully to: $toEmail";
                    $resultType = 'success';
                } else {
                    throw new Exception('Failed to send email. Check SMTP settings.');
                }
                break;

            case 'line':
                $lineUserId = $_POST['line_user_id'] ?? $adminUser['line_user_id'] ?? '';
                $message = $_POST['line_message'] ?? 'This is a test message from Price Tracker.';

                if (empty($lineUserId)) {
                    throw new Exception('LINE User ID is required. Connect your LINE account first.');
                }

                // Check if LineNotifier exists
                $lineNotifierPath = __DIR__ . '/../../modules/notification/LineNotifier.php';
                if (!file_exists($lineNotifierPath)) {
                    throw new Exception('LineNotifier module not found.');
                }

                require_once $lineNotifierPath;

                $channelToken = $settings['line_channel_access_token'] ?? '';
                if (empty($channelToken)) {
                    throw new Exception('LINE Channel Access Token not configured.');
                }

                $notifier = new \Modules\Notification\LineNotifier($channelToken);
                $sent = $notifier->sendText($lineUserId, $message);

                if ($sent) {
                    $result = "Test LINE message sent successfully!";
                    $resultType = 'success';
                } else {
                    throw new Exception('Failed to send LINE message. Check API settings.');
                }
                break;

            case 'line_flex':
                $lineUserId = $_POST['line_user_id'] ?? $adminUser['line_user_id'] ?? '';

                if (empty($lineUserId)) {
                    throw new Exception('LINE User ID is required.');
                }

                $lineNotifierPath = __DIR__ . '/../../modules/notification/LineNotifier.php';
                if (!file_exists($lineNotifierPath)) {
                    throw new Exception('LineNotifier module not found.');
                }

                require_once $lineNotifierPath;

                $channelToken = $settings['line_channel_access_token'] ?? '';
                if (empty($channelToken)) {
                    throw new Exception('LINE Channel Access Token not configured.');
                }

                $notifier = new \Modules\Notification\LineNotifier($channelToken);

                // Send test price alert
                $sent = $notifier->sendPriceAlert($lineUserId, [
                    'product_name' => 'Test Product - MacBook Pro 14"',
                    'platform' => 'jib',
                    'old_price' => 69900,
                    'new_price' => 59900,
                    'change_percent' => -14.3,
                    'product_url' => 'https://www.jib.co.th/web/product/readProduct/12345',
                    'image_url' => 'https://via.placeholder.com/300x300?text=Test+Product',
                ]);

                if ($sent) {
                    $result = "Test LINE Flex Message sent successfully!";
                    $resultType = 'success';
                } else {
                    throw new Exception('Failed to send LINE Flex Message.');
                }
                break;

            default:
                throw new Exception('Invalid test type.');
        }

    } catch (Exception $e) {
        $result = $e->getMessage();
        $resultType = 'danger';
    }
}

// Check configuration status
$emailConfigured = !empty($settings['smtp_host'] ?? $_ENV['SMTP_HOST'] ?? '');
$lineConfigured = !empty($settings['line_channel_access_token'] ?? '');
$lineConnected = !empty($adminUser['line_user_id']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Notifications - Price Tracker Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="../dashboard.php">
                <i class="bi bi-cart-check"></i> Price Tracker
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="settings.php">
                    <i class="bi bi-gear"></i> Settings
                </a>
                <a class="nav-link" href="../dashboard.php">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <h2 class="mb-4"><i class="bi bi-send-check"></i> Test Notifications</h2>

        <?php if ($result): ?>
        <div class="alert alert-<?= $resultType ?> alert-dismissible fade show">
            <?= htmlspecialchars($result) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Configuration Status -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-envelope display-4 text-<?= $emailConfigured ? 'success' : 'secondary' ?>"></i>
                        <h5 class="mt-2">Email (SMTP)</h5>
                        <?php if ($emailConfigured): ?>
                        <span class="badge bg-success">Configured</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Not Configured</span>
                        <p class="small text-muted mt-2">Set SMTP settings in .env or admin settings</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-chat-dots display-4 text-<?= $lineConfigured ? 'success' : 'secondary' ?>"></i>
                        <h5 class="mt-2">LINE API</h5>
                        <?php if ($lineConfigured): ?>
                        <span class="badge bg-success">Configured</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Not Configured</span>
                        <p class="small text-muted mt-2">Add LINE API credentials in admin settings</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-person-check display-4 text-<?= $lineConnected ? 'success' : 'secondary' ?>"></i>
                        <h5 class="mt-2">LINE Account</h5>
                        <?php if ($lineConnected): ?>
                        <span class="badge bg-success">Connected</span>
                        <p class="small text-muted mt-2">
                            ID: <?= substr($adminUser['line_user_id'], 0, 10) ?>...
                        </p>
                        <?php else: ?>
                        <span class="badge bg-secondary">Not Connected</span>
                        <p class="small text-muted mt-2">
                            <a href="../line_connect.php">Connect LINE Account</a>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Email Test -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <i class="bi bi-envelope"></i> Test Email Notification
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="test_type" value="email">

                            <div class="mb-3">
                                <label class="form-label">To Email</label>
                                <input type="email" name="to_email" class="form-control"
                                       value="<?= htmlspecialchars($adminUser['email']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Subject</label>
                                <input type="text" name="subject" class="form-control"
                                       value="Price Tracker Test Email" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Message</label>
                                <textarea name="message" class="form-control" rows="3" required>This is a test email from Price Tracker.

If you received this email, your SMTP configuration is working correctly!</textarea>
                            </div>

                            <button type="submit" class="btn btn-primary" <?= !$emailConfigured ? 'disabled' : '' ?>>
                                <i class="bi bi-send"></i> Send Test Email
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- LINE Test -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <i class="bi bi-chat-dots"></i> Test LINE Notification
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="test_type" value="line">

                            <div class="mb-3">
                                <label class="form-label">LINE User ID</label>
                                <input type="text" name="line_user_id" class="form-control"
                                       value="<?= htmlspecialchars($adminUser['line_user_id'] ?? '') ?>"
                                       placeholder="U1234567890abcdef..." required>
                                <div class="form-text">
                                    Your LINE User ID (starts with U)
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Message</label>
                                <textarea name="line_message" class="form-control" rows="3" required>🔔 Price Tracker Test

This is a test message from Price Tracker.
If you received this message, your LINE configuration is working correctly!</textarea>
                            </div>

                            <button type="submit" class="btn btn-success" <?= !$lineConfigured ? 'disabled' : '' ?>>
                                <i class="bi bi-send"></i> Send Text Message
                            </button>
                        </form>

                        <hr>

                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="test_type" value="line_flex">
                            <input type="hidden" name="line_user_id"
                                   value="<?= htmlspecialchars($adminUser['line_user_id'] ?? '') ?>">

                            <p class="text-muted small">
                                Test a Flex Message (price alert format):
                            </p>

                            <button type="submit" class="btn btn-outline-success"
                                    <?= (!$lineConfigured || !$lineConnected) ? 'disabled' : '' ?>>
                                <i class="bi bi-card-text"></i> Send Flex Message
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Troubleshooting -->
        <div class="card">
            <div class="card-header">
                <i class="bi bi-question-circle"></i> Troubleshooting
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Email Issues</h6>
                        <ul class="small text-muted">
                            <li>Check SMTP host, port, and credentials in .env</li>
                            <li>For Gmail, use an App Password (not your regular password)</li>
                            <li>Ensure port 587 (TLS) or 465 (SSL) is open</li>
                            <li>Check spam folder for test emails</li>
                            <li>View PHP error logs for detailed errors</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>LINE Issues</h6>
                        <ul class="small text-muted">
                            <li>Verify Channel Access Token in admin settings</li>
                            <li>Ensure LINE Official Account is set up correctly</li>
                            <li>User must follow your LINE Official Account first</li>
                            <li>LINE User ID format: U followed by 32 hex characters</li>
                            <li>Check LINE Developers Console for webhook errors</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
