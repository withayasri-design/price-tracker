# 02 — Module: Authentication & User Management

**เป้าหมายของโมดูล:** ผู้ใช้ Login เข้าระบบได้อย่างปลอดภัย และ Admin สามารถจัดการสิทธิ์ผู้ใช้ทั้งระบบได้

**ผลลัพธ์ที่ผู้ใช้ได้รับ:** บัญชีผู้ใช้ที่ปลอดภัย เข้าใช้งานได้ตามสิทธิ์ Role ของตนเอง

**ตารางที่เกี่ยวข้อง:** `users` (ดู `01-database-schema.md`)

---

## คอมโพเนนต์ย่อย (เรียงตามลำดับการทำงาน)

### 2.1 Register Component
**หน้าที่:** รับสมัครสมาชิกใหม่

**Input:** email, password, confirm_password, full_name
**Flow:**
1. Validate รูปแบบ email + password (ขั้นต่ำ 8 ตัวอักษร)
2. เช็ค email ซ้ำใน `users`
3. Hash password ด้วย `password_hash(PASSWORD_BCRYPT)`
4. Insert `users` (role default = `user`, is_active = 1)
5. (ทางเลือก) ส่ง email ยืนยันบัญชี — ไม่บังคับใน Phase 1

**Output:** `{ success: true, message: "สมัครสมาชิกสำเร็จ" }` → redirect ไปหน้า login

**Error Cases:** email ซ้ำ, password ไม่ตรงกัน, format ผิด

---

### 2.2 Login Component
**หน้าที่:** ตรวจสอบ credential และสร้าง session

**Input:** email, password
**Flow:**
1. ค้นหา user จาก email
2. เช็ค `is_active = 1`
3. `password_verify()` เทียบ password
4. สร้าง session: `$_SESSION['user_id']`, `$_SESSION['role']`, `$_SESSION['full_name']`
5. Redirect: role=admin → `admin/dashboard.php`, role=user → `dashboard.php`

**Error Cases:** email ไม่พบ, password ผิด, บัญชีถูกระงับ (is_active=0) → แสดงข้อความแยกกันแต่ไม่บอกรายละเอียดเกินไป (กัน user enumeration): ใช้ข้อความรวม "อีเมลหรือรหัสผ่านไม่ถูกต้อง" ยกเว้นกรณีบัญชีถูกระงับให้แจ้งชัดเจนว่า "บัญชีถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ"

---

### 2.3 Session Guard Component
**หน้าที่:** ป้องกันการเข้าถึงหน้า/API โดยไม่ได้ login หรือข้ามสิทธิ์

**Flow (เรียกใช้ทุกหน้าที่ต้อง login):**
```php
function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }
}
```
ใช้ `requireLogin()` ทุกหน้า user, ใช้ `requireAdmin()` ทุกหน้า/API ใน `pages/admin/` และ `api/admin/`

---

### 2.4 Role Management Component (Admin)
**หน้าที่:** Admin จัดการสิทธิ์และสถานะผู้ใช้

**ฟีเจอร์:**
- ดูรายชื่อผู้ใช้ทั้งหมด พร้อม role, สถานะ, จำนวนสินค้าที่ติดตาม
- เปลี่ยน role (`admin` ↔ `user`)
- ระงับ/เปิดใช้งานบัญชี (`is_active`)
- **ห้าม** ให้ admin ลด role ตัวเองจนไม่เหลือ admin คนสุดท้ายในระบบ (validate ก่อน update)

**API ที่เกี่ยวข้อง:** ดู `08-api-reference.md` หมวด Admin — User Management

---

### 2.5 Profile Component
**หน้าที่:** ผู้ใช้จัดการข้อมูลส่วนตัวของตนเอง

**ฟีเจอร์:**
- แก้ไข full_name
- เปลี่ยน password (ต้องกรอก password เดิมก่อน)
- เปิด/ปิด `notify_email` (รับแจ้งเตือนทาง Email หรือไม่ — เป็นค่า global เริ่มต้น ผูกกับโมดูล 06)

---

## Non-Functional Requirements เฉพาะโมดูลนี้

- CSRF token ทุกฟอร์ม (login, register, change password, admin role change)
- Session timeout: กำหนดค่า idle timeout (แนะนำ 60 นาที) แล้ว regenerate session id หลัง login (`session_regenerate_id(true)`) กัน session fixation
- Rate limit การ login ผิดซ้ำ (แนะนำ: ล็อกชั่วคราวหลังผิด 5 ครั้งใน 15 นาที) — ป้องกัน brute force
