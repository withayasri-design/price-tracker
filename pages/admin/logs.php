<?php
/**
 * Admin Log Viewer
 *
 * View and filter agent logs and system events.
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
$agentType = $_GET['agent_type'] ?? '';
$logLevel = $_GET['log_level'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query
$where = ['1=1'];
$params = [];

if ($agentType) {
    $where[] = 'agent_type = ?';
    $params[] = $agentType;
}

if ($logLevel) {
    $where[] = 'log_level = ?';
    $params[] = $logLevel;
}

if ($dateFrom) {
    $where[] = 'DATE(created_at) >= ?';
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where[] = 'DATE(created_at) <= ?';
    $params[] = $dateTo;
}

if ($search) {
    $where[] = '(message LIKE ? OR context LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = implode(' AND ', $where);

// Get total count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM agent_logs WHERE $whereClause");
$stmt->execute($params);
$totalLogs = (int) $stmt->fetchColumn();
$totalPages = max(1, ceil($totalLogs / $perPage));

// Get logs
$stmt = $pdo->prepare("
    SELECT log_id, agent_type, job_id, log_level, message, context, created_at
    FROM agent_logs
    WHERE $whereClause
    ORDER BY created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available agent types
$agentTypes = $pdo->query("SELECT DISTINCT agent_type FROM agent_logs ORDER BY agent_type")->fetchAll(PDO::FETCH_COLUMN);

// Get log level counts for current filter
$stmt = $pdo->prepare("
    SELECT log_level, COUNT(*) as cnt
    FROM agent_logs
    WHERE $whereClause
    GROUP BY log_level
");
$stmt->execute($params);
$levelCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - Price Tracker Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .log-row { font-family: monospace; font-size: 0.85rem; }
        .log-row:hover { background-color: #f8f9fa; }
        .log-level-error { color: #dc3545; font-weight: bold; }
        .log-level-warning { color: #ffc107; }
        .log-level-info { color: #0dcaf0; }
        .log-level-debug { color: #6c757d; }
        .log-context { max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .log-context:hover { white-space: normal; word-break: break-all; }
        pre.context-json { background: #f8f9fa; padding: 10px; border-radius: 4px; max-height: 300px; overflow: auto; }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="../dashboard.php">
                <i class="bi bi-cart-check"></i> Price Tracker
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../dashboard.php">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-journal-text"></i> System Logs</h2>
            <div>
                <span class="badge bg-secondary"><?= number_format($totalLogs) ?> logs</span>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card border-danger">
                    <div class="card-body text-center">
                        <h3 class="text-danger"><?= number_format($levelCounts['error'] ?? 0) ?></h3>
                        <small class="text-muted">Errors</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <h3 class="text-warning"><?= number_format($levelCounts['warning'] ?? 0) ?></h3>
                        <small class="text-muted">Warnings</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <h3 class="text-info"><?= number_format($levelCounts['info'] ?? 0) ?></h3>
                        <small class="text-muted">Info</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <h3 class="text-secondary"><?= number_format($levelCounts['debug'] ?? 0) ?></h3>
                        <small class="text-muted">Debug</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Agent Type</label>
                        <select name="agent_type" class="form-select">
                            <option value="">All</option>
                            <?php foreach ($agentTypes as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>" <?= $agentType === $type ? 'selected' : '' ?>>
                                <?= htmlspecialchars($type) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Log Level</label>
                        <select name="log_level" class="form-select">
                            <option value="">All</option>
                            <option value="error" <?= $logLevel === 'error' ? 'selected' : '' ?>>Error</option>
                            <option value="warning" <?= $logLevel === 'warning' ? 'selected' : '' ?>>Warning</option>
                            <option value="info" <?= $logLevel === 'info' ? 'selected' : '' ?>>Info</option>
                            <option value="debug" <?= $logLevel === 'debug' ? 'selected' : '' ?>>Debug</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Search messages..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 150px;">Timestamp</th>
                            <th style="width: 100px;">Agent</th>
                            <th style="width: 80px;">Level</th>
                            <th>Message</th>
                            <th style="width: 80px;">Job ID</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="bi bi-inbox"></i> No logs found
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr class="log-row">
                            <td class="text-nowrap">
                                <small><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></small>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= htmlspecialchars($log['agent_type']) ?></span>
                            </td>
                            <td>
                                <span class="log-level-<?= $log['log_level'] ?>">
                                    <?= strtoupper($log['log_level']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($log['message']) ?></td>
                            <td>
                                <?php if ($log['job_id']): ?>
                                <small class="text-muted">#<?= $log['job_id'] ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['context']): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal" data-bs-target="#contextModal"
                                        data-context="<?= htmlspecialchars($log['context']) ?>">
                                    <i class="bi bi-code"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination pagination-sm mb-0 justify-content-center">
                        <?php
                        $queryParams = $_GET;
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        ?>

                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <?php $queryParams['page'] = $page - 1; ?>
                            <a class="page-link" href="?<?= http_build_query($queryParams) ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <?php $queryParams['page'] = $i; ?>
                            <a class="page-link" href="?<?= http_build_query($queryParams) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <?php $queryParams['page'] = $page + 1; ?>
                            <a class="page-link" href="?<?= http_build_query($queryParams) ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Context Modal -->
    <div class="modal fade" id="contextModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Log Context</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <pre class="context-json" id="contextContent"></pre>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show context in modal
        document.getElementById('contextModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const context = button.getAttribute('data-context');
            const content = document.getElementById('contextContent');

            try {
                const parsed = JSON.parse(context);
                content.textContent = JSON.stringify(parsed, null, 2);
            } catch (e) {
                content.textContent = context;
            }
        });

        // Auto-refresh every 30 seconds if on first page with no filters
        <?php if ($page === 1 && !$agentType && !$logLevel && !$search): ?>
        setTimeout(() => location.reload(), 30000);
        <?php endif; ?>
    </script>
</body>
</html>
