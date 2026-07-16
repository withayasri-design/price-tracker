# 08 — API Reference (Contract กลาง)

> ทุก endpoint คืนค่า JSON รูปแบบเดียวกัน: `{ "success": bool, "data": {...}|null, "message": string }`
> ทุก endpoint (ยกเว้น auth) ต้องผ่าน `requireLogin()` หรือ `requireAdmin()` ตาม `02-module-auth.md`

---

## Auth (`api/auth/`)

| Method | Endpoint | หน้าที่ | โมดูล |
|---|---|---|---|
| POST | `/api/auth/register.php` | สมัครสมาชิก | 2.1 |
| POST | `/api/auth/login.php` | เข้าสู่ระบบ | 2.2 |
| POST | `/api/auth/logout.php` | ออกจากระบบ | 2.2 |
| POST | `/api/auth/change_password.php` | เปลี่ยนรหัสผ่าน | 2.5 |
| PUT | `/api/auth/profile.php` | แก้ไขข้อมูลส่วนตัว | 2.5 |

## Product Tracking (`api/products/`)

| Method | Endpoint | หน้าที่ | โมดูล |
|---|---|---|---|
| POST | `/api/products/add.php` | เพิ่มสินค้าติดตามด้วย URL | 3.1 |
| POST | `/api/products/search.php` | ค้นหาสินค้าด้วย keyword | 3.3 |
| GET | `/api/products/list.php` | รายการสินค้าที่ user ติดตาม (รองรับ filter/sort) | 3.5 |
| PUT | `/api/products/threshold.php` | แก้ไข target_price/target_discount | 3.4 |
| PUT | `/api/products/toggle.php` | หยุดชั่วคราว/เปิดใช้งาน tracking | 3.5 |
| DELETE | `/api/products/remove.php` | ลบสินค้าออกจากการติดตาม (ของ user เอง) | 3.5 |

## Scraping (`api/scraping/`)

| Method | Endpoint | หน้าที่ | โมดูล |
|---|---|---|---|
| POST | `/api/scraping/refresh.php` | Manual trigger ดึงราคาสินค้า 1 ชิ้น | 4.5 |
| GET | `/api/admin/scraping_logs.php` | ดู log ทั้งระบบ (Admin เท่านั้น) | 4.9 / 7.5 |
| POST | `/api/admin/scraping_rerun.php` | สั่ง re-run job ที่ fail (Admin) | 7.5 |

## Price History (`api/history/`)

| Method | Endpoint | หน้าที่ | โมดูล |
|---|---|---|---|
| GET | `/api/history/trend.php?product_id=&range=` | ข้อมูลกราฟราคาย้อนหลัง | 5.2 |
| GET | `/api/history/lowest.php?product_id=` | ราคาต่ำสุดในประวัติ | 5.4 |
| GET | `/api/history/summary.php` | สรุปราคาที่เปลี่ยนแปลง 24ชม./7วัน ของ user | 5.5 |

## Notification (`api/notifications/`)

| Method | Endpoint | หน้าที่ | โมดูล |
|---|---|---|---|
| GET | `/api/notifications/list.php` | รายการแจ้งเตือนของ user | 6.5 |
| PUT | `/api/notifications/mark_read.php` | mark อ่านแล้ว (รายตัว/ทั้งหมด) | 6.4 |
| PUT | `/api/notifications/preference.php` | เปิด/ปิด email notification | 6.6 |

## Dashboard (`api/dashboard/`)

| Method | Endpoint | หน้าที่ | โมดูล |
|---|---|---|---|
| GET | `/api/dashboard/summary.php` | ข้อมูลสรุปหน้า Dashboard user | 7.1 |
| GET | `/api/admin/dashboard_summary.php` | ข้อมูลสรุป Admin Dashboard | 7.4 |

## Admin — User Management (`api/admin/`)

| Method | Endpoint | หน้าที่ | โมดูล |
|---|---|---|---|
| GET | `/api/admin/users_list.php` | รายชื่อ user ทั้งหมด | 2.4 |
| PUT | `/api/admin/user_role.php` | เปลี่ยน role | 2.4 |
| PUT | `/api/admin/user_status.php` | ระงับ/เปิดใช้งานบัญชี | 2.4 |
| GET | `/api/admin/settings.php` | อ่านค่า `system_settings` | 4.6 / 6.3 |
| PUT | `/api/admin/settings.php` | แก้ไขค่า `system_settings` | 4.6 / 6.3 |

---

## ตัวอย่าง Response Format

**Success:**
```json
{
  "success": true,
  "data": { "product_id": 123, "last_price": 259.00 },
  "message": "อัปเดตราคาล่าสุดสำเร็จ"
}
```

**Failure:**
```json
{
  "success": false,
  "data": null,
  "message": "ไม่พบสินค้านี้ในระบบ หรือคุณไม่มีสิทธิ์เข้าถึง"
}
```
