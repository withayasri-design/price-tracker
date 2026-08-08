<?php

/**
 * Admin Users Management
 *
 * View and manage system users.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';

use Core\Auth;
use Core\Csrf;

Auth::requireAdmin();

$csrfToken = Csrf::token();

// Handle actions
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::verify($_POST['csrf_token'] ?? '');

        $action = $_POST['action'] ?? '';
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($action === 'toggle_status' && $userId > 0) {
            // Don't allow deactivating yourself
            if ($userId === Auth::userId()) {
                throw new Exception('ไม่สามารถปิดการใช้งานบัญชีตัวเองได้');
            }

            $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active, updated_at = NOW() WHERE user_id = :id");
            $stmt->execute(['id' => $userId]);
            $message = 'อัพเดตสถานะผู้ใช้เรียบร้อย';
        } elseif ($action === 'toggle_role' && $userId > 0) {
            // Don't allow changing your own role
            if ($userId === Auth::userId()) {
                throw new Exception('ไม่สามารถเปลี่ยน role ตัวเองได้');
            }

            $stmt = $pdo->prepare("UPDATE users SET role = IF(role = 'admin', 'user', 'admin'), updated_at = NOW() WHERE user_id = :id");
            $stmt->execute(['id' => $userId]);
            $message = 'อัพเดต role เรียบร้อย';
        } elseif ($action === 'delete' && $userId > 0) {
            // Don't allow deleting yourself
            if ($userId === Auth::userId()) {
                throw new Exception('ไม่สามารถลบบัญชีตัวเองได้');
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = :id");
            $stmt->execute(['id' => $userId]);
            $message = 'ลบผู้ใช้เรียบร้อย';
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Search
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';

// Build query
$where = '';
$params = [];
$conditions = [];

if ($search !== '') {
    // Support multiple keywords separated by space
    $keywords = preg_split('/\s+/', $search);
    $keywordConditions = [];

    foreach ($keywords as $i => $keyword) {
        if ($keyword === '') continue;
        $paramName = "search{$i}";
        $keywordConditions[] = "(email LIKE :{$paramName} OR full_name LIKE :{$paramName})";
        $params[$paramName] = "%{$keyword}%";
    }

    if (!empty($keywordConditions)) {
        $conditions[] = '(' . implode(' AND ', $keywordConditions) . ')';
    }
}

if ($roleFilter !== '' && in_array($roleFilter, ['admin', 'user'])) {
    $conditions[] = "role = :role";
    $params['role'] = $roleFilter;
}

if (!empty($conditions)) {
    $where = "WHERE " . implode(' AND ', $conditions);
}

// Get total count
$countSql = "SELECT COUNT(*) FROM users {$where}";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalUsers = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalUsers / $perPage));

// Get users
$sql = "
    SELECT
        u.user_id,
        u.email,
        u.full_name,
        u.role,
        u.is_active,
        u.line_user_id,
        u.created_at,
        u.updated_at,
        (SELECT COUNT(*) FROM user_tracking ut WHERE ut.user_id = u.user_id AND ut.is_active = 1) as tracking_count
    FROM users u
    {$where}
    ORDER BY u.created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stmt = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(is_active = 1) as active,
        SUM(role = 'admin') as admins,
        SUM(line_user_id IS NOT NULL) as line_connected
    FROM users
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>จัดการผู้ใช้ - Admin - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .user-row:hover {
            background-color: #f8f9fa;
        }
    </style>
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
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="../dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../products.php"><i class="fas fa-box me-1"></i>สินค้า</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../compare.php"><i class="fas fa-balance-scale me-1"></i>เปรียบเทียบ</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php"><i class="fas fa-cog me-1"></i>Admin</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-user-shield me-1"></i><?= htmlspecialchars(Auth::fullName()) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user me-2"></i>โปรไฟล์</a></li>
                            <li><a class="dropdown-item" href="../line_connect.php"><i class="fab fa-line me-2"></i>เชื่อมต่อ LINE</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>ออกจากระบบ</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
                <li class="breadcrumb-item active">จัดการผู้ใช้</li>
            </ol>
        </nav>

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-users me-2"></i>จัดการผู้ใช้</h2>
                <p class="text-muted mb-0">ดูและจัดการผู้ใช้ในระบบ</p>
            </div>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>กลับ
            </a>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <div class="h3 mb-0 text-primary"><?= number_format((int) $stats['total']) ?></div>
                        <small class="text-muted">ผู้ใช้ทั้งหมด</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <div class="h3 mb-0 text-success"><?= number_format((int) $stats['active']) ?></div>
                        <small class="text-muted">Active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <div class="h3 mb-0 text-warning"><?= number_format((int) $stats['admins']) ?></div>
                        <small class="text-muted">Admins</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <div class="h3 mb-0 text-info"><?= number_format((int) $stats['line_connected']) ?></div>
                        <small class="text-muted">LINE Connected</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-5">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" name="search"
                                   placeholder="ค้นหาด้วย keywords (คั่นด้วย space)..."
                                   value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <small class="text-muted">เช่น: john admin@email</small>
                    </div>
                    <div class="col-md-3">
                        <select name="role" class="form-select">
                            <option value="">-- ทุก Role --</option>
                            <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>User</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i>ค้นหา
                        </button>
                        <?php if ($search || $roleFilter): ?>
                        <a href="users.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i>ล้าง
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        รายการผู้ใช้
                        <?php if ($search): ?>
                        <span class="badge bg-secondary ms-2">Keywords: <?= htmlspecialchars($search) ?></span>
                        <?php endif; ?>
                        <?php if ($roleFilter): ?>
                        <span class="badge bg-info ms-2">Role: <?= htmlspecialchars($roleFilter) ?></span>
                        <?php endif; ?>
                    </h6>
                    <span class="text-muted small">
                        แสดง <?= count($users) ?> จาก <?= number_format($totalUsers) ?> รายการ
                    </span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>ผู้ใช้</th>
                            <th>Role</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center">LINE</th>
                            <th class="text-center">ติดตาม</th>
                            <th>สร้างเมื่อ</th>
                            <th>อัพเดตล่าสุด</th>
                            <th class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">ไม่พบผู้ใช้</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($users as $user): ?>
                        <tr class="user-row">
                            <td><?= $user['user_id'] ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($user['full_name'] ?: '-') ?></div>
                                <small class="text-muted"><?= htmlspecialchars($user['email']) ?></small>
                            </td>
                            <td>
                                <?php if ($user['role'] === 'admin'): ?>
                                <span class="badge bg-warning text-dark"><i class="fas fa-shield-alt me-1"></i>Admin</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">User</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($user['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($user['line_user_id']): ?>
                                <span class="text-success"><i class="fab fa-line fa-lg"></i></span>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info"><?= number_format($user['tracking_count']) ?></span>
                            </td>
                            <td>
                                <small><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></small>
                            </td>
                            <td>
                                <small><?= date('d/m/Y H:i', strtotime($user['updated_at'])) ?></small>
                            </td>
                            <td class="text-center">
                                <?php if ($user['user_id'] !== Auth::userId()): ?>
                                <div class="btn-group btn-group-sm">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                        <button type="submit" class="btn btn-outline-<?= $user['is_active'] ? 'warning' : 'success' ?>"
                                                title="<?= $user['is_active'] ? 'ปิดการใช้งาน' : 'เปิดการใช้งาน' ?>">
                                            <i class="fas fa-<?= $user['is_active'] ? 'ban' : 'check' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="toggle_role">
                                        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                        <button type="submit" class="btn btn-outline-info"
                                                title="<?= $user['role'] === 'admin' ? 'เปลี่ยนเป็น User' : 'เปลี่ยนเป็น Admin' ?>">
                                            <i class="fas fa-user-cog"></i>
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('ยืนยันการลบผู้ใช้นี้?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="ลบ">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <span class="text-muted small">คุณ</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white">
                <nav>
                    <ul class="pagination pagination-sm mb-0 justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($roleFilter) ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
