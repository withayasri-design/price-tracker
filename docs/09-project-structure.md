# 09 — Project Structure

```
price-tracker/
├── config/
│   ├── database.php              # PDO connection (อ่าน credential จาก .env หรือ config array)
│   ├── mail.php                   # PHPMailer/SMTP config
│   └── line.php                   # LINE Messaging API credentials
├── core/
│   ├── Auth.php                    # requireLogin(), requireAdmin(), session helper
│   ├── Database.php                 # PDO wrapper กลาง (prepared statement helper)
│   ├── Csrf.php                     # สร้าง/ตรวจสอบ CSRF token
│   ├── Notification.php             # dispatchNotification(), sendEmailAlert()
│   └── Queue.php                    # DB-backed job queue for agent pipeline
├── agents/                          # Agent orchestration layer (see 10-agents.md)
│   ├── AgentInterface.php           # Contract สำหรับทุก agent
│   ├── AgentResult.php              # Result object จาก agent processing
│   ├── AgentRunner.php              # Queue processor, job lifecycle management
│   ├── ScraperAgent.php             # Agent 1: ดึงราคาจาก platform
│   ├── DataCleaningAgent.php        # Agent 2: Fuzzy matching cross-platform
│   ├── PriceDiffAgent.php           # Agent 3: ตรวจจับ price drop/promotion
│   └── AlertDispatchAgent.php       # Agent 4: ส่ง LINE + Email notifications
├── modules/
│   ├── auth/
│   │   └── AuthService.php          # logic ของ Module 02
│   ├── products/
│   │   ├── ProductService.php       # logic ของ Module 03
│   │   └── UrlParser.php            # 3.2 URL Parser
│   ├── scraping/
│   │   ├── PlatformAdapterInterface.php
│   │   ├── ScrapedProductData.php
│   │   ├── ShopeeScraper.php
│   │   ├── LazadaScraper.php
│   │   ├── RateLimiter.php
│   │   └── ScrapingService.php      # orchestrate flow ของ Module 04
│   ├── history/
│   │   └── HistoryService.php       # logic ของ Module 05
│   ├── notification/
│   │   ├── AlertService.php         # logic ของ Module 06 (Threshold Checker ฯลฯ)
│   │   ├── LineNotifier.php         # LINE Messaging API integration
│   │   └── NotificationChannel.php  # Channel abstraction (email, line, dashboard)
│   └── matching/                    # Cross-platform product matching
│       ├── MatchingService.php      # Fuzzy matching orchestration
│       ├── SimilarityCalculator.php # Trigram/Levenshtein algorithms
│       └── MasterProductService.php # Master catalog CRUD
├── cron/
│   ├── run_scraping_job.php         # entry point สำหรับ crontab (Module 04 - 4.4)
│   └── run_agent_queue.php          # Agent queue processor (see agents/)
├── api/
│   ├── auth/
│   ├── products/
│   ├── scraping/
│   ├── history/
│   ├── notifications/
│   │   └── line_webhook.php         # LINE webhook receiver
│   ├── dashboard/
│   ├── agents/                      # Agent management APIs
│   │   ├── queue_status.php
│   │   └── trigger_agent.php
│   ├── matching/                    # Product matching APIs
│   │   ├── suggestions.php
│   │   ├── confirm_match.php
│   │   └── unlink.php
│   └── admin/
├── pages/
│   ├── login.php
│   ├── register.php
│   ├── dashboard.php
│   ├── products.php                 # Tracking List (Module 03 - 3.5)
│   ├── product_detail.php           # กราฟราคา + comparison (Module 05)
│   ├── notifications.php
│   ├── profile.php
│   ├── line_connect.php             # LINE account linking (OAuth)
│   └── admin/
│       ├── dashboard.php
│       ├── users.php
│       ├── scraping_monitor.php
│       ├── settings.php
│       ├── agent_monitor.php        # Agent queue status dashboard
│       └── master_products.php      # Review unmatched products
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       ├── products.js               # Vanilla JS: AJAX fetch, manual trigger
│       ├── notifications.js
│       └── chart-helper.js           # wrapper เรียก Chart.js
├── vendor/                            # composer: PHPMailer เป็นหลัก
├── database/
│   └── schema.sql                     # สร้างจาก 01-database-schema.md
├── docs/                               # เก็บไฟล์ spec ทั้งหมดนี้ไว้อ้างอิงต่อเนื่อง
│   ├── 00-CLAUDE.md
│   ├── 01-database-schema.md
│   ├── 02-module-auth.md
│   ├── 03-module-product-tracking.md
│   ├── 04-module-scraping-engine.md
│   ├── 05-module-price-history.md
│   ├── 06-module-alert-notification.md
│   ├── 07-module-dashboard.md
│   ├── 08-api-reference.md
│   ├── 09-project-structure.md
│   └── 10-agents.md                    # Agent pipeline architecture spec
├── composer.json
└── .env.example                        # DB_HOST, DB_NAME, SMTP_* ฯลฯ (ห้าม commit .env จริง)
```

## หลักการวางไฟล์

1. **`modules/`** = business logic ล้วนๆ (ไม่ยุ่งกับ HTTP request/response โดยตรง) — เขียนเป็น class/function ที่ทดสอบ unit test ได้
2. **`api/`** = เปลือกบางๆ ที่รับ HTTP request → เรียก `modules/` → ตอบกลับ JSON เท่านั้น ไม่ควรมี business logic อยู่ในไฟล์นี้
3. **`pages/`** = HTML/PHP ที่ render UI, เรียก `core/Auth.php` guard ก่อนเสมอ, ดึงข้อมูลผ่าน AJAX ไปที่ `api/` (ไม่ query database ตรงในไฟล์ page)
4. **`cron/`** = entry point เดียวสำหรับ cron, เรียกใช้ `modules/scraping/ScrapingService.php` เป็นหลัก ไม่เขียน logic ซ้ำ
5. **`agents/`** = orchestration layer ที่เรียกใช้ `modules/` ผ่าน queue-based pipeline — ใช้สำหรับ cross-platform matching และ multi-channel notifications

## Agent Pipeline Architecture

```
Cron/Manual Trigger
        ↓
ScraperAgent (scrape prices → raw_price_snapshots)
        ↓
DataCleaningAgent (fuzzy match → master_products)
        ↓
PriceDiffAgent (detect changes → price_events)
        ↓
AlertDispatchAgent (notify → LINE + Email)
```

- **Coexistence:** Direct service calls (manual refresh) และ agent pipeline ทำงานคู่กัน
- **Queue-based:** ใช้ `agent_job_queue` table แทน Redis/RabbitMQ (ตาม tech stack constraint)
- **Dual notifications:** รองรับทั้ง LINE OA และ Email — user เลือกได้ใน profile
