# 04 — Module: Price Scraping Engine

**เป้าหมายของโมดูล:** ดึงราคาสินค้าจาก Marketplace หลัก (Shopee/Lazada/TikTok Shop) และร้านค้าเฉพาะทาง Tier 2 (JIB, Banana IT, Advice, Global House, HomePro Online, Thai Watsadu, Power Buy) อย่างแม่นยำและสม่ำเสมอ ทั้งแบบอัตโนมัติ (Cron) และสั่งเอง (Manual)

**ผลลัพธ์ที่ผู้ใช้ได้รับ:** ราคาสินค้าล่าสุดที่อัปเดตอัตโนมัติตามรอบ พร้อมปุ่มดึงราคาทันทีเมื่อต้องการ

**ตารางที่เกี่ยวข้อง:** `tracked_products`, `scraping_logs`, `price_history`, `system_settings`

> ⚠️ **การเพิ่ม Tier 2 Platform ต้องขยาย ENUM `platform` ใน `tracked_products`** จาก `ENUM('shopee','lazada')` เป็นรายชื่อที่ครบตามหัวข้อ 4.3b — เป็นการแก้ schema เดิม **ต้องยืนยันกับผู้ใช้ก่อน** ตามกฎในไฟล์ `00-CLAUDE.md` ห้ามแก้ schema ในโค้ดเองทันที

> ⚠️ **โมดูลนี้มีความเสี่ยงด้านเทคนิคสูงสุดในระบบทั้งหมด** ต้องอ่านหัวข้อ "ข้อจำกัดและความเสี่ยง" ก่อน implement จริง

---

## คอมโพเนนต์ย่อย (เรียงตามลำดับการทำงาน)

### 4.1 Platform Adapter Interface
**หน้าที่:** กำหนด contract กลางให้ทุก platform scraper ต้องเขียนตาม เพื่อให้เพิ่ม platform ใหม่ในอนาคตได้โดยไม่กระทบโค้ดเดิม

```php
interface PlatformAdapterInterface {
    /** ดึงข้อมูลราคา/สต็อกล่าสุดของสินค้า 1 ชิ้น */
    public function fetchProduct(string $platformProductId): ScrapedProductData;

    /** ค้นหาสินค้าจาก keyword (ใช้โดย Module 03 - Keyword Search) */
    public function search(string $keyword, int $limit = 10): array; // array of ScrapedProductData

    /** แยก platform_product_id จาก URL ของ platform นี้ */
    public function parseProductId(string $url): string;
}

class ScrapedProductData {
    public string $productName;
    public string $imageUrl;
    public float $price;
    public ?float $originalPrice;
    public ?float $discountPercent;
    public string $stockStatus; // 'in_stock' | 'out_of_stock' | 'unknown'
}
```

---

### 4.2 Shopee Scraper Component
**หน้าที่:** implement `PlatformAdapterInterface` เฉพาะ Shopee

**สถานะ:** ⚠️ ยังไม่ระบุ endpoint จริง — ต้อง inspect network traffic ของหน้าเว็บ Shopee จริงก่อนเขียนโค้ด (ดูหัวข้อ "แนวทาง" ด้านล่าง)

---

### 4.3 Lazada Scraper Component
**หน้าที่:** implement `PlatformAdapterInterface` เฉพาะ Lazada

**สถานะ:** ⚠️ เช่นเดียวกับ Shopee — ต้อง inspect endpoint จริงก่อน พัฒนาหลัง Shopee เสร็จสมบูรณ์แล้ว (ตามลำดับ Phase ใน `00-CLAUDE.md`)

---

### 4.3a TikTok Shop Scraper Component
**หน้าที่:** implement `PlatformAdapterInterface` เฉพาะ TikTok Shop

**เหตุผลที่เพิ่ม:** TikTok Shop เป็นแพลตฟอร์มที่เติบโตเร็วที่สุดในไทย มีส่วนแบ่งตลาดสูงจนแซง Lazada ขึ้นมาเป็นอันดับสอง ราคาสินค้าบางกลุ่ม (โดยเฉพาะที่มาจาก Live/Flash Sale) มักถูกกว่า platform อื่นชัดเจน

**สถานะ:** ⚠️ ยังไม่ระบุ endpoint จริง — ความยากสูงกว่า Shopee/Lazada เพราะโครงสร้างราคาผูกกับ Live Commerce และ session-based token มากกว่า ต้อง inspect network อย่างละเอียดก่อนเขียนโค้ด **แนะนำพัฒนาหลัง Shopee/Lazada เสถียรแล้ว**

---

### 4.3b Tier 2 Adapter Components (ร้านค้าเฉพาะทาง)
**หน้าที่:** implement `PlatformAdapterInterface` สำหรับร้านค้าเฉพาะทางที่ตรงกับงานจัดซื้อของ BSI/AWP/RSM มากกว่า marketplace ทั่วไป

**เหตุผลที่เพิ่มกลุ่มนี้:** เว็บกลุ่มนี้บางส่วน (ไม่ใช่ทั้งหมด — ดูผล inspect จริงด้านล่าง) เป็น traditional server-rendered ไม่มีระบบ anti-bot หนักเท่า Shopee/Lazada/TikTok — scrape ง่ายกว่ามาก จึงเหมาะเป็น platform แรกที่ใช้ทดสอบ `PlatformAdapterInterface` และ pipeline ทั้งหมด

> ⚠️ **ผลการ inspect จริง (ผ่าน web fetch):** ความยากจริงต่างจากที่ประเมินไว้เดิม โปรดใช้ตารางนี้แทนการประเมินคร่าวๆ ก่อนหน้า

| Adapter | ร้าน | ผลการ inspect จริง | สถานะ |
|---|---|---|---|
| `JibScraper` | JIB | **ยืนยันแล้ว** — เว็บเป็น server-rendered ธรรมดา ราคา/สต็อก/ชื่อสินค้าอยู่ใน HTML ที่ fetch ได้ตรงๆ ไม่ต้องใช้ headless browser | ✅ พร้อม implement |
| `BananaScraper` | Banana IT (โดเมนจริงคือ `bnn.in.th` ไม่ใช่ `banana-it.com`) | **ยืนยันแล้ว** — แม้ใช้ Nuxt.js (framework ที่มักเป็น SPA) แต่หน้ารายการสินค้า render แบบ SSR ราคาปรากฏใน HTML ที่ fetch ได้ตรงๆ | ✅ พร้อม implement |
| `AdviceScraper` | Advice | **แก้ไขผลประเมิน — ง่ายกว่าที่คิดตอนแรก!** หน้า **search/category** เป็น client-side rendered (fetch ได้แค่ "Loading...") แต่หน้า **product detail** กลับเป็น server-rendered เต็มรูปแบบ — ทดสอบ fetch จริงแล้วเจอราคา, รหัสสินค้า, สเปกครบใน HTML ตรงๆ (เช่น "รหัสสินค้า : A0175015" และ "฿24,990") | ✅ พร้อม implement (เฉพาะ fetch by URL/ID — ฟีเจอร์ค้นหาด้วย keyword ยังต้องหา internal API เพิ่ม) |
| `GlobalHouseScraper` | Global House | **ผลผสม** — หน้า product detail มี URL จริงคือ `/product/{slug}-i.{productCode}` และชื่อ/รายละเอียดสินค้าถูก index โดย Google (แปลว่า SSR อย่างน้อยบางส่วน) แต่ราคาในหลายหน้าที่ตรวจสอบแสดงข้อความ "กำลังโหลด..." แทนราคาจริง ซึ่งบ่งชี้ว่า **ราคาอาจโหลดผ่าน JS/API แยกต่างหาก** แม้เนื้อหาอื่นจะ SSR ก็ตาม (พบบางหน้า เช่น หน้ายิปซั่มบอร์ด ที่ราคาปรากฏตรงๆ — ไม่แน่ชัดว่าขึ้นกับปัจจัยใด) | ⚠️ ต้อง inspect network เพิ่มเพื่อหา endpoint ราคาที่แท้จริง |
| `HomeProScraper` | HomePro Online | **ยังไม่ยืนยันชัดเจน** — ผลค้นหาแสดงชื่อ/ราคาสินค้าจริง ซึ่งบ่งชี้ว่าอย่างน้อยบางหน้าอาจ SSR แต่ยังไม่ได้ทดสอบ fetch หน้าสินค้าโดยตรง | ⚠️ ต้อง fetch ทดสอบเพิ่มก่อนสรุป |
| `ThaiWatsaduScraper` | Thai Watsadu | **ยังไม่ได้ inspect** (อยู่ในเครือ Central Retail เดียวกับ HomePro อาจใช้ stack ใกล้เคียงกัน) | ⚠️ ยังไม่ inspect |
| `PowerBuyScraper` | Power Buy | **ยังไม่ได้ inspect** | ⚠️ ยังไม่ inspect |

**สรุปสำหรับวางแผน Phase:** เริ่ม implement `JibScraper`, `BananaScraper` และ `AdviceScraper` ก่อน (ยืนยันแล้วว่า fetch หน้า product detail ได้ตรงๆ ทั้งสามร้าน) ส่วน `GlobalHouseScraper` ต้อง inspect เพิ่มเรื่อง endpoint ราคาก่อนตัดสินใจ priority — HomePro, Thai Watsadu, Power Buy ยังต้อง inspect เพิ่มก่อนจัด priority

**ข้อมูล URL Pattern จริงที่ inspect ได้ (JIB, Banana IT, Advice, Global House):**

```
JIB product detail:
https://www.jib.co.th/web/product/readProduct/{productId}/{categoryId}/{slug}
ตัวอย่างจริง: https://www.jib.co.th/web/product/readProduct/82064/1338/NOTEBOOK--โน้ตบุ๊ค--LENOVO-LOQ-ESSENTIAL-15IRX11E-83SC003GTA---LUNA-GREY
→ platform_product_id = productId (เช่น "82064")
→ ราคา/ชื่อสินค้า/สต็อกอยู่ใน HTML ตรงๆ เช่น "46,990.- -9% 42,590.-" (ราคาปกติ / % ส่วนลด / ราคาหลังลด)

Banana IT (โดเมนจริง bnn.in.th ไม่ใช่ banana-it.com):
https://www.bnn.in.th/{lang}/p/{slug}-{numericCode}_{shortHash}
ตัวอย่างจริง: https://www.bnn.in.th/en/p/microsoft-surface-laptop-138in-m1016512-platinum-ep2-59033-196388720286_zpxl8e
→ platform_product_id = ส่วน {numericCode}_{shortHash} ท้าย slug (เช่น "196388720286_zpxl8e")
→ ราคาอยู่ใน HTML ตรงๆ เช่น "฿60,990 Save up to ฿3,500"

Advice product detail:
https://www.advice.co.th/product/{category}/{subcategory}/{slug}
ตัวอย่างจริง: https://www.advice.co.th/product/notebook/notebook-hp/notebook-hp-omnibook-3-15-fn0077au-glacier-silver-
→ platform_product_id = รหัสสินค้าที่อยู่ใน HTML (เช่น "A0175015") ไม่ได้อยู่ใน URL ตรงๆ ต้อง parse จาก HTML content ("รหัสสินค้า : A0175015") ไม่ใช่จาก URL
→ ราคาอยู่ใน HTML ตรงๆ ทั้งราคาออนไลน์และราคาหน้าร้าน (เช่น "ราคาเฉพาะออนไลน์ ฿24,990")
→ ⚠️ ต่างจาก platform อื่น: หน้า search/category (`/product/search`, `/product/{category}`) เป็น client-side rendered แต่หน้า product detail กลับ SSR — ห้ามใช้ URL Parser เดาว่าทั้งเว็บ scrape ยากเหมือนกันหมด

Global House product detail:
https://globalhouse.co.th/product/{slug}-i.{productCode}
ตัวอย่างจริง: https://globalhouse.co.th/product/ยิปไลน์-ยิปซั่มบอร์ด-ชนิดธรรมดา-9มม.x120x240-ซม.-ขอบลาด-i.8858909502123
→ platform_product_id = productCode ท้าย URL หลัง "-i." (เช่น "8858909502123" หรือรูปแบบ "GP630407-000006" สำหรับสินค้าประเภทบริการ)
→ ชื่อ/รายละเอียดสินค้าถูก index (น่าจะ SSR) แต่ราคาในหลายหน้าที่ตรวจสอบยังแสดง "กำลังโหลด..." แทนราคาจริง — ต้อง inspect network เพิ่มเพื่อหา endpoint ที่ใช้โหลดราคา
```

**ข้อกำหนดสำคัญ:** URL pattern ทั้งหมดนี้คือของจริงที่ inspect ได้จากเว็บ ณ วันที่ทำ spec ฉบับนี้ (กรกฎาคม 2569) — platform อาจเปลี่ยนโครงสร้าง URL ได้ในอนาคตโดยไม่แจ้งล่วงหน้าเช่นเดียวกับ marketplace ใหญ่ ต้องตรวจสอบซ้ำก่อน deploy จริงเสมอ



**ข้อกำหนดเพิ่มเติมก่อน Implement:**
1. ต้องขยาย ENUM `platform` ใน `tracked_products` (ดูคำเตือนด้านบนของไฟล์นี้) — เสนอค่าที่ต้องเพิ่ม: `'jib'`, `'banana'`, `'advice'`, `'globalhouse'`, `'homepro'`, `'thaiwatsadu'`, `'powerbuy'`
2. ต้องเพิ่ม pattern ใน **3.2 URL Parser** (`03-module-product-tracking.md`) ให้รู้จัก URL ของแต่ละร้านเพิ่มเติม
3. แนะนำให้พัฒนา Adapter กลุ่มนี้ **ก่อน** TikTok Shop (4.3a) เพราะความเสี่ยงด้านเทคนิคต่ำกว่า ใช้เป็นก้าวแรกพิสูจน์ว่า pipeline ทั้งระบบ (Rate Limiter, Queue, Error Handler, Alert) ทำงานถูกต้องจริง

---

### 4.4 Cron Scheduler Component
**หน้าที่:** รันงาน scraping อัตโนมัติตามรอบเวลาที่ตั้งค่าใน `system_settings.cron_interval_minutes`

**ไฟล์:** `cron/run_scraping_job.php`

**Flow:**
1. ดึงรายการ `tracked_products` ที่ `is_active = 1` และ `last_checked_at` เก่ากว่ารอบเวลาที่ตั้งไว้ (หรือยังไม่เคย scrape)
2. ส่งเข้า **4.7 Scraping Job Queue**
3. ทำงานผ่าน **4.6 Rate Limiter** เพื่อไม่ยิง request รัวเกินไป

**Crontab ตัวอย่าง:**
```
*/30 * * * * php /path/to/cron/run_scraping_job.php >> /path/to/logs/cron.log 2>&1
```
(รันทุก 30 นาที แล้วให้ script เช็คเองว่าสินค้าไหนถึงรอบแล้วจริงๆ ตาม `cron_interval_minutes`)

---

### 4.5 Manual Trigger Component
**หน้าที่:** ให้ user กดปุ่ม "ดึงราคาล่าสุด" แล้วได้ผลทันที (ไม่ต้องรอ cron)

**Flow:**
1. User กดปุ่มที่หน้า Tracking List (โมดูล 03) หรือ Admin Monitor (โมดูล 07)
2. เรียก API `POST /api/product_refresh.php` (ดู `08-api-reference.md`)
3. เช็คสิทธิ์: user ทั่วไปสั่งได้เฉพาะสินค้าที่ตนเอง track, admin สั่งได้ทุกสินค้า
4. เช็ค Rate Limiter ก่อนยิงจริง (กัน user spam กดปุ่ม)
5. เรียก Adapter ที่ตรง platform → บันทึกผล → ส่งกลับ JSON ทันที (แสดงราคาล่าสุด real-time บนหน้าเว็บโดยไม่ reload)

---

### 4.6 Rate Limiter Component
**หน้าที่:** จำกัดความถี่ request ต่อ platform ป้องกันโดนบล็อก IP

**แนวทาง implement:** เก็บ counter ใน table หรือไฟล์ cache ชั่วคราว (เช่น ใช้ตาราง `scraping_logs` นับจำนวนที่ status ล่าสุดใน 1 นาที เทียบกับค่า `rate_limit_per_minute_{platform}` ใน `system_settings`)

```
ก่อนยิง request:
  count = SELECT COUNT(*) FROM scraping_logs
          WHERE product_id IN (platform นี้) AND created_at > NOW() - INTERVAL 1 MINUTE
  IF count >= rate_limit_per_minute_{platform}:
      → เข้าคิวรอรอบถัดไป (ไม่ยิงทันที)
```

ควรมี delay สุ่มระหว่าง request แต่ละครั้ง (เช่น 1-3 วินาที) ไม่ยิงรัวติดกัน

---

### 4.7 Scraping Job Queue Component
**หน้าที่:** จัดคิวงาน scraping ไม่ให้ยิงพร้อมกันเกินขีดจำกัด

**แนวทางง่ายที่สุดสำหรับ PHP Native (ไม่ใช้ Redis/RabbitMQ):** ประมวลผลแบบ sequential loop ใน cron script โดยใส่ `usleep()` หน่วงระหว่างแต่ละรายการ ตาม Rate Limiter กำหนด — เพียงพอสำหรับ scale เริ่มต้น ถ้าสินค้าที่ติดตามเยอะมากในอนาคตค่อยพิจารณาระบบคิวจริงจัง

---

### 4.8 Error Handler & Retry Component
**หน้าที่:** จัดการกรณี scraping ล้มเหลว

**กรณีที่ต้องรองรับ:**
| กรณี | การจัดการ |
|---|---|
| สินค้าถูกลบ/URL ไม่มีอยู่แล้ว | บันทึก log, ตั้ง `stock_status = 'unknown'`, แจ้ง user ว่าสินค้าอาจถูกถอดออกจากระบบร้าน |
| Timeout / Network Error | Retry อัตโนมัติ 1 ครั้งหลัง delay 5 วินาที ถ้ายัง fail → บันทึก log แล้วรอรอบถัดไป |
| โดน Rate Limit / Block จาก platform (เช่น HTTP 403/429) | หยุดยิง platform นั้นชั่วคราว (เช่น 30 นาที) และแจ้งเตือน Admin ผ่าน Dashboard |
| โครงสร้าง Response เปลี่ยน (parse ไม่ได้) | บันทึก log พร้อม raw response (จำกัดขนาด) เพื่อ debug, แจ้งเตือน Admin ว่า Adapter อาจต้องอัปเดต |

---

### 4.9 Scraping Log Component
**หน้าที่:** บันทึกทุกครั้งที่มีการ scrape ลง `scraping_logs` (สำเร็จ/ล้มเหลว, เวลาใช้, ใครสั่ง)

ใช้แสดงผลใน **Admin Scraping Monitor** (โมดูล 07)

---

## ข้อจำกัดและความเสี่ยง (ต้องอ่านก่อน Implement)

> หัวข้อนี้เน้นความเสี่ยงของ **Shopee/Lazada/TikTok Shop** เป็นหลัก เนื่องจากเป็น SPA ที่มี anti-bot หนัก ส่วน **Tier 2 (4.3b)** ความเสี่ยงต่ำกว่ามาก เพราะเป็นเว็บ server-rendered แบบดั้งเดิม — แต่ก็ยังต้อง inspect โครงสร้าง HTML จริงก่อน implement ทุกครั้งเช่นกัน

1. **Shopee/Lazada/TikTok Shop เป็น SPA (JavaScript-rendered)** — ไม่สามารถ `file_get_contents()` หรือ cURL ดึง HTML แล้ว parse ตรงๆ ได้ผลลัพธ์ที่ถูกต้อง
2. **แนวทางแนะนำอันดับแรก:** เปิด DevTools → Network tab บนหน้าเว็บจริง หา internal API endpoint ที่หน้าเว็บเรียกตอนโหลดราคา (มักเป็น JSON REST/GraphQL endpoint) แล้วเรียก endpoint นั้นตรงๆ ผ่าน PHP cURL พร้อม header ที่จำเป็น (User-Agent, Referer ฯลฯ)
3. **Fallback ถ้า endpoint ป้องกันแน่นเกินไป (เช่น ต้องใช้ signature/token ที่คำนวณจาก JS):** ต้องใช้ Headless Browser (Puppeteer/Playwright บน Node.js) เป็น Microservice แยกต่างหาก รับ URL → คืนค่า JSON กลับมาให้ PHP เรียกผ่าน internal HTTP call — เป็นส่วนเสริมนอก PHP Native stack หลัก ต้องยืนยันกับผู้ใช้ก่อนว่า server รองรับการรัน Node.js หรือไม่
4. **ความเสี่ยงเรื่องการเปลี่ยนแปลงของ Platform:** Shopee/Lazada อาจเปลี่ยนโครงสร้าง endpoint/HTML ได้ตลอดเวลาโดยไม่แจ้งล่วงหน้า ทำให้ Adapter พังกะทันหัน — ควรมี Error Handler (4.8) ที่แจ้งเตือน Admin ทันทีเมื่อ parse ไม่ได้ ไม่ใช่ปล่อยให้เงียบ
5. **ความถี่การดึงข้อมูล** ควรตั้งค่าแบบระมัดระวัง (เช่น เริ่มที่ทุก 3-6 ชั่วโมง) แล้วค่อยปรับตามความจำเป็นจริง เพื่อลดความเสี่ยงการโดนจำกัดการเข้าถึง

## API ที่เกี่ยวข้อง

ดูรายละเอียดใน `08-api-reference.md` หมวด Scraping
