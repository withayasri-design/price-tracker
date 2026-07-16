<?php

/**
 * Scraping Service
 *
 * Orchestrates scraping across all platform adapters.
 * Handles adapter selection, rate limiting, and result storage.
 */

declare(strict_types=1);

namespace Modules\Scraping;

use PDO;
use Modules\Scraping\Adapters\JibAdapter;
use Modules\Scraping\Adapters\BananaAdapter;
use Modules\Scraping\Adapters\AdviceAdapter;

class ScrapingService
{
    private PDO $pdo;

    /** @var PlatformAdapterInterface[] */
    private array $adapters = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->registerDefaultAdapters();
    }

    /**
     * Register default platform adapters.
     */
    private function registerDefaultAdapters(): void
    {
        $this->register(new JibAdapter());
        $this->register(new BananaAdapter());
        $this->register(new AdviceAdapter());
        // TODO: Add more adapters as they're implemented
    }

    /**
     * Register a platform adapter.
     */
    public function register(PlatformAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->getPlatform()] = $adapter;
    }

    /**
     * Get adapter for a URL.
     */
    public function getAdapterForUrl(string $url): ?PlatformAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->canHandle($url)) {
                return $adapter;
            }
        }
        return null;
    }

    /**
     * Get adapter by platform name.
     */
    public function getAdapter(string $platform): ?PlatformAdapterInterface
    {
        return $this->adapters[$platform] ?? null;
    }

    /**
     * Scrape a product by ID.
     *
     * @param int $productId
     * @return ScrapedProduct|null
     */
    public function scrapeProduct(int $productId): ?ScrapedProduct
    {
        // Get product info
        $stmt = $this->pdo->prepare("
            SELECT product_id, platform, product_url
            FROM tracked_products
            WHERE product_id = :id AND is_active = 1
        ");
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            return null;
        }

        return $this->scrapeUrl($product['product_url'], $product['platform']);
    }

    /**
     * Scrape a product URL.
     *
     * @param string $url
     * @param string|null $platform Optional platform hint
     * @return ScrapedProduct
     * @throws ScrapingException
     */
    public function scrapeUrl(string $url, ?string $platform = null): ScrapedProduct
    {
        // Get adapter
        $adapter = $platform ? $this->getAdapter($platform) : $this->getAdapterForUrl($url);

        if (!$adapter) {
            throw new ScrapingException(
                "No adapter available for URL: {$url}",
                ScrapingException::ERROR_UNKNOWN,
                $url
            );
        }

        // Scrape the product
        return $adapter->scrape($url);
    }

    /**
     * Scrape and save product data.
     *
     * @param int $productId
     * @param string $triggerType 'cron' or 'manual'
     * @param int|null $triggeredByUserId
     * @return array Result with success status and scraped data
     */
    public function scrapeAndSave(int $productId, string $triggerType = 'cron', ?int $triggeredByUserId = null): array
    {
        $startTime = microtime(true);

        try {
            // Get product info
            $stmt = $this->pdo->prepare("
                SELECT product_id, platform, product_url
                FROM tracked_products
                WHERE product_id = :id
            ");
            $stmt->execute(['id' => $productId]);
            $productRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$productRow) {
                return ['success' => false, 'error' => 'Product not found'];
            }

            // Scrape
            $scraped = $this->scrapeUrl($productRow['product_url'], $productRow['platform']);
            $duration = (int) ((microtime(true) - $startTime) * 1000);

            // Update tracked_products
            $stmt = $this->pdo->prepare("
                UPDATE tracked_products SET
                    product_name = :name,
                    image_url = :image,
                    last_price = :price,
                    last_original_price = :original_price,
                    last_stock_status = :stock,
                    last_checked_at = NOW()
                WHERE product_id = :id
            ");
            $stmt->execute([
                'name' => $scraped->name,
                'image' => $scraped->imageUrl,
                'price' => $scraped->price,
                'original_price' => $scraped->originalPrice,
                'stock' => $scraped->stockStatus,
                'id' => $productId,
            ]);

            // Insert into price_history
            $stmt = $this->pdo->prepare("
                INSERT INTO price_history (product_id, price, original_price, discount_percent, stock_status, scraped_at)
                VALUES (:pid, :price, :original, :discount, :stock, NOW())
            ");
            $stmt->execute([
                'pid' => $productId,
                'price' => $scraped->price,
                'original' => $scraped->originalPrice,
                'discount' => $scraped->discountPercent,
                'stock' => $scraped->stockStatus,
            ]);

            // Insert into raw_price_snapshots for agent pipeline
            $stmt = $this->pdo->prepare("
                INSERT INTO raw_price_snapshots
                    (product_id, platform, platform_product_id, raw_name, raw_price, raw_original_price, raw_stock_status, raw_attributes, processing_status, scraped_at)
                VALUES
                    (:pid, :platform, :ppid, :name, :price, :original, :stock, :attrs, 'pending', NOW())
            ");
            $stmt->execute([
                'pid' => $productId,
                'platform' => $scraped->platform,
                'ppid' => $scraped->platformProductId,
                'name' => $scraped->name,
                'price' => $scraped->price,
                'original' => $scraped->originalPrice,
                'stock' => $scraped->stockStatus,
                'attrs' => json_encode($scraped->attributes),
            ]);

            // Log success
            $this->logScrape($productId, $triggerType, $triggeredByUserId, 'success', null, $duration);

            return [
                'success' => true,
                'data' => $scraped->toArray(),
                'duration_ms' => $duration,
            ];

        } catch (ScrapingException $e) {
            $duration = (int) ((microtime(true) - $startTime) * 1000);
            $this->logScrape($productId, $triggerType, $triggeredByUserId, 'failed', $e->getMessage(), $duration);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => $e->getErrorType(),
                'retryable' => $e->isRetryable(),
                'duration_ms' => $duration,
            ];

        } catch (\Throwable $e) {
            $duration = (int) ((microtime(true) - $startTime) * 1000);
            $this->logScrape($productId, $triggerType, $triggeredByUserId, 'failed', $e->getMessage(), $duration);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => 'unknown',
                'retryable' => false,
                'duration_ms' => $duration,
            ];
        }
    }

    /**
     * Log scraping attempt.
     */
    private function logScrape(
        int $productId,
        string $triggerType,
        ?int $triggeredByUserId,
        string $status,
        ?string $errorMessage,
        int $durationMs
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO scraping_logs (product_id, trigger_type, triggered_by_user_id, status, error_message, duration_ms, created_at)
            VALUES (:pid, :trigger, :user, :status, :error, :duration, NOW())
        ");
        $stmt->execute([
            'pid' => $productId,
            'trigger' => $triggerType,
            'user' => $triggeredByUserId,
            'status' => $status,
            'error' => $errorMessage,
            'duration' => $durationMs,
        ]);
    }

    /**
     * Get list of supported platforms.
     */
    public function getSupportedPlatforms(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * Check if a platform is supported.
     */
    public function isPlatformSupported(string $platform): bool
    {
        return isset($this->adapters[$platform]);
    }
}
