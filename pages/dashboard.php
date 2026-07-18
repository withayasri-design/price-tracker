<?php

/**
 * User Dashboard
 *
 * Shows tracked products, recent alerts, and price statistics.
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

// Get tracked products count
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM user_tracking
    WHERE user_id = :user_id AND is_active = 1
");
$stmt->execute(['user_id' => $userId]);
$trackingCount = (int) $stmt->fetchColumn();

// Get unread alerts count
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM alerts a
    JOIN user_tracking ut ON a.tracking_id = ut.tracking_id
    WHERE ut.user_id = :user_id AND a.is_read = 0
");
$stmt->execute(['user_id' => $userId]);
$unreadAlertsCount = (int) $stmt->fetchColumn();

// Get user's tracked products with latest prices
$stmt = $pdo->prepare("
    SELECT
        ut.tracking_id,
        ut.label,
        ut.target_price,
        ut.target_discount_percent,
        tp.product_id,
        tp.platform,
        tp.product_name,
        tp.image_url,
        tp.product_url,
        tp.last_price,
        tp.last_original_price,
        tp.last_stock_status,
        tp.last_checked_at,
        CASE
            WHEN tp.last_original_price > 0
            THEN ROUND((1 - tp.last_price / tp.last_original_price) * 100, 1)
            ELSE 0
        END as discount_percent
    FROM user_tracking ut
    JOIN tracked_products tp ON ut.product_id = tp.product_id
    WHERE ut.user_id = :user_id AND ut.is_active = 1
    ORDER BY tp.last_checked_at DESC
    LIMIT 20
");
$stmt->execute(['user_id' => $userId]);
$trackedProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent alerts
$stmt = $pdo->prepare("
    SELECT
        a.alert_id,
        a.price_at_alert,
        a.alert_type,
        a.is_read,
        a.created_at,
        tp.product_name,
        tp.platform,
        tp.image_url,
        tp.product_url
    FROM alerts a
    JOIN user_tracking ut ON a.tracking_id = ut.tracking_id
    JOIN tracked_products tp ON ut.product_id = tp.product_id
    WHERE ut.user_id = :user_id
    ORDER BY a.created_at DESC
    LIMIT 10
");
$stmt->execute(['user_id' => $userId]);
$recentAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent price events for tracked products
$stmt = $pdo->prepare("
    SELECT
        pe.event_id,
        pe.event_type,
        pe.old_price,
        pe.new_price,
        pe.change_percent,
        pe.created_at,
        tp.product_name,
        tp.platform,
        tp.image_url,
        tp.product_url
    FROM price_events pe
    JOIN tracked_products tp ON pe.product_id = tp.product_id
    JOIN user_tracking ut ON tp.product_id = ut.product_id
    WHERE ut.user_id = :user_id AND ut.is_active = 1
    ORDER BY pe.created_at DESC
    LIMIT 10
");
$stmt->execute(['user_id' => $userId]);
$priceEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = Csrf::token();

// Platform badge colors
$platformColors = [
    'shopee' => 'danger',
    'lazada' => 'primary',
    'tiktok' => 'dark',
    'jib' => 'warning',
    'banana' => 'success',
    'advice' => 'info',
    'globalhouse' => 'secondary',
    'homepro' => 'warning',
    'thaiwatsadu' => 'primary',
    'powerbuy' => 'danger',
];

$eventTypeLabels = [
    'price_drop' => ['label' => 'ราคาลด', 'class' => 'success', 'icon' => 'arrow-down'],
    'price_increase' => ['label' => 'ราคาเพิ่ม', 'class' => 'danger', 'icon' => 'arrow-up'],
    'lowest_ever' => ['label' => 'ต่ำสุดเท่าที่เคย', 'class' => 'warning', 'icon' => 'star'],
    'flash_sale' => ['label' => 'Flash Sale', 'class' => 'info', 'icon' => 'bolt'],
    'back_in_stock' => ['label' => 'มีของแล้ว', 'class' => 'success', 'icon' => 'box'],
    'out_of_stock' => ['label' => 'หมด', 'class' => 'secondary', 'icon' => 'times'],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .product-img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
        }
        .price-current {
            font-size: 1.1rem;
            font-weight: bold;
            color: #e53935;
        }
        .price-original {
            text-decoration: line-through;
            color: #999;
            font-size: 0.85rem;
        }
        .discount-badge {
            background: #ff5722;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        .stat-card {
            border-left: 4px solid;
        }
        .stat-card.primary { border-color: #667eea; }
        .stat-card.success { border-color: #28a745; }
        .stat-card.warning { border-color: #ffc107; }
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
                        <a class="nav-link active" href="../dashboard.php">
                            <i class="fas fa-home me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../compare.php">
                            <i class="fas fa-balance-scale me-1"></i>เปรียบเทียบ
                        </a>
                    </li>
                    <?php if (Auth::isAdmin()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="../admin/master_products.php">
                            <i class="fas fa-cog me-1"></i>Admin
                        </a>
                    </li>
                    <?php endif; ?>
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
        <!-- Welcome & Flash messages -->
        <?php if (Auth::hasFlash('success')): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars(Auth::getFlash('success')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card stat-card primary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">สินค้าที่ติดตาม</h6>
                                <h3 class="mb-0"><?= number_format($trackingCount) ?></h3>
                            </div>
                            <div class="text-primary opacity-50">
                                <i class="fas fa-box fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card stat-card warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">แจ้งเตือนใหม่</h6>
                                <h3 class="mb-0"><?= number_format($unreadAlertsCount) ?></h3>
                            </div>
                            <div class="text-warning opacity-50">
                                <i class="fas fa-bell fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card stat-card success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">ราคาลดวันนี้</h6>
                                <h3 class="mb-0"><?= count(array_filter($priceEvents, fn($e) => $e['event_type'] === 'price_drop' && date('Y-m-d', strtotime($e['created_at'])) === date('Y-m-d'))) ?></h3>
                            </div>
                            <div class="text-success opacity-50">
                                <i class="fas fa-arrow-down fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Tracked Products -->
            <div class="col-lg-8 mb-4">
                <div class="card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-box me-2"></i>สินค้าที่ติดตาม</h5>
                        <a href="../products.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i>เพิ่มสินค้า
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($trackedProducts)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                <p class="text-muted mb-3">ยังไม่มีสินค้าที่ติดตาม</p>
                                <a href="../products.php" class="btn btn-primary">
                                    <i class="fas fa-plus me-1"></i>เพิ่มสินค้าแรก
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <tbody>
                                        <?php foreach ($trackedProducts as $product): ?>
                                            <tr>
                                                <td style="width: 80px;">
                                                    <img src="<?= htmlspecialchars($product['image_url'] ?: '/assets/img/no-image.png') ?>"
                                                         alt="" class="product-img">
                                                </td>
                                                <td>
                                                    <div class="d-flex justify-content-between">
                                                        <div>
                                                            <span class="badge bg-<?= $platformColors[$product['platform']] ?? 'secondary' ?> me-1">
                                                                <?= strtoupper($product['platform']) ?>
                                                            </span>
                                                            <a href="<?= htmlspecialchars($product['product_url']) ?>"
                                                               target="_blank"
                                                               class="text-decoration-none text-dark fw-medium">
                                                                <?= htmlspecialchars(mb_substr($product['product_name'] ?? 'ไม่ทราบชื่อ', 0, 50)) ?>
                                                                <?= mb_strlen($product['product_name'] ?? '') > 50 ? '...' : '' ?>
                                                            </a>
                                                            <?php if ($product['label']): ?>
                                                                <span class="badge bg-light text-dark ms-1"><?= htmlspecialchars($product['label']) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="mt-1 small text-muted">
                                                        <?php if ($product['target_price']): ?>
                                                            <i class="fas fa-bullseye me-1"></i>เป้าหมาย: <?= number_format((float)$product['target_price'], 2) ?> บาท
                                                        <?php elseif ($product['target_discount_percent']): ?>
                                                            <i class="fas fa-percent me-1"></i>เป้าหมาย: ลด <?= $product['target_discount_percent'] ?>%
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="text-end" style="width: 150px;">
                                                    <div class="price-current">
                                                        <?= number_format((float)($product['last_price'] ?? 0), 2) ?> บาท
                                                    </div>
                                                    <?php if ($product['last_original_price'] && (float)$product['last_original_price'] > (float)$product['last_price']): ?>
                                                        <div class="price-original"><?= number_format((float)$product['last_original_price'], 2) ?></div>
                                                        <span class="discount-badge">-<?= $product['discount_percent'] ?>%</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Events -->
            <div class="col-lg-4 mb-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>กิจกรรมล่าสุด</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($priceEvents)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-clock fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0 small">ยังไม่มีกิจกรรม</p>
                            </div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach (array_slice($priceEvents, 0, 5) as $event):
                                    $eventInfo = $eventTypeLabels[$event['event_type']] ?? ['label' => $event['event_type'], 'class' => 'secondary', 'icon' => 'info'];
                                ?>
                                    <li class="list-group-item">
                                        <div class="d-flex">
                                            <div class="me-2">
                                                <span class="badge bg-<?= $eventInfo['class'] ?> rounded-circle p-2">
                                                    <i class="fas fa-<?= $eventInfo['icon'] ?>"></i>
                                                </span>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="small">
                                                    <strong class="text-<?= $eventInfo['class'] ?>"><?= $eventInfo['label'] ?></strong>
                                                    <?php if ($event['change_percent']): ?>
                                                        <span class="text-muted">(<?= $event['change_percent'] > 0 ? '+' : '' ?><?= $event['change_percent'] ?>%)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small text-truncate" style="max-width: 200px;">
                                                    <?= htmlspecialchars($event['product_name'] ?? 'ไม่ทราบชื่อ') ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <?php if ($event['old_price'] && $event['new_price']): ?>
                                                        <?= number_format((float)$event['old_price'], 0) ?> -> <?= number_format((float)$event['new_price'], 0) ?> บาท
                                                    <?php endif; ?>
                                                    <span class="ms-1"><?= date('d/m H:i', strtotime($event['created_at'])) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
