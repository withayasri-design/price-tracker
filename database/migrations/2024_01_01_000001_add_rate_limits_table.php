<?php
/**
 * Migration: Add rate limits table
 * Created: 2024-01-01 00:00:01
 *
 * Creates the rate_limits table for API rate limiting.
 */

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rate_key VARCHAR(64) NOT NULL,
                timestamp INT NOT NULL,
                INDEX idx_key_timestamp (rate_key, timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE IF EXISTS rate_limits");
    },
];
