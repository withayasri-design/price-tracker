<?php
/**
 * Database Backup Script
 *
 * Creates compressed SQL backups of the database.
 * Supports retention policy and cleanup of old backups.
 *
 * Run: php cron/backup.php
 * Schedule: 0 2 * * * (daily at 2 AM)
 *
 * Options:
 *   --retention=DAYS  Keep backups for N days (default: 7)
 *   --compress        Compress backup with gzip (default: true)
 *   --tables=LIST     Backup specific tables only (comma-separated)
 *   --exclude=LIST    Exclude tables from backup (comma-separated)
 */

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

// Configuration
$config = [
    'db_host' => $_ENV['DB_HOST'] ?? 'localhost',
    'db_name' => $_ENV['DB_NAME'] ?? 'price_tracker',
    'db_user' => $_ENV['DB_USER'] ?? 'root',
    'db_pass' => $_ENV['DB_PASS'] ?? '',
    'backup_dir' => __DIR__ . '/../database/backups',
    'retention_days' => 7,
    'compress' => true,
    'tables' => [],      // Empty = all tables
    'exclude' => [],     // Tables to exclude
];

// Parse command line arguments
$options = getopt('', ['retention:', 'compress::', 'tables:', 'exclude:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Price Tracker Database Backup

Usage: php backup.php [options]

Options:
  --retention=DAYS   Keep backups for N days (default: 7)
  --compress         Compress backup with gzip
  --tables=LIST      Backup specific tables only (comma-separated)
  --exclude=LIST     Exclude tables from backup (comma-separated)
  --help             Show this help message

Examples:
  php backup.php
  php backup.php --retention=30
  php backup.php --tables=users,tracked_products
  php backup.php --exclude=agent_logs,raw_price_snapshots

HELP;
    exit(0);
}

if (isset($options['retention'])) {
    $config['retention_days'] = (int) $options['retention'];
}
if (isset($options['compress'])) {
    $config['compress'] = $options['compress'] !== 'false';
}
if (isset($options['tables'])) {
    $config['tables'] = array_map('trim', explode(',', $options['tables']));
}
if (isset($options['exclude'])) {
    $config['exclude'] = array_map('trim', explode(',', $options['exclude']));
}

// Create backup directory if not exists
if (!is_dir($config['backup_dir'])) {
    mkdir($config['backup_dir'], 0755, true);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting database backup..." . PHP_EOL;

// Connect to database
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']),
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    echo "[ERROR] Database connection failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// Get list of tables
$stmt = $pdo->query("SHOW TABLES");
$allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Filter tables
$tables = $allTables;
if (!empty($config['tables'])) {
    $tables = array_intersect($allTables, $config['tables']);
}
if (!empty($config['exclude'])) {
    $tables = array_diff($tables, $config['exclude']);
}

if (empty($tables)) {
    echo "[ERROR] No tables to backup!" . PHP_EOL;
    exit(1);
}

echo "[INFO] Backing up " . count($tables) . " tables..." . PHP_EOL;

// Generate backup filename
$timestamp = date('Y-m-d_His');
$filename = sprintf('%s_%s.sql', $config['db_name'], $timestamp);
$filepath = $config['backup_dir'] . '/' . $filename;

// Start building SQL dump
$dump = [];
$dump[] = "-- Price Tracker Database Backup";
$dump[] = "-- Generated: " . date('Y-m-d H:i:s');
$dump[] = "-- Database: " . $config['db_name'];
$dump[] = "-- Tables: " . count($tables);
$dump[] = "";
$dump[] = "SET FOREIGN_KEY_CHECKS=0;";
$dump[] = "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
$dump[] = "SET AUTOCOMMIT=0;";
$dump[] = "START TRANSACTION;";
$dump[] = "";

foreach ($tables as $table) {
    echo "[INFO] Backing up table: $table" . PHP_EOL;

    // Get CREATE TABLE statement
    $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
    $createTable = $stmt->fetch();
    $createSql = $createTable['Create Table'] ?? $createTable[1];

    $dump[] = "-- --------------------------------------------------------";
    $dump[] = "-- Table structure for `$table`";
    $dump[] = "-- --------------------------------------------------------";
    $dump[] = "";
    $dump[] = "DROP TABLE IF EXISTS `$table`;";
    $dump[] = $createSql . ";";
    $dump[] = "";

    // Get table data
    $stmt = $pdo->query("SELECT * FROM `$table`");
    $rows = $stmt->fetchAll();

    if (count($rows) > 0) {
        $dump[] = "-- Data for table `$table`";
        $dump[] = "";

        // Get column names
        $columns = array_keys($rows[0]);
        $columnList = '`' . implode('`, `', $columns) . '`';

        // Batch inserts for better performance
        $batchSize = 100;
        $batches = array_chunk($rows, $batchSize);

        foreach ($batches as $batch) {
            $values = [];
            foreach ($batch as $row) {
                $rowValues = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $rowValues[] = 'NULL';
                    } else {
                        $rowValues[] = $pdo->quote($value);
                    }
                }
                $values[] = '(' . implode(', ', $rowValues) . ')';
            }
            $dump[] = "INSERT INTO `$table` ($columnList) VALUES";
            $dump[] = implode(",\n", $values) . ";";
            $dump[] = "";
        }
    }

    $dump[] = "";
}

$dump[] = "SET FOREIGN_KEY_CHECKS=1;";
$dump[] = "COMMIT;";
$dump[] = "";
$dump[] = "-- Backup completed: " . date('Y-m-d H:i:s');

// Write dump to file
$dumpContent = implode("\n", $dump);
$bytesWritten = file_put_contents($filepath, $dumpContent);

if ($bytesWritten === false) {
    echo "[ERROR] Failed to write backup file!" . PHP_EOL;
    exit(1);
}

echo "[INFO] Backup written: $filename (" . number_format($bytesWritten) . " bytes)" . PHP_EOL;

// Compress if enabled
if ($config['compress'] && function_exists('gzopen')) {
    $gzFilepath = $filepath . '.gz';
    $gz = gzopen($gzFilepath, 'w9');

    if ($gz) {
        gzwrite($gz, $dumpContent);
        gzclose($gz);

        // Remove uncompressed file
        unlink($filepath);

        $compressedSize = filesize($gzFilepath);
        $compressionRatio = round((1 - $compressedSize / $bytesWritten) * 100, 1);

        echo "[INFO] Compressed: {$filename}.gz (" . number_format($compressedSize) . " bytes, {$compressionRatio}% reduction)" . PHP_EOL;
        $filename .= '.gz';
    } else {
        echo "[WARNING] Compression failed, keeping uncompressed backup" . PHP_EOL;
    }
}

// Cleanup old backups
echo "[INFO] Cleaning up old backups (retention: {$config['retention_days']} days)..." . PHP_EOL;

$cutoffTime = time() - ($config['retention_days'] * 24 * 60 * 60);
$backupFiles = glob($config['backup_dir'] . '/' . $config['db_name'] . '_*.sql*');
$deletedCount = 0;

foreach ($backupFiles as $backupFile) {
    if (filemtime($backupFile) < $cutoffTime) {
        unlink($backupFile);
        $deletedCount++;
    }
}

if ($deletedCount > 0) {
    echo "[INFO] Deleted $deletedCount old backup(s)" . PHP_EOL;
}

// List remaining backups
$remainingBackups = glob($config['backup_dir'] . '/' . $config['db_name'] . '_*.sql*');
echo "[INFO] Total backups: " . count($remainingBackups) . PHP_EOL;

// Summary
echo "[" . date('Y-m-d H:i:s') . "] Backup complete: $filename" . PHP_EOL;

// Log to agent_logs if database is available
try {
    $stmt = $pdo->prepare("
        INSERT INTO agent_logs (agent_type, log_level, message, context, created_at)
        VALUES ('backup', 'info', 'Database backup completed', ?, NOW())
    ");
    $stmt->execute([json_encode([
        'filename' => $filename,
        'tables' => count($tables),
        'size_bytes' => $config['compress'] ? ($compressedSize ?? $bytesWritten) : $bytesWritten,
        'deleted_old' => $deletedCount,
    ])]);
} catch (PDOException $e) {
    // Ignore logging errors
}

exit(0);
