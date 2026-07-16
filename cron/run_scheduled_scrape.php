<?php

/**
 * Scheduled Scraping Cron Job
 *
 * Queues products for scraping based on last_checked_at.
 * Run this periodically (e.g., every 30 minutes) to queue stale products.
 *
 * Usage:
 *   php cron/run_scheduled_scrape.php [max_products]
 *
 * Crontab example (every 30 minutes):
 *   */30 * * * * php /path/to/cron/run_scheduled_scrape.php >> /var/log/scrape.log 2>&1
 */

declare(strict_types=1);

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Queue.php';

use Core\Queue;

// Parse arguments
$maxProducts = isset($argv[1]) ? (int) $argv[1] : 100;

$startTime = date('Y-m-d H:i:s');
echo "[{$startTime}] Starting scheduled scrape job\n";

try {
    // Get scraping interval from settings (default 3 hours = 180 minutes)
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'cron_interval_minutes'");
    $stmt->execute();
    $intervalMinutes = (int) ($stmt->fetchColumn() ?: 180);

    echo "  Scraping interval: {$intervalMinutes} minutes\n";

    // Find products that need scraping
    // - Last checked more than $intervalMinutes ago
    // - Or never checked (last_checked_at IS NULL)
    // - Is active
    // - Has at least one active user tracking
    $stmt = $pdo->prepare("
        SELECT DISTINCT tp.product_id, tp.platform, tp.last_checked_at
        FROM tracked_products tp
        INNER JOIN user_tracking ut ON tp.product_id = ut.product_id
        WHERE tp.is_active = 1
          AND ut.is_active = 1
          AND (
              tp.last_checked_at IS NULL
              OR tp.last_checked_at < DATE_SUB(NOW(), INTERVAL :interval MINUTE)
          )
        ORDER BY tp.last_checked_at ASC NULLS FIRST
        LIMIT :max_products
    ");
    $stmt->bindValue(':interval', $intervalMinutes, PDO::PARAM_INT);
    $stmt->bindValue(':max_products', $maxProducts, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = count($products);
    echo "  Found {$count} products to scrape\n";

    if ($count === 0) {
        echo "  Nothing to do\n";
        exit(0);
    }

    // Queue scraping jobs
    $queue = new Queue($pdo);
    $queued = 0;
    $skipped = 0;

    foreach ($products as $product) {
        // Check if already queued
        $stmt = $pdo->prepare("
            SELECT job_id FROM agent_job_queue
            WHERE agent_type = 'scraper'
              AND status IN ('pending', 'processing')
              AND JSON_EXTRACT(payload, '$.product_id') = :pid
            LIMIT 1
        ");
        $stmt->execute(['pid' => $product['product_id']]);

        if ($stmt->fetch()) {
            $skipped++;
            continue;
        }

        // Queue the job
        $queue->push('scraper', [
            'product_id' => $product['product_id'],
            'platform' => $product['platform'],
            'trigger' => 'scheduled',
        ], 5); // Normal priority

        $queued++;
    }

    echo "  Queued: {$queued}, Skipped (already queued): {$skipped}\n";

    // Log summary
    $pdo->prepare("
        INSERT INTO agent_logs (agent_type, log_level, message, context, created_at)
        VALUES ('scraper', 'info', 'Scheduled scrape queued', :context, NOW())
    ")->execute([
        'context' => json_encode([
            'products_found' => $count,
            'queued' => $queued,
            'skipped' => $skipped,
            'interval_minutes' => $intervalMinutes,
        ]),
    ]);

    $endTime = date('Y-m-d H:i:s');
    echo "[{$endTime}] Scheduled scrape job completed\n";

} catch (Throwable $e) {
    $errorTime = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$errorTime}] ERROR: {$e->getMessage()}\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
