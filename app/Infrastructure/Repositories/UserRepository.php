<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class UserRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForAuthentication(string $login): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT id, username, email, password_hash, activo, deleted_at
            FROM usuarios
            WHERE username = :username OR email = :email
            LIMIT 1
            SQL
        );
        $statement->execute([
            'username' => $login,
            'email' => $login,
        ]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        return $this->findByField('username', $username);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->findByField('email', $email);
    }

    public function count(): int
    {
        return (int) $this->connection->pdo()
            ->query('SELECT COUNT(*) FROM usuarios')
            ->fetchColumn();
    }

    public function create(string $username, string $email, string $passwordHash): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO usuarios (username, email, password_hash, activo)
            VALUES (:username, :email, :password_hash, 1)
            SQL
        );
        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE usuarios
            SET password_hash = :password_hash,
                actualizado_en = CURRENT_TIMESTAMP,
                actualizado_por = :actualizado_por
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'id' => $userId,
            'password_hash' => $passwordHash,
            'actualizado_por' => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByField(string $field, string $value): ?array
    {
        if (!in_array($field, ['username', 'email'], true)) {
            throw new \InvalidArgumentException('Unsupported user lookup field.');
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT id, username, email, activo, eliminado_en
             FROM usuarios
             WHERE ' . $field . ' = :value
             LIMIT 1'
        );
        $statement->execute(['value' => $value]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }
}
