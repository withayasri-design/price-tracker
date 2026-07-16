<?php

/**
 * Trigger Agent API
 *
 * Manually queue an agent job or trigger immediate processing.
 *
 * POST /api/agents/trigger_agent.php
 * Body: {
 *   "agent_type": "scraper|data_cleaning|price_diff|alert_dispatch",
 *   "payload": {...},
 *   "priority": 1-10,
 *   "immediate": false
 * }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Csrf.php';
require_once __DIR__ . '/../../core/Queue.php';

use Core\Auth;
use Core\Csrf;
use Core\Queue;

header('Content-Type: application/json');

// Require admin access
if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Verify CSRF
try {
    Csrf::verify($input['csrf_token'] ?? '');
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$agentType = $input['agent_type'] ?? null;
$payload = $input['payload'] ?? [];
$priority = isset($input['priority']) ? (int) $input['priority'] : 5;
$immediate = $input['immediate'] ?? false;

// Validate agent type
$validAgents = ['scraper', 'data_cleaning', 'price_diff', 'alert_dispatch'];
if (!in_array($agentType, $validAgents, true)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid agent type',
        'valid_types' => $validAgents,
    ]);
    exit;
}

// Validate priority
$priority = max(1, min(10, $priority));

try {
    $queue = new Queue($pdo);

    if ($immediate) {
        // Run agent immediately (synchronous)
        require_once __DIR__ . '/../../agents/AgentInterface.php';
        require_once __DIR__ . '/../../agents/AgentResult.php';
        require_once __DIR__ . '/../../agents/AgentRunner.php';
        require_once __DIR__ . '/../../agents/ScraperAgent.php';
        require_once __DIR__ . '/../../agents/DataCleaningAgent.php';
        require_once __DIR__ . '/../../agents/PriceDiffAgent.php';
        require_once __DIR__ . '/../../agents/AlertDispatchAgent.php';
        require_once __DIR__ . '/../../modules/matching/SimilarityCalculator.php';
        require_once __DIR__ . '/../../modules/matching/MasterProductService.php';
        require_once __DIR__ . '/../../modules/notification/LineNotifier.php';
        require_once __DIR__ . '/../../modules/notification/EmailNotifier.php';
        require_once __DIR__ . '/../../modules/scraping/ScrapingException.php';
        require_once __DIR__ . '/../../modules/scraping/ScrapedProduct.php';
        require_once __DIR__ . '/../../modules/scraping/PlatformAdapterInterface.php';
        require_once __DIR__ . '/../../modules/scraping/BaseAdapter.php';
        require_once __DIR__ . '/../../modules/scraping/adapters/JibAdapter.php';
        require_once __DIR__ . '/../../modules/scraping/adapters/BananaAdapter.php';
        require_once __DIR__ . '/../../modules/scraping/adapters/AdviceAdapter.php';
        require_once __DIR__ . '/../../modules/scraping/adapters/GlobalHouseAdapter.php';
        require_once __DIR__ . '/../../modules/scraping/adapters/HomeProAdapter.php';
        require_once __DIR__ . '/../../modules/scraping/adapters/ThaiWatsaduAdapter.php';
        require_once __DIR__ . '/../../modules/scraping/adapters/PowerBuyAdapter.php';
        require_once __DIR__ . '/../../modules/scraping/ScrapingService.php';
        require_once __DIR__ . '/../../config/line.php';

        $runner = new \Agents\AgentRunner($pdo);

        // Register agents
        $runner->register(new \Agents\ScraperAgent($pdo));
        $runner->register(new \Agents\DataCleaningAgent($pdo));
        $runner->register(new \Agents\PriceDiffAgent($pdo));
        $runner->register(new \Agents\AlertDispatchAgent($pdo));

        // Queue the job first
        $jobId = $queue->push($agentType, $payload, $priority);

        // Process immediately
        $stats = $runner->run($agentType, 1);

        echo json_encode([
            'success' => true,
            'job_id' => $jobId,
            'immediate' => true,
            'stats' => $stats,
        ]);

    } else {
        // Queue for background processing
        $jobId = $queue->push($agentType, $payload, $priority);

        echo json_encode([
            'success' => true,
            'job_id' => $jobId,
            'immediate' => false,
            'message' => "Job queued for {$agentType} agent",
        ]);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
