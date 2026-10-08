<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Infrastructure\Repositories\NotificationPushDeliveryRepository;
use App\Infrastructure\Repositories\PushSubscriptionRepository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class NotificationPushService
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly NotificationPushDeliveryRepository $deliveries,
        private readonly string $publicKey,
        private readonly string $privateKey,
        private readonly string $subject
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '' && $this->subject !== '';
    }

    /** @param array<string,mixed> $event */
    public function dispatch(int $notificationId, int $userId, array $event): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $this->subject,
                    'publicKey' => $this->publicKey,
                    'privateKey' => $this->privateKey,
                ],
            ], ['TTL' => 300], 10);
        } catch (\Throwable) {
            return;
        }

        $title = trim((string) ($event['titulo'] ?? 'SoporteGR ERP'));
        $title = $title !== '' ? mb_substr($title, 0, 120) : 'SoporteGR ERP';
        $actionUrl = (string) ($event['accion_url'] ?? '/app#centro-notificaciones');
        if (!$this->isSafeInternalUrl($actionUrl)) {
            $actionUrl = '/app#centro-notificaciones';
        }
        $payload = json_encode([
            'notification_id' => $notificationId,
            'title' => $title,
            'body' => 'Tienes una nueva notificación asignada.',
            'action_url' => $actionUrl,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $activeSubscriptions = $this->subscriptions->activeForUser($userId);
        } catch (\Throwable) {
            return;
        }

        foreach ($activeSubscriptions as $subscriptionData) {
            $subscriptionId = (int) ($subscriptionData['id'] ?? 0);
            if ($subscriptionId < 1) {
                continue;
            }
            if (!$this->deliveries->ensurePending($notificationId, $subscriptionId)) {
                continue;
            }
            try {
                $subscription = Subscription::create([
                    'endpoint' => (string) $subscriptionData['endpoint'],
                    'keys' => [
                        'p256dh' => (string) $subscriptionData['p256dh'],
                        'auth' => (string) $subscriptionData['auth'],
                    ],
                    'contentEncoding' => (string) $subscriptionData['content_encoding'],
                ]);
                $report = $webPush->sendOneNotification($subscription, $payload);
                $response = $report->getResponse();
                $status = $response?->getStatusCode();
                if ($report->isSuccess()) {
                    $this->deliveries->markSent($notificationId, $subscriptionId);
                } else {
                    $permanent = $report->isSubscriptionExpired();
                    $this->deliveries->markFailure(
                        $notificationId,
                        $subscriptionId,
                        $permanent ? 'permanent_failure' : 'temporary_failure',
                        $status,
                        $permanent ? 'subscription_expired' : 'delivery_failed'
                    );
                    if ($permanent) {
                        $this->subscriptions->revokeById($subscriptionId, $userId);
                    }
                }
            } catch (\Throwable) {
                $this->deliveries->markFailure(
                    $notificationId,
                    $subscriptionId,
                    'temporary_failure',
                    null,
                    'delivery_exception'
                );
            }
        }
    }

    private function isSafeInternalUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'])
            || !isset($parts['path'])
            || !is_string($parts['path'])
            || !str_starts_with($parts['path'], '/')
            || str_starts_with($parts['path'], '//')) {
            return false;
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if (str_starts_with($path, '//') || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }
}
