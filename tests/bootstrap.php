<?php
/**
 * PHPUnit Bootstrap
 *
 * Sets up autoloading and test environment
 */

declare(strict_types=1);

// Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Define test constants
define('PROJECT_ROOT', dirname(__DIR__));
define('TESTING', true);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load test environment variables if exists
$testEnvFile = PROJECT_ROOT . '/.env.testing';
if (file_exists($testEnvFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(PROJECT_ROOT, '.env.testing');
    $dotenv->load();
}
