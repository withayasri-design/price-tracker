<?php

/**
 * API: Get price event statistics.
 *
 * GET /api/events/stats.php
 * Optional params:
 *   - days: number of days to look back (default: 7)
 *
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "by_type": { "price_drop": 42, "flash_sale": 5, ... },
 *     "by_platform": { "shopee": 30, "lazada": 20, ... },
 *     "total_events": 100,
 *     "undispatched_count": 15,
 *     "recent_drops": [...]
 *   }
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/Auth.php';

use Core\Auth;

try {
    // Require login
    Auth::requireLogin();

    $days = min(90, max(1, (int) ($_GET['days'] ?? 7)));

    // Events by type
    $stmt = $pdo->prepare("
        SELECT event_type, COUNT(*) as count
        FROM price_events
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        GROUP BY event_type
    ");
    $stmt->execute(['days' => $days]);
    $byType = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byType[$row['event_type']] = (int) $row['count'];
    }

    // Events by platform
    $stmt = $pdo->prepare("
        SELECT tp.platform, COUNT(*) as count
        FROM price_events pe
        JOIN tracked_products tp ON pe.product_id = tp.product_id
        WHERE pe.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        GROUP BY tp.platform
        ORDER BY count DESC
    ");
    $stmt->execute(['days' => $days]);
    $byPlatform = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byPlatform[$row['platform']] = (int) $row['count'];
    }

    // Total events
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM price_events
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
    ");
    $stmt->execute(['days' => $days]);
    $totalEvents = (int) $stmt->fetchColumn();

    // Undispatched count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM price_events WHERE is_dispatched = 0
    ");
    $stmt->execute();
    $undispatchedCount = (int) $stmt->fetchColumn();

    // Recent significant drops (top 10)
    $stmt = $pdo->prepare("
        SELECT pe.event_id, pe.event_type, pe.old_price, pe.new_price, pe.change_percent,
               pe.created_at,
               tp.product_name, tp.platform, tp.product_url
        FROM price_events pe
        JOIN tracked_products tp ON pe.product_id = tp.product_id
        WHERE pe.event_type IN ('price_drop', 'flash_sale', 'lowest_ever')
          AND pe.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        ORDER BY ABS(pe.change_percent) DESC, pe.created_at DESC
        LIMIT 10
    ");
    $stmt->execute(['days' => $days]);
    $recentDrops = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recentDrops as &$drop) {
        $drop['old_price'] = $drop['old_price'] ? (float) $drop['old_price'] : null;
        $drop['new_price'] = (float) $drop['new_price'];
        $drop['change_percent'] = $drop['change_percent'] ? (float) $drop['change_percent'] : null;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'days' => $days,
            'by_type' => $byType,
            'by_platform' => $byPlatform,
            'total_events' => $totalEvents,
            'undispatched_count' => $undispatchedCount,
            'recent_drops' => $recentDrops,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
    ], JSON_UNESCAPED_UNICODE);
}
