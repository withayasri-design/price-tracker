# AGENTS.md — Smart Shopping Assistant

> Agent specification สำหรับ MVP phase (4 agents หลัก)
> ใช้เป็น context reference สำหรับ Claude Code / Cursor เวลา implement

---

## 1. Scraper Agent

**หน้าที่:** ดึงข้อมูลราคา/สต็อก/รายละเอียดสินค้าจาก Shopee, Lazada, TikTok Shop

| Item | Detail |
|---|---|
| Trigger | Cron ทุก 1-6 ชม. (ปรับตาม priority ของสินค้าใน watchlist) |
| Input | Product URL หรือ Product ID list จาก DB |
| Output | Raw product data (ราคา, สต็อก, timestamp) → เขียนลง `raw_price_snapshots` table |
| Tech | PHP 8.x Native + cURL/Guzzle, headless browser (Puppeteer/Playwright) fallback สำหรับหน้าที่ render ด้วย JS |
| Rate limit | Delay แบบ randomized ระหว่าง request (2-8 วินาที), จำกัด concurrent request ต่อ platform |
| Error handling | Retry 3 ครั้งแบบ exponential backoff, log failed URL ไว้ retry รอบถัดไป, ไม่ throw error ทั้ง batch เพราะ 1 URL fail |
| Dependency | ต้องรันก่อน Data Cleaning Agent และ Price Diff Agent |

**Watchpoint:** Shopee/Lazada เปลี่ยน DOM/API structure บ่อย ต้อง design ให้ selector/parsing logic แยกเป็น module ต่างหาก ปรับง่ายโดยไม่กระทบ core scraping flow

---

## 2. Data Cleaning Agent

**หน้าที่:** Normalize ชื่อสินค้า/แบรนด์จากหลายแพลตฟอร์มให้ match เป็นสินค้าเดียวกัน (fuzzy matching)

| Item | Detail |
|---|---|
| Trigger | หลัง Scraper Agent เสร็จ (queue-based) |
| Input | Raw product data จาก `raw_price_snapshots` |
| Output | Normalized product record ผูกกับ `master_product_id` |
| Tech | Trigram/similarity matching (คล้าย SIMILARITY_PCT ที่คุณเคยใช้กับ IBM i) หรือใช้ MariaDB `SOUNDEX`/Levenshtein function |
| Logic | เทียบชื่อสินค้า + แบรนด์ + spec หลัก (สี, ขนาด) ถ้า similarity > threshold → merge เข้า master product เดิม ถ้าไม่ถึง → สร้างใหม่ + flag ให้ review |
| Error handling | เก็บ unmatched record ไว้ใน review queue ไม่ auto-merge ถ้า confidence ต่ำ |

---

## 3. Price Diff Agent

**หน้าที่:** เทียบราคาปัจจุบันกับ history เพื่อตรวจจับ price drop / promotion

| Item | Detail |
|---|---|
| Trigger | หลัง Data Cleaning Agent เสร็จ |
| Input | Normalized price record ล่าสุด + price history ของ `master_product_id` |
| Output | Price change event (`price_drop`, `back_in_stock`, `lowest_price_ever`) → เขียนลง `price_events` table |
| Logic | เทียบ % change, เทียบกับ lowest price ใน 30/90 วัน, เช็คว่าตรงเงื่อนไข watchlist ของ user คนไหนบ้าง |
| Dependency | Trigger ต่อให้ Alert Dispatch Agent ทำงานทันทีเมื่อเจอ event ที่ match watchlist |

---

## 4. Alert Dispatch Agent

**หน้าที่:** ส่งแจ้งเตือนผ่าน LINE OA เมื่อราคาสินค้าตรงเงื่อนไขที่ user ตั้งไว้

| Item | Detail |
|---|---|
| Trigger | Event-driven — ทำงานทันทีที่ Price Diff Agent สร้าง event ที่ match watchlist |
| Input | `price_events` + user watchlist mapping |
| Output | LINE Notify/Messaging API push message |
| Tech | LINE Messaging API (คุณมี integration experience อยู่แล้วจาก ProcureAlert) |
| Rate limit | Batch message ถ้า user คนเดียวมีหลาย event พร้อมกัน (ป้องกัน spam แจ้งเตือนรัว ๆ) |
| Error handling | Queue + retry ถ้า LINE API rate limit, log delivery status ต่อ user |

---

## Suggested Execution Flow

```
Scraper Agent (cron)
      ↓
Data Cleaning Agent (queue-triggered)
      ↓
Price Diff Agent (queue-triggered)
      ↓
Alert Dispatch Agent (event-triggered) ──→ Affiliate Link Agent (แทรกก่อนส่งลิงก์จริง)
```

## Phase 2 Agents (หลัง validate ตลาดแล้ว)

- **Affiliate Link Agent** — แปลง URL เป็น affiliate link ก่อนส่งให้ user (ควรทำคู่กับ Alert Dispatch ตั้งแต่ MVP ถ้าเป็นไปได้ เพราะเป็นแหล่งรายได้หลัก)
- **Coupon/Deal Aggregator Agent** — cron รายวัน ดึงโค้ดส่วนลดจากแต่ละแพลตฟอร์ม
- **Anti-Block/Rotation Agent** — proxy/user-agent rotation เมื่อ scale ใหญ่ขึ้นจนโดน rate limit บ่อย
- **Analytics Agent** — cron รายสัปดาห์ สรุป trend/พฤติกรรม user เพื่อปรับ recommendation
