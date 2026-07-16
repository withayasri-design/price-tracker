<?php

/**
 * LINE Account Linking Page
 *
 * Allows users to connect their Price Tracker account with LINE
 * to receive price drop notifications via LINE message.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../config/line.php';

use Core\Auth;
use Core\Csrf;

// Require login
Auth::requireLogin();

$userId = $_SESSION['user_id'];
$error = null;
$success = null;

// Get current user's LINE status
$stmt = $pdo->prepare("
    SELECT line_user_id, notify_line
    FROM users
    WHERE user_id = :id
");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$isLinked = !empty($user['line_user_id']);
$notifyLine = (bool) $user['notify_line'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::verify($_POST['csrf_token'] ?? '');

        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'link':
                // Link using code from LINE bot
                $linkCode = strtoupper(trim($_POST['link_code'] ?? ''));

                if (empty($linkCode) || strlen($linkCode) !== 8) {
                    throw new Exception('กรุณากรอกรหัสเชื่อมต่อ 8 หลัก');
                }

                // Check link code in system_settings
                $stmt = $pdo->prepare("
                    SELECT setting_value FROM system_settings
                    WHERE setting_key = :key
                ");
                $stmt->execute(['key' => 'line_link_' . $linkCode]);
                $codeData = $stmt->fetchColumn();

                if (!$codeData) {
                    throw new Exception('รหัสเชื่อมต่อไม่ถูกต้องหรือหมดอายุแล้ว');
                }

                $codeInfo = json_decode($codeData, true);

                if (!$codeInfo || empty($codeInfo['line_user_id'])) {
                    throw new Exception('รหัสเชื่อมต่อไม่ถูกต้อง');
                }

                // Check expiration
                if (isset($codeInfo['expires_at']) && strtotime($codeInfo['expires_at']) < time()) {
                    throw new Exception('รหัสเชื่อมต่อหมดอายุแล้ว กรุณาขอรหัสใหม่จาก LINE');
                }

                // Check if LINE account already linked to another user
                $stmt = $pdo->prepare("
                    SELECT user_id FROM users
                    WHERE line_user_id = :line_id AND user_id != :user_id
                ");
                $stmt->execute([
                    'line_id' => $codeInfo['line_user_id'],
                    'user_id' => $userId,
                ]);
                if ($stmt->fetch()) {
                    throw new Exception('บัญชี LINE นี้เชื่อมต่อกับผู้ใช้อื่นแล้ว');
                }

                // Link the account
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET line_user_id = :line_id, notify_line = 1
                    WHERE user_id = :user_id
                ");
                $stmt->execute([
                    'line_id' => $codeInfo['line_user_id'],
                    'user_id' => $userId,
                ]);

                // Delete used code
                $pdo->prepare("DELETE FROM system_settings WHERE setting_key = :key")
                    ->execute(['key' => 'line_link_' . $linkCode]);

                $isLinked = true;
                $notifyLine = true;
                $success = 'เชื่อมต่อบัญชี LINE สำเร็จ! คุณจะได้รับแจ้งเตือนผ่าน LINE แล้ว';

                // Send confirmation to LINE
                $config = getLineConfig($pdo);
                if (!empty($config['channel_access_token'])) {
                    require_once __DIR__ . '/../modules/notification/LineNotifier.php';
                    $notifier = new \Modules\Notification\LineNotifier($config['channel_access_token']);
                    $notifier->pushMessage($codeInfo['line_user_id'], [
                        $notifier->buildTextMessage("✅ เชื่อมต่อบัญชีสำเร็จแล้ว!\n\nคุณจะได้รับแจ้งเตือนเมื่อสินค้าที่ติดตามมีราคาลดลงครับ 📉")
                    ]);
                }
                break;

            case 'unlink':
                // Unlink LINE account
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET line_user_id = NULL, notify_line = 0
                    WHERE user_id = :user_id
                ");
                $stmt->execute(['user_id' => $userId]);

                $isLinked = false;
                $notifyLine = false;
                $success = 'ยกเลิกการเชื่อมต่อ LINE สำเร็จ';
                break;

            case 'toggle_notify':
                // Toggle LINE notifications
                $newValue = $notifyLine ? 0 : 1;
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET notify_line = :notify
                    WHERE user_id = :user_id
                ");
                $stmt->execute([
                    'notify' => $newValue,
                    'user_id' => $userId,
                ]);

                $notifyLine = (bool) $newValue;
                $success = $notifyLine ? 'เปิดการแจ้งเตือน LINE แล้ว' : 'ปิดการแจ้งเตือน LINE แล้ว';
                break;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$csrfToken = Csrf::generate();

// Check if LINE is configured
$lineConfigured = isLineConfigured($pdo);

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เชื่อมต่อ LINE - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .line-color { color: #06C755; }
        .btn-line {
            background-color: #06C755;
            border-color: #06C755;
            color: white;
        }
        .btn-line:hover {
            background-color: #05B04A;
            border-color: #05B04A;
            color: white;
        }
        .link-code-input {
            font-family: monospace;
            font-size: 1.5rem;
            text-align: center;
            letter-spacing: 0.5rem;
            text-transform: uppercase;
        }
        .qr-placeholder {
            width: 200px;
            height: 200px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            border-radius: 10px;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="/pages/dashboard.php">
                <i class="fas fa-chart-line me-2"></i>Price Tracker
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="/pages/profile.php">
                    <i class="fas fa-arrow-left me-1"></i>กลับ Profile
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-white text-center py-4">
                        <i class="fab fa-line fa-3x line-color mb-3"></i>
                        <h4 class="mb-0">เชื่อมต่อ LINE</h4>
                        <p class="text-muted mb-0">รับแจ้งเตือนราคาสินค้าผ่าน LINE</p>
                    </div>

                    <div class="card-body p-4">
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!$lineConfigured): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                ระบบแจ้งเตือน LINE ยังไม่ได้ตั้งค่า กรุณาติดต่อผู้ดูแลระบบ
                            </div>
                        <?php elseif ($isLinked): ?>
                            <!-- Already linked -->
                            <div class="text-center mb-4">
                                <div class="bg-success text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                                    <i class="fas fa-check fa-2x"></i>
                                </div>
                                <h5 class="mt-3 text-success">เชื่อมต่อแล้ว</h5>
                                <p class="text-muted">บัญชีของคุณเชื่อมต่อกับ LINE เรียบร้อยแล้ว</p>
                            </div>

                            <!-- Notification toggle -->
                            <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-4">
                                <div>
                                    <strong>การแจ้งเตือน LINE</strong>
                                    <div class="text-muted small">รับการแจ้งเตือนเมื่อราคาลด</div>
                                </div>
                                <form method="post" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="toggle_notify">
                                    <button type="submit" class="btn <?= $notifyLine ? 'btn-success' : 'btn-outline-secondary' ?>">
                                        <?= $notifyLine ? '<i class="fas fa-bell me-1"></i>เปิด' : '<i class="fas fa-bell-slash me-1"></i>ปิด' ?>
                                    </button>
                                </form>
                            </div>

                            <!-- Unlink button -->
                            <form method="post" onsubmit="return confirm('ต้องการยกเลิกการเชื่อมต่อ LINE หรือไม่?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="unlink">
                                <button type="submit" class="btn btn-outline-danger w-100">
                                    <i class="fas fa-unlink me-2"></i>ยกเลิกการเชื่อมต่อ
                                </button>
                            </form>

                        <?php else: ?>
                            <!-- Not linked - show instructions -->
                            <div class="mb-4">
                                <h5><i class="fas fa-info-circle me-2 text-primary"></i>วิธีเชื่อมต่อ</h5>
                                <ol class="mb-0">
                                    <li class="mb-2">เพิ่ม LINE Official Account ของเราเป็นเพื่อน</li>
                                    <li class="mb-2">พิมพ์ <code>link</code> ในแชท LINE</li>
                                    <li class="mb-2">นำรหัส 8 หลักที่ได้มากรอกด้านล่าง</li>
                                </ol>
                            </div>

                            <!-- QR Code placeholder -->
                            <div class="text-center mb-4">
                                <div class="qr-placeholder mb-2">
                                    <span class="text-muted">
                                        <i class="fas fa-qrcode fa-3x"></i>
                                        <div class="small mt-2">QR Code</div>
                                    </span>
                                </div>
                                <a href="#" class="btn btn-line btn-sm">
                                    <i class="fab fa-line me-1"></i>เพิ่มเพื่อน
                                </a>
                            </div>

                            <hr>

                            <!-- Link code form -->
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="link">

                                <div class="mb-3">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-key me-1"></i>รหัสเชื่อมต่อ
                                    </label>
                                    <input type="text"
                                           name="link_code"
                                           class="form-control link-code-input"
                                           placeholder="XXXXXXXX"
                                           maxlength="8"
                                           pattern="[A-Za-z0-9]{8}"
                                           required>
                                    <div class="form-text">กรอกรหัส 8 หลักที่ได้จาก LINE</div>
                                </div>

                                <button type="submit" class="btn btn-line w-100">
                                    <i class="fas fa-link me-2"></i>เชื่อมต่อบัญชี
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Benefits -->
                <div class="card mt-4 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-star text-warning me-2"></i>ประโยชน์ของการเชื่อมต่อ LINE
                        </h5>
                        <ul class="mb-0">
                            <li class="mb-2">รับแจ้งเตือนทันทีเมื่อราคาสินค้าลด</li>
                            <li class="mb-2">ข้อความสวยงามพร้อมรูปสินค้าและปุ่มซื้อเลย</li>
                            <li class="mb-2">ดูประวัติราคาได้จากในแชท</li>
                            <li>ไม่พลาดโปรโมชั่น Flash Sale</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-uppercase link code input
        document.querySelector('.link-code-input')?.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        });
    </script>
</body>
</html>
