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

## Commands

```bash
# Install PHP dependencies
composer install

# Set up database
mysql -u root -p < database/schema.sql

# Run development server
php -S localhost:8000

# Cron job (add to crontab for scheduled scraping)
*/30 * * * * php /path/to/cron/run_scraping_job.php

# Agent queue processor (run every minute)
* * * * * php /path/to/cron/run_agent_queue.php
```

## Architecture

```
modules/           Pure business logic (no HTTP concerns, unit testable)
api/               Thin HTTP layer (validate → call modules → JSON response)
pages/             UI pages (Auth guard → render HTML → AJAX to api/)
core/              Shared utilities (Database, Auth, CSRF, Queue)
agents/            Orchestration layer for pipeline processing
cron/              Cron entry points (scraping + agent queue)
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

## Critical Rules

1. **All SQL queries MUST use PDO Prepared Statements** - never concatenate strings into SQL
2. **Check `01-database-schema.md` before creating/modifying tables** - it's the Source of Truth
3. **API responses are always JSON:** `{ "success": true|false, "data": {...}, "message": "..." }`
4. **All forms require CSRF tokens**
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

| Platform | Status | Notes |
|----------|--------|-------|
| JIB | Ready | Server-rendered, straightforward |
| Banana IT | Ready | SSR despite Nuxt.js |
| Advice | Ready | Detail pages SSR, product ID from HTML |
| Global House | Needs inspection | Some prices load via JS |
| Shopee/Lazada/TikTok | Not inspected | SPA + anti-bot, requires network inspection |

## Development Phases

1. Database schema + Auth + Project skeleton
2. Product Tracking (Add/List/Threshold)
3. Scraping Engine - start with JIB/Banana/Advice (easiest)
4. Price History + Alerts
5. Dashboard & Reporting
