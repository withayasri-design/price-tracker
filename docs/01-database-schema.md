# 01 — Database Schema (Source of Truth)

> **สำคัญ:** ไฟล์นี้คือ source of truth เดียวของโครงสร้างฐานข้อมูล ห้ามสร้าง/แก้ไข schema ในไฟล์โค้ดโดยไม่อ้างอิงจากที่นี่ หากพัฒนาไปแล้วพบว่าต้องการตารางที่ไม่มีในไฟล์นี้ **ให้หยุดและแจ้งเตือนผู้ใช้ก่อน** ห้ามสร้างตารางเองทันที

## ER ภาพรวมความสัมพันธ์

```
users 1---* user_tracking *---1 tracked_products 1---* price_history
                |                                  |
                |                                  *---* scraping_logs
                *
              alerts
```

- 1 `tracked_products` (สินค้าจริง 1 URL) อาจถูกหลาย `users` ติดตามพร้อมกันผ่าน `user_tracking` (ประหยัด scraping — scrape ครั้งเดียวใช้ร่วมกันได้)
- แต่ละ `user_tracking` มีเงื่อนไข target price/discount เป็นของตัวเอง

## ตารางทั้งหมด

### `users`
```sql
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notify_email TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `02-module-auth`

### `tracked_products`
```sql
CREATE TABLE tracked_products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    platform ENUM('shopee','lazada','tiktok','jib','banana','advice','globalhouse','homepro','thaiwatsadu','powerbuy') NOT NULL,
    platform_product_id VARCHAR(255) NOT NULL,
    product_url TEXT NOT NULL,
    product_name VARCHAR(500),
    image_url TEXT,
    last_price DECIMAL(12,2),
    last_original_price DECIMAL(12,2),
    last_stock_status VARCHAR(50),
    last_checked_at DATETIME,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_platform_product (platform, platform_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `03-module-product-tracking`, `04-module-scraping-engine`

> **หมายเหตุการอัปเดต:** ขยาย ENUM จากเดิม `('shopee','lazada')` เพื่อรองรับ TikTok Shop (`tiktok`) และ Tier 2 (`jib`, `banana`, `advice`, `globalhouse`, `homepro`, `thaiwatsadu`, `powerbuy`) ตามที่ระบุใน `04-module-scraping-engine.md` หัวข้อ 4.3a/4.3b — หากมีตารางนี้อยู่แล้วในระบบจริงที่ยังใช้ ENUM เดิม ต้องรัน `ALTER TABLE tracked_products MODIFY COLUMN platform ENUM(...)` แทนการ DROP/CREATE ใหม่ เพื่อไม่ให้ข้อมูลเดิมหาย

### `user_tracking`
```sql
CREATE TABLE user_tracking (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `03-module-product-tracking`, `06-module-alert-notification`

### `price_history`
```sql
CREATE TABLE price_history (
    history_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    original_price DECIMAL(12,2) NULL,
    discount_percent DECIMAL(5,2) NULL,
    stock_status VARCHAR(50),
    scraped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES tracked_products(product_id) ON DELETE CASCADE,
    INDEX idx_product_time (product_id, scraped_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `05-module-price-history`

### `scraping_logs`
```sql
CREATE TABLE scraping_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `04-module-scraping-engine`, `07-module-dashboard` (admin monitor)

### `alerts`
```sql
CREATE TABLE alerts (
    alert_id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_id INT NOT NULL,
    price_at_alert DECIMAL(12,2) NOT NULL,
    alert_type ENUM('target_price','target_discount') NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    email_sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tracking_id) REFERENCES user_tracking(tracking_id) ON DELETE CASCADE,
    INDEX idx_tracking_created (tracking_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `06-module-alert-notification`, `07-module-dashboard`

### `system_settings`
```sql
CREATE TABLE system_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
Key ที่คาดว่าจะใช้: `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass_encrypted`, `cron_interval_minutes`, `rate_limit_per_minute_shopee`, `rate_limit_per_minute_lazada`, `rate_limit_per_minute_tiktok`, `rate_limit_per_minute_jib`, `rate_limit_per_minute_banana`, `rate_limit_per_minute_advice`, `rate_limit_per_minute_globalhouse`, `rate_limit_per_minute_homepro`, `rate_limit_per_minute_thaiwatsadu`, `rate_limit_per_minute_powerbuy`

ใช้โดยโมดูล: `04-module-scraping-engine`, `06-module-alert-notification` (Admin เท่านั้น)

## Seed Data ที่ควรมีตอน Initial Setup

```sql
-- Admin คนแรก (เปลี่ยน password ทันทีหลังติดตั้ง)
INSERT INTO users (email, password_hash, full_name, role)
VALUES ('admin@example.com', '<bcrypt-hash>', 'System Admin', 'admin');

-- ค่าตั้งต้นระบบ (Marketplace หลัก — ระวังมากกว่า เพราะ anti-bot หนักกว่า)
INSERT INTO system_settings (setting_key, setting_value) VALUES
('cron_interval_minutes', '180'),
('rate_limit_per_minute_shopee', '10'),
('rate_limit_per_minute_lazada', '10'),
('rate_limit_per_minute_tiktok', '5');

-- ค่าตั้งต้น Tier 2 (เว็บ server-rendered ทั่วไป — ตั้งค่าเริ่มต้นสูงกว่าได้ แต่ยังควรระมัดระวัง)
INSERT INTO system_settings (setting_key, setting_value) VALUES
('rate_limit_per_minute_jib', '15'),
('rate_limit_per_minute_banana', '15'),
('rate_limit_per_minute_advice', '15'),
('rate_limit_per_minute_globalhouse', '15'),
('rate_limit_per_minute_homepro', '15'),
('rate_limit_per_minute_thaiwatsadu', '15'),
('rate_limit_per_minute_powerbuy', '15');
```

---

## Agent Pipeline Tables (Phase 2 - Cross-Platform Matching)

> เพิ่มเติมจาก `agents.md` — รองรับ agent pipeline architecture สำหรับ cross-platform matching และ LINE notifications

### ER ภาพรวม Agent Pipeline

```
raw_price_snapshots ---> product_master_mapping ---> master_products
         |                        |
         v                        v
   price_events <---------- price_history (existing)
         |
         v
   agent_job_queue ---> agent_logs
```

### `raw_price_snapshots`
```sql
CREATE TABLE raw_price_snapshots (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `agents/ScraperAgent`, `agents/DataCleaningAgent`

### `master_products`
```sql
CREATE TABLE master_products (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `modules/matching/MasterProductService`, `agents/DataCleaningAgent`

### `product_master_mapping`
```sql
CREATE TABLE product_master_mapping (
    mapping_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL UNIQUE,
    master_product_id INT NOT NULL,
    similarity_score DECIMAL(5,4) NULL,
    matched_by ENUM('auto','manual','review') NOT NULL DEFAULT 'auto',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES tracked_products(product_id) ON DELETE CASCADE,
    FOREIGN KEY (master_product_id) REFERENCES master_products(master_product_id) ON DELETE CASCADE,
    INDEX idx_master_product_id (master_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `modules/matching/MasterProductService`, `agents/DataCleaningAgent`

### `price_events`
```sql
CREATE TABLE price_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `agents/PriceDiffAgent`, `agents/AlertDispatchAgent`

### `agent_job_queue`
```sql
CREATE TABLE agent_job_queue (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `core/Queue`, `agents/AgentRunner`

### `agent_logs`
```sql
CREATE TABLE agent_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
ใช้โดยโมดูล: `agents/AgentRunner`, `pages/admin/agent_monitor`

---

## Table Modifications (ALTER statements)

> รันเฉพาะเมื่อตารางมีอยู่แล้วในระบบ — ถ้ายังไม่มีให้รวมคอลัมน์เหล่านี้ใน CREATE TABLE ตั้งแต่แรก

### `users` — เพิ่ม LINE notification support
```sql
ALTER TABLE users
ADD COLUMN line_user_id VARCHAR(50) NULL AFTER notify_email,
ADD COLUMN notify_line TINYINT(1) NOT NULL DEFAULT 1 AFTER line_user_id,
ADD UNIQUE INDEX idx_line_user_id (line_user_id);
```

### `tracked_products` — เพิ่ม affiliate link support
```sql
ALTER TABLE tracked_products
ADD COLUMN affiliate_url TEXT NULL AFTER product_url,
ADD COLUMN affiliate_program ENUM('shopee','lazada','tiktok','accesstrade','none') NULL AFTER affiliate_url;
```

### `alerts` — เพิ่ม LINE delivery status
```sql
ALTER TABLE alerts
ADD COLUMN line_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER email_sent,
ADD COLUMN dispatch_channel ENUM('dashboard','email','line') NULL AFTER line_sent;
```

---

## Additional Seed Data (Agent Pipeline)

```sql
-- LINE API Configuration
INSERT INTO system_settings (setting_key, setting_value) VALUES
('line_channel_access_token', ''),
('line_channel_secret', ''),
('line_liff_id', '');

-- Agent Configuration
INSERT INTO system_settings (setting_key, setting_value) VALUES
('agent_scraper_batch_size', '50'),
('agent_cleaning_similarity_threshold', '0.85'),
('agent_pricediff_significant_change_percent', '5'),
('agent_dispatch_batch_delay_seconds', '60');

-- Affiliate Configuration (Phase 2)
INSERT INTO system_settings (setting_key, setting_value) VALUES
('affiliate_shopee_partner_id', ''),
('affiliate_lazada_partner_id', ''),
('affiliate_tiktok_partner_id', '');
```
