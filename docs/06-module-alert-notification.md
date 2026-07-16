# 06 — Module: Alert & Notification

**เป้าหมายของโมดูล:** แจ้งเตือนผู้ใช้ทันทีที่ราคาสินค้าที่ติดตามถึงเงื่อนไขที่ตั้งไว้ ผ่านทั้ง Email และ Dashboard

**ผลลัพธ์ที่ผู้ใช้ได้รับ:** รู้ทันทีเมื่อสินค้าที่ติดตามลดราคาถึงเป้าหมาย ไม่พลาดโปรโมชั่น

**ตารางที่เกี่ยวข้อง:** `alerts`, `user_tracking`, `users`, `system_settings`

**Dependency:** ถูกเรียกต่อจาก `05-module-price-history.md` (5.1 Price Snapshot Recorder) ทุกครั้งที่บันทึกราคาใหม่สำเร็จ

---

## คอมโพเนนต์ย่อย (เรียงตามลำดับการทำงาน)

### 6.1 Threshold Checker Component
**หน้าที่:** เทียบราคาใหม่ที่เพิ่งบันทึกกับเงื่อนไขที่ user ตั้งไว้ทุกคนที่ track สินค้านั้น

**Flow:**
```php
function checkThresholds(int $productId, float $newPrice, ?float $originalPrice): void {
    $trackings = getUserTrackingsByProduct($productId); // ทุก user ที่ track สินค้านี้ และ is_active=1

    foreach ($trackings as $t) {
        $triggered = false;
        $alertType = null;

        if ($t->target_price !== null && $newPrice <= $t->target_price) {
            $triggered = true;
            $alertType = 'target_price';
        } elseif ($t->target_discount_percent !== null && $originalPrice) {
            $discount = (($originalPrice - $newPrice) / $originalPrice) * 100;
            if ($discount >= $t->target_discount_percent) {
                $triggered = true;
                $alertType = 'target_discount';
            }
        }

        if ($triggered && !alreadyAlertedForThisPrice($t->tracking_id, $newPrice)) {
            dispatchNotification($t, $newPrice, $alertType); // ส่งต่อไป 6.2
        }
    }
}
```

**สำคัญ — กันแจ้งเตือนซ้ำ:** ก่อนแจ้งเตือน ต้องเช็คว่าไม่เคยแจ้งเตือน "ราคานี้พอดี" มาก่อนสำหรับ tracking นี้ (เช็คจาก `alerts` ล่าสุดของ tracking_id นั้นว่า `price_at_alert` เท่ากับราคาปัจจุบันหรือไม่ หรือกำหนดกฎว่าจะไม่แจ้งซ้ำถ้าราคาไม่ได้ลดลงเพิ่มจากครั้งก่อน)

---

### 6.2 Notification Dispatcher Component
**หน้าที่:** ตัดสินใจว่าจะส่งแจ้งเตือนช่องทางไหนตามการตั้งค่าของ user

**Flow:**
```php
function dispatchNotification(UserTracking $t, float $price, string $alertType): void {
    $alertId = insertAlert($t->tracking_id, $price, $alertType); // บันทึกก่อนเสมอ (6.4)

    // Dashboard notification: สร้างเสมอ (ไม่มี opt-out เพราะเป็นช่องทางหลักที่ไม่รบกวน)
    // Email: เช็คว่า user เปิด notify_email หรือไม่ (จาก users.notify_email)
    if ($t->user->notify_email) {
        sendEmailAlert($t, $price, $alertType); // 6.3
        markAlertEmailSent($alertId);
    }
}
```

---

### 6.3 Email Alert Component
**หน้าที่:** ส่งอีเมลแจ้งเตือนผ่าน PHPMailer (SMTP)

**เนื้อหาอีเมลควรมี:**
- รูปสินค้า, ชื่อสินค้า
- ราคาเดิม (ก่อนติดตาม หรือราคาครั้งก่อนหน้า) vs ราคาใหม่
- % ส่วนลด
- ปุ่ม/ลิงก์ไปหน้าสินค้าโดยตรง (product_url)
- ลิงก์กลับมาที่ Dashboard ของระบบ

**Config:** อ่านค่า SMTP จาก `system_settings` (`smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass_encrypted`) — ห้าม hardcode credential ในโค้ด

**Error Handling:** ถ้าส่ง email ล้มเหลว (SMTP error) → log error แต่ไม่ทำให้ทั้ง flow scraping ล้มเหลวตาม (แยก try-catch อิสระ) — Dashboard notification ต้องถูกสร้างสำเร็จเสมอแม้ email จะส่งไม่ได้

---

### 6.4 In-App Notification Component
**หน้าที่:** สร้าง record ใน `alerts` และแสดงเป็นกระดิ่งแจ้งเตือนบน Dashboard

**สถานะ:** `is_read = 0` ตอนสร้าง → user กดอ่าน/คลิกดูสินค้า → update เป็น `is_read = 1`

---

### 6.5 Alert History Component
**หน้าที่:** แสดงประวัติการแจ้งเตือนทั้งหมดของ user (ไม่ใช่แค่ที่ยังไม่อ่าน)

**ฟีเจอร์:** filter ตามช่วงเวลา, ตามสินค้า, mark all as read

---

### 6.6 Notification Preference Component
**หน้าที่:** ผู้ใช้ตั้งค่าเปิด/ปิดการแจ้งเตือนทาง Email

**ระดับ:** Phase 1 ทำเป็น global setting เดียว (`users.notify_email`) ก่อน — ถ้าต้องการตั้งค่าราย item ในอนาคต ต้องเพิ่มคอลัมน์ใน `user_tracking` (ต้องยืนยัน schema เพิ่มก่อน implement ตามกฎในไฟล์ CLAUDE.md)

---

## Logic การแจ้งเตือนโดยสรุป (End-to-End)

```
[Module 04] Scraping Engine ดึงราคาสำเร็จ
        ↓
[Module 05 - 5.1] บันทึก price_history + update tracked_products
        ↓
[Module 06 - 6.1] Threshold Checker เทียบกับเงื่อนไขของทุก user ที่ track สินค้านี้
        ↓ (ถ้าเข้าเงื่อนไข และยังไม่เคยแจ้งเตือนราคานี้)
[Module 06 - 6.2] Notification Dispatcher
        ↓                           ↓
[6.4] Dashboard Notification   [6.3] Email (ถ้าเปิดใช้งาน)
        ↓
[6.5] บันทึกลง Alert History (กันแจ้งซ้ำในอนาคต)
```

## API ที่เกี่ยวข้อง

ดูรายละเอียดใน `08-api-reference.md` หมวด Notification
