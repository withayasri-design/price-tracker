<?php

/**
 * Refresh Product API
 *
 * POST /api/products/refresh.php
 *
 * Request:
 *   - tracking_id: int (required)
 *
 * Response:
 *   { success: bool, message: string }
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';
require_once __DIR__ . '/../../modules/scraping/UrlParser.php';
require_once __DIR__ . '/../../modules/tracking/TrackingService.php';

use Core\Auth;
use Core\Csrf;
use Modules\Tracking\TrackingService;

try {
    // Require authentication
    Auth::startSession();
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
        exit;
    }

    // Require POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Get JSON input or form data
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    // Verify CSRF
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Csrf::check($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    // Validate input
    $trackingId = isset($input['tracking_id']) ? (int) $input['tracking_id'] : 0;
    if ($trackingId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid tracking ID']);
        exit;
    }

    $service = new TrackingService($pdo);
    $result = $service->requestRefresh(Auth::userId(), $trackingId);

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'ส่งคำขอรีเฟรชสำเร็จ ราคาจะอัปเดตในอีกสักครู่',
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่พบรายการที่ต้องการรีเฟรช',
        ]);
    }

} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
