<?php

/**
 * Cron entry point for processing agent job queue.
 *
 * Usage:
 *   php cron/run_agent_queue.php [agent_type] [max_jobs]
 *
 * Examples:
 *   php cron/run_agent_queue.php                    # Process all agents, 10 jobs each
 *   php cron/run_agent_queue.php scraper            # Process scraper agent only
 *   php cron/run_agent_queue.php data_cleaning 50   # Process 50 data_cleaning jobs
 *
 * Crontab example (run every minute):
 *   * * * * * php /path/to/cron/run_agent_queue.php >> /var/log/agent_queue.log 2>&1
 */

declare(strict_types=1);

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Queue.php';
require_once __DIR__ . '/../agents/AgentInterface.php';
require_once __DIR__ . '/../agents/AgentResult.php';
require_once __DIR__ . '/../agents/AgentRunner.php';
require_once __DIR__ . '/../agents/ScraperAgent.php';
require_once __DIR__ . '/../agents/DataCleaningAgent.php';
require_once __DIR__ . '/../agents/PriceDiffAgent.php';
require_once __DIR__ . '/../agents/AlertDispatchAgent.php';
require_once __DIR__ . '/../modules/matching/SimilarityCalculator.php';
require_once __DIR__ . '/../modules/matching/MasterProductService.php';
require_once __DIR__ . '/../modules/notification/LineNotifier.php';
require_once __DIR__ . '/../modules/scraping/ScrapingException.php';
require_once __DIR__ . '/../modules/scraping/ScrapedProduct.php';
require_once __DIR__ . '/../modules/scraping/PlatformAdapterInterface.php';
require_once __DIR__ . '/../modules/scraping/BaseAdapter.php';
require_once __DIR__ . '/../modules/scraping/adapters/JibAdapter.php';
require_once __DIR__ . '/../modules/scraping/adapters/BananaAdapter.php';
require_once __DIR__ . '/../modules/scraping/adapters/AdviceAdapter.php';
require_once __DIR__ . '/../modules/scraping/ScrapingService.php';
require_once __DIR__ . '/../config/line.php';

use Agents\AgentRunner;
use Agents\ScraperAgent;
use Agents\DataCleaningAgent;
use Agents\PriceDiffAgent;
use Agents\AlertDispatchAgent;

// Parse arguments
$agentType = $argv[1] ?? null;
$maxJobs = isset($argv[2]) ? (int) $argv[2] : 10;

try {
    // Get database connection
    // Assumes config/database.php returns or sets a $pdo variable
    if (!isset($pdo)) {
        throw new RuntimeException('Database connection not initialized');
    }

    // Initialize runner and register agents
    $runner = new AgentRunner($pdo);

    // Register all agents (pipeline order)
    $runner->register(new ScraperAgent($pdo));
    $runner->register(new DataCleaningAgent($pdo));
    $runner->register(new PriceDiffAgent($pdo));
    $runner->register(new AlertDispatchAgent($pdo));

    $startTime = date('Y-m-d H:i:s');
    echo "[{$startTime}] Starting agent queue processor\n";

    if ($agentType !== null) {
        // Process single agent
        $stats = $runner->run($agentType, $maxJobs);
        printStats([$agentType => $stats]);
    } else {
        // Process all agents
        $stats = $runner->runAll($maxJobs);
        printStats($stats);
    }

    $endTime = date('Y-m-d H:i:s');
    echo "[{$endTime}] Agent queue processing complete\n";

} catch (Throwable $e) {
    $errorTime = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$errorTime}] ERROR: {$e->getMessage()}\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

/**
 * Print processing statistics.
 */
function printStats(array $stats): void
{
    foreach ($stats as $agentType => $agentStats) {
        echo sprintf(
            "  %s: processed=%d, succeeded=%d, failed=%d, duration=%dms\n",
            $agentType,
            $agentStats['processed'],
            $agentStats['succeeded'],
            $agentStats['failed'],
            $agentStats['duration_ms']
        );
    }
}
