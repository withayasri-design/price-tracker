<?php
/**
 * API Usage Examples
 *
 * Demonstrates how to use the Price Tracker API programmatically.
 * Run this script from the command line: php examples/api_example.php
 */

declare(strict_types=1);

// Configuration
$baseUrl = 'http://localhost:8000/api';  // Change to your server URL
$sessionCookie = '';  // Will be set after login

/**
 * Make HTTP request
 */
function apiRequest(
    string $endpoint,
    string $method = 'GET',
    array $data = [],
    ?string $cookie = null
): array {
    global $baseUrl;

    $url = $baseUrl . $endpoint;
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    // Capture cookies from response
    curl_setopt($ch, CURLOPT_HEADER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    curl_close($ch);

    // Extract session cookie if present
    $cookies = [];
    preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $headers, $matches);
    foreach ($matches[1] as $cookie) {
        $cookies[] = $cookie;
    }

    return [
        'status' => $httpCode,
        'body' => json_decode($body, true) ?? $body,
        'cookies' => implode('; ', $cookies),
    ];
}

/**
 * Print formatted output
 */
function printResult(string $title, array $result): void {
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "  $title\n";
    echo str_repeat('=', 60) . "\n";
    echo "Status: {$result['status']}\n";
    echo "Response:\n";
    if (is_array($result['body'])) {
        echo json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo $result['body'];
    }
    echo "\n";
}

// ============================================================
// Example 1: Health Check (no auth required)
// ============================================================
echo "\n🏥 Checking API Health...\n";

$result = apiRequest('/health.php');
printResult('Health Check', $result);

if ($result['status'] !== 200) {
    echo "\n❌ API is not healthy. Please check your server.\n";
    exit(1);
}

echo "\n✅ API is healthy!\n";

// ============================================================
// Example 2: Login (to get session cookie)
// ============================================================
echo "\n🔐 Logging in...\n";

// Note: In a real scenario, you would POST to a login endpoint
// For this example, we'll simulate having a session

echo "Note: This example requires manual session setup.\n";
echo "To test with authentication:\n";
echo "1. Login via browser at {$baseUrl}/../pages/login.php\n";
echo "2. Copy your PHPSESSID cookie value\n";
echo "3. Set it in this script's \$sessionCookie variable\n";

// ============================================================
// Example 3: List Products (requires auth)
// ============================================================
echo "\n📦 Listing Products...\n";

if ($sessionCookie) {
    $result = apiRequest('/products/list.php', 'GET', [], $sessionCookie);
    printResult('Product List', $result);
} else {
    echo "Skipped - no session cookie set\n";
}

// ============================================================
// Example 4: Get Price History (requires auth)
// ============================================================
echo "\n📈 Getting Price History...\n";

if ($sessionCookie) {
    $result = apiRequest('/products/history.php?product_id=1&days=30', 'GET', [], $sessionCookie);
    printResult('Price History', $result);
} else {
    echo "Skipped - no session cookie set\n";
}

// ============================================================
// Example 5: Add Product (requires auth)
// ============================================================
echo "\n➕ Adding Product Example...\n";

$productData = [
    'url' => 'https://www.jib.co.th/web/product/readProduct/12345',
    'target_price' => 25000.00,
    'label' => 'Test Product',
];

echo "Would POST to /products/add.php with:\n";
echo json_encode($productData, JSON_PRETTY_PRINT) . "\n";

if ($sessionCookie) {
    // Uncomment to actually add a product:
    // $result = apiRequest('/products/add.php', 'POST', $productData, $sessionCookie);
    // printResult('Add Product', $result);
    echo "Skipped - uncomment in code to test\n";
} else {
    echo "Skipped - no session cookie set\n";
}

// ============================================================
// Example 6: Refresh Product Price (requires auth)
// ============================================================
echo "\n🔄 Refresh Product Example...\n";

if ($sessionCookie) {
    // Uncomment to actually refresh:
    // $result = apiRequest('/products/refresh.php', 'POST', ['product_id' => 1], $sessionCookie);
    // printResult('Refresh Product', $result);
    echo "Skipped - uncomment in code to test\n";
} else {
    echo "Skipped - no session cookie set\n";
}

// ============================================================
// Example 7: List Price Events (requires auth)
// ============================================================
echo "\n📊 Listing Price Events...\n";

if ($sessionCookie) {
    $result = apiRequest('/events/list.php?limit=5', 'GET', [], $sessionCookie);
    printResult('Price Events', $result);
} else {
    echo "Skipped - no session cookie set\n";
}

// ============================================================
// Example 8: Queue Status (admin only)
// ============================================================
echo "\n⚙️ Agent Queue Status...\n";

if ($sessionCookie) {
    $result = apiRequest('/agents/queue_status.php', 'GET', [], $sessionCookie);
    printResult('Queue Status', $result);
} else {
    echo "Skipped - no session cookie set\n";
}

// ============================================================
// Summary
// ============================================================
echo "\n" . str_repeat('=', 60) . "\n";
echo "  API Examples Complete\n";
echo str_repeat('=', 60) . "\n";
echo "\nAPI Documentation: {$baseUrl}/docs.php\n";
echo "OpenAPI Spec: {$baseUrl}/../docs/openapi.yaml\n";
echo "\n";
