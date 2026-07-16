<?php

/**
 * API: Unlink a product from its master product.
 *
 * POST /api/matching/unlink.php
 * Body: { "product_id": 123 }
 *
 * Response:
 * {
 *   "success": true,
 *   "message": "Product unlinked successfully"
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';
require_once __DIR__ . '/../../modules/matching/MasterProductService.php';

use Core\Auth;
use Core\Csrf;
use Modules\Matching\MasterProductService;

try {
    // Require login
    Auth::requireLogin();

    // Only POST allowed
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('Method not allowed');
    }

    // Parse JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new InvalidArgumentException('Invalid JSON body');
    }

    // Validate CSRF (if provided)
    if (isset($input['csrf_token'])) {
        Csrf::verify($input['csrf_token']);
    }

    // Validate product_id
    $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$productId) {
        throw new InvalidArgumentException('product_id is required');
    }

    // Verify product exists
    $stmt = $pdo->prepare("SELECT product_id FROM tracked_products WHERE product_id = :id");
    $stmt->execute(['id' => $productId]);
    if (!$stmt->fetch()) {
        throw new InvalidArgumentException('Product not found');
    }

    $service = new MasterProductService($pdo);
    $service->unlinkProduct($productId);

    echo json_encode([
        'success' => true,
        'message' => 'Product unlinked successfully',
    ], JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
    ], JSON_UNESCAPED_UNICODE);
}
