<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Infrastructure\Repositories\PushSubscriptionRepository;
use JsonException;

final class PushSubscriptionController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PushSubscriptionRepository $subscriptions
    ) {
    }

    public function subscribe(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::json(['ok' => false, 'error' => 'No autenticado.'], 401);
        }
        try {
            $raw = $request->rawBody(8192);
            $data = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
        } catch (\LengthException) {
            return Response::json(['ok' => false, 'error' => 'Solicitud demasiado grande.'], 413);
        } catch (JsonException) {
            return Response::json(['ok' => false, 'error' => 'JSON inválido.'], 400);
        }
        if (!is_array($data) || array_diff(array_keys($data), ['endpoint', 'keys', 'contentEncoding']) !== []) {
            return Response::json(['ok' => false, 'error' => 'Suscripción inválida.'], 422);
        }
        $keys = $data['keys'] ?? null;
        $endpoint = $data['endpoint'] ?? null;
        $encoding = $data['contentEncoding'] ?? 'aes128gcm';
        if (
            !is_string($endpoint) || strlen($endpoint) < 20 || strlen($endpoint) > 2048
            || !is_array($keys)
            || array_diff(array_keys($keys), ['p256dh', 'auth']) !== []
            || !is_string($keys['p256dh'] ?? null) || strlen($keys['p256dh']) > 255
            || !is_string($keys['auth'] ?? null) || strlen($keys['auth']) > 255
            || !is_string($encoding) || !in_array($encoding, ['aes128gcm', 'aesgcm'], true)
        ) {
            return Response::json(['ok' => false, 'error' => 'Suscripción inválida.'], 422);
        }
        $id = $this->subscriptions->upsertForUser((int) $user['user_id'], [
            'endpoint' => $endpoint,
            'p256dh' => $keys['p256dh'],
            'auth' => $keys['auth'],
            'content_encoding' => $encoding,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
        ]);
        if ($id < 1) {
            return Response::json(['ok' => false, 'error' => 'Suscripción no disponible.'], 409);
        }
        return Response::json(['ok' => true, 'subscription_id' => $id], 201);
    }

    public function unsubscribe(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::json(['ok' => false, 'error' => 'No autenticado.'], 401);
        }
        try {
            $data = json_decode($request->rawBody(4096), true, 4, JSON_THROW_ON_ERROR);
        } catch (\LengthException) {
            return Response::json(['ok' => false, 'error' => 'Solicitud demasiado grande.'], 413);
        } catch (JsonException) {
            return Response::json(['ok' => false, 'error' => 'JSON inválido.'], 400);
        }
        $endpoint = is_array($data) ? ($data['endpoint'] ?? null) : null;
        if (!is_string($endpoint) || strlen($endpoint) < 20 || strlen($endpoint) > 2048) {
            return Response::json(['ok' => false, 'error' => 'Suscripción inválida.'], 422);
        }
        $this->subscriptions->revokeForUser((int) $user['user_id'], $endpoint);
        return Response::json(['ok' => true]);
    }
}
