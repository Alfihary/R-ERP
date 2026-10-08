<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Audit\AuditService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\DeviceUnlockService;
use App\Domain\Auth\SessionLockService;
use App\Support\Security\CsrfTokenService;

final class DeviceUnlockController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly SessionLockService $lock,
        private readonly DeviceUnlockService $device,
        private readonly CsrfTokenService $csrf,
        private readonly AuditService $audit
    ) {}

    public function page(Request $request): Response
    {
        if (!$this->lock->locked()) { return Response::redirect('/app'); }
        return Response::html(View::render('auth/unlock', [
            'csrf' => $this->csrf, 'expired' => $this->lock->expired(),
        ]));
    }

    public function status(Request $request): Response
    {
        return Response::json([
            'enabled' => $this->lock->enabled(), 'locked' => $this->lock->locked(),
            'expired' => $this->lock->expired(), 'available' => $this->device->available(),
            'origin' => $this->device->origin(), 'csrf' => $this->csrf->token(),
            'idleSeconds' => SessionLockService::IDLE_SECONDS,
        ]);
    }

    public function activity(Request $request): Response
    {
        $this->lock->touch();
        return $this->status($request);
    }

    public function lock(Request $request): Response
    {
        if (!$this->lock->enabled()) { return Response::json(['error' => 'Activa primero la protección del dispositivo.'], 409); }
        $this->lock->lock();
        $this->audit->record('sesion.dispositivo.bloqueada', $this->auth->user()['user_id']);
        return Response::json(['ok' => true]);
    }

    public function options(Request $request, string $operation): Response
    {
        return $this->execute($request, $operation, false);
    }

    public function complete(Request $request, string $operation): Response
    {
        return $this->execute($request, $operation, true);
    }

    private function execute(Request $request, string $operation, bool $complete): Response
    {
        $user = $this->auth->user();
        if (!$this->device->available()) { return Response::json(['error' => 'El desbloqueo no está configurado en este servidor.'], 503); }
        if ($request->header('Origin') !== $this->device->origin()) {
            return Response::json(['error' => 'Abre la aplicación desde ' . $this->device->origin() . ' para activar el dispositivo.'], 403);
        }
        if ($operation === 'register' && $this->lock->locked()) {
            return Response::json(['error' => 'Desbloquea la sesión antes de continuar.'], 423);
        }
        try {
            $this->device->rateLimit();
        } catch (\RuntimeException) {
            return Response::json(['error' => 'Demasiados intentos. Espera un minuto.'], 429);
        }
        try {
            if (!$complete && $operation === 'register') {
                $password = $request->input('password');
                if (!is_string($password) || strlen($password) > 1024 || !$this->auth->verifyCurrentPassword($password)) {
                    $this->audit->record('sesion.dispositivo.registro_denegado', $user['user_id']);
                    return Response::json(['error' => 'Verifica tu contraseña actual.'], 422);
                }
            }
            if (!$complete) { return Response::json((array) $this->device->options($user, $operation)); }
            $this->device->complete($user, $operation, $request->body());
            $this->audit->record($operation === 'register' ? 'sesion.dispositivo.activado' : 'sesion.dispositivo.desbloqueada', $user['user_id']);
            return Response::json(['ok' => true]);
        } catch (\Throwable) {
            $this->audit->record('sesion.dispositivo.verificacion_fallida', $user['user_id']);
            return Response::json(['error' => 'No se pudo verificar el dispositivo. Inténtalo otra vez o cierra sesión para ingresar con contraseña.'], 422);
        }
    }
}
