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
            SELECT id, username, email, password_hash, activo, eliminado_en
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

    /** @return array<string, mixed>|null */
    public function findByIdForUpdate(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, username, email, password_hash, activo, ultimo_acceso_en,
                    actualizado_en, eliminado_en
             FROM usuarios WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    public function existsUsername(string $username, ?int $exceptUserId = null): bool
    {
        return $this->exists('username', $username, $exceptUserId);
    }

    public function existsEmail(string $email, ?int $exceptUserId = null): bool
    {
        return $this->exists('email', $email, $exceptUserId);
    }

    /** @param array{username:string,email:string,password_hash:string,activo:int,creado_por:int,actualizado_por:int} $data */
    public function insertAdmin(array $data): int
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo, creado_por, actualizado_por)
             VALUES (:username, :email, :password_hash, :activo, :creado_por, :actualizado_por)'
        );
        $statement->execute($data);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /** @param array{username:string,email:string,actualizado_por:int} $data */
    public function updateAdmin(int $userId, array $data): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE usuarios SET username = :username, email = :email,
                    actualizado_por = :actualizado_por
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute($data + ['id' => $userId]);
    }

    public function setActive(int $userId, bool $active, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE usuarios SET activo = :activo, actualizado_por = :actor_id
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $userId,
            'activo' => $active ? 1 : 0,
            'actor_id' => $actorId,
        ]);
    }

    public function softDelete(int $userId, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE usuarios SET activo = 0, eliminado_en = CURRENT_TIMESTAMP,
                    eliminado_por = :actor_deleted, actualizado_por = :actor_updated
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $userId,
            'actor_deleted' => $actorId,
            'actor_updated' => $actorId,
        ]);
    }

    public function updateAdminPassword(int $userId, string $hash, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE usuarios SET password_hash = :hash, actualizado_por = :actor_id
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $userId, 'hash' => $hash, 'actor_id' => $actorId]);
    }

    /** @return list<array{id:int,username:string,email:string,activo:int,eliminado_en:string|null}> */
    public function usableAdminsForUpdate(int $adminRoleId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT u.id, u.username, u.email, u.activo, u.eliminado_en
             FROM usuarios u
             INNER JOIN usuario_roles ur ON ur.usuario_id = u.id
                AND ur.rol_id = :rol_id AND ur.activo = 1 AND ur.eliminado_en IS NULL
             WHERE u.activo = 1 AND u.eliminado_en IS NULL
             ORDER BY u.id FOR UPDATE'
        );
        $statement->execute(['rol_id' => $adminRoleId]);

        return $statement->fetchAll();
    }

    /** @param callable(): mixed $operation */
    public function transactional(callable $operation): mixed
    {
        $pdo = $this->connection->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($owns) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function exists(string $field, string $value, ?int $exceptUserId): bool
    {
        if (!in_array($field, ['username', 'email'], true)) {
            throw new \InvalidArgumentException('Unsupported user uniqueness field.');
        }
        $sql = 'SELECT COUNT(*) FROM usuarios WHERE ' . $field . ' = :value';
        $parameters = ['value' => $value];
        if ($exceptUserId !== null) {
            $sql .= ' AND id <> :id';
            $parameters['id'] = $exceptUserId;
        }
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn() > 0;
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
