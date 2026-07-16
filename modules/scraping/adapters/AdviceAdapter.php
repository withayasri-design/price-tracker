<?php

/**
 * Advice Adapter
 *
 * Scraper for Advice.co.th - IT retailer.
 * Detail pages are server-side rendered.
 *
 * URL patterns:
 *   - https://www.advice.co.th/product/12345
 *   - https://www.advice.co.th/product/product-name-12345
 */

declare(strict_types=1);

namespace Modules\Scraping\Adapters;

use Modules\Scraping\BaseAdapter;
use Modules\Scraping\ScrapedProduct;
use Modules\Scraping\ScrapingException;

class AdviceAdapter extends BaseAdapter
{
    protected int $rateLimit = 15;

    public function getPlatform(): string
    {
        return 'advice';
    }

    public function canHandle(string $url): bool
    {
        return (bool) preg_match('#advice\.co\.th/product/#i', $url);
    }

    public function scrape(string $url): ScrapedProduct
    {
        // Extract product ID from URL
        // Can be /product/12345 or /product/product-name-12345
        if (!preg_match('#/product/(?:.*?-)?(\d+)(?:[/?#]|$)#', $url, $matches)) {
            // Try alternate pattern
            if (!preg_match('#/product/(\d+)#', $url, $matches)) {
                throw ScrapingException::parseError($url, 'product_id');
            }
        }
        $productId = $matches[1];

        // Fetch page
        $html = $this->fetchHtml($url);

        // Create product object
        $product = new ScrapedProduct('advice', $productId, $url);

        // Extract from JSON-LD first
        $jsonLd = $this->extractJsonLd($html);
        if ($jsonLd) {
            $product->name = $jsonLd['name'] ?? null;

            if (isset($jsonLd['offers'])) {
                $offers = $jsonLd['offers'];
                // Handle array of offers
                if (isset($offers[0])) {
                    $offers = $offers[0];
                }
                $product->price = isset($offers['price']) ? (float) $offers['price'] : null;

                if (isset($offers['availability'])) {
                    $availability = strtolower($offers['availability']);
                    $product->stockStatus = str_contains($availability, 'instock') ? 'in_stock' : 'out_of_stock';
                }
            }

            $product->imageUrl = is_array($jsonLd['image'] ?? null) ? ($jsonLd['image'][0] ?? null) : ($jsonLd['image'] ?? null);
        }

        // Fallback to HTML parsing
        if (!$product->name) {
            $name = $this->extractByRegex($html, '/<h1[^>]*class="[^"]*product[^"]*name[^"]*"[^>]*>([^<]+)/i');
            if (!$name) {
                $name = $this->extractByRegex($html, '/<h1[^>]*>([^<]+)<\/h1>/i');
            }
            if (!$name) {
                $name = $this->extractByRegex($html, '/<title>([^<]+?)(?:\s*[-|]\s*Advice)?<\/title>/i');
            }
            $product->name = $this->cleanText($name);
        }

        if ($product->price === null) {
            // Try various price patterns used by Advice
            $priceStr = $this->extractByRegex($html, '/class="[^"]*(?:sale-price|current-price|price)[^"]*"[^>]*>([^<]*[\d,]+[^<]*)/i');
            if (!$priceStr) {
                $priceStr = $this->extractByRegex($html, '/(?:ราคา|Price)[^<]*<[^>]+>([^<]*฿?[\d,]+)/i');
            }
            if (!$priceStr) {
                $priceStr = $this->extractByRegex($html, '/฿\s*([\d,]+(?:\.\d{2})?)/');
            }
            $product->price = $this->parsePrice($priceStr);
        }

        // Extract original price
        $originalPriceStr = $this->extractByRegex($html, '/<del[^>]*>([^<]+)<\/del>/i');
        if (!$originalPriceStr) {
            $originalPriceStr = $this->extractByRegex($html, '/class="[^"]*(?:original|old|regular)[^"]*price[^"]*"[^>]*>([^<]+)/i');
        }
        if (!$originalPriceStr) {
            $originalPriceStr = $this->extractByRegex($html, '/class="[^"]*line-through[^"]*"[^>]*>([^<]+)/i');
        }
        $product->originalPrice = $this->parsePrice($originalPriceStr);

        // Extract image if not from JSON-LD
        if (!$product->imageUrl) {
            $product->imageUrl = $this->extractByRegex($html, '/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i');
            if (!$product->imageUrl) {
                $product->imageUrl = $this->extractByRegex($html, '/<img[^>]+class="[^"]*product[^"]*image[^"]*"[^>]+src="([^"]+)"/i');
            }
        }

        // Check stock status if not from JSON-LD
        if (!$product->stockStatus) {
            if (preg_match('/(?:สินค้าหมด|out\s*of\s*stock|ไม่มีสินค้า|sold\s*out)/i', $html)) {
                $product->stockStatus = 'out_of_stock';
            } elseif (preg_match('/(?:มีสินค้า|in\s*stock|พร้อมส่ง|สั่งซื้อได้)/i', $html)) {
                $product->stockStatus = 'in_stock';
            } else {
                $product->stockStatus = 'unknown';
            }
        }

        // Validate required fields
        if ($product->price === null) {
            throw ScrapingException::parseError($url, 'price');
        }

        $product->calculateDiscount();

        return $product;
    }

    /**
     * Extract JSON-LD structured data.
     */
    private function extractJsonLd(string $html): ?array
    {
        // Find all JSON-LD scripts
        if (preg_match_all('/<script[^>]*type="application\/ld\+json"[^>]*>([^<]+)<\/script>/i', $html, $matches)) {
            foreach ($matches[1] as $jsonStr) {
                $data = json_decode($jsonStr, true);
                if (!is_array($data)) {
                    continue;
                }

                // Handle @graph structure
                if (isset($data['@graph'])) {
                    foreach ($data['@graph'] as $item) {
                        if (isset($item['@type']) && $item['@type'] === 'Product') {
                            return $item;
                        }
                    }
                }

                // Direct Product type
                if (isset($data['@type']) && $data['@type'] === 'Product') {
                    return $data;
                }
            }
        }
        return null;
    }
}
