<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Infrastructure\Repositories\NotificationRepository;

final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly ?NotificationPushService $push = null
    )
    {
    }

    /** @param array<string,mixed> $event @return array{status:string,id:?int} */
    public function create(array $event): array
    {
        $fingerprintInput = $event;
        unset($fingerprintInput['idempotency_fingerprint']);
        $event['idempotency_fingerprint'] = hash(
            'sha256',
            json_encode($fingerprintInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );
        $result = $this->notifications->createIdempotent($event);
        if ($result['status'] === 'created' && $this->push !== null && $result['id'] !== null) {
            try {
                $this->push->dispatch((int) $result['id'], (int) $event['usuario_id'], $event);
            } catch (\Throwable) {
                // Push failure must never turn a persisted ERP notification into an error.
            }
        }
        return $result;
    }

    /** @return array{items:list<array<string,mixed>>,unread_count:int,filter:string} */
    public function dashboardData(int $userId, string $filter): array
    {
        if (!in_array($filter, ['all', 'unread', 'today'], true)) {
            $filter = 'all';
        }
        return [
            'items' => $this->notifications->listForUser($userId, $filter),
            'unread_count' => $this->notifications->unreadCountForUser($userId),
            'filter' => $filter,
        ];
    }

    public function markRead(int $notificationId, int $userId): ?string
    {
        return $this->notifications->markReadForUser($notificationId, $userId);
    }

    public function markAllRead(int $userId): int
    {
        return $this->notifications->markAllReadForUser($userId);
    }

    /** @return array{unread_count:int,nuevas_hoy:int,latest_id:int,notificaciones:list<array<string,mixed>>} */
    public function realtimeState(int $userId): array
    {
        return $this->notifications->realtimeStateForUser($userId);
    }
}
