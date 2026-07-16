<?php

declare(strict_types=1);

namespace Agents;

use Throwable;

/**
 * Contract for all pipeline agents.
 * Each agent processes jobs from the queue and optionally chains to the next agent.
 */
interface AgentInterface
{
    /**
     * Get the agent's identifier (matches agent_type in DB).
     * One of: scraper, data_cleaning, price_diff, alert_dispatch, affiliate
     */
    public function getName(): string;

    /**
     * Process a single job payload.
     *
     * @param array $payload Job-specific data from the queue
     * @return AgentResult Result containing success status and optional next payload
     */
    public function process(array $payload): AgentResult;

    /**
     * Get the next agent type in the pipeline, or null if this is the last.
     * Used for automatic job chaining.
     */
    public function getNextAgentType(): ?string;

    /**
     * Determine if a failed job should be retried based on the exception.
     * Return false for permanent failures (e.g., validation errors).
     *
     * @param Throwable $e The exception that caused the failure
     */
    public function shouldRetry(Throwable $e): bool;
}
