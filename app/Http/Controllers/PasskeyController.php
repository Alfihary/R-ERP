<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\PasskeyService;
use App\Domain\Security\PermissionService;
use App\Domain\Audit\AuditService;
use App\Support\Security\CsrfTokenService;

final class PasskeyController
{
    public function __construct(private readonly AuthService $auth, private readonly PasskeyService $passkeys,
        private readonly PermissionService $permissions, private readonly CsrfTokenService $csrf, private readonly AuditService $audit) {}

    public function status(Request $request): Response
    {
        return Response::json([
            'available' => $this->passkeys->ready(),
            'has_credentials' => $this->passkeys->hasCredentials(),
            'origin' => $this->passkeys->origin(),
        ]);
    }
    public function manageStatus(Request $request): Response
    {
        $ready = $this->passkeys->ready();
        return Response::json([
            'available' => $ready,
            'origin' => $this->passkeys->origin(),
            'credentials' => $ready ? $this->passkeys->credentials($this->auth->user()['user_id']) : [],
        ]);
    }
    public function operation(Request $request, string $operation): Response
    {
        if (!$this->passkeys->ready()) return Response::json(['error' => 'El acceso con passkeys está pendiente de activar en el servidor. Usa tu contraseña.'], 503);
        if ($request->header('Origin') !== $this->passkeys->origin()) return Response::json(['error' => 'Abre ' . $this->passkeys->origin() . ' para usar tu dispositivo.'], 403);
        if (str_starts_with($operation, 'login') && $this->auth->check()) return Response::json(['redirect' => $this->auth->isLocked() ? '/desbloquear' : '/app']);
        try { $this->passkeys->rateLimit(); }
        catch (\RuntimeException) { return Response::json(['error' => 'Espera un minuto antes de volver a intentarlo.'], 429); }
        try {
            $user = $this->auth->user();
            if ($operation === 'register-options' || $operation === 'revoke') {
                $password = $request->input('password');
                $version = is_string($password) && strlen($password) <= 1024 ? $this->auth->confirmedPasswordVersion($password) : null;
                if ($version === null) throw new \RuntimeException('Password not confirmed.');
                if ($operation === 'register-options') return Response::json((array) $this->passkeys->registerOptions($user, $version));
                $credentialId = filter_var($request->input('credential_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!is_int($credentialId) || !$this->passkeys->revoke($user['user_id'], $credentialId)) throw new \RuntimeException('Credential unavailable.');
                $this->audit->record('auth.passkey.revocada', $user['user_id'], ['entidad' => 'usuario_passkeys', 'entidad_id' => (string) $credentialId]);
                return Response::json(['ok' => true]);
            }
            if ($operation === 'register-verify') {
                $name = $request->input('name');
                if (!is_string($name)) throw new \RuntimeException('Credential name required.');
                $this->passkeys->register($user, $name, $request->body());
                $this->audit->record('auth.passkey.registrada', $user['user_id']);
                return Response::json(['ok' => true]);
            }
            if ($operation === 'login-options') return Response::json((array) $this->passkeys->loginOptions());
            if ($operation !== 'login-verify') throw new \RuntimeException('Invalid operation.');
            $verified = $this->passkeys->login($request->body());
            if (!$this->permissions->allows($verified['user_id'], 'sistema.app.ver') || !$this->auth->establishPasskeySession($verified)) throw new \RuntimeException('Access denied.');
            $this->csrf->regenerate();
            $this->audit->record('auth.passkey.ingreso', $verified['user_id']);
            return Response::json(['ok' => true, 'redirect' => '/app']);
        } catch (\Throwable) {
            $this->audit->record('auth.passkey.fallido', $this->auth->user()['user_id'] ?? null, ['resultado' => 'fallido']);
            return Response::json(['error' => 'No se pudo verificar el acceso. Comprueba tu contraseña al registrar el dispositivo, o usa una llave previamente registrada para ingresar.'], 422);
        }
    }
}
