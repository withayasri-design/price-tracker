<?php

/**
 * Price History API
 *
 * GET /api/products/history.php?tracking_id=123&days=30
 *
 * Query params:
 *   - tracking_id: int (required)
 *   - days: int (optional, default 30, max 90)
 *
 * Response:
 *   {
 *     success: bool,
 *     data: {
 *       product: {...},
 *       history: [...],
 *       stats: { min_price, max_price, avg_price, ... }
 *     }
 *   }
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../modules/scraping/UrlParser.php';
require_once __DIR__ . '/../../modules/tracking/TrackingService.php';

use Core\Auth;
use Modules\Tracking\TrackingService;

try {
    // Require authentication
    Auth::startSession();
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
        exit;
    }

    // Require GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Validate input
    $trackingId = isset($_GET['tracking_id']) ? (int) $_GET['tracking_id'] : 0;
    if ($trackingId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'tracking_id is required']);
        exit;
    }

    $days = isset($_GET['days']) ? min(90, max(1, (int) $_GET['days'])) : 30;

    $service = new TrackingService($pdo);

    // Get tracking details (verifies ownership)
    $tracking = $service->getTracking(Auth::userId(), $trackingId);
    if (!$tracking) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'ไม่พบรายการที่ต้องการ']);
        exit;
    }

    // Get price history
    $history = $service->getPriceHistory((int) $tracking['product_id'], $days);

    // Get price statistics
    $stats = $service->getPriceStats((int) $tracking['product_id'], $days);

    // Format response
    echo json_encode([
        'success' => true,
        'data' => [
            'product' => [
                'tracking_id' => $tracking['tracking_id'],
                'product_id' => $tracking['product_id'],
                'platform' => $tracking['platform'],
                'product_name' => $tracking['product_name'],
                'image_url' => $tracking['image_url'],
                'product_url' => $tracking['product_url'],
                'last_price' => $tracking['last_price'],
                'last_original_price' => $tracking['last_original_price'],
                'last_stock_status' => $tracking['last_stock_status'],
                'last_checked_at' => $tracking['last_checked_at'],
                'target_price' => $tracking['target_price'],
                'target_discount_percent' => $tracking['target_discount_percent'],
            ],
            'history' => $history,
            'stats' => [
                'min_price' => $stats['min_price'] ?? null,
                'max_price' => $stats['max_price'] ?? null,
                'avg_price' => $stats['avg_price'] ? round((float) $stats['avg_price'], 2) : null,
                'current_price' => $stats['current_price'] ?? null,
                'first_price' => $stats['first_price'] ?? null,
                'data_points' => (int) ($stats['data_points'] ?? 0),
                'days' => $days,
            ],
        ],
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
