<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class CompanyRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @param array{search?: string, status?: string, page?: int, per_page?: int} $filters
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
                id,
                codigo,
                nombre,
                razon_social,
                nombre_comercial,
                rfc,
                regimen_fiscal,
                telefono,
                email,
                sitio_web,
                pais,
                estado,
                municipio,
                colonia,
                calle,
                numero_exterior,
                numero_interior,
                codigo_postal,
                logo_path,
                color_primario,
                activo
            FROM empresas
            WHERE
            SQL
            . ' ' . $where
            . ' ORDER BY nombre, codigo LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * @param array{search?: string, status?: string} $filters
     */
    public function count(array $filters): int
    {
        [$where, $parameters] = $this->conditions($filters);
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM empresas WHERE ' . $where
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
                id,
                codigo,
                nombre,
                razon_social,
                nombre_comercial,
                rfc,
                regimen_fiscal,
                telefono,
                email,
                sitio_web,
                pais,
                estado,
                municipio,
                colonia,
                calle,
                numero_exterior,
                numero_interior,
                codigo_postal,
                logo_path,
                color_primario,
                activo
            FROM empresas
            WHERE id = :id AND eliminado_en IS NULL
            LIMIT 1
            SQL
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array{id: int, codigo: string, nombre: string}>
     */
    public function activeOptions(): array
    {
        $rows = $this->connection->pdo()->query(
            'SELECT id, codigo, nombre
             FROM empresas
             WHERE activo = 1 AND eliminado_en IS NULL
             ORDER BY nombre, codigo'
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'codigo' => (string) $row['codigo'],
            'nombre' => (string) $row['nombre'],
        ], $rows);
    }

    public function existsCode(string $code, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM empresas WHERE codigo = :codigo';
        $parameters = ['codigo' => $code];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $parameters['id'] = $excludeId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function create(array $data, int $adminUserId): int
    {
        return $this->transactional(function () use ($data, $adminUserId): int {
            $columns = array_keys($data);
            $placeholders = array_map(
                static fn (string $column): string => ':' . $column,
                $columns
            );
            $statement = $this->connection->pdo()->prepare(
                'INSERT INTO empresas ('
                . implode(', ', $columns)
                . ', activo, creado_por, actualizado_por) VALUES ('
                . implode(', ', $placeholders)
                . ', 1, :creado_por, :actualizado_por)'
            );
            $statement->execute($data + [
                'creado_por' => $adminUserId,
                'actualizado_por' => $adminUserId,
            ]);
            $companyId = (int) $this->connection->pdo()->lastInsertId();
            $this->assignUser($companyId, $adminUserId);

            return $companyId;
        });
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function update(int $id, array $data, int $actorId): void
    {
        $assignments = array_map(
            static fn (string $column): string => $column . ' = :' . $column,
            array_keys($data)
        );
        $statement = $this->connection->pdo()->prepare(
            'UPDATE empresas SET '
            . implode(', ', $assignments)
            . ', actualizado_por = :actualizado_por
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute($data + [
            'actualizado_por' => $actorId,
            'id' => $id,
        ]);
    }

    public function activate(int $id, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE empresas
             SET activo = 1, actualizado_por = :actor_id
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $id, 'actor_id' => $actorId]);
    }

    public function deactivate(int $id, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE empresas
             SET activo = 0, actualizado_por = :actor_id
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $id, 'actor_id' => $actorId]);
    }

    public function assignUser(int $companyId, int $userId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO usuario_empresas (
                usuario_id,
                empresa_id,
                activo,
                creado_por,
                actualizado_por
            ) VALUES (
                :usuario_id,
                :empresa_id,
                1,
                :creado_por,
                :actualizado_por
            )
            ON DUPLICATE KEY UPDATE
                activo = 1,
                eliminado_en = NULL,
                eliminado_por = NULL,
                actualizado_por = :actualizado_por_duplicate
            SQL
        );
        $statement->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'creado_por' => $userId,
            'actualizado_por' => $userId,
            'actualizado_por_duplicate' => $userId,
        ]);
    }

    public function activeWarehouseCount(int $companyId): int
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM almacenes
             WHERE empresa_id = :empresa_id
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['empresa_id' => $companyId]);

        return (int) $statement->fetchColumn();
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
     * @param array{search?: string, status?: string} $filters
     * @return array{0: string, 1: array<string, string>}
     */
    private function conditions(array $filters): array
    {
        $conditions = ['eliminado_en IS NULL'];
        $parameters = [];

        if (($filters['search'] ?? '') !== '') {
            $conditions[] =
                '(codigo LIKE :search_code OR nombre LIKE :search_name OR rfc LIKE :search_rfc)';
            $term = '%' . (string) $filters['search'] . '%';
            $parameters['search_code'] = $term;
            $parameters['search_name'] = $term;
            $parameters['search_rfc'] = $term;
        }

        if (($filters['status'] ?? '') === 'active') {
            $conditions[] = 'activo = 1';
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $conditions[] = 'activo = 0';
        }

        return [implode(' AND ', $conditions), $parameters];
    }
}
