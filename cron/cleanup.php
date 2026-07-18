<?php
/**
 * Cleanup Cron Job
 *
 * Performs daily maintenance tasks:
 * - Removes old log entries
 * - Cleans up expired sessions
 * - Purges old price history (configurable retention)
 * - Removes orphaned records
 *
 * Run: php cron/cleanup.php
 * Schedule: 0 3 * * * (daily at 3 AM)
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
    'retention_days_price_history' => 90,    // Keep 90 days of price history
    'retention_days_agent_logs' => 30,       // Keep 30 days of agent logs
    'retention_days_raw_snapshots' => 7,     // Keep 7 days of raw snapshots
    'retention_days_completed_jobs' => 7,    // Keep 7 days of completed jobs
];

// Database connection
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
    echo "[ERROR] Database connection failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting cleanup..." . PHP_EOL;

$totalDeleted = 0;

// 1. Clean old price history
try {
    $stmt = $pdo->prepare("
        DELETE FROM price_history
        WHERE scraped_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$config['retention_days_price_history']]);
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "[INFO] Deleted $deleted old price history records" . PHP_EOL;
} catch (PDOException $e) {
    echo "[WARNING] Failed to clean price_history: " . $e->getMessage() . PHP_EOL;
}

// 2. Clean old agent logs
try {
    $stmt = $pdo->prepare("
        DELETE FROM agent_logs
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$config['retention_days_agent_logs']]);
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "[INFO] Deleted $deleted old agent log entries" . PHP_EOL;
} catch (PDOException $e) {
    echo "[WARNING] Failed to clean agent_logs: " . $e->getMessage() . PHP_EOL;
}

// 3. Clean old raw price snapshots
try {
    $stmt = $pdo->prepare("
        DELETE FROM raw_price_snapshots
        WHERE scraped_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$config['retention_days_raw_snapshots']]);
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "[INFO] Deleted $deleted old raw snapshots" . PHP_EOL;
} catch (PDOException $e) {
    echo "[WARNING] Failed to clean raw_price_snapshots: " . $e->getMessage() . PHP_EOL;
}

// 4. Clean completed/failed jobs
try {
    $stmt = $pdo->prepare("
        DELETE FROM agent_job_queue
        WHERE status IN ('completed', 'failed')
        AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$config['retention_days_completed_jobs']]);
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "[INFO] Deleted $deleted old job queue entries" . PHP_EOL;
} catch (PDOException $e) {
    echo "[WARNING] Failed to clean agent_job_queue: " . $e->getMessage() . PHP_EOL;
}

// 5. Clean dispatched price events (older than 30 days)
try {
    $stmt = $pdo->query("
        DELETE FROM price_events
        WHERE is_dispatched = 1
        AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "[INFO] Deleted $deleted old dispatched price events" . PHP_EOL;
} catch (PDOException $e) {
    echo "[WARNING] Failed to clean price_events: " . $e->getMessage() . PHP_EOL;
}

// 6. Clean old sent alerts (older than 90 days)
try {
    $stmt = $pdo->query("
        DELETE FROM alerts
        WHERE sent_at IS NOT NULL
        AND sent_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
    echo "[INFO] Deleted $deleted old sent alerts" . PHP_EOL;
} catch (PDOException $e) {
    echo "[WARNING] Failed to clean alerts: " . $e->getMessage() . PHP_EOL;
}

// 7. Reset stuck jobs (processing for more than 1 hour)
try {
    $stmt = $pdo->query("
        UPDATE agent_job_queue
        SET status = 'pending',
            started_at = NULL,
            attempts = attempts + 1
        WHERE status = 'processing'
        AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $reset = $stmt->rowCount();
    if ($reset > 0) {
        echo "[INFO] Reset $reset stuck jobs" . PHP_EOL;
    }
} catch (PDOException $e) {
    echo "[WARNING] Failed to reset stuck jobs: " . $e->getMessage() . PHP_EOL;
}

// 8. Mark exceeded retry jobs as failed
try {
    $stmt = $pdo->query("
        UPDATE agent_job_queue
        SET status = 'failed',
            error_message = 'Max retry attempts exceeded',
            completed_at = NOW()
        WHERE status = 'pending'
        AND attempts >= max_attempts
    ");
    $failed = $stmt->rowCount();
    if ($failed > 0) {
        echo "[INFO] Marked $failed jobs as failed (max retries exceeded)" . PHP_EOL;
    }
} catch (PDOException $e) {
    echo "[WARNING] Failed to mark exceeded jobs: " . $e->getMessage() . PHP_EOL;
}

// 9. Clean temp files older than 24 hours
$tempDir = __DIR__ . '/../temp';
if (is_dir($tempDir)) {
    $tempFiles = 0;
    $files = glob($tempDir . '/*');
    $cutoff = time() - (24 * 60 * 60);

    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            unlink($file);
            $tempFiles++;
        }
    }

    if ($tempFiles > 0) {
        echo "[INFO] Deleted $tempFiles old temp files" . PHP_EOL;
    }
}

// 10. Rotate large log files (> 10MB)
$logDir = __DIR__ . '/../logs';
if (is_dir($logDir)) {
    $logFiles = glob($logDir . '/*.log');
    $maxSize = 10 * 1024 * 1024; // 10MB

    foreach ($logFiles as $logFile) {
        if (filesize($logFile) > $maxSize) {
            $rotatedFile = $logFile . '.' . date('Y-m-d-His');
            rename($logFile, $rotatedFile);

            // Compress rotated file
            if (function_exists('gzopen')) {
                $gz = gzopen($rotatedFile . '.gz', 'w9');
                gzwrite($gz, file_get_contents($rotatedFile));
                gzclose($gz);
                unlink($rotatedFile);
                echo "[INFO] Rotated and compressed: " . basename($logFile) . PHP_EOL;
            } else {
                echo "[INFO] Rotated: " . basename($logFile) . PHP_EOL;
            }
        }
    }
}

// Summary
echo "[" . date('Y-m-d H:i:s') . "] Cleanup complete. Total records deleted: $totalDeleted" . PHP_EOL;

// Log to agent_logs
try {
    $stmt = $pdo->prepare("
        INSERT INTO agent_logs (agent_type, log_level, message, context, created_at)
        VALUES ('cleanup', 'info', 'Daily cleanup completed', ?, NOW())
    ");
    $stmt->execute([json_encode(['records_deleted' => $totalDeleted])]);
} catch (PDOException $e) {
    // Ignore logging errors
}
