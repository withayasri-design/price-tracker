<?php

declare(strict_types=1);

namespace Agents;

/**
 * Result object returned by agent processing.
 */
class AgentResult
{
    public bool $success;
    public ?string $message;
    public ?array $nextPayload;
    public array $metrics;

    /**
     * @param bool $success Whether the job completed successfully
     * @param string|null $message Optional status message
     * @param array|null $nextPayload Data to pass to the next agent in pipeline (if any)
     * @param array $metrics Processing metrics (e.g., duration_ms, items_processed)
     */
    public function __construct(
        bool $success,
        ?string $message = null,
        ?array $nextPayload = null,
        array $metrics = []
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->nextPayload = $nextPayload;
        $this->metrics = $metrics;
    }

    /**
     * Create a successful result.
     */
    public static function success(?string $message = null, ?array $nextPayload = null, array $metrics = []): self
    {
        return new self(true, $message, $nextPayload, $metrics);
    }

    /**
     * Create a failed result.
     */
    public static function failure(string $message, array $metrics = []): self
    {
        return new self(false, $message, null, $metrics);
    }

    /**
     * Convert to array for logging.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'has_next_payload' => $this->nextPayload !== null,
            'metrics' => $this->metrics,
        ];
    }
}
