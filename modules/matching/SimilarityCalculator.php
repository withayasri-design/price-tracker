<?php

declare(strict_types=1);

namespace Modules\Matching;

/**
 * Calculates similarity between product names for cross-platform matching.
 * Uses trigram similarity and Levenshtein distance algorithms.
 */
class SimilarityCalculator
{
    private float $nameWeight = 0.6;
    private float $brandWeight = 0.25;
    private float $attributesWeight = 0.15;

    /**
     * Calculate trigram similarity between two strings.
     * Trigrams are 3-character substrings used for fuzzy matching.
     *
     * @param string $a First string
     * @param string $b Second string
     * @return float Similarity score between 0.0 and 1.0
     */
    public function trigramSimilarity(string $a, string $b): float
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);

        if ($a === $b) {
            return 1.0;
        }

        if (strlen($a) < 3 || strlen($b) < 3) {
            // Fall back to Levenshtein for very short strings
            return $this->levenshteinSimilarity($a, $b);
        }

        $trigramsA = $this->getTrigrams($a);
        $trigramsB = $this->getTrigrams($b);

        if (empty($trigramsA) || empty($trigramsB)) {
            return 0.0;
        }

        $intersection = count(array_intersect($trigramsA, $trigramsB));
        $union = count(array_unique(array_merge($trigramsA, $trigramsB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Calculate Levenshtein similarity (normalized).
     *
     * @param string $a First string
     * @param string $b Second string
     * @return float Similarity score between 0.0 and 1.0
     */
    public function levenshteinSimilarity(string $a, string $b): float
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);

        if ($a === $b) {
            return 1.0;
        }

        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($a, $b);
        return 1.0 - ($distance / $maxLen);
    }

    /**
     * Calculate combined match score using multiple factors.
     *
     * @param string $nameA Product name from platform A
     * @param string $nameB Product name from platform B
     * @param string|null $brandA Brand from platform A
     * @param string|null $brandB Brand from platform B
     * @param array|null $attrsA Attributes from platform A (e.g., ['color' => 'black', 'size' => '256GB'])
     * @param array|null $attrsB Attributes from platform B
     * @return float Combined similarity score between 0.0 and 1.0
     */
    public function calculateMatchScore(
        string $nameA,
        string $nameB,
        ?string $brandA = null,
        ?string $brandB = null,
        ?array $attrsA = null,
        ?array $attrsB = null
    ): float {
        // Name similarity (weighted average of trigram and Levenshtein)
        $nameSimilarity = (
            $this->trigramSimilarity($nameA, $nameB) * 0.7 +
            $this->levenshteinSimilarity($nameA, $nameB) * 0.3
        );

        // Brand similarity
        $brandSimilarity = 1.0; // Default to 1.0 if no brand info
        if ($brandA !== null && $brandB !== null) {
            $brandSimilarity = $this->trigramSimilarity($brandA, $brandB);
        } elseif ($brandA !== null || $brandB !== null) {
            // One has brand, other doesn't - slight penalty
            $brandSimilarity = 0.5;
        }

        // Attributes similarity
        $attrSimilarity = 1.0; // Default to 1.0 if no attributes
        if ($attrsA !== null && $attrsB !== null && !empty($attrsA) && !empty($attrsB)) {
            $attrSimilarity = $this->calculateAttributesSimilarity($attrsA, $attrsB);
        } elseif (($attrsA !== null && !empty($attrsA)) || ($attrsB !== null && !empty($attrsB))) {
            // One has attributes, other doesn't - slight penalty
            $attrSimilarity = 0.7;
        }

        // Weighted combination
        return (
            $nameSimilarity * $this->nameWeight +
            $brandSimilarity * $this->brandWeight +
            $attrSimilarity * $this->attributesWeight
        );
    }

    /**
     * Calculate similarity between attribute sets.
     */
    private function calculateAttributesSimilarity(array $attrsA, array $attrsB): float
    {
        $commonKeys = array_intersect(array_keys($attrsA), array_keys($attrsB));

        if (empty($commonKeys)) {
            return 0.5; // No common attributes to compare
        }

        $matchCount = 0;
        foreach ($commonKeys as $key) {
            $valA = $this->normalize((string) $attrsA[$key]);
            $valB = $this->normalize((string) $attrsB[$key]);

            if ($valA === $valB) {
                $matchCount++;
            } elseif ($this->trigramSimilarity($valA, $valB) > 0.8) {
                $matchCount += 0.8;
            }
        }

        return $matchCount / count($commonKeys);
    }

    /**
     * Extract brand from product name using common patterns.
     *
     * @param string $productName
     * @return string|null Extracted brand or null
     */
    public function extractBrand(string $productName): ?string
    {
        // Common Thai e-commerce brand patterns
        $knownBrands = [
            'apple', 'samsung', 'xiaomi', 'oppo', 'vivo', 'realme', 'huawei',
            'sony', 'lg', 'panasonic', 'sharp', 'toshiba', 'hitachi',
            'dell', 'hp', 'lenovo', 'asus', 'acer', 'msi',
            'logitech', 'razer', 'steelseries', 'hyperx', 'corsair',
            'jbl', 'bose', 'marshall', 'harman kardon', 'bang & olufsen',
            'nike', 'adidas', 'puma', 'new balance', 'converse',
            'philips', 'braun', 'dyson', 'electrolux', 'bosch',
        ];

        $normalized = $this->normalize($productName);
        $words = explode(' ', $normalized);

        foreach ($words as $word) {
            if (in_array($word, $knownBrands, true)) {
                return ucfirst($word);
            }
        }

        // Try first word as brand (common pattern)
        if (!empty($words[0]) && strlen($words[0]) >= 2) {
            return ucfirst($words[0]);
        }

        return null;
    }

    /**
     * Extract key attributes from product name.
     *
     * @param string $productName
     * @return array Extracted attributes
     */
    public function extractAttributes(string $productName): array
    {
        $attrs = [];
        $text = $this->normalize($productName);

        // Storage capacity (e.g., 128GB, 256GB, 1TB)
        if (preg_match('/(\d+)\s*(gb|tb)/i', $text, $matches)) {
            $attrs['storage'] = strtoupper($matches[1] . $matches[2]);
        }

        // RAM (e.g., 8GB RAM, 16GB)
        if (preg_match('/(\d+)\s*gb\s*ram/i', $text, $matches)) {
            $attrs['ram'] = $matches[1] . 'GB';
        }

        // Screen size (e.g., 15.6", 27 inch)
        if (preg_match('/(\d+\.?\d*)\s*("|inch|นิ้ว)/i', $text, $matches)) {
            $attrs['screen_size'] = $matches[1] . '"';
        }

        // Color patterns
        $colors = [
            'black' => ['black', 'ดำ', 'noir'],
            'white' => ['white', 'ขาว', 'blanc'],
            'silver' => ['silver', 'เงิน'],
            'gold' => ['gold', 'ทอง'],
            'blue' => ['blue', 'น้ำเงิน', 'ฟ้า'],
            'red' => ['red', 'แดง'],
            'green' => ['green', 'เขียว'],
            'pink' => ['pink', 'ชมพู'],
            'purple' => ['purple', 'ม่วง'],
            'gray' => ['gray', 'grey', 'เทา'],
        ];

        foreach ($colors as $colorKey => $patterns) {
            foreach ($patterns as $pattern) {
                if (mb_stripos($text, $pattern) !== false) {
                    $attrs['color'] = $colorKey;
                    break 2;
                }
            }
        }

        return $attrs;
    }

    /**
     * Determine confidence level based on similarity score.
     *
     * @param float $score Similarity score
     * @return string One of: high, medium, low, review
     */
    public function getConfidenceLevel(float $score): string
    {
        if ($score >= 0.90) {
            return 'high';
        } elseif ($score >= 0.80) {
            return 'medium';
        } elseif ($score >= 0.65) {
            return 'low';
        }
        return 'review';
    }

    /**
     * Normalize string for comparison.
     */
    private function normalize(string $text): string
    {
        // Convert to lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Remove common filler words
        $fillers = ['the', 'a', 'an', 'and', 'or', 'for', 'with', 'ของ', 'และ', 'สำหรับ'];
        foreach ($fillers as $filler) {
            $text = preg_replace('/\b' . preg_quote($filler, '/') . '\b/u', '', $text);
        }

        // Remove special characters except spaces
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        // Collapse multiple spaces
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Generate trigrams from a string.
     */
    private function getTrigrams(string $text): array
    {
        $text = '  ' . $text . '  '; // Pad for edge trigrams
        $length = mb_strlen($text, 'UTF-8');
        $trigrams = [];

        for ($i = 0; $i <= $length - 3; $i++) {
            $trigrams[] = mb_substr($text, $i, 3, 'UTF-8');
        }

        return $trigrams;
    }

    /**
     * Set custom weights for scoring.
     */
    public function setWeights(float $name, float $brand, float $attributes): void
    {
        $total = $name + $brand + $attributes;
        $this->nameWeight = $name / $total;
        $this->brandWeight = $brand / $total;
        $this->attributesWeight = $attributes / $total;
    }
}
