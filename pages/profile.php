<?php

/**
 * User Profile Page
 *
 * Allows users to view and edit their profile information,
 * change password, and manage notification settings.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';

use Core\Auth;
use Core\Csrf;

Auth::requireLogin();

$userId = Auth::userId();
$error = null;
$success = null;

// Get user data
$stmt = $pdo->prepare("
    SELECT user_id, email, full_name, role, notify_email, notify_line, line_user_id, created_at
    FROM users WHERE user_id = :id
");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    Auth::logout();
    header('Location: /pages/login.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::verify($_POST['csrf_token'] ?? '');

        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'update_profile':
                $fullName = trim($_POST['full_name'] ?? '');

                if (empty($fullName)) {
                    throw new Exception('กรุณากรอกชื่อ-นามสกุล');
                }

                if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
                    throw new Exception('ชื่อต้องมีความยาว 2-150 ตัวอักษร');
                }

                $stmt = $pdo->prepare("
                    UPDATE users SET full_name = :name WHERE user_id = :id
                ");
                $stmt->execute(['name' => $fullName, 'id' => $userId]);

                $user['full_name'] = $fullName;
                $_SESSION['full_name'] = $fullName;
                $success = 'อัปเดตข้อมูลสำเร็จ';
                break;

            case 'change_password':
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if (empty($currentPassword)) {
                    throw new Exception('กรุณากรอกรหัสผ่านปัจจุบัน');
                }

                // Verify current password
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :id");
                $stmt->execute(['id' => $userId]);
                $hash = $stmt->fetchColumn();

                if (!Auth::verifyPassword($currentPassword, $hash)) {
                    throw new Exception('รหัสผ่านปัจจุบันไม่ถูกต้อง');
                }

                if (strlen($newPassword) < 8) {
                    throw new Exception('รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร');
                }

                if ($newPassword !== $confirmPassword) {
                    throw new Exception('รหัสผ่านใหม่ไม่ตรงกัน');
                }

                $newHash = Auth::hashPassword($newPassword);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :id");
                $stmt->execute(['hash' => $newHash, 'id' => $userId]);

                $success = 'เปลี่ยนรหัสผ่านสำเร็จ';
                break;

            case 'update_notifications':
                $notifyEmail = isset($_POST['notify_email']) ? 1 : 0;
                $notifyLine = isset($_POST['notify_line']) ? 1 : 0;

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET notify_email = :email, notify_line = :line
                    WHERE user_id = :id
                ");
                $stmt->execute([
                    'email' => $notifyEmail,
                    'line' => $notifyLine,
                    'id' => $userId,
                ]);

                $user['notify_email'] = $notifyEmail;
                $user['notify_line'] = $notifyLine;
                $success = 'อัปเดตการแจ้งเตือนสำเร็จ';
                break;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$csrfToken = Csrf::generate();
$isLineLinked = !empty($user['line_user_id']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Price Tracker</title>
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
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="../dashboard.php">
                            <i class="fas fa-home me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i><?= htmlspecialchars($user['full_name']) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="../line_connect.php"><i class="fab fa-line me-2"></i>LINE</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <div class="col-lg-4 mb-4">
                <!-- Profile Card -->
                <div class="card text-center">
                    <div class="card-body py-5">
                        <div class="mb-3">
                            <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 100px; height: 100px;">
                                <i class="fas fa-user fa-3x"></i>
                            </div>
                        </div>
                        <h4 class="mb-1"><?= htmlspecialchars($user['full_name']) ?></h4>
                        <p class="text-muted mb-2"><?= htmlspecialchars($user['email']) ?></p>
                        <span class="badge bg-<?= $user['role'] === 'admin' ? 'danger' : 'primary' ?>">
                            <?= $user['role'] === 'admin' ? 'Admin' : 'User' ?>
                        </span>
                        <hr class="my-4">
                        <div class="text-start px-3">
                            <p class="mb-2">
                                <i class="fas fa-calendar-alt me-2 text-muted"></i>
                                <span class="text-muted">สมาชิกตั้งแต่:</span>
                                <span><?= date('d/m/Y', strtotime($user['created_at'])) ?></span>
                            </p>
                            <p class="mb-0">
                                <i class="fab fa-line me-2 text-muted"></i>
                                <span class="text-muted">LINE:</span>
                                <?php if ($isLineLinked): ?>
                                    <span class="text-success"><i class="fas fa-check-circle me-1"></i>เชื่อมต่อแล้ว</span>
                                <?php else: ?>
                                    <a href="../line_connect.php" class="text-decoration-none">เชื่อมต่อ LINE</a>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
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

                <!-- Profile Info -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-user me-2"></i>ข้อมูลส่วนตัว</h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="update_profile">

                            <div class="mb-3">
                                <label class="form-label">อีเมล</label>
                                <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                                <div class="form-text">ไม่สามารถเปลี่ยนอีเมลได้</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ชื่อ-นามสกุล</label>
                                <input type="text"
                                       name="full_name"
                                       class="form-control"
                                       value="<?= htmlspecialchars($user['full_name']) ?>"
                                       required>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>บันทึก
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Change Password -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-lock me-2"></i>เปลี่ยนรหัสผ่าน</h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="change_password">

                            <div class="mb-3">
                                <label class="form-label">รหัสผ่านปัจจุบัน</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">รหัสผ่านใหม่</label>
                                <input type="password" name="new_password" class="form-control" minlength="8" required>
                                <div class="form-text">อย่างน้อย 8 ตัวอักษร</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
                                <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                            </div>

                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-key me-1"></i>เปลี่ยนรหัสผ่าน
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Notification Settings -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-bell me-2"></i>การแจ้งเตือน</h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="action" value="update_notifications">

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input"
                                       type="checkbox"
                                       name="notify_email"
                                       id="notifyEmail"
                                       <?= $user['notify_email'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="notifyEmail">
                                    <i class="fas fa-envelope me-1"></i>รับแจ้งเตือนทางอีเมล
                                </label>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input"
                                       type="checkbox"
                                       name="notify_line"
                                       id="notifyLine"
                                       <?= $user['notify_line'] ? 'checked' : '' ?>
                                       <?= !$isLineLinked ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="notifyLine">
                                    <i class="fab fa-line me-1"></i>รับแจ้งเตือนทาง LINE
                                    <?php if (!$isLineLinked): ?>
                                        <a href="../line_connect.php" class="text-decoration-none small">(เชื่อมต่อ LINE ก่อน)</a>
                                    <?php endif; ?>
                                </label>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>บันทึก
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
