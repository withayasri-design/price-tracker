<?php

declare(strict_types=1);

namespace Modules\Scraping;

/**
 * Headless browser scraper for SPA sites (Shopee, Lazada, TikTok)
 *
 * Uses Node.js + Puppeteer for JavaScript-rendered pages.
 * Falls back to standard HTTP scraping if Node.js is not available.
 */
class HeadlessScraper
{
    private string $scriptPath;
    private string $nodePath;
    private int $timeout;

    public function __construct(
        ?string $scriptPath = null,
        ?string $nodePath = null,
        int $timeout = 30
    ) {
        $this->scriptPath = $scriptPath ?? __DIR__ . '/../../scripts/scraper.js';
        $this->nodePath = $nodePath ?? $this->detectNodePath();
        $this->timeout = $timeout;
    }

    /**
     * Check if headless scraping is available
     */
    public function isAvailable(): bool
    {
        if (empty($this->nodePath)) {
            return false;
        }

        // Check if script exists
        if (!file_exists($this->scriptPath)) {
            return false;
        }

        // Check if puppeteer is installed
        $packageJson = dirname($this->scriptPath) . '/package.json';
        if (!file_exists($packageJson)) {
            return false;
        }

        return true;
    }

    /**
     * Get setup instructions if not available
     */
    public function getSetupInstructions(): array
    {
        return [
            'title' => 'Headless Scraper Setup',
            'steps' => [
                '1. Install Node.js from https://nodejs.org/',
                '2. Open terminal in scripts folder',
                '3. Run: npm init -y',
                '4. Run: npm install puppeteer',
                '5. Restart scraping service',
            ],
            'commands' => [
                'cd ' . dirname($this->scriptPath),
                'npm init -y',
                'npm install puppeteer',
            ],
        ];
    }

    /**
     * Scrape a URL using headless browser
     *
     * @param string $platform Platform name (shopee, lazada, tiktok)
     * @param string $url Product URL
     * @return array Scraped data or error
     */
    public function scrape(string $platform, string $url): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'error' => 'Headless scraper not available. Run setup first.',
                'setup' => $this->getSetupInstructions(),
            ];
        }

        $platform = strtolower($platform);
        if (!in_array($platform, ['shopee', 'lazada', 'tiktok'])) {
            return [
                'success' => false,
                'error' => "Platform '{$platform}' is not supported for headless scraping",
            ];
        }

        // Build command
        $escapedUrl = escapeshellarg($url);
        $escapedPlatform = escapeshellarg($platform);
        $escapedScript = escapeshellarg($this->scriptPath);

        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg($this->nodePath),
            $escapedScript,
            $escapedPlatform,
            $escapedUrl
        );

        // Execute with timeout
        $output = [];
        $returnCode = 0;

        // Use proc_open for better control
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return [
                'success' => false,
                'error' => 'Failed to start headless scraper process',
            ];
        }

        // Close stdin
        fclose($pipes[0]);

        // Set non-blocking mode
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            // Read output
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            // Check if process ended
            if (!$status['running']) {
                $returnCode = $status['exitcode'];
                break;
            }

            // Check timeout
            if ((time() - $startTime) > $this->timeout) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return [
                    'success' => false,
                    'error' => 'Scraping timeout after ' . $this->timeout . ' seconds',
                ];
            }

            usleep(100000); // 100ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        // Parse output
        $result = json_decode(trim($stdout), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response from scraper',
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }

        // Detect anti-bot blocking
        if ($result['success'] && !empty($result['data'])) {
            $data = $result['data'];

            // Check for anti-bot redirect in URL
            $redirectPatterns = [
                'verify/traffic',
                'captcha',
                'security-check',
                'blocked',
                'access-denied',
            ];

            $finalUrl = $data['url'] ?? '';
            foreach ($redirectPatterns as $pattern) {
                if (stripos($finalUrl, $pattern) !== false) {
                    return [
                        'success' => false,
                        'error' => 'Anti-bot protection detected. Platform blocked the request.',
                        'blocked' => true,
                        'redirect_url' => $finalUrl,
                        'platform' => $platform,
                    ];
                }
            }

            // Check for generic block page titles
            $blockTitles = [
                'Security Check',
                'Access Denied',
                'Blocked',
                'Verification Required',
            ];

            $name = $data['name'] ?? '';
            foreach ($blockTitles as $title) {
                if (stripos($name, $title) !== false) {
                    return [
                        'success' => false,
                        'error' => 'Anti-bot protection detected. Platform blocked the request.',
                        'blocked' => true,
                        'page_title' => $name,
                        'platform' => $platform,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Convert scraped data to ScrapedProduct
     */
    public function toScrapedProduct(array $result, string $platform, string $productId, string $url): ?ScrapedProduct
    {
        if (!$result['success'] || empty($result['data'])) {
            return null;
        }

        $data = $result['data'];

        return new ScrapedProduct(
            platform: $platform,
            productId: $productId,
            url: $url,
            name: $data['name'] ?? null,
            price: $data['price'] ?? null,
            originalPrice: $data['originalPrice'] ?? null,
            imageUrl: $data['image'] ?? null,
            stockStatus: $data['stockStatus'] ?? 'unknown',
            currency: 'THB'
        );
    }

    /**
     * Detect Node.js path
     */
    private function detectNodePath(): string
    {
        // Common Node.js paths
        $paths = [
            'node', // In PATH
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
            '/usr/bin/node',
            '/usr/local/bin/node',
        ];

        foreach ($paths as $path) {
            if ($this->isExecutable($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * Check if path is executable
     */
    private function isExecutable(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Try to run node --version
            $output = shell_exec(escapeshellarg($path) . ' --version 2>&1');
            return $output !== null && strpos($output, 'v') === 0;
        }

        return is_executable($path);
    }
}
