<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class PermissionRepository
{
    public function __construct(
        private readonly ConnectionProvider $connection
    ) {
    }

    public function userHasPermission(
        int $userId,
        string $permissionCode
    ): bool {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM usuarios u

                INNER JOIN usuario_rol ur
                    ON ur.usuario_id = u.id

                INNER JOIN roles r
                    ON r.id = ur.rol_id
                   AND r.activo = 1
                   AND r.deleted_at IS NULL

                INNER JOIN rol_permiso rp
                    ON rp.rol_id = r.id

                INNER JOIN permisos p
                    ON p.id = rp.permiso_id
                   AND p.activo = 1

                WHERE u.id = :usuario_id
                  AND u.activo = 1
                  AND u.deleted_at IS NULL
                  AND p.codigo = :permiso_codigo
            )
            SQL
        );

        $statement->execute([
            'usuario_id' => $userId,
            'permiso_codigo' => $permissionCode,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }
}