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
                COALESCE(NULLIF(e.nombre_comercial, ''), e.razon_social, '') AS nombre,
                COALESCE(e.rfc, '') AS codigo
            FROM usuarios u
            INNER JOIN empresas e
                ON e.id = u.empresa_id
               AND e.activo = 1
               AND e.deleted_at IS NULL
            WHERE u.id = :usuario_id
              AND u.activo = 1
              AND u.deleted_at IS NULL
              AND u.empresa_id IS NOT NULL
            ORDER BY nombre ASC, e.id ASC
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
                a.codigo_almacen AS codigo,
                a.nombre
            FROM usuarios u
            INNER JOIN empresas e
                ON e.id = u.empresa_id
               AND e.activo = 1
               AND e.deleted_at IS NULL
            INNER JOIN almacenes a
                ON a.id = u.almacen_id
               AND a.empresa_id = u.empresa_id
               AND a.activo = 1
               AND a.deleted_at IS NULL
            WHERE u.id = :usuario_id
              AND u.activo = 1
              AND u.deleted_at IS NULL
              AND u.empresa_id IS NOT NULL
              AND u.almacen_id IS NOT NULL
            ORDER BY e.nombre_comercial ASC, a.nombre ASC, a.id ASC
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
