<?php

/**
 * Banana IT Adapter
 *
 * Scraper for BananaIT.co.th - IT retailer.
 * Despite using Nuxt.js, product pages are server-side rendered.
 *
 * URL patterns:
 *   - https://www.bananait.co.th/product/12345
 *   - https://www.bananait.co.th/product/12345/product-name
 */

declare(strict_types=1);

namespace Modules\Scraping\Adapters;

use Modules\Scraping\BaseAdapter;
use Modules\Scraping\ScrapedProduct;
use Modules\Scraping\ScrapingException;

class BananaAdapter extends BaseAdapter
{
    protected int $rateLimit = 15;

    public function getPlatform(): string
    {
        return 'banana';
    }

    public function canHandle(string $url): bool
    {
        return (bool) preg_match('#bananait\.co\.th/product/\d+#i', $url);
    }

    public function scrape(string $url): ScrapedProduct
    {
        // Extract product ID from URL
        if (!preg_match('#/product/(\d+)#', $url, $matches)) {
            throw ScrapingException::parseError($url, 'product_id');
        }
        $productId = $matches[1];

        // Fetch page
        $html = $this->fetchHtml($url);

        // Create product object
        $product = new ScrapedProduct('banana', $productId, $url);

        // Extract from JSON-LD (most reliable for Nuxt sites)
        $jsonLd = $this->extractJsonLd($html);
        if ($jsonLd) {
            $product->name = $jsonLd['name'] ?? null;
            $product->price = isset($jsonLd['offers']['price']) ? (float) $jsonLd['offers']['price'] : null;
            $product->imageUrl = $jsonLd['image'] ?? null;

            if (isset($jsonLd['offers']['availability'])) {
                $availability = strtolower($jsonLd['offers']['availability']);
                $product->stockStatus = str_contains($availability, 'instock') ? 'in_stock' : 'out_of_stock';
            }
        }

        // Fallback to HTML parsing if JSON-LD didn't have everything
        if (!$product->name) {
            $name = $this->extractByRegex($html, '/<h1[^>]*>([^<]+)<\/h1>/i');
            if (!$name) {
                $name = $this->extractByRegex($html, '/<title>([^<]+?)(?:\s*[-|]\s*Banana)?<\/title>/i');
            }
            $product->name = $this->cleanText($name);
        }

        if ($product->price === null) {
            // Try various price patterns
            $priceStr = $this->extractByRegex($html, '/class="[^"]*price[^"]*"[^>]*>([^<]*฿[^<]+)/i');
            if (!$priceStr) {
                $priceStr = $this->extractByRegex($html, '/฿\s*([\d,]+(?:\.\d{2})?)/');
            }
            $product->price = $this->parsePrice($priceStr);
        }

        // Extract original price
        $originalPriceStr = $this->extractByRegex($html, '/<del[^>]*>([^<]*฿[^<]+)<\/del>/i');
        if (!$originalPriceStr) {
            $originalPriceStr = $this->extractByRegex($html, '/class="[^"]*(?:original|old|was)[^"]*price[^"]*"[^>]*>([^<]+)/i');
        }
        $product->originalPrice = $this->parsePrice($originalPriceStr);

        // Extract image if not from JSON-LD
        if (!$product->imageUrl) {
            $product->imageUrl = $this->extractByRegex($html, '/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i');
        }

        // Check stock status if not from JSON-LD
        if (!$product->stockStatus) {
            if (preg_match('/(?:สินค้าหมด|out\s*of\s*stock|หมด)/i', $html)) {
                $product->stockStatus = 'out_of_stock';
            } else {
                $product->stockStatus = 'in_stock';
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
        if (preg_match('/<script[^>]*type="application\/ld\+json"[^>]*>([^<]+)<\/script>/i', $html, $matches)) {
            $data = json_decode($matches[1], true);
            if (is_array($data)) {
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
