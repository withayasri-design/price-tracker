<?php

/**
 * File Logger
 *
 * Handles logging to files with support for different log levels,
 * daily rotation, and structured log format.
 */

declare(strict_types=1);

namespace Core;

class Logger
{
    private const LOG_DIR = __DIR__ . '/../logs';

    // Log levels (PSR-3 compatible)
    public const EMERGENCY = 'emergency';
    public const ALERT = 'alert';
    public const CRITICAL = 'critical';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';
    public const INFO = 'info';
    public const DEBUG = 'debug';

    private static array $levelPriority = [
        self::EMERGENCY => 0,
        self::ALERT => 1,
        self::CRITICAL => 2,
        self::ERROR => 3,
        self::WARNING => 4,
        self::NOTICE => 5,
        self::INFO => 6,
        self::DEBUG => 7,
    ];

    private string $channel;
    private string $minLevel;
    private static array $instances = [];

    /**
     * Create a new logger instance.
     *
     * @param string $channel Log channel name (e.g., 'app', 'scraper', 'auth')
     * @param string $minLevel Minimum log level to record
     */
    public function __construct(string $channel = 'app', string $minLevel = self::DEBUG)
    {
        $this->channel = $channel;
        $this->minLevel = $minLevel;
        $this->ensureLogDirectory();
    }

    /**
     * Get a singleton logger instance for a channel.
     */
    public static function channel(string $channel = 'app'): self
    {
        if (!isset(self::$instances[$channel])) {
            self::$instances[$channel] = new self($channel);
        }
        return self::$instances[$channel];
    }

    /**
     * Log an emergency message.
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    /**
     * Log an alert message.
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    /**
     * Log a critical message.
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Log an error message.
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Log a warning message.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Log a notice message.
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    /**
     * Log an info message.
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * Log a debug message.
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Log a message with the specified level.
     */
    public function log(string $level, string $message, array $context = []): void
    {
        // Check if level should be logged
        if (!$this->shouldLog($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);

        // Interpolate context into message
        $message = $this->interpolate($message, $context);

        // Format log entry
        $logEntry = sprintf(
            "[%s] [%s] [%s] %s",
            $timestamp,
            $levelUpper,
            $this->channel,
            $message
        );

        // Add context if present (excluding interpolated keys)
        if (!empty($context)) {
            $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $logEntry .= " | Context: {$contextJson}";
        }

        $logEntry .= PHP_EOL;

        // Write to daily log file
        $this->write($logEntry, $level);
    }

    /**
     * Log an exception with stack trace.
     */
    public function exception(\Throwable $e, string $message = '', array $context = []): void
    {
        $context['exception'] = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        $fullMessage = $message ?: $e->getMessage();
        $fullMessage .= "\nStack trace:\n" . $e->getTraceAsString();

        $this->error($fullMessage, $context);
    }

    /**
     * Check if a level should be logged based on minimum level.
     */
    private function shouldLog(string $level): bool
    {
        $levelPriority = self::$levelPriority[$level] ?? 7;
        $minPriority = self::$levelPriority[$this->minLevel] ?? 7;
        return $levelPriority <= $minPriority;
    }

    /**
     * Interpolate context values into message placeholders.
     */
    private function interpolate(string $message, array &$context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_string($val) || is_numeric($val)) {
                $replace['{' . $key . '}'] = $val;
                unset($context[$key]); // Remove interpolated keys
            }
        }
        return strtr($message, $replace);
    }

    /**
     * Write log entry to file.
     */
    private function write(string $entry, string $level): void
    {
        // Daily log file
        $date = date('Y-m-d');
        $filename = self::LOG_DIR . "/{$this->channel}-{$date}.log";

        // Also write to error log for critical levels
        if (in_array($level, [self::EMERGENCY, self::ALERT, self::CRITICAL, self::ERROR])) {
            $errorFilename = self::LOG_DIR . "/error-{$date}.log";
            file_put_contents($errorFilename, $entry, FILE_APPEND | LOCK_EX);
        }

        file_put_contents($filename, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Ensure log directory exists.
     */
    private function ensureLogDirectory(): void
    {
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0755, true);
        }
    }

    /**
     * Get all log files.
     */
    public static function getLogFiles(): array
    {
        $files = glob(self::LOG_DIR . '/*.log');
        $result = [];

        foreach ($files as $file) {
            $result[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'modified' => filemtime($file),
            ];
        }

        // Sort by modified date descending
        usort($result, fn($a, $b) => $b['modified'] - $a['modified']);

        return $result;
    }

    /**
     * Read log file contents.
     */
    public static function readLog(string $filename, int $lines = 100): array
    {
        $filepath = self::LOG_DIR . '/' . basename($filename);

        if (!file_exists($filepath)) {
            return [];
        }

        $file = new \SplFileObject($filepath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $lines);
        $result = [];

        $file->seek($startLine);
        while (!$file->eof()) {
            $line = $file->fgets();
            if (trim($line) !== '') {
                $result[] = $line;
            }
        }

        return $result;
    }

    /**
     * Delete old log files (older than specified days).
     */
    public static function cleanup(int $days = 30): int
    {
        $files = glob(self::LOG_DIR . '/*.log');
        $cutoff = time() - ($days * 86400);
        $deleted = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Get log directory path.
     */
    public static function getLogDir(): string
    {
        return self::LOG_DIR;
    }
}
