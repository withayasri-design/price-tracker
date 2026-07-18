<?php
/**
 * File Log Viewer
 *
 * View and manage application log files.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Logger.php';

use Core\Auth;
use Core\Logger;

Auth::requireAdmin();

$action = $_GET['action'] ?? '';
$file = $_GET['file'] ?? '';
$lines = (int) ($_GET['lines'] ?? 200);

// Handle actions
if ($action === 'download' && $file) {
    $filepath = Logger::getLogDir() . '/' . basename($file);
    if (file_exists($filepath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}

if ($action === 'delete' && $file && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $filepath = Logger::getLogDir() . '/' . basename($file);
    if (file_exists($filepath)) {
        unlink($filepath);
        header('Location: file_logs.php?deleted=1');
        exit;
    }
}

if ($action === 'cleanup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $days = (int) ($_POST['days'] ?? 30);
    $deleted = Logger::cleanup($days);
    header('Location: file_logs.php?cleaned=' . $deleted);
    exit;
}

// Get log files
$logFiles = Logger::getLogFiles();

// Get selected log content
$logContent = [];
if ($file) {
    $logContent = Logger::readLog($file, $lines);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Logs - Price Tracker Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .log-viewer {
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 0.8rem;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 1rem;
            border-radius: 8px;
            max-height: 600px;
            overflow: auto;
        }
        .log-line {
            white-space: pre-wrap;
            word-break: break-all;
            padding: 2px 0;
            border-bottom: 1px solid #333;
        }
        .log-line:hover {
            background: #2d2d2d;
        }
        .log-emergency, .log-alert, .log-critical { color: #ff6b6b; }
        .log-error { color: #ff8c8c; }
        .log-warning { color: #ffd93d; }
        .log-notice { color: #6bcb77; }
        .log-info { color: #4d96ff; }
        .log-debug { color: #888; }
        .file-list {
            max-height: 400px;
            overflow-y: auto;
        }
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
                <a class="nav-link" href="logs.php">
                    <i class="bi bi-database"></i> DB Logs
                </a>
                <a class="nav-link" href="../dashboard.php">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-file-text"></i> File Logs</h2>
            <div>
                <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cleanupModal">
                    <i class="bi bi-trash"></i> Cleanup Old Logs
                </button>
            </div>
        </div>

        <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> Log file deleted successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['cleaned'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> Cleaned up <?= (int)$_GET['cleaned'] ?> old log file(s).
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- File List -->
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-folder"></i> Log Files
                        <span class="badge bg-secondary float-end"><?= count($logFiles) ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div class="file-list">
                            <?php if (empty($logFiles)): ?>
                                <p class="text-muted text-center py-4">No log files</p>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($logFiles as $logFile): ?>
                                        <a href="?file=<?= urlencode($logFile['name']) ?>"
                                           class="list-group-item list-group-item-action <?= $file === $logFile['name'] ? 'active' : '' ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <i class="bi bi-file-text me-1"></i>
                                                    <small><?= htmlspecialchars($logFile['name']) ?></small>
                                                </div>
                                                <small class="text-<?= $file === $logFile['name'] ? 'light' : 'muted' ?>">
                                                    <?= number_format($logFile['size'] / 1024, 1) ?> KB
                                                </small>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Log Content -->
            <div class="col-md-9">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-terminal"></i>
                            <?php if ($file): ?>
                                <?= htmlspecialchars($file) ?>
                            <?php else: ?>
                                Select a log file
                            <?php endif; ?>
                        </div>
                        <?php if ($file): ?>
                        <div>
                            <div class="btn-group btn-group-sm">
                                <a href="?file=<?= urlencode($file) ?>&lines=100" class="btn btn-outline-secondary <?= $lines == 100 ? 'active' : '' ?>">100</a>
                                <a href="?file=<?= urlencode($file) ?>&lines=200" class="btn btn-outline-secondary <?= $lines == 200 ? 'active' : '' ?>">200</a>
                                <a href="?file=<?= urlencode($file) ?>&lines=500" class="btn btn-outline-secondary <?= $lines == 500 ? 'active' : '' ?>">500</a>
                            </div>
                            <a href="?action=download&file=<?= urlencode($file) ?>" class="btn btn-sm btn-outline-primary ms-2">
                                <i class="bi bi-download"></i> Download
                            </a>
                            <button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteLog('<?= htmlspecialchars($file) ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($file && !empty($logContent)): ?>
                            <div class="log-viewer">
                                <?php foreach ($logContent as $line): ?>
                                    <?php
                                    $levelClass = 'log-debug';
                                    if (strpos($line, '[EMERGENCY]') !== false || strpos($line, '[ALERT]') !== false || strpos($line, '[CRITICAL]') !== false) {
                                        $levelClass = 'log-critical';
                                    } elseif (strpos($line, '[ERROR]') !== false) {
                                        $levelClass = 'log-error';
                                    } elseif (strpos($line, '[WARNING]') !== false) {
                                        $levelClass = 'log-warning';
                                    } elseif (strpos($line, '[NOTICE]') !== false) {
                                        $levelClass = 'log-notice';
                                    } elseif (strpos($line, '[INFO]') !== false) {
                                        $levelClass = 'log-info';
                                    }
                                    ?>
                                    <div class="log-line <?= $levelClass ?>"><?= htmlspecialchars($line) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($file): ?>
                            <p class="text-muted text-center py-5">Log file is empty</p>
                        <?php else: ?>
                            <p class="text-muted text-center py-5">
                                <i class="bi bi-arrow-left me-2"></i>Select a log file to view
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cleanup Modal -->
    <div class="modal fade" id="cleanupModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="?action=cleanup">
                    <div class="modal-header">
                        <h5 class="modal-title">Cleanup Old Logs</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Delete log files older than:</p>
                        <select name="days" class="form-select">
                            <option value="7">7 days</option>
                            <option value="14">14 days</option>
                            <option value="30" selected>30 days</option>
                            <option value="60">60 days</option>
                            <option value="90">90 days</option>
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Delete Old Logs
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Form -->
    <form id="deleteForm" method="POST" style="display:none;">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteLog(filename) {
            if (confirm('Delete log file: ' + filename + '?')) {
                document.getElementById('deleteForm').action = '?action=delete&file=' + encodeURIComponent(filename);
                document.getElementById('deleteForm').submit();
            }
        }

        // Auto-scroll to bottom
        const viewer = document.querySelector('.log-viewer');
        if (viewer) {
            viewer.scrollTop = viewer.scrollHeight;
        }
    </script>
</body>
</html>
