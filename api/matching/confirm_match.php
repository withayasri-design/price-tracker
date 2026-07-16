<?php

/**
 * API: Confirm or create a product-to-master mapping.
 *
 * POST /api/matching/confirm_match.php
 * Body: { "product_id": 123, "master_product_id": 456 }
 *   OR: { "product_id": 123, "create_new": true, "canonical_name": "Product Name" }
 *
 * Response:
 * {
 *   "success": true,
 *   "data": { "master_product_id": 456, "action": "linked" }
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';
require_once __DIR__ . '/../../modules/matching/SimilarityCalculator.php';
require_once __DIR__ . '/../../modules/matching/MasterProductService.php';

use Core\Auth;
use Core\Csrf;
use Modules\Matching\MasterProductService;
use Modules\Matching\SimilarityCalculator;

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
    $stmt = $pdo->prepare("SELECT product_id, product_name FROM tracked_products WHERE product_id = :id");
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        throw new InvalidArgumentException('Product not found');
    }

    $service = new MasterProductService($pdo);
    $calculator = new SimilarityCalculator();

    $createNew = !empty($input['create_new']);

    if ($createNew) {
        // Create new master product
        $canonicalName = trim($input['canonical_name'] ?? $product['product_name']);
        $brand = $input['brand'] ?? $calculator->extractBrand($canonicalName);
        $category = $input['category'] ?? null;
        $attributes = $calculator->extractAttributes($canonicalName);

        $masterProductId = $service->createMasterProduct(
            $canonicalName,
            $brand,
            $category,
            $attributes,
            'high' // Manual creation = high confidence
        );

        $service->linkProduct($productId, $masterProductId, 1.0, 'manual');

        echo json_encode([
            'success' => true,
            'data' => [
                'master_product_id' => $masterProductId,
                'action' => 'created',
            ],
        ], JSON_UNESCAPED_UNICODE);

    } else {
        // Link to existing master product
        $masterProductId = filter_var($input['master_product_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$masterProductId) {
            throw new InvalidArgumentException('master_product_id is required when not creating new');
        }

        // Verify master product exists
        $master = $service->getMasterProduct($masterProductId);
        if (!$master) {
            throw new InvalidArgumentException('Master product not found');
        }

        // Calculate similarity for record
        $similarityScore = $calculator->calculateMatchScore(
            $product['product_name'],
            $master['canonical_name'],
            $calculator->extractBrand($product['product_name']),
            $master['brand']
        );

        $service->linkProduct($productId, $masterProductId, $similarityScore, 'manual');
        $service->confirmMatch($masterProductId, 'high');

        echo json_encode([
            'success' => true,
            'data' => [
                'master_product_id' => $masterProductId,
                'action' => 'linked',
                'similarity_score' => round($similarityScore, 4),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

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
