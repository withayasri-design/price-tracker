<?php

/**
 * Add Product API
 *
 * POST /api/products/add.php
 *
 * Request:
 *   - url: string (required) - Product URL
 *   - label: string (optional) - Custom label
 *   - target_price: float (optional) - Target price threshold
 *   - target_discount_percent: float (optional) - Target discount percentage
 *
 * Response:
 *   { success: bool, data: { tracking_id, product_id, platform }, message: string }
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
    $url = trim($input['url'] ?? '');
    if (empty($url)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'กรุณาใส่ URL สินค้า']);
        exit;
    }

    $label = isset($input['label']) ? trim($input['label']) : null;
    $targetPrice = isset($input['target_price']) && $input['target_price'] !== ''
        ? (float) $input['target_price']
        : null;
    $targetDiscountPercent = isset($input['target_discount_percent']) && $input['target_discount_percent'] !== ''
        ? (float) $input['target_discount_percent']
        : null;

    // Validate label length
    if ($label !== null && mb_strlen($label) > 200) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ชื่อเรียกต้องไม่เกิน 200 ตัวอักษร']);
        exit;
    }

    // Validate target values
    if ($targetPrice !== null && $targetPrice <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ราคาเป้าหมายต้องมากกว่า 0']);
        exit;
    }

    if ($targetDiscountPercent !== null && ($targetDiscountPercent <= 0 || $targetDiscountPercent > 100)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ส่วนลดเป้าหมายต้องอยู่ระหว่าง 1-100%']);
        exit;
    }

    // Add product
    $service = new TrackingService($pdo);
    $result = $service->addProduct(
        Auth::userId(),
        $url,
        $label,
        $targetPrice,
        $targetDiscountPercent
    );

    echo json_encode([
        'success' => true,
        'data' => $result,
        'message' => 'เพิ่มสินค้าสำเร็จ',
    ]);

} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
