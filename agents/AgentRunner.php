<?php

declare(strict_types=1);

namespace Agents;

use Core\Queue;
use PDO;
use Throwable;

/**
 * Processes jobs from the queue using registered agents.
 * Handles job lifecycle, logging, and pipeline chaining.
 */
class AgentRunner
{
    private PDO $pdo;
    private Queue $queue;
    /** @var array<string, AgentInterface> */
    private array $agents = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->queue = new Queue($pdo);
    }

    /**
     * Register an agent for processing jobs.
     */
    public function register(AgentInterface $agent): void
    {
        $this->agents[$agent->getName()] = $agent;
    }

    /**
     * Process jobs for a specific agent type.
     *
     * @param string $agentType The agent type to process
     * @param int $maxJobs Maximum number of jobs to process (0 = unlimited)
     * @param int $sleepMs Milliseconds to sleep between jobs
     * @return array Processing statistics
     */
    public function run(string $agentType, int $maxJobs = 0, int $sleepMs = 100): array
    {
        if (!isset($this->agents[$agentType])) {
            throw new \InvalidArgumentException("Unknown agent type: {$agentType}");
        }

        $agent = $this->agents[$agentType];
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $startTime = microtime(true);

        while ($maxJobs === 0 || $processed < $maxJobs) {
            $job = $this->queue->pop($agentType);

            if ($job === null) {
                break; // No more jobs
            }

            $processed++;
            $jobStartTime = microtime(true);

            try {
                $result = $agent->process($job['payload']);
                $durationMs = (int) ((microtime(true) - $jobStartTime) * 1000);

                if ($result->success) {
                    $this->queue->complete((int) $job['job_id']);
                    $succeeded++;

                    $this->log($agentType, (int) $job['job_id'], 'info', 'Job completed', [
                        'duration_ms' => $durationMs,
                        'metrics' => $result->metrics,
                    ]);

                    // Chain to next agent if configured
                    $nextAgentType = $agent->getNextAgentType();
                    if ($nextAgentType !== null && $result->nextPayload !== null) {
                        $this->queue->push($nextAgentType, $result->nextPayload);
                        $this->log($agentType, (int) $job['job_id'], 'info', "Chained to {$nextAgentType}");
                    }
                } else {
                    $willRetry = $this->queue->fail((int) $job['job_id'], $result->message ?? 'Unknown error');
                    $failed++;

                    $this->log($agentType, (int) $job['job_id'], 'warning', $result->message ?? 'Job failed', [
                        'will_retry' => $willRetry,
                        'duration_ms' => $durationMs,
                    ]);
                }

            } catch (Throwable $e) {
                $durationMs = (int) ((microtime(true) - $jobStartTime) * 1000);
                $shouldRetry = $agent->shouldRetry($e);

                if ($shouldRetry) {
                    $willRetry = $this->queue->fail((int) $job['job_id'], $e->getMessage());
                } else {
                    // Permanently fail - set retry_count to max
                    $this->pdo->prepare("
                        UPDATE agent_job_queue
                        SET status = 'failed', retry_count = max_retries,
                            error_message = :error, completed_at = NOW()
                        WHERE job_id = :job_id
                    ")->execute([
                        'job_id' => $job['job_id'],
                        'error' => $e->getMessage(),
                    ]);
                    $willRetry = false;
                }

                $failed++;

                $this->log($agentType, (int) $job['job_id'], 'error', $e->getMessage(), [
                    'exception' => get_class($e),
                    'will_retry' => $willRetry,
                    'duration_ms' => $durationMs,
                ]);
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return [
            'agent_type' => $agentType,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
        ];
    }

    /**
     * Run all registered agents once (useful for testing).
     *
     * @param int $maxJobsPerAgent
     * @return array Stats per agent
     */
    public function runAll(int $maxJobsPerAgent = 10): array
    {
        $results = [];
        foreach (array_keys($this->agents) as $agentType) {
            $results[$agentType] = $this->run($agentType, $maxJobsPerAgent);
        }
        return $results;
    }

    /**
     * Write to agent_logs table.
     */
    private function log(string $agentType, ?int $jobId, string $level, string $message, array $context = []): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO agent_logs (agent_type, job_id, log_level, message, context, created_at)
            VALUES (:agent_type, :job_id, :log_level, :message, :context, NOW())
        ");
        $stmt->execute([
            'agent_type' => $agentType,
            'job_id' => $jobId,
            'log_level' => $level,
            'message' => $message,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Get queue statistics for all registered agents.
     */
    public function getStats(): array
    {
        $stats = [];
        foreach (array_keys($this->agents) as $agentType) {
            $stats[$agentType] = $this->queue->getStats($agentType);
        }
        return $stats;
    }
}
