<?php

/**
 * Admin Products Management
 *
 * Search, view and manage tracked products.
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

        if ($action === 'add_by_keyword') {
            // Add product by keyword/name (manual entry)
            $productName = trim($_POST['product_name'] ?? '');
            $platform = $_POST['platform'] ?? '';
            $productUrl = trim($_POST['product_url'] ?? '');

            if (empty($productName)) {
                throw new Exception('กรุณากรอกชื่อสินค้า');
            }
            if (empty($platform)) {
                throw new Exception('กรุณาเลือก Platform');
            }

            // Generate a placeholder product ID if no URL
            $platformProductId = 'manual_' . time() . '_' . mt_rand(1000, 9999);

            // If URL provided, try to extract product ID
            if (!empty($productUrl)) {
                // Load adapter to extract product ID
                $adapterFile = __DIR__ . '/../../modules/scraping/adapters/' . ucfirst($platform) . 'Adapter.php';
                if (file_exists($adapterFile)) {
                    require_once __DIR__ . '/../../modules/scraping/PlatformAdapterInterface.php';
                    require_once __DIR__ . '/../../modules/scraping/BaseAdapter.php';
                    require_once __DIR__ . '/../../modules/scraping/ScrapedProduct.php';
                    require_once $adapterFile;

                    $adapterClass = 'Modules\\Scraping\\Adapters\\' . ucfirst($platform) . 'Adapter';
                    if (class_exists($adapterClass)) {
                        $adapter = new $adapterClass();
                        $extractedId = $adapter->extractProductId($productUrl);
                        if ($extractedId) {
                            $platformProductId = $extractedId;
                        }
                    }
                }
            }

            // Check if already exists
            $stmt = $pdo->prepare("SELECT product_id FROM tracked_products WHERE platform = :platform AND platform_product_id = :pid");
            $stmt->execute(['platform' => $platform, 'pid' => $platformProductId]);
            if ($stmt->fetch()) {
                throw new Exception('สินค้านี้มีอยู่ในระบบแล้ว');
            }

            // Insert product
            $stmt = $pdo->prepare("
                INSERT INTO tracked_products (platform, platform_product_id, product_url, product_name, is_active, created_at)
                VALUES (:platform, :pid, :url, :name, 1, NOW())
            ");
            $stmt->execute([
                'platform' => $platform,
                'pid' => $platformProductId,
                'url' => $productUrl ?: '#',
                'name' => $productName,
            ]);

            $message = "เพิ่มสินค้า \"{$productName}\" เรียบร้อย";

        } elseif ($action === 'toggle_status') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId > 0) {
                $stmt = $pdo->prepare("UPDATE tracked_products SET is_active = NOT is_active WHERE product_id = :id");
                $stmt->execute(['id' => $productId]);
                $message = 'อัพเดตสถานะสินค้าเรียบร้อย';
            }

        } elseif ($action === 'delete') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId > 0) {
                $stmt = $pdo->prepare("DELETE FROM tracked_products WHERE product_id = :id");
                $stmt->execute(['id' => $productId]);
                $message = 'ลบสินค้าเรียบร้อย';
            }
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
$platformFilter = $_GET['platform'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Build query
$conditions = [];
$params = [];

if ($search !== '') {
    // Support multiple keywords
    $keywords = preg_split('/\s+/', $search);
    $keywordConditions = [];

    foreach ($keywords as $i => $keyword) {
        if ($keyword === '') continue;
        $paramName = "search{$i}";
        $keywordConditions[] = "product_name LIKE :{$paramName}";
        $params[$paramName] = "%{$keyword}%";
    }

    if (!empty($keywordConditions)) {
        $conditions[] = '(' . implode(' AND ', $keywordConditions) . ')';
    }
}

if ($platformFilter !== '') {
    $conditions[] = "platform = :platform";
    $params['platform'] = $platformFilter;
}

if ($statusFilter !== '') {
    $conditions[] = "is_active = :status";
    $params['status'] = $statusFilter === 'active' ? 1 : 0;
}

$where = !empty($conditions) ? "WHERE " . implode(' AND ', $conditions) : '';

// Get total count
$countSql = "SELECT COUNT(*) FROM tracked_products {$where}";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalProducts = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalProducts / $perPage));

// Get products
$sql = "
    SELECT
        tp.product_id,
        tp.platform,
        tp.platform_product_id,
        tp.product_url,
        tp.product_name,
        tp.last_price,
        tp.last_original_price,
        tp.last_stock_status,
        tp.last_checked_at,
        tp.is_active,
        tp.created_at,
        (SELECT COUNT(*) FROM user_tracking ut WHERE ut.product_id = tp.product_id AND ut.is_active = 1) as tracking_count,
        (SELECT COUNT(*) FROM price_history ph WHERE ph.product_id = tp.product_id) as history_count
    FROM tracked_products tp
    {$where}
    ORDER BY tp.created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stmt = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(is_active = 1) as active,
        SUM(last_price IS NOT NULL) as with_price
    FROM tracked_products
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Platform stats
$stmt = $pdo->query("SELECT platform, COUNT(*) as count FROM tracked_products GROUP BY platform ORDER BY count DESC");
$platformStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Platform info
$platforms = [
    'jib' => ['name' => 'JIB', 'color' => 'warning'],
    'banana' => ['name' => 'Banana IT', 'color' => 'success'],
    'advice' => ['name' => 'Advice', 'color' => 'info'],
    'powerbuy' => ['name' => 'Power Buy', 'color' => 'danger'],
    'globalhouse' => ['name' => 'Global House', 'color' => 'secondary'],
    'homepro' => ['name' => 'HomePro', 'color' => 'warning'],
    'thaiwatsadu' => ['name' => 'Thai Watsadu', 'color' => 'primary'],
    'lazada' => ['name' => 'Lazada', 'color' => 'primary'],
    'shopee' => ['name' => 'Shopee', 'color' => 'danger'],
    'tiktok' => ['name' => 'TikTok Shop', 'color' => 'dark'],
];

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>จัดการสินค้า - Admin - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .product-name {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .product-row:hover {
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
                <li class="breadcrumb-item active">จัดการสินค้า</li>
            </ol>
        </nav>

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-boxes me-2"></i>จัดการสินค้า</h2>
                <p class="text-muted mb-0">ค้นหา เพิ่ม และจัดการสินค้าในระบบ</p>
            </div>
            <div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="fas fa-plus me-1"></i>เพิ่มสินค้า
                </button>
                <a href="index.php" class="btn btn-outline-secondary ms-2">
                    <i class="fas fa-arrow-left me-1"></i>กลับ
                </a>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="h3 mb-0 text-primary"><?= number_format((int) $stats['total']) ?></div>
                        <small class="text-muted">สินค้าทั้งหมด</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="h3 mb-0 text-success"><?= number_format((int) $stats['active']) ?></div>
                        <small class="text-muted">Active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="h3 mb-0 text-info"><?= number_format((int) $stats['with_price']) ?></div>
                        <small class="text-muted">มีราคาแล้ว</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-4">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" name="search"
                                   placeholder="ค้นหาด้วย keywords..."
                                   value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <small class="text-muted">เช่น: iPhone 15 Pro</small>
                    </div>
                    <div class="col-md-3">
                        <select name="platform" class="form-select">
                            <option value="">-- ทุก Platform --</option>
                            <?php foreach ($platforms as $key => $info): ?>
                            <option value="<?= $key ?>" <?= $platformFilter === $key ? 'selected' : '' ?>>
                                <?= $info['name'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">-- ทุกสถานะ --</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i>ค้นหา
                        </button>
                        <?php if ($search || $platformFilter || $statusFilter): ?>
                        <a href="products.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i>ล้าง
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Products Table -->
        <div class="card">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        รายการสินค้า
                        <?php if ($search): ?>
                        <span class="badge bg-secondary ms-2">Keywords: <?= htmlspecialchars($search) ?></span>
                        <?php endif; ?>
                        <?php if ($platformFilter): ?>
                        <span class="badge bg-<?= $platforms[$platformFilter]['color'] ?? 'secondary' ?> ms-2">
                            <?= $platforms[$platformFilter]['name'] ?? $platformFilter ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($statusFilter): ?>
                        <span class="badge bg-<?= $statusFilter === 'active' ? 'success' : 'danger' ?> ms-2">
                            <?= $statusFilter === 'active' ? 'Active' : 'Inactive' ?>
                        </span>
                        <?php endif; ?>
                    </h6>
                    <span class="text-muted small">
                        แสดง <?= count($products) ?> จาก <?= number_format($totalProducts) ?> รายการ
                    </span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Platform</th>
                            <th>ชื่อสินค้า</th>
                            <th class="text-end">ราคา</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-center">ติดตาม</th>
                            <th>ตรวจสอบล่าสุด</th>
                            <th class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">ไม่พบสินค้า</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($products as $product): ?>
                        <tr class="product-row">
                            <td><?= $product['product_id'] ?></td>
                            <td>
                                <span class="badge bg-<?= $platforms[$product['platform']]['color'] ?? 'secondary' ?>">
                                    <?= $platforms[$product['platform']]['name'] ?? $product['platform'] ?>
                                </span>
                            </td>
                            <td>
                                <div class="product-name" title="<?= htmlspecialchars($product['product_name'] ?? '') ?>">
                                    <?php if ($product['product_url'] && $product['product_url'] !== '#'): ?>
                                    <a href="<?= htmlspecialchars($product['product_url']) ?>" target="_blank" class="text-decoration-none">
                                        <?= htmlspecialchars($product['product_name'] ?? 'ไม่มีชื่อ') ?>
                                        <i class="fas fa-external-link-alt fa-xs ms-1"></i>
                                    </a>
                                    <?php else: ?>
                                    <?= htmlspecialchars($product['product_name'] ?? 'ไม่มีชื่อ') ?>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">ID: <?= htmlspecialchars($product['platform_product_id']) ?></small>
                            </td>
                            <td class="text-end">
                                <?php if ($product['last_price']): ?>
                                <span class="fw-bold text-primary">฿<?= number_format((float) $product['last_price'], 2) ?></span>
                                <?php if ($product['last_original_price'] && $product['last_original_price'] > $product['last_price']): ?>
                                <br><small class="text-muted text-decoration-line-through">
                                    ฿<?= number_format((float) $product['last_original_price'], 2) ?>
                                </small>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($product['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info"><?= number_format($product['tracking_count']) ?></span>
                            </td>
                            <td>
                                <?php if ($product['last_checked_at']): ?>
                                <small><?= date('d/m/Y H:i', strtotime($product['last_checked_at'])) ?></small>
                                <?php else: ?>
                                <small class="text-muted">ยังไม่เคย</small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="../product_detail.php?id=<?= $product['product_id'] ?>" class="btn btn-outline-info" title="ดูรายละเอียด">
                                        <i class="fas fa-chart-line"></i>
                                    </a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                        <button type="submit" class="btn btn-outline-<?= $product['is_active'] ? 'warning' : 'success' ?>"
                                                title="<?= $product['is_active'] ? 'ปิดการใช้งาน' : 'เปิดการใช้งาน' ?>">
                                            <i class="fas fa-<?= $product['is_active'] ? 'ban' : 'check' ?>"></i>
                                        </button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('ยืนยันการลบสินค้านี้? ข้อมูลประวัติราคาจะถูกลบด้วย');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="ลบ">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
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
                            <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&platform=<?= urlencode($platformFilter) ?>&status=<?= urlencode($statusFilter) ?>">
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
                            <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&platform=<?= urlencode($platformFilter) ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&platform=<?= urlencode($platformFilter) ?>&status=<?= urlencode($statusFilter) ?>">
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

    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="add_by_keyword">

                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>เพิ่มสินค้าใหม่</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">ชื่อสินค้า / Keywords <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="product_name" required
                                   placeholder="เช่น: iPhone 15 Pro Max 256GB">
                            <small class="text-muted">ใส่ชื่อสินค้าที่ต้องการติดตาม</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Platform <span class="text-danger">*</span></label>
                            <select name="platform" class="form-select" required>
                                <option value="">-- เลือก Platform --</option>
                                <?php foreach ($platforms as $key => $info): ?>
                                <option value="<?= $key ?>"><?= $info['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">URL สินค้า (ถ้ามี)</label>
                            <input type="url" class="form-control" name="product_url"
                                   placeholder="https://...">
                            <small class="text-muted">ใส่ URL เพื่อให้ระบบดึงข้อมูลราคาได้</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i>เพิ่มสินค้า
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
