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
}
