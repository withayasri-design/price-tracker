<?php
/**
 * 404 Not Found Error Page
 */

http_response_code(404);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - ไม่พบหน้าที่ต้องการ | Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .error-container {
            text-align: center;
            color: white;
            padding: 40px;
        }
        .error-code {
            font-size: 8rem;
            font-weight: 700;
            line-height: 1;
            text-shadow: 4px 4px 0 rgba(0,0,0,0.1);
        }
        .error-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .btn-home {
            background: white;
            color: #667eea;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            color: #764ba2;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas fa-search"></i>
        </div>
        <div class="error-code">404</div>
        <h2 class="mb-3">ไม่พบหน้าที่ต้องการ</h2>
        <p class="mb-4 opacity-75">
            หน้าที่คุณกำลังมองหาอาจถูกย้าย ลบ หรือไม่เคยมีอยู่<br>
            กรุณาตรวจสอบ URL อีกครั้ง
        </p>
        <a href="/pages/dashboard.php" class="btn btn-home">
            <i class="fas fa-home me-2"></i>กลับหน้าหลัก
        </a>
    </div>
</body>
</html>
