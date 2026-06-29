<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent($file, $line)) {
            throw new \RuntimeException(
                sprintf('Cannot start the session after output at %s:%d.', $file, $line)
            );
        }

        $name = (string) ($this->config['name'] ?? 'soportegr_session');
        $sameSite = (string) ($this->config['same_site'] ?? 'Lax');
        $secure = (bool) ($this->config['secure'] ?? false);
        $gcMaxLifetime = (int) ($this->config['gc_max_lifetime'] ?? 7200);

        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1) {
            throw new \RuntimeException('Invalid session cookie name.');
        }

        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new \RuntimeException('Invalid SameSite session policy.');
        }

        if ($sameSite === 'None' && !$secure) {
            throw new \RuntimeException('SameSite=None requires a secure session cookie.');
        }

        session_name($name);
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) $gcMaxLifetime);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);

        if (!session_start()) {
            throw new \RuntimeException('Unable to start the session.');
        }
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->assertStarted();

        if (!session_regenerate_id($deleteOldSession)) {
            throw new \RuntimeException('Unable to regenerate the session identifier.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertStarted();

        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->assertStarted();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->assertStarted();
        unset($_SESSION[$key]);
    }

    private function assertStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('The session has not been started.');
        }
    }
}
