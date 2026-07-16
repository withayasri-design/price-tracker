<?php

/**
 * Base Adapter
 *
 * Provides common HTTP fetching and parsing functionality
 * for all platform adapters.
 */

declare(strict_types=1);

namespace Modules\Scraping;

abstract class BaseAdapter implements PlatformAdapterInterface
{
    protected int $timeout = 30;
    protected string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    protected int $rateLimit = 15;

    /**
     * Fetch HTML content from URL.
     *
     * @param string $url
     * @return string HTML content
     * @throws ScrapingException
     */
    protected function fetchHtml(string $url): string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: th-TH,th;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control: no-cache',
                'Connection: keep-alive',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => 'gzip, deflate',
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($html === false) {
            throw ScrapingException::networkError($url, $error);
        }

        if ($httpCode === 404) {
            throw ScrapingException::notFound($url);
        }

        if ($httpCode === 429) {
            throw ScrapingException::rateLimited($url);
        }

        if ($httpCode === 403 || $httpCode === 503) {
            throw ScrapingException::blocked($url, $httpCode);
        }

        if ($httpCode >= 400) {
            throw new ScrapingException(
                "HTTP error {$httpCode} fetching {$url}",
                ScrapingException::ERROR_NETWORK,
                $url,
                $httpCode
            );
        }

        return $html;
    }

    /**
     * Extract text content using regex.
     *
     * @param string $html
     * @param string $pattern Regex pattern with capture group
     * @param int $group Group number to return
     * @return string|null
     */
    protected function extractByRegex(string $html, string $pattern, int $group = 1): ?string
    {
        if (preg_match($pattern, $html, $matches)) {
            return trim($matches[$group] ?? '');
        }
        return null;
    }

    /**
     * Extract multiple matches using regex.
     *
     * @param string $html
     * @param string $pattern
     * @return array
     */
    protected function extractAllByRegex(string $html, string $pattern): array
    {
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            return $matches;
        }
        return [];
    }

    /**
     * Parse price string to float.
     *
     * @param string|null $priceStr
     * @return float|null
     */
    protected function parsePrice(?string $priceStr): ?float
    {
        if ($priceStr === null || $priceStr === '') {
            return null;
        }

        // Remove currency symbols and formatting
        $cleaned = preg_replace('/[^\d.,]/', '', $priceStr);

        // Handle Thai/international number formats
        // Remove thousand separators (commas before the last period)
        $cleaned = preg_replace('/,(?=\d{3}(?:[.,]|$))/', '', $cleaned);

        // Convert to standard decimal
        $cleaned = str_replace(',', '.', $cleaned);

        // Remove duplicate periods (keep last one)
        if (substr_count($cleaned, '.') > 1) {
            $parts = explode('.', $cleaned);
            $decimal = array_pop($parts);
            $cleaned = implode('', $parts) . '.' . $decimal;
        }

        $value = (float) $cleaned;
        return $value > 0 ? $value : null;
    }

    /**
     * Clean HTML entities and extra whitespace.
     *
     * @param string|null $text
     * @return string|null
     */
    protected function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Get rate limit for this adapter.
     */
    public function getRateLimit(): int
    {
        return $this->rateLimit;
    }
}
