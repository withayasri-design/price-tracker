<?php

/**
 * Login Page
 *
 * Handles user authentication with email/password.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';

use Core\Auth;
use Core\Csrf;

// Redirect if already logged in
if (Auth::check()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::verify($_POST['csrf_token'] ?? '');

        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            throw new Exception('กรุณากรอกอีเมลและรหัสผ่าน');
        }

        // Find user by email
        $stmt = $pdo->prepare("
            SELECT user_id, email, password_hash, full_name, role, is_active
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception('อีเมลหรือรหัสผ่านไม่ถูกต้อง');
        }

        if (!$user['is_active']) {
            throw new Exception('บัญชีนี้ถูกระงับการใช้งาน');
        }

        if (!Auth::verifyPassword($password, $user['password_hash'])) {
            throw new Exception('อีเมลหรือรหัสผ่านไม่ถูกต้อง');
        }

        // Check if password needs rehash
        if (Auth::needsRehash($user['password_hash'])) {
            $newHash = Auth::hashPassword($password);
            $pdo->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :id")
                ->execute(['hash' => $newHash, 'id' => $user['user_id']]);
        }

        // Log in the user
        Auth::login(
            $user['user_id'],
            $user['email'],
            $user['role'],
            $user['full_name']
        );

        // Redirect to intended page or dashboard
        $redirectUrl = Auth::getRedirectAfterLogin();
        header('Location: ' . $redirectUrl);
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
    <title>เข้าสู่ระบบ - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            max-width: 420px;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="login-card">
            <div class="card shadow-lg border-0">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <div class="brand-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h4 class="fw-bold">Price Tracker</h4>
                        <p class="text-muted">เข้าสู่ระบบเพื่อติดตามราคาสินค้า</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (Auth::hasFlash('success')): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars(Auth::getFlash('success')) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-envelope me-1"></i>อีเมล
                            </label>
                            <input type="email"
                                   name="email"
                                   class="form-control form-control-lg"
                                   placeholder="you@example.com"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                   required
                                   autofocus>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">
                                <i class="fas fa-lock me-1"></i>รหัสผ่าน
                            </label>
                            <input type="password"
                                   name="password"
                                   class="form-control form-control-lg"
                                   placeholder="รหัสผ่านของคุณ"
                                   required>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
                            <i class="fas fa-sign-in-alt me-2"></i>เข้าสู่ระบบ
                        </button>
                    </form>

                    <hr class="my-4">

                    <div class="text-center">
                        <p class="mb-0">
                            ยังไม่มีบัญชี?
                            <a href="../register.php" class="text-decoration-none fw-bold">สมัครสมาชิก</a>
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
