<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class InventoryRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
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

    public function activeUserExists(int $userId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM usuarios
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @return array{id: int}|null
     */
    public function activeCompany(int $companyId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id
             FROM empresas
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $companyId]);
        $row = $statement->fetch();

        return is_array($row) ? ['id' => (int) $row['id']] : null;
    }

    /**
     * @return array{id: int, empresa_id: int}|null
     */
    public function activeWarehouseForCompany(
        int $companyId,
        int $warehouseId
    ): ?array {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, empresa_id
             FROM almacenes
             WHERE id = :id
               AND empresa_id = :empresa_id
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'id' => $warehouseId,
            'empresa_id' => $companyId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'empresa_id' => (int) $row['empresa_id'],
        ] : null;
    }

    /**
     * @return array{id: int, codigo: string, naturaleza: string}|null
     */
    public function activeConceptByCode(string $code): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, codigo, naturaleza
             FROM conceptos_movimiento_inventario
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $row = $statement->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'codigo' => (string) $row['codigo'],
            'naturaleza' => (string) $row['naturaleza'],
        ] : null;
    }

    /**
     * @param list<string> $productIds
     * @return array<string, array{id_producto: string, tipo_codigo: string, activo: int, controla_series: int}>
     */
    public function activeProductsByIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                p.id_producto,
                p.activo,
                p.controla_series,
                tp.codigo AS tipo_codigo
             FROM productos p
             INNER JOIN tipos_producto tp ON tp.id = p.tipo_producto_id
             WHERE p.id_producto IN (' . $placeholders . ')
               AND p.eliminado_en IS NULL
               AND tp.activo = 1
               AND tp.eliminado_en IS NULL'
        );
        $statement->execute($productIds);
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['id_producto']] = [
                'id_producto' => (string) $row['id_producto'],
                'tipo_codigo' => (string) $row['tipo_codigo'],
                'activo' => (int) $row['activo'],
                'controla_series' => (int) $row['controla_series'],
            ];
        }

        return $rows;
    }

    public function createDraftMovement(
        int $companyId,
        int $warehouseId,
        int $conceptId,
        string $movementDate,
        ?string $reference,
        ?string $notes,
        int $actorId,
        ?int $folioId = null,
        ?string $folio = null
    ): int {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO movimientos_inventario (
                empresa_id,
                almacen_id,
                concepto_movimiento_id,
                fecha_movimiento,
                estado,
                folio_id,
                folio,
                referencia,
                observaciones,
                creado_por
             )
             VALUES (
                :empresa_id,
                :almacen_id,
                :concepto_movimiento_id,
                :fecha_movimiento,
                \'BORRADOR\',
                :folio_id,
                :folio,
                :referencia,
                :observaciones,
                :creado_por
             )'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'concepto_movimiento_id' => $conceptId,
            'fecha_movimiento' => $movementDate,
            'folio_id' => $folioId,
            'folio' => $folio,
            'referencia' => $reference,
            'observaciones' => $notes,
            'creado_por' => $actorId,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function insertMovementDetail(
        int $movementId,
        string $productId,
        string $quantity,
        ?string $notes,
        int $actorId
    ): int {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO movimientos_inventario_detalle (
                movimiento_id,
                id_producto,
                cantidad,
                observaciones,
                creado_por
             )
             VALUES (
                :movimiento_id,
                :id_producto,
                :cantidad,
                :observaciones,
                :creado_por
             )'
        );
        $statement->execute([
            'movimiento_id' => $movementId,
            'id_producto' => $productId,
            'cantidad' => $quantity,
            'observaciones' => $notes,
            'creado_por' => $actorId,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param list<string> $seriesNumbers
     * @return array<string, array{id: int, id_producto: string, numero_serie: string, activo: int}>
     */
    public function activeSeriesByNumbers(
        string $productId,
        array $seriesNumbers
    ): array {
        if ($seriesNumbers === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($seriesNumbers), '?'));
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, id_producto, numero_serie, activo
             FROM producto_series
             WHERE id_producto = ?
               AND numero_serie IN (' . $placeholders . ')
               AND eliminado_en IS NULL'
        );
        $statement->execute(array_merge([$productId], $seriesNumbers));
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['numero_serie']] = [
                'id' => (int) $row['id'],
                'id_producto' => (string) $row['id_producto'],
                'numero_serie' => (string) $row['numero_serie'],
                'activo' => (int) $row['activo'],
            ];
        }

        return $rows;
    }

    public function createSeries(
        string $productId,
        string $seriesNumber
    ): int {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO producto_series (
                id_producto,
                numero_serie
             )
             VALUES (
                :id_producto,
                :numero_serie
             )'
        );
        $statement->execute([
            'id_producto' => $productId,
            'numero_serie' => $seriesNumber,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param list<int> $seriesIds
     * @return array<int, array{serie_id: int, almacen_id: int|null, estado: string}>
     */
    public function lockSeriesStocks(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        $seriesIds = array_values(array_unique(array_map('intval', $seriesIds)));
        sort($seriesIds);
        $placeholders = implode(', ', array_fill(0, count($seriesIds), '?'));
        $statement = $this->connection->pdo()->prepare(
            'SELECT serie_id, almacen_id, estado
             FROM existencias_serie
             WHERE serie_id IN (' . $placeholders . ')
             ORDER BY serie_id
             FOR UPDATE'
        );
        $statement->execute($seriesIds);
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[(int) $row['serie_id']] = [
                'serie_id' => (int) $row['serie_id'],
                'almacen_id' => $row['almacen_id'] === null
                    ? null
                    : (int) $row['almacen_id'],
                'estado' => (string) $row['estado'],
            ];
        }

        return $rows;
    }

    public function saveSeriesStock(
        int $seriesId,
        ?int $warehouseId,
        string $state
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO existencias_serie (
                serie_id,
                almacen_id,
                estado
             )
             VALUES (
                :serie_id,
                :almacen_id,
                :estado
             )
             ON DUPLICATE KEY UPDATE
                almacen_id = VALUES(almacen_id),
                estado = VALUES(estado)'
        );
        $statement->execute([
            'serie_id' => $seriesId,
            'almacen_id' => $warehouseId,
            'estado' => $state,
        ]);
    }

    public function insertMovementDetailSeries(
        int $movementDetailId,
        int $seriesId
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO movimiento_detalle_series (
                movimiento_detalle_id,
                serie_id
             )
             VALUES (
                :movimiento_detalle_id,
                :serie_id
             )'
        );
        $statement->execute([
            'movimiento_detalle_id' => $movementDetailId,
            'serie_id' => $seriesId,
        ]);
    }

    public function ensureExistenceRow(int $warehouseId, string $productId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO existencias_producto (
                almacen_id,
                id_producto,
                cantidad_actual
             )
             VALUES (
                :almacen_id,
                :id_producto,
                0.000000
             )
             ON DUPLICATE KEY UPDATE
                id_producto = id_producto'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
        ]);
    }

    /**
     * @return array{id: int, cantidad_actual: string}|null
     */
    public function lockExistence(int $warehouseId, string $productId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, cantidad_actual
             FROM existencias_producto
             WHERE almacen_id = :almacen_id
               AND id_producto = :id_producto
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'cantidad_actual' => (string) $row['cantidad_actual'],
        ] : null;
    }

    public function increaseExistence(
        int $warehouseId,
        string $productId,
        string $quantity
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE existencias_producto
             SET cantidad_actual = cantidad_actual + :cantidad
             WHERE almacen_id = :almacen_id
               AND id_producto = :id_producto'
        );
        $statement->execute([
            'cantidad' => $quantity,
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
        ]);
    }

    public function decreaseExistence(
        int $warehouseId,
        string $productId,
        string $quantity
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE existencias_producto
             SET cantidad_actual = cantidad_actual - :cantidad
             WHERE almacen_id = :almacen_id
               AND id_producto = :id_producto'
        );
        $statement->execute([
            'cantidad' => $quantity,
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
        ]);
    }

    public function markMovementApplied(int $movementId, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE movimientos_inventario
             SET estado = \'APLICADO\',
                 aplicado_en = CURRENT_TIMESTAMP,
                 aplicado_por = :actor_id
             WHERE id = :id
               AND estado = \'BORRADOR\''
        );
        $statement->execute([
            'actor_id' => $actorId,
            'id' => $movementId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException(
                'Inventory movement could not be marked as applied.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function movementResult(int $movementId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                m.id,
                m.empresa_id,
                m.almacen_id,
                m.fecha_movimiento,
                m.estado,
                m.folio_id,
                m.folio,
                m.referencia,
                c.codigo AS concepto_codigo,
                c.naturaleza
             FROM movimientos_inventario m
             INNER JOIN conceptos_movimiento_inventario c
                ON c.id = m.concepto_movimiento_id
             WHERE m.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $movementId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new \RuntimeException('Applied inventory movement not found.');
        }

        return $row;
    }
}
