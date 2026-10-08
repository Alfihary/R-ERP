<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class ClassificationRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(bool $forUpdate = false): array
    {
        $sql = <<<'SQL'
            SELECT id, parent_id, codigo, nombre, activo
            FROM clasificaciones_producto
            WHERE eliminado_en IS NULL
            ORDER BY codigo
            SQL;

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        return $this->connection->pdo()->query($sql)->fetchAll();
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM clasificaciones_producto
            WHERE codigo = :codigo
            SQL;
        $parameters = ['codigo' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $parameters['except_id'] = $exceptId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() > 0;
    }

    public function create(
        string $code,
        string $name,
        ?int $parentId,
        int $actorId
    ): int {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO clasificaciones_producto (
                parent_id,
                codigo,
                nombre,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :parent_id,
                :codigo,
                :nombre,
                1,
                :creado_por,
                :actualizado_por
            )
            SQL
        );
        $statement->execute([
            'parent_id' => $parentId,
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function update(
        int $id,
        string $code,
        string $name,
        ?int $parentId,
        int $actorId
    ): void {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE clasificaciones_producto
            SET parent_id = :parent_id,
                codigo = :codigo,
                nombre = :nombre,
                actualizado_por = :actualizado_por
            WHERE id = :id
              AND eliminado_en IS NULL
            SQL
        );
        $statement->execute([
            'parent_id' => $parentId,
            'codigo' => $code,
            'nombre' => $name,
            'actualizado_por' => $actorId,
            'id' => $id,
        ]);
    }

    public function setActive(int $id, bool $active, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE clasificaciones_producto
            SET activo = :activo,
                actualizado_por = :actualizado_por
            WHERE id = :id
              AND eliminado_en IS NULL
            SQL
        );
        $statement->execute([
            'activo' => $active ? 1 : 0,
            'actualizado_por' => $actorId,
            'id' => $id,
        ]);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
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
}
