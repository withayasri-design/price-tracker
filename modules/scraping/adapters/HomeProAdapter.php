<?php

/**
 * HomePro Adapter
 *
 * Scraper for homepro.co.th - Home improvement and furniture retailer.
 *
 * URL patterns:
 *   - https://www.homepro.co.th/p/12345
 *   - https://www.homepro.co.th/product/product-name-12345
 */

declare(strict_types=1);

namespace Modules\Scraping\Adapters;

use Modules\Scraping\BaseAdapter;
use Modules\Scraping\ScrapedProduct;
use Modules\Scraping\ScrapingException;

class HomeProAdapter extends BaseAdapter
{
    protected int $rateLimit = 12;

    public function getPlatform(): string
    {
        return 'homepro';
    }

    public function canHandle(string $url): bool
    {
        return (bool) preg_match('#homepro\.co\.th/(?:p|product)/#i', $url);
    }

    public function scrape(string $url): ScrapedProduct
    {
        // Extract product ID from URL
        if (!preg_match('#/(?:p|product)/(?:[^/]+-)?(\d+)#', $url, $matches)) {
            if (!preg_match('#/(?:p|product)/([^/?]+)#', $url, $matches)) {
                throw ScrapingException::parseError($url, 'product_id');
            }
        }
        $productId = $matches[1];

        // Fetch page
        $html = $this->fetchHtml($url);

        // Create product object
        $product = new ScrapedProduct('homepro', $productId, $url);

        // Extract product name from various patterns
        $name = $this->extractByRegex($html, '/<h1[^>]*class="[^"]*product[_-]?title[^"]*"[^>]*>([^<]+)/i');
        if (!$name) {
            $name = $this->extractByRegex($html, '/<h1[^>]*class="[^"]*pdp[_-]?name[^"]*"[^>]*>([^<]+)/i');
        }
        if (!$name) {
            $name = $this->extractByRegex($html, '/<h1[^>]*>([^<]+)<\/h1>/i');
        }
        if (!$name) {
            $name = $this->extractByRegex($html, '/<title>([^<]+?)(?:\s*[-|]\s*HomePro)?<\/title>/i');
        }
        $product->name = $this->cleanText($name);

        // Extract current price
        $priceStr = $this->extractByRegex($html, '/class="[^"]*(?:price-final|selling-price|special-price)[^"]*"[^>]*>([^<]+)/i');
        if (!$priceStr) {
            $priceStr = $this->extractByRegex($html, '/data-price="([\d,.]+)"/i');
        }
        if (!$priceStr) {
            $priceStr = $this->extractByRegex($html, '/"price"\s*:\s*"?([\d,.]+)"?/i');
        }
        if (!$priceStr) {
            // Look for Thai Baht pattern
            $priceStr = $this->extractByRegex($html, '/฿\s*([\d,]+(?:\.\d{2})?)/');
        }
        $product->price = $this->parsePrice($priceStr);

        // Extract original price
        $originalPriceStr = $this->extractByRegex($html, '/class="[^"]*(?:price-old|regular-price|was-price)[^"]*"[^>]*>([^<]+)/i');
        if (!$originalPriceStr) {
            $originalPriceStr = $this->extractByRegex($html, '/<del[^>]*>([^<]*[\d,]+[^<]*)<\/del>/i');
        }
        if (!$originalPriceStr) {
            $originalPriceStr = $this->extractByRegex($html, '/<s[^>]*>([^<]*[\d,]+[^<]*)<\/s>/i');
        }
        $product->originalPrice = $this->parsePrice($originalPriceStr);

        // Extract image URL
        $imageUrl = $this->extractByRegex($html, '/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i');
        if (!$imageUrl) {
            $imageUrl = $this->extractByRegex($html, '/<meta[^>]+content="([^"]+)"[^>]+property="og:image"/i');
        }
        if (!$imageUrl) {
            $imageUrl = $this->extractByRegex($html, '/class="[^"]*(?:product-image|pdp-image)[^"]*"[^>]*src="([^"]+)"/i');
        }
        $product->imageUrl = $imageUrl;

        // Extract stock status
        if (preg_match('/(?:สินค้าหมด|out\s*of\s*stock|ไม่มีสินค้า|sold\s*out)/i', $html)) {
            $product->stockStatus = 'out_of_stock';
        } elseif (preg_match('/(?:มีสินค้า|in\s*stock|พร้อมจำหน่าย|add\s*to\s*cart)/i', $html)) {
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
