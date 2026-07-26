<?php

/**
 * Lazada Adapter
 *
 * Scraper for Lazada.co.th - Thailand's major e-commerce platform.
 *
 * Note: Lazada uses heavy JavaScript rendering. This adapter attempts
 * to extract data from embedded JSON in the page source.
 *
 * URL patterns:
 *   - https://www.lazada.co.th/products/product-name-i123456789-s987654321.html
 */

declare(strict_types=1);

namespace Modules\Scraping\Adapters;

use Modules\Scraping\BaseAdapter;
use Modules\Scraping\ScrapedProduct;
use Modules\Scraping\ScrapingException;

class LazadaAdapter extends BaseAdapter
{
    protected int $rateLimit = 10; // requests per minute

    public function getPlatform(): string
    {
        return 'lazada';
    }

    public function canHandle(string $url): bool
    {
        return (bool) preg_match('/lazada\.co\.th\/products\//i', $url);
    }

    public function extractProductId(string $url): ?string
    {
        // Extract product ID from URL patterns:
        // - /products/product-name-i123456789-s987654321.html (with product name)
        // - /products/i123456789-s987654321.html (without product name)
        if (preg_match('/[\/\-]i(\d+)-s(\d+)/i', $url, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }
        // Alternative pattern: just item ID
        if (preg_match('/[\/\-]i(\d+)\./i', $url, $matches)) {
            return $matches[1];
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

        // Fetch page
        $html = $this->fetchHtml($url);

        // Parse and return
        return $this->parseHtml($html, $url, $productId);
    }

    protected function parseHtml(string $html, string $url, string $productId): ScrapedProduct
    {
        $product = new ScrapedProduct($this->getPlatform(), $productId, $this->cleanUrl($url));

        // Try to extract data from __NEXT_DATA__ JSON (React app)
        if (preg_match('/<script[^>]*id="__NEXT_DATA__"[^>]*>(.+?)<\/script>/s', $html, $matches)) {
            $this->parseNextData($product, $matches[1]);
            return $product;
        }

        // Try to extract from app/pdp script data
        if (preg_match('/app\.run\(\s*(\{.+?\})\s*\)/s', $html, $matches)) {
            $this->parseAppData($product, $matches[1]);
            return $product;
        }

        // Try to extract from window.__data__ or similar
        if (preg_match('/window\.__data__\s*=\s*(\{.+?\});/s', $html, $matches)) {
            $this->parseWindowData($product, $matches[1]);
            return $product;
        }

        // Fallback: Try basic HTML parsing
        $this->parseBasicHtml($product, $html);

        if (empty($product->name) || $product->price === null) {
            throw new ScrapingException(
                'Could not extract product data from Lazada page',
                ScrapingException::ERROR_PARSING,
                $url
            );
        }

        return $product;
    }

    /**
     * Parse Next.js __NEXT_DATA__ JSON.
     */
    private function parseNextData(ScrapedProduct $product, string $json): void
    {
        $data = json_decode($json, true);
        if (!$data) {
            return;
        }

        // Navigate to product data - structure varies
        $productData = $data['props']['pageProps']['data']['data'] ??
                       $data['props']['initialState']['pdp'] ??
                       null;

        if (!$productData) {
            return;
        }

        $product->name = $productData['title'] ?? $productData['name'] ?? null;
        $product->price = $this->extractPrice($productData['price'] ?? $productData['salePrice'] ?? null);
        $product->originalPrice = $this->extractPrice($productData['originalPrice'] ?? null);
        $product->imageUrl = $productData['image'] ?? $productData['mainImage'] ?? null;
        $product->stockStatus = $this->parseStockStatus($productData['stock'] ?? $productData['quantity'] ?? null);
    }

    /**
     * Parse app.run() data format.
     */
    private function parseAppData(ScrapedProduct $product, string $json): void
    {
        // Fix common JSON issues in Lazada's format
        $json = preg_replace('/,\s*}/', '}', $json);
        $json = preg_replace('/,\s*]/', ']', $json);

        $data = json_decode($json, true);
        if (!$data) {
            return;
        }

        $itemInfo = $data['data']['root']['fields']['product'] ??
                    $data['data']['root']['fields']['skuInfos'][0] ??
                    null;

        if ($itemInfo) {
            $product->name = $itemInfo['title'] ?? $itemInfo['name'] ?? null;
            $product->price = $this->extractPrice($itemInfo['price'] ?? null);
            $product->originalPrice = $this->extractPrice($itemInfo['originalPrice'] ?? null);
        }
    }

    /**
     * Parse window.__data__ format.
     */
    private function parseWindowData(ScrapedProduct $product, string $json): void
    {
        $data = json_decode($json, true);
        if (!$data || !isset($data['data'])) {
            return;
        }

        $root = $data['data']['root']['fields'] ?? [];
        $skuInfo = $root['primaryKey']['skuInfo'] ?? $root['skuInfos'][0] ?? [];

        $product->name = $root['product']['title'] ?? null;
        $product->price = $this->extractPrice($skuInfo['price']['salePrice']['value'] ?? null);
        $product->originalPrice = $this->extractPrice($skuInfo['price']['originalPrice']['value'] ?? null);
    }

    /**
     * Basic HTML parsing fallback.
     */
    private function parseBasicHtml(ScrapedProduct $product, string $html): void
    {
        // Try to get title from various meta tags
        if (preg_match('/<meta[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']+)/i', $html, $matches)) {
            $product->name = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        } elseif (preg_match('/<title>([^<]+)/i', $html, $matches)) {
            $product->name = trim(preg_replace('/\s*[-|]\s*Lazada.*$/i', '', html_entity_decode($matches[1])));
        }

        // Try to get price from meta or JSON-LD
        if (preg_match('/"price":\s*"?(\d+(?:\.\d+)?)"?/i', $html, $matches)) {
            $product->price = (float) $matches[1];
        } elseif (preg_match('/฿\s*([\d,]+(?:\.\d+)?)/u', $html, $matches)) {
            $product->price = (float) str_replace(',', '', $matches[1]);
        }

        // Try to get image
        if (preg_match('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)/i', $html, $matches)) {
            $product->imageUrl = $matches[1];
        }

        // Default stock status
        $product->stockStatus = 'unknown';
        if (preg_match('/out.?of.?stock|sold.?out|หมด/i', $html)) {
            $product->stockStatus = 'out_of_stock';
        } elseif (preg_match('/in.?stock|add.?to.?cart|ซื้อ/i', $html)) {
            $product->stockStatus = 'in_stock';
        }
    }

    /**
     * Extract numeric price from various formats.
     */
    private function extractPrice($value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            // Remove currency symbols and format
            $cleaned = preg_replace('/[^\d.]/', '', $value);
            if (is_numeric($cleaned)) {
                return (float) $cleaned;
            }
        }

        if (is_array($value) && isset($value['value'])) {
            return $this->extractPrice($value['value']);
        }

        return null;
    }

    /**
     * Parse stock status from various formats.
     */
    private function parseStockStatus($stock): string
    {
        if ($stock === null) {
            return 'unknown';
        }

        if (is_numeric($stock)) {
            return $stock > 0 ? 'in_stock' : 'out_of_stock';
        }

        if (is_bool($stock)) {
            return $stock ? 'in_stock' : 'out_of_stock';
        }

        $stockStr = strtolower((string) $stock);
        if (strpos($stockStr, 'out') !== false || strpos($stockStr, 'sold') !== false) {
            return 'out_of_stock';
        }

        return 'in_stock';
    }

    /**
     * Clean URL by removing unnecessary tracking parameters.
     */
    private function cleanUrl(string $url): string
    {
        // Parse URL and keep only essential parts
        $parsed = parse_url($url);
        if (!$parsed) {
            return $url;
        }

        $cleanUrl = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];
        return $cleanUrl;
    }
}
