<?php

declare(strict_types=1);

namespace Modules\Matching;

use PDO;

/**
 * Manages the master product catalog and cross-platform mappings.
 * Handles CRUD operations for master_products and product_master_mapping tables.
 */
class MasterProductService
{
    private PDO $pdo;
    private SimilarityCalculator $calculator;
    private float $autoMatchThreshold;

    public function __construct(PDO $pdo, ?SimilarityCalculator $calculator = null, float $autoMatchThreshold = 0.85)
    {
        $this->pdo = $pdo;
        $this->calculator = $calculator ?? new SimilarityCalculator();
        $this->autoMatchThreshold = $autoMatchThreshold;
    }

    /**
     * Create a new master product.
     *
     * @param string $canonicalName
     * @param string|null $brand
     * @param string|null $category
     * @param array|null $attributes
     * @param string $confidence
     * @return int The created master_product_id
     */
    public function createMasterProduct(
        string $canonicalName,
        ?string $brand = null,
        ?string $category = null,
        ?array $attributes = null,
        string $confidence = 'review'
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO master_products (canonical_name, brand, category, normalized_attributes, match_confidence, created_at, updated_at)
            VALUES (:name, :brand, :category, :attrs, :confidence, NOW(), NOW())
        ");
        $stmt->execute([
            'name' => $canonicalName,
            'brand' => $brand,
            'category' => $category,
            'attrs' => $attributes ? json_encode($attributes, JSON_UNESCAPED_UNICODE) : null,
            'confidence' => $confidence,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Link a tracked product to a master product.
     *
     * @param int $productId tracked_products.product_id
     * @param int $masterProductId master_products.master_product_id
     * @param float|null $similarityScore
     * @param string $matchedBy auto|manual|review
     */
    public function linkProduct(int $productId, int $masterProductId, ?float $similarityScore = null, string $matchedBy = 'auto'): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO product_master_mapping (product_id, master_product_id, similarity_score, matched_by, created_at)
            VALUES (:product_id, :master_id, :score, :matched_by, NOW())
            ON DUPLICATE KEY UPDATE
                master_product_id = VALUES(master_product_id),
                similarity_score = VALUES(similarity_score),
                matched_by = VALUES(matched_by)
        ");
        $stmt->execute([
            'product_id' => $productId,
            'master_id' => $masterProductId,
            'score' => $similarityScore,
            'matched_by' => $matchedBy,
        ]);
    }

    /**
     * Unlink a tracked product from its master product.
     */
    public function unlinkProduct(int $productId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM product_master_mapping WHERE product_id = :product_id");
        $stmt->execute(['product_id' => $productId]);
    }

    /**
     * Find potential master product matches for a tracked product.
     *
     * @param int $productId
     * @param int $limit Maximum number of suggestions
     * @return array Array of potential matches with scores
     */
    public function findMatches(int $productId, int $limit = 5): array
    {
        // Get the tracked product info
        $stmt = $this->pdo->prepare("
            SELECT product_id, product_name, platform
            FROM tracked_products
            WHERE product_id = :product_id
        ");
        $stmt->execute(['product_id' => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product || empty($product['product_name'])) {
            return [];
        }

        // Extract brand and attributes from product name
        $productBrand = $this->calculator->extractBrand($product['product_name']);
        $productAttrs = $this->calculator->extractAttributes($product['product_name']);

        // Get all master products (in a real system, you'd want to limit/filter this)
        $stmt = $this->pdo->prepare("
            SELECT master_product_id, canonical_name, brand, category, normalized_attributes
            FROM master_products
            ORDER BY updated_at DESC
            LIMIT 500
        ");
        $stmt->execute();
        $masterProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matches = [];
        foreach ($masterProducts as $master) {
            $masterAttrs = $master['normalized_attributes']
                ? json_decode($master['normalized_attributes'], true)
                : null;

            $score = $this->calculator->calculateMatchScore(
                $product['product_name'],
                $master['canonical_name'],
                $productBrand,
                $master['brand'],
                $productAttrs,
                $masterAttrs
            );

            if ($score >= 0.5) { // Minimum threshold for suggestions
                $matches[] = [
                    'master_product_id' => (int) $master['master_product_id'],
                    'canonical_name' => $master['canonical_name'],
                    'brand' => $master['brand'],
                    'category' => $master['category'],
                    'similarity_score' => round($score, 4),
                    'confidence' => $this->calculator->getConfidenceLevel($score),
                ];
            }
        }

        // Sort by score descending
        usort($matches, fn($a, $b) => $b['similarity_score'] <=> $a['similarity_score']);

        return array_slice($matches, 0, $limit);
    }

    /**
     * Auto-match a product to a master product or create a new one.
     *
     * @param int $productId
     * @param string $productName
     * @param string $platform
     * @return array Result with action taken and master_product_id
     */
    public function autoMatch(int $productId, string $productName, string $platform): array
    {
        // Check if already mapped
        $stmt = $this->pdo->prepare("
            SELECT master_product_id FROM product_master_mapping WHERE product_id = :product_id
        ");
        $stmt->execute(['product_id' => $productId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            return [
                'action' => 'already_mapped',
                'master_product_id' => (int) $existing['master_product_id'],
            ];
        }

        // Find best match
        $matches = $this->findMatches($productId, 1);

        if (!empty($matches) && $matches[0]['similarity_score'] >= $this->autoMatchThreshold) {
            // Auto-link to existing master product
            $this->linkProduct($productId, $matches[0]['master_product_id'], $matches[0]['similarity_score'], 'auto');

            return [
                'action' => 'auto_linked',
                'master_product_id' => $matches[0]['master_product_id'],
                'similarity_score' => $matches[0]['similarity_score'],
            ];
        }

        // Create new master product
        $brand = $this->calculator->extractBrand($productName);
        $attrs = $this->calculator->extractAttributes($productName);

        $confidence = empty($matches) ? 'review' : 'low';
        $masterProductId = $this->createMasterProduct($productName, $brand, null, $attrs, $confidence);

        // Link to the new master product
        $this->linkProduct($productId, $masterProductId, 1.0, 'auto');

        return [
            'action' => 'created_new',
            'master_product_id' => $masterProductId,
        ];
    }

    /**
     * Get master product by ID.
     */
    public function getMasterProduct(int $masterProductId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT master_product_id, canonical_name, brand, category, normalized_attributes,
                   match_confidence, created_at, updated_at
            FROM master_products
            WHERE master_product_id = :id
        ");
        $stmt->execute(['id' => $masterProductId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['normalized_attributes']) {
            $result['normalized_attributes'] = json_decode($result['normalized_attributes'], true);
        }

        return $result ?: null;
    }

    /**
     * Get all tracked products linked to a master product.
     */
    public function getLinkedProducts(int $masterProductId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT tp.product_id, tp.platform, tp.product_name, tp.product_url,
                   tp.last_price, tp.last_checked_at,
                   pmm.similarity_score, pmm.matched_by, pmm.created_at as linked_at
            FROM product_master_mapping pmm
            JOIN tracked_products tp ON tp.product_id = pmm.product_id
            WHERE pmm.master_product_id = :master_id
            ORDER BY tp.platform, tp.last_price
        ");
        $stmt->execute(['master_id' => $masterProductId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get products pending review (low confidence or unmatched).
     */
    public function getProductsForReview(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT tp.product_id, tp.platform, tp.product_name, tp.product_url,
                   tp.last_price, tp.created_at,
                   mp.master_product_id, mp.canonical_name as master_name, mp.match_confidence,
                   pmm.similarity_score
            FROM tracked_products tp
            LEFT JOIN product_master_mapping pmm ON tp.product_id = pmm.product_id
            LEFT JOIN master_products mp ON pmm.master_product_id = mp.master_product_id
            WHERE pmm.product_id IS NULL
               OR mp.match_confidence IN ('low', 'review')
            ORDER BY tp.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get count of products pending review.
     */
    public function getReviewCount(): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count
            FROM tracked_products tp
            LEFT JOIN product_master_mapping pmm ON tp.product_id = pmm.product_id
            LEFT JOIN master_products mp ON pmm.master_product_id = mp.master_product_id
            WHERE pmm.product_id IS NULL
               OR mp.match_confidence IN ('low', 'review')
        ");
        $stmt->execute();
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    /**
     * Update master product confidence after manual review.
     */
    public function confirmMatch(int $masterProductId, string $newConfidence = 'high'): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE master_products
            SET match_confidence = :confidence, updated_at = NOW()
            WHERE master_product_id = :id
        ");
        $stmt->execute([
            'id' => $masterProductId,
            'confidence' => $newConfidence,
        ]);

        // Also update the mapping to 'manual'
        $stmt = $this->pdo->prepare("
            UPDATE product_master_mapping
            SET matched_by = 'manual'
            WHERE master_product_id = :id AND matched_by != 'manual'
        ");
        $stmt->execute(['id' => $masterProductId]);
    }

    /**
     * Update master product details.
     */
    public function updateMasterProduct(
        int $masterProductId,
        ?string $canonicalName = null,
        ?string $brand = null,
        ?string $category = null,
        ?array $attributes = null
    ): void {
        $updates = [];
        $params = ['id' => $masterProductId];

        if ($canonicalName !== null) {
            $updates[] = 'canonical_name = :name';
            $params['name'] = $canonicalName;
        }
        if ($brand !== null) {
            $updates[] = 'brand = :brand';
            $params['brand'] = $brand;
        }
        if ($category !== null) {
            $updates[] = 'category = :category';
            $params['category'] = $category;
        }
        if ($attributes !== null) {
            $updates[] = 'normalized_attributes = :attrs';
            $params['attrs'] = json_encode($attributes, JSON_UNESCAPED_UNICODE);
        }

        if (empty($updates)) {
            return;
        }

        $updates[] = 'updated_at = NOW()';
        $sql = "UPDATE master_products SET " . implode(', ', $updates) . " WHERE master_product_id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Get cross-platform price comparison for a master product.
     */
    public function getCrossPlatformPrices(int $masterProductId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT tp.platform, tp.product_name, tp.product_url,
                   tp.last_price, tp.last_original_price, tp.last_stock_status,
                   tp.last_checked_at
            FROM product_master_mapping pmm
            JOIN tracked_products tp ON tp.product_id = pmm.product_id
            WHERE pmm.master_product_id = :master_id
              AND tp.is_active = 1
            ORDER BY tp.last_price ASC
        ");
        $stmt->execute(['master_id' => $masterProductId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($products)) {
            return [];
        }

        // Find lowest price
        $lowestPrice = null;
        $lowestPlatform = null;
        foreach ($products as $product) {
            if ($product['last_price'] !== null) {
                if ($lowestPrice === null || $product['last_price'] < $lowestPrice) {
                    $lowestPrice = (float) $product['last_price'];
                    $lowestPlatform = $product['platform'];
                }
            }
        }

        return [
            'master_product_id' => $masterProductId,
            'lowest_price' => $lowestPrice,
            'lowest_platform' => $lowestPlatform,
            'platform_count' => count($products),
            'platforms' => $products,
        ];
    }

    /**
     * Set auto-match threshold.
     */
    public function setAutoMatchThreshold(float $threshold): void
    {
        $this->autoMatchThreshold = $threshold;
    }
}
