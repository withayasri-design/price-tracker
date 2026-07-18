<?php
/**
 * Data Export Page
 *
 * Allows users to export their tracked products, price history, and events.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';

use Core\Auth;

Auth::requireLogin();
$userId = Auth::userId();

// Get user's product count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM user_tracking WHERE user_id = ? AND is_active = 1");
$stmt->execute([$userId]);
$productCount = (int) $stmt->fetchColumn();

// Get events count
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM price_events pe
    JOIN user_tracking ut ON pe.product_id = ut.product_id
    WHERE ut.user_id = ?
");
$stmt->execute([$userId]);
$eventCount = (int) $stmt->fetchColumn();

// Get alerts count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM alerts WHERE user_id = ? AND sent_at IS NOT NULL");
$stmt->execute([$userId]);
$alertCount = (int) $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Data - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-cart-check"></i> Price Tracker
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">
                    <i class="bi bi-arrow-left"></i> Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <h2 class="mb-4"><i class="bi bi-download"></i> Export Your Data</h2>

                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-box"></i> Tracked Products
                        <span class="badge bg-primary float-end"><?= number_format($productCount) ?> items</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Export all products you're currently tracking, including target prices and labels.
                        </p>
                        <div class="btn-group">
                            <a href="../api/export.php?type=products&format=csv" class="btn btn-outline-primary">
                                <i class="bi bi-filetype-csv"></i> Download CSV
                            </a>
                            <a href="../api/export.php?type=products&format=json" class="btn btn-outline-secondary">
                                <i class="bi bi-filetype-json"></i> Download JSON
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-graph-up"></i> Price Events
                        <span class="badge bg-success float-end"><?= number_format($eventCount) ?> events</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Export price changes detected for your tracked products (price drops, increases, stock changes).
                        </p>
                        <div class="btn-group">
                            <a href="../api/export.php?type=events&format=csv" class="btn btn-outline-primary">
                                <i class="bi bi-filetype-csv"></i> Download CSV
                            </a>
                            <a href="../api/export.php?type=events&format=json" class="btn btn-outline-secondary">
                                <i class="bi bi-filetype-json"></i> Download JSON
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-bell"></i> Alert History
                        <span class="badge bg-info float-end"><?= number_format($alertCount) ?> alerts</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Export all notifications that have been sent to you via email or LINE.
                        </p>
                        <div class="btn-group">
                            <a href="../api/export.php?type=alerts&format=csv" class="btn btn-outline-primary">
                                <i class="bi bi-filetype-csv"></i> Download CSV
                            </a>
                            <a href="../api/export.php?type=alerts&format=json" class="btn btn-outline-secondary">
                                <i class="bi bi-filetype-json"></i> Download JSON
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card border-warning">
                    <div class="card-header bg-warning text-dark">
                        <i class="bi bi-database"></i> Full Data Export (GDPR)
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Export all your data in a single JSON file. This includes your profile, all tracked products, price history, events, and alerts.
                        </p>
                        <a href="../api/export.php?type=all&format=json" class="btn btn-warning">
                            <i class="bi bi-download"></i> Download All My Data
                        </a>
                    </div>
                </div>

                <div class="card mt-4 border-0 bg-transparent">
                    <div class="card-body text-center text-muted">
                        <small>
                            <i class="bi bi-info-circle"></i>
                            CSV files include a UTF-8 BOM for Excel compatibility.<br>
                            JSON files are formatted for readability.
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
