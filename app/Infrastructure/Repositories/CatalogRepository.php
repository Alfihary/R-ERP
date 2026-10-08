<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class CatalogRepository
{
    /**
     * @var array<string, array{table: string, columns: list<string>}>
     */
    private const CATALOGS = [
        'monedas' => [
            'table' => 'monedas',
            'columns' => [
                'id',
                'codigo',
                'nombre',
                'simbolo',
                'decimales',
                'es_base',
                'activo',
            ],
        ],
        'unidades' => [
            'table' => 'unidades_medida',
            'columns' => [
                'id',
                'codigo',
                'nombre',
                'abreviatura',
                'activo',
            ],
        ],
        'impuestos' => [
            'table' => 'impuestos',
            'columns' => [
                'id',
                'codigo',
                'nombre',
                'tasa',
                'tipo',
                'activo',
            ],
        ],
        'lineas' => [
            'table' => 'lineas_producto',
            'columns' => ['id', 'codigo', 'nombre', 'activo'],
        ],
        'marcas' => [
            'table' => 'marcas',
            'columns' => ['id', 'codigo', 'nombre', 'activo'],
        ],
    ];

    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(string $catalog): array
    {
        $definition = $this->definition($catalog);
        $statement = $this->connection->pdo()->query(
            'SELECT ' . implode(', ', $definition['columns'])
            . ' FROM ' . $definition['table']
            . ' WHERE eliminado_en IS NULL'
            . ' ORDER BY codigo'
        );

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $catalog, int $id): ?array
    {
        $definition = $this->definition($catalog);
        $statement = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', $definition['columns'])
            . ' FROM ' . $definition['table']
            . ' WHERE id = :id AND eliminado_en IS NULL'
            . ' LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $record = $statement->fetch();

        return $record === false ? null : $record;
    }

    public function codeExists(
        string $catalog,
        string $code,
        ?int $exceptId = null
    ): bool {
        $definition = $this->definition($catalog);
        $sql = 'SELECT COUNT(*) FROM ' . $definition['table']
            . ' WHERE codigo = :codigo';
        $parameters = ['codigo' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $parameters['except_id'] = $exceptId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array<string, int|string> $data
     */
    public function create(
        string $catalog,
        array $data,
        int $actorId
    ): int {
        if ($catalog === 'monedas' && ($data['es_base'] ?? 0) === 1) {
            return $this->transactional(function () use (
                $catalog,
                $data,
                $actorId
            ): int {
                $this->clearBaseCurrency($actorId);
                return $this->insert($catalog, $data, $actorId);
            });
        }

        return $this->insert($catalog, $data, $actorId);
    }

    /**
     * @param array<string, int|string> $data
     */
    public function update(
        string $catalog,
        int $id,
        array $data,
        int $actorId
    ): void {
        if ($catalog === 'monedas' && ($data['es_base'] ?? 0) === 1) {
            $this->transactional(function () use (
                $catalog,
                $id,
                $data,
                $actorId
            ): void {
                $this->clearBaseCurrency($actorId);
                $this->updateRecord($catalog, $id, $data, $actorId);
            });
            return;
        }

        $this->updateRecord($catalog, $id, $data, $actorId);
    }

    public function setActive(
        string $catalog,
        int $id,
        bool $active,
        int $actorId
    ): void {
        $definition = $this->definition($catalog);
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . $definition['table']
            . ' SET activo = :activo, actualizado_por = :actor_id'
            . ' WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute([
            'activo' => $active ? 1 : 0,
            'actor_id' => $actorId,
            'id' => $id,
        ]);

        if ($statement->rowCount() === 0 && $this->find($catalog, $id) === null) {
            throw new \RuntimeException('Catalog record was not found.');
        }
    }

    /**
     * @param array<string, int|string> $data
     */
    private function insert(
        string $catalog,
        array $data,
        int $actorId
    ): int {
        $definition = $this->definition($catalog);
        $this->assertWritableColumns($definition['columns'], $data);
        $columns = array_keys($data);
        $placeholders = array_map(
            static fn (string $column): string => ':' . $column,
            $columns
        );
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . $definition['table']
            . ' (' . implode(', ', $columns)
            . ', activo, creado_por, actualizado_por)'
            . ' VALUES (' . implode(', ', $placeholders)
            . ', 1, :creado_por, :actualizado_por)'
        );
        $statement->execute($data + [
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, int|string> $data
     */
    private function updateRecord(
        string $catalog,
        int $id,
        array $data,
        int $actorId
    ): void {
        $definition = $this->definition($catalog);
        $this->assertWritableColumns($definition['columns'], $data);
        $assignments = array_map(
            static fn (string $column): string =>
                $column . ' = :' . $column,
            array_keys($data)
        );
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . $definition['table']
            . ' SET ' . implode(', ', $assignments)
            . ', actualizado_por = :actualizado_por'
            . ' WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute($data + [
            'actualizado_por' => $actorId,
            'id' => $id,
        ]);

        if ($statement->rowCount() === 0 && $this->find($catalog, $id) === null) {
            throw new \RuntimeException('Catalog record was not found.');
        }
    }

    private function clearBaseCurrency(int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE monedas
             SET es_base = 0, actualizado_por = :actor_id
             WHERE es_base = 1 AND eliminado_en IS NULL'
        );
        $statement->execute(['actor_id' => $actorId]);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
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
     * @param list<string> $columns
     * @param array<string, int|string> $data
     */
    private function assertWritableColumns(array $columns, array $data): void
    {
        $writable = array_diff($columns, ['id', 'activo']);

        if ($data === [] || array_diff(array_keys($data), $writable) !== []) {
            throw new \InvalidArgumentException(
                'Catalog write contains unsupported fields.'
            );
        }
    }

    /**
     * @return array{table: string, columns: list<string>}
     */
    private function definition(string $catalog): array
    {
        $definition = self::CATALOGS[$catalog] ?? null;

        if ($definition === null) {
            throw new \InvalidArgumentException('Unsupported catalog.');
        }

        return $definition;
    }
}
