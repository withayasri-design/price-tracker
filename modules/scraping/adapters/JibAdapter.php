<?php

/**
 * JIB Adapter
 *
 * Scraper for JIB.co.th - Thailand's leading IT retailer.
 * Server-rendered pages, straightforward to scrape.
 *
 * URL patterns:
 *   - https://www.jib.co.th/web/product/readProduct/12345
 *   - https://www.jib.co.th/web/product/readProduct/12345/product-name
 */

declare(strict_types=1);

namespace Modules\Scraping\Adapters;

use Modules\Scraping\BaseAdapter;
use Modules\Scraping\ScrapedProduct;
use Modules\Scraping\ScrapingException;

class JibAdapter extends BaseAdapter
{
    protected int $rateLimit = 15;

    public function getPlatform(): string
    {
        return 'jib';
    }

    public function canHandle(string $url): bool
    {
        return (bool) preg_match('#jib\.co\.th/web/product/readProduct/\d+#i', $url);
    }

    public function scrape(string $url): ScrapedProduct
    {
        // Extract product ID from URL
        if (!preg_match('#/readProduct/(\d+)#', $url, $matches)) {
            throw ScrapingException::parseError($url, 'product_id');
        }
        $productId = $matches[1];

        // Fetch page
        $html = $this->fetchHtml($url);

        // Create product object
        $product = new ScrapedProduct('jib', $productId, $url);

        // Extract product name
        // <h1 class="product-name">Product Name</h1>
        // or <title>Product Name - JIB</title>
        $name = $this->extractByRegex($html, '/<h1[^>]*class="[^"]*product-name[^"]*"[^>]*>([^<]+)/i');
        if (!$name) {
            $name = $this->extractByRegex($html, '/<title>([^<]+?)(?:\s*[-|]\s*JIB)?<\/title>/i');
        }
        $product->name = $this->cleanText($name);

        // Extract current price
        // <span class="product-price">฿12,345</span>
        // or data-price="12345"
        $priceStr = $this->extractByRegex($html, '/class="[^"]*product-price[^"]*"[^>]*>([^<]+)/i');
        if (!$priceStr) {
            $priceStr = $this->extractByRegex($html, '/data-price="([\d,.]+)"/i');
        }
        if (!$priceStr) {
            // Try finding price in JSON-LD
            $priceStr = $this->extractByRegex($html, '/"price"\s*:\s*"?([\d,.]+)"?/i');
        }
        $product->price = $this->parsePrice($priceStr);

        // Extract original price (if on sale)
        // <span class="original-price">฿15,000</span>
        // or <del>฿15,000</del>
        $originalPriceStr = $this->extractByRegex($html, '/class="[^"]*original-price[^"]*"[^>]*>([^<]+)/i');
        if (!$originalPriceStr) {
            $originalPriceStr = $this->extractByRegex($html, '/<del[^>]*>([^<]*฿[^<]+)<\/del>/i');
        }
        $product->originalPrice = $this->parsePrice($originalPriceStr);

        // Extract image URL
        // <img class="product-image" src="...">
        // or og:image meta tag
        $imageUrl = $this->extractByRegex($html, '/class="[^"]*product-image[^"]*"[^>]*src="([^"]+)"/i');
        if (!$imageUrl) {
            $imageUrl = $this->extractByRegex($html, '/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i');
        }
        if (!$imageUrl) {
            $imageUrl = $this->extractByRegex($html, '/<meta[^>]+content="([^"]+)"[^>]+property="og:image"/i');
        }
        $product->imageUrl = $imageUrl;

        // Extract stock status
        // Look for "สินค้าหมด", "out of stock", or stock quantity
        if (preg_match('/(?:สินค้าหมด|out\s*of\s*stock|หมดชั่วคราว)/i', $html)) {
            $product->stockStatus = 'out_of_stock';
        } elseif (preg_match('/(?:มีสินค้า|in\s*stock|พร้อมส่ง)/i', $html)) {
            $product->stockStatus = 'in_stock';
        } else {
            $product->stockStatus = 'unknown';
        }

        // Validate required fields
        if ($product->price === null) {
            throw ScrapingException::parseError($url, 'price');
        }

        $product->calculateDiscount();

        return $product;
    }
}
