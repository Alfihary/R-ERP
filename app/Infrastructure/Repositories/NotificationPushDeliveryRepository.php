<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class NotificationPushDeliveryRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    public function ensurePending(int $notificationId, int $subscriptionId): bool
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
INSERT INTO notification_push_deliveries (notificacion_id, push_subscription_id, status)
VALUES (:notification_id, :subscription_id, 'pending')
ON DUPLICATE KEY UPDATE updated_at = updated_at
SQL);
        $statement->execute([
            'notification_id' => $notificationId,
            'subscription_id' => $subscriptionId,
        ]);
        $lookup = $this->connection->pdo()->prepare(<<<'SQL'
SELECT status
FROM notification_push_deliveries
WHERE notificacion_id = :notification_id AND push_subscription_id = :subscription_id
LIMIT 1
SQL);
        $lookup->execute([
            'notification_id' => $notificationId,
            'subscription_id' => $subscriptionId,
        ]);
        return $lookup->fetchColumn() !== 'sent';
    }

    public function markSent(int $notificationId, int $subscriptionId): void
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
UPDATE notification_push_deliveries
SET status = 'sent', attempt_count = attempt_count + 1,
    last_http_status = 201, last_error_code = NULL,
    last_attempt_at = CURRENT_TIMESTAMP, sent_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE notificacion_id = :notification_id AND push_subscription_id = :subscription_id
  AND status <> 'sent'
SQL);
        $statement->execute([
            'notification_id' => $notificationId,
            'subscription_id' => $subscriptionId,
        ]);
    }

    public function markFailure(
        int $notificationId,
        int $subscriptionId,
        string $status,
        ?int $httpStatus,
        ?string $errorCode
    ): void {
        $status = in_array($status, ['temporary_failure', 'permanent_failure'], true)
            ? $status
            : 'temporary_failure';
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
UPDATE notification_push_deliveries
SET status = :status, attempt_count = attempt_count + 1,
    last_http_status = :http_status, last_error_code = :error_code,
    last_attempt_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
WHERE notificacion_id = :notification_id AND push_subscription_id = :subscription_id
SQL);
        $statement->execute([
            'status' => $status,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'notification_id' => $notificationId,
            'subscription_id' => $subscriptionId,
        ]);
    }
}
