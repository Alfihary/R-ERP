<?php
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Core\Session;
use lbuchs\WebAuthn\WebAuthn;

final class DeviceUnlockService
{
    public function __construct(
        private readonly Session $session,
        private readonly SessionLockService $lock,
        private readonly string $origin
    ) {}

    public function origin(): string { return $this->origin; }

    public function available(): bool
    {
        $parts = parse_url($this->origin);
        return class_exists(WebAuthn::class) && is_array($parts)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && empty($parts['path']) && !empty($parts['host'])
            && (($parts['scheme'] ?? '') === 'https'
                || (($parts['scheme'] ?? '') === 'http'
                    && in_array($parts['host'], ['localhost', '127.0.0.1', '::1'], true)));
    }

    public function rateLimit(): void
    {
        $rate = $this->session->get('device_rate', ['start' => time(), 'count' => 0]);
        if (!is_array($rate) || time() - (int) ($rate['start'] ?? 0) >= 60) {
            $rate = ['start' => time(), 'count' => 0];
        }
        if ((int) $rate['count'] >= 10) { throw new \RuntimeException('Espera un minuto antes de intentarlo de nuevo.'); }
        $rate['count']++;
        $this->session->put('device_rate', $rate);
    }

    public function options(array $user, string $operation): object
    {
        $web = $this->web();
        if ($operation === 'register') {
            if ($this->lock->enabled()) { throw new \RuntimeException('La protección ya está activa.'); }
            $args = $web->getCreateArgs(random_bytes(32), $user['username'], $user['username'], 60, 'discouraged', 'required', false);
        } else {
            $state = $this->lock->state();
            if (!$this->lock->enabled() || $this->lock->expired() || ($state['user_id'] ?? 0) !== $user['user_id']) {
                throw new \RuntimeException('Inicia sesión de nuevo con tu contraseña.');
            }
            $args = $web->getGetArgs([$state['credential_id']], 60, false, false, false, false, true, 'required');
        }
        $this->session->put('device_challenge', [
            'value' => $web->getChallenge()->getBinaryString(), 'operation' => $operation,
            'user_id' => $user['user_id'], 'expires' => time() + 90,
        ]);
        return $args;
    }

    public function complete(array $user, string $operation, array $input): void
    {
        // Consume before validation, including failed attempts, to prevent replay.
        $pending = $this->session->get('device_challenge');
        $this->session->remove('device_challenge');
        if (!is_array($pending) || ($pending['operation'] ?? '') !== $operation
            || ($pending['user_id'] ?? 0) !== $user['user_id'] || ($pending['expires'] ?? 0) < time()) {
            throw new \RuntimeException('La solicitud venció. Vuelve a intentarlo.');
        }
        $client = $this->decode($input['clientDataJSON'] ?? null);
        $clientData = json_decode($client, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($clientData) || ($clientData['origin'] ?? '') !== $this->origin
            || ($clientData['crossOrigin'] ?? false) !== false || isset($clientData['topOrigin'])) {
            throw new \RuntimeException('El origen de la solicitud no es válido.');
        }
        $web = $this->web();
        if ($operation === 'register') {
            $data = $web->processCreate($client, $this->decode($input['attestationObject'] ?? null), $pending['value'], true, true);
            if (!hash_equals($data->credentialId, $this->decode($input['rawId'] ?? null))) {
                throw new \RuntimeException('La credencial no coincide.');
            }
            $this->lock->enroll($user['user_id'], $data->credentialId, $data->credentialPublicKey, $data->signatureCounter ?? 0);
            $this->session->regenerate();
            return;
        }
        $state = $this->lock->state();
        if (!$this->lock->enabled() || $this->lock->expired() || ($state['user_id'] ?? 0) !== $user['user_id']
            || !hash_equals($state['credential_id'], $this->decode($input['rawId'] ?? null))) {
            throw new \RuntimeException('La credencial no corresponde a esta sesión.');
        }
        $web->processGet($client, $this->decode($input['authenticatorData'] ?? null),
            $this->decode($input['signature'] ?? null), $state['public_key'], $pending['value'],
            (int) $state['counter'], true, true);
        $this->lock->unlock($web->getSignatureCounter() ?? 0);
    }

    private function web(): WebAuthn
    {
        if (!$this->available()) { throw new \RuntimeException('El desbloqueo del dispositivo no está configurado.'); }
        return new WebAuthn('Grupo Refrigerantes', (string) parse_url($this->origin, PHP_URL_HOST), ['none'], true);
    }

    private function decode(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 32768 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new \RuntimeException('Respuesta del dispositivo inválida.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') { throw new \RuntimeException('Respuesta del dispositivo inválida.'); }
        return $decoded;
    }
}
