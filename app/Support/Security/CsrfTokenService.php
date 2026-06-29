<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Core\Session;

final class CsrfTokenService
{
    private const TOKEN_KEY = '_security_csrf_token';
    private const ISSUED_AT_KEY = '_security_csrf_issued_at';

    public function __construct(
        private readonly Session $session,
        private readonly int $ttlSeconds = 7200
    ) {
        if ($this->ttlSeconds < 1) {
            throw new \InvalidArgumentException('CSRF token lifetime must be positive.');
        }
    }

    public function token(): string
    {
        $token = $this->session->get(self::TOKEN_KEY);
        $issuedAt = $this->session->get(self::ISSUED_AT_KEY);

        if (!is_string($token) || !is_int($issuedAt) || $this->isExpired($issuedAt)) {
            return $this->regenerate();
        }

        return $token;
    }

    public function validate(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $storedToken = $this->session->get(self::TOKEN_KEY);
        $issuedAt = $this->session->get(self::ISSUED_AT_KEY);

        if (!is_string($storedToken) || !is_int($issuedAt) || $this->isExpired($issuedAt)) {
            return false;
        }

        return hash_equals($storedToken, $token);
    }

    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->put(self::TOKEN_KEY, $token);
        $this->session->put(self::ISSUED_AT_KEY, time());

        return $token;
    }

    private function isExpired(int $issuedAt): bool
    {
        return $issuedAt + $this->ttlSeconds < time();
    }
}
