<?php

/**
 * Agent Queue Status API
 *
 * Returns current queue statistics and recent job status.
 *
 * GET /api/agents/queue_status.php
 * GET /api/agents/queue_status.php?agent_type=scraper
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';

use Core\Auth;

header('Content-Type: application/json');

// Require admin access
if (!Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required']);
    exit;
}

$agentType = $_GET['agent_type'] ?? null;

try {
    // Get queue statistics by status
    $statusQuery = "
        SELECT
            agent_type,
            status,
            COUNT(*) as count,
            AVG(TIMESTAMPDIFF(SECOND, created_at, COALESCE(completed_at, NOW()))) as avg_duration_sec
        FROM agent_job_queue
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ";

    if ($agentType) {
        $statusQuery .= " AND agent_type = :agent_type";
    }

    $statusQuery .= " GROUP BY agent_type, status ORDER BY agent_type, status";

    $stmt = $pdo->prepare($statusQuery);
    if ($agentType) {
        $stmt->execute(['agent_type' => $agentType]);
    } else {
        $stmt->execute();
    }

    $stats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = $row['agent_type'];
        if (!isset($stats[$type])) {
            $stats[$type] = [
                'pending' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 0,
                'avg_duration_sec' => 0,
            ];
        }
        $stats[$type][$row['status']] = (int) $row['count'];
        if ($row['status'] === 'completed') {
            $stats[$type]['avg_duration_sec'] = round((float) $row['avg_duration_sec'], 2);
        }
    }

    // Get recent jobs
    $jobsQuery = "
        SELECT
            job_id, agent_type, status, priority, retry_count,
            error_message, created_at, started_at, completed_at
        FROM agent_job_queue
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ";

    if ($agentType) {
        $jobsQuery .= " AND agent_type = :agent_type";
    }

    $jobsQuery .= " ORDER BY created_at DESC LIMIT 50";

    $stmt = $pdo->prepare($jobsQuery);
    if ($agentType) {
        $stmt->execute(['agent_type' => $agentType]);
    } else {
        $stmt->execute();
    }
    $recentJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get processing rate (jobs per hour)
    $rateQuery = "
        SELECT
            agent_type,
            COUNT(*) as completed_count
        FROM agent_job_queue
        WHERE status = 'completed'
          AND completed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ";

    if ($agentType) {
        $rateQuery .= " AND agent_type = :agent_type";
    }

    $rateQuery .= " GROUP BY agent_type";

    $stmt = $pdo->prepare($rateQuery);
    if ($agentType) {
        $stmt->execute(['agent_type' => $agentType]);
    } else {
        $stmt->execute();
    }

    $processingRate = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $processingRate[$row['agent_type']] = (int) $row['completed_count'];
    }

    // Get error summary
    $errorQuery = "
        SELECT
            agent_type,
            SUBSTRING(error_message, 1, 100) as error_preview,
            COUNT(*) as count
        FROM agent_job_queue
        WHERE status = 'failed'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ";

    if ($agentType) {
        $errorQuery .= " AND agent_type = :agent_type";
    }

    $errorQuery .= " GROUP BY agent_type, error_preview ORDER BY count DESC LIMIT 10";

    $stmt = $pdo->prepare($errorQuery);
    if ($agentType) {
        $stmt->execute(['agent_type' => $agentType]);
    } else {
        $stmt->execute();
    }
    $topErrors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'stats' => $stats,
            'processing_rate_per_hour' => $processingRate,
            'recent_jobs' => $recentJobs,
            'top_errors' => $topErrors,
            'generated_at' => date('Y-m-d H:i:s'),
        ],
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
