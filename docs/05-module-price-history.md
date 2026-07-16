# 05 — Module: Price History & Comparison

**เป้าหมายของโมดูล:** เก็บประวัติราคาทุกครั้งที่ดึงข้อมูลสำเร็จ เพื่อให้ผู้ใช้ดูแนวโน้มและเปรียบเทียบราคาระหว่าง Platform ก่อนตัดสินใจซื้อ

**ผลลัพธ์ที่ผู้ใช้ได้รับ:** กราฟแนวโน้มราคาย้อนหลัง + ตารางเปรียบเทียบราคาสินค้าจากหลาย Platform

**ตารางที่เกี่ยวข้อง:** `price_history`, `tracked_products`

**Dependency:** ข้อมูลถูกสร้างโดย `04-module-scraping-engine` ทุกครั้งที่ scrape สำเร็จ

---

## คอมโพเนนต์ย่อย (เรียงตามลำดับการทำงาน)

### 5.1 Price Snapshot Recorder
**หน้าที่:** บันทึกราคาลง `price_history` ทุกครั้งที่ Scraping Engine ดึงข้อมูลสำเร็จ

**Flow (เรียกจากโมดูล 04 หลัง fetch สำเร็จ):**
```php
function recordPriceSnapshot(int $productId, ScrapedProductData $data): void {
    // 1. Insert price_history
    // 2. Update tracked_products.last_price, last_original_price,
    //    last_stock_status, last_checked_at
    // 3. เรียกต่อไปยัง Module 06 (Threshold Checker) เพื่อเช็คว่าต้องแจ้งเตือนหรือไม่
}
```
> หมายเหตุ: บันทึกทุกครั้งแม้ราคาจะไม่เปลี่ยนแปลง (เพื่อให้กราฟแนวโน้มมีจุดข้อมูลสม่ำเสมอ) — ถ้ากังวลขนาดฐานข้อมูลโตเร็วเกินไป พิจารณาบันทึกเฉพาะตอนราคาเปลี่ยน + เก็บ "last_checked_at" แยกไว้ที่ตารางหลักแทน (ตัดสินใจตอน implement จริงตามปริมาณสินค้า)

---

### 5.2 Price Trend Chart Component
**หน้าที่:** แสดงกราฟราคาย้อนหลังของสินค้า 1 ชิ้น (ใช้ Chart.js)

**Input:** product_id, ช่วงเวลา (7 / 30 / 90 วัน)
**Query:**
```sql
SELECT price, scraped_at FROM price_history
WHERE product_id = ? AND scraped_at >= ?
ORDER BY scraped_at ASC;
```
**Output:** Line chart แกน X = วันที่, แกน Y = ราคา, มี marker จุดราคาต่ำสุด

---

### 5.3 Cross-Platform Comparison Component
**หน้าที่:** เทียบราคาสินค้าที่ user มองว่าเป็น "สินค้าเดียวกัน" ระหว่าง Shopee กับ Lazada

**แนวทาง:** เนื่องจากไม่มีทางจับคู่สินค้าข้าม platform ได้อัตโนมัติแม่นยำ 100% (ชื่อ/SKU ต่างกัน) จึงออกแบบให้เป็น **การจับคู่ด้วยมือ (manual link)**:
- User สามารถ "จับคู่" 2 tracking item ของตนเอง (1 จาก Shopee, 1 จาก Lazada) ว่าเป็นสินค้าเดียวกัน ผ่านตาราง comparison group (เพิ่มตารางเสริมถ้าต้องการ — ต้องยืนยัน schema ก่อนเพิ่มตามกฎในไฟล์ `01-database-schema.md`)
- แสดงราคาทั้งสอง platform เทียบกันในการ์ดเดียว พร้อม badge "ถูกกว่า" ที่ platform ที่ราคาต่ำกว่า

> ⚠️ ฟีเจอร์นี้ต้องเพิ่มตารางใหม่ (เช่น `comparison_groups`) ซึ่งไม่มีใน `01-database-schema.md` ฉบับปัจจุบัน — **ต้องแจ้งผู้ใช้และขอยืนยัน schema เพิ่มก่อน implement** ตามกฎในไฟล์ CLAUDE.md

---

### 5.4 Lowest Price Tracker Component
**หน้าที่:** คำนวณและแสดง "ราคาต่ำสุดที่เคยพบ" ของสินค้าแต่ละชิ้น

**Query:**
```sql
SELECT MIN(price) AS lowest_price, scraped_at
FROM price_history
WHERE product_id = ?
ORDER BY price ASC LIMIT 1;
```
แสดงคู่กับราคาปัจจุบันเพื่อให้ user เห็นว่าตอนนี้ "ใกล้ราคาต่ำสุดในประวัติ" แค่ไหน (เช่น "ตอนนี้สูงกว่าราคาต่ำสุด 12%")

---

### 5.5 Price Change Summary Component
**หน้าที่:** สรุปราคาที่เปลี่ยนแปลงในรอบ 24 ชม./7 วัน สำหรับใช้ในหน้า Dashboard (โมดูล 07)

**Logic:** เทียบราคาล่าสุด (`last_price`) กับราคาที่บันทึกไว้เมื่อ 24 ชม./7 วันก่อน จาก `price_history` แล้วคำนวณ % เปลี่ยนแปลง จัดเรียงสินค้าที่ลดราคามากที่สุดไว้บนสุด

---

## API ที่เกี่ยวข้อง

ดูรายละเอียดใน `08-api-reference.md` หมวด Price History
