<?php
declare(strict_types=1);
namespace App\Domain\Auth;

use App\Core\Session;
use lbuchs\WebAuthn\WebAuthn;

final class PasskeyService
{
    public function __construct(private readonly Session $session, private readonly PasskeyStore $store, private readonly string $origin) {}

    public function origin(): string { return $this->origin; }
    public function ready(): bool
    {
        $unlock = new DeviceUnlockService($this->session, new SessionLockService($this->session), $this->origin);
        return $unlock->available() && $this->store->ready();
    }
    public function hasCredentials(): bool
    {
        return $this->ready() && $this->store->hasAny();
    }
    public function rateLimit(): void
    {
        $rate = $this->session->get('passkey_rate', ['start' => time(), 'count' => 0]);
        if (time() - $rate['start'] >= 60) $rate = ['start' => time(), 'count' => 0];
        if ($rate['count'] >= 10) throw new \RuntimeException('Too many attempts.');
        $rate['count']++;
        $this->session->put('passkey_rate', $rate);
    }

    public function registerOptions(array $user, string $passwordVersion): object
    {
        $existing = $this->store->forUser($user['user_id']);
        if (count($existing) >= 10) throw new \RuntimeException('Credential limit reached.');
        $handle = $existing[0]['user_handle'] ?? random_bytes(32);
        $web = $this->web();
        $args = $web->getCreateArgs($handle, $user['email'], $user['username'], 60, 'required', 'required', null, array_column($existing, 'credential_id'));
        $this->saveChallenge($web, 'register', ['user_id' => $user['user_id'], 'user_handle' => $handle, 'password_version' => $passwordVersion]);
        return $args;
    }

    public function register(array $user, string $name, array $input): void
    {
        $pending = $this->consume('register');
        if ($pending['user_id'] !== $user['user_id']) throw new \RuntimeException('Wrong user.');
        $client = $this->client($input);
        $data = $this->web()->processCreate($client, $this->decode($input['attestationObject'] ?? null), $pending['challenge'], true, true);
        if (!hash_equals($data->credentialId, $this->decode($input['rawId'] ?? null)) || strlen($data->credentialId) > 1024) throw new \RuntimeException('Invalid credential.');
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 80) throw new \RuntimeException('Invalid credential name.');
        $this->store->create($user['user_id'], ['name' => $name, 'credential_id' => $data->credentialId, 'user_handle' => $pending['user_handle'], 'public_key' => $data->credentialPublicKey, 'sign_count' => $data->signatureCounter ?? 0, 'rp_id' => $data->rpId, 'password_version' => $pending['password_version']]);
        $this->session->regenerate();
    }

    public function loginOptions(): object
    {
        $web = $this->web();
        $args = $web->getGetArgs([], 60, true, true, true, true, true, 'required');
        $this->saveChallenge($web, 'login');
        return $args;
    }

    /** Caller must recheck current application permissions before establishing a session. */
    public function login(array $input): array
    {
        $pending = $this->consume('login');
        $client = $this->client($input);
        $id = $this->decode($input['rawId'] ?? null);
        if (strlen($id) > 1024) throw new \RuntimeException('Invalid credential.');
        $row = $this->store->find($id);
        if ($row === null || !hash_equals($row['credential_id'], $id)
            || !hash_equals($row['user_handle'], $this->decode($input['userHandle'] ?? null))
            || $row['rp_id'] !== parse_url($this->origin, PHP_URL_HOST)) throw new \RuntimeException('Credential unavailable.');
        $web = $this->web();
        $web->processGet($client, $this->decode($input['authenticatorData'] ?? null), $this->decode($input['signature'] ?? null), $row['public_key'], $pending['challenge'], (int) $row['sign_count'], true, true);
        if (!$this->store->accept($row, $web->getSignatureCounter() ?? 0)) throw new \RuntimeException('Credential changed.');
        return ['user_id' => (int) $row['usuario_id'], 'username' => $row['username'], 'email' => $row['email'], 'password_version' => $row['password_version']];
    }

    public function credentials(int $userId): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['nombre'],
            'created_at' => (string) $row['creado_en'],
            'last_used_at' => $row['ultimo_uso_en'] === null ? null : (string) $row['ultimo_uso_en'],
            'uses' => (int) $row['usos'],
        ], $this->store->forUser($userId));
    }
    public function revoke(int $userId, int $credentialId): bool
    {
        $this->session->remove('passkey_challenge');
        return $credentialId > 0 && $this->store->revoke($userId, $credentialId);
    }

    private function web(): WebAuthn
    {
        if (!$this->ready()) throw new \RuntimeException('Passkeys unavailable.');
        return new WebAuthn('Grupo Refrigerantes', (string) parse_url($this->origin, PHP_URL_HOST), ['none'], true);
    }
    private function saveChallenge(WebAuthn $web, string $action, array $extra = []): void
    {
        $this->session->put('passkey_challenge', ['challenge' => $web->getChallenge()->getBinaryString(), 'action' => $action, 'expires' => time() + 90] + $extra);
    }
    private function consume(string $action): array
    {
        $pending = $this->session->get('passkey_challenge');
        $this->session->remove('passkey_challenge');
        if (!is_array($pending) || ($pending['action'] ?? '') !== $action || ($pending['expires'] ?? 0) < time()) throw new \RuntimeException('Expired challenge.');
        return $pending;
    }
    private function client(array $input): string
    {
        $raw = $this->decode($input['clientDataJSON'] ?? null);
        $client = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($client) || ($client['origin'] ?? '') !== $this->origin || ($client['crossOrigin'] ?? false) !== false || isset($client['topOrigin'])) throw new \RuntimeException('Wrong origin.');
        return $raw;
    }
    private function decode(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 32768 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) throw new \RuntimeException('Invalid data.');
        $raw = base64_decode(strtr($value, '-_', '+/'), true);
        if ($raw === false || $raw === '') throw new \RuntimeException('Invalid data.');
        return $raw;
    }
}
