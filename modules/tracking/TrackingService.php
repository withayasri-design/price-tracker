<?php

/**
 * Tracking Service
 *
 * Handles product tracking operations for users.
 */

declare(strict_types=1);

namespace Modules\Tracking;

use PDO;
use Modules\Scraping\UrlParser;

class TrackingService
{
    private PDO $pdo;
    private UrlParser $urlParser;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->urlParser = new UrlParser();
    }

    /**
     * Add a product to user's tracking list.
     *
     * @param int $userId
     * @param string $url Product URL
     * @param string|null $label Optional label
     * @param float|null $targetPrice Target price threshold
     * @param float|null $targetDiscountPercent Target discount percentage
     * @return array Result with tracking info
     * @throws \Exception on validation failure
     */
    public function addProduct(
        int $userId,
        string $url,
        ?string $label = null,
        ?float $targetPrice = null,
        ?float $targetDiscountPercent = null
    ): array {
        // Parse the URL
        $parsed = $this->urlParser->parse($url);
        if (!$parsed) {
            throw new \Exception('ไม่รองรับ URL นี้ กรุณาใส่ลิงก์สินค้าจากร้านค้าที่รองรับ');
        }

        $platform = $parsed['platform'];
        $platformProductId = $parsed['product_id'];
        $productUrl = $parsed['url'];

        // Check if product already exists in tracked_products
        $stmt = $this->pdo->prepare("
            SELECT product_id FROM tracked_products
            WHERE platform = :platform AND platform_product_id = :pid
            LIMIT 1
        ");
        $stmt->execute([
            'platform' => $platform,
            'pid' => $platformProductId,
        ]);
        $existingProduct = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingProduct) {
            $productId = (int) $existingProduct['product_id'];
        } else {
            // Create new tracked product
            $stmt = $this->pdo->prepare("
                INSERT INTO tracked_products (platform, platform_product_id, product_url, is_active, created_at)
                VALUES (:platform, :pid, :url, 1, NOW())
            ");
            $stmt->execute([
                'platform' => $platform,
                'pid' => $platformProductId,
                'url' => $productUrl,
            ]);
            $productId = (int) $this->pdo->lastInsertId();
        }

        // Check if user is already tracking this product
        $stmt = $this->pdo->prepare("
            SELECT tracking_id, is_active FROM user_tracking
            WHERE user_id = :uid AND product_id = :pid
            LIMIT 1
        ");
        $stmt->execute([
            'uid' => $userId,
            'pid' => $productId,
        ]);
        $existingTracking = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingTracking) {
            if ($existingTracking['is_active']) {
                throw new \Exception('คุณติดตามสินค้านี้อยู่แล้ว');
            }

            // Reactivate existing tracking
            $stmt = $this->pdo->prepare("
                UPDATE user_tracking
                SET is_active = 1,
                    label = :label,
                    target_price = :target_price,
                    target_discount_percent = :target_discount
                WHERE tracking_id = :tid
            ");
            $stmt->execute([
                'label' => $label,
                'target_price' => $targetPrice,
                'target_discount' => $targetDiscountPercent,
                'tid' => $existingTracking['tracking_id'],
            ]);
            $trackingId = (int) $existingTracking['tracking_id'];
        } else {
            // Create new user tracking
            $stmt = $this->pdo->prepare("
                INSERT INTO user_tracking (user_id, product_id, label, target_price, target_discount_percent, is_active, created_at)
                VALUES (:uid, :pid, :label, :target_price, :target_discount, 1, NOW())
            ");
            $stmt->execute([
                'uid' => $userId,
                'pid' => $productId,
                'label' => $label,
                'target_price' => $targetPrice,
                'target_discount' => $targetDiscountPercent,
            ]);
            $trackingId = (int) $this->pdo->lastInsertId();
        }

        // Queue scraping job for this product
        $this->queueScrapeJob($productId);

        return [
            'tracking_id' => $trackingId,
            'product_id' => $productId,
            'platform' => $platform,
            'url' => $productUrl,
        ];
    }

    /**
     * Get user's tracked products.
     *
     * @param int $userId
     * @param bool $activeOnly Only return active tracking
     * @return array
     */
    public function getUserProducts(int $userId, bool $activeOnly = true): array
    {
        $sql = "
            SELECT
                ut.tracking_id,
                ut.label,
                ut.target_price,
                ut.target_discount_percent,
                ut.is_active as tracking_active,
                ut.created_at as tracking_created,
                tp.product_id,
                tp.platform,
                tp.platform_product_id,
                tp.product_url,
                tp.product_name,
                tp.image_url,
                tp.last_price,
                tp.last_original_price,
                tp.last_stock_status,
                tp.last_checked_at,
                CASE
                    WHEN tp.last_original_price > 0 AND tp.last_price > 0
                    THEN ROUND((1 - tp.last_price / tp.last_original_price) * 100, 1)
                    ELSE 0
                END as current_discount_percent
            FROM user_tracking ut
            JOIN tracked_products tp ON ut.product_id = tp.product_id
            WHERE ut.user_id = :user_id
        ";

        if ($activeOnly) {
            $sql .= " AND ut.is_active = 1";
        }

        $sql .= " ORDER BY ut.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update tracking settings.
     *
     * @param int $userId
     * @param int $trackingId
     * @param array $data Fields to update
     * @return bool
     * @throws \Exception if not found or not owned
     */
    public function updateTracking(int $userId, int $trackingId, array $data): bool
    {
        // Verify ownership
        $stmt = $this->pdo->prepare("
            SELECT tracking_id FROM user_tracking
            WHERE tracking_id = :tid AND user_id = :uid
        ");
        $stmt->execute(['tid' => $trackingId, 'uid' => $userId]);

        if (!$stmt->fetch()) {
            throw new \Exception('ไม่พบรายการที่ต้องการแก้ไข');
        }

        $allowedFields = ['label', 'target_price', 'target_discount_percent', 'is_active'];
        $updates = [];
        $params = ['tid' => $trackingId];

        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updates[] = "{$field} = :{$field}";
                $params[$field] = $value;
            }
        }

        if (empty($updates)) {
            return true; // Nothing to update
        }

        $sql = "UPDATE user_tracking SET " . implode(', ', $updates) . " WHERE tracking_id = :tid";
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Remove product from user's tracking.
     *
     * @param int $userId
     * @param int $trackingId
     * @return bool
     */
    public function removeTracking(int $userId, int $trackingId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE user_tracking
            SET is_active = 0
            WHERE tracking_id = :tid AND user_id = :uid
        ");

        return $stmt->execute(['tid' => $trackingId, 'uid' => $userId]);
    }

    /**
     * Permanently delete tracking record.
     *
     * @param int $userId
     * @param int $trackingId
     * @return bool
     */
    public function deleteTracking(int $userId, int $trackingId): bool
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM user_tracking
            WHERE tracking_id = :tid AND user_id = :uid
        ");

        return $stmt->execute(['tid' => $trackingId, 'uid' => $userId]);
    }

    /**
     * Get single tracking details.
     *
     * @param int $userId
     * @param int $trackingId
     * @return array|null
     */
    public function getTracking(int $userId, int $trackingId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                ut.*,
                tp.platform,
                tp.platform_product_id,
                tp.product_url,
                tp.product_name,
                tp.image_url,
                tp.last_price,
                tp.last_original_price,
                tp.last_stock_status,
                tp.last_checked_at
            FROM user_tracking ut
            JOIN tracked_products tp ON ut.product_id = tp.product_id
            WHERE ut.tracking_id = :tid AND ut.user_id = :uid
        ");
        $stmt->execute(['tid' => $trackingId, 'uid' => $userId]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get price history for a tracked product.
     *
     * @param int $productId
     * @param int $days Number of days to look back
     * @return array
     */
    public function getPriceHistory(int $productId, int $days = 30): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                price,
                original_price,
                discount_percent,
                stock_status,
                scraped_at
            FROM price_history
            WHERE product_id = :pid
              AND scraped_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ORDER BY scraped_at ASC
        ");
        $stmt->execute(['pid' => $productId, 'days' => $days]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get price statistics for a product.
     *
     * @param int $productId
     * @param int $days
     * @return array
     */
    public function getPriceStats(int $productId, int $days = 30): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                MIN(price) as min_price,
                MAX(price) as max_price,
                AVG(price) as avg_price,
                COUNT(*) as data_points,
                (SELECT price FROM price_history
                 WHERE product_id = :pid1 ORDER BY scraped_at DESC LIMIT 1) as current_price,
                (SELECT price FROM price_history
                 WHERE product_id = :pid2 ORDER BY scraped_at ASC LIMIT 1) as first_price
            FROM price_history
            WHERE product_id = :pid3
              AND scraped_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $stmt->execute([
            'pid1' => $productId,
            'pid2' => $productId,
            'pid3' => $productId,
            'days' => $days,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Queue a scrape job for a product.
     *
     * @param int $productId
     */
    private function queueScrapeJob(int $productId): void
    {
        // Check if there's already a pending job
        $stmt = $this->pdo->prepare("
            SELECT job_id FROM agent_job_queue
            WHERE agent_type = 'scraper'
              AND status = 'pending'
              AND JSON_EXTRACT(payload, '$.product_id') = :pid
            LIMIT 1
        ");
        $stmt->execute(['pid' => $productId]);

        if ($stmt->fetch()) {
            return; // Job already queued
        }

        // Queue new job
        $stmt = $this->pdo->prepare("
            INSERT INTO agent_job_queue (agent_type, payload, priority, status, created_at)
            VALUES ('scraper', :payload, 8, 'pending', NOW())
        ");
        $stmt->execute([
            'payload' => json_encode([
                'product_id' => $productId,
                'trigger' => 'user_add',
            ]),
        ]);
    }

    /**
     * Request immediate refresh for a product.
     *
     * @param int $userId
     * @param int $trackingId
     * @return bool
     */
    public function requestRefresh(int $userId, int $trackingId): bool
    {
        // Get product ID and verify ownership
        $stmt = $this->pdo->prepare("
            SELECT ut.product_id, tp.last_checked_at
            FROM user_tracking ut
            JOIN tracked_products tp ON ut.product_id = tp.product_id
            WHERE ut.tracking_id = :tid AND ut.user_id = :uid
        ");
        $stmt->execute(['tid' => $trackingId, 'uid' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        // Rate limit: no refresh within 5 minutes
        if ($result['last_checked_at']) {
            $lastCheck = strtotime($result['last_checked_at']);
            if (time() - $lastCheck < 300) {
                throw new \Exception('กรุณารออีก ' . (300 - (time() - $lastCheck)) . ' วินาที');
            }
        }

        $this->queueScrapeJob((int) $result['product_id']);
        return true;
    }
}
