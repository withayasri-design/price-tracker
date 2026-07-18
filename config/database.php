<?php

/**
 * Database Configuration
 *
 * Establishes PDO connection to MariaDB.
 * Reads credentials from environment variables or .env file.
 */

declare(strict_types=1);

// Load .env file if exists
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue; // Skip comments
        if (strpos($line, '=') === false) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if (!isset($_ENV[$key]) && !getenv($key)) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

// Database configuration
$dbConfig = [
    'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost',
    'port' => $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306',
    'name' => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'price_tracker',
    'user' => $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root',
    'pass' => $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '',
    'charset' => $_ENV['DB_CHARSET'] ?? getenv('DB_CHARSET') ?: 'utf8mb4',
];

// Build DSN
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $dbConfig['host'],
    $dbConfig['port'],
    $dbConfig['name'],
    $dbConfig['charset']
);

// PDO options
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$dbConfig['charset']} COLLATE utf8mb4_unicode_ci",
];

// Create PDO instance
try {
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], $options);
} catch (PDOException $e) {
    // Log error using file logger
    require_once __DIR__ . '/../core/Logger.php';
    \Core\Logger::channel('database')->critical('Database connection failed', [
        'error' => $e->getMessage(),
        'host' => $dbConfig['host'],
        'database' => $dbConfig['name'],
    ]);

    if (($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: 'false') === 'true') {
        throw $e;
    }

    die('Database connection failed. Please check configuration.');
}

// Make $pdo available globally
return $pdo;
