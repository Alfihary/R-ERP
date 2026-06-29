<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\RoleRepository;
use App\Infrastructure\Repositories\UserRepository;

final class InitialAdminService
{
    public function __construct(
        private readonly ConnectionProvider $connection,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles
    ) {
    }

    /**
     * @return array{result: string, user_id: int}
     */
    public function create(string $username, string $email, string $password): array
    {
        [$username, $email] = $this->validateInput($username, $email, $password);
        $userByUsername = $this->users->findByUsername($username);
        $userByEmail = $this->users->findByEmail($email);
        $adminRole = $this->roles->findActiveSystemRole('ADMIN');

        if ($adminRole === null) {
            throw new \RuntimeException('The structural ADMIN role is missing or inactive.');
        }

        if ($userByUsername !== null || $userByEmail !== null) {
            if ($userByUsername === null
                || $userByEmail === null
                || (int) $userByUsername['id'] !== (int) $userByEmail['id']
                || !$this->isActiveUser($userByUsername)
                || !$this->roles->userHasRole(
                    (int) $userByUsername['id'],
                    (int) $adminRole['id']
                )
            ) {
                throw new \RuntimeException(
                    'The configured initial administrator conflicts with existing records.'
                );
            }

            return [
                'result' => 'already_exists',
                'user_id' => (int) $userByUsername['id'],
            ];
        }

        if ($this->users->count() !== 0) {
            throw new \RuntimeException(
                'Initial administrator creation is disabled after other users exist.'
            );
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $userId = $this->users->create(
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT)
            );
            $this->roles->assignUser($userId, (int) $adminRole['id']);
            $pdo->commit();

            return ['result' => 'created', 'user_id' => $userId];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function rotatePassword(string $username, string $email, string $password): int
    {
        [$username, $email] = $this->validateInput($username, $email, $password);
        $userByUsername = $this->users->findByUsername($username);
        $userByEmail = $this->users->findByEmail($email);
        $adminRole = $this->roles->findActiveSystemRole('ADMIN');

        if ($userByUsername === null
            || $userByEmail === null
            || $adminRole === null
            || (int) $userByUsername['id'] !== (int) $userByEmail['id']
            || !$this->isActiveUser($userByUsername)
            || !$this->roles->userHasRole(
                (int) $userByUsername['id'],
                (int) $adminRole['id']
            )
        ) {
            throw new \RuntimeException('The configured initial administrator was not found.');
        }

        $userId = (int) $userByUsername['id'];
        $this->users->updatePasswordHash(
            $userId,
            password_hash($password, PASSWORD_DEFAULT)
        );

        return $userId;
    }

    /**
     * @return array{string, string}
     */
    private function validateInput(string $username, string $email, string $password): array
    {
        $username = strtolower(trim($username));
        $email = strtolower(trim($email));

        if (preg_match('/^[a-z0-9._-]{3,50}$/', $username) !== 1) {
            throw new \RuntimeException('Initial administrator username is invalid.');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new \RuntimeException('Initial administrator email is invalid.');
        }

        if (strlen($password) < 12) {
            throw new \RuntimeException(
                'Initial administrator temporary password must contain at least 12 characters.'
            );
        }

        return [$username, $email];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isActiveUser(array $user): bool
    {
        return (int) ($user['activo'] ?? 0) === 1
            && ($user['eliminado_en'] ?? null) === null;
    }
}
