<?php

/**
 * Registration Page
 *
 * Handles new user registration.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';

use Core\Auth;
use Core\Csrf;

// Redirect if already logged in
if (Auth::check()) {
    header('Location: /pages/dashboard.php');
    exit;
}

$error = null;
$formData = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::verify($_POST['csrf_token'] ?? '');

        $formData = [
            'email' => trim($_POST['email'] ?? ''),
            'full_name' => trim($_POST['full_name'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'password_confirm' => $_POST['password_confirm'] ?? '',
        ];

        // Validation
        if (empty($formData['email'])) {
            throw new Exception('กรุณากรอกอีเมล');
        }

        if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
        }

        if (empty($formData['full_name'])) {
            throw new Exception('กรุณากรอกชื่อ-นามสกุล');
        }

        if (mb_strlen($formData['full_name']) < 2 || mb_strlen($formData['full_name']) > 150) {
            throw new Exception('ชื่อต้องมีความยาว 2-150 ตัวอักษร');
        }

        if (empty($formData['password'])) {
            throw new Exception('กรุณากรอกรหัสผ่าน');
        }

        if (strlen($formData['password']) < 8) {
            throw new Exception('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
        }

        if ($formData['password'] !== $formData['password_confirm']) {
            throw new Exception('รหัสผ่านไม่ตรงกัน');
        }

        // Check if email already exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $formData['email']]);

        if ($stmt->fetch()) {
            throw new Exception('อีเมลนี้ถูกใช้งานแล้ว');
        }

        // Create user
        $passwordHash = Auth::hashPassword($formData['password']);

        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, full_name, role, is_active)
            VALUES (:email, :password_hash, :full_name, 'user', 1)
        ");
        $stmt->execute([
            'email' => $formData['email'],
            'password_hash' => $passwordHash,
            'full_name' => $formData['full_name'],
        ]);

        $userId = (int) $pdo->lastInsertId();

        // Auto-login after registration
        Auth::login($userId, $formData['email'], 'user', $formData['full_name']);

        Auth::flash('success', 'ยินดีต้อนรับ! สมัครสมาชิกสำเร็จแล้ว');
        header('Location: /pages/dashboard.php');
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$csrfToken = Csrf::generate();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 2rem 0;
        }
        .register-card {
            max-width: 480px;
            margin: 0 auto;
        }
        .brand-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        .brand-icon i {
            font-size: 2rem;
            color: white;
        }
        .password-requirements {
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="register-card">
            <div class="card shadow-lg border-0">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <div class="brand-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h4 class="fw-bold">สมัครสมาชิก</h4>
                        <p class="text-muted">สร้างบัญชีเพื่อเริ่มติดตามราคาสินค้า</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-user me-1"></i>ชื่อ-นามสกุล
                            </label>
                            <input type="text"
                                   name="full_name"
                                   class="form-control form-control-lg"
                                   placeholder="ชื่อ นามสกุล"
                                   value="<?= htmlspecialchars($formData['full_name'] ?? '') ?>"
                                   required
                                   autofocus>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-envelope me-1"></i>อีเมล
                            </label>
                            <input type="email"
                                   name="email"
                                   class="form-control form-control-lg"
                                   placeholder="you@example.com"
                                   value="<?= htmlspecialchars($formData['email'] ?? '') ?>"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-lock me-1"></i>รหัสผ่าน
                            </label>
                            <input type="password"
                                   name="password"
                                   class="form-control form-control-lg"
                                   placeholder="รหัสผ่านอย่างน้อย 8 ตัวอักษร"
                                   minlength="8"
                                   required>
                            <div class="password-requirements text-muted mt-1">
                                <i class="fas fa-info-circle me-1"></i>ใช้ตัวอักษรอย่างน้อย 8 ตัว
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">
                                <i class="fas fa-lock me-1"></i>ยืนยันรหัสผ่าน
                            </label>
                            <input type="password"
                                   name="password_confirm"
                                   class="form-control form-control-lg"
                                   placeholder="กรอกรหัสผ่านอีกครั้ง"
                                   minlength="8"
                                   required>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
                            <i class="fas fa-user-plus me-2"></i>สมัครสมาชิก
                        </button>
                    </form>

                    <hr class="my-4">

                    <div class="text-center">
                        <p class="mb-0">
                            มีบัญชีอยู่แล้ว?
                            <a href="/pages/login.php" class="text-decoration-none fw-bold">เข้าสู่ระบบ</a>
                        </p>
                    </div>
                </div>
            </div>

            <p class="text-center text-white-50 mt-4 small">
                <i class="fas fa-shield-alt me-1"></i>
                ข้อมูลของคุณได้รับการปกป้องอย่างปลอดภัย
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
