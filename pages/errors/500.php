<?php
/**
 * 500 Internal Server Error Page
 */

http_response_code(500);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - เกิดข้อผิดพลาด | Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #e53935 0%, #c62828 100%);
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
            animation: shake 0.5s ease-in-out infinite;
        }
        @keyframes shake {
            0%, 100% { transform: rotate(0deg); }
            25% { transform: rotate(-5deg); }
            75% { transform: rotate(5deg); }
        }
        .btn-home {
            background: white;
            color: #e53935;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            color: #c62828;
        }
        .btn-refresh {
            background: transparent;
            color: white;
            border: 2px solid white;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: 600;
            transition: background 0.2s;
        }
        .btn-refresh:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="error-code">500</div>
        <h2 class="mb-3">เกิดข้อผิดพลาดภายในระบบ</h2>
        <p class="mb-4 opacity-75">
            ขออภัย เกิดข้อผิดพลาดที่ไม่คาดคิด<br>
            ทีมงานได้รับแจ้งและกำลังดำเนินการแก้ไข
        </p>
        <div class="d-flex gap-3 justify-content-center flex-wrap">
            <a href="/pages/dashboard.php" class="btn btn-home">
                <i class="fas fa-home me-2"></i>กลับหน้าหลัก
            </a>
            <button onclick="location.reload()" class="btn btn-refresh">
                <i class="fas fa-redo me-2"></i>ลองใหม่อีกครั้ง
            </button>
        </div>
    </div>
</body>
</html>
