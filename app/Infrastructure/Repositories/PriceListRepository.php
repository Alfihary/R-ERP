<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class PriceListRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM listas_precios
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveById(int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM listas_precios
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDefault(): ?array
    {
        $row = $this->connection->pdo()->query(
            'SELECT *
             FROM listas_precios
             WHERE es_predeterminada = 1
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        )->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(): array
    {
        return $this->connection->pdo()->query(
            'SELECT *
             FROM listas_precios
             WHERE activo = 1
               AND eliminado_en IS NULL
             ORDER BY nombre, clave'
        )->fetchAll();
    }

    public function existsClave(string $clave, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*)
                FROM listas_precios
                WHERE clave = :clave';
        $params = ['clave' => $clave];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function insert(array $data): int
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO listas_precios (
                clave,
                nombre,
                observaciones,
                incluye_impuestos,
                es_predeterminada,
                activo,
                creado_por,
                actualizado_por
             ) VALUES (
                :clave,
                :nombre,
                :observaciones,
                :incluye_impuestos,
                :es_predeterminada,
                :activo,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'clave' => $data['clave'],
            'nombre' => $data['nombre'],
            'observaciones' => $data['observaciones'] ?? null,
            'incluye_impuestos' => $data['incluye_impuestos'] ?? 0,
            'es_predeterminada' => $data['es_predeterminada'] ?? 0,
            'activo' => $data['activo'] ?? 1,
            'creado_por' => $data['creado_por'] ?? null,
            'actualizado_por' => $data['actualizado_por'] ?? null,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function update(int $id, array $data): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE listas_precios
             SET clave = :clave,
                 nombre = :nombre,
                 observaciones = :observaciones,
                 incluye_impuestos = :incluye_impuestos,
                 es_predeterminada = :es_predeterminada,
                 activo = :activo,
                 actualizado_en = CURRENT_TIMESTAMP,
                 actualizado_por = :actualizado_por
             WHERE id = :id
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $id,
            'clave' => $data['clave'],
            'nombre' => $data['nombre'],
            'observaciones' => $data['observaciones'] ?? null,
            'incluye_impuestos' => $data['incluye_impuestos'],
            'es_predeterminada' => $data['es_predeterminada'],
            'activo' => $data['activo'],
            'actualizado_por' => $data['actualizado_por'] ?? null,
        ]);
    }

    public function softDelete(int $id, int $userId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE listas_precios
             SET activo = 0,
                 actualizado_en = CURRENT_TIMESTAMP,
                 actualizado_por = :actualizado_por,
                 eliminado_en = CURRENT_TIMESTAMP,
                 eliminado_por = :eliminado_por
             WHERE id = :id
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $id,
            'actualizado_por' => $userId,
            'eliminado_por' => $userId,
        ]);
    }
}
