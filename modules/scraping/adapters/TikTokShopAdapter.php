<?php

/**
 * TikTok Shop Adapter
 *
 * Scraper for TikTok Shop - Growing e-commerce platform integrated with TikTok.
 *
 * IMPORTANT: TikTok Shop uses Cloudflare/security verification and heavy
 * JavaScript rendering. This adapter attempts to extract data but will
 * likely be blocked by anti-bot measures.
 *
 * For production use, consider:
 * - Headless browser (Puppeteer/Playwright)
 * - Official TikTok Shop API (requires partnership)
 *
 * URL patterns:
 *   - https://www.tiktok.com/view/product/1234567890123456789
 *   - https://shop.tiktok.com/view/product/1234567890123456789
 *   - https://shop-th.tiktok.com/view/product/1234567890123456789
 *   - https://vt.tiktok.com/XXXXXX/ (short URLs)
 */

declare(strict_types=1);

namespace Modules\Scraping\Adapters;

use Modules\Scraping\BaseAdapter;
use Modules\Scraping\ScrapedProduct;
use Modules\Scraping\ScrapingException;
use Modules\Scraping\HeadlessScraper;

class TikTokShopAdapter extends BaseAdapter
{
    protected int $rateLimit = 3; // Very low rate limit due to anti-bot
    protected int $timeout = 45;

    public function getPlatform(): string
    {
        return 'tiktok';
    }

    public function canHandle(string $url): bool
    {
        // Match various TikTok Shop domains
        return (bool) preg_match('/(?:tiktok\.com|shop\.tiktok\.com|shop-[a-z]{2}\.tiktok\.com).*(?:\/view\/product\/|\/product\/)/i', $url)
            || (bool) preg_match('/vt\.tiktok\.com/i', $url);
    }

    public function extractProductId(string $url): ?string
    {
        // Pattern: /view/product/1234567890123456789
        if (preg_match('/\/(?:view\/)?product\/(\d{15,25})/i', $url, $matches)) {
            return $matches[1];
        }

        // Short URL pattern (vt.tiktok.com/XXXXXX)
        // Note: Short URLs need to be resolved to get the actual product ID
        if (preg_match('/vt\.tiktok\.com\/([A-Za-z0-9]+)/i', $url, $matches)) {
            return 'short_' . $matches[1];
        }

        return null;
    }

    public function scrape(string $url): ScrapedProduct
    {
        // Extract product ID
        $productId = $this->extractProductId($url);
        if (!$productId) {
            throw ScrapingException::parseError($url, 'product_id');
        }

        // Handle short URLs - try to resolve them
        if (strpos($productId, 'short_') === 0) {
            $resolvedUrl = $this->resolveShortUrl($url);
            if ($resolvedUrl && $resolvedUrl !== $url) {
                $url = $resolvedUrl;
                $productId = $this->extractProductId($url) ?? $productId;
            }
        }

        // Build canonical URL
        $cleanUrl = $this->buildCanonicalUrl($productId);

        // Create product object
        $product = new ScrapedProduct($this->getPlatform(), $productId, $cleanUrl);

        // Try multiple extraction methods
        $success = false;

        // Method 1: Try HTML extraction with meta tags
        $success = $this->tryHtmlExtraction($product, $url);

        // Method 2: Try API extraction (usually blocked)
        if (!$success) {
            $success = $this->tryApiExtraction($product, $productId);
        }

        // Method 3: Try headless browser (Puppeteer)
        if (!$success || ($product->name === null && $product->price === null)) {
            $success = $this->tryHeadlessScraping($product, $productId, $url);
        }

        // Validate we got minimum required data
        if (!$success || ($product->name === null && $product->price === null)) {
            throw new ScrapingException(
                'TikTok Shop มีระบบป้องกัน Bot ที่เข้มงวด ไม่สามารถดึงข้อมูลได้ในขณะนี้ กรุณาลองใหม่ภายหลังหรือใช้ร้านค้าอื่น',
                ScrapingException::ERROR_BLOCKED,
                $url,
                403
            );
        }

        $product->calculateDiscount();
        return $product;
    }

    /**
     * Try to extract data using headless browser (Puppeteer).
     */
    private function tryHeadlessScraping(ScrapedProduct &$product, string $productId, string $url): bool
    {
        require_once __DIR__ . '/../HeadlessScraper.php';

        $scraper = new HeadlessScraper();

        if (!$scraper->isAvailable()) {
            return false;
        }

        $result = $scraper->scrape('tiktok', $url);

        // Check if blocked by anti-bot
        if (!empty($result['blocked'])) {
            return false;
        }

        if (!$result['success'] || empty($result['data'])) {
            return false;
        }

        $data = $result['data'];

        // Skip if name looks like a block page
        if (!empty($data['name'])) {
            $blockIndicators = ['Security Check', 'Access Denied', 'Blocked', 'TikTok'];
            $isBlocked = false;
            foreach ($blockIndicators as $indicator) {
                if (stripos($data['name'], $indicator) !== false && strlen($data['name']) < 50) {
                    $isBlocked = true;
                    break;
                }
            }
            if (!$isBlocked) {
                $product->name = $data['name'];
            }
        }

        if (!empty($data['price'])) {
            $product->price = (float) $data['price'];
        }
        if (!empty($data['originalPrice'])) {
            $product->originalPrice = (float) $data['originalPrice'];
        }
        if (!empty($data['image'])) {
            $product->imageUrl = $data['image'];
        }
        if (!empty($data['stockStatus'])) {
            $product->stockStatus = $data['stockStatus'];
        }

        return $product->name !== null || $product->price !== null;
    }

    /**
     * Try to resolve a short URL to the full product URL.
     */
    private function resolveShortUrl(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
        ]);

        $response = curl_exec($ch);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        return $redirectUrl ?: null;
    }

    /**
     * Try to extract data from HTML page.
     */
    private function tryHtmlExtraction(ScrapedProduct $product, string $url): bool
    {
        try {
            $html = $this->fetchHtml($url);
        } catch (ScrapingException $e) {
            return false;
        }

        // Check if we hit a security check page
        if (stripos($html, 'Security Check') !== false || stripos($html, 'captcha') !== false) {
            return false;
        }

        // Try to extract from meta tags
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i', $html, $matches)) {
            $product->name = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        }

        // Try title tag
        if (!$product->name && preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
            $title = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            if ($title !== 'Security Check' && stripos($title, 'TikTok') === false) {
                $product->name = $title;
            }
        }

        // Try to find price in embedded JSON
        if (preg_match('/"price"\s*:\s*"?(\d+(?:\.\d+)?)"?/i', $html, $matches)) {
            $product->price = (float) $matches[1];
        }

        // Try alternative price patterns
        if (!$product->price && preg_match('/"sale_price"\s*:\s*"?(\d+(?:\.\d+)?)"?/i', $html, $matches)) {
            $product->price = (float) $matches[1];
        }

        // Try original price
        if (preg_match('/"original_price"\s*:\s*"?(\d+(?:\.\d+)?)"?/i', $html, $matches)) {
            $product->originalPrice = (float) $matches[1];
        }

        // Try og:image
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i', $html, $matches)) {
            $product->imageUrl = $matches[1];
        }

        // Default stock status
        $product->stockStatus = 'unknown';

        // Check for stock status in HTML
        if (preg_match('/(?:sold\s*out|out\s*of\s*stock|หมด)/i', $html)) {
            $product->stockStatus = 'out_of_stock';
        } elseif (preg_match('/(?:in\s*stock|available|add\s*to\s*cart)/i', $html)) {
            $product->stockStatus = 'in_stock';
        }

        return $product->name !== null || $product->price !== null;
    }

    /**
     * Try to extract data from TikTok Shop API.
     */
    private function tryApiExtraction(ScrapedProduct $product, string $productId): bool
    {
        // TikTok Shop API endpoint (usually requires authentication)
        $apiUrl = "https://shop.tiktok.com/api/v1/product/detail?product_id={$productId}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Referer: https://shop.tiktok.com/',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return false;
        }

        $data = json_decode($response, true);
        if (!$data || isset($data['error']) || !isset($data['data'])) {
            return false;
        }

        $item = $data['data'];

        $product->name = $item['product_name'] ?? $item['title'] ?? null;
        $product->price = isset($item['price']) ? (float) $item['price'] : null;
        $product->originalPrice = isset($item['original_price']) ? (float) $item['original_price'] : null;
        $product->imageUrl = $item['cover_image'] ?? $item['images'][0] ?? null;
        $product->stockStatus = $this->parseStockStatus($item);

        return $product->name !== null || $product->price !== null;
    }

    /**
     * Parse stock status from API response.
     */
    private function parseStockStatus(array $item): string
    {
        $stock = $item['stock'] ?? $item['available_stock'] ?? null;
        $status = $item['status'] ?? $item['product_status'] ?? null;

        if ($status === 'sold_out' || $stock === 0) {
            return 'out_of_stock';
        }

        if ($stock > 0 || $status === 'available') {
            return 'in_stock';
        }

        return 'unknown';
    }

    /**
     * Build canonical URL for a TikTok Shop product.
     */
    private function buildCanonicalUrl(string $productId): string
    {
        // Remove 'short_' prefix if present
        if (strpos($productId, 'short_') === 0) {
            return "https://vt.tiktok.com/" . substr($productId, 6);
        }

        return "https://www.tiktok.com/view/product/{$productId}";
    }
}
