<?php

/**
 * Product Detail Page
 *
 * Shows product info, price history chart, and tracking settings.
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

// Get tracking ID from URL
$trackingId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($trackingId <= 0) {
    header('Location: products.php');
    exit;
}

$service = new TrackingService($pdo);

// Get tracking details
$tracking = $service->getTracking($userId, $trackingId);
if (!$tracking) {
    header('Location: products.php');
    exit;
}

// Get price history (30 days by default)
$days = isset($_GET['days']) ? min(90, max(7, (int) $_GET['days'])) : 30;
$history = $service->getPriceHistory((int) $tracking['product_id'], $days);
$stats = $service->getPriceStats((int) $tracking['product_id'], $days);

$csrfToken = Csrf::token();

// Platform info
$platformInfo = [
    'shopee' => ['name' => 'Shopee', 'color' => '#EE4D2D'],
    'lazada' => ['name' => 'Lazada', 'color' => '#0F146D'],
    'tiktok' => ['name' => 'TikTok Shop', 'color' => '#000000'],
    'jib' => ['name' => 'JIB', 'color' => '#FF6600'],
    'banana' => ['name' => 'Banana IT', 'color' => '#FFD700'],
    'advice' => ['name' => 'Advice', 'color' => '#00BFFF'],
    'globalhouse' => ['name' => 'Global House', 'color' => '#008000'],
    'homepro' => ['name' => 'HomePro', 'color' => '#FF8C00'],
    'thaiwatsadu' => ['name' => 'Thai Watsadu', 'color' => '#0066CC'],
    'powerbuy' => ['name' => 'Power Buy', 'color' => '#CC0000'],
];

$platform = $platformInfo[$tracking['platform']] ?? ['name' => $tracking['platform'], 'color' => '#666666'];

// Prepare chart data
$chartLabels = [];
$chartPrices = [];
$chartOriginalPrices = [];

foreach ($history as $record) {
    $chartLabels[] = date('d/m', strtotime($record['scraped_at']));
    $chartPrices[] = (float) $record['price'];
    $chartOriginalPrices[] = $record['original_price'] ? (float) $record['original_price'] : null;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title><?= htmlspecialchars($tracking['product_name'] ?? 'Product') ?> - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .product-img {
            max-width: 300px;
            max-height: 300px;
            object-fit: contain;
            border-radius: 8px;
        }
        .price-current {
            font-size: 2rem;
            font-weight: bold;
            color: #e53935;
        }
        .price-original {
            text-decoration: line-through;
            color: #999;
            font-size: 1.2rem;
        }
        .stat-card {
            text-align: center;
            padding: 1rem;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .stat-label {
            font-size: 0.85rem;
            color: #666;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="/pages/dashboard.php">
                <i class="fas fa-chart-line me-2"></i>Price Tracker
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="/pages/products.php">
                    <i class="fas fa-arrow-left me-1"></i>กลับ
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row">
            <!-- Product Info -->
            <div class="col-lg-4 mb-4">
                <div class="card">
                    <div class="card-body text-center">
                        <?php if ($tracking['image_url']): ?>
                            <img src="<?= htmlspecialchars($tracking['image_url']) ?>" alt="" class="product-img mb-3">
                        <?php else: ?>
                            <div class="bg-light d-flex align-items-center justify-content-center mb-3" style="height: 200px; border-radius: 8px;">
                                <i class="fas fa-image fa-4x text-muted"></i>
                            </div>
                        <?php endif; ?>

                        <span class="badge mb-2" style="background-color: <?= $platform['color'] ?>">
                            <?= $platform['name'] ?>
                        </span>

                        <h5 class="card-title"><?= htmlspecialchars($tracking['product_name'] ?? 'ไม่ทราบชื่อ') ?></h5>

                        <?php if ($tracking['label']): ?>
                            <p class="text-muted small">
                                <i class="fas fa-tag me-1"></i><?= htmlspecialchars($tracking['label']) ?>
                            </p>
                        <?php endif; ?>

                        <hr>

                        <!-- Current Price -->
                        <div class="mb-3">
                            <?php if ($tracking['last_price']): ?>
                                <div class="price-current">฿<?= number_format($tracking['last_price'], 2) ?></div>
                                <?php if ($tracking['last_original_price'] && $tracking['last_original_price'] > $tracking['last_price']):
                                    $discount = round((1 - $tracking['last_price'] / $tracking['last_original_price']) * 100, 1);
                                ?>
                                    <div class="price-original">฿<?= number_format($tracking['last_original_price'], 2) ?></div>
                                    <span class="badge bg-danger">ลด <?= $discount ?>%</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">รอดึงข้อมูล...</span>
                            <?php endif; ?>
                        </div>

                        <!-- Target -->
                        <?php if ($tracking['target_price'] || $tracking['target_discount_percent']): ?>
                            <div class="alert alert-info py-2 small">
                                <i class="fas fa-bullseye me-1"></i>
                                <?php if ($tracking['target_price']): ?>
                                    เป้าหมาย: ฿<?= number_format($tracking['target_price'], 2) ?>
                                <?php else: ?>
                                    เป้าหมาย: ลด <?= $tracking['target_discount_percent'] ?>%
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Last checked -->
                        <p class="text-muted small mb-3">
                            <i class="fas fa-clock me-1"></i>
                            อัปเดต: <?= $tracking['last_checked_at'] ? date('d/m/Y H:i', strtotime($tracking['last_checked_at'])) : 'ยังไม่เคย' ?>
                        </p>

                        <!-- Actions -->
                        <div class="d-grid gap-2">
                            <a href="<?= htmlspecialchars($tracking['product_url']) ?>" target="_blank" class="btn btn-primary">
                                <i class="fas fa-external-link-alt me-1"></i>ดูสินค้า
                            </a>
                            <button class="btn btn-outline-secondary" onclick="refreshProduct()">
                                <i class="fas fa-sync-alt me-1"></i>รีเฟรชราคา
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Price History -->
            <div class="col-lg-8">
                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="stat-value text-success">
                                <?= $stats['min_price'] ? '฿' . number_format($stats['min_price'], 0) : '-' ?>
                            </div>
                            <div class="stat-label">ราคาต่ำสุด</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="stat-value text-danger">
                                <?= $stats['max_price'] ? '฿' . number_format($stats['max_price'], 0) : '-' ?>
                            </div>
                            <div class="stat-label">ราคาสูงสุด</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="stat-value text-primary">
                                <?= $stats['avg_price'] ? '฿' . number_format($stats['avg_price'], 0) : '-' ?>
                            </div>
                            <div class="stat-label">ราคาเฉลี่ย</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="stat-value text-secondary">
                                <?= $stats['data_points'] ?? 0 ?>
                            </div>
                            <div class="stat-label">จุดข้อมูล</div>
                        </div>
                    </div>
                </div>

                <!-- Chart -->
                <div class="card mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-chart-area me-2"></i>กราฟราคา</h5>
                        <div class="btn-group btn-group-sm">
                            <a href="?id=<?= $trackingId ?>&days=7" class="btn btn-outline-secondary <?= $days == 7 ? 'active' : '' ?>">7 วัน</a>
                            <a href="?id=<?= $trackingId ?>&days=30" class="btn btn-outline-secondary <?= $days == 30 ? 'active' : '' ?>">30 วัน</a>
                            <a href="?id=<?= $trackingId ?>&days=90" class="btn btn-outline-secondary <?= $days == 90 ? 'active' : '' ?>">90 วัน</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($history)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-chart-line fa-3x mb-3"></i>
                                <p>ยังไม่มีข้อมูลราคา</p>
                            </div>
                        <?php else: ?>
                            <div class="chart-container">
                                <canvas id="priceChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Price History Table -->
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>ประวัติราคา</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($history)): ?>
                            <div class="text-center py-4 text-muted">ไม่มีข้อมูล</div>
                        <?php else: ?>
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th>วันที่</th>
                                            <th class="text-end">ราคา</th>
                                            <th class="text-end">ราคาเดิม</th>
                                            <th class="text-end">ส่วนลด</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_reverse($history) as $record): ?>
                                            <tr>
                                                <td><?= date('d/m/Y H:i', strtotime($record['scraped_at'])) ?></td>
                                                <td class="text-end fw-bold">฿<?= number_format($record['price'], 2) ?></td>
                                                <td class="text-end text-muted">
                                                    <?= $record['original_price'] ? '฿' . number_format($record['original_price'], 2) : '-' ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($record['discount_percent'] && $record['discount_percent'] > 0): ?>
                                                        <span class="badge bg-danger"><?= $record['discount_percent'] ?>%</span>
                                                    <?php else: ?>
                                                        -
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
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        <?php if (!empty($history)): ?>
        // Price Chart
        const ctx = document.getElementById('priceChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [
                    {
                        label: 'ราคาขาย',
                        data: <?= json_encode($chartPrices) ?>,
                        borderColor: '#e53935',
                        backgroundColor: 'rgba(229, 57, 53, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                    },
                    {
                        label: 'ราคาเดิม',
                        data: <?= json_encode($chartOriginalPrices) ?>,
                        borderColor: '#999',
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.3,
                        pointRadius: 2,
                        pointHoverRadius: 4,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ฿' + context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function(value) {
                                return '฿' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Refresh product
        async function refreshProduct() {
            try {
                const response = await fetch('../api/products/refresh.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        tracking_id: <?= $trackingId ?>,
                        csrf_token: csrfToken
                    })
                });
                const data = await response.json();
                alert(data.message);
                if (data.success) {
                    setTimeout(() => location.reload(), 2000);
                }
            } catch (error) {
                alert('เกิดข้อผิดพลาด');
            }
        }
    </script>
</body>
</html>
