-- ============================================
-- Price Tracker Database Schema
-- MariaDB / MySQL 8.0+
-- ============================================
-- Run this file to initialize a fresh database:
--   mysql -u root -p < database/schema.sql
-- ============================================

-- Create database
CREATE DATABASE IF NOT EXISTS price_tracker
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE price_tracker;

-- ============================================
-- Core Tables
-- ============================================

-- Users table with LINE notification support
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notify_email TINYINT(1) NOT NULL DEFAULT 1,
    line_user_id VARCHAR(50) NULL,
    notify_line TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_line_user_id (line_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracked products with affiliate link support
CREATE TABLE IF NOT EXISTS tracked_products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    platform ENUM('shopee','lazada','tiktok','jib','banana','advice','globalhouse','homepro','thaiwatsadu','powerbuy') NOT NULL,
    platform_product_id VARCHAR(255) NOT NULL,
    product_url TEXT NOT NULL,
    affiliate_url TEXT NULL,
    affiliate_program ENUM('shopee','lazada','tiktok','accesstrade','none') NULL,
    product_name VARCHAR(500),
    image_url TEXT,
    last_price DECIMAL(12,2),
    last_original_price DECIMAL(12,2),
    last_stock_status VARCHAR(50),
    last_checked_at DATETIME,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_platform_product (platform, platform_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User tracking preferences
CREATE TABLE IF NOT EXISTS user_tracking (
    tracking_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    label VARCHAR(200),
    target_price DECIMAL(12,2) NULL,
    target_discount_percent DECIMAL(5,2) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES tracked_products(product_id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_product (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Price history
CREATE TABLE IF NOT EXISTS price_history (
    history_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    original_price DECIMAL(12,2) NULL,
    discount_percent DECIMAL(5,2) NULL,
    stock_status VARCHAR(50),
    scraped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES tracked_products(product_id) ON DELETE CASCADE,
    INDEX idx_product_time (product_id, scraped_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scraping logs
CREATE TABLE IF NOT EXISTS scraping_logs (
    log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    trigger_type ENUM('cron','manual') NOT NULL,
    triggered_by_user_id INT NULL,
    status ENUM('success','failed') NOT NULL,
    error_message TEXT NULL,
    duration_ms INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES tracked_products(product_id) ON DELETE CASCADE,
    FOREIGN KEY (triggered_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_product_created (product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alerts with LINE delivery status
CREATE TABLE IF NOT EXISTS alerts (
    alert_id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_id INT NOT NULL,
    price_at_alert DECIMAL(12,2) NOT NULL,
    alert_type ENUM('target_price','target_discount') NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    email_sent TINYINT(1) NOT NULL DEFAULT 0,
    line_sent TINYINT(1) NOT NULL DEFAULT 0,
    dispatch_channel ENUM('dashboard','email','line') NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_id) REFERENCES user_tracking(tracking_id) ON DELETE CASCADE,
    INDEX idx_tracking_created (tracking_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System settings (key-value store)
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Agent Pipeline Tables
-- ============================================

-- Raw price snapshots (before normalization)
CREATE TABLE IF NOT EXISTS raw_price_snapshots (
    snapshot_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    platform ENUM('shopee','lazada','tiktok','jib','banana','advice','globalhouse','homepro','thaiwatsadu','powerbuy') NOT NULL,
    platform_product_id VARCHAR(255) NOT NULL,
    raw_name VARCHAR(500),
    raw_price DECIMAL(12,2) NOT NULL,
    raw_original_price DECIMAL(12,2) NULL,
    raw_stock_status VARCHAR(50),
    raw_attributes JSON NULL,
    processing_status ENUM('pending','processed','failed') NOT NULL DEFAULT 'pending',
    scraped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    FOREIGN KEY (product_id) REFERENCES tracked_products(product_id) ON DELETE CASCADE,
    INDEX idx_processing_status (processing_status),
    INDEX idx_scraped_at (scraped_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Master products (canonical product catalog)
CREATE TABLE IF NOT EXISTS master_products (
    master_product_id INT AUTO_INCREMENT PRIMARY KEY,
    canonical_name VARCHAR(500) NOT NULL,
    brand VARCHAR(200) NULL,
    category VARCHAR(200) NULL,
    normalized_attributes JSON NULL,
    match_confidence ENUM('high','medium','low','review') NOT NULL DEFAULT 'review',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_canonical_name (canonical_name(100)),
    INDEX idx_brand (brand),
    INDEX idx_match_confidence (match_confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product to master product mapping
CREATE TABLE IF NOT EXISTS product_master_mapping (
    mapping_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL UNIQUE,
    master_product_id INT NOT NULL,
    similarity_score DECIMAL(5,4) NULL,
    matched_by ENUM('auto','manual','review') NOT NULL DEFAULT 'auto',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES tracked_products(product_id) ON DELETE CASCADE,
    FOREIGN KEY (master_product_id) REFERENCES master_products(master_product_id) ON DELETE CASCADE,
    INDEX idx_master_product_id (master_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Price events (price drops, flash sales, etc.)
CREATE TABLE IF NOT EXISTS price_events (
    event_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    master_product_id INT NULL,
    product_id INT NOT NULL,
    event_type ENUM('price_drop','price_increase','back_in_stock','out_of_stock','lowest_ever','flash_sale') NOT NULL,
    old_price DECIMAL(12,2) NULL,
    new_price DECIMAL(12,2) NOT NULL,
    change_percent DECIMAL(6,2) NULL,
    event_metadata JSON NULL,
    is_dispatched TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (master_product_id) REFERENCES master_products(master_product_id) ON DELETE SET NULL,
    FOREIGN KEY (product_id) REFERENCES tracked_products(product_id) ON DELETE CASCADE,
    INDEX idx_event_type (event_type),
    INDEX idx_is_dispatched (is_dispatched),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agent job queue
CREATE TABLE IF NOT EXISTS agent_job_queue (
    job_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    agent_type ENUM('scraper','data_cleaning','price_diff','alert_dispatch','affiliate') NOT NULL,
    payload JSON NOT NULL,
    priority TINYINT NOT NULL DEFAULT 5,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    retry_count TINYINT NOT NULL DEFAULT 0,
    max_retries TINYINT NOT NULL DEFAULT 3,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    INDEX idx_agent_status (agent_type, status),
    INDEX idx_priority_created (priority, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agent execution logs
CREATE TABLE IF NOT EXISTS agent_logs (
    log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    agent_type ENUM('scraper','data_cleaning','price_diff','alert_dispatch','affiliate') NOT NULL,
    job_id BIGINT NULL,
    log_level ENUM('info','warning','error','debug') NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    context JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES agent_job_queue(job_id) ON DELETE SET NULL,
    INDEX idx_agent_type_created (agent_type, created_at),
    INDEX idx_log_level (log_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Seed Data
-- ============================================

-- Default system settings (Rate limits per platform)
INSERT INTO system_settings (setting_key, setting_value) VALUES
-- Scraping intervals
('cron_interval_minutes', '180'),
-- Marketplace rate limits (stricter - anti-bot detection)
('rate_limit_per_minute_shopee', '10'),
('rate_limit_per_minute_lazada', '10'),
('rate_limit_per_minute_tiktok', '5'),
-- Tier 2 rate limits (more lenient)
('rate_limit_per_minute_jib', '15'),
('rate_limit_per_minute_banana', '15'),
('rate_limit_per_minute_advice', '15'),
('rate_limit_per_minute_globalhouse', '15'),
('rate_limit_per_minute_homepro', '15'),
('rate_limit_per_minute_thaiwatsadu', '15'),
('rate_limit_per_minute_powerbuy', '15')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- LINE API Configuration (empty - configure in admin panel)
INSERT INTO system_settings (setting_key, setting_value) VALUES
('line_channel_access_token', ''),
('line_channel_secret', ''),
('line_liff_id', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Agent Configuration
INSERT INTO system_settings (setting_key, setting_value) VALUES
('agent_scraper_batch_size', '50'),
('agent_cleaning_similarity_threshold', '0.85'),
('agent_pricediff_significant_change_percent', '5'),
('agent_dispatch_batch_delay_seconds', '60')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Affiliate Configuration (Phase 2 - empty placeholders)
INSERT INTO system_settings (setting_key, setting_value) VALUES
('affiliate_shopee_partner_id', ''),
('affiliate_lazada_partner_id', ''),
('affiliate_tiktok_partner_id', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================
-- Note: Create admin user manually after setup
-- ============================================
-- Run this after setup (replace with your details):
--
-- INSERT INTO users (email, password_hash, full_name, role)
-- VALUES (
--     'admin@example.com',
--     '$2y$12$...',  -- Use PHP's password_hash('yourpassword', PASSWORD_DEFAULT, ['cost' => 12])
--     'System Admin',
--     'admin'
-- );
