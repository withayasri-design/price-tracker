#!/usr/bin/env php
<?php
/**
 * Price Tracker CLI Tool
 *
 * Command-line interface for managing the Price Tracker system.
 *
 * Usage: php bin/cli.php <command> [options]
 *
 * Commands:
 *   status          Show system status
 *   queue:list      List pending jobs
 *   queue:clear     Clear completed/failed jobs
 *   queue:retry     Retry failed jobs
 *   user:list       List all users
 *   user:create     Create a new user
 *   user:password   Reset user password
 *   product:list    List tracked products
 *   product:refresh Refresh product prices
 *   cache:clear     Clear application cache
 *   db:stats        Show database statistics
 *   config:check    Verify configuration
 */

declare(strict_types=1);

// Bootstrap
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

// Colors for terminal output
class Console
{
    public static function success(string $msg): void { echo "\033[32m✓ $msg\033[0m\n"; }
    public static function error(string $msg): void { echo "\033[31m✗ $msg\033[0m\n"; }
    public static function warning(string $msg): void { echo "\033[33m! $msg\033[0m\n"; }
    public static function info(string $msg): void { echo "\033[36mℹ $msg\033[0m\n"; }
    public static function line(string $msg = ''): void { echo "$msg\n"; }

    public static function table(array $headers, array $rows): void
    {
        // Calculate column widths
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = strlen($header);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
            }
        }

        // Print header
        $line = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';
        echo "$line\n";
        echo '|';
        foreach ($headers as $i => $header) {
            echo ' ' . str_pad($header, $widths[$i]) . ' |';
        }
        echo "\n$line\n";

        // Print rows
        foreach ($rows as $row) {
            echo '|';
            foreach ($row as $i => $cell) {
                echo ' ' . str_pad((string) $cell, $widths[$i]) . ' |';
            }
            echo "\n";
        }
        echo "$line\n";
    }

    public static function prompt(string $question, string $default = ''): string
    {
        $defaultText = $default ? " [$default]" : '';
        echo "\033[33m$question$defaultText: \033[0m";
        $input = trim(fgets(STDIN));
        return $input ?: $default;
    }

    public static function confirm(string $question): bool
    {
        echo "\033[33m$question [y/N]: \033[0m";
        $input = strtolower(trim(fgets(STDIN)));
        return $input === 'y' || $input === 'yes';
    }
}

// Database connection
function getDatabase(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;dbname=%s;charset=utf8mb4',
                    $_ENV['DB_HOST'] ?? 'localhost',
                    $_ENV['DB_NAME'] ?? 'price_tracker'
                ),
                $_ENV['DB_USER'] ?? 'root',
                $_ENV['DB_PASS'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            Console::error("Database connection failed: " . $e->getMessage());
            exit(1);
        }
    }

    return $pdo;
}

// Commands
$commands = [
    'status' => function () {
        Console::line("\n=== Price Tracker Status ===\n");

        // PHP Version
        Console::info("PHP Version: " . PHP_VERSION);

        // Database
        try {
            $pdo = getDatabase();
            $stmt = $pdo->query("SELECT VERSION() as version");
            $version = $stmt->fetchColumn();
            Console::success("Database: Connected (MySQL $version)");
        } catch (Exception $e) {
            Console::error("Database: Not connected");
        }

        // Tables
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        Console::info("Tables: " . count($tables));

        // Users
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        Console::info("Users: " . $stmt->fetchColumn());

        // Products
        $stmt = $pdo->query("SELECT COUNT(*) FROM tracked_products WHERE is_active = 1");
        Console::info("Active Products: " . $stmt->fetchColumn());

        // Queue
        $stmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM agent_job_queue GROUP BY status");
        $queue = $stmt->fetchAll();
        Console::info("Job Queue:");
        foreach ($queue as $row) {
            Console::line("  - {$row['status']}: {$row['cnt']}");
        }

        // Disk usage
        $dir = dirname(__DIR__);
        $size = shell_exec("du -sh $dir 2>/dev/null") ?: 'N/A';
        Console::info("Disk Usage: " . trim($size));

        Console::line();
    },

    'queue:list' => function () {
        $pdo = getDatabase();

        $stmt = $pdo->query("
            SELECT job_id, agent_type, status, priority, attempts, created_at
            FROM agent_job_queue
            ORDER BY priority ASC, created_at ASC
            LIMIT 20
        ");
        $jobs = $stmt->fetchAll();

        if (empty($jobs)) {
            Console::info("No jobs in queue.");
            return;
        }

        Console::table(
            ['ID', 'Agent', 'Status', 'Priority', 'Attempts', 'Created'],
            array_map(fn($j) => [
                $j['job_id'],
                $j['agent_type'],
                $j['status'],
                $j['priority'],
                $j['attempts'],
                $j['created_at'],
            ], $jobs)
        );
    },

    'queue:clear' => function () {
        if (!Console::confirm("Clear all completed/failed jobs?")) {
            return;
        }

        $pdo = getDatabase();
        $stmt = $pdo->query("DELETE FROM agent_job_queue WHERE status IN ('completed', 'failed')");
        Console::success("Cleared " . $stmt->rowCount() . " jobs.");
    },

    'queue:retry' => function () {
        $pdo = getDatabase();

        $stmt = $pdo->query("
            UPDATE agent_job_queue
            SET status = 'pending', attempts = 0, error_message = NULL
            WHERE status = 'failed'
        ");

        Console::success("Reset " . $stmt->rowCount() . " failed jobs to pending.");
    },

    'user:list' => function () {
        $pdo = getDatabase();

        $stmt = $pdo->query("
            SELECT user_id, email, full_name, role, is_active, created_at
            FROM users
            ORDER BY user_id
        ");
        $users = $stmt->fetchAll();

        Console::table(
            ['ID', 'Email', 'Name', 'Role', 'Active', 'Created'],
            array_map(fn($u) => [
                $u['user_id'],
                $u['email'],
                substr($u['full_name'], 0, 20),
                $u['role'],
                $u['is_active'] ? 'Yes' : 'No',
                substr($u['created_at'], 0, 10),
            ], $users)
        );
    },

    'user:create' => function () {
        $email = Console::prompt("Email");
        $name = Console::prompt("Full Name");
        $password = Console::prompt("Password (min 8 chars)");
        $role = Console::prompt("Role", "user");

        if (strlen($password) < 8) {
            Console::error("Password must be at least 8 characters.");
            return;
        }

        $pdo = getDatabase();

        // Check if email exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            Console::error("Email already exists.");
            return;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, full_name, role, is_active, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$email, $hash, $name, $role]);

        Console::success("User created with ID: " . $pdo->lastInsertId());
    },

    'user:password' => function () {
        $email = Console::prompt("User Email");
        $password = Console::prompt("New Password (min 8 chars)");

        if (strlen($password) < 8) {
            Console::error("Password must be at least 8 characters.");
            return;
        }

        $pdo = getDatabase();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->execute([$hash, $email]);

        if ($stmt->rowCount() > 0) {
            Console::success("Password updated.");
        } else {
            Console::error("User not found.");
        }
    },

    'product:list' => function () {
        $pdo = getDatabase();

        $stmt = $pdo->query("
            SELECT product_id, platform, product_name, last_price, last_stock_status, updated_at
            FROM tracked_products
            WHERE is_active = 1
            ORDER BY updated_at DESC
            LIMIT 20
        ");
        $products = $stmt->fetchAll();

        Console::table(
            ['ID', 'Platform', 'Name', 'Price', 'Stock', 'Updated'],
            array_map(fn($p) => [
                $p['product_id'],
                $p['platform'],
                mb_substr($p['product_name'], 0, 30),
                number_format((float) $p['last_price'], 0),
                $p['last_stock_status'],
                substr($p['updated_at'] ?? '', 0, 16),
            ], $products)
        );
    },

    'product:refresh' => function (array $args) {
        $productId = $args[0] ?? null;

        if (!$productId) {
            Console::error("Usage: product:refresh <product_id>");
            return;
        }

        $pdo = getDatabase();

        // Queue a scrape job for this product
        $stmt = $pdo->prepare("
            INSERT INTO agent_job_queue (agent_type, payload, status, priority, created_at)
            VALUES ('scraper', ?, 'pending', 1, NOW())
        ");
        $stmt->execute([json_encode(['product_ids' => [(int) $productId]])]);

        Console::success("Queued refresh for product #$productId");
    },

    'cache:clear' => function () {
        $cacheDir = dirname(__DIR__) . '/cache';

        if (!is_dir($cacheDir)) {
            Console::info("Cache directory does not exist.");
            return;
        }

        $files = glob($cacheDir . '/*');
        $count = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $count++;
            }
        }

        Console::success("Cleared $count cached files.");
    },

    'db:stats' => function () {
        $pdo = getDatabase();

        Console::line("\n=== Database Statistics ===\n");

        $tables = [
            'users' => 'Users',
            'tracked_products' => 'Products',
            'price_history' => 'Price History',
            'user_tracking' => 'User Tracking',
            'alerts' => 'Alerts',
            'agent_job_queue' => 'Job Queue',
            'agent_logs' => 'Agent Logs',
            'price_events' => 'Price Events',
        ];

        $rows = [];
        foreach ($tables as $table => $label) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
                $count = $stmt->fetchColumn();
                $rows[] = [$label, number_format($count)];
            } catch (Exception $e) {
                $rows[] = [$label, 'N/A'];
            }
        }

        Console::table(['Table', 'Rows'], $rows);

        // Database size
        $dbName = $_ENV['DB_NAME'] ?? 'price_tracker';
        $stmt = $pdo->prepare("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
            FROM information_schema.tables
            WHERE table_schema = ?
        ");
        $stmt->execute([$dbName]);
        $size = $stmt->fetchColumn();

        Console::info("\nTotal Database Size: {$size} MB");
    },

    'config:check' => function () {
        Console::line("\n=== Configuration Check ===\n");

        $checks = [
            ['PHP Version >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['PDO Extension', extension_loaded('pdo')],
            ['PDO MySQL', extension_loaded('pdo_mysql')],
            ['cURL Extension', extension_loaded('curl')],
            ['JSON Extension', extension_loaded('json')],
            ['mbstring Extension', extension_loaded('mbstring')],
            ['.env File Exists', file_exists(dirname(__DIR__) . '/.env')],
            ['vendor/ Directory', is_dir(dirname(__DIR__) . '/vendor')],
            ['logs/ Writable', is_writable(dirname(__DIR__) . '/logs') || @mkdir(dirname(__DIR__) . '/logs', 0755, true)],
            ['cache/ Writable', is_writable(dirname(__DIR__) . '/cache') || @mkdir(dirname(__DIR__) . '/cache', 0755, true)],
        ];

        $allPassed = true;
        foreach ($checks as [$name, $passed]) {
            if ($passed) {
                Console::success($name);
            } else {
                Console::error($name);
                $allPassed = false;
            }
        }

        // Database connection
        try {
            getDatabase();
            Console::success("Database Connection");
        } catch (Exception $e) {
            Console::error("Database Connection: " . $e->getMessage());
            $allPassed = false;
        }

        Console::line();
        if ($allPassed) {
            Console::success("All checks passed!");
        } else {
            Console::warning("Some checks failed. Please review.");
        }
    },

    'help' => function () {
        Console::line("
Price Tracker CLI

Usage: php bin/cli.php <command> [arguments]

Commands:
  status            Show system status
  queue:list        List pending jobs in queue
  queue:clear       Clear completed/failed jobs
  queue:retry       Retry all failed jobs
  user:list         List all users
  user:create       Create a new user interactively
  user:password     Reset a user's password
  product:list      List tracked products
  product:refresh   Queue price refresh for a product
  cache:clear       Clear application cache
  db:stats          Show database statistics
  config:check      Verify system configuration
  help              Show this help message

Examples:
  php bin/cli.php status
  php bin/cli.php user:create
  php bin/cli.php product:refresh 123
  php bin/cli.php queue:retry
");
    },
];

// Parse arguments
$command = $argv[1] ?? 'help';
$args = array_slice($argv, 2);

// Execute command
if (isset($commands[$command])) {
    $commands[$command]($args);
} else {
    Console::error("Unknown command: $command");
    Console::line("Run 'php bin/cli.php help' for available commands.");
    exit(1);
}
