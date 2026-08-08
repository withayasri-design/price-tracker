<?php

/**
 * Affiliate Service
 *
 * Generates affiliate links for supported platforms.
 * Supports: Lazada, AccessTrade (for JIB and other Thai retailers)
 */

declare(strict_types=1);

namespace Modules\Affiliate;

use PDO;

class AffiliateService
{
    private PDO $pdo;
    private array $settings = [];

    // Supported affiliate programs
    public const PROGRAM_LAZADA = 'lazada';
    public const PROGRAM_ACCESSTRADE = 'accesstrade';
    public const PROGRAM_NONE = 'none';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->loadSettings();
    }

    /**
     * Load affiliate settings from database.
     */
    private function loadSettings(): void
    {
        $stmt = $this->pdo->query("
            SELECT setting_key, setting_value
            FROM system_settings
            WHERE setting_key LIKE 'affiliate_%'
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = str_replace('affiliate_', '', $row['setting_key']);
            $this->settings[$key] = $row['setting_value'];
        }
    }

    /**
     * Get setting value.
     */
    public function getSetting(string $key, ?string $default = null): ?string
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Check if affiliate program is enabled.
     */
    public function isEnabled(string $program): bool
    {
        return !empty($this->settings[$program . '_enabled'])
            && $this->settings[$program . '_enabled'] === '1';
    }

    /**
     * Generate affiliate link for a product URL.
     */
    public function generateAffiliateLink(string $url, string $platform): array
    {
        // Determine which affiliate program to use
        $program = $this->getAffiliateProgram($platform);

        if ($program === self::PROGRAM_NONE) {
            return [
                'success' => false,
                'url' => $url,
                'program' => self::PROGRAM_NONE,
                'message' => 'No affiliate program available for this platform',
            ];
        }

        // Generate affiliate link based on program
        switch ($program) {
            case self::PROGRAM_LAZADA:
                return $this->generateLazadaLink($url);

            case self::PROGRAM_ACCESSTRADE:
                return $this->generateAccessTradeLink($url, $platform);

            default:
                return [
                    'success' => false,
                    'url' => $url,
                    'program' => self::PROGRAM_NONE,
                    'message' => 'Unknown affiliate program',
                ];
        }
    }

    /**
     * Get affiliate program for a platform.
     */
    public function getAffiliateProgram(string $platform): string
    {
        switch ($platform) {
            case 'lazada':
                if ($this->isEnabled('lazada')) {
                    return self::PROGRAM_LAZADA;
                }
                break;

            case 'jib':
                // JIB uses AccessTrade
                if ($this->isEnabled('accesstrade')) {
                    return self::PROGRAM_ACCESSTRADE;
                }
                break;
        }

        return self::PROGRAM_NONE;
    }

    /**
     * Generate Lazada affiliate link.
     * Uses Lazada Affiliate Program format.
     */
    private function generateLazadaLink(string $url): array
    {
        $partnerId = $this->getSetting('lazada_partner_id');
        $trackingId = $this->getSetting('lazada_tracking_id');

        if (empty($partnerId)) {
            return [
                'success' => false,
                'url' => $url,
                'program' => self::PROGRAM_LAZADA,
                'message' => 'Lazada partner ID not configured',
            ];
        }

        // Lazada affiliate link format
        // https://c.lazada.co.th/t/c.{partner_id}?url={encoded_url}&sub_aff_id={tracking}
        $affiliateUrl = sprintf(
            'https://c.lazada.co.th/t/c.%s?url=%s',
            $partnerId,
            urlencode($url)
        );

        if (!empty($trackingId)) {
            $affiliateUrl .= '&sub_aff_id=' . urlencode($trackingId);
        }

        return [
            'success' => true,
            'url' => $affiliateUrl,
            'program' => self::PROGRAM_LAZADA,
            'original_url' => $url,
        ];
    }

    /**
     * Generate AccessTrade affiliate link.
     * Used for JIB and other Thai retailers.
     */
    private function generateAccessTradeLink(string $url, string $platform): array
    {
        $publisherId = $this->getSetting('accesstrade_publisher_id');
        $campaignId = $this->getSetting('accesstrade_' . $platform . '_campaign_id');

        if (empty($publisherId)) {
            return [
                'success' => false,
                'url' => $url,
                'program' => self::PROGRAM_ACCESSTRADE,
                'message' => 'AccessTrade publisher ID not configured',
            ];
        }

        // AccessTrade affiliate link format
        // https://publisher.accesstrade.co.th/deep_link/?pid={publisher_id}&offer_id={campaign_id}&url={encoded_url}
        $affiliateUrl = sprintf(
            'https://publisher.accesstrade.co.th/deep_link/?pid=%s&url=%s',
            $publisherId,
            urlencode($url)
        );

        if (!empty($campaignId)) {
            $affiliateUrl .= '&offer_id=' . urlencode($campaignId);
        }

        return [
            'success' => true,
            'url' => $affiliateUrl,
            'program' => self::PROGRAM_ACCESSTRADE,
            'original_url' => $url,
        ];
    }

    /**
     * Update product affiliate URL in database.
     */
    public function updateProductAffiliateUrl(int $productId, string $affiliateUrl, string $program): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE tracked_products
            SET affiliate_url = :url, affiliate_program = :program
            WHERE product_id = :id
        ");

        return $stmt->execute([
            'url' => $affiliateUrl,
            'program' => $program,
            'id' => $productId,
        ]);
    }

    /**
     * Generate affiliate links for all products without one.
     */
    public function generateMissingAffiliateLinks(): array
    {
        $stmt = $this->pdo->query("
            SELECT product_id, platform, product_url
            FROM tracked_products
            WHERE (affiliate_url IS NULL OR affiliate_url = '')
            AND is_active = 1
        ");

        $results = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        while ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results['total']++;

            $result = $this->generateAffiliateLink($product['product_url'], $product['platform']);

            if ($result['success']) {
                $this->updateProductAffiliateUrl(
                    (int) $product['product_id'],
                    $result['url'],
                    $result['program']
                );
                $results['success']++;
            } elseif ($result['program'] === self::PROGRAM_NONE) {
                $results['skipped']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Get supported affiliate programs.
     */
    public function getSupportedPrograms(): array
    {
        return [
            self::PROGRAM_LAZADA => [
                'name' => 'Lazada Affiliate',
                'platforms' => ['lazada'],
                'enabled' => $this->isEnabled('lazada'),
            ],
            self::PROGRAM_ACCESSTRADE => [
                'name' => 'AccessTrade',
                'platforms' => ['jib'],
                'enabled' => $this->isEnabled('accesstrade'),
            ],
        ];
    }
}
