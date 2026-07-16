<?php

/**
 * Price Tracker Landing Page
 *
 * Public landing page showing features and call-to-action.
 */

declare(strict_types=1);

require_once __DIR__ . '/core/Auth.php';

use Core\Auth;

// Redirect logged-in users to dashboard
if (Auth::check()) {
    header('Location: /pages/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price Tracker - ติดตามราคาสินค้าอัจฉริยะ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .hero-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 100px 0;
        }
        .feature-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2rem;
        }
        .platform-badge {
            font-size: 1.5rem;
            margin: 0 0.5rem;
            opacity: 0.8;
        }
        .platform-badge:hover {
            opacity: 1;
        }
        .cta-section {
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="/">
                <i class="fas fa-chart-line me-2"></i>Price Tracker
            </a>
            <div class="ms-auto">
                <a href="/pages/login.php" class="btn btn-outline-light me-2">เข้าสู่ระบบ</a>
                <a href="/pages/register.php" class="btn btn-primary">สมัครสมาชิก</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section text-center">
        <div class="container">
            <h1 class="display-4 fw-bold mb-4">ติดตามราคาสินค้าอัจฉริยะ</h1>
            <p class="lead mb-4 opacity-75">
                ไม่พลาดทุกดีลราคาดี แจ้งเตือนทันทีเมื่อราคาลดตามที่คุณตั้งไว้
            </p>
            <div class="mb-4">
                <span class="platform-badge text-warning"><i class="fas fa-store"></i> Shopee</span>
                <span class="platform-badge text-info"><i class="fas fa-shopping-bag"></i> Lazada</span>
                <span class="platform-badge"><i class="fab fa-tiktok"></i> TikTok Shop</span>
                <span class="platform-badge text-success"><i class="fas fa-laptop"></i> JIB</span>
                <span class="platform-badge text-warning"><i class="fas fa-desktop"></i> Banana IT</span>
            </div>
            <a href="/pages/register.php" class="btn btn-light btn-lg px-5">
                <i class="fas fa-rocket me-2"></i>เริ่มต้นใช้งานฟรี
            </a>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-5">
        <div class="container">
            <h2 class="text-center mb-5">ฟีเจอร์หลัก</h2>
            <div class="row">
                <div class="col-md-4 text-center mb-4">
                    <div class="feature-icon bg-primary text-white">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h4>แจ้งเตือนราคาลด</h4>
                    <p class="text-muted">
                        ตั้งราคาเป้าหมายหรือ % ส่วนลดที่ต้องการ
                        ระบบจะแจ้งเตือนทันทีเมื่อราคาถึงเป้าหมาย
                    </p>
                </div>
                <div class="col-md-4 text-center mb-4">
                    <div class="feature-icon bg-success text-white">
                        <i class="fas fa-chart-area"></i>
                    </div>
                    <h4>กราฟประวัติราคา</h4>
                    <p class="text-muted">
                        ดูย้อนหลังราคาสินค้าตลอด 30-90 วัน
                        รู้ว่าราคาตอนนี้ถูกจริงหรือเปล่า
                    </p>
                </div>
                <div class="col-md-4 text-center mb-4">
                    <div class="feature-icon bg-info text-white">
                        <i class="fas fa-sync-alt"></i>
                    </div>
                    <h4>เปรียบเทียบข้ามแพลตฟอร์ม</h4>
                    <p class="text-muted">
                        เทียบราคาสินค้าเดียวกันจากหลายร้านค้า
                        เลือกซื้อจากที่ถูกที่สุด
                    </p>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 text-center mb-4">
                    <div class="feature-icon bg-warning text-white">
                        <i class="fab fa-line"></i>
                    </div>
                    <h4>แจ้งเตือนผ่าน LINE</h4>
                    <p class="text-muted">
                        เชื่อมต่อ LINE OA รับแจ้งเตือนราคาลดทันที
                        พร้อมปุ่มกดซื้อได้เลย
                    </p>
                </div>
                <div class="col-md-4 text-center mb-4">
                    <div class="feature-icon bg-danger text-white">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h4>ตรวจจับ Flash Sale</h4>
                    <p class="text-muted">
                        ระบบตรวจจับโปรโมชั่น Flash Sale อัตโนมัติ
                        ไม่พลาดดีลหมดไวก่อนใคร
                    </p>
                </div>
                <div class="col-md-4 text-center mb-4">
                    <div class="feature-icon bg-secondary text-white">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h4>ปลอดภัย & ฟรี</h4>
                    <p class="text-muted">
                        ไม่ต้องใส่รหัส Login ของแพลตฟอร์ม
                        ใช้งานได้ฟรีไม่มีค่าใช้จ่าย
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="py-5 bg-light">
        <div class="container">
            <h2 class="text-center mb-5">วิธีใช้งาน</h2>
            <div class="row justify-content-center">
                <div class="col-md-3 text-center mb-4">
                    <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3" style="width: 50px; height: 50px; font-size: 1.5rem; font-weight: bold;">1</div>
                    <h5>สมัครสมาชิก</h5>
                    <p class="text-muted small">ใช้อีเมลของคุณสร้างบัญชีฟรี</p>
                </div>
                <div class="col-md-3 text-center mb-4">
                    <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3" style="width: 50px; height: 50px; font-size: 1.5rem; font-weight: bold;">2</div>
                    <h5>เพิ่มสินค้า</h5>
                    <p class="text-muted small">วาง URL สินค้าจากร้านค้าออนไลน์</p>
                </div>
                <div class="col-md-3 text-center mb-4">
                    <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3" style="width: 50px; height: 50px; font-size: 1.5rem; font-weight: bold;">3</div>
                    <h5>ตั้งราคาเป้าหมาย</h5>
                    <p class="text-muted small">กำหนดราคาหรือ % ที่ต้องการ</p>
                </div>
                <div class="col-md-3 text-center mb-4">
                    <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3" style="width: 50px; height: 50px; font-size: 1.5rem; font-weight: bold;">4</div>
                    <h5>รอรับแจ้งเตือน</h5>
                    <p class="text-muted small">ระบบจะแจ้งเมื่อราคาลด!</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section py-5 text-center">
        <div class="container">
            <h2 class="mb-4">เริ่มติดตามราคาสินค้าวันนี้</h2>
            <p class="text-muted mb-4">ฟรี! ไม่มีค่าใช้จ่าย ไม่ต้องใส่บัตรเครดิต</p>
            <a href="/pages/register.php" class="btn btn-primary btn-lg px-5">
                <i class="fas fa-user-plus me-2"></i>สมัครสมาชิกฟรี
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark text-white-50 py-4">
        <div class="container text-center">
            <p class="mb-2">
                <i class="fas fa-chart-line me-1"></i>Price Tracker
            </p>
            <p class="small mb-0">
                &copy; <?= date('Y') ?> Price Tracker. All rights reserved.
            </p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
