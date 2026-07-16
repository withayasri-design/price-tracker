<?php

/**
 * Power Buy Adapter
 *
 * Scraper for powerbuy.co.th - Electronics and appliances retailer.
 *
 * URL patterns:
 *   - https://www.powerbuy.co.th/th/product/12345
 *   - https://www.powerbuy.co.th/th/p/product-name-12345
 */

declare(strict_types=1);

namespace Modules\Scraping\Adapters;

use Modules\Scraping\BaseAdapter;
use Modules\Scraping\ScrapedProduct;
use Modules\Scraping\ScrapingException;

class PowerBuyAdapter extends BaseAdapter
{
    protected int $rateLimit = 12;

    public function getPlatform(): string
    {
        return 'powerbuy';
    }

    public function canHandle(string $url): bool
    {
        return (bool) preg_match('#powerbuy\.co\.th/(?:th/)?(?:product|p)/#i', $url);
    }

    public function scrape(string $url): ScrapedProduct
    {
        // Extract product ID from URL
        if (!preg_match('#/(?:product|p)/(?:[^/]+-)?(\d+)#', $url, $matches)) {
            if (!preg_match('#/(?:product|p)/([^/?]+)#', $url, $matches)) {
                throw ScrapingException::parseError($url, 'product_id');
            }
        }
        $productId = $matches[1];

        // Fetch page
        $html = $this->fetchHtml($url);

        // Create product object
        $product = new ScrapedProduct('powerbuy', $productId, $url);

        // Extract product name
        $name = $this->extractByRegex($html, '/<h1[^>]*class="[^"]*product[_-]?name[^"]*"[^>]*>([^<]+)/i');
        if (!$name) {
            $name = $this->extractByRegex($html, '/<h1[^>]*class="[^"]*pdp[_-]?title[^"]*"[^>]*>([^<]+)/i');
        }
        if (!$name) {
            $name = $this->extractByRegex($html, '/<h1[^>]*>([^<]+)<\/h1>/i');
        }
        if (!$name) {
            $name = $this->extractByRegex($html, '/<title>([^<]+?)(?:\s*[-|]\s*Power\s*Buy)?<\/title>/i');
        }
        $product->name = $this->cleanText($name);

        // Extract current price (Power Buy often uses JSON-LD)
        $priceStr = $this->extractByRegex($html, '/"price"\s*:\s*"?([\d,.]+)"?/i');
        if (!$priceStr) {
            $priceStr = $this->extractByRegex($html, '/class="[^"]*(?:price-final|special-price|selling-price)[^"]*"[^>]*>([^<]+)/i');
        }
        if (!$priceStr) {
            $priceStr = $this->extractByRegex($html, '/data-price="([\d,.]+)"/i');
        }
        if (!$priceStr) {
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
        if (!$originalPriceStr) {
            $originalPriceStr = $this->extractByRegex($html, '/class="[^"]*strike[^"]*"[^>]*>([^<]*[\d,]+[^<]*)/i');
        }
        $product->originalPrice = $this->parsePrice($originalPriceStr);

        // Extract image URL
        $imageUrl = $this->extractByRegex($html, '/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i');
        if (!$imageUrl) {
            $imageUrl = $this->extractByRegex($html, '/<meta[^>]+content="([^"]+)"[^>]+property="og:image"/i');
        }
        if (!$imageUrl) {
            $imageUrl = $this->extractByRegex($html, '/"image"\s*:\s*"([^"]+)"/i');
        }
        if (!$imageUrl) {
            $imageUrl = $this->extractByRegex($html, '/class="[^"]*product[_-]?image[^"]*"[^>]*src="([^"]+)"/i');
        }
        $product->imageUrl = $imageUrl;

        // Extract stock status
        if (preg_match('/(?:สินค้าหมด|out\s*of\s*stock|ไม่มีสินค้า|sold\s*out)/i', $html)) {
            $product->stockStatus = 'out_of_stock';
        } elseif (preg_match('/(?:มีสินค้า|in\s*stock|พร้อมจำหน่าย|หยิบใส่ตะกร้า)/i', $html)) {
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
