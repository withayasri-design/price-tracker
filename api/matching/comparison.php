<?php

/**
 * API: Get cross-platform price comparison for a master product.
 *
 * GET /api/matching/comparison.php?master_product_id=123
 *
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "master_product": { ... },
 *     "lowest_price": 9990.00,
 *     "lowest_platform": "shopee",
 *     "platforms": [
 *       { "platform": "shopee", "price": 9990.00, ... },
 *       { "platform": "lazada", "price": 10500.00, ... }
 *     ],
 *     "price_difference": { "amount": 510.00, "percent": 5.11 }
 *   }
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../modules/matching/MasterProductService.php';

use Core\Auth;
use Modules\Matching\MasterProductService;

try {
    // Require login
    Auth::requireLogin();

    // Validate input
    $masterProductId = filter_input(INPUT_GET, 'master_product_id', FILTER_VALIDATE_INT);
    if (!$masterProductId) {
        throw new InvalidArgumentException('master_product_id is required');
    }

    $service = new MasterProductService($pdo);

    // Get master product info
    $master = $service->getMasterProduct($masterProductId);
    if (!$master) {
        throw new InvalidArgumentException('Master product not found');
    }

    // Get cross-platform prices
    $comparison = $service->getCrossPlatformPrices($masterProductId);

    if (empty($comparison['platforms'])) {
        echo json_encode([
            'success' => true,
            'data' => [
                'master_product' => $master,
                'platforms' => [],
                'message' => 'No linked products found',
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Calculate price statistics
    $prices = array_filter(array_column($comparison['platforms'], 'last_price'), fn($p) => $p !== null);
    $lowestPrice = !empty($prices) ? min($prices) : null;
    $highestPrice = !empty($prices) ? max($prices) : null;

    $priceDifference = null;
    if ($lowestPrice && $highestPrice && $lowestPrice > 0) {
        $priceDifference = [
            'amount' => round($highestPrice - $lowestPrice, 2),
            'percent' => round((($highestPrice - $lowestPrice) / $lowestPrice) * 100, 2),
        ];
    }

    // Add savings info to each platform
    foreach ($comparison['platforms'] as &$platform) {
        if ($platform['last_price'] !== null && $lowestPrice !== null) {
            $platform['last_price'] = (float) $platform['last_price'];
            $platform['is_lowest'] = $platform['last_price'] == $lowestPrice;
            if (!$platform['is_lowest'] && $lowestPrice > 0) {
                $platform['savings_if_switch'] = [
                    'amount' => round($platform['last_price'] - $lowestPrice, 2),
                    'percent' => round((($platform['last_price'] - $lowestPrice) / $platform['last_price']) * 100, 2),
                ];
            }
        }
        if ($platform['last_original_price'] !== null) {
            $platform['last_original_price'] = (float) $platform['last_original_price'];
            $platform['discount_percent'] = round(
                (($platform['last_original_price'] - $platform['last_price']) / $platform['last_original_price']) * 100,
                1
            );
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'master_product' => $master,
            'lowest_price' => $lowestPrice,
            'highest_price' => $highestPrice,
            'lowest_platform' => $comparison['lowest_platform'],
            'platform_count' => $comparison['platform_count'],
            'platforms' => $comparison['platforms'],
            'price_difference' => $priceDifference,
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
