<?php

/**
 * URL Parser
 *
 * Parses product URLs from various e-commerce platforms
 * and extracts platform type and product ID.
 */

declare(strict_types=1);

namespace Modules\Scraping;

class UrlParser
{
    /**
     * Platform URL patterns.
     * Each pattern should capture the product ID in group 1.
     */
    private const PATTERNS = [
        'shopee' => [
            // https://shopee.co.th/product/123456/789012
            '#shopee\.co\.th/.*?(\d+)/(\d+)#',
            // https://shopee.co.th/Product-Name-i.123456.789012
            '#shopee\.co\.th/.*-i\.(\d+)\.(\d+)#',
        ],
        'lazada' => [
            // https://www.lazada.co.th/products/product-name-i123456789-s987654321.html
            '#lazada\.co\.th/products/.*-i(\d+)-s(\d+)\.html#',
            // https://www.lazada.co.th/products/product-name-i123456789.html
            '#lazada\.co\.th/products/.*-i(\d+)\.html#',
        ],
        'tiktok' => [
            // https://www.tiktok.com/view/product/1234567890123456789
            '#tiktok\.com/view/product/(\d+)#',
            // https://shop.tiktok.com/view/product/1234567890
            '#shop\.tiktok\.com/view/product/(\d+)#',
        ],
        'jib' => [
            // https://www.jib.co.th/web/product/readProduct/12345
            '#jib\.co\.th/web/product/readProduct/(\d+)#',
            // https://www.jib.co.th/web/product/readProduct/12345/product-name
            '#jib\.co\.th/web/product/readProduct/(\d+)/.*#',
        ],
        'banana' => [
            // https://www.bananait.co.th/product/12345
            '#bananait\.co\.th/product/(\d+)#',
            // https://www.bananait.co.th/product/12345/product-name
            '#bananait\.co\.th/product/(\d+)/.*#',
        ],
        'advice' => [
            // https://www.advice.co.th/product/12345
            '#advice\.co\.th/product/(\d+)#',
            // https://www.advice.co.th/product/product-name-12345
            '#advice\.co\.th/product/.*-(\d+)$#',
        ],
        'globalhouse' => [
            // https://www.globalhouse.co.th/product/12345
            '#globalhouse\.co\.th/product/(\d+)#',
            // https://www.globalhouse.co.th/product/12345/product-name
            '#globalhouse\.co\.th/product/(\d+)/.*#',
        ],
        'homepro' => [
            // https://www.homepro.co.th/p/12345
            '#homepro\.co\.th/p/(\d+)#',
            // https://www.homepro.co.th/product/12345
            '#homepro\.co\.th/product/(\d+)#',
        ],
        'thaiwatsadu' => [
            // https://www.thaiwatsadu.com/th/product/12345
            '#thaiwatsadu\.com/th/product/(\d+)#',
            // https://www.thaiwatsadu.com/product/12345
            '#thaiwatsadu\.com/product/(\d+)#',
        ],
        'powerbuy' => [
            // https://www.powerbuy.co.th/th/product/12345
            '#powerbuy\.co\.th/th/product/(\d+)#',
            // https://www.powerbuy.co.th/product/12345
            '#powerbuy\.co\.th/product/(\d+)#',
        ],
    ];

    /**
     * Parse a product URL.
     *
     * @param string $url Product URL
     * @return array|null ['platform' => string, 'product_id' => string, 'url' => string] or null if not recognized
     */
    public function parse(string $url): ?array
    {
        $url = trim($url);

        if (empty($url)) {
            return null;
        }

        // Normalize URL
        $url = $this->normalizeUrl($url);

        foreach (self::PATTERNS as $platform => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $url, $matches)) {
                    $productId = $this->extractProductId($platform, $matches);
                    if ($productId) {
                        return [
                            'platform' => $platform,
                            'product_id' => $productId,
                            'url' => $url,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if a URL is supported.
     *
     * @param string $url
     * @return bool
     */
    public function isSupported(string $url): bool
    {
        return $this->parse($url) !== null;
    }

    /**
     * Get the platform from a URL without full parsing.
     *
     * @param string $url
     * @return string|null Platform name or null
     */
    public function detectPlatform(string $url): ?string
    {
        $url = strtolower($url);

        $platformDomains = [
            'shopee' => ['shopee.co.th'],
            'lazada' => ['lazada.co.th'],
            'tiktok' => ['tiktok.com', 'shop.tiktok.com'],
            'jib' => ['jib.co.th'],
            'banana' => ['bananait.co.th'],
            'advice' => ['advice.co.th'],
            'globalhouse' => ['globalhouse.co.th'],
            'homepro' => ['homepro.co.th'],
            'thaiwatsadu' => ['thaiwatsadu.com'],
            'powerbuy' => ['powerbuy.co.th'],
        ];

        foreach ($platformDomains as $platform => $domains) {
            foreach ($domains as $domain) {
                if (str_contains($url, $domain)) {
                    return $platform;
                }
            }
        }

        return null;
    }

    /**
     * Get list of supported platforms.
     *
     * @return array
     */
    public function getSupportedPlatforms(): array
    {
        return array_keys(self::PATTERNS);
    }

    /**
     * Normalize a URL for parsing.
     *
     * @param string $url
     * @return string
     */
    private function normalizeUrl(string $url): string
    {
        // Add https if no protocol
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        // Remove tracking parameters
        $url = preg_replace('/[?&](utm_[^&]+|ref[^&]*|fbclid[^&]*)/', '', $url);

        // Clean up any trailing ? or &
        $url = rtrim($url, '?&');

        return $url;
    }

    /**
     * Extract product ID from regex matches.
     *
     * @param string $platform
     * @param array $matches
     * @return string|null
     */
    private function extractProductId(string $platform, array $matches): ?string
    {
        // For platforms with shop_id.product_id format (Shopee, Lazada)
        if (in_array($platform, ['shopee', 'lazada'])) {
            if (isset($matches[2])) {
                // Return combined ID for uniqueness
                return $matches[1] . '_' . $matches[2];
            }
            return $matches[1] ?? null;
        }

        // For other platforms, just return the first captured group
        return $matches[1] ?? null;
    }

    /**
     * Build a canonical URL for a product.
     *
     * @param string $platform
     * @param string $productId
     * @return string|null
     */
    public function buildCanonicalUrl(string $platform, string $productId): ?string
    {
        $templates = [
            'jib' => 'https://www.jib.co.th/web/product/readProduct/%s',
            'banana' => 'https://www.bananait.co.th/product/%s',
            'advice' => 'https://www.advice.co.th/product/%s',
            'globalhouse' => 'https://www.globalhouse.co.th/product/%s',
            'homepro' => 'https://www.homepro.co.th/p/%s',
            'thaiwatsadu' => 'https://www.thaiwatsadu.com/th/product/%s',
            'powerbuy' => 'https://www.powerbuy.co.th/th/product/%s',
        ];

        // Shopee and Lazada have complex URL structures
        if (in_array($platform, ['shopee', 'lazada', 'tiktok'])) {
            return null; // Can't build canonical URL for these
        }

        if (!isset($templates[$platform])) {
            return null;
        }

        return sprintf($templates[$platform], $productId);
    }
}
