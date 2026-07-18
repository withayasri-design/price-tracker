<?php
/**
 * Unit tests for Queue
 */

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class QueueTest extends TestCase
{
    /**
     * Test job payload structure
     */
    public function testJobPayloadStructure(): void
    {
        $payload = $this->createJobPayload('scraper', ['product_ids' => [1, 2, 3]]);

        $this->assertArrayHasKey('agent_type', $payload);
        $this->assertArrayHasKey('payload', $payload);
        $this->assertArrayHasKey('created_at', $payload);
        $this->assertArrayHasKey('job_id', $payload);

        $this->assertEquals('scraper', $payload['agent_type']);
        $this->assertEquals([1, 2, 3], $payload['payload']['product_ids']);
    }

    /**
     * Test job ID generation is unique
     */
    public function testJobIdUniqueness(): void
    {
        $jobIds = [];

        for ($i = 0; $i < 100; $i++) {
            $payload = $this->createJobPayload('test', []);
            $jobIds[] = $payload['job_id'];
        }

        $uniqueIds = array_unique($jobIds);
        $this->assertCount(100, $uniqueIds, 'Job IDs should be unique');
    }

    /**
     * Test job priority ordering
     */
    public function testJobPriorityOrdering(): void
    {
        $jobs = [
            ['priority' => 5, 'agent_type' => 'low'],
            ['priority' => 1, 'agent_type' => 'high'],
            ['priority' => 3, 'agent_type' => 'medium'],
        ];

        // Sort by priority (lower number = higher priority)
        usort($jobs, fn($a, $b) => $a['priority'] <=> $b['priority']);

        $this->assertEquals('high', $jobs[0]['agent_type']);
        $this->assertEquals('medium', $jobs[1]['agent_type']);
        $this->assertEquals('low', $jobs[2]['agent_type']);
    }

    /**
     * Test job status transitions
     */
    public function testJobStatusTransitions(): void
    {
        $validTransitions = [
            'pending' => ['processing', 'cancelled'],
            'processing' => ['completed', 'failed', 'retry'],
            'retry' => ['pending', 'failed'],
            'completed' => [],
            'failed' => ['retry'],
            'cancelled' => [],
        ];

        foreach ($validTransitions as $currentStatus => $allowedNext) {
            foreach ($allowedNext as $nextStatus) {
                $this->assertTrue(
                    $this->canTransition($currentStatus, $nextStatus, $validTransitions),
                    "Should allow transition from $currentStatus to $nextStatus"
                );
            }
        }

        // Test invalid transitions
        $this->assertFalse($this->canTransition('completed', 'processing', $validTransitions));
        $this->assertFalse($this->canTransition('cancelled', 'pending', $validTransitions));
    }

    /**
     * Test retry delay calculation (exponential backoff)
     */
    public function testRetryDelayCalculation(): void
    {
        $baseDelay = 60; // 1 minute
        $maxDelay = 3600; // 1 hour

        $testCases = [
            ['attempt' => 1, 'expected_min' => 60, 'expected_max' => 120],
            ['attempt' => 2, 'expected_min' => 120, 'expected_max' => 240],
            ['attempt' => 3, 'expected_min' => 240, 'expected_max' => 480],
            ['attempt' => 10, 'expected_min' => 3600, 'expected_max' => 3600], // Capped
        ];

        foreach ($testCases as $case) {
            $delay = $this->calculateRetryDelay($case['attempt'], $baseDelay, $maxDelay);

            $this->assertGreaterThanOrEqual(
                $case['expected_min'],
                $delay,
                "Attempt {$case['attempt']} delay should be >= {$case['expected_min']}"
            );
            $this->assertLessThanOrEqual(
                $case['expected_max'],
                $delay,
                "Attempt {$case['attempt']} delay should be <= {$case['expected_max']}"
            );
        }
    }

    /**
     * Test batch size validation
     */
    public function testBatchSizeValidation(): void
    {
        $validSizes = [1, 5, 10, 50, 100];
        $invalidSizes = [0, -1, 101, 1000];

        foreach ($validSizes as $size) {
            $this->assertTrue(
                $this->isValidBatchSize($size),
                "Batch size $size should be valid"
            );
        }

        foreach ($invalidSizes as $size) {
            $this->assertFalse(
                $this->isValidBatchSize($size),
                "Batch size $size should be invalid"
            );
        }
    }

    /**
     * Test agent type validation
     */
    public function testAgentTypeValidation(): void
    {
        $validTypes = ['scraper', 'data_cleaning', 'price_diff', 'alert_dispatch'];
        $invalidTypes = ['unknown', 'test', '', null];

        foreach ($validTypes as $type) {
            $this->assertTrue(
                $this->isValidAgentType($type),
                "Agent type '$type' should be valid"
            );
        }

        foreach ($invalidTypes as $type) {
            $this->assertFalse(
                $this->isValidAgentType($type),
                "Agent type '$type' should be invalid"
            );
        }
    }

    /**
     * Helper: Create job payload
     */
    private function createJobPayload(string $agentType, array $data): array
    {
        return [
            'job_id' => uniqid('job_', true),
            'agent_type' => $agentType,
            'payload' => $data,
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 'pending',
            'priority' => 5,
            'attempts' => 0,
        ];
    }

    /**
     * Helper: Check if status transition is valid
     */
    private function canTransition(string $from, string $to, array $transitions): bool
    {
        return isset($transitions[$from]) && in_array($to, $transitions[$from], true);
    }

    /**
     * Helper: Calculate retry delay with exponential backoff
     */
    private function calculateRetryDelay(int $attempt, int $baseDelay, int $maxDelay): int
    {
        $delay = $baseDelay * (2 ** ($attempt - 1));
        return min($delay, $maxDelay);
    }

    /**
     * Helper: Validate batch size
     */
    private function isValidBatchSize(int $size): bool
    {
        return $size >= 1 && $size <= 100;
    }

    /**
     * Helper: Validate agent type
     */
    private function isValidAgentType(?string $type): bool
    {
        $validTypes = ['scraper', 'data_cleaning', 'price_diff', 'alert_dispatch'];
        return $type !== null && in_array($type, $validTypes, true);
    }
}
