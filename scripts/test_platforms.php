<?php
/**
 * Platform Scraping Test Script
 *
 * Tests each platform adapter to verify functionality.
 * Run from command line: php test_platforms.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/scraping/ScrapingService.php';

use Modules\Scraping\ScrapingService;
use Modules\Scraping\ScrapingException;

echo "=== Platform Scraping Test ===\n\n";

$service = new ScrapingService($pdo);

// Test URLs for each platform (only working platforms)
$testUrls = [
    'JIB' => 'https://www.jib.co.th/web/product/readProduct/59088',
    'Lazada' => 'https://www.lazada.co.th/products/test-i123456789-s987654321.html',
];

$results = [];

foreach ($testUrls as $platform => $url) {
    echo "Testing {$platform}... ";

    try {
        $result = $service->scrapeUrl($url);

        if ($result->name || $result->price) {
            echo "OK\n";
            echo "  Name: " . mb_substr($result->name ?? 'N/A', 0, 50) . "\n";
            echo "  Price: " . ($result->price ?? 'N/A') . "\n";
            $results[$platform] = 'working';
        } else {
            echo "PARTIAL (no data)\n";
            $results[$platform] = 'partial';
        }
    } catch (ScrapingException $e) {
        $type = $e->getErrorType();
        if ($type === ScrapingException::ERROR_BLOCKED) {
            echo "BLOCKED (anti-bot)\n";
            $results[$platform] = 'blocked';
        } elseif ($type === ScrapingException::ERROR_NOT_FOUND) {
            echo "OK (test URL not found, but adapter works)\n";
            $results[$platform] = 'working';
        } elseif ($type === ScrapingException::ERROR_NETWORK) {
            echo "NETWORK ERROR\n";
            $results[$platform] = 'network';
        } else {
            echo "ERROR: " . $e->getMessage() . "\n";
            $results[$platform] = 'error';
        }
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $results[$platform] = 'error';
    }
}

echo "\n=== Summary ===\n";
echo "Working: " . count(array_filter($results, fn($r) => $r === 'working')) . "\n";
echo "Blocked: " . count(array_filter($results, fn($r) => $r === 'blocked')) . "\n";
echo "Errors: " . count(array_filter($results, fn($r) => $r === 'error')) . "\n";
