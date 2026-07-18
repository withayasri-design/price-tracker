<?php
/**
 * Price Tracker Installation Script
 *
 * Run via CLI: php install.php
 * Or via browser: http://localhost/price-tracker/install.php
 */

declare(strict_types=1);

// Prevent re-installation if already configured
$envFile = __DIR__ . '/.env';
$lockFile = __DIR__ . '/.installed';

// CLI or Web mode
$isCli = php_sapi_name() === 'cli';

// Helper functions
function output(string $message, string $type = 'info'): void {
    global $isCli;

    $colors = [
        'info' => "\033[36m",    // Cyan
        'success' => "\033[32m", // Green
        'warning' => "\033[33m", // Yellow
        'error' => "\033[31m",   // Red
        'reset' => "\033[0m",
    ];

    if ($isCli) {
        $prefix = match($type) {
            'success' => '[✓] ',
            'warning' => '[!] ',
            'error' => '[✗] ',
            default => '[i] ',
        };
        echo $colors[$type] . $prefix . $message . $colors['reset'] . PHP_EOL;
    } else {
        $class = match($type) {
            'success' => 'text-success',
            'warning' => 'text-warning',
            'error' => 'text-danger',
            default => 'text-info',
        };
        echo "<p class=\"$class\">$message</p>";
    }
}

function prompt(string $question, string $default = ''): string {
    global $isCli;

    if (!$isCli) {
        return $default;
    }

    $defaultText = $default ? " [$default]" : '';
    echo "\033[33m$question$defaultText: \033[0m";
    $input = trim(fgets(STDIN));

    return $input ?: $default;
}

function checkRequirement(string $name, bool $passed, string $message = ''): bool {
    if ($passed) {
        output("$name: OK", 'success');
    } else {
        output("$name: FAILED" . ($message ? " - $message" : ''), 'error');
    }
    return $passed;
}

// Start installation
if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Price Tracker Installation</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '</head><body class="bg-light"><div class="container py-5"><div class="card"><div class="card-body">';
    echo '<h1 class="card-title mb-4">🛒 Price Tracker Installation</h1>';
}

output("=== Price Tracker Installation ===", 'info');
output("", 'info');

// Check if already installed
if (file_exists($lockFile)) {
    output("Price Tracker is already installed!", 'warning');
    output("To reinstall, delete the .installed file first.", 'info');
    if (!$isCli) {
        echo '</div></div></div></body></html>';
    }
    exit(0);
}

// Step 1: Check PHP Requirements
output("Step 1: Checking PHP Requirements...", 'info');
$requirementsPassed = true;

$requirementsPassed &= checkRequirement(
    'PHP Version (8.2+)',
    version_compare(PHP_VERSION, '8.2.0', '>='),
    'PHP ' . PHP_VERSION . ' detected'
);

$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'curl', 'mbstring', 'openssl'];
foreach ($requiredExtensions as $ext) {
    $requirementsPassed &= checkRequirement(
        "Extension: $ext",
        extension_loaded($ext)
    );
}

$requirementsPassed &= checkRequirement(
    'Composer autoload',
    file_exists(__DIR__ . '/vendor/autoload.php'),
    'Run: composer install'
);

if (!$requirementsPassed) {
    output("", 'info');
    output("Please fix the above requirements and run again.", 'error');
    if (!$isCli) {
        echo '</div></div></div></body></html>';
    }
    exit(1);
}

output("", 'info');

// Step 2: Database Configuration
output("Step 2: Database Configuration...", 'info');

if ($isCli) {
    $dbHost = prompt('Database Host', 'localhost');
    $dbName = prompt('Database Name', 'price_tracker');
    $dbUser = prompt('Database User', 'root');
    $dbPass = prompt('Database Password', '');
} else {
    // For web, check if form was submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $dbHost = $_POST['db_host'] ?? 'localhost';
        $dbName = $_POST['db_name'] ?? 'price_tracker';
        $dbUser = $_POST['db_user'] ?? 'root';
        $dbPass = $_POST['db_pass'] ?? '';
    } else {
        // Show configuration form
        echo '<form method="POST" class="mt-4">';
        echo '<h5>Database Configuration</h5>';
        echo '<div class="mb-3"><label class="form-label">Database Host</label>';
        echo '<input type="text" name="db_host" class="form-control" value="localhost" required></div>';
        echo '<div class="mb-3"><label class="form-label">Database Name</label>';
        echo '<input type="text" name="db_name" class="form-control" value="price_tracker" required></div>';
        echo '<div class="mb-3"><label class="form-label">Database User</label>';
        echo '<input type="text" name="db_user" class="form-control" value="root" required></div>';
        echo '<div class="mb-3"><label class="form-label">Database Password</label>';
        echo '<input type="password" name="db_pass" class="form-control"></div>';
        echo '<h5 class="mt-4">Admin Account</h5>';
        echo '<div class="mb-3"><label class="form-label">Admin Email</label>';
        echo '<input type="email" name="admin_email" class="form-control" required></div>';
        echo '<div class="mb-3"><label class="form-label">Admin Password</label>';
        echo '<input type="password" name="admin_pass" class="form-control" required minlength="8"></div>';
        echo '<button type="submit" class="btn btn-primary">Install</button>';
        echo '</form></div></div></div></body></html>';
        exit(0);
    }
}

// Test database connection
output("Testing database connection...", 'info');

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    output("Database connection successful!", 'success');
} catch (PDOException $e) {
    output("Database connection failed: " . $e->getMessage(), 'error');
    if (!$isCli) {
        echo '</div></div></div></body></html>';
    }
    exit(1);
}

// Create database if not exists
output("Creating database '$dbName' if not exists...", 'info');
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");
    output("Database ready!", 'success');
} catch (PDOException $e) {
    output("Failed to create database: " . $e->getMessage(), 'error');
    exit(1);
}

// Step 3: Run Schema
output("", 'info');
output("Step 3: Creating Database Tables...", 'info');

$schemaFile = __DIR__ . '/database/schema.sql';
if (!file_exists($schemaFile)) {
    output("Schema file not found: $schemaFile", 'error');
    exit(1);
}

$schema = file_get_contents($schemaFile);
// Remove comments and split by semicolon
$schema = preg_replace('/--.*$/m', '', $schema);
$statements = array_filter(array_map('trim', explode(';', $schema)));

$tableCount = 0;
foreach ($statements as $statement) {
    if (empty($statement)) continue;

    try {
        $pdo->exec($statement);
        if (stripos($statement, 'CREATE TABLE') !== false) {
            $tableCount++;
        }
    } catch (PDOException $e) {
        // Ignore "table already exists" errors
        if ($e->getCode() !== '42S01') {
            output("SQL Error: " . $e->getMessage(), 'warning');
        }
    }
}

output("Created/verified $tableCount tables", 'success');

// Step 4: Create Admin User
output("", 'info');
output("Step 4: Creating Admin User...", 'info');

if ($isCli) {
    $adminEmail = prompt('Admin Email', 'admin@pricetracker.local');
    $adminPass = prompt('Admin Password (min 8 chars)', 'admin123');
} else {
    $adminEmail = $_POST['admin_email'] ?? 'admin@pricetracker.local';
    $adminPass = $_POST['admin_pass'] ?? 'admin123';
}

$passwordHash = password_hash($adminPass, PASSWORD_DEFAULT);

try {
    // Check if admin exists
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->execute([$adminEmail]);

    if ($stmt->fetch()) {
        output("Admin user already exists, skipping...", 'warning');
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, full_name, role, is_active, notify_email, created_at)
            VALUES (?, ?, 'Administrator', 'admin', 1, 1, NOW())
        ");
        $stmt->execute([$adminEmail, $passwordHash]);
        output("Admin user created: $adminEmail", 'success');
    }
} catch (PDOException $e) {
    output("Failed to create admin user: " . $e->getMessage(), 'error');
}

// Step 5: Create .env file
output("", 'info');
output("Step 5: Creating Configuration File...", 'info');

$envContent = <<<ENV
# Price Tracker Configuration
# Generated by install.php on {date('Y-m-d H:i:s')}

# Database
DB_HOST=$dbHost
DB_NAME=$dbName
DB_USER=$dbUser
DB_PASS=$dbPass

# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost/price-tracker

# Email (SMTP) - Configure these for email notifications
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_FROM_EMAIL=noreply@pricetracker.local
SMTP_FROM_NAME=Price Tracker

# LINE Messaging API - Configure in admin panel
LINE_CHANNEL_ACCESS_TOKEN=
LINE_CHANNEL_SECRET=

# Security
SESSION_LIFETIME=7200
CSRF_TOKEN_LIFETIME=3600
ENV;

// Use heredoc properly
$envContent = "# Price Tracker Configuration
# Generated by install.php on " . date('Y-m-d H:i:s') . "

# Database
DB_HOST=$dbHost
DB_NAME=$dbName
DB_USER=$dbUser
DB_PASS=$dbPass

# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost/price-tracker

# Email (SMTP) - Configure these for email notifications
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_FROM_EMAIL=noreply@pricetracker.local
SMTP_FROM_NAME=Price Tracker

# LINE Messaging API - Configure in admin panel
LINE_CHANNEL_ACCESS_TOKEN=
LINE_CHANNEL_SECRET=

# Security
SESSION_LIFETIME=7200
CSRF_TOKEN_LIFETIME=3600
";

if (file_put_contents($envFile, $envContent)) {
    output(".env file created!", 'success');
} else {
    output("Failed to create .env file - please create it manually", 'warning');
}

// Step 6: Insert default settings
output("", 'info');
output("Step 6: Inserting Default Settings...", 'info');

$defaultSettings = [
    ['cron_interval_minutes', '180'],
    ['rate_limit_per_minute_jib', '15'],
    ['rate_limit_per_minute_banana', '15'],
    ['rate_limit_per_minute_advice', '15'],
    ['rate_limit_per_minute_globalhouse', '12'],
    ['rate_limit_per_minute_homepro', '12'],
    ['rate_limit_per_minute_thaiwatsadu', '12'],
    ['rate_limit_per_minute_powerbuy', '12'],
    ['agent_scraper_batch_size', '10'],
    ['agent_cleaning_similarity_threshold', '0.7'],
    ['agent_pricediff_significant_change_percent', '5'],
    ['agent_dispatch_batch_delay_seconds', '60'],
];

$stmt = $pdo->prepare("
    INSERT INTO system_settings (setting_key, setting_value, updated_at)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
");

foreach ($defaultSettings as [$key, $value]) {
    $stmt->execute([$key, $value]);
}

output("Default settings inserted!", 'success');

// Step 7: Set file permissions (if on Unix)
if (PHP_OS_FAMILY !== 'Windows') {
    output("", 'info');
    output("Step 7: Setting File Permissions...", 'info');

    $writableDirs = ['logs', 'cache', 'uploads', 'temp'];
    foreach ($writableDirs as $dir) {
        $path = __DIR__ . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        chmod($path, 0755);
    }

    chmod($envFile, 0600);
    output("Permissions set!", 'success');
}

// Step 8: Create lock file
file_put_contents($lockFile, date('Y-m-d H:i:s'));

// Done!
output("", 'info');
output("==========================================", 'success');
output("  Installation Complete!", 'success');
output("==========================================", 'success');
output("", 'info');
output("Next steps:", 'info');
output("1. Configure email settings in .env or admin panel", 'info');
output("2. Set up LINE API credentials in admin panel", 'info');
output("3. Add cron jobs for scheduled scraping:", 'info');
output("   * * * * * php " . __DIR__ . "/cron/run_agent_queue.php", 'info');
output("   */30 * * * * php " . __DIR__ . "/cron/run_scheduled_scrape.php", 'info');
output("", 'info');
output("Login at: http://localhost/price-tracker/pages/login.php", 'info');
output("Email: $adminEmail", 'info');
output("", 'info');
output("For security, delete install.php after installation!", 'warning');

if (!$isCli) {
    echo '<div class="alert alert-success mt-4">';
    echo '<h5>Installation Complete!</h5>';
    echo '<p><a href="pages/login.php" class="btn btn-primary">Go to Login</a></p>';
    echo '</div>';
    echo '</div></div></div></body></html>';
}
