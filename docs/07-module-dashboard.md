# 07 — Module: Dashboard & Reporting

**เป้าหมายของโมดูล:** ให้ผู้ใช้เห็นภาพรวมสินค้าที่ติดตาม สถานะราคา และแจ้งเตือนล่าสุดในหน้าเดียว เพื่อใช้ตัดสินใจซื้อได้ทันที และให้ Admin มองเห็นสุขภาพของระบบ Scraping ทั้งหมด

**ผลลัพธ์ที่ผู้ใช้ได้รับ:** หน้าจอสรุปที่ครบถ้วน ใช้งานได้ทันทีโดยไม่ต้องไล่ดูทีละหน้า

**ตารางที่เกี่ยวข้อง:** อ่านข้อมูลรวมจาก `tracked_products`, `user_tracking`, `price_history`, `alerts`, `scraping_logs`, `users`

**Dependency:** เป็นโมดูลรวมผลลัพธ์จากทุกโมดูลก่อนหน้า ควรพัฒนาเป็นลำดับสุดท้าย

---

## คอมโพเนนต์ย่อย

### 7.1 User Dashboard Component
**หน้าที่:** การ์ดสรุปภาพรวมสำหรับ user ทั่วไป

**แสดง:**
- จำนวนสินค้าที่ติดตามทั้งหมด (active/paused)
- จำนวนแจ้งเตือนใหม่ที่ยังไม่อ่าน
- สินค้าที่ลดราคาล่าสุด 5 อันดับ (จาก Module 05 - 5.5 Price Change Summary)
- สินค้าที่ใกล้ราคาต่ำสุดในประวัติมากที่สุด (โอกาสซื้อดี)

---

### 7.2 Product Card Grid Component
**หน้าที่:** แสดงสินค้าที่ติดตามทั้งหมดเป็นการ์ด

**ต่อการ์ดแสดง:** รูปสินค้า, ชื่อ, platform badge (Shopee/Lazada), ราคาปัจจุบัน, ราคาเดิม (ขีดฆ่า), % เปลี่ยนแปลงจากครั้งก่อน (สีเขียว/แดง), ปุ่ม "ดึงราคาล่าสุด" (manual trigger → Module 04), ปุ่มดูกราฟราคา (→ Module 05 - 5.2)

---

### 7.3 Notification Center Component
**หน้าที่:** กระดิ่งแจ้งเตือนที่ header + หน้ารายการแจ้งเตือนเต็ม

เชื่อมกับ Module 06 (6.4, 6.5) โดยตรง — ดึงข้อมูลจาก `alerts`

---

### 7.4 Admin Dashboard Component
**หน้าที่:** ภาพรวมทั้งระบบสำหรับ Admin

**แสดง:**
- จำนวน user ทั้งหมด (active/suspended)
- จำนวนสินค้าที่ติดตามทั้งระบบ แยกตาม platform
- Success/Fail rate ของ scraping job ใน 24 ชม.ล่าสุด (จาก `scraping_logs`)
- กราฟจำนวน alert ที่เกิดขึ้นต่อวัน (ย้อนหลัง 30 วัน)

---

### 7.5 Scraping Monitor Component (Admin)
**หน้าที่:** ดู log การ scrape แบบละเอียด และจัดการปัญหา

**ฟีเจอร์:**
- ตาราง log ล่าสุด (product, platform, status, duration, error_message, เวลา)
- Filter: platform, status (success/failed), ช่วงเวลา
- ปุ่ม "Re-run" สำหรับ job ที่ fail (เรียก Manual Trigger จาก Module 04 - 4.5)
- แสดงสถานะ Rate Limiter ปัจจุบันของแต่ละ platform (ยิงไปแล้วกี่ครั้งในนาทีนี้ เทียบกับ limit)
- ปุ่มตั้งค่า Cron Interval และ Rate Limit (เขียนลง `system_settings`)

---

## Layout สรุป

```
User Dashboard (dashboard.php)
├── 7.1 Summary Cards
├── 7.2 Product Card Grid (พร้อม filter/sort)
└── 7.3 Notification Center (dropdown ที่ header)

Admin Dashboard (admin/dashboard.php)
├── 7.4 System Overview Cards
├── 7.4 Alert Trend Chart
└── Link ไปหน้า:
    ├── admin/users.php (Module 02 - 2.4 Role Management)
    └── admin/scraping_monitor.php (7.5)
```

## API ที่เกี่ยวข้อง

ดูรายละเอียดใน `08-api-reference.md` หมวด Dashboard และ Admin
