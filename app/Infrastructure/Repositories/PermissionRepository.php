<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class PermissionRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    public function userHasPermission(int $userId, string $permissionCode): bool
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM usuarios u
                INNER JOIN usuario_roles ur
                    ON ur.usuario_id = u.id
                   AND ur.activo = 1
                   AND ur.eliminado_en IS NULL
                INNER JOIN roles r
                    ON r.id = ur.rol_id
                   AND r.activo = 1
                   AND r.eliminado_en IS NULL
                INNER JOIN rol_permisos rp
                    ON rp.rol_id = r.id
                   AND rp.activo = 1
                   AND rp.eliminado_en IS NULL
                INNER JOIN permisos p
                    ON p.id = rp.permiso_id
                   AND p.activo = 1
                   AND p.eliminado_en IS NULL
                WHERE u.id = :usuario_id
                  AND u.activo = 1
                  AND u.eliminado_en IS NULL
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
