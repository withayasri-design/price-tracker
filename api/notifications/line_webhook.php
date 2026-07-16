<?php

/**
 * LINE Webhook Endpoint
 *
 * Receives webhook events from LINE platform:
 * - Follow/Unfollow events
 * - Message events (for bot commands)
 * - Postback events (for button actions)
 *
 * Set this URL in LINE Developers Console:
 * https://yourdomain.com/api/notifications/line_webhook.php
 */

declare(strict_types=1);

// Always return 200 OK to LINE (even on errors)
http_response_code(200);
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/line.php';
require_once __DIR__ . '/../../modules/notification/LineNotifier.php';

use Modules\Notification\LineNotifier;

try {
    // Get request body
    $body = file_get_contents('php://input');

    if (empty($body)) {
        exit(json_encode(['status' => 'no body']));
    }

    // Get LINE signature
    $signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

    // Get config
    $config = getLineConfig($pdo);

    if (empty($config['channel_secret'])) {
        error_log('LINE webhook: Channel secret not configured');
        exit(json_encode(['status' => 'not configured']));
    }

    // Verify signature
    if (!LineNotifier::verifySignature($body, $signature, $config['channel_secret'])) {
        error_log('LINE webhook: Invalid signature');
        exit(json_encode(['status' => 'invalid signature']));
    }

    // Parse events
    $data = json_decode($body, true);
    $events = $data['events'] ?? [];

    foreach ($events as $event) {
        handleEvent($event, $pdo, $config);
    }

    echo json_encode(['status' => 'ok', 'events_processed' => count($events)]);

} catch (Throwable $e) {
    error_log('LINE webhook error: ' . $e->getMessage());
    echo json_encode(['status' => 'error']);
}

/**
 * Handle a single LINE event.
 */
function handleEvent(array $event, PDO $pdo, array $config): void
{
    $type = $event['type'] ?? '';
    $lineUserId = LineNotifier::parseUserIdFromEvent($event);

    if (!$lineUserId) {
        return;
    }

    switch ($type) {
        case 'follow':
            handleFollow($lineUserId, $pdo, $config);
            break;

        case 'unfollow':
            handleUnfollow($lineUserId, $pdo);
            break;

        case 'message':
            handleMessage($event, $lineUserId, $pdo, $config);
            break;

        case 'postback':
            handlePostback($event, $lineUserId, $pdo, $config);
            break;
    }
}

/**
 * Handle follow event - welcome message.
 */
function handleFollow(string $lineUserId, PDO $pdo, array $config): void
{
    // Check if user already linked
    $stmt = $pdo->prepare("SELECT user_id, full_name FROM users WHERE line_user_id = :line_id");
    $stmt->execute(['line_id' => $lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $notifier = new LineNotifier($config['channel_access_token']);

    if ($user) {
        // Already linked - welcome back
        $message = $notifier->buildTextMessage(
            "สวัสดีครับ คุณ{$user['full_name']}! 👋\n\n" .
            "ยินดีต้อนรับกลับมา\n" .
            "คุณจะได้รับแจ้งเตือนเมื่อสินค้าที่ติดตามมีราคาลดลงครับ 📉"
        );
    } else {
        // Not linked - prompt to link
        $message = $notifier->buildTextMessage(
            "สวัสดีครับ! 👋\n\n" .
            "ขอบคุณที่เพิ่มเป็นเพื่อนครับ\n\n" .
            "กรุณาเชื่อมต่อบัญชีของคุณเพื่อรับแจ้งเตือนราคาสินค้า:\n" .
            "1. ไปที่เว็บไซต์ Price Tracker\n" .
            "2. เข้าสู่ระบบ แล้วไปที่ Profile\n" .
            "3. กดปุ่ม 'เชื่อมต่อ LINE'\n\n" .
            "หรือพิมพ์ 'link' เพื่อรับลิงก์เชื่อมต่อครับ"
        );
    }

    $notifier->pushMessage($lineUserId, [$message]);

    // Log follow event
    $pdo->prepare("
        INSERT INTO agent_logs (agent_type, log_level, message, context, created_at)
        VALUES ('alert_dispatch', 'info', 'LINE follow event', :context, NOW())
    ")->execute([
        'context' => json_encode(['line_user_id' => $lineUserId, 'is_linked' => $user !== false]),
    ]);
}

/**
 * Handle unfollow event.
 */
function handleUnfollow(string $lineUserId, PDO $pdo): void
{
    // Disable LINE notifications for this user
    $stmt = $pdo->prepare("
        UPDATE users
        SET notify_line = 0
        WHERE line_user_id = :line_id
    ");
    $stmt->execute(['line_id' => $lineUserId]);

    // Log unfollow
    $pdo->prepare("
        INSERT INTO agent_logs (agent_type, log_level, message, context, created_at)
        VALUES ('alert_dispatch', 'info', 'LINE unfollow event', :context, NOW())
    ")->execute([
        'context' => json_encode(['line_user_id' => $lineUserId]),
    ]);
}

/**
 * Handle message event - bot commands.
 */
function handleMessage(array $event, string $lineUserId, PDO $pdo, array $config): void
{
    $messageType = $event['message']['type'] ?? '';
    $text = $event['message']['text'] ?? '';

    if ($messageType !== 'text') {
        return;
    }

    $command = mb_strtolower(trim($text));
    $notifier = new LineNotifier($config['channel_access_token']);

    switch ($command) {
        case 'link':
        case 'เชื่อมต่อ':
            // Check if already linked
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE line_user_id = :line_id");
            $stmt->execute(['line_id' => $lineUserId]);

            if ($stmt->fetch()) {
                $message = $notifier->buildTextMessage("บัญชีของคุณเชื่อมต่อกับ LINE เรียบร้อยแล้วครับ ✅");
            } else {
                // Generate linking code
                $linkCode = generateLinkCode($lineUserId, $pdo);
                $message = $notifier->buildTextMessage(
                    "🔗 รหัสเชื่อมต่อของคุณคือ:\n\n" .
                    "📋 {$linkCode}\n\n" .
                    "วิธีใช้:\n" .
                    "1. ไปที่เว็บไซต์ Price Tracker\n" .
                    "2. เข้าสู่ระบบ → Profile → เชื่อมต่อ LINE\n" .
                    "3. กรอกรหัสด้านบน\n\n" .
                    "รหัสนี้ใช้ได้ 15 นาทีครับ"
                );
            }
            $notifier->pushMessage($lineUserId, [$message]);
            break;

        case 'status':
        case 'สถานะ':
            $stmt = $pdo->prepare("
                SELECT u.full_name,
                       (SELECT COUNT(*) FROM user_tracking ut WHERE ut.user_id = u.user_id AND ut.is_active = 1) as tracking_count
                FROM users u
                WHERE u.line_user_id = :line_id
            ");
            $stmt->execute(['line_id' => $lineUserId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $message = $notifier->buildTextMessage(
                    "📊 สถานะบัญชี\n\n" .
                    "👤 ชื่อ: {$user['full_name']}\n" .
                    "📦 สินค้าที่ติดตาม: {$user['tracking_count']} รายการ\n" .
                    "🔔 การแจ้งเตือน LINE: เปิด"
                );
            } else {
                $message = $notifier->buildTextMessage("ยังไม่ได้เชื่อมต่อบัญชี\nพิมพ์ 'link' เพื่อรับรหัสเชื่อมต่อครับ");
            }
            $notifier->pushMessage($lineUserId, [$message]);
            break;

        case 'help':
        case 'ช่วยเหลือ':
            $message = $notifier->buildTextMessage(
                "📖 คำสั่งที่ใช้ได้:\n\n" .
                "link - รับรหัสเชื่อมต่อบัญชี\n" .
                "status - ดูสถานะบัญชี\n" .
                "help - แสดงข้อความนี้\n\n" .
                "💡 เมื่อเชื่อมต่อแล้ว คุณจะได้รับแจ้งเตือนอัตโนมัติเมื่อสินค้าลดราคาครับ"
            );
            $notifier->pushMessage($lineUserId, [$message]);
            break;

        default:
            // Unknown command - send help
            $message = $notifier->buildTextMessage(
                "ไม่เข้าใจคำสั่งครับ 🤔\n\nพิมพ์ 'help' เพื่อดูคำสั่งที่ใช้ได้"
            );
            $notifier->pushMessage($lineUserId, [$message]);
    }
}

/**
 * Handle postback event - button actions.
 */
function handlePostback(array $event, string $lineUserId, PDO $pdo, array $config): void
{
    $data = $event['postback']['data'] ?? '';
    parse_str($data, $params);

    // Handle different postback actions
    $action = $params['action'] ?? '';

    switch ($action) {
        case 'view_product':
            // Log product view from LINE
            $productId = $params['product_id'] ?? 0;
            $pdo->prepare("
                INSERT INTO agent_logs (agent_type, log_level, message, context, created_at)
                VALUES ('alert_dispatch', 'info', 'LINE product view', :context, NOW())
            ")->execute([
                'context' => json_encode(['line_user_id' => $lineUserId, 'product_id' => $productId]),
            ]);
            break;

        case 'mute':
            // Mute notifications temporarily
            // Could implement notification pause feature
            break;
    }
}

/**
 * Generate a temporary link code for LINE account linking.
 */
function generateLinkCode(string $lineUserId, PDO $pdo): string
{
    $code = strtoupper(bin2hex(random_bytes(4))); // 8-character code

    // Store in system_settings temporarily (or create a separate linking table)
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_at)
        VALUES (:key, :value, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    $stmt->execute([
        'key' => 'line_link_' . $code,
        'value' => json_encode([
            'line_user_id' => $lineUserId,
            'expires_at' => date('Y-m-d H:i:s', time() + 900), // 15 minutes
        ]),
    ]);

    return $code;
}
