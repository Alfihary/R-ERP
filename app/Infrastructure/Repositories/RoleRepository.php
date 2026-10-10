<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class RoleRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveSystemRole(string $code): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT id, codigo
            FROM roles
            WHERE codigo = :codigo
              AND es_sistema = 1
              AND activo = 1
              AND eliminado_en IS NULL
            LIMIT 1
            SQL
        );
        $statement->execute(['codigo' => $code]);
        $role = $statement->fetch();

        return $role === false ? null : $role;
    }

    public function userHasRole(int $userId, int $roleId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT COUNT(*)
            FROM usuario_roles
            WHERE usuario_id = :usuario_id
              AND rol_id = :rol_id
              AND activo = 1
              AND eliminado_en IS NULL
            SQL
        );
        $statement->execute([
            'usuario_id' => $userId,
            'rol_id' => $roleId,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function assignUser(int $userId, int $roleId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO usuario_roles (usuario_id, rol_id, creado_por)
            VALUES (:usuario_id, :rol_id, :creado_por)
            SQL
        );
        $statement->execute([
            'usuario_id' => $userId,
            'rol_id' => $roleId,
            'creado_por' => $userId,
        ]);
    }

    /** @return list<array{id:int,codigo:string,nombre:string,activo:int,eliminado_en:string|null}> */
    public function activeRolesByIds(array $roleIds): array
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        if ($roleIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, codigo, nombre, activo, eliminado_en FROM roles
             WHERE id IN (' . $placeholders . ') AND activo = 1 AND eliminado_en IS NULL
             ORDER BY id'
        );
        $statement->execute($roleIds);
        return $statement->fetchAll();
    }

    /** @return list<array{id:int,codigo:string,nombre:string}> */
    public function activeOptions(): array
    {
        return $this->connection->pdo()->query(
            'SELECT id, codigo, nombre FROM roles
             WHERE activo = 1 AND eliminado_en IS NULL
             ORDER BY nombre, codigo, id'
        )->fetchAll();
    }

    /** @return list<array{id:int,codigo:string}> */
    public function activeRolesForUser(int $userId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT r.id, r.codigo FROM usuario_roles ur
             INNER JOIN roles r ON r.id = ur.rol_id
             WHERE ur.usuario_id = :user_id AND ur.activo = 1 AND ur.eliminado_en IS NULL
               AND r.activo = 1 AND r.eliminado_en IS NULL ORDER BY r.id'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    /** @return array{id:int,codigo:string}|null */
    public function findStructural(string $code): ?array
    {
        return $this->findActiveSystemRole($code);
    }

    /** @return array{id:int,codigo:string}|null */
    public function lockStructural(string $code): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, codigo FROM roles
             WHERE codigo = :codigo AND es_sistema = 1 AND activo = 1
               AND eliminado_en IS NULL LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['codigo' => $code]);
        $role = $statement->fetch();
        return $role === false ? null : $role;
    }

    /** @return list<array{id:int,rol_id:int,activo:int,eliminado_en:string|null}> */
    public function lockUserRoleRows(int $userId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT usuario_id, rol_id, activo, eliminado_en FROM usuario_roles
             WHERE usuario_id = :user_id ORDER BY rol_id FOR UPDATE'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    /** @param list<int> $roleIds */
    public function replaceUserRoles(int $userId, array $roleIds, int $actorId): void
    {
        $existing = $this->activeRolesForUser($userId);
        $current = array_map(static fn (array $row): int => (int) $row['id'], $existing);
        $desired = array_values(array_unique(array_map('intval', $roleIds)));
        $remove = array_values(array_diff($current, $desired));
        $add = array_values(array_diff($desired, $current));
        $pdo = $this->connection->pdo();

        foreach ($remove as $roleId) {
            $statement = $pdo->prepare(
                'UPDATE usuario_roles SET activo = 0, eliminado_en = CURRENT_TIMESTAMP,
                        eliminado_por = :actor_deleted, actualizado_por = :actor_updated
                 WHERE usuario_id = :user_id AND rol_id = :role_id AND activo = 1'
            );
            $statement->execute([
                'actor_deleted' => $actorId, 'actor_updated' => $actorId,
                'user_id' => $userId, 'role_id' => $roleId,
            ]);
        }
        foreach ($add as $roleId) {
            $statement = $pdo->prepare(
                'INSERT INTO usuario_roles (usuario_id, rol_id, activo, creado_por, actualizado_por)
                 VALUES (:user_id, :role_id, 1, :actor_created, :actor_updated)
                 ON DUPLICATE KEY UPDATE activo = 1, eliminado_en = NULL,
                    eliminado_por = NULL, actualizado_por = :actor_update'
            );
            $statement->execute([
                'user_id' => $userId,
                'role_id' => $roleId,
                'actor_created' => $actorId,
                'actor_updated' => $actorId,
                'actor_update' => $actorId,
            ]);
        }
    }

    public function deactivateUserRoles(int $userId, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE usuario_roles SET activo = 0, eliminado_en = CURRENT_TIMESTAMP,
                    eliminado_por = :actor_deleted, actualizado_por = :actor_updated
             WHERE usuario_id = :user_id AND activo = 1 AND eliminado_en IS NULL'
        );
        $statement->execute([
            'actor_deleted' => $actorId, 'actor_updated' => $actorId,
            'user_id' => $userId,
        ]);
    }

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
}
