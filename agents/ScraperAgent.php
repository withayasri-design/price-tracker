<?php

declare(strict_types=1);

namespace Agents;

use PDO;
use Throwable;

/**
 * Scraper Agent: Fetches product prices from e-commerce platforms.
 *
 * Wraps the existing ScrapingService while adding:
 * - Writes to raw_price_snapshots for agent pipeline
 * - Queues DataCleaningAgent jobs after scraping
 *
 * Pipeline: ScraperAgent -> DataCleaningAgent -> PriceDiffAgent -> AlertDispatchAgent
 */
class ScraperAgent implements AgentInterface
{
    private PDO $pdo;
    private int $batchSize;
    private int $delayBetweenRequestsMs;

    public function __construct(PDO $pdo, int $batchSize = 50, int $delayBetweenRequestsMs = 3000)
    {
        $this->pdo = $pdo;
        $this->batchSize = $batchSize;
        $this->delayBetweenRequestsMs = $delayBetweenRequestsMs;
    }

    public function getName(): string
    {
        return 'scraper';
    }

    public function getNextAgentType(): ?string
    {
        return 'data_cleaning';
    }

    public function shouldRetry(Throwable $e): bool
    {
        // Retry on network errors, don't retry on validation errors
        $nonRetryable = [
            'InvalidArgumentException',
            'DomainException',
        ];
        return !in_array(get_class($e), $nonRetryable, true);
    }

    /**
     * Process a batch of products for scraping.
     *
     * @param array $payload Expected keys:
     *   - product_ids: array of product IDs to scrape (optional, defaults to all active)
     *   - platform: filter by platform (optional)
     */
    public function process(array $payload): AgentResult
    {
        $startTime = microtime(true);
        $productIds = $payload['product_ids'] ?? null;
        $platform = $payload['platform'] ?? null;

        // Get products to scrape
        $products = $this->getProductsToScrape($productIds, $platform);

        if (empty($products)) {
            return AgentResult::success('No products to scrape', null, [
                'products_found' => 0,
            ]);
        }

        $scraped = 0;
        $failed = 0;
        $snapshotIds = [];

        foreach ($products as $product) {
            try {
                $snapshotId = $this->scrapeProduct($product);
                if ($snapshotId !== null) {
                    $snapshotIds[] = $snapshotId;
                    $scraped++;
                } else {
                    $failed++;
                }

                // Rate limiting delay
                if ($this->delayBetweenRequestsMs > 0) {
                    usleep($this->delayBetweenRequestsMs * 1000);
                }

            } catch (Throwable $e) {
                $failed++;
                $this->logScrapingError($product['product_id'], $e->getMessage());
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Prepare payload for DataCleaningAgent
        $nextPayload = !empty($snapshotIds) ? [
            'snapshot_ids' => $snapshotIds,
        ] : null;

        return AgentResult::success(
            "Scraped {$scraped} products, {$failed} failed",
            $nextPayload,
            [
                'products_attempted' => count($products),
                'scraped' => $scraped,
                'failed' => $failed,
                'duration_ms' => $durationMs,
            ]
        );
    }

    /**
     * Get list of products to scrape.
     */
    private function getProductsToScrape(?array $productIds, ?string $platform): array
    {
        $sql = "
            SELECT product_id, platform, platform_product_id, product_url, product_name
            FROM tracked_products
            WHERE is_active = 1
        ";
        $params = [];

        if ($productIds !== null) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $sql .= " AND product_id IN ({$placeholders})";
            $params = array_merge($params, $productIds);
        }

        if ($platform !== null) {
            $sql .= " AND platform = ?";
            $params[] = $platform;
        }

        $sql .= " ORDER BY last_checked_at ASC NULLS FIRST LIMIT ?";
        $params[] = $this->batchSize;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Scrape a single product and save to raw_price_snapshots.
     * Also updates tracked_products.last_* fields for backward compatibility.
     *
     * @return int|null Snapshot ID on success, null on failure
     */
    private function scrapeProduct(array $product): ?int
    {
        // TODO: Replace with actual platform-specific scraping logic
        // This should call the appropriate PlatformAdapter from modules/scraping/
        $scrapedData = $this->callPlatformScraper($product);

        if ($scrapedData === null) {
            return null;
        }

        // Insert into raw_price_snapshots
        $stmt = $this->pdo->prepare("
            INSERT INTO raw_price_snapshots (
                product_id, platform, platform_product_id,
                raw_name, raw_price, raw_original_price, raw_stock_status,
                raw_attributes, processing_status, scraped_at
            ) VALUES (
                :product_id, :platform, :platform_product_id,
                :raw_name, :raw_price, :raw_original_price, :raw_stock_status,
                :raw_attributes, 'pending', NOW()
            )
        ");
        $stmt->execute([
            'product_id' => $product['product_id'],
            'platform' => $product['platform'],
            'platform_product_id' => $product['platform_product_id'],
            'raw_name' => $scrapedData['name'],
            'raw_price' => $scrapedData['price'],
            'raw_original_price' => $scrapedData['original_price'],
            'raw_stock_status' => $scrapedData['stock_status'],
            'raw_attributes' => isset($scrapedData['attributes'])
                ? json_encode($scrapedData['attributes'], JSON_UNESCAPED_UNICODE)
                : null,
        ]);
        $snapshotId = (int) $this->pdo->lastInsertId();

        // Also update tracked_products for backward compatibility
        $this->updateTrackedProduct($product['product_id'], $scrapedData);

        // Insert into price_history (existing behavior)
        $this->insertPriceHistory($product['product_id'], $scrapedData);

        // Log successful scrape
        $this->logScrapingSuccess($product['product_id']);

        return $snapshotId;
    }

    /**
     * Call the platform-specific scraper.
     * This is a placeholder - should delegate to modules/scraping/ScrapingService.
     */
    private function callPlatformScraper(array $product): ?array
    {
        // TODO: Implement actual scraping logic
        // For now, return null to indicate "not implemented"
        //
        // Example implementation:
        // $scraperClass = $this->getPlatformAdapter($product['platform']);
        // $scraper = new $scraperClass();
        // return $scraper->scrape($product['product_url']);

        return null;
    }

    /**
     * Update tracked_products with latest scraped data.
     */
    private function updateTrackedProduct(int $productId, array $data): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE tracked_products
            SET product_name = COALESCE(:name, product_name),
                last_price = :price,
                last_original_price = :original_price,
                last_stock_status = :stock_status,
                last_checked_at = NOW()
            WHERE product_id = :product_id
        ");
        $stmt->execute([
            'product_id' => $productId,
            'name' => $data['name'],
            'price' => $data['price'],
            'original_price' => $data['original_price'],
            'stock_status' => $data['stock_status'],
        ]);
    }

    /**
     * Insert price history record.
     */
    private function insertPriceHistory(int $productId, array $data): void
    {
        $discountPercent = null;
        if ($data['original_price'] && $data['original_price'] > 0 && $data['price'] < $data['original_price']) {
            $discountPercent = round((1 - $data['price'] / $data['original_price']) * 100, 2);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO price_history (product_id, price, original_price, discount_percent, stock_status, scraped_at)
            VALUES (:product_id, :price, :original_price, :discount_percent, :stock_status, NOW())
        ");
        $stmt->execute([
            'product_id' => $productId,
            'price' => $data['price'],
            'original_price' => $data['original_price'],
            'discount_percent' => $discountPercent,
            'stock_status' => $data['stock_status'],
        ]);
    }

    /**
     * Log successful scraping.
     */
    private function logScrapingSuccess(int $productId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO scraping_logs (product_id, trigger_type, status, created_at)
            VALUES (:product_id, 'cron', 'success', NOW())
        ");
        $stmt->execute(['product_id' => $productId]);
    }

    /**
     * Log scraping error.
     */
    private function logScrapingError(int $productId, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO scraping_logs (product_id, trigger_type, status, error_message, created_at)
            VALUES (:product_id, 'cron', 'failed', :error, NOW())
        ");
        $stmt->execute([
            'product_id' => $productId,
            'error' => $errorMessage,
        ]);
    }
}
