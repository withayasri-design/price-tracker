<?php

/**
 * Agent Monitor Page
 *
 * Shows agent job queue status and logs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';

use Core\Auth;
use Core\Csrf;

Auth::requireAdmin();

// Get queue statistics
$stmt = $pdo->query("
    SELECT
        agent_type,
        status,
        COUNT(*) as count
    FROM agent_job_queue
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY agent_type, status
    ORDER BY agent_type, status
");
$queueStats = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $queueStats[$row['agent_type']][$row['status']] = (int) $row['count'];
}

// Get recent jobs
$stmt = $pdo->query("
    SELECT job_id, agent_type, status, retry_count, error_message, created_at, started_at, completed_at
    FROM agent_job_queue
    ORDER BY created_at DESC
    LIMIT 50
");
$recentJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent logs
$stmt = $pdo->query("
    SELECT log_id, agent_type, job_id, log_level, message, created_at
    FROM agent_logs
    ORDER BY created_at DESC
    LIMIT 100
");
$recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get scraping stats
$stmt = $pdo->query("
    SELECT
        status,
        COUNT(*) as count,
        AVG(duration_ms) as avg_duration
    FROM scraping_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY status
");
$scrapingStats = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $scrapingStats[$row['status']] = [
        'count' => (int) $row['count'],
        'avg_duration' => round((float) $row['avg_duration']),
    ];
}

$csrfToken = Csrf::token();

$agentLabels = [
    'scraper' => ['name' => 'Scraper Agent', 'icon' => 'spider', 'color' => 'primary'],
    'data_cleaning' => ['name' => 'Data Cleaning', 'icon' => 'broom', 'color' => 'info'],
    'price_diff' => ['name' => 'Price Diff', 'icon' => 'chart-line', 'color' => 'warning'],
    'alert_dispatch' => ['name' => 'Alert Dispatch', 'icon' => 'bell', 'color' => 'success'],
];

$statusColors = [
    'pending' => 'secondary',
    'processing' => 'primary',
    'completed' => 'success',
    'failed' => 'danger',
];

$logLevelColors = [
    'debug' => 'secondary',
    'info' => 'info',
    'warning' => 'warning',
    'error' => 'danger',
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Monitor - Price Tracker Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card { text-align: center; }
        .stat-value { font-size: 2rem; font-weight: bold; }
        .stat-label { font-size: 0.85rem; color: #666; }
        .log-message { font-family: monospace; font-size: 0.85rem; }
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
                <a class="nav-link" href="./master_products.php">Master Products</a>
                <a class="nav-link active" href="./agent_monitor.php">Agents</a>
                <a class="nav-link" href="./settings.php">Settings</a>
                <a class="nav-link" href="../dashboard.php">
                    <i class="fas fa-arrow-left me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-robot me-2"></i>Agent Monitor</h4>
            <button class="btn btn-outline-primary" onclick="location.reload()">
                <i class="fas fa-sync-alt me-1"></i>Refresh
            </button>
        </div>

        <!-- Agent Stats -->
        <div class="row mb-4">
            <?php foreach ($agentLabels as $agentType => $agent): ?>
                <?php
                $stats = $queueStats[$agentType] ?? [];
                $pending = $stats['pending'] ?? 0;
                $processing = $stats['processing'] ?? 0;
                $completed = $stats['completed'] ?? 0;
                $failed = $stats['failed'] ?? 0;
                ?>
                <div class="col-md-3 mb-3">
                    <div class="card">
                        <div class="card-body stat-card">
                            <i class="fas fa-<?= $agent['icon'] ?> fa-2x text-<?= $agent['color'] ?> mb-2"></i>
                            <h6><?= $agent['name'] ?></h6>
                            <div class="d-flex justify-content-around mt-2">
                                <span class="badge bg-secondary"><?= $pending ?> pending</span>
                                <span class="badge bg-success"><?= $completed ?> done</span>
                                <span class="badge bg-danger"><?= $failed ?> fail</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Scraping Stats -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-spider me-2"></i>Scraping (24h)</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col">
                                <div class="stat-value text-success"><?= $scrapingStats['success']['count'] ?? 0 ?></div>
                                <div class="stat-label">Success</div>
                            </div>
                            <div class="col">
                                <div class="stat-value text-danger"><?= $scrapingStats['failed']['count'] ?? 0 ?></div>
                                <div class="stat-label">Failed</div>
                            </div>
                            <div class="col">
                                <div class="stat-value text-primary"><?= $scrapingStats['success']['avg_duration'] ?? 0 ?>ms</div>
                                <div class="stat-label">Avg Time</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-tasks me-2"></i>Queue Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <?php
                            $totalPending = array_sum(array_column(array_map(fn($s) => ['p' => $s['pending'] ?? 0], $queueStats), 'p'));
                            $totalProcessing = array_sum(array_column(array_map(fn($s) => ['p' => $s['processing'] ?? 0], $queueStats), 'p'));
                            $totalCompleted = array_sum(array_column(array_map(fn($s) => ['p' => $s['completed'] ?? 0], $queueStats), 'p'));
                            ?>
                            <div class="col">
                                <div class="stat-value text-secondary"><?= $totalPending ?></div>
                                <div class="stat-label">Pending</div>
                            </div>
                            <div class="col">
                                <div class="stat-value text-primary"><?= $totalProcessing ?></div>
                                <div class="stat-label">Processing</div>
                            </div>
                            <div class="col">
                                <div class="stat-value text-success"><?= $totalCompleted ?></div>
                                <div class="stat-label">Completed</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Recent Jobs -->
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Recent Jobs</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>ID</th>
                                        <th>Agent</th>
                                        <th>Status</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentJobs as $job): ?>
                                        <tr>
                                            <td><?= $job['job_id'] ?></td>
                                            <td>
                                                <span class="badge bg-<?= $agentLabels[$job['agent_type']]['color'] ?? 'secondary' ?>">
                                                    <?= $job['agent_type'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $statusColors[$job['status']] ?? 'secondary' ?>">
                                                    <?= $job['status'] ?>
                                                </span>
                                                <?php if ($job['retry_count'] > 0): ?>
                                                    <small class="text-muted">(retry <?= $job['retry_count'] ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small"><?= date('H:i:s', strtotime($job['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Logs -->
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-scroll me-2"></i>Recent Logs</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>Level</th>
                                        <th>Agent</th>
                                        <th>Message</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentLogs as $log): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-<?= $logLevelColors[$log['log_level']] ?? 'secondary' ?>">
                                                    <?= $log['log_level'] ?>
                                                </span>
                                            </td>
                                            <td class="small"><?= $log['agent_type'] ?></td>
                                            <td class="log-message text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($log['message']) ?>">
                                                <?= htmlspecialchars(mb_substr($log['message'], 0, 50)) ?>
                                            </td>
                                            <td class="small"><?= date('H:i:s', strtotime($log['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => location.reload(), 30000);
    </script>
</body>
</html>
