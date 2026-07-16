<?php

declare(strict_types=1);

namespace Modules\Notification;

/**
 * LINE Messaging API integration for sending notifications.
 * Supports push messages, flex messages, and rich content.
 */
class LineNotifier
{
    private string $channelAccessToken;
    private string $apiEndpoint = 'https://api.line.me/v2/bot/message';

    public function __construct(string $channelAccessToken)
    {
        $this->channelAccessToken = $channelAccessToken;
    }

    /**
     * Send a push message to a specific user.
     *
     * @param string $lineUserId LINE user ID
     * @param array $messages Array of message objects
     * @return array Response with success status
     */
    public function pushMessage(string $lineUserId, array $messages): array
    {
        $payload = [
            'to' => $lineUserId,
            'messages' => array_slice($messages, 0, 5), // LINE allows max 5 messages
        ];

        return $this->sendRequest('/push', $payload);
    }

    /**
     * Send messages to multiple users.
     *
     * @param array $lineUserIds Array of LINE user IDs (max 500)
     * @param array $messages Array of message objects
     * @return array Response with success status
     */
    public function multicast(array $lineUserIds, array $messages): array
    {
        $payload = [
            'to' => array_slice($lineUserIds, 0, 500),
            'messages' => array_slice($messages, 0, 5),
        ];

        return $this->sendRequest('/multicast', $payload);
    }

    /**
     * Build a simple text message.
     */
    public function buildTextMessage(string $text): array
    {
        return [
            'type' => 'text',
            'text' => mb_substr($text, 0, 5000), // LINE limit
        ];
    }

    /**
     * Build a price alert flex message with product info.
     *
     * @param string $productName Product name
     * @param string|null $imageUrl Product image URL
     * @param float|null $oldPrice Previous price
     * @param float $newPrice Current price
     * @param string $productUrl Link to product page
     * @param string|null $affiliateUrl Affiliate link (optional)
     * @param string $eventType Type of event (price_drop, flash_sale, etc.)
     * @param string $platform Platform name
     * @return array Flex message object
     */
    public function buildPriceAlertFlex(
        string $productName,
        ?string $imageUrl,
        ?float $oldPrice,
        float $newPrice,
        string $productUrl,
        ?string $affiliateUrl = null,
        string $eventType = 'price_drop',
        string $platform = ''
    ): array {
        // Calculate discount info
        $discountPercent = null;
        $savedAmount = null;
        if ($oldPrice !== null && $oldPrice > 0 && $newPrice < $oldPrice) {
            $discountPercent = round((($oldPrice - $newPrice) / $oldPrice) * 100, 0);
            $savedAmount = $oldPrice - $newPrice;
        }

        // Event type styling
        $headerColor = $this->getEventColor($eventType);
        $headerText = $this->getEventText($eventType);

        // Build hero image section
        $heroSection = null;
        if ($imageUrl) {
            $heroSection = [
                'type' => 'image',
                'url' => $imageUrl,
                'size' => 'full',
                'aspectRatio' => '1:1',
                'aspectMode' => 'cover',
            ];
        }

        // Build body contents
        $bodyContents = [
            // Event badge
            [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $headerText,
                        'size' => 'xs',
                        'color' => '#ffffff',
                        'weight' => 'bold',
                    ],
                ],
                'backgroundColor' => $headerColor,
                'paddingAll' => '5px',
                'cornerRadius' => '5px',
                'width' => 'fit-content',
            ],
            // Product name
            [
                'type' => 'text',
                'text' => mb_substr($productName, 0, 100),
                'weight' => 'bold',
                'size' => 'md',
                'wrap' => true,
                'margin' => 'md',
            ],
            // Platform
            [
                'type' => 'text',
                'text' => strtoupper($platform),
                'size' => 'xs',
                'color' => '#888888',
            ],
        ];

        // Price section
        $priceContents = [];

        if ($oldPrice !== null) {
            $priceContents[] = [
                'type' => 'text',
                'text' => '฿' . number_format($oldPrice, 0),
                'size' => 'sm',
                'color' => '#999999',
                'decoration' => 'line-through',
            ];
        }

        $priceContents[] = [
            'type' => 'text',
            'text' => '฿' . number_format($newPrice, 0),
            'size' => 'xxl',
            'color' => '#E53935',
            'weight' => 'bold',
        ];

        if ($discountPercent !== null) {
            $priceContents[] = [
                'type' => 'text',
                'text' => "ลด {$discountPercent}% (ประหยัด ฿" . number_format($savedAmount, 0) . ")",
                'size' => 'sm',
                'color' => '#4CAF50',
                'weight' => 'bold',
            ];
        }

        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => $priceContents,
            'margin' => 'lg',
        ];

        // Build footer with action buttons
        $buyUrl = $affiliateUrl ?? $productUrl;
        $footerContents = [
            [
                'type' => 'button',
                'style' => 'primary',
                'action' => [
                    'type' => 'uri',
                    'label' => 'ซื้อเลย',
                    'uri' => $buyUrl,
                ],
                'color' => $headerColor,
            ],
            [
                'type' => 'button',
                'style' => 'secondary',
                'action' => [
                    'type' => 'uri',
                    'label' => 'ดูประวัติราคา',
                    'uri' => $productUrl, // Link to price tracker detail page
                ],
            ],
        ];

        // Assemble flex message
        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $bodyContents,
                'paddingAll' => '15px',
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $footerContents,
                'spacing' => 'sm',
            ],
        ];

        if ($heroSection) {
            $bubble['hero'] = $heroSection;
        }

        return [
            'type' => 'flex',
            'altText' => "{$headerText}: {$productName} - ฿" . number_format($newPrice, 0),
            'contents' => $bubble,
        ];
    }

    /**
     * Build a carousel of price alerts for multiple products.
     *
     * @param array $alerts Array of alert data
     * @return array Flex carousel message
     */
    public function buildPriceAlertCarousel(array $alerts): array
    {
        $bubbles = [];

        foreach (array_slice($alerts, 0, 10) as $alert) { // LINE carousel max 10 bubbles
            $bubble = $this->buildPriceAlertFlex(
                $alert['product_name'],
                $alert['image_url'] ?? null,
                $alert['old_price'] ?? null,
                $alert['new_price'],
                $alert['product_url'],
                $alert['affiliate_url'] ?? null,
                $alert['event_type'] ?? 'price_drop',
                $alert['platform'] ?? ''
            );

            $bubbles[] = $bubble['contents'];
        }

        return [
            'type' => 'flex',
            'altText' => 'แจ้งเตือนราคาสินค้า ' . count($bubbles) . ' รายการ',
            'contents' => [
                'type' => 'carousel',
                'contents' => $bubbles,
            ],
        ];
    }

    /**
     * Build a daily summary message.
     */
    public function buildDailySummary(int $dropCount, int $flashSaleCount, float $maxSavings): array
    {
        $text = "📊 สรุปประจำวัน\n\n";
        $text .= "🔻 ราคาลด: {$dropCount} รายการ\n";
        $text .= "⚡ Flash Sale: {$flashSaleCount} รายการ\n";
        $text .= "💰 ประหยัดสูงสุด: ฿" . number_format($maxSavings, 0) . "\n\n";
        $text .= "เข้าดูรายละเอียดได้ที่ Dashboard";

        return $this->buildTextMessage($text);
    }

    /**
     * Get color code for event type.
     */
    private function getEventColor(string $eventType): string
    {
        return match ($eventType) {
            'flash_sale' => '#FF5722',
            'lowest_ever' => '#9C27B0',
            'price_drop' => '#4CAF50',
            'back_in_stock' => '#2196F3',
            'price_increase' => '#FF9800',
            default => '#607D8B',
        };
    }

    /**
     * Get display text for event type.
     */
    private function getEventText(string $eventType): string
    {
        return match ($eventType) {
            'flash_sale' => '⚡ FLASH SALE',
            'lowest_ever' => '🏆 ราคาต่ำสุด',
            'price_drop' => '🔻 ราคาลด',
            'back_in_stock' => '📦 กลับมามีสินค้า',
            'price_increase' => '📈 ราคาขึ้น',
            'out_of_stock' => '❌ สินค้าหมด',
            default => '📢 แจ้งเตือน',
        };
    }

    /**
     * Send HTTP request to LINE API.
     */
    private function sendRequest(string $endpoint, array $payload): array
    {
        $url = $this->apiEndpoint . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->channelAccessToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'error' => $error,
                'http_code' => 0,
            ];
        }

        $decoded = json_decode($response, true);

        return [
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'response' => $decoded,
        ];
    }

    /**
     * Verify LINE webhook signature.
     */
    public static function verifySignature(string $body, string $signature, string $channelSecret): bool
    {
        $hash = base64_encode(hash_hmac('sha256', $body, $channelSecret, true));
        return hash_equals($hash, $signature);
    }

    /**
     * Parse LINE user ID from webhook event.
     */
    public static function parseUserIdFromEvent(array $event): ?string
    {
        return $event['source']['userId'] ?? null;
    }
}
