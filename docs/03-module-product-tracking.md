# 03 — Module: Product Tracking Management

**เป้าหมายของโมดูล:** ผู้ใช้เพิ่ม/จัดการสินค้าที่ต้องการติดตามราคาได้อย่างยืดหยุ่น ไม่ว่าจะมี URL อยู่แล้วหรือแค่รู้ชื่อสินค้า

**ผลลัพธ์ที่ผู้ใช้ได้รับ:** รายการสินค้าที่ติดตามอยู่ พร้อมราคาล่าสุดและเงื่อนไขแจ้งเตือนที่ตั้งไว้

**ตารางที่เกี่ยวข้อง:** `tracked_products`, `user_tracking` (ดู `01-database-schema.md`)

**Dependency:** โมดูลนี้เรียกใช้ `04-module-scraping-engine` เพื่อดึงราคาครั้งแรกตอนเพิ่มสินค้า

---

## คอมโพเนนต์ย่อย (เรียงตามลำดับการทำงาน)

### 3.1 Add Tracking Item Component
**หน้าที่:** จุดเริ่มต้นการเพิ่มสินค้าใหม่ — รับ URL โดยตรง

**Input:** product_url, label (optional), target_price (optional), target_discount_percent (optional)

**Flow:**
1. ส่ง URL ไปที่ **3.2 URL Parser** เพื่อตรวจสอบ platform + ดึง platform_product_id
2. เช็คว่า `tracked_products` มีสินค้านี้อยู่แล้วหรือไม่ (`platform` + `platform_product_id`)
   - ถ้ามีอยู่แล้ว → เช็คว่า user คนนี้ track อยู่แล้วหรือยังใน `user_tracking` (กันซ้ำ) → ถ้ายัง insert `user_tracking` ใหม่ผูกกับ product เดิม
   - ถ้ายังไม่มี → insert `tracked_products` ใหม่ (ยังไม่มีราคา) → insert `user_tracking`
3. เรียก **Manual Trigger** (จากโมดูล 04) ทันทีเพื่อดึงราคาครั้งแรก แบบ synchronous หรือ queue ก็ได้ (แนะนำ queue ถ้ามีสินค้าจำนวนมากพร้อมกัน)
4. Redirect กลับหน้า Tracking List พร้อมข้อความสำเร็จ

**Error Cases:** URL ไม่ใช่ของ Shopee/Lazada, URL รูปแบบผิด, ดึงราคาครั้งแรกไม่สำเร็จ (ยัง insert รายการได้ แต่ label สถานะ "รอข้อมูล")

---

### 3.2 URL Parser Component
**หน้าที่:** แยกวิเคราะห์ URL เพื่อระบุ Platform และ Product ID

**Logic (ตัวอย่างแนวคิด — ต้องปรับตาม URL pattern จริงของแต่ละ platform ตอน implement):**
```php
function parseProductUrl(string $url): array {
    if (str_contains($url, 'shopee.co.th') || str_contains($url, 'shp.ee')) {
        // Shopee URL pattern: .../product-name-i.{shopId}.{itemId}
        preg_match('/i\.(\d+)\.(\d+)/', $url, $matches);
        return ['platform' => 'shopee', 'platform_product_id' => $matches[1] . '.' . $matches[2]];
    }
    if (str_contains($url, 'lazada.co.th')) {
        // Lazada URL pattern: .../products/...-i{itemId}-s{skuId}.html
        preg_match('/-i(\d+)-s(\d+)/', $url, $matches);
        return ['platform' => 'lazada', 'platform_product_id' => $matches[1] . '.' . $matches[2]];
    }
    if (str_contains($url, 'tiktok.com') && str_contains($url, '/shop/')) {
        // TikTok Shop URL pattern: ยังไม่ยืนยัน — ต้อง inspect จริงก่อน
        return ['platform' => 'tiktok', 'platform_product_id' => '<TODO: ยืนยัน pattern จริง>'];
    }
    if (str_contains($url, 'jib.co.th')) {
        // ยืนยันแล้ว (กรกฎาคม 2569): .../web/product/readProduct/{productId}/{catId}/{slug}
        preg_match('#readProduct/(\d+)/(\d+)/#', $url, $matches);
        return ['platform' => 'jib', 'platform_product_id' => $matches[1] ?? '<TODO: ตรวจสอบ URL>'];
    }
    if (str_contains($url, 'bnn.in.th')) {
        // ยืนยันแล้ว (กรกฎาคม 2569): โดเมนจริงคือ bnn.in.th ไม่ใช่ banana-it.com
        // .../{lang}/p/{slug}-{numericCode}_{shortHash}
        preg_match('#/p/[a-z0-9-]+-(\d+_[a-z0-9]+)#i', $url, $matches);
        return ['platform' => 'banana', 'platform_product_id' => $matches[1] ?? '<TODO: ตรวจสอบ URL>'];
    }
    if (str_contains($url, 'advice.co.th')) {
        // ยืนยันแล้ว (กรกฎาคม 2569): .../product/{category}/{subcategory}/{slug}
        // platform_product_id ("รหัสสินค้า") ไม่ได้อยู่ใน URL — ต้อง parse จาก HTML content หลัง fetch
        // เช่น: preg_match('/รหัสสินค้า\s*:\s*([A-Z0-9]+)/u', $html, $m)
        return ['platform' => 'advice', 'platform_product_id' => '<ต้อง fetch หน้า product ก่อนเพื่อดึงรหัสสินค้าจาก HTML>'];
    }
    if (str_contains($url, 'globalhouse.co.th')) {
        // ยืนยันแล้ว (กรกฎาคม 2569): .../product/{slug}-i.{productCode}
        preg_match('#-i\.([A-Za-z0-9-]+)$#', $url, $matches);
        return ['platform' => 'globalhouse', 'platform_product_id' => $matches[1] ?? '<TODO: ตรวจสอบ URL>'];
        // ⚠️ ราคาบางหน้ายังโหลดผ่าน JS แยก ("กำลังโหลด...") ต้อง inspect network เพิ่มก่อนใช้งานจริง
    }
    if (str_contains($url, 'homepro.co.th')) {
        return ['platform' => 'homepro', 'platform_product_id' => '<TODO: ยืนยัน pattern จริง>'];
    }
    if (str_contains($url, 'thaiwatsadu.com')) {
        return ['platform' => 'thaiwatsadu', 'platform_product_id' => '<TODO: ยืนยัน pattern จริง>'];
    }
    if (str_contains($url, 'powerbuy.co.th')) {
        return ['platform' => 'powerbuy', 'platform_product_id' => '<TODO: ยืนยัน pattern จริง>'];
    }
    throw new InvalidArgumentException('URL ไม่รองรับ ต้องเป็นหนึ่งใน platform ที่ระบบรองรับเท่านั้น');
}
```
> หมายเหตุ: pattern ของ JIB และ Banana IT (bnn.in.th) ยืนยันจากการ inspect เว็บจริงแล้วเมื่อกรกฎาคม 2569 ส่วน platform อื่นที่เหลือยังเป็น placeholder (`<TODO>`) รอการ inspect เพิ่มเติม — ดูรายละเอียดผลการ inspect ทั้งหมดใน `04-module-scraping-engine.md` หัวข้อ 4.3b ห้าม deploy โค้ดที่มี `<TODO>` ค้างอยู่

---

### 3.3 Keyword Search Component
**หน้าที่:** ถ้า user ไม่มี URL ให้ค้นหาจาก keyword แล้วเลือกสินค้าที่ตรง

**Flow:**
1. รับ keyword จาก user
2. เรียก Search API ของ Shopee/Lazada ผ่าน Scraper Adapter (ดู `04-module-scraping-engine.md` — ต้องมี method `search(keyword)` ใน Adapter)
3. แสดงผลลัพธ์เป็น grid การ์ด (รูป, ชื่อ, ราคา, platform)
4. User เลือกสินค้าที่ต้องการ → ระบบดึง URL/product_id ของสินค้านั้น → ส่งต่อไปยัง flow เดียวกับ **3.1 Add Tracking Item**

**หมายเหตุ:** เป็นฟีเจอร์ที่ซับซ้อนกว่า URL เพราะต้องพึ่ง Search Endpoint ของแต่ละ platform ซึ่งอาจมีข้อจำกัดด้าน anti-bot มากกว่าหน้ารายละเอียดสินค้า — ควรทำหลัง 3.1-3.2 เสร็จสมบูรณ์แล้ว

---

### 3.4 Threshold Setting Component
**หน้าที่:** ตั้ง/แก้ไขเงื่อนไขแจ้งเตือนของสินค้าที่ติดตาม (ต่อ user ต่อสินค้า)

**Input:** tracking_id, target_price (บาท) และ/หรือ target_discount_percent (%)
**Rule:** ต้องกรอกอย่างน้อย 1 เงื่อนไข (ราคา หรือ % ส่วนลด) — ถ้ากรอกทั้งคู่ ระบบแจ้งเตือนเมื่อเข้าเงื่อนไขใดเงื่อนไขหนึ่งก่อน (OR logic)

**Validation:** target_price ต้องน้อยกว่าราคาปัจจุบัน (`last_price`) ไม่งั้นแจ้งเตือนจะ trigger ทันที — แสดง warning ให้ user แต่ไม่บล็อกการบันทึก

---

### 3.5 Tracking List Component
**หน้าที่:** แสดงรายการสินค้าที่ user ติดตามทั้งหมด พร้อมจัดการ

**ฟีเจอร์:**
- แสดง: รูป, ชื่อสินค้า, platform badge, ราคาปัจจุบัน, เงื่อนไขที่ตั้งไว้, วันที่ scrape ล่าสุด
- Filter: platform, สถานะ (active/paused), เรียงตาม (ราคาต่ำสุด/ล่าสุดที่อัปเดต)
- Action ต่อรายการ: แก้ไขเงื่อนไข (3.4), หยุดชั่วคราว/เปิดใช้งาน (`is_active`), ลบออกจากการติดตาม, ปุ่ม "ดึงราคาล่าสุด" (manual trigger → โมดูล 04)

**หมายเหตุการลบ:** ลบแค่ record ใน `user_tracking` ของ user นั้น ไม่ลบ `tracked_products` กลาง (เผื่อ user อื่นยังติดตามอยู่) — ถ้าไม่มี user ใดติดตามสินค้านั้นเหลือเลย ให้ตั้ง `tracked_products.is_active = 0` แทนการลบจริง (soft delete เพื่อรักษาประวัติราคา)

---

## API ที่เกี่ยวข้อง

ดูรายละเอียด request/response เต็มใน `08-api-reference.md` หมวด Product Tracking
