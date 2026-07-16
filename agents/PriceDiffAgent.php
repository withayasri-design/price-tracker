<?php

declare(strict_types=1);

namespace Agents;

use PDO;
use Throwable;

/**
 * Price Diff Agent: Detects price changes and creates price events.
 *
 * Compares current prices with historical data to detect:
 * - Price drops (significant decrease)
 * - Price increases
 * - Lowest price ever
 * - Back in stock
 * - Out of stock
 * - Flash sales (large sudden drops)
 *
 * Pipeline: ScraperAgent -> DataCleaningAgent -> PriceDiffAgent -> AlertDispatchAgent
 */
class PriceDiffAgent implements AgentInterface
{
    private PDO $pdo;
    private float $significantChangePercent;
    private int $lowestPriceDays;

    public function __construct(PDO $pdo, float $significantChangePercent = 5.0, int $lowestPriceDays = 90)
    {
        $this->pdo = $pdo;
        $this->significantChangePercent = $significantChangePercent;
        $this->lowestPriceDays = $lowestPriceDays;
    }

    public function getName(): string
    {
        return 'price_diff';
    }

    public function getNextAgentType(): ?string
    {
        return 'alert_dispatch';
    }

    public function shouldRetry(Throwable $e): bool
    {
        // Retry on transient errors
        if ($e instanceof \PDOException) {
            return true;
        }
        return false;
    }

    /**
     * Process products and detect price changes.
     *
     * @param array $payload Expected keys:
     *   - product_ids: array of product IDs to check (optional, defaults to recently updated)
     */
    public function process(array $payload): AgentResult
    {
        $startTime = microtime(true);
        $productIds = $payload['product_ids'] ?? null;

        if ($productIds === null) {
            // Get products updated in the last hour
            $productIds = $this->getRecentlyUpdatedProducts(100);
        }

        if (empty($productIds)) {
            return AgentResult::success('No products to check', null, [
                'products_found' => 0,
            ]);
        }

        $processed = 0;
        $eventsCreated = 0;
        $eventIds = [];

        foreach ($productIds as $productId) {
            try {
                $events = $this->checkProductForEvents((int) $productId);

                foreach ($events as $event) {
                    $eventId = $this->createPriceEvent($event);
                    if ($eventId) {
                        $eventIds[] = $eventId;
                        $eventsCreated++;
                    }
                }

                $processed++;

            } catch (Throwable $e) {
                // Log but continue processing other products
                $this->logError($productId, $e->getMessage());
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Prepare payload for AlertDispatchAgent
        $nextPayload = !empty($eventIds) ? [
            'event_ids' => $eventIds,
        ] : null;

        return AgentResult::success(
            "Processed {$processed} products, created {$eventsCreated} events",
            $nextPayload,
            [
                'products_checked' => $processed,
                'events_created' => $eventsCreated,
                'duration_ms' => $durationMs,
            ]
        );
    }

    /**
     * Check a single product for price events.
     *
     * @return array List of detected events
     */
    private function checkProductForEvents(int $productId): array
    {
        $events = [];

        // Get current product data
        $stmt = $this->pdo->prepare("
            SELECT tp.product_id, tp.platform, tp.product_name,
                   tp.last_price, tp.last_original_price, tp.last_stock_status,
                   pmm.master_product_id
            FROM tracked_products tp
            LEFT JOIN product_master_mapping pmm ON tp.product_id = pmm.product_id
            WHERE tp.product_id = :id AND tp.is_active = 1
        ");
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product || $product['last_price'] === null) {
            return [];
        }

        $currentPrice = (float) $product['last_price'];
        $masterProductId = $product['master_product_id'] ? (int) $product['master_product_id'] : null;

        // Get previous price (most recent before current)
        $previousPrice = $this->getPreviousPrice($productId);

        // Get historical lowest price
        $lowestPrice = $this->getLowestPrice($productId, $this->lowestPriceDays);

        // Get previous stock status
        $previousStockStatus = $this->getPreviousStockStatus($productId);
        $currentStockStatus = $product['last_stock_status'];

        // Check for price drop
        if ($previousPrice !== null && $currentPrice < $previousPrice) {
            $changePercent = (($previousPrice - $currentPrice) / $previousPrice) * 100;

            if ($changePercent >= $this->significantChangePercent) {
                $eventType = $changePercent >= 20 ? 'flash_sale' : 'price_drop';

                $events[] = [
                    'product_id' => $productId,
                    'master_product_id' => $masterProductId,
                    'event_type' => $eventType,
                    'old_price' => $previousPrice,
                    'new_price' => $currentPrice,
                    'change_percent' => -$changePercent,
                    'metadata' => [
                        'platform' => $product['platform'],
                        'product_name' => $product['product_name'],
                    ],
                ];
            }
        }

        // Check for price increase
        if ($previousPrice !== null && $currentPrice > $previousPrice) {
            $changePercent = (($currentPrice - $previousPrice) / $previousPrice) * 100;

            if ($changePercent >= $this->significantChangePercent) {
                $events[] = [
                    'product_id' => $productId,
                    'master_product_id' => $masterProductId,
                    'event_type' => 'price_increase',
                    'old_price' => $previousPrice,
                    'new_price' => $currentPrice,
                    'change_percent' => $changePercent,
                    'metadata' => [
                        'platform' => $product['platform'],
                        'product_name' => $product['product_name'],
                    ],
                ];
            }
        }

        // Check for lowest price ever
        if ($lowestPrice !== null && $currentPrice < $lowestPrice) {
            // Avoid duplicate if we already have a price_drop event
            $hasDropEvent = array_filter($events, fn($e) => in_array($e['event_type'], ['price_drop', 'flash_sale']));

            if (empty($hasDropEvent)) {
                $events[] = [
                    'product_id' => $productId,
                    'master_product_id' => $masterProductId,
                    'event_type' => 'lowest_ever',
                    'old_price' => $lowestPrice,
                    'new_price' => $currentPrice,
                    'change_percent' => null,
                    'metadata' => [
                        'platform' => $product['platform'],
                        'product_name' => $product['product_name'],
                        'lowest_in_days' => $this->lowestPriceDays,
                    ],
                ];
            } else {
                // Add lowest_ever flag to existing event metadata
                foreach ($events as &$event) {
                    if (in_array($event['event_type'], ['price_drop', 'flash_sale'])) {
                        $event['metadata']['is_lowest_ever'] = true;
                        $event['metadata']['lowest_in_days'] = $this->lowestPriceDays;
                    }
                }
            }
        }

        // Check for stock status changes
        if ($previousStockStatus !== null && $currentStockStatus !== null) {
            $wasOutOfStock = $this->isOutOfStock($previousStockStatus);
            $isNowOutOfStock = $this->isOutOfStock($currentStockStatus);

            if ($wasOutOfStock && !$isNowOutOfStock) {
                $events[] = [
                    'product_id' => $productId,
                    'master_product_id' => $masterProductId,
                    'event_type' => 'back_in_stock',
                    'old_price' => $previousPrice,
                    'new_price' => $currentPrice,
                    'change_percent' => null,
                    'metadata' => [
                        'platform' => $product['platform'],
                        'product_name' => $product['product_name'],
                        'previous_status' => $previousStockStatus,
                        'current_status' => $currentStockStatus,
                    ],
                ];
            } elseif (!$wasOutOfStock && $isNowOutOfStock) {
                $events[] = [
                    'product_id' => $productId,
                    'master_product_id' => $masterProductId,
                    'event_type' => 'out_of_stock',
                    'old_price' => $previousPrice,
                    'new_price' => $currentPrice,
                    'change_percent' => null,
                    'metadata' => [
                        'platform' => $product['platform'],
                        'product_name' => $product['product_name'],
                    ],
                ];
            }
        }

        return $events;
    }

    /**
     * Get the previous price for a product.
     */
    private function getPreviousPrice(int $productId): ?float
    {
        $stmt = $this->pdo->prepare("
            SELECT price
            FROM price_history
            WHERE product_id = :id
            ORDER BY scraped_at DESC
            LIMIT 1 OFFSET 1
        ");
        $stmt->execute(['id' => $productId]);
        $result = $stmt->fetchColumn();

        return $result !== false ? (float) $result : null;
    }

    /**
     * Get the lowest price in the specified number of days.
     */
    private function getLowestPrice(int $productId, int $days): ?float
    {
        $stmt = $this->pdo->prepare("
            SELECT MIN(price) as lowest
            FROM price_history
            WHERE product_id = :id
              AND scraped_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
              AND price > 0
        ");
        $stmt->execute(['id' => $productId, 'days' => $days]);
        $result = $stmt->fetchColumn();

        return $result !== false && $result !== null ? (float) $result : null;
    }

    /**
     * Get the previous stock status.
     */
    private function getPreviousStockStatus(int $productId): ?string
    {
        $stmt = $this->pdo->prepare("
            SELECT stock_status
            FROM price_history
            WHERE product_id = :id
            ORDER BY scraped_at DESC
            LIMIT 1 OFFSET 1
        ");
        $stmt->execute(['id' => $productId]);
        $result = $stmt->fetchColumn();

        return $result !== false ? $result : null;
    }

    /**
     * Check if a stock status indicates out of stock.
     */
    private function isOutOfStock(?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        $outOfStockPatterns = [
            'out of stock',
            'out_of_stock',
            'sold out',
            'หมด',
            'สินค้าหมด',
            'ไม่มีสินค้า',
            '0',
        ];

        $status = mb_strtolower($status);
        foreach ($outOfStockPatterns as $pattern) {
            if (strpos($status, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a price event record.
     *
     * @return int|null Event ID or null if duplicate
     */
    private function createPriceEvent(array $event): ?int
    {
        // Check for duplicate event (same product, type, and price within last hour)
        $stmt = $this->pdo->prepare("
            SELECT event_id FROM price_events
            WHERE product_id = :product_id
              AND event_type = :event_type
              AND new_price = :new_price
              AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            LIMIT 1
        ");
        $stmt->execute([
            'product_id' => $event['product_id'],
            'event_type' => $event['event_type'],
            'new_price' => $event['new_price'],
        ]);

        if ($stmt->fetch()) {
            return null; // Duplicate, skip
        }

        // Insert new event
        $stmt = $this->pdo->prepare("
            INSERT INTO price_events (
                master_product_id, product_id, event_type,
                old_price, new_price, change_percent,
                event_metadata, is_dispatched, created_at
            ) VALUES (
                :master_product_id, :product_id, :event_type,
                :old_price, :new_price, :change_percent,
                :metadata, 0, NOW()
            )
        ");
        $stmt->execute([
            'master_product_id' => $event['master_product_id'],
            'product_id' => $event['product_id'],
            'event_type' => $event['event_type'],
            'old_price' => $event['old_price'],
            'new_price' => $event['new_price'],
            'change_percent' => $event['change_percent'],
            'metadata' => json_encode($event['metadata'], JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Get products that were updated recently.
     */
    private function getRecentlyUpdatedProducts(int $limit): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT product_id
            FROM price_history
            WHERE scraped_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY scraped_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Log an error.
     */
    private function logError(int $productId, string $message): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO agent_logs (agent_type, log_level, message, context, created_at)
            VALUES ('price_diff', 'error', :message, :context, NOW())
        ");
        $stmt->execute([
            'message' => "Error processing product {$productId}",
            'context' => json_encode(['product_id' => $productId, 'error' => $message]),
        ]);
    }
}
