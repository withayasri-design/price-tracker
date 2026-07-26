<?php

/**
 * Scraping Monitor
 *
 * Real-time monitoring of scraping activities across all platforms.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';

use Core\Auth;
use Core\Csrf;

Auth::requireAdmin();

$csrfToken = Csrf::generate();

// Filters
$platform = $_GET['platform'] ?? '';
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get overall statistics
$stmt = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(status = 'success') as success_count,
        SUM(status = 'failed') as failed_count,
        AVG(duration_ms) as avg_duration
    FROM scraping_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
$stats24h = $stmt->fetch(PDO::FETCH_ASSOC);

// Get statistics by platform (last 24 hours)
$stmt = $pdo->query("
    SELECT
        tp.platform,
        COUNT(*) as total,
        SUM(sl.status = 'success') as success_count,
        SUM(sl.status = 'failed') as failed_count,
        AVG(sl.duration_ms) as avg_duration,
        MAX(sl.created_at) as last_scrape
    FROM scraping_logs sl
    JOIN tracked_products tp ON sl.product_id = tp.product_id
    WHERE sl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY tp.platform
    ORDER BY total DESC
");
$platformStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent errors
$stmt = $pdo->query("
    SELECT
        sl.log_id,
        sl.error_message,
        sl.created_at,
        tp.platform,
        tp.product_name,
        tp.product_url
    FROM scraping_logs sl
    JOIN tracked_products tp ON sl.product_id = tp.product_id
    WHERE sl.status = 'failed'
    ORDER BY sl.created_at DESC
    LIMIT 10
");
$recentErrors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build query for logs table
$where = ['1=1'];
$params = [];

if ($platform) {
    $where[] = 'tp.platform = ?';
    $params[] = $platform;
}

if ($status) {
    $where[] = 'sl.status = ?';
    $params[] = $status;
}

if ($dateFrom) {
    $where[] = 'DATE(sl.created_at) >= ?';
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = 'DATE(sl.created_at) <= ?';
    $params[] = $dateTo;
}

$whereClause = implode(' AND ', $where);

// Get total count
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM scraping_logs sl
    JOIN tracked_products tp ON sl.product_id = tp.product_id
    WHERE $whereClause
");
$stmt->execute($params);
$totalLogs = (int) $stmt->fetchColumn();
$totalPages = max(1, ceil($totalLogs / $perPage));

// Get logs
$stmt = $pdo->prepare("
    SELECT
        sl.log_id,
        sl.product_id,
        sl.trigger_type,
        sl.status,
        sl.error_message,
        sl.duration_ms,
        sl.created_at,
        tp.platform,
        tp.product_name,
        tp.product_url
    FROM scraping_logs sl
    JOIN tracked_products tp ON sl.product_id = tp.product_id
    WHERE $whereClause
    ORDER BY sl.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available platforms
$stmt = $pdo->query("SELECT DISTINCT platform FROM tracked_products ORDER BY platform");
$platforms = $stmt->fetchAll(PDO::FETCH_COLUMN);

$platformInfo = [
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
    <title>Scraping Monitor - Price Tracker Admin</title>
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
        .platform-row {
            transition: background 0.2s;
        }
        .platform-row:hover {
            background: #f8f9fa;
        }
        .progress-thin {
            height: 6px;
        }
        .log-row.success {
            border-left: 3px solid #198754;
        }
        .log-row.failed {
            border-left: 3px solid #dc3545;
        }
        .error-preview {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
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
                <a class="nav-link" href="index.php"><i class="fas fa-tachometer-alt me-1"></i>Admin</a>
                <a class="nav-link active" href="scraping_monitor.php"><i class="fas fa-spider me-1"></i>Scraping</a>
                <a class="nav-link" href="../logout.php"><i class="fas fa-sign-out-alt me-1"></i>ออก</a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-spider me-2"></i>Scraping Monitor</h2>
                <p class="text-muted mb-0">ตรวจสอบสถานะการดึงข้อมูลสินค้า</p>
            </div>
            <div>
                <button class="btn btn-outline-primary" onclick="location.reload()">
                    <i class="fas fa-sync-alt me-1"></i>Refresh
                </button>
            </div>
        </div>

        <!-- 24h Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                            <i class="fas fa-download"></i>
                        </div>
                        <div>
                            <div class="h4 mb-0"><?= number_format((int)($stats24h['total'] ?? 0)) ?></div>
                            <div class="text-muted small">Total (24h)</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-success bg-opacity-10 text-success me-3">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <div class="h4 mb-0"><?= number_format((int)($stats24h['success_count'] ?? 0)) ?></div>
                            <div class="text-muted small">Success</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div>
                            <div class="h4 mb-0"><?= number_format((int)($stats24h['failed_count'] ?? 0)) ?></div>
                            <div class="text-muted small">Failed</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card stat-card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="stat-icon bg-info bg-opacity-10 text-info me-3">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <div class="h4 mb-0"><?= number_format((float)($stats24h['avg_duration'] ?? 0), 0) ?>ms</div>
                            <div class="text-muted small">Avg Duration</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- Platform Stats -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-store me-2"></i>Platform Statistics (24h)</h6>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($platformStats)): ?>
                            <p class="text-muted text-center py-4 mb-0">ไม่มีข้อมูล</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Platform</th>
                                            <th class="text-center">Total</th>
                                            <th class="text-center">Success</th>
                                            <th class="text-center">Rate</th>
                                            <th class="text-end">Avg Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($platformStats as $ps):
                                            $pInfo = $platformInfo[$ps['platform']] ?? ['name' => $ps['platform'], 'color' => 'secondary'];
                                            $successRate = $ps['total'] > 0 ? ($ps['success_count'] / $ps['total']) * 100 : 0;
                                        ?>
                                            <tr class="platform-row">
                                                <td>
                                                    <span class="badge bg-<?= $pInfo['color'] ?>"><?= $pInfo['name'] ?></span>
                                                </td>
                                                <td class="text-center"><?= number_format((int)$ps['total']) ?></td>
                                                <td class="text-center text-success"><?= number_format((int)$ps['success_count']) ?></td>
                                                <td class="text-center">
                                                    <div class="d-flex align-items-center">
                                                        <div class="progress progress-thin flex-grow-1 me-2">
                                                            <div class="progress-bar bg-success" style="width: <?= $successRate ?>%"></div>
                                                        </div>
                                                        <small><?= number_format($successRate, 0) ?>%</small>
                                                    </div>
                                                </td>
                                                <td class="text-end"><?= number_format((float)$ps['avg_duration'], 0) ?>ms</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Errors -->
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Recent Errors</h6>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recentErrors)): ?>
                            <p class="text-muted text-center py-4 mb-0">
                                <i class="fas fa-check-circle text-success fa-2x mb-2 d-block"></i>
                                ไม่มี errors ล่าสุด
                            </p>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($recentErrors as $error):
                                    $pInfo = $platformInfo[$error['platform']] ?? ['name' => $error['platform'], 'color' => 'secondary'];
                                ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <span class="badge bg-<?= $pInfo['color'] ?> me-1"><?= $pInfo['name'] ?></span>
                                                <small class="text-muted"><?= date('d/m H:i', strtotime($error['created_at'])) ?></small>
                                            </div>
                                        </div>
                                        <div class="error-preview text-danger small mt-1">
                                            <i class="fas fa-times-circle me-1"></i><?= htmlspecialchars($error['error_message'] ?? 'Unknown error') ?>
                                        </div>
                                        <small class="text-muted"><?= htmlspecialchars(mb_substr($error['product_name'] ?? '', 0, 50)) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Platform</label>
                        <select name="platform" class="form-select">
                            <option value="">ทั้งหมด</option>
                            <?php foreach ($platforms as $p):
                                $pInfo = $platformInfo[$p] ?? ['name' => $p];
                            ?>
                                <option value="<?= $p ?>" <?= $platform === $p ? 'selected' : '' ?>><?= $pInfo['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">ทั้งหมด</option>
                            <option value="success" <?= $status === 'success' ? 'selected' : '' ?>>Success</option>
                            <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">จากวันที่</label>
                        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">ถึงวันที่</label>
                        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-1"></i>ค้นหา
                        </button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <a href="scraping_monitor.php" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-undo me-1"></i>Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-list me-2"></i>Scraping Logs</h6>
                <span class="badge bg-secondary"><?= number_format($totalLogs) ?> records</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($logs)): ?>
                    <p class="text-muted text-center py-4 mb-0">ไม่พบข้อมูล</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>เวลา</th>
                                    <th>Platform</th>
                                    <th>สินค้า</th>
                                    <th>Trigger</th>
                                    <th>Status</th>
                                    <th>Duration</th>
                                    <th>Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log):
                                    $pInfo = $platformInfo[$log['platform']] ?? ['name' => $log['platform'], 'color' => 'secondary'];
                                ?>
                                    <tr class="log-row <?= $log['status'] ?>">
                                        <td>
                                            <small><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $pInfo['color'] ?>"><?= $pInfo['name'] ?></span>
                                        </td>
                                        <td>
                                            <a href="<?= htmlspecialchars($log['product_url']) ?>" target="_blank" class="text-decoration-none" title="<?= htmlspecialchars($log['product_name'] ?? '') ?>">
                                                <?= htmlspecialchars(mb_substr($log['product_name'] ?? 'Unknown', 0, 40)) ?>
                                                <?= mb_strlen($log['product_name'] ?? '') > 40 ? '...' : '' ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $log['trigger_type'] === 'manual' ? 'info' : 'secondary' ?>">
                                                <?= $log['trigger_type'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($log['status'] === 'success'): ?>
                                                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Success</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Failed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?= number_format((int)$log['duration_ms']) ?>ms</small>
                                        </td>
                                        <td>
                                            <?php if ($log['error_message']): ?>
                                                <span class="error-preview text-danger small" title="<?= htmlspecialchars($log['error_message']) ?>">
                                                    <?= htmlspecialchars(mb_substr($log['error_message'], 0, 30)) ?>...
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
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
