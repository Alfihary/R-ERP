<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class WarehouseRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @param array{
     *     search?: string,
     *     status?: string,
     *     company_id?: int|null,
     *     page?: int,
     *     per_page?: int
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function paginate(array $filters): array
    {
        [$where, $parameters] = $this->conditions($filters);
        $limit = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                a.id,
                a.empresa_id,
                e.nombre AS empresa_nombre,
                e.codigo AS empresa_codigo,
                a.codigo,
                a.nombre,
                a.tipo_almacen,
                a.responsable,
                a.telefono,
                a.email,
                a.pais,
                a.estado,
                a.municipio,
                a.colonia,
                a.calle,
                a.numero_exterior,
                a.numero_interior,
                a.codigo_postal,
                a.permite_ventas,
                a.permite_compras,
                a.permite_inventario,
                a.permite_transferencias,
                a.es_principal,
                a.activo
            FROM almacenes a
            INNER JOIN empresas e ON e.id = a.empresa_id
            WHERE
            SQL
            . ' ' . $where
            . ' ORDER BY e.nombre, a.nombre, a.codigo LIMIT '
            . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * @param array{search?: string, status?: string, company_id?: int|null} $filters
     */
    public function count(array $filters): int
    {
        [$where, $parameters] = $this->conditions($filters);
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM almacenes a
             INNER JOIN empresas e ON e.id = a.empresa_id
             WHERE ' . $where
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                a.id,
                a.empresa_id,
                e.nombre AS empresa_nombre,
                e.codigo AS empresa_codigo,
                a.codigo,
                a.nombre,
                a.tipo_almacen,
                a.responsable,
                a.telefono,
                a.email,
                a.pais,
                a.estado,
                a.municipio,
                a.colonia,
                a.calle,
                a.numero_exterior,
                a.numero_interior,
                a.codigo_postal,
                a.permite_ventas,
                a.permite_compras,
                a.permite_inventario,
                a.permite_transferencias,
                a.es_principal,
                a.activo
            FROM almacenes a
            INNER JOIN empresas e ON e.id = a.empresa_id
            WHERE a.id = :id AND a.eliminado_en IS NULL
            LIMIT 1
            SQL
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function existsCodeForCompany(
        int $companyId,
        string $code,
        ?int $excludeId = null
    ): bool {
        $sql = 'SELECT COUNT(*)
                FROM almacenes
                WHERE empresa_id = :empresa_id AND codigo = :codigo';
        $parameters = [
            'empresa_id' => $companyId,
            'codigo' => $code,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $parameters['id'] = $excludeId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() > 0;
    }

    public function activeCompanyExists(int $companyId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM empresas
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $companyId]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function create(array $data, int $adminUserId): int
    {
        return $this->transactional(function () use ($data, $adminUserId): int {
            if ((int) ($data['es_principal'] ?? 0) === 1) {
                $this->unsetPrincipalForCompany((int) $data['empresa_id']);
            }

            $columns = array_keys($data);
            $placeholders = array_map(
                static fn (string $column): string => ':' . $column,
                $columns
            );
            $statement = $this->connection->pdo()->prepare(
                'INSERT INTO almacenes ('
                . implode(', ', $columns)
                . ', activo, creado_por, actualizado_por) VALUES ('
                . implode(', ', $placeholders)
                . ', 1, :creado_por, :actualizado_por)'
            );
            $statement->execute($data + [
                'creado_por' => $adminUserId,
                'actualizado_por' => $adminUserId,
            ]);
            $warehouseId = (int) $this->connection->pdo()->lastInsertId();
            $this->assignUser(
                $warehouseId,
                (int) $data['empresa_id'],
                $adminUserId
            );

            return $warehouseId;
        });
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function update(int $id, array $data, int $actorId): void
    {
        $this->transactional(function () use ($id, $data, $actorId): void {
            if ((int) ($data['es_principal'] ?? 0) === 1) {
                $this->unsetPrincipalForCompany((int) $data['empresa_id'], $id);
            }

            $assignments = array_map(
                static fn (string $column): string =>
                    $column . ' = :' . $column,
                array_keys($data)
            );
            $statement = $this->connection->pdo()->prepare(
                'UPDATE almacenes SET '
                . implode(', ', $assignments)
                . ', actualizado_por = :actualizado_por
                 WHERE id = :id AND eliminado_en IS NULL'
            );
            $statement->execute($data + [
                'actualizado_por' => $actorId,
                'id' => $id,
            ]);

            $this->assignUser(
                $id,
                (int) $data['empresa_id'],
                $actorId
            );
        });
    }

    public function activate(int $id, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE almacenes
             SET activo = 1, actualizado_por = :actor_id
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $id, 'actor_id' => $actorId]);
    }

    public function deactivate(int $id, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE almacenes
             SET activo = 0,
                 es_principal = 0,
                 actualizado_por = :actor_id
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $id, 'actor_id' => $actorId]);
    }

    public function activeWarehouseCount(int $companyId, ?int $excludeId = null): int
    {
        $sql = 'SELECT COUNT(*)
                FROM almacenes
                WHERE empresa_id = :empresa_id
                  AND activo = 1
                  AND eliminado_en IS NULL';
        $parameters = ['empresa_id' => $companyId];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $parameters['id'] = $excludeId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function assignUser(int $warehouseId, int $companyId, int $userId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO usuario_almacenes (
                usuario_id,
                empresa_id,
                almacen_id,
                activo,
                creado_por,
                actualizado_por
            ) VALUES (
                :usuario_id,
                :empresa_id,
                :almacen_id,
                1,
                :creado_por,
                :actualizado_por
            )
            ON DUPLICATE KEY UPDATE
                empresa_id = :empresa_id_duplicate,
                activo = 1,
                eliminado_en = NULL,
                eliminado_por = NULL,
                actualizado_por = :actualizado_por_duplicate
            SQL
        );
        $statement->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'creado_por' => $userId,
            'actualizado_por' => $userId,
            'empresa_id_duplicate' => $companyId,
            'actualizado_por_duplicate' => $userId,
        ]);
    }

    public function unsetPrincipalForCompany(
        int $companyId,
        ?int $exceptId = null
    ): void {
        $sql = 'UPDATE almacenes
                SET es_principal = 0
                WHERE empresa_id = :empresa_id';
        $parameters = ['empresa_id' => $companyId];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $parameters['except_id'] = $exceptId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($parameters);
    }

    /**
     * @param callable(): mixed $operation
     */
    public function transactional(callable $operation): mixed
    {
        $pdo = $this->connection->pdo();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $operation();

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array{search?: string, status?: string, company_id?: int|null} $filters
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function conditions(array $filters): array
    {
        $conditions = ['a.eliminado_en IS NULL', 'e.eliminado_en IS NULL'];
        $parameters = [];

        if (($filters['search'] ?? '') !== '') {
            $conditions[] =
                '(a.codigo LIKE :search_code OR a.nombre LIKE :search_name OR a.responsable LIKE :search_responsible)';
            $term = '%' . (string) $filters['search'] . '%';
            $parameters['search_code'] = $term;
            $parameters['search_name'] = $term;
            $parameters['search_responsible'] = $term;
        }

        if (($filters['status'] ?? '') === 'active') {
            $conditions[] = 'a.activo = 1';
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $conditions[] = 'a.activo = 0';
        }

        if (($filters['company_id'] ?? null) !== null) {
            $conditions[] = 'a.empresa_id = :empresa_id';
            $parameters['empresa_id'] = (int) $filters['company_id'];
        }

        return [implode(' AND ', $conditions), $parameters];
    }
}
