# CLAUDE.md — Price Tracker (Multi-Platform) Project Reference

> ไฟล์นี้คือจุดอ้างอิงหลักสำหรับ Claude Code เมื่อพัฒนาโปรเจกต์นี้ ให้อ่านไฟล์นี้ก่อนเสมอ แล้วค่อยเปิดไฟล์ spec โมดูลที่เกี่ยวข้องตามงานที่ทำ

## 1. โปรเจกต์คืออะไร

ระบบติดตามราคาสินค้าจากหลาย Platform แจ้งเตือนเมื่อราคาลดถึงเป้าหมาย เก็บประวัติราคาไว้เปรียบเทียบ รองรับผู้ใช้หลายคน (Multi-user, Role Admin/User)

**Platform ที่รองรับ:**
- **Marketplace หลัก:** Shopee, Lazada, TikTok Shop
- **ร้านค้าเฉพาะทาง (Tier 2):** JIB, Banana IT (bnn.in.th), Advice, Global House, HomePro Online, Thai Watsadu, Power Buy

## 2. Tech Stack (ตายตัว ห้ามเปลี่ยน)

| ส่วน | เทคโนโลยี |
|---|---|
| Backend | PHP 8.2 **Native** (ห้ามใช้ MVC Framework เช่น Laravel/Symfony) |
| Database | MariaDB — **PDO Prepared Statements เท่านั้น** ห้ามใช้ mysqli หรือ raw query แบบไม่ bind param |
| Frontend | Vanilla JS เท่านั้น (ห้ามใช้ jQuery, React, Vue, DataTables) |
| UI Framework | Bootstrap 5.3 |
| ไอคอน | Font Awesome 6 |
| กราฟ | Chart.js |
| Email | PHPMailer (SMTP) |
| Scheduler | Cron Job (Linux) |
| Auth | PHP Native Session + `password_hash()` / `password_verify()` |

## 3. กฎการเขียนโค้ด (Conventions)

1. **ทุก query ต้องใช้ PDO Prepared Statement** — ห้าม concat string เข้า SQL โดยตรง
2. **ห้ามสร้างตารางใหม่เองถ้าไม่แน่ใจ** — ถ้าไม่พบตารางที่ต้องการใช้ในระบบจริง ให้ **แจ้งเตือนผู้ใช้ก่อน** ห้ามสร้างตารางในโค้ดทันทีโดยไม่ถาม (อ้างอิง schema จาก `01-database-schema.md` เป็นหลัก)
3. โครงสร้างโค้ดแบ่งเป็น **โมดูลหลัก** และแต่ละโมดูลแบ่งเป็น **คอมโพเนนต์ย่อยที่ทำงานเรียงลำดับ** ตามที่ระบุในแต่ละไฟล์ spec — ห้ามยุบรวมคอมโพเนนต์ข้ามหน้าที่กัน
4. ไฟล์ PHP หน้า UI กับไฟล์ API (AJAX endpoint) แยกโฟลเดอร์กันชัดเจน (`pages/` vs `api/`)
5. Comment โค้ดและชื่อตัวแปรเป็นภาษาอังกฤษ, ข้อความที่แสดงผลบน UI เป็นภาษาไทย
6. ทุกฟอร์มต้องมี CSRF token
7. Response จาก API เป็น JSON เสมอ รูปแบบ: `{ "success": true|false, "data": {...}, "message": "..." }`
8. **ห้าม deploy โค้ด scraper ที่ยังมี `<TODO>` ค้างอยู่** — ต้อง inspect URL/endpoint จริงของ platform นั้นให้ยืนยันก่อนเสมอ (ดูสถานะแต่ละ platform ในหัวข้อ 7)

## 4. โครงสร้างไฟล์ Spec ทั้งหมดของโปรเจกต์

| ไฟล์ | เนื้อหา |
|---|---|
| `00-CLAUDE.md` | ไฟล์นี้ — ภาพรวม, กฎกลาง, สถานะ platform ล่าสุด |
| `01-database-schema.md` | โครงสร้างฐานข้อมูลทั้งหมด (Source of Truth) — รองรับ platform ENUM ครบ 10 ชนิด |
| `02-module-auth.md` | โมดูล Authentication & User Management |
| `03-module-product-tracking.md` | โมดูล Product Tracking Management — URL Parser พร้อม pattern จริงของหลาย platform |
| `04-module-scraping-engine.md` | โมดูล Price Scraping Engine — ผล inspect จริงของทุก platform + Adapter design |
| `05-module-price-history.md` | โมดูล Price History & Comparison |
| `06-module-alert-notification.md` | โมดูล Alert & Notification |
| `07-module-dashboard.md` | โมดูล Dashboard & Reporting |
| `08-api-reference.md` | รายการ API Endpoint ทั้งหมด (contract กลาง) |
| `09-project-structure.md` | โครงสร้างโฟลเดอร์/ไฟล์ทั้งโปรเจกต์ |

## 5. ลำดับการพัฒนาที่แนะนำ (อัปเดตตามผล inspect จริง)

```
Phase 1: 01-database-schema → 02-module-auth → 09-project-structure (ตั้ง skeleton)
Phase 2: 03-module-product-tracking (Add/Tracking List/Threshold — ยังไม่รวม Keyword Search)
Phase 3: 04-module-scraping-engine — เริ่มจาก platform ที่ยืนยันแล้วว่า scrape ง่าย:
         3a. JibScraper (ยืนยันแล้ว — server-rendered เต็มรูปแบบ)
         3b. BananaScraper — bnn.in.th (ยืนยันแล้ว — SSR แม้ใช้ Nuxt.js)
         3c. AdviceScraper (ยืนยันแล้ว — หน้า product detail SSR แม้หน้า search จะ client-rendered)
         3d. GlobalHouseScraper (ต้อง inspect network เพิ่ม — ราคาบางหน้าโหลดผ่าน JS)
         3e. ShopeeScraper, LazadaScraper, TikTokScraper (ยากสุด — SPA + anti-bot หนัก ต้อง inspect endpoint จริงก่อน)
         3f. HomeProScraper, ThaiWatsaduScraper, PowerBuyScraper (ยังไม่ได้ inspect เลย)
Phase 4: 05-module-price-history → 06-module-alert-notification
Phase 5: 07-module-dashboard (ทั้ง User + Admin)
Phase 6: ปรับปรุง Rate Limiter, Error Handling, ทดสอบระยะยาว, เพิ่ม Keyword Search (3.3)
```

## 6. Role และสิทธิ์ (สรุปย่อ — รายละเอียดใน `02-module-auth.md`)

| ฟีเจอร์ | Admin | User |
|---|:---:|:---:|
| จัดการสินค้าที่ตนเองติดตาม | ✅ | ✅ |
| จัดการผู้ใช้ทั้งหมด | ✅ | ❌ |
| ดู Log การ Scraping ทั้งระบบ | ✅ | ❌ |
| Trigger Scraping ทั้งระบบ | ✅ | เฉพาะสินค้าตนเอง |
| ตั้งค่าระบบ (SMTP/Cron) | ✅ | ❌ |

## 7. สถานะการ Inspect แต่ละ Platform (อัปเดตล่าสุด กรกฎาคม 2569)

| Platform | สถานะ | รายละเอียด |
|---|---|---|
| **JIB** | พร้อม implement | Server-rendered เต็มรูปแบบ, URL pattern + regex ยืนยันแล้วใน `03`, `04` |
| **Banana IT** (bnn.in.th) | พร้อม implement | SSR แม้ใช้ Nuxt.js, URL pattern + regex ยืนยันแล้ว |
| **Advice** | พร้อม implement (fetch by URL) | หน้า product detail SSR เต็มรูปแบบ แต่หน้า search เป็น client-rendered — รหัสสินค้าต้อง parse จาก HTML content ไม่ใช่จาก URL |
| **Global House** | ต้อง inspect เพิ่มเติม | URL pattern ยืนยันแล้ว แต่ราคาในหลายหน้าโหลดผ่าน JS แยก ("กำลังโหลด...") ต้องหา endpoint ราคาจริงก่อน |
| **Shopee / Lazada / TikTok Shop** | ยังไม่ inspect | SPA + anti-bot หนัก ต้อง inspect network จริงก่อนเขียน Adapter |
| **HomePro / Thai Watsadu / Power Buy** | ยังไม่ inspect | ยังไม่มีข้อมูลเพียงพอ ต้อง inspect ก่อนจัด priority |

**ห้าม deploy Adapter ของ platform ที่ยังไม่ inspect โดยไม่ตรวจสอบซ้ำก่อนเสมอ** — โครงสร้างเว็บอาจเปลี่ยนได้ตลอดเวลาแม้ platform ที่พร้อมแล้วก็ตาม

## 8. ประเด็นที่ยังไม่ได้ตัดสินใจ (ต้องยืนยันก่อนเริ่มแต่ละ Phase)

- SMTP Provider ที่จะใช้ส่ง Email
- ค่า default รอบ Cron และ rate limit ต่อ platform (ตั้งต้นแยกกลุ่ม Marketplace หลัก vs Tier 2 ใน `01-database-schema.md` แล้ว)
- Server รองรับ Headless Browser Microservice หรือไม่ (จำเป็นสำหรับ Global House ในอนาคต และอาจจำเป็นสำหรับ Shopee/Lazada/TikTok)
- Endpoint ราคาจริงของ Global House (ต้อง inspect network เพิ่ม)
- Endpoint จริงของ Shopee/Lazada/TikTok Shop (ยังไม่ได้เริ่ม inspect เลย)
