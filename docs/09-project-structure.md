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
│   ├── scraping/
│   │   ├── PlatformAdapterInterface.php  # Contract for platform adapters
│   │   ├── BaseAdapter.php               # Common HTTP/parsing functionality
│   │   ├── ScrapedProduct.php            # Data class for scraped results
│   │   ├── ScrapingException.php         # Exception handling with retry logic
│   │   ├── ScrapingService.php           # Orchestrates all adapters
│   │   ├── UrlParser.php                 # URL parsing for all platforms
│   │   └── adapters/
│   │       ├── JibAdapter.php            # jib.co.th
│   │       ├── BananaAdapter.php         # bananait.co.th
│   │       ├── AdviceAdapter.php         # advice.co.th
│   │       ├── GlobalHouseAdapter.php    # globalhouse.co.th
│   │       ├── HomeProAdapter.php        # homepro.co.th
│   │       ├── ThaiWatsaduAdapter.php    # thaiwatsadu.com
│   │       └── PowerBuyAdapter.php       # powerbuy.co.th
│   ├── tracking/
│   │   └── TrackingService.php           # Product tracking CRUD & price history
│   ├── notification/
│   │   ├── LineNotifier.php              # LINE Messaging API with Flex Messages
│   │   └── EmailNotifier.php             # Email notifications (SMTP/sendmail)
│   └── matching/                         # Cross-platform product matching
│       ├── SimilarityCalculator.php      # Trigram/Levenshtein algorithms
│       └── MasterProductService.php      # Master catalog CRUD
├── cron/
│   ├── run_agent_queue.php          # Agent queue processor (every minute)
│   └── run_scheduled_scrape.php     # Queue stale products for scraping (every 30 min)
├── api/
│   ├── products/
│   │   ├── add.php                  # Add product tracking
│   │   ├── list.php                 # List tracked products
│   │   ├── delete.php               # Remove tracking
│   │   ├── refresh.php              # Manual price refresh
│   │   └── history.php              # Price history data
│   ├── agents/
│   │   ├── queue_status.php         # Queue statistics & monitoring
│   │   └── trigger_agent.php        # Manual agent trigger
│   ├── events/
│   │   ├── list.php                 # List price events
│   │   └── stats.php                # Event statistics
│   ├── matching/
│   │   ├── suggestions.php          # Get matching suggestions
│   │   ├── confirm_match.php        # Confirm product match
│   │   ├── unlink.php               # Unlink matched products
│   │   └── comparison.php           # Cross-platform comparison data
│   └── notifications/
│       └── line_webhook.php         # LINE webhook receiver
├── pages/
│   ├── login.php                    # User login
│   ├── register.php                 # User registration
│   ├── logout.php                   # Logout handler
│   ├── dashboard.php                # Main dashboard with price events
│   ├── products.php                 # Product tracking management
│   ├── product_detail.php           # Price history chart (Chart.js)
│   ├── compare.php                  # Cross-platform price comparison
│   ├── profile.php                  # User profile & notification settings
│   ├── line_connect.php             # LINE account linking (OAuth)
│   └── admin/
│       ├── settings.php             # System settings (scraping, email, LINE, agents)
│       ├── agent_monitor.php        # Agent queue status & logs
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
