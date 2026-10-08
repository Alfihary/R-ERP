<?php
declare(strict_types=1);

namespace App\Domain\Auth;

use App\Core\Session;

/** Credentials are deliberately scoped to one authenticated PHP session. */
final class SessionLockService
{
    public const IDLE_SECONDS = 300;
    public const MAX_AGE_SECONDS = 28800;
    private const KEY = 'device_unlock';

    public function __construct(private readonly Session $session) {}

    public function state(): array
    {
        $state = $this->session->get(self::KEY, []);
        return is_array($state) ? $state : [];
    }

    public function enabled(): bool { return isset($this->state()['credential_id']); }

    public function expired(): bool
    {
        return $this->enabled() && time() - (int) ($this->state()['created_at'] ?? 0) >= self::MAX_AGE_SECONDS;
    }

    public function locked(): bool
    {
        $state = $this->state();
        return $this->enabled() && (
            ($state['locked'] ?? true) || $this->expired()
            || time() - (int) ($state['last_activity'] ?? 0) >= self::IDLE_SECONDS
        );
    }

    public function touch(): void
    {
        if ($this->enabled() && !$this->locked()) {
            $this->save(['last_activity' => time()]);
        }
    }

    public function lock(): void
    {
        if ($this->enabled()) { $this->save(['locked' => true]); }
        $this->session->remove('device_challenge');
    }

    public function enroll(int $userId, string $credentialId, string $publicKey, int $counter): void
    {
        if ($this->enabled()) { throw new \RuntimeException('La protección ya está activa en esta sesión.'); }
        $this->session->put(self::KEY, [
            'user_id' => $userId, 'credential_id' => $credentialId,
            'public_key' => $publicKey, 'counter' => $counter,
            'created_at' => time(), 'last_activity' => time(), 'locked' => false,
        ]);
    }

    public function unlock(int $counter): void
    {
        if (!$this->enabled() || $this->expired()) { throw new \RuntimeException('Inicia sesión de nuevo con tu contraseña.'); }
        $this->session->regenerate();
        $this->save(['counter' => $counter, 'locked' => false, 'last_activity' => time()]);
    }

    public function reset(): void
    {
        foreach ([self::KEY, 'device_challenge', 'device_rate'] as $key) { $this->session->remove($key); }
    }

    private function save(array $changes): void
    {
        $this->session->put(self::KEY, array_replace($this->state(), $changes));
    }
}
