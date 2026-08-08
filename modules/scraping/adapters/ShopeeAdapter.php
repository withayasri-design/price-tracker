<?php

/**
 * Shopee Adapter
 *
 * Scraper for Shopee.co.th - Thailand's largest e-commerce platform.
 *
 * IMPORTANT: Shopee uses heavy JavaScript rendering and anti-bot measures.
 * This adapter attempts to extract data from:
 * 1. Embedded JSON in page source
 * 2. Meta tags (og:title, og:image, etc.)
 * 3. Shopee's internal API (may require session)
 *
 * Success rate may be limited. For production use, consider:
 * - Headless browser (Puppeteer/Playwright)
 * - Official Shopee API (requires partnership)
 *
 * URL patterns:
 *   - https://shopee.co.th/product/123456/789012
 *   - https://shopee.co.th/Product-Name-i.123456.789012
 *   - https://shopee.co.th/-i.123456.789012
 */

declare(strict_types=1);

namespace Modules\Scraping\Adapters;

use Modules\Scraping\BaseAdapter;
use Modules\Scraping\ScrapedProduct;
use Modules\Scraping\ScrapingException;
use Modules\Scraping\HeadlessScraper;

class ShopeeAdapter extends BaseAdapter
{
    protected int $rateLimit = 5; // Lower rate limit due to anti-bot
    protected int $timeout = 45; // Longer timeout for Shopee

    public function getPlatform(): string
    {
        return 'shopee';
    }

    public function canHandle(string $url): bool
    {
        return (bool) preg_match('/shopee\.co\.th/i', $url);
    }

    public function extractProductId(string $url): ?string
    {
        // Pattern 1: /product/shopid/itemid
        if (preg_match('/\/product\/(\d+)\/(\d+)/i', $url, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }

        // Pattern 2: -i.shopid.itemid or Product-Name-i.shopid.itemid
        if (preg_match('/-i\.(\d+)\.(\d+)/i', $url, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }

        return null;
    }

    /**
     * Extract shop ID and item ID separately.
     */
    private function extractIds(string $url): ?array
    {
        // Pattern 1: /product/shopid/itemid
        if (preg_match('/\/product\/(\d+)\/(\d+)/i', $url, $matches)) {
            return ['shop_id' => $matches[1], 'item_id' => $matches[2]];
        }

        // Pattern 2: -i.shopid.itemid
        if (preg_match('/-i\.(\d+)\.(\d+)/i', $url, $matches)) {
            return ['shop_id' => $matches[1], 'item_id' => $matches[2]];
        }

        return null;
    }

    public function scrape(string $url): ScrapedProduct
    {
        // Extract product IDs
        $ids = $this->extractIds($url);
        if (!$ids) {
            throw ScrapingException::parseError($url, 'product_id');
        }

        $productId = $ids['shop_id'] . '_' . $ids['item_id'];
        $cleanUrl = $this->buildCanonicalUrl($ids['shop_id'], $ids['item_id']);

        // Create product object
        $product = new ScrapedProduct($this->getPlatform(), $productId, $cleanUrl);

        // Try multiple extraction methods
        $success = false;

        // Method 1: Try Shopee API
        $success = $this->tryApiExtraction($product, $ids['shop_id'], $ids['item_id']);

        // Method 2: Try HTML page extraction
        if (!$success) {
            $success = $this->tryHtmlExtraction($product, $url);
        }

        // Method 3: Try headless browser (Puppeteer)
        if (!$success || ($product->name === null && $product->price === null)) {
            $success = $this->tryHeadlessScraping($product, $productId, $url);
        }

        // Validate we got minimum required data
        if (!$success || ($product->name === null && $product->price === null)) {
            throw new ScrapingException(
                'Shopee มีระบบป้องกัน Bot ที่เข้มงวด ไม่สามารถดึงข้อมูลได้ในขณะนี้ กรุณาลองใหม่ภายหลังหรือใช้ร้านค้าอื่น',
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

        $result = $scraper->scrape('shopee', $url);

        // Check if blocked by anti-bot
        if (!empty($result['blocked'])) {
            // Anti-bot detected, return false to trigger appropriate error
            return false;
        }

        if (!$result['success'] || empty($result['data'])) {
            return false;
        }

        $data = $result['data'];

        // Skip if name looks like a block page
        if (!empty($data['name'])) {
            $blockIndicators = ['Security Check', 'Hot Deals', 'Access Denied', 'Shopee Thailand'];
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
     * Try to extract data from Shopee's API.
     */
    private function tryApiExtraction(ScrapedProduct $product, string $shopId, string $itemId): bool
    {
        $apiUrl = "https://shopee.co.th/api/v4/item/get?shopid={$shopId}&itemid={$itemId}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Referer: https://shopee.co.th/',
                'X-Requested-With: XMLHttpRequest',
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

        $product->name = $item['name'] ?? $item['title'] ?? null;
        $product->price = $this->extractApiPrice($item['price'] ?? $item['price_min'] ?? null);
        $product->originalPrice = $this->extractApiPrice($item['price_before_discount'] ?? $item['price_max'] ?? null);
        $product->imageUrl = $this->buildImageUrl($item['image'] ?? $item['images'][0] ?? null);
        $product->stockStatus = $this->parseStockFromApi($item);

        return $product->name !== null || $product->price !== null;
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

        // Try to find product name from title or meta tags
        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
            $title = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            // Remove " | Shopee Thailand" suffix
            $title = preg_replace('/\s*\|\s*Shopee.*$/i', '', $title);
            if ($title && $title !== 'Shopee Thailand') {
                $product->name = $title;
            }
        }

        // Try og:title
        if (!$product->name && preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i', $html, $matches)) {
            $product->name = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        }

        // Try to find price in embedded JSON
        if (preg_match('/"price"\s*:\s*(\d+)/i', $html, $matches)) {
            $product->price = (float) $matches[1] / 100000; // Shopee stores price in smallest unit
        }

        // Try to find price in different format
        if (!$product->price && preg_match('/"price_min"\s*:\s*(\d+)/i', $html, $matches)) {
            $product->price = (float) $matches[1] / 100000;
        }

        // Try og:image
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i', $html, $matches)) {
            $product->imageUrl = $matches[1];
        }

        // Default stock status
        $product->stockStatus = 'unknown';

        // Check for out of stock indicators
        if (preg_match('/(?:สินค้าหมด|sold\s*out|out\s*of\s*stock)/i', $html)) {
            $product->stockStatus = 'out_of_stock';
        }

        return $product->name !== null || $product->price !== null;
    }

    /**
     * Extract price from API response (Shopee uses price * 100000).
     */
    private function extractApiPrice($value): ?float
    {
        if ($value === null || $value === 0) {
            return null;
        }

        if (is_numeric($value)) {
            // Shopee API returns price multiplied by 100000
            return (float) $value / 100000;
        }

        return null;
    }

    /**
     * Parse stock status from API response.
     */
    private function parseStockFromApi(array $item): string
    {
        $stock = $item['stock'] ?? $item['normal_stock'] ?? null;

        if ($stock === null) {
            return 'unknown';
        }

        if ($stock === 0) {
            return 'out_of_stock';
        }

        if ($stock > 0) {
            return 'in_stock';
        }

        return 'unknown';
    }

    /**
     * Build image URL from Shopee image hash.
     */
    private function buildImageUrl(?string $imageHash): ?string
    {
        if (!$imageHash) {
            return null;
        }

        // If it's already a full URL
        if (strpos($imageHash, 'http') === 0) {
            return $imageHash;
        }

        // Build Shopee CDN URL
        return "https://down-th.img.susercontent.com/file/{$imageHash}";
    }

    /**
     * Build canonical URL for a Shopee product.
     */
    private function buildCanonicalUrl(string $shopId, string $itemId): string
    {
        return "https://shopee.co.th/product/{$shopId}/{$itemId}";
    }
}
