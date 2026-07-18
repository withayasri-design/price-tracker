<?php

/**
 * Products Management Page
 *
 * Allows users to add, view, and manage their tracked products.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../modules/scraping/UrlParser.php';
require_once __DIR__ . '/../modules/tracking/TrackingService.php';

use Core\Auth;
use Core\Csrf;
use Modules\Tracking\TrackingService;

Auth::requireLogin();

$userId = Auth::userId();
$userName = Auth::fullName();

// Get user's tracked products
$service = new TrackingService($pdo);
$products = $service->getUserProducts($userId);

$csrfToken = Csrf::token();

// Platform info
$platformInfo = [
    'shopee' => ['name' => 'Shopee', 'color' => 'danger', 'icon' => 'store'],
    'lazada' => ['name' => 'Lazada', 'color' => 'primary', 'icon' => 'shopping-bag'],
    'tiktok' => ['name' => 'TikTok Shop', 'color' => 'dark', 'icon' => 'hashtag'],
    'jib' => ['name' => 'JIB', 'color' => 'warning', 'icon' => 'laptop'],
    'banana' => ['name' => 'Banana IT', 'color' => 'success', 'icon' => 'desktop'],
    'advice' => ['name' => 'Advice', 'color' => 'info', 'icon' => 'microchip'],
    'globalhouse' => ['name' => 'Global House', 'color' => 'secondary', 'icon' => 'home'],
    'homepro' => ['name' => 'HomePro', 'color' => 'warning', 'icon' => 'tools'],
    'thaiwatsadu' => ['name' => 'Thai Watsadu', 'color' => 'primary', 'icon' => 'warehouse'],
    'powerbuy' => ['name' => 'Power Buy', 'color' => 'danger', 'icon' => 'plug'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>จัดการสินค้า - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .product-img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
        }
        .product-img-placeholder {
            width: 80px;
            height: 80px;
            background: #f0f0f0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
        }
        .price-current {
            font-size: 1.2rem;
            font-weight: bold;
            color: #e53935;
        }
        .price-original {
            text-decoration: line-through;
            color: #999;
        }
        .discount-badge {
            background: #ff5722;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .target-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        .product-card {
            transition: box-shadow 0.2s;
        }
        .product-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .stock-out {
            opacity: 0.6;
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
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="../dashboard.php">
                            <i class="fas fa-home me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="../products.php">
                            <i class="fas fa-box me-1"></i>สินค้า
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i><?= htmlspecialchars($userName) ?>
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
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-box me-2"></i>สินค้าที่ติดตาม</h4>
                <p class="text-muted mb-0"><?= count($products) ?> รายการ</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="fas fa-plus me-1"></i>เพิ่มสินค้า
            </button>
        </div>

        <!-- Alert messages -->
        <div id="alertContainer"></div>

        <!-- Products Grid -->
        <?php if (empty($products)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                    <h5>ยังไม่มีสินค้าที่ติดตาม</h5>
                    <p class="text-muted mb-4">เริ่มติดตามราคาสินค้าโดยการเพิ่ม URL สินค้าจากร้านค้าออนไลน์</p>
                    <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addProductModal">
                        <i class="fas fa-plus me-2"></i>เพิ่มสินค้าแรก
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($products as $product):
                    $platform = $platformInfo[$product['platform']] ?? ['name' => $product['platform'], 'color' => 'secondary', 'icon' => 'store'];
                    $isOutOfStock = stripos($product['last_stock_status'] ?? '', 'out') !== false;
                ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card product-card h-100 <?= $isOutOfStock ? 'stock-out' : '' ?>">
                            <div class="card-body">
                                <div class="d-flex mb-3">
                                    <?php if ($product['image_url']): ?>
                                        <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="" class="product-img me-3">
                                    <?php else: ?>
                                        <div class="product-img-placeholder me-3">
                                            <i class="fas fa-image fa-2x"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-grow-1 overflow-hidden">
                                        <span class="badge bg-<?= $platform['color'] ?> mb-1">
                                            <i class="fas fa-<?= $platform['icon'] ?> me-1"></i><?= $platform['name'] ?>
                                        </span>
                                        <h6 class="mb-1 text-truncate" title="<?= htmlspecialchars($product['product_name'] ?? 'ไม่ทราบชื่อ') ?>">
                                            <?= htmlspecialchars($product['product_name'] ?? 'กำลังโหลด...') ?>
                                        </h6>
                                        <?php if ($product['label']): ?>
                                            <small class="text-muted"><i class="fas fa-tag me-1"></i><?= htmlspecialchars($product['label']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Price -->
                                <div class="mb-3">
                                    <?php if ($product['last_price']): ?>
                                        <div class="price-current">
                                            ฿<?= number_format($product['last_price'], 2) ?>
                                        </div>
                                        <?php if ($product['last_original_price'] && $product['last_original_price'] > $product['last_price']): ?>
                                            <span class="price-original">฿<?= number_format($product['last_original_price'], 2) ?></span>
                                            <span class="discount-badge ms-1">-<?= $product['current_discount_percent'] ?>%</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">รอดึงข้อมูลราคา...</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Target -->
                                <?php if ($product['target_price'] || $product['target_discount_percent']): ?>
                                    <div class="mb-3">
                                        <?php if ($product['target_price']): ?>
                                            <span class="target-badge">
                                                <i class="fas fa-bullseye me-1"></i>เป้า ฿<?= number_format($product['target_price'], 2) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($product['target_discount_percent']): ?>
                                            <span class="target-badge">
                                                <i class="fas fa-percent me-1"></i>เป้า ลด <?= $product['target_discount_percent'] ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Stock status -->
                                <?php if ($isOutOfStock): ?>
                                    <div class="mb-3">
                                        <span class="badge bg-secondary"><i class="fas fa-times me-1"></i>สินค้าหมด</span>
                                    </div>
                                <?php endif; ?>

                                <!-- Last checked -->
                                <div class="text-muted small mb-3">
                                    <i class="fas fa-clock me-1"></i>
                                    <?php if ($product['last_checked_at']): ?>
                                        อัปเดต <?= date('d/m/Y H:i', strtotime($product['last_checked_at'])) ?>
                                    <?php else: ?>
                                        ยังไม่เคยอัปเดต
                                    <?php endif; ?>
                                </div>

                                <!-- Actions -->
                                <div class="d-flex gap-2">
                                    <a href="../product_detail.php?id=<?= $product['tracking_id'] ?>" class="btn btn-outline-primary btn-sm flex-grow-1">
                                        <i class="fas fa-chart-line me-1"></i>ดูราคา
                                    </a>
                                    <a href="<?= htmlspecialchars($product['product_url']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm" title="ดูสินค้า">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                    <button class="btn btn-outline-secondary btn-sm" onclick="refreshProduct(<?= $product['tracking_id'] ?>)" title="รีเฟรช">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" onclick="deleteProduct(<?= $product['tracking_id'] ?>)" title="ลบ">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>เพิ่มสินค้า</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addProductForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">URL สินค้า <span class="text-danger">*</span></label>
                            <input type="url" name="url" class="form-control" placeholder="https://www.shopee.co.th/..." required>
                            <div class="form-text">
                                รองรับ: Shopee, Lazada, TikTok Shop, JIB, Banana IT, Advice, Global House, HomePro, Thai Watsadu, Power Buy
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ชื่อเรียก (ไม่บังคับ)</label>
                            <input type="text" name="label" class="form-control" placeholder="เช่น โน้ตบุ๊คเครื่องใหม่" maxlength="200">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">ราคาเป้าหมาย</label>
                                <div class="input-group">
                                    <span class="input-group-text">฿</span>
                                    <input type="number" name="target_price" class="form-control" placeholder="0.00" min="0" step="0.01">
                                </div>
                                <div class="form-text">แจ้งเตือนเมื่อราคาต่ำกว่านี้</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">ส่วนลดเป้าหมาย</label>
                                <div class="input-group">
                                    <input type="number" name="target_discount_percent" class="form-control" placeholder="0" min="1" max="100">
                                    <span class="input-group-text">%</span>
                                </div>
                                <div class="form-text">แจ้งเตือนเมื่อลดมากกว่านี้</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary" id="addProductBtn">
                            <i class="fas fa-plus me-1"></i>เพิ่มสินค้า
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        function showAlert(message, type = 'success') {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            container.appendChild(alert);
            setTimeout(() => alert.remove(), 5000);
        }

        // Add product form
        document.getElementById('addProductForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('addProductBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>กำลังเพิ่ม...';

            const formData = new FormData(this);
            formData.append('csrf_token', csrfToken);

            try {
                const response = await fetch('/api/products/add.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    showAlert(data.message);
                    bootstrap.Modal.getInstance(document.getElementById('addProductModal')).hide();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert(data.message, 'danger');
                }
            } catch (error) {
                showAlert('เกิดข้อผิดพลาด กรุณาลองใหม่', 'danger');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });

        // Refresh product
        async function refreshProduct(trackingId) {
            try {
                const response = await fetch('/api/products/refresh.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ tracking_id: trackingId, csrf_token: csrfToken })
                });
                const data = await response.json();
                showAlert(data.message, data.success ? 'success' : 'warning');
            } catch (error) {
                showAlert('เกิดข้อผิดพลาด', 'danger');
            }
        }

        // Delete product
        async function deleteProduct(trackingId) {
            if (!confirm('ต้องการหยุดติดตามสินค้านี้หรือไม่?')) return;

            try {
                const response = await fetch('/api/products/delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ tracking_id: trackingId, csrf_token: csrfToken })
                });
                const data = await response.json();

                if (data.success) {
                    showAlert(data.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert(data.message, 'danger');
                }
            } catch (error) {
                showAlert('เกิดข้อผิดพลาด', 'danger');
            }
        }
    </script>
</body>
</html>
