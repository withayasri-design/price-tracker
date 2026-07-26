<?php

/**
 * Admin Dashboard
 *
 * Main admin page with system overview, statistics, and quick navigation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';

use Core\Auth;
use Core\Csrf;

Auth::requireAdmin();

$csrfToken = Csrf::generate();

// Get statistics
$stats = [];

// Total users
$stmt = $pdo->query("SELECT COUNT(*) as total, SUM(role = 'admin') as admins FROM users WHERE is_active = 1");
$userStats = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['users'] = (int) $userStats['total'];
$stats['admins'] = (int) $userStats['admins'];

// Total tracked products
$stmt = $pdo->query("SELECT COUNT(*) as total FROM tracked_products WHERE is_active = 1");
$stats['products'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total user trackings
$stmt = $pdo->query("SELECT COUNT(*) as total FROM user_tracking WHERE is_active = 1");
$stats['trackings'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Price history records
$stmt = $pdo->query("SELECT COUNT(*) as total FROM price_history");
$stats['price_records'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Price events (last 7 days)
$stmt = $pdo->query("SELECT COUNT(*) as total FROM price_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stats['recent_events'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Scraping stats (last 24 hours)
$stmt = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(status = 'success') as success,
        SUM(status = 'failed') as failed
    FROM scraping_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
$scrapingStats = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['scrapes_24h'] = (int) $scrapingStats['total'];
$stats['scrapes_success'] = (int) $scrapingStats['success'];
$stats['scrapes_failed'] = (int) $scrapingStats['failed'];

// Agent queue status
$stmt = $pdo->query("
    SELECT
        status,
        COUNT(*) as count
    FROM agent_job_queue
    GROUP BY status
");
$queueStats = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $queueStats[$row['status']] = (int) $row['count'];
}

// Products by platform
$stmt = $pdo->query("
    SELECT platform, COUNT(*) as count
    FROM tracked_products
    WHERE is_active = 1
    GROUP BY platform
    ORDER BY count DESC
");
$platformStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent price events
$stmt = $pdo->query("
    SELECT
        pe.event_type,
        pe.old_price,
        pe.new_price,
        pe.created_at,
        tp.product_name,
        tp.platform
    FROM price_events pe
    JOIN tracked_products tp ON pe.product_id = tp.product_id
    ORDER BY pe.created_at DESC
    LIMIT 10
");
$recentEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent scraping logs
$stmt = $pdo->query("
    SELECT
        sl.status,
        sl.error_message,
        sl.duration_ms,
        sl.created_at,
        tp.product_name,
        tp.platform
    FROM scraping_logs sl
    JOIN tracked_products tp ON sl.product_id = tp.product_id
    ORDER BY sl.created_at DESC
    LIMIT 10
");
$recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Platform info for display
$platformInfo = [
    'jib' => ['name' => 'JIB', 'color' => 'warning', 'status' => 'ready'],
    'banana' => ['name' => 'Banana IT', 'color' => 'success', 'status' => 'ready'],
    'advice' => ['name' => 'Advice', 'color' => 'info', 'status' => 'ready'],
    'powerbuy' => ['name' => 'Power Buy', 'color' => 'danger', 'status' => 'ready'],
    'globalhouse' => ['name' => 'Global House', 'color' => 'secondary', 'status' => 'ready'],
    'homepro' => ['name' => 'HomePro', 'color' => 'warning', 'status' => 'ready'],
    'thaiwatsadu' => ['name' => 'Thai Watsadu', 'color' => 'primary', 'status' => 'ready'],
    'lazada' => ['name' => 'Lazada', 'color' => 'primary', 'status' => 'ready'],
    'shopee' => ['name' => 'Shopee', 'color' => 'danger', 'status' => 'limited'],
    'tiktok' => ['name' => 'TikTok Shop', 'color' => 'dark', 'status' => 'limited'],
];

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>Admin Dashboard - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-size: 1.5rem;
        }
        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
        }
        .admin-nav-card {
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
        }
        .admin-nav-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .platform-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .event-row {
            border-left: 3px solid transparent;
        }
        .event-row.price_drop {
            border-left-color: #198754;
        }
        .event-row.price_increase {
            border-left-color: #dc3545;
        }
        .event-row.back_in_stock {
            border-left-color: #0d6efd;
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
                        <a class="nav-link" href="../dashboard.php"><i class="fas fa-home me-1"></i>หน้าหลัก</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../products.php"><i class="fas fa-box me-1"></i>สินค้า</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php"><i class="fas fa-cog me-1"></i>Admin</a>
                    </li>
                </ul>
                <div class="navbar-nav">
                    <span class="nav-link text-light">
                        <i class="fas fa-user-shield me-1"></i><?= htmlspecialchars(Auth::fullName()) ?>
                    </span>
                    <a class="nav-link" href="../logout.php"><i class="fas fa-sign-out-alt me-1"></i>ออกจากระบบ</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-tachometer-alt me-2"></i>Admin Dashboard</h2>
                <p class="text-muted mb-0">ภาพรวมระบบและสถิติการใช้งาน</p>
            </div>
            <div class="text-muted">
                <i class="fas fa-clock me-1"></i>อัพเดตล่าสุด: <?= date('H:i:s') ?>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= number_format($stats['users']) ?></div>
                            <div class="text-muted small">ผู้ใช้งาน</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-success bg-opacity-10 text-success me-3">
                            <i class="fas fa-box"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= number_format($stats['products']) ?></div>
                            <div class="text-muted small">สินค้าที่ติดตาม</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                            <i class="fas fa-history"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= number_format($stats['price_records']) ?></div>
                            <div class="text-muted small">ประวัติราคา</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= number_format($stats['recent_events']) ?></div>
                            <div class="text-muted small">Events (7 วัน)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Admin Navigation Cards -->
        <h5 class="mb-3"><i class="fas fa-th-large me-2"></i>เมนู Admin</h5>
        <div class="row g-3 mb-4">
            <div class="col-md-4 col-sm-6">
                <a href="settings.php" class="card admin-nav-card h-100 text-decoration-none">
                    <div class="card-body text-center py-4">
                        <div class="text-primary mb-2"><i class="fas fa-cogs fa-2x"></i></div>
                        <h6 class="card-title mb-1">ตั้งค่าระบบ</h6>
                        <small class="text-muted">Scraping, Notifications, API</small>
                    </div>
                </a>
            </div>
            <div class="col-md-4 col-sm-6">
                <a href="agent_monitor.php" class="card admin-nav-card h-100 text-decoration-none">
                    <div class="card-body text-center py-4">
                        <div class="text-success mb-2"><i class="fas fa-robot fa-2x"></i></div>
                        <h6 class="card-title mb-1">Agent Monitor</h6>
                        <small class="text-muted">
                            Pending: <?= number_format($queueStats['pending'] ?? 0) ?> |
                            Running: <?= number_format($queueStats['running'] ?? 0) ?>
                        </small>
                    </div>
                </a>
            </div>
            <div class="col-md-4 col-sm-6">
                <a href="master_products.php" class="card admin-nav-card h-100 text-decoration-none">
                    <div class="card-body text-center py-4">
                        <div class="text-info mb-2"><i class="fas fa-cubes fa-2x"></i></div>
                        <h6 class="card-title mb-1">Master Products</h6>
                        <small class="text-muted">จัดการ Cross-platform Matching</small>
                    </div>
                </a>
            </div>
            <div class="col-md-4 col-sm-6">
                <a href="logs.php" class="card admin-nav-card h-100 text-decoration-none">
                    <div class="card-body text-center py-4">
                        <div class="text-secondary mb-2"><i class="fas fa-list-alt fa-2x"></i></div>
                        <h6 class="card-title mb-1">Scraping Logs</h6>
                        <small class="text-muted">ดูประวัติการ Scrape</small>
                    </div>
                </a>
            </div>
            <div class="col-md-4 col-sm-6">
                <a href="file_logs.php" class="card admin-nav-card h-100 text-decoration-none">
                    <div class="card-body text-center py-4">
                        <div class="text-dark mb-2"><i class="fas fa-file-alt fa-2x"></i></div>
                        <h6 class="card-title mb-1">File Logs</h6>
                        <small class="text-muted">ดู Log Files</small>
                    </div>
                </a>
            </div>
            <div class="col-md-4 col-sm-6">
                <a href="test_notifications.php" class="card admin-nav-card h-100 text-decoration-none">
                    <div class="card-body text-center py-4">
                        <div class="text-warning mb-2"><i class="fas fa-paper-plane fa-2x"></i></div>
                        <h6 class="card-title mb-1">Test Notifications</h6>
                        <small class="text-muted">ทดสอบ LINE / Email</small>
                    </div>
                </a>
            </div>
        </div>

        <div class="row g-4">
            <!-- Scraping Status -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-spider me-2"></i>Scraping Status (24 ชม.)</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center mb-3">
                            <div class="col-4">
                                <div class="h4 mb-0 text-primary"><?= number_format($stats['scrapes_24h']) ?></div>
                                <small class="text-muted">Total</small>
                            </div>
                            <div class="col-4">
                                <div class="h4 mb-0 text-success"><?= number_format($stats['scrapes_success']) ?></div>
                                <small class="text-muted">Success</small>
                            </div>
                            <div class="col-4">
                                <div class="h4 mb-0 text-danger"><?= number_format($stats['scrapes_failed']) ?></div>
                                <small class="text-muted">Failed</small>
                            </div>
                        </div>
                        <?php if ($stats['scrapes_24h'] > 0): ?>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-success" style="width: <?= ($stats['scrapes_success'] / $stats['scrapes_24h']) * 100 ?>%"></div>
                            <div class="progress-bar bg-danger" style="width: <?= ($stats['scrapes_failed'] / $stats['scrapes_24h']) * 100 ?>%"></div>
                        </div>
                        <div class="text-center mt-2">
                            <small class="text-muted">
                                Success Rate: <?= $stats['scrapes_24h'] > 0 ? round(($stats['scrapes_success'] / $stats['scrapes_24h']) * 100, 1) : 0 ?>%
                            </small>
                        </div>
                        <?php else: ?>
                        <p class="text-muted text-center mb-0">ไม่มีข้อมูล</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Platform Status -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-store me-2"></i>Platform Status</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <?php foreach ($platformInfo as $key => $platform): ?>
                            <div class="col-6 col-md-4">
                                <div class="d-flex align-items-center p-2 border rounded">
                                    <span class="badge bg-<?= $platform['color'] ?> me-2"><?= $platform['name'] ?></span>
                                    <?php if ($platform['status'] === 'ready'): ?>
                                    <span class="text-success small"><i class="fas fa-check-circle"></i></span>
                                    <?php else: ?>
                                    <span class="text-warning small"><i class="fas fa-exclamation-triangle"></i></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-check-circle text-success me-1"></i>Ready: Full scraping
                                <i class="fas fa-exclamation-triangle text-warning ms-2 me-1"></i>Limited: URL parsing only
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Products by Platform -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>สินค้าตาม Platform</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($platformStats)): ?>
                        <p class="text-muted text-center mb-0">ไม่มีข้อมูล</p>
                        <?php else: ?>
                        <?php foreach ($platformStats as $ps): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span>
                                <span class="badge bg-<?= $platformInfo[$ps['platform']]['color'] ?? 'secondary' ?> me-2">
                                    <?= $platformInfo[$ps['platform']]['name'] ?? $ps['platform'] ?>
                                </span>
                            </span>
                            <span class="fw-bold"><?= number_format($ps['count']) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Events -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fas fa-bolt me-2"></i>Price Events ล่าสุด</h6>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recentEvents)): ?>
                        <p class="text-muted text-center py-4 mb-0">ไม่มี Events</p>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach (array_slice($recentEvents, 0, 5) as $event): ?>
                            <div class="list-group-item event-row <?= $event['event_type'] ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <small class="text-muted d-block"><?= htmlspecialchars($event['product_name'] ?? 'Unknown') ?></small>
                                        <span class="badge bg-<?= $platformInfo[$event['platform']]['color'] ?? 'secondary' ?> platform-badge">
                                            <?= $platformInfo[$event['platform']]['name'] ?? $event['platform'] ?>
                                        </span>
                                    </div>
                                    <div class="text-end">
                                        <?php if ($event['event_type'] === 'price_drop'): ?>
                                        <span class="text-success fw-bold">
                                            <i class="fas fa-arrow-down me-1"></i>฿<?= number_format($event['new_price']) ?>
                                        </span>
                                        <?php elseif ($event['event_type'] === 'price_increase'): ?>
                                        <span class="text-danger fw-bold">
                                            <i class="fas fa-arrow-up me-1"></i>฿<?= number_format($event['new_price']) ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-info fw-bold">
                                            <i class="fas fa-box me-1"></i>Back in Stock
                                        </span>
                                        <?php endif; ?>
                                        <small class="text-muted d-block"><?= date('d/m H:i', strtotime($event['created_at'])) ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
