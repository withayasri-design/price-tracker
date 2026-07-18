<?php
/**
 * Database Migration System
 *
 * Simple migration system for managing database schema changes.
 *
 * Usage:
 *   php database/migrate.php              # Run pending migrations
 *   php database/migrate.php --status     # Show migration status
 *   php database/migrate.php --rollback   # Rollback last migration
 *   php database/migrate.php --create NAME # Create new migration file
 */

declare(strict_types=1);

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

// Configuration
$migrationsDir = __DIR__ . '/migrations';
$migrationsTable = 'migrations';

// Console helpers
function success(string $msg): void { echo "\033[32m✓ $msg\033[0m\n"; }
function error(string $msg): void { echo "\033[31m✗ $msg\033[0m\n"; }
function info(string $msg): void { echo "\033[36mℹ $msg\033[0m\n"; }
function warning(string $msg): void { echo "\033[33m! $msg\033[0m\n"; }

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
            error("Database connection failed: " . $e->getMessage());
            exit(1);
        }
    }

    return $pdo;
}

// Ensure migrations table exists
function ensureMigrationsTable(PDO $pdo, string $table): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `$table` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            batch INT NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Get executed migrations
function getExecutedMigrations(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("SELECT migration FROM `$table` ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get current batch number
function getCurrentBatch(PDO $pdo, string $table): int
{
    $stmt = $pdo->query("SELECT MAX(batch) FROM `$table`");
    return (int) $stmt->fetchColumn();
}

// Get migration files
function getMigrationFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob($dir . '/*.php');
    sort($files);

    return array_map('basename', $files);
}

// Run migrations
function runMigrations(PDO $pdo, string $table, string $dir): void
{
    ensureMigrationsTable($pdo, $table);

    $executed = getExecutedMigrations($pdo, $table);
    $files = getMigrationFiles($dir);
    $pending = array_diff($files, $executed);

    if (empty($pending)) {
        info("No pending migrations.");
        return;
    }

    $batch = getCurrentBatch($pdo, $table) + 1;

    echo "\nRunning " . count($pending) . " migration(s)...\n\n";

    foreach ($pending as $file) {
        $migration = require $dir . '/' . $file;

        if (!isset($migration['up']) || !is_callable($migration['up'])) {
            error("Invalid migration: $file (missing 'up' function)");
            continue;
        }

        try {
            $pdo->beginTransaction();

            echo "Migrating: $file ... ";
            $migration['up']($pdo);

            $stmt = $pdo->prepare("INSERT INTO `$table` (migration, batch) VALUES (?, ?)");
            $stmt->execute([$file, $batch]);

            $pdo->commit();
            success("done");

        } catch (Exception $e) {
            $pdo->rollBack();
            error("failed");
            error("  " . $e->getMessage());
            exit(1);
        }
    }

    echo "\n";
    success("All migrations completed.");
}

// Rollback last batch
function rollbackMigrations(PDO $pdo, string $table, string $dir): void
{
    $batch = getCurrentBatch($pdo, $table);

    if ($batch === 0) {
        info("Nothing to rollback.");
        return;
    }

    $stmt = $pdo->prepare("SELECT migration FROM `$table` WHERE batch = ? ORDER BY id DESC");
    $stmt->execute([$batch]);
    $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($migrations)) {
        info("Nothing to rollback.");
        return;
    }

    echo "\nRolling back " . count($migrations) . " migration(s)...\n\n";

    foreach ($migrations as $file) {
        $migrationFile = $dir . '/' . $file;

        if (!file_exists($migrationFile)) {
            warning("Migration file not found: $file");
            continue;
        }

        $migration = require $migrationFile;

        if (!isset($migration['down']) || !is_callable($migration['down'])) {
            warning("No rollback defined for: $file");
            continue;
        }

        try {
            $pdo->beginTransaction();

            echo "Rolling back: $file ... ";
            $migration['down']($pdo);

            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE migration = ?");
            $stmt->execute([$file]);

            $pdo->commit();
            success("done");

        } catch (Exception $e) {
            $pdo->rollBack();
            error("failed");
            error("  " . $e->getMessage());
            exit(1);
        }
    }

    echo "\n";
    success("Rollback completed.");
}

// Show migration status
function showStatus(PDO $pdo, string $table, string $dir): void
{
    ensureMigrationsTable($pdo, $table);

    $executed = getExecutedMigrations($pdo, $table);
    $files = getMigrationFiles($dir);

    echo "\n=== Migration Status ===\n\n";

    if (empty($files)) {
        info("No migration files found.");
        return;
    }

    foreach ($files as $file) {
        if (in_array($file, $executed)) {
            success("$file");
        } else {
            warning("$file (pending)");
        }
    }

    $pending = count(array_diff($files, $executed));
    echo "\n";
    info("Total: " . count($files) . " migrations, $pending pending");
}

// Create new migration
function createMigration(string $dir, string $name): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $timestamp = date('Y_m_d_His');
    $filename = "{$timestamp}_{$name}.php";
    $filepath = $dir . '/' . $filename;

    $template = <<<'PHP'
<?php
/**
 * Migration: %NAME%
 * Created: %DATE%
 */

return [
    'up' => function (PDO $pdo): void {
        // Run migration
        $pdo->exec("
            -- Add your SQL here
        ");
    },

    'down' => function (PDO $pdo): void {
        // Rollback migration
        $pdo->exec("
            -- Add rollback SQL here
        ");
    },
];
PHP;

    $content = str_replace(
        ['%NAME%', '%DATE%'],
        [$name, date('Y-m-d H:i:s')],
        $template
    );

    file_put_contents($filepath, $content);
    success("Created migration: $filename");
}

// Parse arguments
$args = array_slice($argv, 1);
$command = 'migrate';

foreach ($args as $arg) {
    if ($arg === '--status') {
        $command = 'status';
    } elseif ($arg === '--rollback') {
        $command = 'rollback';
    } elseif (str_starts_with($arg, '--create')) {
        $command = 'create';
    } elseif ($arg === '--help') {
        $command = 'help';
    }
}

// Execute command
$pdo = getDatabase();

switch ($command) {
    case 'migrate':
        runMigrations($pdo, $migrationsTable, $migrationsDir);
        break;

    case 'status':
        showStatus($pdo, $migrationsTable, $migrationsDir);
        break;

    case 'rollback':
        rollbackMigrations($pdo, $migrationsTable, $migrationsDir);
        break;

    case 'create':
        $name = $args[array_search('--create', $args) + 1] ?? null;
        if (!$name) {
            $name = readline("Migration name: ");
        }
        if ($name) {
            $name = preg_replace('/[^a-z0-9_]/i', '_', $name);
            createMigration($migrationsDir, $name);
        } else {
            error("Migration name is required.");
        }
        break;

    case 'help':
    default:
        echo <<<HELP

Database Migration System

Usage: php database/migrate.php [command]

Commands:
  (default)         Run all pending migrations
  --status          Show migration status
  --rollback        Rollback the last batch of migrations
  --create NAME     Create a new migration file
  --help            Show this help message

Examples:
  php database/migrate.php
  php database/migrate.php --status
  php database/migrate.php --rollback
  php database/migrate.php --create add_user_preferences

Migration files are stored in: database/migrations/

HELP;
        break;
}
