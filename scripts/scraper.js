/**
 * Puppeteer-based scraper for SPA sites (Shopee, Lazada, TikTok)
 *
 * Usage: node scraper.js <platform> <url>
 * Output: JSON with product data
 */

const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');

// Use stealth plugin to avoid detection
puppeteer.use(StealthPlugin());

const platforms = {
    shopee: {
        homepage: 'https://shopee.co.th',
        mobile: true, // Use mobile user agent for better success
        selectors: {
            name: '[data-sqe="name"], [class*="product-title"], [class*="attM6y"], h1, .product-name',
            price: '[class*="pmmxKx"], [class*="HVuiK7"], [class*="price"], span[class*="price"], .product-price',
            originalPrice: '[class*="original-price"], [class*="before-discount"], del',
            image: '[class*="product-image"] img, [class*="image-viewer"] img, picture img, .product-img img',
            stock: '[class*="stock"], [class*="quantity"], [class*="items-left"]'
        },
        waitFor: 'body',
        timeout: 30000
    },
    lazada: {
        homepage: 'https://www.lazada.co.th',
        selectors: {
            name: '.pdp-mod-product-badge-title, .pdp-product-title, h1[class*="title"], title',
            price: '.pdp-price_type_normal, .pdp-price, [class*="price-current"], span[class*="price"]',
            originalPrice: '.pdp-price_type_deleted, .origin-price, del',
            image: '.pdp-mod-common-image img, .gallery-preview-panel__image img, picture img',
            stock: '[class*="stock"], [class*="availability"], .quantity-content'
        },
        waitFor: 'body',
        timeout: 30000
    },
    tiktok: {
        homepage: 'https://www.tiktok.com',
        selectors: {
            name: '[class*="product-title"], h1, [class*="title"]',
            price: '[class*="price-current"], [class*="sale-price"], [class*="price"]',
            originalPrice: '[class*="original-price"], [class*="price-original"], del',
            image: '[class*="product-image"] img, picture img',
            stock: '[class*="stock"], [class*="inventory"]'
        },
        waitFor: 'body',
        timeout: 30000
    }
};

async function scrape(platform, url) {
    const config = platforms[platform];
    if (!config) {
        throw new Error(`Unknown platform: ${platform}`);
    }

    const browser = await puppeteer.launch({
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-accelerated-2d-canvas',
            '--disable-gpu',
            '--window-size=1920,1080',
            '--disable-blink-features=AutomationControlled',
            '--disable-infobars',
            '--lang=th-TH,th',
            '--start-maximized'
        ]
    });

    try {
        const page = await browser.newPage();

        // Use mobile or desktop user agent based on platform config
        if (config.mobile) {
            // Mobile user agent (iPhone)
            await page.setUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1');
            await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
            await page.setExtraHTTPHeaders({
                'Accept-Language': 'th-TH,th;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            });
        } else {
            // Desktop user agent (Chrome 125 on Windows)
            await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36');
            await page.setViewport({ width: 1920, height: 1080 });
            await page.setExtraHTTPHeaders({
                'Accept-Language': 'th-TH,th;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'sec-ch-ua': '"Google Chrome";v="125", "Chromium";v="125", "Not.A/Brand";v="24"',
                'sec-ch-ua-mobile': '?0',
                'sec-ch-ua-platform': '"Windows"'
            });
        }

        // Visit homepage first to establish session/cookies
        if (config.homepage) {
            try {
                await page.goto(config.homepage, {
                    waitUntil: 'domcontentloaded',
                    timeout: 15000
                });

                // Wait a bit for cookies to set
                await new Promise(resolve => setTimeout(resolve, 2000 + Math.random() * 1000));

                // Try to close any popups/modals
                await page.evaluate(() => {
                    // Common close button selectors
                    const closeSelectors = [
                        '[class*="close"]', '[class*="dismiss"]',
                        'button[aria-label="close"]', '[class*="modal"] button'
                    ];
                    for (const sel of closeSelectors) {
                        try {
                            const btn = document.querySelector(sel);
                            if (btn && btn.offsetParent !== null) btn.click();
                        } catch (e) {}
                    }
                });

                await new Promise(resolve => setTimeout(resolve, 1000));
            } catch (e) {
                // Continue even if homepage fails
            }
        }

        // Navigate to product URL
        await page.goto(url, {
            waitUntil: 'networkidle0',
            timeout: config.timeout
        });

        // Wait for main content
        try {
            await page.waitForSelector(config.waitFor, { timeout: 10000 });
        } catch (e) {
            // Continue anyway, some elements might be loaded
        }

        // Random delay (2-4 seconds) to appear more human
        const randomDelay = 2000 + Math.floor(Math.random() * 2000);
        await new Promise(resolve => setTimeout(resolve, randomDelay));

        // Simulate human behavior - scroll down slightly
        await page.evaluate(() => {
            window.scrollBy(0, 300 + Math.random() * 200);
        });
        await new Promise(resolve => setTimeout(resolve, 500 + Math.random() * 500));

        // Extract data
        const data = await page.evaluate((selectors) => {
            const getText = (selector) => {
                try {
                    const el = document.querySelector(selector);
                    return el ? el.textContent.trim() : null;
                } catch (e) {
                    return null;
                }
            };

            const getImage = (selector) => {
                try {
                    const el = document.querySelector(selector);
                    return el ? (el.src || el.getAttribute('data-src') || el.getAttribute('srcset')?.split(' ')[0]) : null;
                } catch (e) {
                    return null;
                }
            };

            const parsePrice = (text) => {
                if (!text) return null;
                // Remove currency symbols and extract number
                const match = text.replace(/[^\d.,]/g, '').replace(/,/g, '');
                return match ? parseFloat(match) : null;
            };

            // Try multiple selectors
            const trySelectors = (selectorsStr, extractor = getText) => {
                const selectorList = selectorsStr.split(',').map(s => s.trim());
                for (const selector of selectorList) {
                    try {
                        const result = extractor(selector);
                        if (result && result.trim && result.trim() !== '') return result;
                        if (result && typeof result === 'string') return result;
                    } catch (e) {
                        // Continue to next selector
                    }
                }
                return null;
            };

            // Try selectors first
            let name = trySelectors(selectors.name);
            let priceText = trySelectors(selectors.price);
            let originalPriceText = trySelectors(selectors.originalPrice);
            let image = trySelectors(selectors.image, getImage);
            let stockText = trySelectors(selectors.stock);

            // Fallback: Try meta tags
            if (!name) {
                const ogTitle = document.querySelector('meta[property="og:title"]');
                if (ogTitle) name = ogTitle.getAttribute('content');
            }
            if (!name) {
                const titleEl = document.querySelector('title');
                if (titleEl) name = titleEl.textContent.replace(/\s*[-|].*$/, '').trim();
            }

            if (!image) {
                const ogImage = document.querySelector('meta[property="og:image"]');
                if (ogImage) image = ogImage.getAttribute('content');
            }

            // Fallback: Try to find price in JSON-LD
            if (!priceText) {
                const jsonLd = document.querySelector('script[type="application/ld+json"]');
                if (jsonLd) {
                    try {
                        const data = JSON.parse(jsonLd.textContent);
                        if (data.offers?.price) priceText = String(data.offers.price);
                        else if (data.price) priceText = String(data.price);
                    } catch (e) {}
                }
            }

            // Fallback: Try Next.js data
            if (!name || !priceText) {
                const nextData = document.querySelector('#__NEXT_DATA__');
                if (nextData) {
                    try {
                        const data = JSON.parse(nextData.textContent);
                        const props = data?.props?.pageProps;
                        if (props?.product) {
                            if (!name) name = props.product.name || props.product.title;
                            if (!priceText && props.product.price) priceText = String(props.product.price);
                        }
                    } catch (e) {}
                }
            }

            // Fallback: Search all script tags for product data
            if (!name || !priceText) {
                const scripts = document.querySelectorAll('script');
                for (const script of scripts) {
                    const text = script.textContent || '';
                    // Look for JSON with product data
                    const patterns = [
                        /"title"\s*:\s*"([^"]+)"/,
                        /"name"\s*:\s*"([^"]+)"/,
                        /"product_name"\s*:\s*"([^"]+)"/
                    ];
                    for (const pattern of patterns) {
                        if (!name) {
                            const match = text.match(pattern);
                            if (match && match[1].length > 5 && match[1].length < 200) {
                                name = match[1];
                                break;
                            }
                        }
                    }
                    if (!priceText) {
                        const priceMatch = text.match(/"price"\s*:\s*"?(\d+(?:\.\d+)?)"?/);
                        if (priceMatch) priceText = priceMatch[1];
                    }
                }
            }

            return {
                name,
                price: parsePrice(priceText),
                priceText,
                originalPrice: parsePrice(originalPriceText),
                originalPriceText,
                image,
                stockStatus: stockText || 'unknown',
                url: window.location.href
            };
        }, config.selectors);

        return {
            success: true,
            data,
            scrapedAt: new Date().toISOString()
        };

    } catch (error) {
        return {
            success: false,
            error: error.message,
            scrapedAt: new Date().toISOString()
        };
    } finally {
        await browser.close();
    }
}

// Main execution
const args = process.argv.slice(2);
if (args.length < 2) {
    console.error(JSON.stringify({
        success: false,
        error: 'Usage: node scraper.js <platform> <url>'
    }));
    process.exit(1);
}

const [platform, url] = args;

scrape(platform, url)
    .then(result => {
        console.log(JSON.stringify(result, null, 2));
    })
    .catch(error => {
        console.error(JSON.stringify({
            success: false,
            error: error.message
        }));
        process.exit(1);
    });
