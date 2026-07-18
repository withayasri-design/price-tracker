<?php

/**
 * Logging Helper Functions
 *
 * Provides global logging functions for easy access throughout the application.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/Logger.php';

use Core\Logger;

/**
 * Log a message to the specified channel.
 *
 * @param string $level Log level (debug, info, notice, warning, error, critical, alert, emergency)
 * @param string $message Log message
 * @param array $context Additional context data
 * @param string $channel Log channel (default: 'app')
 */
function app_log(string $level, string $message, array $context = [], string $channel = 'app'): void
{
    Logger::channel($channel)->log($level, $message, $context);
}

/**
 * Log a debug message.
 */
function log_debug(string $message, array $context = [], string $channel = 'app'): void
{
    Logger::channel($channel)->debug($message, $context);
}

/**
 * Log an info message.
 */
function log_info(string $message, array $context = [], string $channel = 'app'): void
{
    Logger::channel($channel)->info($message, $context);
}

/**
 * Log a warning message.
 */
function log_warning(string $message, array $context = [], string $channel = 'app'): void
{
    Logger::channel($channel)->warning($message, $context);
}

/**
 * Log an error message.
 */
function log_error(string $message, array $context = [], string $channel = 'app'): void
{
    Logger::channel($channel)->error($message, $context);
}

/**
 * Log an exception with stack trace.
 */
function log_exception(\Throwable $e, string $message = '', string $channel = 'app'): void
{
    Logger::channel($channel)->exception($e, $message);
}

/**
 * Get a logger instance for a specific channel.
 */
function logger(string $channel = 'app'): Logger
{
    return Logger::channel($channel);
}
