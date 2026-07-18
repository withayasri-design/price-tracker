<?php
/**
 * Unit tests for SimilarityCalculator
 */

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SimilarityCalculatorTest extends TestCase
{
    /**
     * Test trigram generation
     */
    public function testTrigramGeneration(): void
    {
        $trigrams = $this->generateTrigrams('apple');

        $this->assertContains('app', $trigrams);
        $this->assertContains('ppl', $trigrams);
        $this->assertContains('ple', $trigrams);
        $this->assertCount(3, $trigrams);
    }

    /**
     * Test trigram generation with short strings
     */
    public function testTrigramGenerationShortString(): void
    {
        $this->assertEmpty($this->generateTrigrams('ab'));
        $this->assertCount(1, $this->generateTrigrams('abc'));
    }

    /**
     * Test Levenshtein distance
     */
    public function testLevenshteinDistance(): void
    {
        // Identical strings
        $this->assertEquals(0, levenshtein('apple', 'apple'));

        // One character difference
        $this->assertEquals(1, levenshtein('apple', 'aple'));

        // Complete difference
        $this->assertEquals(5, levenshtein('apple', 'xxxxx'));
    }

    /**
     * Test similarity calculation for exact matches
     */
    public function testExactMatchSimilarity(): void
    {
        $similarity = $this->calculateSimilarity(
            'Apple MacBook Air M3 13"',
            'Apple MacBook Air M3 13"'
        );

        $this->assertEquals(1.0, $similarity);
    }

    /**
     * Test similarity calculation for similar products
     */
    public function testSimilarProductsSimilarity(): void
    {
        $testCases = [
            // Same product, different formatting
            [
                'Apple MacBook Air M3 13 inch',
                'Apple MacBook Air M3 13"',
                0.8, // Expected minimum similarity
            ],
            // Same product, different stores
            [
                'ASUS ROG Strix G16 G614JV-N4139W',
                'ASUS ROG Strix G16 (G614JV)',
                0.7,
            ],
            // Similar products with slight differences
            [
                'Samsung Galaxy S24 Ultra 256GB',
                'Samsung Galaxy S24 Ultra 512GB',
                0.85,
            ],
            // Different products
            [
                'Apple MacBook Air M3',
                'Samsung Galaxy S24',
                0.3, // Expected maximum similarity for different products
            ],
        ];

        foreach ($testCases as [$product1, $product2, $threshold]) {
            $similarity = $this->calculateSimilarity($product1, $product2);

            if ($threshold > 0.5) {
                $this->assertGreaterThanOrEqual(
                    $threshold,
                    $similarity,
                    "Expected $product1 and $product2 to have similarity >= $threshold, got $similarity"
                );
            } else {
                $this->assertLessThanOrEqual(
                    $threshold,
                    $similarity,
                    "Expected $product1 and $product2 to have similarity <= $threshold, got $similarity"
                );
            }
        }
    }

    /**
     * Test normalization of product names
     */
    public function testProductNameNormalization(): void
    {
        $testCases = [
            // Remove special characters
            ['Apple MacBook (2024)', 'apple macbook 2024'],
            // Normalize quotes
            ['Samsung 55" TV', 'samsung 55 tv'],
            // Handle Thai characters
            ['Makita สว่านไร้สาย 18V', 'makita สว่านไร้สาย 18v'],
            // Multiple spaces
            ['Product   Name   Here', 'product name here'],
        ];

        foreach ($testCases as [$input, $expected]) {
            $normalized = $this->normalizeProductName($input);
            $this->assertEquals($expected, $normalized, "Failed for: $input");
        }
    }

    /**
     * Test brand extraction
     */
    public function testBrandExtraction(): void
    {
        $testCases = [
            ['Apple MacBook Air M3', 'Apple'],
            ['Samsung Galaxy S24 Ultra', 'Samsung'],
            ['ASUS ROG Strix Gaming Laptop', 'ASUS'],
            ['Logitech G Pro X Mouse', 'Logitech'],
            ['Sony PlayStation 5', 'Sony'],
            ['Unknown Product Name', null],
        ];

        foreach ($testCases as [$productName, $expectedBrand]) {
            $brand = $this->extractBrand($productName);
            $this->assertEquals($expectedBrand, $brand, "Failed for: $productName");
        }
    }

    /**
     * Test model number extraction
     */
    public function testModelNumberExtraction(): void
    {
        $testCases = [
            ['ASUS ROG Strix G16 G614JV-N4139W', 'G614JV-N4139W'],
            ['Samsung SM-S928B Galaxy S24', 'SM-S928B'],
            ['Apple MacBook Air MBA-M3-13', 'MBA-M3-13'],
            ['Generic Product Without Model', null],
        ];

        foreach ($testCases as [$productName, $expectedModel]) {
            $model = $this->extractModelNumber($productName);
            $this->assertEquals($expectedModel, $model, "Failed for: $productName");
        }
    }

    /**
     * Helper: Generate trigrams from string
     */
    private function generateTrigrams(string $text): array
    {
        $text = strtolower($text);
        $trigrams = [];

        for ($i = 0; $i <= strlen($text) - 3; $i++) {
            $trigrams[] = substr($text, $i, 3);
        }

        return array_unique($trigrams);
    }

    /**
     * Helper: Calculate similarity between two product names
     */
    private function calculateSimilarity(string $name1, string $name2): float
    {
        $norm1 = $this->normalizeProductName($name1);
        $norm2 = $this->normalizeProductName($name2);

        // Trigram similarity (Jaccard index)
        $trigrams1 = $this->generateTrigrams($norm1);
        $trigrams2 = $this->generateTrigrams($norm2);

        if (empty($trigrams1) || empty($trigrams2)) {
            return 0.0;
        }

        $intersection = count(array_intersect($trigrams1, $trigrams2));
        $union = count(array_unique(array_merge($trigrams1, $trigrams2)));

        $trigramSimilarity = $intersection / $union;

        // Levenshtein similarity (normalized)
        $maxLen = max(strlen($norm1), strlen($norm2));
        $levDistance = levenshtein($norm1, $norm2);
        $levSimilarity = 1 - ($levDistance / $maxLen);

        // Weighted average (trigram: 60%, levenshtein: 40%)
        return ($trigramSimilarity * 0.6) + ($levSimilarity * 0.4);
    }

    /**
     * Helper: Normalize product name for comparison
     */
    private function normalizeProductName(string $name): string
    {
        // Convert to lowercase
        $name = mb_strtolower($name, 'UTF-8');

        // Remove special characters except Thai
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);

        // Normalize whitespace
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    /**
     * Helper: Extract brand from product name
     */
    private function extractBrand(string $productName): ?string
    {
        $knownBrands = [
            'Apple', 'Samsung', 'ASUS', 'Acer', 'Lenovo', 'HP', 'Dell',
            'MSI', 'Logitech', 'Razer', 'Sony', 'LG', 'Makita', 'Bosch',
            'TOA', 'Philips', 'Panasonic', 'Canon', 'Epson',
        ];

        foreach ($knownBrands as $brand) {
            if (stripos($productName, $brand) !== false) {
                return $brand;
            }
        }

        return null;
    }

    /**
     * Helper: Extract model number from product name
     */
    private function extractModelNumber(string $productName): ?string
    {
        // Pattern for model numbers (letters + numbers + optional suffix)
        $patterns = [
            '/\b([A-Z]{1,4}-?[A-Z0-9]{2,}(?:-[A-Z0-9]+)*)\b/i',
            '/\b(SM-[A-Z0-9]+)\b/i',
            '/\b([A-Z]{2,3}\d{3,}[A-Z]*(?:-[A-Z0-9]+)?)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $productName, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
