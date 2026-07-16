<?php

/**
 * List Products API
 *
 * GET /api/products/list.php
 *
 * Query params:
 *   - active_only: bool (default true)
 *
 * Response:
 *   { success: bool, data: { products: [...] }, message: string }
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

    $activeOnly = !isset($_GET['active_only']) || $_GET['active_only'] !== 'false';

    $service = new TrackingService($pdo);
    $products = $service->getUserProducts(Auth::userId(), $activeOnly);

    echo json_encode([
        'success' => true,
        'data' => [
            'products' => $products,
            'count' => count($products),
        ],
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
