<?php
/**
 * Data Export API
 *
 * Export user data in various formats (CSV, JSON).
 *
 * GET /api/export.php?type=products&format=csv
 * GET /api/export.php?type=history&product_id=123&format=json
 * GET /api/export.php?type=events&format=csv
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';

use Core\Auth;

// Require authentication
Auth::requireLogin();
$userId = Auth::userId();

// Parameters
$type = $_GET['type'] ?? 'products';
$format = $_GET['format'] ?? 'csv';
$productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : null;

// Validate format
if (!in_array($format, ['csv', 'json'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid format. Use csv or json.']);
    exit;
}

// Get data based on type
$data = [];
$filename = '';

switch ($type) {
    case 'products':
        // Export tracked products
        $stmt = $pdo->prepare("
            SELECT
                tp.product_id,
                tp.platform,
                tp.product_name,
                tp.product_url,
                tp.last_price,
                tp.last_original_price,
                tp.last_stock_status,
                ut.target_price,
                ut.target_discount_percent,
                ut.label,
                tp.created_at,
                tp.updated_at
            FROM tracked_products tp
            JOIN user_tracking ut ON tp.product_id = ut.product_id
            WHERE ut.user_id = ? AND ut.is_active = 1
            ORDER BY tp.updated_at DESC
        ");
        $stmt->execute([$userId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'products_' . date('Y-m-d');
        break;

    case 'history':
        // Export price history for a product
        if (!$productId) {
            http_response_code(400);
            echo json_encode(['error' => 'product_id is required for history export.']);
            exit;
        }

        // Verify user has access to this product
        $stmt = $pdo->prepare("
            SELECT 1 FROM user_tracking
            WHERE user_id = ? AND product_id = ?
        ");
        $stmt->execute([$userId, $productId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied to this product.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT
                ph.price,
                ph.original_price,
                ph.discount_percent,
                ph.stock_status,
                ph.scraped_at
            FROM price_history ph
            WHERE ph.product_id = ?
            ORDER BY ph.scraped_at DESC
            LIMIT 1000
        ");
        $stmt->execute([$productId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'price_history_' . $productId . '_' . date('Y-m-d');
        break;

    case 'events':
        // Export price events
        $stmt = $pdo->prepare("
            SELECT
                pe.event_id,
                tp.platform,
                tp.product_name,
                pe.event_type,
                pe.old_price,
                pe.new_price,
                pe.change_percent,
                pe.created_at
            FROM price_events pe
            JOIN tracked_products tp ON pe.product_id = tp.product_id
            JOIN user_tracking ut ON tp.product_id = ut.product_id
            WHERE ut.user_id = ?
            ORDER BY pe.created_at DESC
            LIMIT 500
        ");
        $stmt->execute([$userId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'price_events_' . date('Y-m-d');
        break;

    case 'alerts':
        // Export sent alerts
        $stmt = $pdo->prepare("
            SELECT
                a.alert_id,
                tp.platform,
                tp.product_name,
                a.alert_type,
                a.triggered_price,
                a.sent_at,
                a.dispatch_channel
            FROM alerts a
            JOIN tracked_products tp ON a.product_id = tp.product_id
            WHERE a.user_id = ? AND a.sent_at IS NOT NULL
            ORDER BY a.sent_at DESC
            LIMIT 500
        ");
        $stmt->execute([$userId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'alerts_' . date('Y-m-d');
        break;

    case 'all':
        // Export all user data (GDPR compliance)
        $export = [
            'exported_at' => date('c'),
            'user' => [],
            'products' => [],
            'events' => [],
            'alerts' => [],
        ];

        // User data
        $stmt = $pdo->prepare("
            SELECT user_id, email, full_name, role, notify_email, notify_line, created_at
            FROM users WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $export['user'] = $stmt->fetch(PDO::FETCH_ASSOC);

        // Products
        $stmt = $pdo->prepare("
            SELECT tp.*, ut.target_price, ut.target_discount_percent, ut.label
            FROM tracked_products tp
            JOIN user_tracking ut ON tp.product_id = ut.product_id
            WHERE ut.user_id = ?
        ");
        $stmt->execute([$userId]);
        $export['products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Events
        $stmt = $pdo->prepare("
            SELECT pe.*
            FROM price_events pe
            JOIN user_tracking ut ON pe.product_id = ut.product_id
            WHERE ut.user_id = ?
            ORDER BY pe.created_at DESC
            LIMIT 1000
        ");
        $stmt->execute([$userId]);
        $export['events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Alerts
        $stmt = $pdo->prepare("
            SELECT * FROM alerts WHERE user_id = ? ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        $export['alerts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Force JSON for full export
        $format = 'json';
        $data = $export;
        $filename = 'full_export_' . date('Y-m-d');
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid export type.']);
        exit;
}

// Output based on format
if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} else {
    // CSV format
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    // BOM for Excel UTF-8 compatibility
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    if (!empty($data)) {
        // Header row
        fputcsv($output, array_keys($data[0]));

        // Data rows
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }

    fclose($output);
}
