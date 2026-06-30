<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Scope\ScopeRepositoryInterface;
use App\Infrastructure\Database\ConnectionProvider;

final class ScopeRepository implements ScopeRepositoryInterface
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    public function companiesForUser(int $userId): array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                e.id,
                e.codigo,
                e.nombre
            FROM usuarios u
            INNER JOIN usuario_empresas ue
                ON ue.usuario_id = u.id
               AND ue.activo = 1
               AND ue.eliminado_en IS NULL
            INNER JOIN empresas e
                ON e.id = ue.empresa_id
               AND e.activo = 1
               AND e.eliminado_en IS NULL
            WHERE u.id = :usuario_id
              AND u.activo = 1
              AND u.eliminado_en IS NULL
            ORDER BY e.nombre ASC, e.id ASC
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);

        $companies = [];

        while (($row = $statement->fetch()) !== false) {
            $companies[] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['codigo'],
                'name' => (string) $row['nombre'],
            ];
        }

        return $companies;
    }

    /**
     * @return list<array{id: int, company_id: int, code: string, name: string}>
     */
    public function warehousesForUser(int $userId): array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                a.id,
                a.empresa_id,
                a.codigo,
                a.nombre
            FROM usuarios u
            INNER JOIN usuario_almacenes ua
                ON ua.usuario_id = u.id
               AND ua.activo = 1
               AND ua.eliminado_en IS NULL
            INNER JOIN usuario_empresas ue
                ON ue.usuario_id = ua.usuario_id
               AND ue.empresa_id = ua.empresa_id
               AND ue.activo = 1
               AND ue.eliminado_en IS NULL
            INNER JOIN empresas e
                ON e.id = ue.empresa_id
               AND e.activo = 1
               AND e.eliminado_en IS NULL
            INNER JOIN almacenes a
                ON a.id = ua.almacen_id
               AND a.empresa_id = ua.empresa_id
               AND a.activo = 1
               AND a.eliminado_en IS NULL
            WHERE u.id = :usuario_id
              AND u.activo = 1
              AND u.eliminado_en IS NULL
            ORDER BY e.nombre ASC, a.nombre ASC, a.id ASC
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);

        $warehouses = [];

        while (($row = $statement->fetch()) !== false) {
            $warehouses[] = [
                'id' => (int) $row['id'],
                'company_id' => (int) $row['empresa_id'],
                'code' => (string) $row['codigo'],
                'name' => (string) $row['nombre'],
            ];
        }

        return $warehouses;
    }
}
