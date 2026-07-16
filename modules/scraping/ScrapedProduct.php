<?php

/**
 * Scraped Product Data
 *
 * Represents the result of scraping a product page.
 */

declare(strict_types=1);

namespace Modules\Scraping;

class ScrapedProduct
{
    public string $platform;
    public string $platformProductId;
    public string $url;
    public ?string $name = null;
    public ?float $price = null;
    public ?float $originalPrice = null;
    public ?string $imageUrl = null;
    public ?string $stockStatus = null;
    public ?float $discountPercent = null;
    public array $attributes = [];
    public \DateTimeImmutable $scrapedAt;

    public function __construct(string $platform, string $platformProductId, string $url)
    {
        $this->platform = $platform;
        $this->platformProductId = $platformProductId;
        $this->url = $url;
        $this->scrapedAt = new \DateTimeImmutable();
    }

    /**
     * Calculate discount percentage if not already set.
     */
    public function calculateDiscount(): void
    {
        if ($this->discountPercent === null && $this->originalPrice > 0 && $this->price > 0) {
            $this->discountPercent = round((1 - $this->price / $this->originalPrice) * 100, 2);
        }
    }

    /**
     * Check if product is in stock.
     */
    public function isInStock(): bool
    {
        if ($this->stockStatus === null) {
            return true; // Assume in stock if not specified
        }

        $outOfStockKeywords = ['out of stock', 'sold out', 'หมด', 'ไม่มี', 'unavailable'];
        $status = strtolower($this->stockStatus);

        foreach ($outOfStockKeywords as $keyword) {
            if (str_contains($status, $keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert to array for database storage.
     */
    public function toArray(): array
    {
        $this->calculateDiscount();

        return [
            'platform' => $this->platform,
            'platform_product_id' => $this->platformProductId,
            'url' => $this->url,
            'name' => $this->name,
            'price' => $this->price,
            'original_price' => $this->originalPrice,
            'image_url' => $this->imageUrl,
            'stock_status' => $this->stockStatus,
            'discount_percent' => $this->discountPercent,
            'attributes' => $this->attributes,
            'scraped_at' => $this->scrapedAt->format('Y-m-d H:i:s'),
        ];
    }
}
