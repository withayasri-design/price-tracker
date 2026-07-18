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
require_once __DIR__ . '/../../config/logging.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';
require_once __DIR__ . '/../../modules/scraping/UrlParser.php';
require_once __DIR__ . '/../../modules/scraping/ScrapingService.php';
require_once __DIR__ . '/../../modules/tracking/TrackingService.php';

use Core\Auth;
use Core\Csrf;
use Modules\Scraping\ScrapingService;
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

    $trackingService = new TrackingService($pdo);

    // Get tracking info and verify ownership
    $tracking = $trackingService->getTracking(Auth::userId(), $trackingId);

    if (!$tracking) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'ไม่พบรายการที่ต้องการรีเฟรช',
        ]);
        exit;
    }

    // Rate limit: no refresh within 1 minute
    if ($tracking['last_checked_at']) {
        $lastCheck = strtotime($tracking['last_checked_at']);
        $elapsed = time() - $lastCheck;
        if ($elapsed < 60) {
            echo json_encode([
                'success' => false,
                'message' => 'กรุณารออีก ' . (60 - $elapsed) . ' วินาที',
            ]);
            exit;
        }
    }

    // Scrape immediately
    $scrapingService = new ScrapingService($pdo);
    $result = $scrapingService->scrapeAndSave(
        (int) $tracking['product_id'],
        'manual',
        Auth::userId()
    );

    if ($result['success']) {
        log_info('Product refreshed', [
            'user_id' => Auth::userId(),
            'product_id' => $tracking['product_id'],
            'price' => $result['data']['price'] ?? null,
        ], 'api');

        echo json_encode([
            'success' => true,
            'message' => 'รีเฟรชสำเร็จ! ราคาล่าสุด: ฿' . number_format((float)($result['data']['price'] ?? 0), 2),
            'data' => $result['data'] ?? null,
        ]);
    } else {
        log_warning('Product refresh failed', [
            'user_id' => Auth::userId(),
            'product_id' => $tracking['product_id'],
            'error' => $result['error'] ?? 'Unknown error',
        ], 'api');

        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถดึงข้อมูลได้: ' . ($result['error'] ?? 'Unknown error'),
        ]);
    }

} catch (\Exception $e) {
    log_error('Refresh exception', [
        'tracking_id' => $trackingId ?? null,
        'error' => $e->getMessage(),
    ], 'api');

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
