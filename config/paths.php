<?php
/**
 * Path Configuration
 *
 * Determines the base URL path for the application.
 * Works whether installed in root or subdirectory.
 */

declare(strict_types=1);

// Determine base path from script location
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$basePath = '';

// Check if we're in a subdirectory
if (strpos($scriptPath, '/price-tracker') !== false) {
    $basePath = '/price-tracker';
} elseif ($scriptPath !== '/' && $scriptPath !== '\\') {
    // Extract the base directory
    $parts = explode('/', trim($scriptPath, '/'));
    if (!empty($parts[0]) && $parts[0] !== 'pages' && $parts[0] !== 'api' && $parts[0] !== 'admin') {
        $basePath = '/' . $parts[0];
    }
}

// Define constants
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('BASE_URL', $protocol . '://' . $host . BASE_PATH);
}

/**
 * Generate URL with base path
 */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return BASE_PATH . '/' . $path;
}

/**
 * Generate full URL with protocol and host
 */
function fullUrl(string $path = ''): string
{
    $path = ltrim($path, '/');
    return BASE_URL . '/' . $path;
}

/**
 * Redirect to a path within the application
 */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}
