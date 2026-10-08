<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class SatCatalogRepository
{
    public function __construct(
        private readonly ConnectionProvider|PDO $connection
    )
    {
    }

    /**
     * @param array{search: string, status: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listUnits(array $filters): array
    {
        [$where, $params] = $this->filters($filters, 'nombre');

        $statement = $this->pdo()->prepare(
            'SELECT id, codigo, nombre, descripcion, activo
             FROM unidades_sat
             WHERE eliminado_en IS NULL ' . $where . '
             ORDER BY codigo ASC
             LIMIT 200'
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array{search: string, status: string, page: int} $filters
     * @return array{
     *     records: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     total_pages: int
     * }
     */
    public function listKeys(array $filters, int $perPage): array
    {
        [$where, $params] = $this->filters($filters, 'descripcion');

        $count = $this->pdo()->prepare(
            'SELECT COUNT(*)
             FROM claves_sat
             WHERE eliminado_en IS NULL ' . $where
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $filters['page']), $totalPages);
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo()->prepare(
            'SELECT id, codigo, descripcion, activo
             FROM claves_sat
             WHERE eliminado_en IS NULL ' . $where . '
             ORDER BY codigo ASC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'records' => $statement->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return list<array{id: int, codigo: string, descripcion: string}>
     */
    public function searchActiveKeys(string $query, int $limit): array
    {
        $limit = max(1, min($limit, 20));
        $statement = $this->pdo()->prepare(
            <<<'SQL'
            SELECT id, codigo, descripcion
            FROM claves_sat
            WHERE activo = 1
              AND eliminado_en IS NULL
              AND (
                  codigo = :exact
                  OR codigo LIKE :code_prefix
                  OR codigo LIKE :code_any
                  OR descripcion LIKE :description_any
              )
            ORDER BY
                CASE
                    WHEN codigo = :order_exact THEN 0
                    WHEN codigo LIKE :order_prefix THEN 1
                    WHEN codigo LIKE :order_any THEN 2
                    ELSE 3
                END,
                codigo ASC
            LIMIT :limit
            SQL
        );
        $statement->bindValue('exact', $query);
        $statement->bindValue('code_prefix', $query . '%');
        $statement->bindValue('code_any', '%' . $query . '%');
        $statement->bindValue('description_any', '%' . $query . '%');
        $statement->bindValue('order_exact', $query);
        $statement->bindValue('order_prefix', $query . '%');
        $statement->bindValue('order_any', '%' . $query . '%');
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'codigo' => (string) $row['codigo'],
                'descripcion' => (string) $row['descripcion'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUnit(int $id): ?array
    {
        return $this->find('unidades_sat', $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findKey(int $id): ?array
    {
        return $this->find('claves_sat', $id);
    }

    public function unitCodeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->codeExists('unidades_sat', $code, $exceptId);
    }

    public function keyCodeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->codeExists('claves_sat', $code, $exceptId);
    }

    /**
     * @param array{codigo: string, nombre: string, descripcion: ?string} $data
     */
    public function createUnit(array $data, int $actorId): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO unidades_sat (
                codigo, nombre, descripcion, activo, creado_por
             ) VALUES (
                :codigo, :nombre, :descripcion, 1, :creado_por
             )'
        );
        $statement->execute([
            'codigo' => $data['codigo'],
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'],
            'creado_por' => $actorId,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array{codigo: string, nombre: string, descripcion: ?string} $data
     */
    public function updateUnit(int $id, array $data, int $actorId): void
    {
        $statement = $this->pdo()->prepare(
            'UPDATE unidades_sat
             SET codigo = :codigo,
                 nombre = :nombre,
                 descripcion = :descripcion,
                 actualizado_por = :actualizado_por
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $id,
            'codigo' => $data['codigo'],
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'],
            'actualizado_por' => $actorId,
        ]);
    }

    /**
     * @param array{codigo: string, descripcion: string} $data
     */
    public function createKey(array $data, int $actorId): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO claves_sat (
                codigo, descripcion, activo, creado_por
             ) VALUES (
                :codigo, :descripcion, 1, :creado_por
             )'
        );
        $statement->execute([
            'codigo' => $data['codigo'],
            'descripcion' => $data['descripcion'],
            'creado_por' => $actorId,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array{codigo: string, descripcion: string} $data
     */
    public function updateKey(int $id, array $data, int $actorId): void
    {
        $statement = $this->pdo()->prepare(
            'UPDATE claves_sat
             SET codigo = :codigo,
                 descripcion = :descripcion,
                 actualizado_por = :actualizado_por
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $id,
            'codigo' => $data['codigo'],
            'descripcion' => $data['descripcion'],
            'actualizado_por' => $actorId,
        ]);
    }

    public function setUnitActive(int $id, bool $active, int $actorId): void
    {
        $this->setActive('unidades_sat', $id, $active, $actorId);
    }

    public function setKeyActive(int $id, bool $active, int $actorId): void
    {
        $this->setActive('claves_sat', $id, $active, $actorId);
    }

    /**
     * @param array{search: string, status: string} $filters
     * @return array{0: string, 1: array<string, string|int>}
     */
    private function filters(array $filters, string $descriptionColumn): array
    {
        $where = '';
        $params = [];

        if ($filters['search'] !== '') {
            $where .= ' AND (
                codigo = :search_exact
                OR codigo LIKE :search_code
                OR ' . $descriptionColumn . ' LIKE :search_text
            )';
            $params['search_exact'] = $filters['search'];
            $params['search_code'] = $filters['search'] . '%';
            $params['search_text'] = '%' . $filters['search'] . '%';
        }

        if ($filters['status'] === 'active') {
            $where .= ' AND activo = :active_status';
            $params['active_status'] = 1;
        } elseif ($filters['status'] === 'inactive') {
            $where .= ' AND activo = :active_status';
            $params['active_status'] = 0;
        }

        return [$where, $params];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $table, int $id): ?array
    {
        $sql = $table === 'claves_sat'
            ? 'SELECT id, codigo, descripcion, activo
               FROM claves_sat
               WHERE id = :id AND eliminado_en IS NULL
               LIMIT 1'
            : 'SELECT id, codigo, nombre, descripcion, activo
               FROM unidades_sat
               WHERE id = :id AND eliminado_en IS NULL
               LIMIT 1';

        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['id' => $id]);
        $record = $statement->fetch();

        return is_array($record) ? $record : null;
    }

    private function codeExists(
        string $table,
        string $code,
        ?int $exceptId
    ): bool {
        $sql = 'SELECT COUNT(*)
                FROM ' . $table . '
                WHERE codigo = :codigo
                  AND eliminado_en IS NULL';
        $params = ['codigo' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $params['except_id'] = $exceptId;
        }

        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn() > 0;
    }

    private function setActive(
        string $table,
        int $id,
        bool $active,
        int $actorId
    ): void {
        $statement = $this->pdo()->prepare(
            'UPDATE ' . $table . '
             SET activo = :activo,
                 actualizado_por = :actualizado_por
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $id,
            'activo' => $active ? 1 : 0,
            'actualizado_por' => $actorId,
        ]);
    }

    private function pdo(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        return $this->connection->pdo();
    }
}
