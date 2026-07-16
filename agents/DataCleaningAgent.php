<?php

declare(strict_types=1);

namespace Agents;

use Modules\Matching\MasterProductService;
use Modules\Matching\SimilarityCalculator;
use PDO;
use Throwable;

/**
 * Data Cleaning Agent: Normalizes product data and creates cross-platform mappings.
 *
 * Processes raw_price_snapshots and:
 * 1. Extracts brand/attributes from product names
 * 2. Matches products to master_products catalog
 * 3. Creates new master products for unmatched items
 * 4. Queues PriceDiffAgent for price change detection
 *
 * Pipeline: ScraperAgent -> DataCleaningAgent -> PriceDiffAgent -> AlertDispatchAgent
 */
class DataCleaningAgent implements AgentInterface
{
    private PDO $pdo;
    private MasterProductService $masterService;
    private SimilarityCalculator $calculator;
    private float $similarityThreshold;

    public function __construct(PDO $pdo, float $similarityThreshold = 0.85)
    {
        $this->pdo = $pdo;
        $this->calculator = new SimilarityCalculator();
        $this->masterService = new MasterProductService($pdo, $this->calculator, $similarityThreshold);
        $this->similarityThreshold = $similarityThreshold;
    }

    public function getName(): string
    {
        return 'data_cleaning';
    }

    public function getNextAgentType(): ?string
    {
        return 'price_diff';
    }

    public function shouldRetry(Throwable $e): bool
    {
        // Don't retry on validation/logic errors
        if ($e instanceof \InvalidArgumentException || $e instanceof \DomainException) {
            return false;
        }
        return true;
    }

    /**
     * Process raw price snapshots and create/update master product mappings.
     *
     * @param array $payload Expected keys:
     *   - snapshot_ids: array of raw_price_snapshots.snapshot_id to process
     */
    public function process(array $payload): AgentResult
    {
        $startTime = microtime(true);
        $snapshotIds = $payload['snapshot_ids'] ?? [];

        if (empty($snapshotIds)) {
            // If no specific IDs, process pending snapshots
            $snapshotIds = $this->getPendingSnapshots(100);
        }

        if (empty($snapshotIds)) {
            return AgentResult::success('No snapshots to process', null, [
                'snapshots_found' => 0,
            ]);
        }

        $processed = 0;
        $matched = 0;
        $created = 0;
        $failed = 0;
        $processedProductIds = [];

        foreach ($snapshotIds as $snapshotId) {
            try {
                $result = $this->processSnapshot((int) $snapshotId);

                if ($result !== null) {
                    $processed++;
                    $processedProductIds[] = $result['product_id'];

                    if ($result['action'] === 'auto_linked' || $result['action'] === 'already_mapped') {
                        $matched++;
                    } elseif ($result['action'] === 'created_new') {
                        $created++;
                    }
                } else {
                    $failed++;
                }

            } catch (Throwable $e) {
                $failed++;
                $this->markSnapshotFailed((int) $snapshotId, $e->getMessage());
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Prepare payload for PriceDiffAgent
        $nextPayload = !empty($processedProductIds) ? [
            'product_ids' => array_unique($processedProductIds),
        ] : null;

        return AgentResult::success(
            "Processed {$processed} snapshots: {$matched} matched, {$created} new, {$failed} failed",
            $nextPayload,
            [
                'snapshots_attempted' => count($snapshotIds),
                'processed' => $processed,
                'matched' => $matched,
                'created' => $created,
                'failed' => $failed,
                'duration_ms' => $durationMs,
            ]
        );
    }

    /**
     * Process a single raw_price_snapshot.
     */
    private function processSnapshot(int $snapshotId): ?array
    {
        // Get snapshot data
        $stmt = $this->pdo->prepare("
            SELECT snapshot_id, product_id, platform, platform_product_id,
                   raw_name, raw_price, raw_original_price, raw_stock_status, raw_attributes
            FROM raw_price_snapshots
            WHERE snapshot_id = :id AND processing_status = 'pending'
        ");
        $stmt->execute(['id' => $snapshotId]);
        $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$snapshot) {
            return null;
        }

        $productId = (int) $snapshot['product_id'];
        $productName = $snapshot['raw_name'] ?? '';

        if (empty($productName)) {
            // Try to get name from tracked_products
            $stmt = $this->pdo->prepare("SELECT product_name FROM tracked_products WHERE product_id = :id");
            $stmt->execute(['id' => $productId]);
            $productName = $stmt->fetchColumn() ?: '';
        }

        if (empty($productName)) {
            $this->markSnapshotFailed($snapshotId, 'No product name available');
            return null;
        }

        // Auto-match to master product
        $matchResult = $this->masterService->autoMatch($productId, $productName, $snapshot['platform']);

        // Update raw attributes if extracted
        $rawAttrs = $snapshot['raw_attributes'] ? json_decode($snapshot['raw_attributes'], true) : [];
        $extractedAttrs = $this->calculator->extractAttributes($productName);
        $mergedAttrs = array_merge($extractedAttrs, $rawAttrs);

        if (!empty($mergedAttrs) && $mergedAttrs !== $rawAttrs) {
            $this->updateSnapshotAttributes($snapshotId, $mergedAttrs);
        }

        // Mark snapshot as processed
        $this->markSnapshotProcessed($snapshotId);

        return [
            'product_id' => $productId,
            'master_product_id' => $matchResult['master_product_id'],
            'action' => $matchResult['action'],
            'similarity_score' => $matchResult['similarity_score'] ?? null,
        ];
    }

    /**
     * Get pending snapshot IDs.
     */
    private function getPendingSnapshots(int $limit): array
    {
        $stmt = $this->pdo->prepare("
            SELECT snapshot_id
            FROM raw_price_snapshots
            WHERE processing_status = 'pending'
            ORDER BY scraped_at ASC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Mark snapshot as processed.
     */
    private function markSnapshotProcessed(int $snapshotId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE raw_price_snapshots
            SET processing_status = 'processed', processed_at = NOW()
            WHERE snapshot_id = :id
        ");
        $stmt->execute(['id' => $snapshotId]);
    }

    /**
     * Mark snapshot as failed.
     */
    private function markSnapshotFailed(int $snapshotId, string $reason): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE raw_price_snapshots
            SET processing_status = 'failed', processed_at = NOW()
            WHERE snapshot_id = :id
        ");
        $stmt->execute(['id' => $snapshotId]);

        // Log the failure
        $stmt = $this->pdo->prepare("
            INSERT INTO agent_logs (agent_type, log_level, message, context, created_at)
            VALUES ('data_cleaning', 'warning', :message, :context, NOW())
        ");
        $stmt->execute([
            'message' => "Snapshot {$snapshotId} processing failed",
            'context' => json_encode(['snapshot_id' => $snapshotId, 'reason' => $reason]),
        ]);
    }

    /**
     * Update snapshot with extracted attributes.
     */
    private function updateSnapshotAttributes(int $snapshotId, array $attributes): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE raw_price_snapshots
            SET raw_attributes = :attrs
            WHERE snapshot_id = :id
        ");
        $stmt->execute([
            'id' => $snapshotId,
            'attrs' => json_encode($attributes, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
