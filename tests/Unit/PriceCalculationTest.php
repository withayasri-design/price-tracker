<?php
/**
 * Unit tests for Price Calculations
 */

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PriceCalculationTest extends TestCase
{
    /**
     * Test discount percentage calculation
     */
    public function testDiscountPercentageCalculation(): void
    {
        $testCases = [
            ['original' => 1000, 'current' => 900, 'expected' => 10.0],
            ['original' => 1000, 'current' => 500, 'expected' => 50.0],
            ['original' => 1000, 'current' => 1000, 'expected' => 0.0],
            ['original' => 49990, 'current' => 44990, 'expected' => 10.0],
            ['original' => 100, 'current' => 33, 'expected' => 67.0],
        ];

        foreach ($testCases as $case) {
            $discount = $this->calculateDiscount($case['original'], $case['current']);
            $this->assertEquals(
                $case['expected'],
                $discount,
                "Discount from {$case['original']} to {$case['current']} should be {$case['expected']}%"
            );
        }
    }

    /**
     * Test price change percentage calculation
     */
    public function testPriceChangePercentage(): void
    {
        $testCases = [
            // Price drop
            ['old' => 1000, 'new' => 900, 'expected' => -10.0],
            // Price increase
            ['old' => 1000, 'new' => 1100, 'expected' => 10.0],
            // No change
            ['old' => 1000, 'new' => 1000, 'expected' => 0.0],
            // Large drop
            ['old' => 50000, 'new' => 25000, 'expected' => -50.0],
        ];

        foreach ($testCases as $case) {
            $change = $this->calculatePriceChange($case['old'], $case['new']);
            $this->assertEquals(
                $case['expected'],
                $change,
                "Change from {$case['old']} to {$case['new']} should be {$case['expected']}%"
            );
        }
    }

    /**
     * Test significant price change detection
     */
    public function testSignificantPriceChange(): void
    {
        $threshold = 5.0; // 5% threshold

        $testCases = [
            ['old' => 1000, 'new' => 940, 'significant' => true],  // -6%
            ['old' => 1000, 'new' => 960, 'significant' => false], // -4%
            ['old' => 1000, 'new' => 1060, 'significant' => true],  // +6%
            ['old' => 1000, 'new' => 1000, 'significant' => false], // 0%
        ];

        foreach ($testCases as $case) {
            $isSignificant = $this->isSignificantChange($case['old'], $case['new'], $threshold);
            $this->assertEquals(
                $case['significant'],
                $isSignificant,
                "Change from {$case['old']} to {$case['new']} significant check failed"
            );
        }
    }

    /**
     * Test target price alert trigger
     */
    public function testTargetPriceAlertTrigger(): void
    {
        $testCases = [
            // Current price below target - should trigger
            ['current' => 900, 'target' => 1000, 'trigger' => true],
            // Current price equals target - should trigger
            ['current' => 1000, 'target' => 1000, 'trigger' => true],
            // Current price above target - should not trigger
            ['current' => 1100, 'target' => 1000, 'trigger' => false],
        ];

        foreach ($testCases as $case) {
            $shouldTrigger = $this->shouldTriggerTargetAlert($case['current'], $case['target']);
            $this->assertEquals(
                $case['trigger'],
                $shouldTrigger,
                "Target alert for current={$case['current']}, target={$case['target']} failed"
            );
        }
    }

    /**
     * Test target discount alert trigger
     */
    public function testTargetDiscountAlertTrigger(): void
    {
        $testCases = [
            // 20% discount when target is 15% - should trigger
            ['original' => 1000, 'current' => 800, 'target_percent' => 15, 'trigger' => true],
            // 10% discount when target is 15% - should not trigger
            ['original' => 1000, 'current' => 900, 'target_percent' => 15, 'trigger' => false],
            // Exact match - should trigger
            ['original' => 1000, 'current' => 850, 'target_percent' => 15, 'trigger' => true],
        ];

        foreach ($testCases as $case) {
            $shouldTrigger = $this->shouldTriggerDiscountAlert(
                $case['original'],
                $case['current'],
                $case['target_percent']
            );
            $this->assertEquals(
                $case['trigger'],
                $shouldTrigger,
                "Discount alert for original={$case['original']}, current={$case['current']}, target={$case['target_percent']}% failed"
            );
        }
    }

    /**
     * Test price formatting for Thai Baht
     */
    public function testThaiPriceFormatting(): void
    {
        $testCases = [
            [1000, '฿1,000'],
            [1000.50, '฿1,001'], // Rounded
            [49990, '฿49,990'],
            [1234567.89, '฿1,234,568'],
            [0, '฿0'],
            [99.99, '฿100'],
        ];

        foreach ($testCases as [$price, $expected]) {
            $formatted = $this->formatThaiPrice($price);
            $this->assertEquals($expected, $formatted, "Formatting $price failed");
        }
    }

    /**
     * Test price statistics calculation
     */
    public function testPriceStatistics(): void
    {
        $priceHistory = [1000, 950, 1100, 900, 1050, 980, 920];

        $stats = $this->calculatePriceStats($priceHistory);

        $this->assertEquals(900, $stats['min']);
        $this->assertEquals(1100, $stats['max']);
        $this->assertEquals(985.71, $stats['avg']); // Rounded to 2 decimals
        $this->assertEquals(920, $stats['current']);
        $this->assertEquals(200, $stats['range']); // max - min
    }

    /**
     * Test empty price history handling
     */
    public function testEmptyPriceHistory(): void
    {
        $stats = $this->calculatePriceStats([]);

        $this->assertNull($stats['min']);
        $this->assertNull($stats['max']);
        $this->assertNull($stats['avg']);
        $this->assertNull($stats['current']);
    }

    /**
     * Test price trend detection
     */
    public function testPriceTrendDetection(): void
    {
        $testCases = [
            // Decreasing trend
            [[1000, 950, 900, 850, 800], 'decreasing'],
            // Increasing trend
            [[800, 850, 900, 950, 1000], 'increasing'],
            // Stable (within 5% variance)
            [[1000, 1020, 990, 1010, 1000], 'stable'],
            // Volatile
            [[1000, 800, 1200, 700, 1100], 'volatile'],
        ];

        foreach ($testCases as [$history, $expectedTrend]) {
            $trend = $this->detectPriceTrend($history);
            $this->assertEquals(
                $expectedTrend,
                $trend,
                "Trend detection failed for: " . implode(', ', $history)
            );
        }
    }

    /**
     * Helper: Calculate discount percentage
     */
    private function calculateDiscount(float $originalPrice, float $currentPrice): float
    {
        if ($originalPrice <= 0) {
            return 0.0;
        }
        return round((($originalPrice - $currentPrice) / $originalPrice) * 100, 1);
    }

    /**
     * Helper: Calculate price change percentage
     */
    private function calculatePriceChange(float $oldPrice, float $newPrice): float
    {
        if ($oldPrice <= 0) {
            return 0.0;
        }
        return round((($newPrice - $oldPrice) / $oldPrice) * 100, 1);
    }

    /**
     * Helper: Check if price change is significant
     */
    private function isSignificantChange(float $oldPrice, float $newPrice, float $threshold): bool
    {
        $change = abs($this->calculatePriceChange($oldPrice, $newPrice));
        return $change >= $threshold;
    }

    /**
     * Helper: Check if target price alert should trigger
     */
    private function shouldTriggerTargetAlert(float $currentPrice, float $targetPrice): bool
    {
        return $currentPrice <= $targetPrice;
    }

    /**
     * Helper: Check if target discount alert should trigger
     */
    private function shouldTriggerDiscountAlert(
        float $originalPrice,
        float $currentPrice,
        float $targetPercent
    ): bool {
        $actualDiscount = $this->calculateDiscount($originalPrice, $currentPrice);
        return $actualDiscount >= $targetPercent;
    }

    /**
     * Helper: Format price in Thai Baht
     */
    private function formatThaiPrice(float $price): string
    {
        return '฿' . number_format(round($price), 0);
    }

    /**
     * Helper: Calculate price statistics
     */
    private function calculatePriceStats(array $prices): array
    {
        if (empty($prices)) {
            return [
                'min' => null,
                'max' => null,
                'avg' => null,
                'current' => null,
                'range' => null,
            ];
        }

        return [
            'min' => min($prices),
            'max' => max($prices),
            'avg' => round(array_sum($prices) / count($prices), 2),
            'current' => end($prices),
            'range' => max($prices) - min($prices),
        ];
    }

    /**
     * Helper: Detect price trend
     */
    private function detectPriceTrend(array $prices): string
    {
        if (count($prices) < 3) {
            return 'unknown';
        }

        $first = $prices[0];
        $last = end($prices);
        $avg = array_sum($prices) / count($prices);

        // Calculate variance
        $variance = 0;
        foreach ($prices as $price) {
            $variance += ($price - $avg) ** 2;
        }
        $variance = sqrt($variance / count($prices));
        $variancePercent = ($variance / $avg) * 100;

        // High variance = volatile
        if ($variancePercent > 15) {
            return 'volatile';
        }

        $changePercent = (($last - $first) / $first) * 100;

        if ($changePercent < -10) {
            return 'decreasing';
        } elseif ($changePercent > 10) {
            return 'increasing';
        }

        return 'stable';
    }
}
