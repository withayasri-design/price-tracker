# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Multi-platform price tracking system that monitors product prices across Thai e-commerce platforms (Shopee, Lazada, TikTok Shop) and specialty retailers (JIB, Banana IT, Advice, Global House, HomePro, Thai Watsadu, Power Buy). Sends alerts when prices hit target thresholds, maintains price history, and supports multi-user access with Admin/User roles.

## Tech Stack (Fixed - Do Not Change)

- **Backend:** PHP 8.2 Native (no Laravel/Symfony)
- **Database:** MariaDB with PDO Prepared Statements only (no mysqli, no raw queries)
- **Frontend:** Vanilla JavaScript only (no jQuery, React, Vue, DataTables)
- **UI:** Bootstrap 5.3 + Font Awesome 6 + Chart.js
- **Email:** PHPMailer (SMTP)
- **Auth:** PHP native sessions + password_hash()/password_verify()
- **LINE:** LINE Messaging API with Flex Messages

## Commands

```bash
# Set up database
mysql -u root -p < database/schema.sql

# Copy and configure environment
cp .env.example .env

# Run development server (XAMPP or PHP built-in)
php -S localhost:8000

# Cron job (add to crontab for scheduled scraping)
*/30 * * * * php /path/to/cron/run_scraping_job.php

# Agent queue processor (run every minute)
* * * * * php /path/to/cron/run_agent_queue.php
```

## Project Structure

```
price-tracker/
├── index.php                    # Landing page
├── .env.example                 # Environment template
├── config/
│   ├── database.php             # PDO connection with .env loading
│   └── line.php                 # LINE API configuration
├── core/
│   ├── Auth.php                 # Session-based authentication
│   ├── Csrf.php                 # CSRF token handling
│   ├── Database.php             # PDO wrapper with helpers
│   └── Queue.php                # DB-backed job queue
├── agents/
│   ├── AgentInterface.php       # Contract for all agents
│   ├── AgentResult.php          # Agent execution result
│   ├── AgentRunner.php          # Queue processor
│   ├── ScraperAgent.php         # Fetches prices
│   ├── DataCleaningAgent.php    # Cross-platform matching
│   ├── PriceDiffAgent.php       # Detects price events
│   └── AlertDispatchAgent.php   # LINE + Email notifications
├── modules/
│   ├── scraping/
│   │   ├── PlatformAdapterInterface.php
│   │   ├── BaseAdapter.php           # Common HTTP/parsing
│   │   ├── ScrapedProduct.php        # Data class
│   │   ├── ScrapingException.php     # Exception handling
│   │   ├── ScrapingService.php       # Orchestrator
│   │   └── adapters/
│   │       ├── JibAdapter.php
│   │       ├── BananaAdapter.php
│   │       ├── AdviceAdapter.php
│   │       ├── GlobalHouseAdapter.php
│   │       ├── HomeProAdapter.php
│   │       ├── ThaiWatsaduAdapter.php
│   │       └── PowerBuyAdapter.php
│   ├── matching/
│   │   ├── SimilarityCalculator.php  # Trigram/Levenshtein
│   │   └── MasterProductService.php  # Master product catalog
│   ├── tracking/
│   │   └── TrackingService.php       # Product tracking logic
│   └── notification/
│       ├── LineNotifier.php          # LINE Messaging API
│       └── EmailNotifier.php         # Email notifications
├── pages/
│   ├── login.php                # User login
│   ├── register.php             # User registration
│   ├── logout.php               # Logout handler
│   ├── dashboard.php            # User dashboard with price events
│   ├── profile.php              # User profile & settings
│   ├── products.php             # Product management
│   ├── product_detail.php       # Price history chart
│   ├── compare.php              # Cross-platform comparison
│   ├── line_connect.php         # LINE account linking
│   └── admin/
│       ├── master_products.php  # Review unmatched products
│       ├── agent_monitor.php    # Agent queue monitoring
│       └── settings.php         # System settings
├── api/
│   ├── products/
│   │   ├── add.php              # Add product tracking
│   │   ├── list.php             # List tracked products
│   │   ├── delete.php           # Remove tracking
│   │   ├── refresh.php          # Manual price refresh
│   │   └── history.php          # Price history data
│   ├── agents/
│   │   ├── queue_status.php     # Queue statistics
│   │   └── trigger_agent.php    # Manual agent trigger
│   ├── events/                  # Price events API
│   ├── matching/                # Cross-platform matching API
│   └── notifications/
│       └── line_webhook.php     # LINE webhook receiver
├── cron/
│   ├── run_agent_queue.php      # Agent queue processor
│   └── run_scheduled_scrape.php # Scheduled scraping
├── database/
│   └── schema.sql               # Full database schema
└── docs/                        # Specification files
```

**Key Patterns:**
- Platform Adapter pattern in `modules/scraping/` - each platform implements `PlatformAdapterInterface`
- Agent Pipeline pattern in `agents/` - queue-based processing for cross-platform matching

## Agent Pipeline

```
ScraperAgent → DataCleaningAgent → PriceDiffAgent → AlertDispatchAgent
     ↓                ↓                  ↓                 ↓
raw_price_snapshots  master_products   price_events    LINE + Email
```

- **Queue-based:** Uses `agent_job_queue` table (DB-backed, no Redis required)
- **Dual notifications:** Both LINE OA and Email supported; users choose in profile
- **Coexistence:** Direct service calls and agent pipeline work together

## Core Classes Usage

### Auth (core/Auth.php)
```php
use Core\Auth;

Auth::requireLogin();                    // Redirect if not logged in
Auth::requireAdmin();                    // Require admin role
Auth::check();                           // Returns bool
Auth::userId();                          // Returns int|null
Auth::login($id, $email, $role, $name);  // Start session
Auth::logout();                          // Destroy session
Auth::hashPassword($password);           // Returns bcrypt hash
Auth::verifyPassword($pwd, $hash);       // Returns bool
Auth::flash('key', 'message');           // Set flash message
Auth::getFlash('key');                   // Get and clear flash
```

### Csrf (core/Csrf.php)
```php
use Core\Csrf;

$token = Csrf::generate();               // Generate new token
Csrf::verify($_POST['csrf_token']);      // Throws on invalid
Csrf::check($token);                     // Returns bool (no throw)
echo Csrf::field();                      // <input type="hidden"...>
echo Csrf::meta();                       // <meta name="csrf-token"...>
```

### Queue (core/Queue.php)
```php
use Core\Queue;

$queue = new Queue($pdo);
$jobId = $queue->push('scraper', ['product_id' => 123]);
$job = $queue->pop('scraper');           // Get next pending job
$queue->complete($jobId);                // Mark as completed
$queue->fail($jobId, 'Error message');   // Mark as failed (with retry)
```

## Critical Rules

1. **All SQL queries MUST use PDO Prepared Statements** - never concatenate strings into SQL
2. **Check `docs/01-database-schema.md` before creating/modifying tables** - it's the Source of Truth
3. **API responses are always JSON:** `{ "success": true|false, "data": {...}, "message": "..." }`
4. **All forms require CSRF tokens** - use `Csrf::field()` in forms
5. **Never deploy scrapers with TODO comments** - verify platform compatibility first
6. **Comments/variables in English, UI text in Thai**

## Specification Files (Source of Truth)

Before implementing any module, read its spec file in `docs/`:

| File | Content |
|------|---------|
| `docs/00-CLAUDE.md` | Project overview in Thai, platform status, development phases |
| `docs/01-database-schema.md` | Database schema - **check before any DB changes** |
| `docs/02-module-auth.md` | Authentication & user management |
| `docs/03-module-product-tracking.md` | Product tracking with URL parser patterns |
| `docs/04-module-scraping-engine.md` | Scraping engine with platform-specific inspection results |
| `docs/05-module-price-history.md` | Price history tracking |
| `docs/06-module-alert-notification.md` | Alert system |
| `docs/07-module-dashboard.md` | Dashboard components |
| `docs/08-api-reference.md` | Complete API endpoint reference |
| `docs/09-project-structure.md` | File organization |
| `docs/10-agents.md` | Agent pipeline architecture (LINE, cross-platform matching) |

## Platform Implementation Status

| Platform | Status | Adapter |
|----------|--------|---------|
| JIB | Ready | `JibAdapter.php` |
| Banana IT | Ready | `BananaAdapter.php` |
| Advice | Ready | `AdviceAdapter.php` |
| Power Buy | Ready | `PowerBuyAdapter.php` |
| Global House | Ready | `GlobalHouseAdapter.php` |
| HomePro | Ready | `HomeProAdapter.php` |
| Thai Watsadu | Ready | `ThaiWatsaduAdapter.php` |
| Shopee | Not implemented | SPA + anti-bot, needs API/headless |
| Lazada | Not implemented | SPA + anti-bot, needs API/headless |
| TikTok Shop | Not implemented | SPA + anti-bot, needs API/headless |

## Development Status

- [x] Database schema + Auth + Project skeleton
- [x] Product Tracking (Add/List/Threshold)
- [x] Scraping Engine (7 platform adapters)
- [x] Price History + Charts
- [x] Agent Pipeline (Scraper → DataCleaning → PriceDiff → AlertDispatch)
- [x] LINE + Email Notifications
- [x] Cross-platform Price Comparison
- [x] Admin Dashboard (Settings, Agent Monitor, Master Products)
- [ ] Affiliate Links (Post-MVP)
