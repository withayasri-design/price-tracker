<?php

/**
 * API: List price events.
 *
 * GET /api/events/list.php
 * Optional params:
 *   - type: filter by event_type (price_drop, flash_sale, lowest_ever, etc.)
 *   - product_id: filter by specific product
 *   - master_product_id: filter by master product (cross-platform)
 *   - limit: number of results (default: 50)
 *   - offset: pagination offset
 *   - undispatched_only: show only events not yet sent (1 or 0)
 *
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "events": [...],
 *     "total": 123
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

    // Parse parameters
    $type = $_GET['type'] ?? null;
    $productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT);
    $masterProductId = filter_input(INPUT_GET, 'master_product_id', FILTER_VALIDATE_INT);
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $undispatchedOnly = ($_GET['undispatched_only'] ?? '0') === '1';

    // Build query
    $where = ['1=1'];
    $params = [];

    if ($type !== null) {
        $validTypes = ['price_drop', 'price_increase', 'back_in_stock', 'out_of_stock', 'lowest_ever', 'flash_sale'];
        if (in_array($type, $validTypes, true)) {
            $where[] = 'pe.event_type = :type';
            $params['type'] = $type;
        }
    }

    if ($productId) {
        $where[] = 'pe.product_id = :product_id';
        $params['product_id'] = $productId;
    }

    if ($masterProductId) {
        $where[] = 'pe.master_product_id = :master_product_id';
        $params['master_product_id'] = $masterProductId;
    }

    if ($undispatchedOnly) {
        $where[] = 'pe.is_dispatched = 0';
    }

    $whereClause = implode(' AND ', $where);

    // Get total count
    $countSql = "SELECT COUNT(*) FROM price_events pe WHERE {$whereClause}";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    // Get events
    $sql = "
        SELECT pe.event_id, pe.event_type, pe.old_price, pe.new_price,
               pe.change_percent, pe.event_metadata, pe.is_dispatched, pe.created_at,
               tp.product_id, tp.platform, tp.product_name, tp.product_url, tp.image_url,
               mp.master_product_id, mp.canonical_name as master_name
        FROM price_events pe
        JOIN tracked_products tp ON pe.product_id = tp.product_id
        LEFT JOIN master_products mp ON pe.master_product_id = mp.master_product_id
        WHERE {$whereClause}
        ORDER BY pe.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $events = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row['event_metadata'] = $row['event_metadata'] ? json_decode($row['event_metadata'], true) : null;
        $row['old_price'] = $row['old_price'] ? (float) $row['old_price'] : null;
        $row['new_price'] = (float) $row['new_price'];
        $row['change_percent'] = $row['change_percent'] ? (float) $row['change_percent'] : null;
        $row['is_dispatched'] = (bool) $row['is_dispatched'];
        $events[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'events' => $events,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
    ], JSON_UNESCAPED_UNICODE);
}
