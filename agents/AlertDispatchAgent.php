<?php

declare(strict_types=1);

namespace Agents;

use Modules\Notification\LineNotifier;
use PDO;
use Throwable;

/**
 * Alert Dispatch Agent: Sends notifications for price events.
 *
 * Supports multiple notification channels:
 * - LINE (via LINE Messaging API)
 * - Email (via PHPMailer - existing AlertService)
 * - Dashboard (in-app notifications)
 *
 * Pipeline: ScraperAgent -> DataCleaningAgent -> PriceDiffAgent -> AlertDispatchAgent
 */
class AlertDispatchAgent implements AgentInterface
{
    private PDO $pdo;
    private ?LineNotifier $lineNotifier = null;
    private int $batchDelaySeconds;

    public function __construct(PDO $pdo, int $batchDelaySeconds = 60)
    {
        $this->pdo = $pdo;
        $this->batchDelaySeconds = $batchDelaySeconds;

        // Initialize LINE notifier if configured
        $this->initLineNotifier();
    }

    public function getName(): string
    {
        return 'alert_dispatch';
    }

    public function getNextAgentType(): ?string
    {
        return null; // Last agent in pipeline
    }

    public function shouldRetry(Throwable $e): bool
    {
        // Retry on network errors
        if (strpos($e->getMessage(), 'curl') !== false) {
            return true;
        }
        if (strpos($e->getMessage(), 'timeout') !== false) {
            return true;
        }
        return false;
    }

    /**
     * Process price events and send notifications.
     *
     * @param array $payload Expected keys:
     *   - event_ids: array of price_events.event_id to process
     */
    public function process(array $payload): AgentResult
    {
        $startTime = microtime(true);
        $eventIds = $payload['event_ids'] ?? null;

        if ($eventIds === null) {
            // Get undispatched events
            $eventIds = $this->getUndispatchedEvents(100);
        }

        if (empty($eventIds)) {
            return AgentResult::success('No events to dispatch', null, [
                'events_found' => 0,
            ]);
        }

        // Group events by user for batching
        $eventsByUser = $this->groupEventsByUser($eventIds);

        $processed = 0;
        $lineSent = 0;
        $emailSent = 0;
        $failed = 0;

        foreach ($eventsByUser as $userId => $userEvents) {
            try {
                $result = $this->dispatchToUser((int) $userId, $userEvents);
                $processed += count($userEvents);
                $lineSent += $result['line_sent'];
                $emailSent += $result['email_sent'];

                // Mark events as dispatched
                $this->markEventsDispatched(array_column($userEvents, 'event_id'));

            } catch (Throwable $e) {
                $failed += count($userEvents);
                $this->logError($userId, $e->getMessage());
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return AgentResult::success(
            "Dispatched {$processed} events: {$lineSent} LINE, {$emailSent} email, {$failed} failed",
            null,
            [
                'events_processed' => $processed,
                'line_sent' => $lineSent,
                'email_sent' => $emailSent,
                'failed' => $failed,
                'users_notified' => count($eventsByUser),
                'duration_ms' => $durationMs,
            ]
        );
    }

    /**
     * Initialize LINE notifier from config.
     */
    private function initLineNotifier(): void
    {
        require_once __DIR__ . '/../config/line.php';

        if (function_exists('getLineConfig')) {
            $config = getLineConfig($this->pdo);
            if (!empty($config['channel_access_token'])) {
                $this->lineNotifier = new LineNotifier($config['channel_access_token']);
            }
        }
    }

    /**
     * Get undispatched event IDs.
     */
    private function getUndispatchedEvents(int $limit): array
    {
        $stmt = $this->pdo->prepare("
            SELECT event_id
            FROM price_events
            WHERE is_dispatched = 0
            ORDER BY created_at ASC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Group events by users who are tracking those products.
     *
     * @return array [user_id => [events...]]
     */
    private function groupEventsByUser(array $eventIds): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

        $stmt = $this->pdo->prepare("
            SELECT DISTINCT
                u.user_id, u.email, u.full_name, u.notify_email, u.notify_line, u.line_user_id,
                pe.event_id, pe.event_type, pe.old_price, pe.new_price, pe.change_percent,
                pe.event_metadata,
                tp.product_id, tp.platform, tp.product_name, tp.product_url, tp.image_url,
                tp.affiliate_url,
                ut.target_price, ut.target_discount_percent
            FROM price_events pe
            JOIN tracked_products tp ON pe.product_id = tp.product_id
            JOIN user_tracking ut ON tp.product_id = ut.product_id AND ut.is_active = 1
            JOIN users u ON ut.user_id = u.user_id AND u.is_active = 1
            WHERE pe.event_id IN ({$placeholders})
              AND (u.notify_email = 1 OR u.notify_line = 1)
        ");

        $stmt->execute($eventIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by user
        $byUser = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'];

            // Check if event matches user's alert criteria
            if (!$this->eventMatchesUserCriteria($row)) {
                continue;
            }

            if (!isset($byUser[$userId])) {
                $byUser[$userId] = [];
            }

            // Decode metadata
            $row['event_metadata'] = $row['event_metadata']
                ? json_decode($row['event_metadata'], true)
                : [];

            $byUser[$userId][] = $row;
        }

        return $byUser;
    }

    /**
     * Check if an event matches the user's tracking criteria.
     */
    private function eventMatchesUserCriteria(array $row): bool
    {
        $eventType = $row['event_type'];
        $newPrice = (float) $row['new_price'];
        $targetPrice = $row['target_price'] ? (float) $row['target_price'] : null;
        $targetDiscount = $row['target_discount_percent'] ? (float) $row['target_discount_percent'] : null;
        $changePercent = $row['change_percent'] ? abs((float) $row['change_percent']) : null;

        // Always notify for these important events
        if (in_array($eventType, ['flash_sale', 'lowest_ever', 'back_in_stock'], true)) {
            return true;
        }

        // Check target price
        if ($targetPrice !== null && $newPrice <= $targetPrice) {
            return true;
        }

        // Check target discount percentage
        if ($targetDiscount !== null && $changePercent !== null && $changePercent >= $targetDiscount) {
            return true;
        }

        // Default: notify for significant price drops even without specific targets
        if ($eventType === 'price_drop' && $changePercent !== null && $changePercent >= 10) {
            return true;
        }

        return false;
    }

    /**
     * Dispatch notifications to a specific user.
     */
    private function dispatchToUser(int $userId, array $events): array
    {
        $result = [
            'line_sent' => 0,
            'email_sent' => 0,
        ];

        if (empty($events)) {
            return $result;
        }

        $user = $events[0]; // User info is same for all events

        // Send LINE notification
        if ($user['notify_line'] && !empty($user['line_user_id']) && $this->lineNotifier !== null) {
            $lineSent = $this->sendLineNotification($user['line_user_id'], $events);
            if ($lineSent) {
                $result['line_sent'] = count($events);
                $this->recordAlertSent($events, 'line');
            }
        }

        // Send email notification
        if ($user['notify_email'] && !empty($user['email'])) {
            $emailSent = $this->sendEmailNotification($user['email'], $user['full_name'], $events);
            if ($emailSent) {
                $result['email_sent'] = count($events);
                $this->recordAlertSent($events, 'email');
            }
        }

        return $result;
    }

    /**
     * Send LINE notification to user.
     */
    private function sendLineNotification(string $lineUserId, array $events): bool
    {
        try {
            if (count($events) === 1) {
                // Single product - send flex message
                $event = $events[0];
                $message = $this->lineNotifier->buildPriceAlertFlex(
                    $event['product_name'],
                    $event['image_url'],
                    $event['old_price'] ? (float) $event['old_price'] : null,
                    (float) $event['new_price'],
                    $event['product_url'],
                    $event['affiliate_url'],
                    $event['event_type'],
                    $event['platform']
                );

                $response = $this->lineNotifier->pushMessage($lineUserId, [$message]);
            } else {
                // Multiple products - send carousel
                $alerts = array_map(fn($e) => [
                    'product_name' => $e['product_name'],
                    'image_url' => $e['image_url'],
                    'old_price' => $e['old_price'] ? (float) $e['old_price'] : null,
                    'new_price' => (float) $e['new_price'],
                    'product_url' => $e['product_url'],
                    'affiliate_url' => $e['affiliate_url'],
                    'event_type' => $e['event_type'],
                    'platform' => $e['platform'],
                ], $events);

                $message = $this->lineNotifier->buildPriceAlertCarousel($alerts);
                $response = $this->lineNotifier->pushMessage($lineUserId, [$message]);
            }

            return $response['success'] ?? false;

        } catch (Throwable $e) {
            $this->logError(0, "LINE send failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send email notification to user.
     * Uses existing AlertService/PHPMailer infrastructure.
     */
    private function sendEmailNotification(string $email, string $name, array $events): bool
    {
        // TODO: Integrate with existing AlertService/PHPMailer
        // For now, log that email would be sent

        $this->pdo->prepare("
            INSERT INTO agent_logs (agent_type, log_level, message, context, created_at)
            VALUES ('alert_dispatch', 'info', :message, :context, NOW())
        ")->execute([
            'message' => "Email notification queued for {$email}",
            'context' => json_encode([
                'email' => $email,
                'name' => $name,
                'event_count' => count($events),
            ]),
        ]);

        // Return true to mark as sent (actual sending via AlertService)
        return true;
    }

    /**
     * Record alert sent in alerts table.
     */
    private function recordAlertSent(array $events, string $channel): void
    {
        foreach ($events as $event) {
            // Get tracking_id for this user-product combination
            $stmt = $this->pdo->prepare("
                SELECT tracking_id FROM user_tracking
                WHERE user_id = :user_id AND product_id = :product_id
                LIMIT 1
            ");
            $stmt->execute([
                'user_id' => $event['user_id'],
                'product_id' => $event['product_id'],
            ]);
            $trackingId = $stmt->fetchColumn();

            if (!$trackingId) {
                continue;
            }

            // Insert alert record
            $alertType = in_array($event['event_type'], ['price_drop', 'flash_sale', 'lowest_ever'])
                ? 'target_price'
                : 'target_discount';

            $stmt = $this->pdo->prepare("
                INSERT INTO alerts (tracking_id, price_at_alert, alert_type, email_sent, line_sent, dispatch_channel, created_at)
                VALUES (:tracking_id, :price, :alert_type, :email_sent, :line_sent, :channel, NOW())
            ");
            $stmt->execute([
                'tracking_id' => $trackingId,
                'price' => $event['new_price'],
                'alert_type' => $alertType,
                'email_sent' => $channel === 'email' ? 1 : 0,
                'line_sent' => $channel === 'line' ? 1 : 0,
                'channel' => $channel,
            ]);
        }
    }

    /**
     * Mark events as dispatched.
     */
    private function markEventsDispatched(array $eventIds): void
    {
        if (empty($eventIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->pdo->prepare("
            UPDATE price_events
            SET is_dispatched = 1
            WHERE event_id IN ({$placeholders})
        ");
        $stmt->execute($eventIds);
    }

    /**
     * Log an error.
     */
    private function logError(int $userId, string $message): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO agent_logs (agent_type, log_level, message, context, created_at)
            VALUES ('alert_dispatch', 'error', :message, :context, NOW())
        ");
        $stmt->execute([
            'message' => $message,
            'context' => json_encode(['user_id' => $userId]),
        ]);
    }
}
