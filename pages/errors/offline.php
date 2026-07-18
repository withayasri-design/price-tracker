<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - Price Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .offline-card {
            background: white;
            border-radius: 20px;
            padding: 3rem;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 400px;
        }
        .offline-icon {
            font-size: 5rem;
            color: #6c757d;
            margin-bottom: 1rem;
        }
        .retry-btn {
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="offline-card">
        <div class="offline-icon">
            <i class="bi bi-wifi-off"></i>
        </div>
        <h2>ไม่มีการเชื่อมต่ออินเทอร์เน็ต</h2>
        <p class="text-muted">
            กรุณาตรวจสอบการเชื่อมต่อของคุณแล้วลองอีกครั้ง
        </p>
        <p class="text-muted small">
            <i class="bi bi-info-circle"></i>
            คุณสามารถดูข้อมูลที่แคชไว้ได้ขณะออฟไลน์
        </p>
        <button onclick="window.location.reload()" class="btn btn-primary btn-lg retry-btn">
            <i class="bi bi-arrow-clockwise"></i> ลองอีกครั้ง
        </button>
    </div>

    <script>
        // Auto-retry when online
        window.addEventListener('online', () => {
            window.location.reload();
        });
    </script>
</body>
</html>
