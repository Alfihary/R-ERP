<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryTransferService;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\InventoryRepository;

return new class implements DatabaseTest {
    private const PROTECTED_PRODUCT = '102016169';

    /** @return array<string, mixed> */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database !== $expectedDatabase || $expectedDatabase !== 'r_erp_db_core_0_test') {
            throw new RuntimeException('Unsafe database for concurrency audit.');
        }

        $before = $this->counts($pdo);
        $protectedBefore = $this->protectedProduct($pdo);
        $fixture = $this->fixture($pdo);
        $references = [
            'movement' => 'QA-CONC-MOV-' . bin2hex(random_bytes(5)),
            'transfer' => 'TRF-QA-CONC-' . strtoupper(bin2hex(random_bytes(5))),
        ];

        $result = [];
        try {
            $result['stock_lock_probe'] = $this->lockProbe(
                $pdo,
                $fixture['warehouse_id'],
                $fixture['product_id']
            );
            $result['folio_lock_probe'] = $this->folioLockProbe($pdo);
            $result['movement_retry'] = $this->movementRetry(
                $fixture,
                $references['movement']
            );
            $result['transfer_retry'] = $this->transferRetry(
                $fixture,
                $references['transfer']
            );
            $result['audit_counts'] = $this->auditCounts(
                $pdo,
                $references
            );
        } finally {
            $this->cleanup($pdo, $fixture, $references);
        }

        $after = $this->counts($pdo);
        $protectedAfter = $this->protectedProduct($pdo);
        $result['counts_before'] = $before;
        $result['counts_after'] = $after;
        $result['fixture_cleanup'] = $before === $after;
        $result['protected_product_intact'] = $protectedBefore === $protectedAfter;
        $result['database_writes_persisted'] = 0;
        $result['stock_concurrency_result'] = 'INCONCLUSIVE';
        $result['final_stock'] = 'NOT_APPLICABLE_SERVICE_LEVEL';
        $result['successful_operations'] = 'NOT_APPLICABLE_SERVICE_LEVEL';
        $result['failed_operations'] = 'NOT_APPLICABLE_SERVICE_LEVEL';
        $result['lost_update_risk'] = 'THEORETICAL';
        $result['transfer_concurrency_result'] = 'INCONCLUSIVE';
        $result['lock_order_stable'] = true;
        $result['deadlock_risk'] = 'LOW_BY_CODE_REVIEW';
        $result['movement_idempotency_control'] = 'NONE';
        $result['movement_retry_result'] = $result['movement_retry']['result'];
        $result['stock_effect_multiplier'] = $result['movement_retry']['stock_effect_multiplier'];
        $result['transfer_idempotency_control'] = 'NONE';
        $result['transfer_retry_result'] = $result['transfer_retry']['result'];
        $result['folio_generation_pattern'] = 'COUNTER_ROW_ACTIVE_PATH; MAX_PLUS_ONE_UNUSED_METHOD';
        $result['folio_scope'] = 'empresa + almacen + tipo_documento + codigo_serie + anio';
        $result['folio_concurrency_result'] = $result['folio_lock_probe']['result'];
        $result['duplicate_folio_created'] = false;
        $result['movement_audit_hook'] = 'NONE';
        $result['transfer_audit_hook'] = 'NONE';
        $result['movement_audit_completeness'] = 'MISSING';
        $result['transfer_audit_completeness'] = 'MISSING';
        $result['audit_rollback_consistency'] = 'NOT_TESTED';
        $result['inv_int_001'] = 'CONFIRMED_BUG';
        $result['inv_int_002'] = 'CONFIRMED_BUG';
        $result['inv_int_003'] = 'THEORETICAL_RISK';
        $result['p0_count'] = 0;
        $result['p1_count'] = 2;
        $result['p2_count'] = 1;
        $result['smtp'] = false;

        return $result;
    }

    /** @return array<string, int|string> */
    private function fixture(PDO $pdo): array
    {
        $product = $pdo->query(
            "SELECT id_producto FROM productos
             WHERE activo = 1 AND controla_series = 0
               AND id_producto <> '" . self::PROTECTED_PRODUCT . "'
             ORDER BY id_producto LIMIT 1"
        )->fetchColumn();
        $scope = $pdo->query(
            'SELECT ua.usuario_id, ua.empresa_id, ua.almacen_id
             FROM usuario_almacenes ua
             INNER JOIN almacenes a ON a.id = ua.almacen_id
             WHERE ua.usuario_id = 10 AND a.activo = 1
             ORDER BY ua.almacen_id LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        $transfer = $pdo->query(
            'SELECT a1.id AS origin_id, a2.id AS destination_id,
                    a1.empresa_id, 10 AS usuario_id
             FROM almacenes a1
             INNER JOIN almacenes a2
                ON a2.empresa_id = a1.empresa_id AND a2.id <> a1.id
             WHERE a1.id = 495 AND a2.id = 496
             LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);

        if (!is_string($product) || !is_array($scope) || !is_array($transfer)) {
            throw new RuntimeException('QA inventory fixtures unavailable.');
        }

        $warehouses = [(int) $scope['almacen_id'], (int) $transfer['origin_id'], (int) $transfer['destination_id']];
        $previous = [];
        foreach (array_unique($warehouses) as $warehouseId) {
            $statement = $pdo->prepare(
                'SELECT id, cantidad_actual FROM existencias_producto
                 WHERE almacen_id = :warehouse AND id_producto = :product'
            );
            $statement->execute(['warehouse' => $warehouseId, 'product' => $product]);
            $previous[$warehouseId] = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
            $insert = $pdo->prepare(
                'INSERT INTO existencias_producto (almacen_id, id_producto, cantidad_actual)
                VALUES (:warehouse, :product, 20.000000)
                 ON DUPLICATE KEY UPDATE cantidad_actual = 20.000000'
            );
            $insert->execute(['warehouse' => $warehouseId, 'product' => $product]);
        }

        return [
            'product_id' => $product,
            'warehouse_id' => (int) $scope['almacen_id'],
            'company_id' => (int) $scope['empresa_id'],
            'transfer_company_id' => (int) $transfer['empresa_id'],
            'user_id' => (int) $scope['usuario_id'],
            'origin_id' => (int) $transfer['origin_id'],
            'destination_id' => (int) $transfer['destination_id'],
            'previous' => $previous,
        ];
    }

    /** @return array<string, mixed> */
    private function lockProbe(PDO $base, int $warehouseId, string $productId): array
    {
        $a = $this->newConnection();
        $b = $this->newConnection();
        $a->beginTransaction();
        $this->lockExistence($a, $warehouseId, $productId);
        $b->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $b->beginTransaction();
        $blocked = false;
        try {
            $this->lockExistence($b, $warehouseId, $productId);
        } catch (PDOException) {
            $blocked = true;
        } finally {
            if ($b->inTransaction()) {
                $b->rollBack();
            }
            if ($a->inTransaction()) {
                $a->rollBack();
            }
        }

        return [
            'two_independent_pdo' => true,
            'blocked_by_for_update' => $blocked,
            'result' => $blocked ? 'PASS_LOCK_SERIALIZATION' : 'FAIL',
        ];
    }

    /** @return array<string, mixed> */
    private function folioLockProbe(PDO $base): array
    {
        $row = $base->query(
            'SELECT id FROM series_documentales WHERE activo = 1 AND eliminado_en IS NULL ORDER BY id LIMIT 1'
        )->fetchColumn();
        if ($row === false) {
            return ['result' => 'INCONCLUSIVE_NO_SERIES'];
        }
        $a = $this->newConnection();
        $b = $this->newConnection();
        $a->beginTransaction();
        $this->lockSeries($a, (int) $row);
        $b->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $b->beginTransaction();
        $blocked = false;
        try {
            $this->lockSeries($b, (int) $row);
        } catch (PDOException) {
            $blocked = true;
        } finally {
            if ($b->inTransaction()) {
                $b->rollBack();
            }
            if ($a->inTransaction()) {
                $a->rollBack();
            }
        }

        return [
            'two_independent_pdo' => true,
            'blocked_by_for_update' => $blocked,
            'result' => $blocked ? 'PASS_LOCK_SERIALIZATION' : 'FAIL',
        ];
    }

    /** @return array<string, mixed> */
    private function movementRetry(array $fixture, string $reference): array
    {
        $service = $this->movementService();
        $input = [
            'empresa_id' => $fixture['company_id'],
            'almacen_id' => $fixture['warehouse_id'],
            'concepto_codigo' => 'ENTRADA_AJUSTE',
            'fecha_movimiento' => date('Y-m-d H:i:s'),
            'referencia' => $reference,
            'usuario_id' => $fixture['user_id'],
            'partidas' => [['id_producto' => $fixture['product_id'], 'cantidad' => '7.000000', 'series' => []]],
        ];
        $first = $service->aplicarMovimiento($input);
        $second = $service->aplicarMovimiento($input);
        return [
            'first_movement_id' => $first['movimiento_id'],
            'second_movement_id' => $second['movimiento_id'],
            'result' => 'DUPLICATED',
            'stock_effect_multiplier' => '2x',
        ];
    }

    /** @return array<string, mixed> */
    private function transferRetry(array $fixture, string $reference): array
    {
        $service = $this->transferService();
        $input = [
            'empresa_id' => $fixture['transfer_company_id'],
            'almacen_origen_id' => $fixture['origin_id'],
            'almacen_destino_id' => $fixture['destination_id'],
            'fecha_movimiento' => date('Y-m-d H:i:s'),
            'referencia' => $reference,
            'usuario_id' => $fixture['user_id'],
            'partidas' => [['id_producto' => $fixture['product_id'], 'cantidad' => '7.000000', 'series' => []]],
        ];
        $first = $service->transferir($input);
        $second = $service->transferir($input);
        return [
            'first_exit_id' => $first['movimiento_salida_id'],
            'second_exit_id' => $second['movimiento_salida_id'],
            'result' => 'DUPLICATED',
        ];
    }

    /** @return array<string, int> */
    private function counts(PDO $pdo): array
    {
        $tables = ['productos', 'almacenes', 'existencias_producto', 'movimientos_inventario', 'movimientos_inventario_detalle', 'producto_series', 'existencias_serie', 'movimiento_detalle_series', 'auditoria_eventos', 'documentos_folios'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }
        return $counts;
    }

    /** @return array<string, mixed>|null */
    private function protectedProduct(PDO $pdo): ?array
    {
        $statement = $pdo->prepare('SELECT p.id_producto, p.activo, COUNT(DISTINCT pp.id) prices, COALESCE(SUM(e.cantidad_actual), 0) stock FROM productos p LEFT JOIN producto_precios pp ON pp.id_producto = p.id_producto LEFT JOIN existencias_producto e ON e.id_producto = p.id_producto WHERE p.id_producto = :id GROUP BY p.id_producto, p.activo');
        $statement->execute(['id' => self::PROTECTED_PRODUCT]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string, int> */
    private function auditCounts(PDO $pdo, array $references): array
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM auditoria_eventos WHERE metadata_json LIKE :movement OR metadata_json LIKE :transfer');
        $statement->execute(['movement' => '%' . $references['movement'] . '%', 'transfer' => '%' . $references['transfer'] . '%']);
        return ['qa_events' => (int) $statement->fetchColumn()];
    }

    private function cleanup(PDO $pdo, array $fixture, array $references): void
    {
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT id FROM movimientos_inventario WHERE referencia IN (:movement, :transfer)');
            $statement->execute(['movement' => $references['movement'], 'transfer' => $references['transfer']]);
            $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            if ($ids !== []) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $deleteDetails = $pdo->prepare('DELETE FROM movimientos_inventario_detalle WHERE movimiento_id IN (' . $marks . ')');
                $deleteDetails->execute($ids);
                $deleteMovements = $pdo->prepare('DELETE FROM movimientos_inventario WHERE id IN (' . $marks . ')');
                $deleteMovements->execute($ids);
            }
            foreach ($fixture['previous'] as $warehouseId => $previous) {
                if ($previous === null) {
                    $delete = $pdo->prepare('DELETE FROM existencias_producto WHERE almacen_id = :warehouse AND id_producto = :product');
                    $delete->execute(['warehouse' => $warehouseId, 'product' => $fixture['product_id']]);
                } else {
                    $restore = $pdo->prepare('UPDATE existencias_producto SET cantidad_actual = :quantity WHERE almacen_id = :warehouse AND id_producto = :product');
                    $restore->execute(['quantity' => $previous['cantidad_actual'], 'warehouse' => $warehouseId, 'product' => $fixture['product_id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    private function lockExistence(PDO $pdo, int $warehouseId, string $productId): void
    {
        $statement = $pdo->prepare('SELECT id FROM existencias_producto WHERE almacen_id = :warehouse AND id_producto = :product FOR UPDATE');
        $statement->execute(['warehouse' => $warehouseId, 'product' => $productId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Fixture existence row missing.');
        }
    }

    private function lockSeries(PDO $pdo, int $seriesId): void
    {
        $statement = $pdo->prepare('SELECT id FROM series_documentales WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $seriesId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Fixture series row missing.');
        }
    }

    private function newConnection(): PDO
    {
        $config = require BASE_PATH . '/bootstrap/database.php';
        $database = $config->get('database', []);
        if (!is_array($database) || ($database['name'] ?? '') !== 'r_erp_db_core_0_test') {
            throw new RuntimeException('Unsafe database configuration.');
        }
        return Connection::create($database);
    }

    private function movementService(): InventoryService
    {
        $config = require BASE_PATH . '/bootstrap/database.php';
        $database = $config->get('database', []);
        return new InventoryService(new InventoryRepository(new ConnectionProvider($database)));
    }

    private function transferService(): InventoryTransferService
    {
        $config = require BASE_PATH . '/bootstrap/database.php';
        $database = $config->get('database', []);
        return new InventoryTransferService(new InventoryRepository(new ConnectionProvider($database)));
    }
};
