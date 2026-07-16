<?php

/**
 * Scraping Exception
 *
 * Exception thrown when scraping fails.
 */

declare(strict_types=1);

namespace Modules\Scraping;

class ScrapingException extends \Exception
{
    public const ERROR_NETWORK = 'network';
    public const ERROR_PARSE = 'parse';
    public const ERROR_NOT_FOUND = 'not_found';
    public const ERROR_BLOCKED = 'blocked';
    public const ERROR_RATE_LIMIT = 'rate_limit';
    public const ERROR_UNKNOWN = 'unknown';

    private string $errorType;
    private ?string $url;
    private ?int $httpCode;

    public function __construct(
        string $message,
        string $errorType = self::ERROR_UNKNOWN,
        ?string $url = null,
        ?int $httpCode = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorType = $errorType;
        $this->url = $url;
        $this->httpCode = $httpCode;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getHttpCode(): ?int
    {
        return $this->httpCode;
    }

    /**
     * Check if this error is retryable.
     */
    public function isRetryable(): bool
    {
        return in_array($this->errorType, [
            self::ERROR_NETWORK,
            self::ERROR_RATE_LIMIT,
        ]);
    }

    /**
     * Create network error exception.
     */
    public static function networkError(string $url, string $details = ''): self
    {
        return new self(
            "Network error fetching {$url}" . ($details ? ": {$details}" : ''),
            self::ERROR_NETWORK,
            $url
        );
    }

    /**
     * Create parse error exception.
     */
    public static function parseError(string $url, string $field): self
    {
        return new self(
            "Failed to parse {$field} from {$url}",
            self::ERROR_PARSE,
            $url
        );
    }

    /**
     * Create not found exception.
     */
    public static function notFound(string $url): self
    {
        return new self(
            "Product not found at {$url}",
            self::ERROR_NOT_FOUND,
            $url,
            404
        );
    }

    /**
     * Create blocked exception.
     */
    public static function blocked(string $url, int $httpCode = 403): self
    {
        return new self(
            "Request blocked by {$url}",
            self::ERROR_BLOCKED,
            $url,
            $httpCode
        );
    }

    /**
     * Create rate limit exception.
     */
    public static function rateLimited(string $url): self
    {
        return new self(
            "Rate limited by {$url}",
            self::ERROR_RATE_LIMIT,
            $url,
            429
        );
    }
}
