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

    /** @return array{id:int}|null */
    public function activeCompany(int $companyId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id FROM empresas WHERE id = :id AND activo = 1 AND eliminado_en IS NULL LIMIT 1'
        );
        $statement->execute(['id' => $companyId]);
        $row = $statement->fetch();
        return $row === false ? null : ['id' => (int) $row['id']];
    }

    /** @return array{id:int,empresa_id:int}|null */
    public function activeWarehouseForCompany(int $warehouseId, int $companyId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, empresa_id FROM almacenes
             WHERE id = :id AND empresa_id = :empresa_id
               AND activo = 1 AND eliminado_en IS NULL LIMIT 1'
        );
        $statement->execute(['id' => $warehouseId, 'empresa_id' => $companyId]);
        $row = $statement->fetch();
        return $row === false ? null : [
            'id' => (int) $row['id'],
            'empresa_id' => (int) $row['empresa_id'],
        ];
    }

    /** @return list<array{id:int,codigo:string,nombre:string}> */
    public function activeCompanyOptions(): array
    {
        return $this->connection->pdo()->query(
            'SELECT id, codigo, nombre FROM empresas
             WHERE activo = 1 AND eliminado_en IS NULL ORDER BY nombre, codigo'
        )->fetchAll();
    }

    /** @return list<array{id:int,empresa_id:int,codigo:string,nombre:string}> */
    public function activeWarehouseOptions(): array
    {
        return $this->connection->pdo()->query(
            'SELECT id, empresa_id, codigo, nombre FROM almacenes
             WHERE activo = 1 AND eliminado_en IS NULL ORDER BY nombre, codigo'
        )->fetchAll();
    }

    public function replaceUserScope(int $userId, int $companyId, int $warehouseId, int $actorId): void
    {
        $pdo = $this->connection->pdo();
        $deactivateCompanies = $pdo->prepare(
            'UPDATE usuario_empresas SET activo = 0, eliminado_en = CURRENT_TIMESTAMP,
                    eliminado_por = :actor_deleted, actualizado_por = :actor_updated
             WHERE usuario_id = :user_id AND empresa_id <> :company_id AND activo = 1'
        );
        $deactivateCompanies->execute([
            'actor_deleted' => $actorId, 'actor_updated' => $actorId,
            'user_id' => $userId, 'company_id' => $companyId,
        ]);
        $company = $pdo->prepare(
            'INSERT INTO usuario_empresas (usuario_id, empresa_id, activo, creado_por, actualizado_por)
             VALUES (:user_id, :company_id, 1, :actor_created, :actor_updated)
             ON DUPLICATE KEY UPDATE activo = 1, eliminado_en = NULL,
                eliminado_por = NULL, actualizado_por = :actor_update'
        );
        $company->execute([
            'user_id' => $userId, 'company_id' => $companyId,
            'actor_created' => $actorId, 'actor_updated' => $actorId, 'actor_update' => $actorId,
        ]);

        $warehouseRows = $pdo->prepare(
            'UPDATE usuario_almacenes SET activo = 0, eliminado_en = CURRENT_TIMESTAMP,
                    eliminado_por = :actor_deleted, actualizado_por = :actor_updated
             WHERE usuario_id = :user_id AND almacen_id <> :warehouse_id AND activo = 1'
        );
        $warehouseRows->execute([
            'actor_deleted' => $actorId, 'actor_updated' => $actorId,
            'user_id' => $userId, 'warehouse_id' => $warehouseId,
        ]);
        $warehouse = $pdo->prepare(
            'INSERT INTO usuario_almacenes (usuario_id, empresa_id, almacen_id, activo, creado_por, actualizado_por)
             VALUES (:user_id, :company_id, :warehouse_id, 1, :actor_created, :actor_updated)
             ON DUPLICATE KEY UPDATE empresa_id = VALUES(empresa_id), activo = 1,
                eliminado_en = NULL, eliminado_por = NULL, actualizado_por = :actor_update'
        );
        $warehouse->execute([
            'user_id' => $userId, 'company_id' => $companyId,
            'warehouse_id' => $warehouseId, 'actor_created' => $actorId,
            'actor_updated' => $actorId,
            'actor_update' => $actorId,
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
