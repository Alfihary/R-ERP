<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class ProductSimilarRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /** @return array<string, mixed>|null */
    public function activeProduct(string $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id_producto, descripcion, sku
             FROM productos
             WHERE id_producto = :id
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function search(string $origin, string $term, int $limit = 10): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id_producto, descripcion, sku
             FROM productos
             WHERE activo = 1 AND eliminado_en IS NULL
               AND id_producto <> :origin
               AND (id_producto LIKE :term
                    OR descripcion LIKE :term
                    OR sku LIKE :term)
             ORDER BY descripcion, id_producto
             LIMIT :limit'
        );
        $statement->bindValue(':origin', $origin);
        $statement->bindValue(':term', '%' . $term . '%');
        $statement->bindValue(':limit', max(1, min(20, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listFor(string $id): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                CASE WHEN ps.producto_a_id = :id THEN ps.producto_b_id
                     ELSE ps.producto_a_id END AS id_producto,
                p.descripcion,
                p.sku,
                ps.creado_en
             FROM producto_similares ps
             INNER JOIN productos p
               ON p.id_producto = CASE
                    WHEN ps.producto_a_id = :id_join THEN ps.producto_b_id
                    ELSE ps.producto_a_id END
             WHERE (ps.producto_a_id = :id_a OR ps.producto_b_id = :id_b)
               AND ps.eliminado_en IS NULL
               AND p.activo = 1 AND p.eliminado_en IS NULL
             ORDER BY p.descripcion, p.id_producto'
        );
        $statement->execute([
            'id' => $id,
            'id_join' => $id,
            'id_a' => $id,
            'id_b' => $id,
        ]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function relation(string $a, string $b): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT producto_a_id, producto_b_id, eliminado_en
             FROM producto_similares
             WHERE producto_a_id = :a AND producto_b_id = :b
             LIMIT 1'
        );
        $statement->execute(['a' => $a, 'b' => $b]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function insert(string $a, string $b, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO producto_similares
                (producto_a_id, producto_b_id, creado_por)
             VALUES (:a, :b, :actor)'
        );
        $statement->execute(['a' => $a, 'b' => $b, 'actor' => $actorId]);
    }

    public function reactivate(string $a, string $b, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE producto_similares
             SET eliminado_en = NULL, eliminado_por = NULL,
                 actualizado_en = CURRENT_TIMESTAMP,
                 actualizado_por = :actor
             WHERE producto_a_id = :a AND producto_b_id = :b'
        );
        $statement->execute(['a' => $a, 'b' => $b, 'actor' => $actorId]);
    }

    public function softDelete(string $a, string $b, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE producto_similares
             SET eliminado_en = CURRENT_TIMESTAMP, eliminado_por = :actor,
                 actualizado_en = CURRENT_TIMESTAMP,
                 actualizado_por = :actor_update
             WHERE producto_a_id = :a AND producto_b_id = :b
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'a' => $a,
            'b' => $b,
            'actor' => $actorId,
            'actor_update' => $actorId,
        ]);
    }
}
