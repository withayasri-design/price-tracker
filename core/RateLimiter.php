<?php
/**
 * Rate Limiter
 *
 * Simple database-backed rate limiting for API endpoints.
 * Uses sliding window algorithm with configurable limits.
 */

declare(strict_types=1);

namespace Core;

use PDO;

class RateLimiter
{
    private PDO $pdo;
    private string $tableName = 'rate_limits';

    // Default limits
    private array $defaultLimits = [
        'api' => ['requests' => 60, 'window' => 60],      // 60 requests per minute
        'login' => ['requests' => 5, 'window' => 300],     // 5 attempts per 5 minutes
        'scrape' => ['requests' => 10, 'window' => 60],    // 10 scrapes per minute
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTableExists();
    }

    /**
     * Check if request is allowed
     *
     * @param string $identifier Unique identifier (e.g., user_id, IP address)
     * @param string $action Action type (e.g., 'api', 'login', 'scrape')
     * @param int|null $maxRequests Override default max requests
     * @param int|null $windowSeconds Override default window
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int]
     */
    public function check(
        string $identifier,
        string $action = 'api',
        ?int $maxRequests = null,
        ?int $windowSeconds = null
    ): array {
        $limits = $this->defaultLimits[$action] ?? $this->defaultLimits['api'];
        $maxRequests = $maxRequests ?? $limits['requests'];
        $windowSeconds = $windowSeconds ?? $limits['window'];

        $key = $this->makeKey($identifier, $action);
        $now = time();
        $windowStart = $now - $windowSeconds;

        // Clean old entries and count recent requests
        $this->cleanup($key, $windowStart);
        $count = $this->getCount($key, $windowStart);

        $allowed = $count < $maxRequests;
        $remaining = max(0, $maxRequests - $count - ($allowed ? 1 : 0));
        $resetAt = $now + $windowSeconds;

        if ($allowed) {
            $this->record($key, $now);
        }

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $resetAt,
            'limit' => $maxRequests,
            'window' => $windowSeconds,
        ];
    }

    /**
     * Middleware-style check that sends headers and returns false if limited
     */
    public function checkWithHeaders(
        string $identifier,
        string $action = 'api',
        ?int $maxRequests = null,
        ?int $windowSeconds = null
    ): bool {
        $result = $this->check($identifier, $action, $maxRequests, $windowSeconds);

        // Set rate limit headers
        header('X-RateLimit-Limit: ' . $result['limit']);
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: ' . $result['reset_at']);

        if (!$result['allowed']) {
            header('Retry-After: ' . $result['window']);
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => 'Too many requests',
                'retry_after' => $result['window'],
            ]);
            return false;
        }

        return true;
    }

    /**
     * Reset rate limit for an identifier
     */
    public function reset(string $identifier, string $action = 'api'): void
    {
        $key = $this->makeKey($identifier, $action);

        $stmt = $this->pdo->prepare("
            DELETE FROM {$this->tableName} WHERE rate_key = ?
        ");
        $stmt->execute([$key]);
    }

    /**
     * Get current usage stats
     */
    public function getStats(string $identifier, string $action = 'api'): array
    {
        $limits = $this->defaultLimits[$action] ?? $this->defaultLimits['api'];
        $key = $this->makeKey($identifier, $action);
        $windowStart = time() - $limits['window'];

        $count = $this->getCount($key, $windowStart);

        return [
            'identifier' => $identifier,
            'action' => $action,
            'current_count' => $count,
            'max_requests' => $limits['requests'],
            'window_seconds' => $limits['window'],
            'remaining' => max(0, $limits['requests'] - $count),
        ];
    }

    /**
     * Set custom limits for an action
     */
    public function setLimits(string $action, int $requests, int $windowSeconds): void
    {
        $this->defaultLimits[$action] = [
            'requests' => $requests,
            'window' => $windowSeconds,
        ];
    }

    /**
     * Create rate limit key
     */
    private function makeKey(string $identifier, string $action): string
    {
        return hash('sha256', $action . ':' . $identifier);
    }

    /**
     * Record a request
     */
    private function record(string $key, int $timestamp): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->tableName} (rate_key, timestamp)
            VALUES (?, ?)
        ");
        $stmt->execute([$key, $timestamp]);
    }

    /**
     * Get count of requests in window
     */
    private function getCount(string $key, int $windowStart): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM {$this->tableName}
            WHERE rate_key = ? AND timestamp >= ?
        ");
        $stmt->execute([$key, $windowStart]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Clean up old entries
     */
    private function cleanup(string $key, int $windowStart): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM {$this->tableName}
            WHERE rate_key = ? AND timestamp < ?
        ");
        $stmt->execute([$key, $windowStart]);
    }

    /**
     * Ensure rate limits table exists
     */
    private function ensureTableExists(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tableName} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rate_key VARCHAR(64) NOT NULL,
                timestamp INT NOT NULL,
                INDEX idx_key_timestamp (rate_key, timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Global cleanup of expired entries (run periodically)
     */
    public function globalCleanup(int $maxAge = 3600): int
    {
        $cutoff = time() - $maxAge;

        $stmt = $this->pdo->prepare("
            DELETE FROM {$this->tableName} WHERE timestamp < ?
        ");
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }
}
