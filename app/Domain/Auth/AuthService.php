<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Core\Session;
use App\Infrastructure\Repositories\UserRepository;

final class AuthService
{
    private const SESSION_KEY = 'auth_user';
    private const DUMMY_PASSWORD_HASH = '$2y$10$i2V1QJcC1dAguHOg1mdK1e0wMd6VGit2Z15ooGE7a5ZmzP02OS7Ue';

    public function __construct(
        private readonly UserRepository $users,
        private readonly Session $session
    ) {
    }

    public function attempt(string $login, string $password): bool
    {
        $normalizedLogin = strtolower(trim($login));
        $user = $normalizedLogin === ''
            ? null
            : $this->users->findForAuthentication($normalizedLogin);
        $eligible = $user !== null
            && (int) $user['activo'] === 1
            && $user['deleted_at'] === null;
        $hash = $eligible
            ? (string) $user['password_hash']
            : self::DUMMY_PASSWORD_HASH;

        if ($password === '' || !password_verify($password, $hash) || !$eligible) {
            return false;
        }

        $this->session->regenerate();
        (new SessionLockService($this->session))->reset();
        $this->session->put(self::SESSION_KEY, [
            'user_id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'email' => (string) $user['email'],
        ]);

        return true;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array{user_id: int, username: string, email: string}|null
     */
    public function user(): ?array
    {
        $user = $this->session->get(self::SESSION_KEY);

        if (!is_array($user)
            || !isset($user['user_id'], $user['username'], $user['email'])
            || !is_int($user['user_id'])
            || !is_string($user['username'])
            || !is_string($user['email'])
        ) {
            return null;
        }

        return [
            'user_id' => $user['user_id'],
            'username' => $user['username'],
            'email' => $user['email'],
        ];
    }

    public function isLocked(): bool
    {
        return (new SessionLockService($this->session))->locked();
    }

    public function touchSession(): void
    {
        (new SessionLockService($this->session))->touch();
    }

    public function verifyCurrentPassword(string $password): bool
    {
        $user = $this->user();
        if ($user === null) { return false; }
        $row = $this->users->findForAuthentication($user['email']);
        return $row !== null && (int) $row['id'] === $user['user_id']
            && (int) $row['activo'] === 1 && $row['deleted_at'] === null
            && password_verify($password, (string) $row['password_hash']);
    }

    public function confirmedPasswordVersion(string $password): ?string
    {
        $user = $this->user();
        if ($user === null || $this->isLocked()) return null;
        $row = $this->users->findForAuthentication($user['email']);
        if ($row === null || (int) $row['id'] !== $user['user_id'] || (int) $row['activo'] !== 1
            || $row['deleted_at'] !== null || !password_verify($password, $row['password_hash'])) return null;
        return hash('sha256', $row['password_hash']);
    }

    /** Only call with the result of PasskeyService::login, never HTTP input. */
    public function establishPasskeySession(array $verified): bool
    {
        $row = $this->users->findForAuthentication($verified['email']);
        if ($row === null || (int) $row['id'] !== $verified['user_id'] || (int) $row['activo'] !== 1
            || $row['deleted_at'] !== null || !hash_equals(hash('sha256', $row['password_hash']), $verified['password_version'])) return false;
        $this->session->regenerate();
        (new SessionLockService($this->session))->reset();
        foreach (['active_company_id', 'active_warehouse_id', 'passkey_challenge'] as $key) $this->session->remove($key);
        $this->session->put(self::SESSION_KEY, ['user_id' => (int) $row['id'], 'username' => $row['username'], 'email' => $row['email']]);
        return true;
    }

    public function logout(): void
    {
        $this->session->invalidate();
    }
}
