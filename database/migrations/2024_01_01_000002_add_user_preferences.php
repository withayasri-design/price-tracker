<?php
/**
 * Migration: Add user preferences column
 * Created: 2024-01-01 00:00:02
 *
 * Adds a JSON preferences column to the users table for storing
 * user-specific settings like notification frequency, display options, etc.
 */

return [
    'up' => function (PDO $pdo): void {
        // Check if column already exists
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'preferences'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("
                ALTER TABLE users
                ADD COLUMN preferences JSON DEFAULT NULL
                COMMENT 'User preferences as JSON'
                AFTER notify_line
            ");
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("
            ALTER TABLE users
            DROP COLUMN IF EXISTS preferences
        ");
    },
];
