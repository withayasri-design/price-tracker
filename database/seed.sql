-- Price Tracker - Sample Seed Data
-- Run after schema.sql to populate test data

-- Sample admin user (password: admin123)
INSERT INTO users (email, password_hash, full_name, role, is_active, notify_email, notify_line, created_at) VALUES
('admin@pricetracker.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin User', 'admin', 1, 1, 0, NOW());

-- Sample regular user (password: user123)
INSERT INTO users (email, password_hash, full_name, role, is_active, notify_email, notify_line, created_at) VALUES
('user@pricetracker.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Test User', 'user', 1, 1, 0, NOW());

-- System settings
INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES
('cron_interval_minutes', '180', NOW()),
('rate_limit_per_minute_jib', '15', NOW()),
('rate_limit_per_minute_banana', '15', NOW()),
('rate_limit_per_minute_advice', '15', NOW()),
('rate_limit_per_minute_globalhouse', '12', NOW()),
('rate_limit_per_minute_homepro', '12', NOW()),
('rate_limit_per_minute_thaiwatsadu', '12', NOW()),
('rate_limit_per_minute_powerbuy', '12', NOW()),
('agent_scraper_batch_size', '10', NOW()),
('agent_cleaning_similarity_threshold', '0.7', NOW()),
('agent_pricediff_significant_change_percent', '5', NOW()),
('agent_dispatch_batch_delay_seconds', '60', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW();

-- Sample tracked products (JIB)
INSERT INTO tracked_products (platform, platform_product_id, product_url, product_name, image_url, last_price, last_original_price, last_stock_status, is_active, created_at) VALUES
('jib', '48622', 'https://www.jib.co.th/web/product/readProduct/48622', 'ASUS ROG Strix G16 G614JV-N4139W', 'https://www.jib.co.th/img_master/product/original/2023072010361848622_1.jpg', 49990.00, 54990.00, 'in_stock', 1, NOW()),
('jib', '50123', 'https://www.jib.co.th/web/product/readProduct/50123', 'Logitech G Pro X Superlight 2', 'https://www.jib.co.th/img_master/product/original/50123_1.jpg', 4990.00, 5290.00, 'in_stock', 1, NOW());

-- Sample tracked products (Banana)
INSERT INTO tracked_products (platform, platform_product_id, product_url, product_name, image_url, last_price, last_original_price, last_stock_status, is_active, created_at) VALUES
('banana', 'p-123456', 'https://www.bananait.co.th/product/p-123456', 'Apple MacBook Air M3 13"', NULL, 42900.00, 44900.00, 'in_stock', 1, NOW());

-- Sample tracked products (Advice)
INSERT INTO tracked_products (platform, platform_product_id, product_url, product_name, image_url, last_price, last_original_price, last_stock_status, is_active, created_at) VALUES
('advice', 'A78901', 'https://www.advice.co.th/product/A78901', 'Samsung Galaxy S24 Ultra 256GB', NULL, 41900.00, 46900.00, 'in_stock', 1, NOW());

-- Sample tracked products (Home improvement)
INSERT INTO tracked_products (platform, platform_product_id, product_url, product_name, image_url, last_price, last_original_price, last_stock_status, is_active, created_at) VALUES
('homepro', 'hp-556677', 'https://www.homepro.co.th/p/hp-556677', 'Makita สว่านไร้สาย 18V', NULL, 3990.00, 4590.00, 'in_stock', 1, NOW()),
('globalhouse', 'gh-112233', 'https://www.globalhouse.co.th/product/gh-112233', 'TOA สีทาบ้าน 5 กล.', NULL, 1290.00, 1490.00, 'in_stock', 1, NOW());

-- User tracking (link user to products)
INSERT INTO user_tracking (user_id, product_id, target_price, target_discount_percent, label, is_active, created_at)
SELECT
    2, -- Test user
    product_id,
    ROUND(last_price * 0.9, 2), -- Target 10% lower
    NULL,
    CASE platform
        WHEN 'jib' THEN 'Gaming'
        WHEN 'banana' THEN 'Work'
        WHEN 'advice' THEN 'Mobile'
        ELSE 'Home'
    END,
    1,
    NOW()
FROM tracked_products;

-- Sample price history
INSERT INTO price_history (product_id, price, original_price, discount_percent, stock_status, scraped_at)
SELECT
    product_id,
    last_price + (RAND() * 1000 - 500),
    last_original_price,
    ROUND((1 - (last_price + (RAND() * 1000 - 500)) / last_original_price) * 100, 1),
    'in_stock',
    DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 30) DAY)
FROM tracked_products
CROSS JOIN (SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) AS nums;

-- Sample master products for cross-platform matching
INSERT INTO master_products (canonical_name, brand, category, normalized_attributes, match_confidence, created_at) VALUES
('Apple MacBook Air M3 13 inch', 'Apple', 'Laptop', '{"model": "MBA-M3-13", "screen": "13 inch", "chip": "M3"}', 1.00, NOW()),
('Samsung Galaxy S24 Ultra', 'Samsung', 'Smartphone', '{"model": "SM-S928B", "storage": "256GB"}', 1.00, NOW()),
('ASUS ROG Strix G16', 'ASUS', 'Laptop', '{"model": "G614JV", "series": "ROG Strix"}', 1.00, NOW());

-- Sample price events
INSERT INTO price_events (product_id, event_type, old_price, new_price, change_percent, is_dispatched, created_at)
SELECT
    product_id,
    'price_drop',
    last_price + 1000,
    last_price,
    -ROUND(1000 / (last_price + 1000) * 100, 1),
    0,
    NOW()
FROM tracked_products
LIMIT 3;

-- Sample agent logs
INSERT INTO agent_logs (agent_type, job_id, log_level, message, context, created_at) VALUES
('scraper', NULL, 'info', 'Scheduled scrape completed', '{"products_scraped": 6, "success": 6, "failed": 0}', NOW()),
('data_cleaning', NULL, 'info', 'Product matching completed', '{"products_processed": 6, "matched": 3, "unmatched": 3}', NOW()),
('price_diff', NULL, 'info', 'Price events detected', '{"events_created": 3, "price_drops": 3, "price_increases": 0}', NOW());

SELECT 'Seed data inserted successfully!' AS status;
