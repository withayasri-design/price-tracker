<?php

/**
 * API: Get master product match suggestions for a tracked product.
 *
 * GET /api/matching/suggestions.php?product_id=123
 *
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "product": { ... },
 *     "suggestions": [
 *       { "master_product_id": 1, "canonical_name": "...", "similarity_score": 0.92, ... }
 *     ]
 *   }
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../modules/matching/SimilarityCalculator.php';
require_once __DIR__ . '/../../modules/matching/MasterProductService.php';

use Core\Auth;
use Modules\Matching\MasterProductService;

try {
    // Require login
    Auth::requireLogin();

    // Validate input
    $productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT);
    if (!$productId) {
        throw new InvalidArgumentException('product_id is required');
    }

    // Get product info
    $stmt = $pdo->prepare("
        SELECT product_id, platform, product_name, product_url, last_price
        FROM tracked_products
        WHERE product_id = :id
    ");
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new InvalidArgumentException('Product not found');
    }

    // Get current mapping if exists
    $stmt = $pdo->prepare("
        SELECT pmm.master_product_id, pmm.similarity_score, pmm.matched_by,
               mp.canonical_name, mp.brand, mp.match_confidence
        FROM product_master_mapping pmm
        JOIN master_products mp ON pmm.master_product_id = mp.master_product_id
        WHERE pmm.product_id = :id
    ");
    $stmt->execute(['id' => $productId]);
    $currentMapping = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get suggestions
    $service = new MasterProductService($pdo);
    $suggestions = $service->findMatches($productId, 10);

    echo json_encode([
        'success' => true,
        'data' => [
            'product' => $product,
            'current_mapping' => $currentMapping ?: null,
            'suggestions' => $suggestions,
        ],
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
