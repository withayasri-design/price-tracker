<?php

/**
 * Cross-Platform Price Comparison
 *
 * Shows products matched across platforms for price comparison.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';

use Core\Auth;
use Core\Csrf;

Auth::requireLogin();

$userId = Auth::userId();
$userName = Auth::fullName();

// Get master products that have multiple platform matches for this user
$stmt = $pdo->prepare("
    SELECT
        mp.master_id,
        mp.canonical_name,
        mp.category,
        COUNT(DISTINCT pmm.product_id) as platform_count,
        MIN(tp.last_price) as min_price,
        MAX(tp.last_price) as max_price,
        GROUP_CONCAT(DISTINCT tp.platform) as platforms
    FROM master_products mp
    JOIN product_master_mapping pmm ON mp.master_id = pmm.master_id AND pmm.is_active = 1
    JOIN tracked_products tp ON pmm.product_id = tp.product_id AND tp.is_active = 1
    JOIN user_tracking ut ON tp.product_id = ut.product_id AND ut.is_active = 1
    WHERE ut.user_id = :user_id
    GROUP BY mp.master_id
    HAVING platform_count >= 1
    ORDER BY platform_count DESC, mp.canonical_name ASC
    LIMIT 50
");
$stmt->execute(['user_id' => $userId]);
$masterProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected master product details if any
$selectedMasterId = isset($_GET['master_id']) ? (int) $_GET['master_id'] : null;
$comparisonProducts = [];

if ($selectedMasterId) {
    $stmt = $pdo->prepare("
        SELECT
            tp.product_id,
            tp.platform,
            tp.product_name,
            tp.product_url,
            tp.image_url,
            tp.last_price,
            tp.last_original_price,
            tp.last_stock_status,
            tp.last_checked_at,
            pmm.confidence_score,
            CASE
                WHEN tp.last_original_price > 0
                THEN ROUND((1 - tp.last_price / tp.last_original_price) * 100, 1)
                ELSE 0
            END as discount_percent
        FROM product_master_mapping pmm
        JOIN tracked_products tp ON pmm.product_id = tp.product_id AND tp.is_active = 1
        WHERE pmm.master_id = :master_id AND pmm.is_active = 1
        ORDER BY tp.last_price ASC
    ");
    $stmt->execute(['master_id' => $selectedMasterId]);
    $comparisonProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get master product info
    $stmt = $pdo->prepare("SELECT * FROM master_products WHERE master_id = :id");
    $stmt->execute(['id' => $selectedMasterId]);
    $selectedMaster = $stmt->fetch(PDO::FETCH_ASSOC);
}

$csrfToken = Csrf::token();

$platformInfo = [
    'shopee' => ['name' => 'Shopee', 'color' => '#EE4D2D', 'class' => 'danger'],
    'lazada' => ['name' => 'Lazada', 'color' => '#0F146D', 'class' => 'primary'],
    'tiktok' => ['name' => 'TikTok Shop', 'color' => '#000000', 'class' => 'dark'],
    'jib' => ['name' => 'JIB', 'color' => '#FF6600', 'class' => 'warning'],
    'banana' => ['name' => 'Banana IT', 'color' => '#28a745', 'class' => 'success'],
    'advice' => ['name' => 'Advice', 'color' => '#17a2b8', 'class' => 'info'],
    'globalhouse' => ['name' => 'Global House', 'color' => '#008000', 'class' => 'success'],
    'homepro' => ['name' => 'HomePro', 'color' => '#FF8C00', 'class' => 'warning'],
    'thaiwatsadu' => ['name' => 'Thai Watsadu', 'color' => '#0066CC', 'class' => 'primary'],
    'powerbuy' => ['name' => 'Power Buy', 'color' => '#CC0000', 'class' => 'danger'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เปรียบเทียบราคา - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .product-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .product-card.best-price {
            border: 2px solid #28a745;
        }
        .product-card.best-price::before {
            content: 'ราคาต่ำสุด';
            position: absolute;
            top: -10px;
            left: 10px;
            background: #28a745;
            color: white;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        .price-current {
            font-size: 1.5rem;
            font-weight: bold;
            color: #e53935;
        }
        .price-original {
            text-decoration: line-through;
            color: #999;
        }
        .savings-badge {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: bold;
        }
        .platform-logo {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        .comparison-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .product-img {
            width: 100px;
            height: 100px;
            object-fit: contain;
            border-radius: 8px;
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
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../products.php">
                    <i class="fas fa-box me-1"></i>สินค้า
                </a>
                <a class="nav-link active" href="../compare.php">
                    <i class="fas fa-balance-scale me-1"></i>เปรียบเทียบ
                </a>
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-home me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <?php if ($selectedMasterId && !empty($comparisonProducts)): ?>
            <!-- Comparison View -->
            <div class="comparison-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1"><?= htmlspecialchars($selectedMaster['canonical_name'] ?? 'เปรียบเทียบราคา') ?></h4>
                        <p class="mb-0 opacity-75">
                            <i class="fas fa-tag me-1"></i><?= htmlspecialchars($selectedMaster['category'] ?? 'ทั่วไป') ?>
                            <span class="mx-2">|</span>
                            <i class="fas fa-store me-1"></i><?= count($comparisonProducts) ?> ร้านค้า
                        </p>
                    </div>
                    <a href="../compare.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-1"></i>กลับ
                    </a>
                </div>

                <?php
                $minPrice = min(array_column($comparisonProducts, 'last_price'));
                $maxPrice = max(array_column($comparisonProducts, 'last_price'));
                $savings = $maxPrice - $minPrice;
                ?>
                <?php if ($savings > 0): ?>
                    <div class="mt-3 p-3 bg-white bg-opacity-10 rounded">
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="h5 mb-0">฿<?= number_format($minPrice, 0) ?></div>
                                <small class="opacity-75">ราคาต่ำสุด</small>
                            </div>
                            <div class="col-4">
                                <div class="h5 mb-0">฿<?= number_format($maxPrice, 0) ?></div>
                                <small class="opacity-75">ราคาสูงสุด</small>
                            </div>
                            <div class="col-4">
                                <div class="h5 mb-0 text-warning">฿<?= number_format($savings, 0) ?></div>
                                <small class="opacity-75">ประหยัดได้</small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Product Cards -->
            <div class="row">
                <?php foreach ($comparisonProducts as $index => $product):
                    $platform = $platformInfo[$product['platform']] ?? ['name' => $product['platform'], 'color' => '#666', 'class' => 'secondary'];
                    $isBestPrice = $product['last_price'] == $minPrice;
                ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card product-card h-100 position-relative <?= $isBestPrice ? 'best-price' : '' ?>">
                            <div class="card-body">
                                <div class="d-flex align-items-start mb-3">
                                    <div class="platform-logo me-3" style="background: <?= $platform['color'] ?>">
                                        <?= strtoupper(substr($product['platform'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <h6 class="mb-0"><?= $platform['name'] ?></h6>
                                        <small class="text-muted">
                                            <?php if ($product['last_stock_status'] === 'in_stock'): ?>
                                                <i class="fas fa-check-circle text-success me-1"></i>มีสินค้า
                                            <?php elseif ($product['last_stock_status'] === 'out_of_stock'): ?>
                                                <i class="fas fa-times-circle text-danger me-1"></i>สินค้าหมด
                                            <?php else: ?>
                                                <i class="fas fa-question-circle text-muted me-1"></i>ไม่ทราบสถานะ
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>

                                <?php if ($product['image_url']): ?>
                                    <div class="text-center mb-3">
                                        <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="" class="product-img">
                                    </div>
                                <?php endif; ?>

                                <p class="small text-muted mb-2" style="height: 40px; overflow: hidden;">
                                    <?= htmlspecialchars(mb_substr($product['product_name'] ?? '', 0, 80)) ?>
                                </p>

                                <div class="text-center mb-3">
                                    <div class="price-current">฿<?= number_format($product['last_price'], 0) ?></div>
                                    <?php if ($product['last_original_price'] && $product['last_original_price'] > $product['last_price']): ?>
                                        <div class="price-original">฿<?= number_format($product['last_original_price'], 0) ?></div>
                                        <span class="badge bg-danger">ลด <?= $product['discount_percent'] ?>%</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($isBestPrice && count($comparisonProducts) > 1): ?>
                                    <div class="text-center mb-3">
                                        <span class="savings-badge">
                                            <i class="fas fa-piggy-bank me-1"></i>ประหยัด ฿<?= number_format($savings, 0) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <small class="text-muted d-block mb-3">
                                    <i class="fas fa-clock me-1"></i>อัปเดต: <?= $product['last_checked_at'] ? date('d/m H:i', strtotime($product['last_checked_at'])) : 'ยังไม่เคย' ?>
                                </small>

                                <a href="<?= htmlspecialchars($product['product_url']) ?>" target="_blank" class="btn btn-<?= $platform['class'] ?> w-100">
                                    <i class="fas fa-external-link-alt me-1"></i>ดูสินค้า
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <!-- Product List -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4><i class="fas fa-balance-scale me-2"></i>เปรียบเทียบราคาข้ามแพลตฟอร์ม</h4>
            </div>

            <?php if (empty($masterProducts)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-search fa-4x text-muted mb-3"></i>
                        <h5>ยังไม่มีสินค้าที่จับคู่ข้ามแพลตฟอร์ม</h5>
                        <p class="text-muted mb-4">
                            เพิ่มสินค้าชิ้นเดียวกันจากหลายแพลตฟอร์ม ระบบจะจับคู่และแสดงการเปรียบเทียบราคาให้อัตโนมัติ
                        </p>
                        <a href="../products.php" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i>เพิ่มสินค้า
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($masterProducts as $master): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <a href="?master_id=<?= $master['master_id'] ?>" class="card text-decoration-none product-card h-100">
                                <div class="card-body">
                                    <h6 class="card-title text-dark"><?= htmlspecialchars($master['canonical_name']) ?></h6>
                                    <p class="text-muted small mb-2">
                                        <i class="fas fa-tag me-1"></i><?= htmlspecialchars($master['category'] ?: 'ทั่วไป') ?>
                                    </p>
                                    <div class="d-flex flex-wrap gap-1 mb-3">
                                        <?php foreach (explode(',', $master['platforms']) as $p):
                                            $pInfo = $platformInfo[trim($p)] ?? ['class' => 'secondary'];
                                        ?>
                                            <span class="badge bg-<?= $pInfo['class'] ?>"><?= strtoupper(trim($p)) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <div class="text-success fw-bold">฿<?= number_format($master['min_price'], 0) ?></div>
                                            <small class="text-muted">ต่ำสุด</small>
                                        </div>
                                        <div class="col-6">
                                            <div class="text-danger fw-bold">฿<?= number_format($master['max_price'], 0) ?></div>
                                            <small class="text-muted">สูงสุด</small>
                                        </div>
                                    </div>
                                    <?php if ($master['max_price'] > $master['min_price']): ?>
                                        <div class="text-center mt-2">
                                            <span class="badge bg-warning text-dark">
                                                ประหยัดได้ ฿<?= number_format($master['max_price'] - $master['min_price'], 0) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer bg-white text-center">
                                    <small class="text-primary">
                                        <i class="fas fa-chart-bar me-1"></i>ดูเปรียบเทียบ <?= $master['platform_count'] ?> ร้านค้า
                                    </small>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
