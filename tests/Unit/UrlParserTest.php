<?php
/**
 * Unit tests for UrlParser
 */

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UrlParserTest extends TestCase
{
    private array $urlPatterns = [
        'jib' => [
            'pattern' => '/jib\.co\.th\/web\/product\/readProduct\/(\d+)/i',
            'valid' => 'https://www.jib.co.th/web/product/readProduct/48622',
            'invalid' => 'https://www.jib.co.th/web/category/laptop',
            'expected_id' => '48622',
        ],
        'banana' => [
            'pattern' => '/bananait\.co\.th\/product\/([\w-]+)/i',
            'valid' => 'https://www.bananait.co.th/product/apple-macbook-air-m3',
            'invalid' => 'https://www.bananait.co.th/category/laptop',
            'expected_id' => 'apple-macbook-air-m3',
        ],
        'advice' => [
            'pattern' => '/advice\.co\.th\/product\/([\w-]+)/i',
            'valid' => 'https://www.advice.co.th/product/samsung-galaxy-s24',
            'invalid' => 'https://www.advice.co.th/promotion',
            'expected_id' => 'samsung-galaxy-s24',
        ],
        'powerbuy' => [
            'pattern' => '/powerbuy\.co\.th\/.*\/product\/([\w-]+)/i',
            'valid' => 'https://www.powerbuy.co.th/th/product/sony-playstation-5',
            'invalid' => 'https://www.powerbuy.co.th/th/category/gaming',
            'expected_id' => 'sony-playstation-5',
        ],
        'globalhouse' => [
            'pattern' => '/globalhouse\.co\.th\/product\/([\w-]+)/i',
            'valid' => 'https://www.globalhouse.co.th/product/makita-drill-18v',
            'invalid' => 'https://www.globalhouse.co.th/brands/makita',
            'expected_id' => 'makita-drill-18v',
        ],
        'homepro' => [
            'pattern' => '/homepro\.co\.th\/p\/([\w-]+)/i',
            'valid' => 'https://www.homepro.co.th/p/bosch-impact-driver',
            'invalid' => 'https://www.homepro.co.th/c/power-tools',
            'expected_id' => 'bosch-impact-driver',
        ],
        'thaiwatsadu' => [
            'pattern' => '/thaiwatsadu\.com\/.*\/product\/([\w-]+)/i',
            'valid' => 'https://www.thaiwatsadu.com/th/product/toa-paint-5gal',
            'invalid' => 'https://www.thaiwatsadu.com/th/category/paint',
            'expected_id' => 'toa-paint-5gal',
        ],
    ];

    /**
     * @dataProvider validUrlProvider
     */
    public function testValidUrlsAreRecognized(string $platform, string $url, string $expectedId): void
    {
        $pattern = $this->urlPatterns[$platform]['pattern'];

        $this->assertMatchesRegularExpression($pattern, $url);

        preg_match($pattern, $url, $matches);
        $this->assertEquals($expectedId, $matches[1]);
    }

    /**
     * @dataProvider invalidUrlProvider
     */
    public function testInvalidUrlsAreRejected(string $platform, string $url): void
    {
        $pattern = $this->urlPatterns[$platform]['pattern'];

        $this->assertDoesNotMatchRegularExpression($pattern, $url);
    }

    public function testDetectPlatformFromUrl(): void
    {
        $testCases = [
            'https://www.jib.co.th/web/product/readProduct/48622' => 'jib',
            'https://www.bananait.co.th/product/macbook' => 'banana',
            'https://www.advice.co.th/product/phone' => 'advice',
            'https://www.powerbuy.co.th/th/product/tv' => 'powerbuy',
            'https://www.globalhouse.co.th/product/drill' => 'globalhouse',
            'https://www.homepro.co.th/p/hammer' => 'homepro',
            'https://www.thaiwatsadu.com/th/product/cement' => 'thaiwatsadu',
            'https://www.amazon.com/product/123' => null,
        ];

        foreach ($testCases as $url => $expectedPlatform) {
            $detectedPlatform = $this->detectPlatform($url);
            $this->assertEquals($expectedPlatform, $detectedPlatform, "Failed for URL: $url");
        }
    }

    public function testNormalizeUrl(): void
    {
        $testCases = [
            // Remove tracking parameters
            'https://www.jib.co.th/web/product/readProduct/123?ref=homepage&utm_source=google'
                => 'https://www.jib.co.th/web/product/readProduct/123',

            // Handle www prefix
            'http://jib.co.th/web/product/readProduct/123'
                => 'https://www.jib.co.th/web/product/readProduct/123',

            // HTTPS upgrade
            'http://www.bananait.co.th/product/test'
                => 'https://www.bananait.co.th/product/test',
        ];

        foreach ($testCases as $input => $expected) {
            $normalized = $this->normalizeUrl($input);
            $this->assertEquals($expected, $normalized, "Failed for URL: $input");
        }
    }

    public static function validUrlProvider(): array
    {
        return [
            'jib' => ['jib', 'https://www.jib.co.th/web/product/readProduct/48622', '48622'],
            'banana' => ['banana', 'https://www.bananait.co.th/product/apple-macbook-air-m3', 'apple-macbook-air-m3'],
            'advice' => ['advice', 'https://www.advice.co.th/product/samsung-galaxy-s24', 'samsung-galaxy-s24'],
            'powerbuy' => ['powerbuy', 'https://www.powerbuy.co.th/th/product/sony-ps5', 'sony-ps5'],
            'globalhouse' => ['globalhouse', 'https://www.globalhouse.co.th/product/makita-drill', 'makita-drill'],
            'homepro' => ['homepro', 'https://www.homepro.co.th/p/bosch-tool', 'bosch-tool'],
            'thaiwatsadu' => ['thaiwatsadu', 'https://www.thaiwatsadu.com/th/product/toa-paint', 'toa-paint'],
        ];
    }

    public static function invalidUrlProvider(): array
    {
        return [
            'jib_category' => ['jib', 'https://www.jib.co.th/web/category/laptop'],
            'banana_home' => ['banana', 'https://www.bananait.co.th/'],
            'advice_promo' => ['advice', 'https://www.advice.co.th/promotion'],
            'powerbuy_brand' => ['powerbuy', 'https://www.powerbuy.co.th/th/brand/sony'],
            'globalhouse_about' => ['globalhouse', 'https://www.globalhouse.co.th/about'],
            'homepro_category' => ['homepro', 'https://www.homepro.co.th/c/power-tools'],
            'thaiwatsadu_branch' => ['thaiwatsadu', 'https://www.thaiwatsadu.com/th/branches'],
        ];
    }

    /**
     * Helper: Detect platform from URL
     */
    private function detectPlatform(string $url): ?string
    {
        $platformDomains = [
            'jib.co.th' => 'jib',
            'bananait.co.th' => 'banana',
            'advice.co.th' => 'advice',
            'powerbuy.co.th' => 'powerbuy',
            'globalhouse.co.th' => 'globalhouse',
            'homepro.co.th' => 'homepro',
            'thaiwatsadu.com' => 'thaiwatsadu',
        ];

        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $host = preg_replace('/^www\./', '', $host);

        return $platformDomains[$host] ?? null;
    }

    /**
     * Helper: Normalize URL
     */
    private function normalizeUrl(string $url): string
    {
        // Parse URL
        $parts = parse_url($url);

        // Force HTTPS
        $scheme = 'https';

        // Ensure www prefix for Thai sites
        $host = $parts['host'] ?? '';
        if (!str_starts_with($host, 'www.')) {
            $host = 'www.' . $host;
        }

        // Keep only path, remove query parameters
        $path = $parts['path'] ?? '/';

        return "{$scheme}://{$host}{$path}";
    }
}
