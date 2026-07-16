<?php

/**
 * Platform Adapter Interface
 *
 * Contract for all e-commerce platform scrapers.
 * Each platform must implement this interface.
 */

declare(strict_types=1);

namespace Modules\Scraping;

interface PlatformAdapterInterface
{
    /**
     * Get the platform identifier.
     *
     * @return string Platform name (e.g., 'jib', 'shopee')
     */
    public function getPlatform(): string;

    /**
     * Check if this adapter can handle the given URL.
     *
     * @param string $url Product URL
     * @return bool
     */
    public function canHandle(string $url): bool;

    /**
     * Scrape product data from URL.
     *
     * @param string $url Product URL
     * @return ScrapedProduct Scraped product data
     * @throws ScrapingException On failure
     */
    public function scrape(string $url): ScrapedProduct;

    /**
     * Get rate limit (requests per minute).
     *
     * @return int
     */
    public function getRateLimit(): int;
}
