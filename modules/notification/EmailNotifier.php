<?php

declare(strict_types=1);

namespace Modules\Notification;

/**
 * Email notification service for sending price alerts.
 * Supports SMTP and sendmail transports.
 */
class EmailNotifier
{
    private string $fromEmail;
    private string $fromName;
    private ?string $smtpHost;
    private ?int $smtpPort;
    private ?string $smtpUser;
    private ?string $smtpPassword;
    private bool $smtpSecure;

    public function __construct(
        string $fromEmail,
        string $fromName = 'Price Tracker',
        ?string $smtpHost = null,
        ?int $smtpPort = null,
        ?string $smtpUser = null,
        ?string $smtpPassword = null,
        bool $smtpSecure = true
    ) {
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
        $this->smtpHost = $smtpHost;
        $this->smtpPort = $smtpPort ?? 587;
        $this->smtpUser = $smtpUser;
        $this->smtpPassword = $smtpPassword;
        $this->smtpSecure = $smtpSecure;
    }

    /**
     * Create from system settings.
     */
    public static function fromSettings(\PDO $pdo): self
    {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'email_%'");
        $settings = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return new self(
            $settings['email_from_address'] ?? 'noreply@pricetracker.local',
            $settings['email_from_name'] ?? 'Price Tracker',
            $settings['email_smtp_host'] ?? null,
            isset($settings['email_smtp_port']) ? (int) $settings['email_smtp_port'] : null,
            $settings['email_smtp_user'] ?? null,
            $settings['email_smtp_password'] ?? null,
            ($settings['email_smtp_secure'] ?? 'tls') !== 'none'
        );
    }

    /**
     * Send a price alert email.
     *
     * @param string $to Recipient email
     * @param string $productName Product name
     * @param string|null $imageUrl Product image URL
     * @param float|null $oldPrice Previous price
     * @param float $newPrice Current price
     * @param string $productUrl Link to product
     * @param string $eventType Type of event
     * @param string $platform Platform name
     * @return array Response with success status
     */
    public function sendPriceAlert(
        string $to,
        string $productName,
        ?string $imageUrl,
        ?float $oldPrice,
        float $newPrice,
        string $productUrl,
        string $eventType = 'price_drop',
        string $platform = ''
    ): array {
        $subject = $this->getSubjectForEvent($eventType, $productName, $newPrice);
        $html = $this->buildPriceAlertHtml(
            $productName,
            $imageUrl,
            $oldPrice,
            $newPrice,
            $productUrl,
            $eventType,
            $platform
        );

        return $this->send($to, $subject, $html);
    }

    /**
     * Send multiple price alerts in a single digest email.
     *
     * @param string $to Recipient email
     * @param array $alerts Array of alert data
     * @return array Response with success status
     */
    public function sendPriceAlertDigest(string $to, array $alerts): array
    {
        $subject = sprintf('Price Tracker: %d รายการที่คุณติดตามมีการเปลี่ยนแปลง', count($alerts));
        $html = $this->buildDigestHtml($alerts);

        return $this->send($to, $subject, $html);
    }

    /**
     * Send a daily summary email.
     */
    public function sendDailySummary(
        string $to,
        int $dropCount,
        int $flashSaleCount,
        float $maxSavings,
        array $topDeals = []
    ): array {
        $subject = 'Price Tracker: สรุปประจำวัน';
        $html = $this->buildDailySummaryHtml($dropCount, $flashSaleCount, $maxSavings, $topDeals);

        return $this->send($to, $subject, $html);
    }

    /**
     * Send email using configured transport.
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $htmlBody HTML content
     * @return array Response with success status
     */
    public function send(string $to, string $subject, string $htmlBody): array
    {
        // Validate email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address'];
        }

        // Use SMTP if configured, otherwise use mail()
        if ($this->smtpHost && $this->smtpUser) {
            return $this->sendViaSmtp($to, $subject, $htmlBody);
        }

        return $this->sendViaMail($to, $subject, $htmlBody);
    }

    /**
     * Send via PHP mail() function.
     */
    private function sendViaMail(string $to, string $subject, string $htmlBody): array
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->formatAddress($this->fromEmail, $this->fromName),
            'Reply-To: ' . $this->fromEmail,
            'X-Mailer: PriceTracker/1.0',
        ];

        $success = mail(
            $to,
            '=?UTF-8?B?' . base64_encode($subject) . '?=',
            $htmlBody,
            implode("\r\n", $headers)
        );

        return [
            'success' => $success,
            'error' => $success ? null : 'mail() returned false',
        ];
    }

    /**
     * Send via SMTP.
     */
    private function sendViaSmtp(string $to, string $subject, string $htmlBody): array
    {
        $socket = @fsockopen(
            ($this->smtpSecure ? 'tls://' : '') . $this->smtpHost,
            $this->smtpPort,
            $errno,
            $errstr,
            30
        );

        if (!$socket) {
            return ['success' => false, 'error' => "SMTP connection failed: {$errstr}"];
        }

        try {
            // Read greeting
            $this->smtpRead($socket);

            // EHLO
            $this->smtpWrite($socket, 'EHLO ' . gethostname());
            $this->smtpRead($socket);

            // AUTH LOGIN
            $this->smtpWrite($socket, 'AUTH LOGIN');
            $this->smtpRead($socket);

            $this->smtpWrite($socket, base64_encode($this->smtpUser));
            $this->smtpRead($socket);

            $this->smtpWrite($socket, base64_encode($this->smtpPassword));
            $response = $this->smtpRead($socket);

            if (!str_starts_with($response, '235')) {
                throw new \RuntimeException('SMTP authentication failed');
            }

            // MAIL FROM
            $this->smtpWrite($socket, 'MAIL FROM:<' . $this->fromEmail . '>');
            $this->smtpRead($socket);

            // RCPT TO
            $this->smtpWrite($socket, 'RCPT TO:<' . $to . '>');
            $this->smtpRead($socket);

            // DATA
            $this->smtpWrite($socket, 'DATA');
            $this->smtpRead($socket);

            // Message headers and body
            $message = $this->buildSmtpMessage($to, $subject, $htmlBody);
            $this->smtpWrite($socket, $message . "\r\n.");
            $this->smtpRead($socket);

            // QUIT
            $this->smtpWrite($socket, 'QUIT');
            fclose($socket);

            return ['success' => true, 'error' => null];

        } catch (\Throwable $e) {
            @fclose($socket);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Write to SMTP socket.
     */
    private function smtpWrite($socket, string $data): void
    {
        fwrite($socket, $data . "\r\n");
    }

    /**
     * Read from SMTP socket.
     */
    private function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }

    /**
     * Build SMTP message with headers.
     */
    private function buildSmtpMessage(string $to, string $subject, string $htmlBody): string
    {
        $boundary = md5(uniqid((string) time()));

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($this->fromEmail, $this->fromName),
            'To: ' . $to,
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: PriceTracker/1.0',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody));
    }

    /**
     * Format email address with name.
     */
    private function formatAddress(string $email, string $name): string
    {
        if (empty($name)) {
            return $email;
        }
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    /**
     * Get subject line for event type.
     */
    private function getSubjectForEvent(string $eventType, string $productName, float $price): string
    {
        $shortName = mb_substr($productName, 0, 50);
        if (mb_strlen($productName) > 50) {
            $shortName .= '...';
        }

        return match ($eventType) {
            'flash_sale' => "⚡ Flash Sale! {$shortName} - ฿" . number_format($price, 0),
            'lowest_ever' => "🏆 ราคาต่ำสุดเท่าที่เคยมี! {$shortName}",
            'price_drop' => "🔻 ราคาลดแล้ว! {$shortName} - ฿" . number_format($price, 0),
            'back_in_stock' => "📦 สินค้ากลับมามี! {$shortName}",
            'price_increase' => "📈 ราคาเพิ่มขึ้น: {$shortName}",
            default => "📢 แจ้งเตือน: {$shortName}",
        };
    }

    /**
     * Build HTML email for price alert.
     */
    private function buildPriceAlertHtml(
        string $productName,
        ?string $imageUrl,
        ?float $oldPrice,
        float $newPrice,
        string $productUrl,
        string $eventType,
        string $platform
    ): string {
        $eventConfig = $this->getEventConfig($eventType);
        $discountHtml = '';

        if ($oldPrice !== null && $oldPrice > 0 && $newPrice < $oldPrice) {
            $discountPercent = round((($oldPrice - $newPrice) / $oldPrice) * 100, 0);
            $savedAmount = $oldPrice - $newPrice;
            $discountHtml = "
                <p style=\"color: #4CAF50; font-weight: bold; margin: 0;\">
                    ลด {$discountPercent}% (ประหยัด ฿" . number_format($savedAmount, 0) . ")
                </p>";
        }

        $imageHtml = '';
        if ($imageUrl) {
            $imageHtml = "
                <div style=\"text-align: center; margin-bottom: 20px;\">
                    <img src=\"{$imageUrl}\" alt=\"\" style=\"max-width: 200px; max-height: 200px; border-radius: 8px;\">
                </div>";
        }

        $oldPriceHtml = '';
        if ($oldPrice !== null) {
            $oldPriceHtml = "<span style=\"text-decoration: line-through; color: #999; font-size: 16px;\">฿" . number_format($oldPrice, 2) . "</span>";
        }

        return $this->wrapInTemplate("
            <div style=\"background: {$eventConfig['color']}; color: white; padding: 15px; text-align: center; border-radius: 8px 8px 0 0;\">
                <h2 style=\"margin: 0;\">{$eventConfig['icon']} {$eventConfig['text']}</h2>
            </div>
            <div style=\"padding: 25px;\">
                {$imageHtml}
                <h3 style=\"margin: 0 0 10px 0; color: #333;\">" . htmlspecialchars($productName) . "</h3>
                <p style=\"color: #666; font-size: 14px; margin: 0 0 20px 0;\">" . strtoupper($platform) . "</p>

                <div style=\"background: #f5f5f5; padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px;\">
                    {$oldPriceHtml}
                    <div style=\"color: #E53935; font-size: 32px; font-weight: bold; margin: 5px 0;\">
                        ฿" . number_format($newPrice, 2) . "
                    </div>
                    {$discountHtml}
                </div>

                <div style=\"text-align: center;\">
                    <a href=\"{$productUrl}\" style=\"display: inline-block; background: {$eventConfig['color']}; color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; font-weight: bold;\">
                        ดูสินค้า
                    </a>
                </div>
            </div>
        ");
    }

    /**
     * Build HTML for digest email.
     */
    private function buildDigestHtml(array $alerts): string
    {
        $itemsHtml = '';

        foreach ($alerts as $alert) {
            $eventConfig = $this->getEventConfig($alert['event_type'] ?? 'price_drop');
            $oldPriceHtml = '';

            if (!empty($alert['old_price']) && $alert['old_price'] > 0) {
                $oldPriceHtml = "<span style=\"text-decoration: line-through; color: #999;\">฿" . number_format($alert['old_price'], 0) . "</span> → ";
            }

            $itemsHtml .= "
                <tr>
                    <td style=\"padding: 15px; border-bottom: 1px solid #eee;\">
                        <span style=\"background: {$eventConfig['color']}; color: white; padding: 3px 8px; border-radius: 4px; font-size: 12px;\">
                            {$eventConfig['icon']}
                        </span>
                    </td>
                    <td style=\"padding: 15px; border-bottom: 1px solid #eee;\">
                        <a href=\"" . htmlspecialchars($alert['product_url']) . "\" style=\"color: #333; text-decoration: none; font-weight: bold;\">
                            " . htmlspecialchars(mb_substr($alert['product_name'], 0, 60)) . "
                        </a>
                        <br>
                        <small style=\"color: #666;\">" . strtoupper($alert['platform'] ?? '') . "</small>
                    </td>
                    <td style=\"padding: 15px; border-bottom: 1px solid #eee; text-align: right;\">
                        {$oldPriceHtml}
                        <strong style=\"color: #E53935;\">฿" . number_format($alert['new_price'], 0) . "</strong>
                    </td>
                </tr>";
        }

        return $this->wrapInTemplate("
            <div style=\"background: #333; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;\">
                <h2 style=\"margin: 0;\">📢 แจ้งเตือนราคาสินค้า</h2>
                <p style=\"margin: 10px 0 0 0; opacity: 0.8;\">" . count($alerts) . " รายการที่คุณติดตาม</p>
            </div>
            <div style=\"padding: 0;\">
                <table style=\"width: 100%; border-collapse: collapse;\">
                    {$itemsHtml}
                </table>
            </div>
        ");
    }

    /**
     * Build HTML for daily summary.
     */
    private function buildDailySummaryHtml(
        int $dropCount,
        int $flashSaleCount,
        float $maxSavings,
        array $topDeals
    ): string {
        $dealsHtml = '';

        if (!empty($topDeals)) {
            $dealsHtml = '<h3 style="margin: 20px 0 10px 0;">🔥 Top Deals วันนี้</h3><ul style="padding-left: 20px;">';
            foreach (array_slice($topDeals, 0, 5) as $deal) {
                $dealsHtml .= '<li style="margin: 8px 0;">
                    <a href="' . htmlspecialchars($deal['url']) . '" style="color: #333;">'
                    . htmlspecialchars(mb_substr($deal['name'], 0, 50))
                    . '</a> - <strong style="color: #E53935;">฿' . number_format($deal['price'], 0) . '</strong>
                </li>';
            }
            $dealsHtml .= '</ul>';
        }

        return $this->wrapInTemplate("
            <div style=\"background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;\">
                <h2 style=\"margin: 0;\">📊 สรุปประจำวัน</h2>
                <p style=\"margin: 10px 0 0 0; opacity: 0.9;\">" . date('d/m/Y') . "</p>
            </div>
            <div style=\"padding: 25px;\">
                <div style=\"display: flex; justify-content: space-around; text-align: center; margin-bottom: 20px;\">
                    <div style=\"flex: 1; padding: 15px;\">
                        <div style=\"font-size: 32px; font-weight: bold; color: #4CAF50;\">{$dropCount}</div>
                        <div style=\"color: #666;\">🔻 ราคาลด</div>
                    </div>
                    <div style=\"flex: 1; padding: 15px;\">
                        <div style=\"font-size: 32px; font-weight: bold; color: #FF5722;\">{$flashSaleCount}</div>
                        <div style=\"color: #666;\">⚡ Flash Sale</div>
                    </div>
                    <div style=\"flex: 1; padding: 15px;\">
                        <div style=\"font-size: 32px; font-weight: bold; color: #2196F3;\">฿" . number_format($maxSavings, 0) . "</div>
                        <div style=\"color: #666;\">💰 ประหยัดสูงสุด</div>
                    </div>
                </div>
                {$dealsHtml}
            </div>
        ");
    }

    /**
     * Get event configuration.
     */
    private function getEventConfig(string $eventType): array
    {
        return match ($eventType) {
            'flash_sale' => ['color' => '#FF5722', 'icon' => '⚡', 'text' => 'FLASH SALE'],
            'lowest_ever' => ['color' => '#9C27B0', 'icon' => '🏆', 'text' => 'ราคาต่ำสุด'],
            'price_drop' => ['color' => '#4CAF50', 'icon' => '🔻', 'text' => 'ราคาลด'],
            'back_in_stock' => ['color' => '#2196F3', 'icon' => '📦', 'text' => 'กลับมามีสินค้า'],
            'price_increase' => ['color' => '#FF9800', 'icon' => '📈', 'text' => 'ราคาขึ้น'],
            default => ['color' => '#607D8B', 'icon' => '📢', 'text' => 'แจ้งเตือน'],
        };
    }

    /**
     * Wrap content in email template.
     */
    private function wrapInTemplate(string $content): string
    {
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
</head>
<body style=\"margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;\">
    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"padding: 20px;\">
        <tr>
            <td align=\"center\">
                <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);\">
                    <tr>
                        <td>
                            {$content}
                        </td>
                    </tr>
                    <tr>
                        <td style=\"padding: 20px; text-align: center; background: #f9f9f9; border-top: 1px solid #eee;\">
                            <p style=\"margin: 0; color: #999; font-size: 12px;\">
                                คุณได้รับอีเมลนี้เพราะเปิดใช้การแจ้งเตือนทางอีเมลใน Price Tracker<br>
                                <a href=\"#\" style=\"color: #666;\">ยกเลิกการรับอีเมล</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
    }
}
