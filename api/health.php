<?php
/**
 * Health Check Endpoint
 *
 * Returns system health status for monitoring and container orchestration.
 * GET /api/health.php
 *
 * Response codes:
 * - 200: All systems operational
 * - 503: One or more systems degraded
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Health check results
$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'version' => '1.0.0',
    'checks' => [],
];

$allHealthy = true;

// 1. PHP Check (always passes if we get here)
$health['checks']['php'] = [
    'status' => 'healthy',
    'version' => PHP_VERSION,
];

// 2. Database Check
try {
    // Try to load environment
    $envFile = __DIR__ . '/../.env';
    $dbConfig = [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'name' => getenv('DB_NAME') ?: 'price_tracker',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ];

    // Parse .env file if exists and env vars not set
    if (file_exists($envFile) && !getenv('DB_HOST')) {
        $envContent = file_get_contents($envFile);
        if (preg_match('/^DB_HOST=(.*)$/m', $envContent, $m)) $dbConfig['host'] = trim($m[1]);
        if (preg_match('/^DB_NAME=(.*)$/m', $envContent, $m)) $dbConfig['name'] = trim($m[1]);
        if (preg_match('/^DB_USER=(.*)$/m', $envContent, $m)) $dbConfig['user'] = trim($m[1]);
        if (preg_match('/^DB_PASS=(.*)$/m', $envContent, $m)) $dbConfig['pass'] = trim($m[1]);
    }

    $startTime = microtime(true);
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4",
        $dbConfig['user'],
        $dbConfig['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );

    // Quick query to verify connection
    $stmt = $pdo->query('SELECT 1');
    $stmt->fetch();

    $latency = round((microtime(true) - $startTime) * 1000, 2);

    $health['checks']['database'] = [
        'status' => 'healthy',
        'latency_ms' => $latency,
    ];
} catch (PDOException $e) {
    $allHealthy = false;
    $health['checks']['database'] = [
        'status' => 'unhealthy',
        'error' => 'Connection failed',
    ];
}

// 3. Filesystem Check
$writableDirs = ['logs', 'cache', 'uploads', 'temp'];
$filesystemHealthy = true;

foreach ($writableDirs as $dir) {
    $path = __DIR__ . '/../' . $dir;
    if (!is_dir($path) || !is_writable($path)) {
        $filesystemHealthy = false;
        break;
    }
}

if ($filesystemHealthy) {
    $health['checks']['filesystem'] = [
        'status' => 'healthy',
    ];
} else {
    // Try to create directories
    foreach ($writableDirs as $dir) {
        $path = __DIR__ . '/../' . $dir;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }

    // Recheck
    $filesystemHealthy = true;
    foreach ($writableDirs as $dir) {
        $path = __DIR__ . '/../' . $dir;
        if (!is_dir($path) || !is_writable($path)) {
            $filesystemHealthy = false;
            break;
        }
    }

    $health['checks']['filesystem'] = [
        'status' => $filesystemHealthy ? 'healthy' : 'degraded',
    ];

    if (!$filesystemHealthy) {
        $allHealthy = false;
    }
}

// 4. Required Extensions Check
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'curl', 'mbstring'];
$missingExtensions = [];

foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

if (empty($missingExtensions)) {
    $health['checks']['extensions'] = [
        'status' => 'healthy',
    ];
} else {
    $allHealthy = false;
    $health['checks']['extensions'] = [
        'status' => 'unhealthy',
        'missing' => $missingExtensions,
    ];
}

// 5. Memory Check
$memoryLimit = ini_get('memory_limit');
$memoryBytes = $memoryLimit === '-1' ? PHP_INT_MAX : (int) $memoryLimit * 1024 * 1024;
$memoryUsage = memory_get_usage(true);
$memoryPercent = $memoryBytes > 0 ? round(($memoryUsage / $memoryBytes) * 100, 1) : 0;

$health['checks']['memory'] = [
    'status' => $memoryPercent < 90 ? 'healthy' : 'degraded',
    'usage_percent' => $memoryPercent,
    'limit' => $memoryLimit,
];

if ($memoryPercent >= 90) {
    $allHealthy = false;
}

// 6. Agent Queue Check (optional)
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 1 ELSE 0 END) as stuck
            FROM agent_job_queue
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $queueStats = $stmt->fetch(PDO::FETCH_ASSOC);

        $queueHealthy = ($queueStats['stuck'] ?? 0) == 0;
        $health['checks']['agent_queue'] = [
            'status' => $queueHealthy ? 'healthy' : 'degraded',
            'jobs_24h' => (int) ($queueStats['total'] ?? 0),
            'failed_24h' => (int) ($queueStats['failed'] ?? 0),
            'stuck_jobs' => (int) ($queueStats['stuck'] ?? 0),
        ];

        if (!$queueHealthy) {
            $allHealthy = false;
        }
    } catch (PDOException $e) {
        // Queue table might not exist yet
        $health['checks']['agent_queue'] = [
            'status' => 'unknown',
        ];
    }
}

// Set overall status
if (!$allHealthy) {
    $health['status'] = 'degraded';
    http_response_code(503);
}

// Output
echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
