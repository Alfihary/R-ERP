<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Tickets\ResponsibleRecipientRepositoryInterface;
use App\Infrastructure\Database\ReadConnectionProvider;

final class ResponsibleRecipientRepository implements ResponsibleRecipientRepositoryInterface
{
    public function __construct(private readonly ReadConnectionProvider $connection)
    {
    }

    public function findEmailsByScopeAndPermission(
        int $empresaId,
        int $almacenId,
        int $actorUserId
    ): array {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT DISTINCT LOWER(TRIM(u.email)) AS email
            FROM usuarios u
            INNER JOIN usuario_empresas ue
                ON ue.usuario_id = u.id
               AND ue.empresa_id = :empresa_id
               AND ue.activo = 1
               AND ue.eliminado_en IS NULL
            INNER JOIN usuario_almacenes ua
                ON ua.usuario_id = u.id
               AND ua.empresa_id = :almacen_empresa_id
               AND ua.almacen_id = :almacen_id
               AND ua.activo = 1
               AND ua.eliminado_en IS NULL
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
               AND p.codigo = 'tickets_productos.resolver'
            INNER JOIN almacenes a
                ON a.id = ua.almacen_id
               AND a.empresa_id = ua.empresa_id
               AND a.empresa_id = :warehouse_empresa_id
               AND a.activo = 1
               AND a.eliminado_en IS NULL
            WHERE u.activo = 1
              AND u.eliminado_en IS NULL
              AND u.id <> :actor_user_id
              AND TRIM(u.email) <> ''
            ORDER BY email ASC
            SQL
        );
        $statement->execute([
            'empresa_id' => $empresaId,
            'almacen_empresa_id' => $empresaId,
            'almacen_id' => $almacenId,
            'warehouse_empresa_id' => $empresaId,
            'actor_user_id' => $actorUserId,
        ]);

        return array_values(array_filter(
            array_map(
                static fn (mixed $email): string => strtolower(trim((string) $email)),
                $statement->fetchAll(\PDO::FETCH_COLUMN)
            ),
            static fn (string $email): bool => $email !== ''
        ));
    }
}
