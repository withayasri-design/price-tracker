<?php

declare(strict_types=1);

namespace Core;

use PDO;

/**
 * Simple database-backed job queue for agent pipeline.
 * Uses agent_job_queue table for persistence.
 */
class Queue
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Add a job to the queue.
     *
     * @param string $agentType One of: scraper, data_cleaning, price_diff, alert_dispatch, affiliate
     * @param array $payload Job data (will be JSON encoded)
     * @param int $priority 1=highest, 10=lowest (default: 5)
     * @return int The created job ID
     */
    public function push(string $agentType, array $payload, int $priority = 5): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO agent_job_queue (agent_type, payload, priority, status, created_at)
            VALUES (:agent_type, :payload, :priority, 'pending', NOW())
        ");

        $stmt->execute([
            'agent_type' => $agentType,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'priority' => $priority,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Fetch and lock the next pending job for a specific agent type.
     * Returns null if no jobs available.
     *
     * @param string $agentType
     * @return array|null Job data with decoded payload, or null
     */
    public function pop(string $agentType): ?array
    {
        $this->pdo->beginTransaction();

        try {
            // Select oldest pending job with highest priority (lowest number)
            $stmt = $this->pdo->prepare("
                SELECT job_id, agent_type, payload, priority, retry_count, max_retries
                FROM agent_job_queue
                WHERE agent_type = :agent_type
                  AND status = 'pending'
                ORDER BY priority ASC, created_at ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(['agent_type' => $agentType]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                $this->pdo->rollBack();
                return null;
            }

            // Mark as processing
            $updateStmt = $this->pdo->prepare("
                UPDATE agent_job_queue
                SET status = 'processing', started_at = NOW()
                WHERE job_id = :job_id
            ");
            $updateStmt->execute(['job_id' => $job['job_id']]);

            $this->pdo->commit();

            $job['payload'] = json_decode($job['payload'], true);
            return $job;

        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Mark a job as completed.
     *
     * @param int $jobId
     */
    public function complete(int $jobId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE agent_job_queue
            SET status = 'completed', completed_at = NOW()
            WHERE job_id = :job_id
        ");
        $stmt->execute(['job_id' => $jobId]);
    }

    /**
     * Mark a job as failed. Will retry if under max_retries limit.
     *
     * @param int $jobId
     * @param string $errorMessage
     * @return bool True if job will be retried, false if permanently failed
     */
    public function fail(int $jobId, string $errorMessage): bool
    {
        // Get current retry count
        $stmt = $this->pdo->prepare("
            SELECT retry_count, max_retries
            FROM agent_job_queue
            WHERE job_id = :job_id
        ");
        $stmt->execute(['job_id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            return false;
        }

        $newRetryCount = $job['retry_count'] + 1;
        $willRetry = $newRetryCount < $job['max_retries'];

        $updateStmt = $this->pdo->prepare("
            UPDATE agent_job_queue
            SET status = :status,
                retry_count = :retry_count,
                error_message = :error_message,
                completed_at = CASE WHEN :will_retry = 0 THEN NOW() ELSE NULL END,
                started_at = NULL
            WHERE job_id = :job_id
        ");
        $updateStmt->execute([
            'job_id' => $jobId,
            'status' => $willRetry ? 'pending' : 'failed',
            'retry_count' => $newRetryCount,
            'error_message' => $errorMessage,
            'will_retry' => $willRetry ? 1 : 0,
        ]);

        return $willRetry;
    }

    /**
     * Get count of jobs by status for a specific agent type.
     *
     * @param string $agentType
     * @return array Associative array with status counts
     */
    public function getStats(string $agentType): array
    {
        $stmt = $this->pdo->prepare("
            SELECT status, COUNT(*) as count
            FROM agent_job_queue
            WHERE agent_type = :agent_type
            GROUP BY status
        ");
        $stmt->execute(['agent_type' => $agentType]);

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stats[$row['status']] = (int) $row['count'];
        }

        return $stats;
    }

    /**
     * Clean up old completed/failed jobs.
     *
     * @param int $daysOld Delete jobs older than this many days
     * @return int Number of deleted jobs
     */
    public function cleanup(int $daysOld = 7): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM agent_job_queue
            WHERE status IN ('completed', 'failed')
              AND completed_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $stmt->execute(['days' => $daysOld]);

        return $stmt->rowCount();
    }
}
