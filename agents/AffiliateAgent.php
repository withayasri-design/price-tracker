<?php

/**
 * Affiliate Agent
 *
 * Generates affiliate links for tracked products.
 * Runs as part of the agent pipeline, typically before AlertDispatchAgent.
 *
 * Pipeline: ScraperAgent → DataCleaningAgent → PriceDiffAgent → AffiliateAgent → AlertDispatchAgent
 */

declare(strict_types=1);

namespace Agents;

require_once __DIR__ . '/AgentInterface.php';
require_once __DIR__ . '/AgentResult.php';
require_once __DIR__ . '/../modules/affiliate/AffiliateService.php';

use PDO;
use Modules\Affiliate\AffiliateService;

class AffiliateAgent implements AgentInterface
{
    private PDO $pdo;
    private AffiliateService $affiliateService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->affiliateService = new AffiliateService($pdo);
    }

    public function getName(): string
    {
        return 'affiliate';
    }

    public function getDescription(): string
    {
        return 'Generates affiliate links for products';
    }

    /**
     * Process a job to generate affiliate link for a product.
     *
     * @param array $payload ['product_id' => int] or ['generate_all' => true]
     * @return AgentResult
     */
    public function process(array $payload): AgentResult
    {
        $startTime = microtime(true);

        try {
            // Check if this is a batch job
            if (!empty($payload['generate_all'])) {
                return $this->processAllProducts();
            }

            // Single product job
            if (empty($payload['product_id'])) {
                return AgentResult::failure('Missing product_id in payload');
            }

            $productId = (int) $payload['product_id'];

            // Get product info
            $stmt = $this->pdo->prepare("
                SELECT product_id, platform, product_url, affiliate_url, affiliate_program
                FROM tracked_products
                WHERE product_id = :id
            ");
            $stmt->execute(['id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                return AgentResult::failure("Product not found: {$productId}");
            }

            // Skip if already has affiliate URL
            if (!empty($product['affiliate_url']) && empty($payload['force'])) {
                return AgentResult::success([
                    'product_id' => $productId,
                    'skipped' => true,
                    'reason' => 'Already has affiliate URL',
                    'affiliate_url' => $product['affiliate_url'],
                ]);
            }

            // Generate affiliate link
            $result = $this->affiliateService->generateAffiliateLink(
                $product['product_url'],
                $product['platform']
            );

            if ($result['success']) {
                // Save to database
                $this->affiliateService->updateProductAffiliateUrl(
                    $productId,
                    $result['url'],
                    $result['program']
                );

                $duration = (int) ((microtime(true) - $startTime) * 1000);

                return AgentResult::success([
                    'product_id' => $productId,
                    'affiliate_url' => $result['url'],
                    'program' => $result['program'],
                    'duration_ms' => $duration,
                ]);
            } else {
                return AgentResult::success([
                    'product_id' => $productId,
                    'skipped' => true,
                    'reason' => $result['message'] ?? 'No affiliate program available',
                ]);
            }

        } catch (\Throwable $e) {
            return AgentResult::failure($e->getMessage());
        }
    }

    /**
     * Process all products without affiliate links.
     */
    private function processAllProducts(): AgentResult
    {
        $startTime = microtime(true);

        try {
            $results = $this->affiliateService->generateMissingAffiliateLinks();

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            return AgentResult::success([
                'batch' => true,
                'total' => $results['total'],
                'success' => $results['success'],
                'failed' => $results['failed'],
                'skipped' => $results['skipped'],
                'duration_ms' => $duration,
            ]);

        } catch (\Throwable $e) {
            return AgentResult::failure($e->getMessage());
        }
    }

    /**
     * Queue affiliate generation for a product.
     */
    public function queueProduct(int $productId, bool $force = false): int
    {
        require_once __DIR__ . '/../core/Queue.php';

        $queue = new \Core\Queue($this->pdo);

        return $queue->push('affiliate', [
            'product_id' => $productId,
            'force' => $force,
        ]);
    }

    /**
     * Queue batch affiliate generation.
     */
    public function queueBatch(): int
    {
        require_once __DIR__ . '/../core/Queue.php';

        $queue = new \Core\Queue($this->pdo);

        return $queue->push('affiliate', [
            'generate_all' => true,
        ]);
    }
}
