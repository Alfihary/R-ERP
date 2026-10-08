<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Notifications\NotificationService;

final class NotificationCenterController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly NotificationService $notifications
    ) {
    }

    /** @return array{items:list<array<string,mixed>>,unread_count:int,filter:string} */
    public function dashboardData(int $userId, mixed $requestedFilter): array
    {
        $filter = is_string($requestedFilter) ? $requestedFilter : 'all';
        return $this->notifications->dashboardData($userId, $filter);
    }

    /** @param array<string,string> $params */
    public function markRead(Request $request, array $params): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $id = $params['id'] ?? '';
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1) {
            return Response::redirect('/app?result=notificacion_no_encontrada');
        }
        $target = $this->notifications->markRead((int) $id, $user['user_id']);
        return Response::redirect($target ?? '/app');
    }

    public function markAllRead(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $this->notifications->markAllRead($user['user_id']);
        return Response::redirect('/app#notificaciones');
    }

    public function state(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::json(['ok' => false, 'error' => 'No autenticado.'], 401);
        }
        return Response::json([
            'ok' => true,
            ...$this->notifications->realtimeState((int) $user['user_id']),
        ]);
    }

}
