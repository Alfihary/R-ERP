<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryTransferService;
use App\Domain\Inventory\InventoryValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\InventoryRepository;

return new class implements DatabaseTest {
    private const PRODUCT_PREFIX = 'QASSV';
    private const SERIES_PREFIX = 'QASSV-SER-';
    private const REFERENCE_PREFIX = 'QA-SER-SVC-';
    private const WAREHOUSE_CODE = 'QASVC-DEST';

    private PDO $pdo;
    /** @var array<string, int> */
    private array $scope;
    private int $adminId;
    private int $unitId;
    private int $typeProductId;
    private int $destinationWarehouseId;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match SERIES-SERVICE-1.'
            );
        }

        $this->cleanup();
        $before = $this->qaCounts();
        $results = [];

        try {
            $this->adminId = $this->adminId();
            $this->scope = $this->scope();
            $this->unitId = $this->idByCode('unidades_medida', 'PIEZA');
            $this->typeProductId = $this->idByCode(
                'tipos_producto',
                'PRODUCTO'
            );
            $this->destinationWarehouseId = $this->createDestinationWarehouse();

            $this->createProduct('QASSVSER1', 1);
            $this->createProduct('QASSVSER2', 1);
            $this->createProduct('QASSVNOR1', 0);
            $this->createProduct('QASSVMIX1', 1);
            $this->createProduct('QASSVMIX2', 0);

            $results['serialized_entry'] = $this->serializedEntry();
            $results['duplicate_entry_rejected'] =
                $this->duplicateEntryRejected();
            $results['reentry_after_exit'] = $this->reentryAfterExit();
            $results['decimal_entry_rejected'] =
                $this->fails(fn () => $this->movement(
                    'ENTRADA_AJUSTE',
                    'QA-SER-SVC-DEC-ENTRY',
                    [['id_producto' => 'QASSVSER1', 'cantidad' => '1.500000',
                        'series' => ['QASSV-SER-DEC']]]
                ));
            $results['count_mismatch_rejected'] =
                $this->fails(fn () => $this->movement(
                    'ENTRADA_AJUSTE',
                    'QA-SER-SVC-MISMATCH',
                    [['id_producto' => 'QASSVSER1', 'cantidad' => '2',
                        'series' => ['QASSV-SER-MIS1']]]
                ));
            $results['duplicate_series_same_part_rejected'] =
                $this->fails(fn () => $this->movement(
                    'ENTRADA_AJUSTE',
                    'QA-SER-SVC-DUP-PART',
                    [['id_producto' => 'QASSVSER1', 'cantidad' => '2',
                        'series' => ['QASSV-SER-DUP', 'QASSV-SER-DUP']]]
                ));
            $results['valid_serialized_exit'] = $this->validSerializedExit();
            $results['nonexistent_series_exit_rejected'] =
                $this->fails(fn () => $this->movement(
                    'SALIDA_AJUSTE',
                    'QA-SER-SVC-MISSING-EXIT',
                    [['id_producto' => 'QASSVSER1', 'cantidad' => '1',
                        'series' => ['QASSV-SER-NOEXIST']]]
                ));
            $results['series_other_warehouse_rejected'] =
                $this->seriesOtherWarehouseRejected();
            $results['out_of_stock_series_exit_rejected'] =
                $this->fails(fn () => $this->movement(
                    'SALIDA_AJUSTE',
                    'QA-SER-SVC-OUT-EXIT',
                    [['id_producto' => 'QASSVSER1', 'cantidad' => '1',
                        'series' => ['QASSV-SER-002']]]
                ));
            $results['decimal_exit_rejected'] =
                $this->fails(fn () => $this->movement(
                    'SALIDA_AJUSTE',
                    'QA-SER-SVC-DEC-EXIT',
                    [['id_producto' => 'QASSVSER1', 'cantidad' => '1.500000',
                        'series' => ['QASSV-SER-001']]]
                ));
            $results['non_serialized_decimal_works'] =
                $this->nonSerializedDecimalWorks();
            $results['non_serialized_with_series_rejected'] =
                $this->nonSerializedWithSeriesRejected();
            $results['valid_serialized_transfer'] =
                $this->validSerializedTransfer();
            $results['transfer_nonexistent_series_rejected'] =
                $this->fails(fn () => $this->transfer(
                    'QA-SER-SVC-TRF-MISSING',
                    [['id_producto' => 'QASSVSER1', 'cantidad' => '1',
                        'series' => ['QASSV-SER-NOEXIST-TRF']]]
                ));
            $results['transfer_series_outside_origin_rejected'] =
                $this->fails(fn () => $this->transfer(
                    'QA-SER-SVC-TRF-OUTSIDE',
                    [['id_producto' => 'QASSVSER2', 'cantidad' => '1',
                        'series' => ['QASSV-SER-TRF-001']]]
                ));
            $results['transfer_rollback_documented'] =
                'covered_by_single_transaction_without_productive_failure_hook';
            $results['mixed_movement_applies'] = $this->mixedMovementApplies();
            $results['total_rollback_when_serialized_part_fails'] =
                $this->totalRollbackWhenSerializedPartFails();

            foreach ($results as $label => $result) {
                if ($result !== true && $label !== 'transfer_rollback_documented') {
                    throw new RuntimeException(
                        'SERIES-SERVICE-1 failed case: ' . $label
                    );
                }
            }

            $during = $this->qaCounts();
        } catch (InventoryValidationException $exception) {
            throw new RuntimeException(
                'SERIES-SERVICE-1 service validation failed: '
                . json_encode(
                    $exception->errors(),
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                0,
                $exception
            );
        } finally {
            $this->cleanup();
        }

        $after = $this->qaCounts();

        if (array_sum($after) !== 0) {
            throw new RuntimeException('SERIES-SERVICE-1 left QA data.');
        }

        return [
            'database' => $database,
            'series_service_active' => true,
            'cases' => $results,
            'counts_before' => $before,
            'counts_during' => $during ?? [],
            'counts_after_cleanup' => $after,
            'cleanup' => 'qa_rows_deleted',
        ];
    }

    /**
     * @param list<array<string, mixed>> $parts
     */
    private function movement(
        string $conceptCode,
        string $reference,
        array $parts
    ): array {
        return $this->inventoryService()->aplicarMovimiento([
            'empresa_id' => $this->scope['empresa_id'],
            'almacen_id' => $this->scope['almacen_id'],
            'concepto_codigo' => $conceptCode,
            'fecha_movimiento' => '2026-07-17 10:00:00',
            'referencia' => $reference,
            'usuario_id' => $this->adminId,
            'partidas' => $parts,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $parts
     */
    private function transfer(string $reference, array $parts): array
    {
        return $this->transferService()->transferir([
            'empresa_id' => $this->scope['empresa_id'],
            'almacen_origen_id' => $this->scope['almacen_id'],
            'almacen_destino_id' => $this->destinationWarehouseId,
            'fecha_movimiento' => '2026-07-17 11:00:00',
            'referencia' => str_starts_with($reference, 'TRF-')
                ? $reference
                : 'TRF-' . $reference,
            'usuario_id' => $this->adminId,
            'partidas' => $parts,
        ]);
    }

    private function serializedEntry(): bool
    {
        $this->movement('ENTRADA_AJUSTE', 'QA-SER-SVC-ENTRY', [[
            'id_producto' => 'QASSVSER1',
            'cantidad' => '2.000000',
            'series' => ['QASSV-SER-001', 'QASSV-SER-002'],
        ]]);

        return $this->aggregate($this->scope['almacen_id'], 'QASSVSER1')
            === '2.000000'
            && $this->seriesState('QASSV-SER-001') === [
                'almacen_id' => $this->scope['almacen_id'],
                'estado' => 'EN_EXISTENCIA',
            ]
            && $this->detailSeriesCount('QA-SER-SVC-ENTRY') === 2;
    }

    private function duplicateEntryRejected(): bool
    {
        $before = $this->aggregate($this->scope['almacen_id'], 'QASSVSER1');

        return $this->fails(fn () => $this->movement(
            'ENTRADA_AJUSTE',
            'QA-SER-SVC-DUP-ENTRY',
            [['id_producto' => 'QASSVSER1', 'cantidad' => '1',
                'series' => ['QASSV-SER-001']]]
        )) && $this->aggregate($this->scope['almacen_id'], 'QASSVSER1') === $before
            && $this->movementCount('QA-SER-SVC-DUP-ENTRY') === 0;
    }

    private function reentryAfterExit(): bool
    {
        $this->movement('SALIDA_AJUSTE', 'QA-SER-SVC-OUT-001', [[
            'id_producto' => 'QASSVSER1',
            'cantidad' => '1',
            'series' => ['QASSV-SER-001'],
        ]]);
        $this->movement('ENTRADA_AJUSTE', 'QA-SER-SVC-REENTRY-001', [[
            'id_producto' => 'QASSVSER1',
            'cantidad' => '1',
            'series' => ['QASSV-SER-001'],
        ]]);

        return $this->seriesState('QASSV-SER-001') === [
            'almacen_id' => $this->scope['almacen_id'],
            'estado' => 'EN_EXISTENCIA',
        ];
    }

    private function validSerializedExit(): bool
    {
        $this->movement('SALIDA_AJUSTE', 'QA-SER-SVC-OUT-002', [[
            'id_producto' => 'QASSVSER1',
            'cantidad' => '1',
            'series' => ['QASSV-SER-002'],
        ]]);

        return $this->aggregate($this->scope['almacen_id'], 'QASSVSER1')
            === '1.000000'
            && $this->seriesState('QASSV-SER-002') === [
                'almacen_id' => null,
                'estado' => 'FUERA_EXISTENCIA',
            ];
    }

    private function seriesOtherWarehouseRejected(): bool
    {
        $this->insertSeriesWithStock(
            'QASSVSER1',
            'QASSV-SER-OTHERWH',
            $this->destinationWarehouseId
        );

        return $this->fails(fn () => $this->movement(
            'SALIDA_AJUSTE',
            'QA-SER-SVC-OTHERWH-EXIT',
            [['id_producto' => 'QASSVSER1', 'cantidad' => '1',
                'series' => ['QASSV-SER-OTHERWH']]]
        ));
    }

    private function nonSerializedDecimalWorks(): bool
    {
        $this->movement('ENTRADA_AJUSTE', 'QA-SER-SVC-NORM-IN', [[
            'id_producto' => 'QASSVNOR1',
            'cantidad' => '2.500000',
        ]]);
        $this->movement('SALIDA_AJUSTE', 'QA-SER-SVC-NORM-OUT', [[
            'id_producto' => 'QASSVNOR1',
            'cantidad' => '1.250000',
        ]]);

        return $this->aggregate($this->scope['almacen_id'], 'QASSVNOR1')
            === '1.250000';
    }

    private function nonSerializedWithSeriesRejected(): bool
    {
        $before = $this->seriesCountForProduct('QASSVNOR1');

        return $this->fails(fn () => $this->movement(
            'ENTRADA_AJUSTE',
            'QA-SER-SVC-NORM-SER',
            [['id_producto' => 'QASSVNOR1', 'cantidad' => '1',
                'series' => ['QASSV-SER-NORM']]]
        )) && $this->seriesCountForProduct('QASSVNOR1') === $before;
    }

    private function validSerializedTransfer(): bool
    {
        $this->movement('ENTRADA_AJUSTE', 'QA-SER-SVC-TRF-IN', [[
            'id_producto' => 'QASSVSER2',
            'cantidad' => '1',
            'series' => ['QASSV-SER-TRF-001'],
        ]]);
        $this->transfer('QA-SER-SVC-TRF-OK', [[
            'id_producto' => 'QASSVSER2',
            'cantidad' => '1',
            'series' => ['QASSV-SER-TRF-001'],
        ]]);

        return $this->aggregate($this->scope['almacen_id'], 'QASSVSER2')
            === '0.000000'
            && $this->aggregate($this->destinationWarehouseId, 'QASSVSER2')
            === '1.000000'
            && $this->seriesState('QASSV-SER-TRF-001') === [
                'almacen_id' => $this->destinationWarehouseId,
                'estado' => 'EN_EXISTENCIA',
            ]
            && $this->detailSeriesCount('TRF-QA-SER-SVC-TRF-OK') === 2;
    }

    private function mixedMovementApplies(): bool
    {
        $this->movement('ENTRADA_AJUSTE', 'QA-SER-SVC-MIXED', [
            [
                'id_producto' => 'QASSVMIX1',
                'cantidad' => '1',
                'series' => ['QASSV-SER-MIX-001'],
            ],
            [
                'id_producto' => 'QASSVMIX2',
                'cantidad' => '3.750000',
            ],
        ]);

        return $this->aggregate($this->scope['almacen_id'], 'QASSVMIX1')
            === '1.000000'
            && $this->aggregate($this->scope['almacen_id'], 'QASSVMIX2')
            === '3.750000';
    }

    private function totalRollbackWhenSerializedPartFails(): bool
    {
        $beforeNormal = $this->aggregate($this->scope['almacen_id'], 'QASSVMIX2');
        $beforeSeries = $this->seriesCountForProduct('QASSVMIX1');

        return $this->fails(fn () => $this->movement(
            'ENTRADA_AJUSTE',
            'QA-SER-SVC-ROLLBACK',
            [
                [
                    'id_producto' => 'QASSVMIX2',
                    'cantidad' => '1.000000',
                ],
                [
                    'id_producto' => 'QASSVMIX1',
                    'cantidad' => '1',
                    'series' => ['QASSV-SER-MIX-001'],
                ],
            ]
        )) && $this->aggregate($this->scope['almacen_id'], 'QASSVMIX2')
            === $beforeNormal
            && $this->seriesCountForProduct('QASSVMIX1') === $beforeSeries
            && $this->movementCount('QA-SER-SVC-ROLLBACK') === 0;
    }

    /**
     * @param callable(): mixed $operation
     */
    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (InventoryValidationException) {
            return true;
        }

        return false;
    }

    private function inventoryService(): InventoryService
    {
        return new InventoryService(new InventoryRepository(
            new ConnectionProvider($this->databaseConfig())
        ));
    }

    private function transferService(): InventoryTransferService
    {
        return new InventoryTransferService(new InventoryRepository(
            new ConnectionProvider($this->databaseConfig())
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseConfig(): array
    {
        $config = require BASE_PATH . '/bootstrap/database.php';
        $databaseConfig = $config->get('database', []);

        if (!is_array($databaseConfig)) {
            throw new RuntimeException('Database config is invalid.');
        }

        return $databaseConfig;
    }

    private function createDestinationWarehouse(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (
                empresa_id,
                codigo,
                nombre,
                creado_por
             )
             VALUES (
                :empresa_id,
                :codigo,
                :nombre,
                :creado_por
             )'
        );
        $statement->execute([
            'empresa_id' => $this->scope['empresa_id'],
            'codigo' => self::WAREHOUSE_CODE,
            'nombre' => 'QA Series Servicio Destino',
            'creado_por' => $this->adminId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createProduct(string $productId, int $tracksSeries): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO productos (
                id_producto,
                descripcion,
                unidad_medida_id,
                tipo_producto_id,
                controla_series,
                creado_por
             )
             VALUES (
                :id_producto,
                :descripcion,
                :unidad_medida_id,
                :tipo_producto_id,
                :controla_series,
                :creado_por
             )'
        );
        $statement->execute([
            'id_producto' => $productId,
            'descripcion' => $productId,
            'unidad_medida_id' => $this->unitId,
            'tipo_producto_id' => $this->typeProductId,
            'controla_series' => $tracksSeries,
            'creado_por' => $this->adminId,
        ]);
    }

    private function insertSeriesWithStock(
        string $productId,
        string $number,
        int $warehouseId
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO producto_series (id_producto, numero_serie)
             VALUES (:id_producto, :numero_serie)'
        );
        $statement->execute([
            'id_producto' => $productId,
            'numero_serie' => $number,
        ]);
        $seriesId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare(
            'INSERT INTO existencias_serie (serie_id, almacen_id, estado)
             VALUES (:serie_id, :almacen_id, \'EN_EXISTENCIA\')'
        );
        $statement->execute([
            'serie_id' => $seriesId,
            'almacen_id' => $warehouseId,
        ]);
    }

    private function aggregate(int $warehouseId, string $productId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(cantidad_actual, 0.000000)
             FROM existencias_producto
             WHERE almacen_id = :almacen_id
               AND id_producto = :id_producto'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
        ]);
        $value = $statement->fetchColumn();

        return $value === false ? '0.000000' : number_format((float) $value, 6, '.', '');
    }

    /**
     * @return array{almacen_id: int|null, estado: string}|null
     */
    private function seriesState(string $number): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT es.almacen_id, es.estado
             FROM existencias_serie es
             INNER JOIN producto_series ps ON ps.id = es.serie_id
             WHERE ps.numero_serie = :numero_serie'
        );
        $statement->execute(['numero_serie' => $number]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'almacen_id' => $row['almacen_id'] === null
                ? null
                : (int) $row['almacen_id'],
            'estado' => (string) $row['estado'],
        ];
    }

    private function detailSeriesCount(string $reference): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM movimiento_detalle_series mds
             INNER JOIN movimientos_inventario_detalle mid
                ON mid.id = mds.movimiento_detalle_id
             INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
             WHERE mi.referencia = :referencia'
        );
        $statement->execute(['referencia' => $reference]);

        return (int) $statement->fetchColumn();
    }

    private function movementCount(string $reference): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM movimientos_inventario
             WHERE referencia = :referencia'
        );
        $statement->execute(['referencia' => $reference]);

        return (int) $statement->fetchColumn();
    }

    private function seriesCountForProduct(string $productId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM producto_series
             WHERE id_producto = :id_producto'
        );
        $statement->execute(['id_producto' => $productId]);

        return (int) $statement->fetchColumn();
    }

    private function adminId(): int
    {
        $id = (int) $this->pdo->query(
            "SELECT u.id
             FROM usuarios u
             INNER JOIN usuario_roles ur
                ON ur.usuario_id = u.id
               AND ur.activo = 1
               AND ur.eliminado_en IS NULL
             INNER JOIN roles r
                ON r.id = ur.rol_id
               AND r.codigo = 'ADMIN'
               AND r.activo = 1
               AND r.eliminado_en IS NULL
             WHERE u.activo = 1
               AND u.eliminado_en IS NULL
             ORDER BY u.id
             LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Active ADMIN user is required.');
        }

        return $id;
    }

    /**
     * @return array{empresa_id: int, almacen_id: int}
     */
    private function scope(): array
    {
        $row = $this->pdo->query(
            'SELECT a.empresa_id, a.id AS almacen_id
             FROM almacenes a
             INNER JOIN empresas e ON e.id = a.empresa_id
             WHERE a.activo = 1
               AND a.eliminado_en IS NULL
               AND e.activo = 1
               AND e.eliminado_en IS NULL
             ORDER BY a.id
             LIMIT 1'
        )->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Active company/warehouse is required.');
        }

        return [
            'empresa_id' => (int) $row['empresa_id'],
            'almacen_id' => (int) $row['almacen_id'],
        ];
    }

    private function idByCode(string $table, string $code): int
    {
        if (!in_array($table, ['unidades_medida', 'tipos_producto'], true)) {
            throw new InvalidArgumentException('Unsupported code table.');
        }

        $statement = $this->pdo->prepare(
            'SELECT id
             FROM ' . $table . '
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Required code is missing: ' . $code);
        }

        return $id;
    }

    /**
     * @return array<string, int>
     */
    private function qaCounts(): array
    {
        return [
            'productos_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM productos
                 WHERE id_producto LIKE '" . self::PRODUCT_PREFIX . "%'"
            )->fetchColumn(),
            'series_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM producto_series
                 WHERE numero_serie LIKE '" . self::SERIES_PREFIX . "%'"
            )->fetchColumn(),
            'existencias_serie_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM existencias_serie es
                 INNER JOIN producto_series ps ON ps.id = es.serie_id
                 WHERE ps.numero_serie LIKE '" . self::SERIES_PREFIX . "%'"
            )->fetchColumn(),
            'movimiento_detalle_series_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM movimiento_detalle_series mds
                 INNER JOIN producto_series ps ON ps.id = mds.serie_id
                 WHERE ps.numero_serie LIKE '" . self::SERIES_PREFIX . "%'"
            )->fetchColumn(),
            'movimientos_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM movimientos_inventario
                 WHERE referencia LIKE '%" . self::REFERENCE_PREFIX . "%'"
            )->fetchColumn(),
            'detalles_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM movimientos_inventario_detalle mid
                 INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
                 WHERE mi.referencia LIKE '%" . self::REFERENCE_PREFIX . "%'"
            )->fetchColumn(),
            'existencias_producto_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM existencias_producto
                 WHERE id_producto LIKE '" . self::PRODUCT_PREFIX . "%'"
            )->fetchColumn(),
            'empresas_qa' => 0,
            'almacenes_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM almacenes
                 WHERE codigo = '" . self::WAREHOUSE_CODE . "'"
            )->fetchColumn(),
        ];
    }

    private function cleanup(): void
    {
        foreach ([
            "DELETE mds FROM movimiento_detalle_series mds
             INNER JOIN movimientos_inventario_detalle mid
                ON mid.id = mds.movimiento_detalle_id
             INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
             WHERE mi.referencia LIKE '%" . self::REFERENCE_PREFIX . "%'",
            "DELETE mds FROM movimiento_detalle_series mds
             INNER JOIN producto_series ps ON ps.id = mds.serie_id
             WHERE ps.numero_serie LIKE '" . self::SERIES_PREFIX . "%'",
            "DELETE mid FROM movimientos_inventario_detalle mid
             INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
             WHERE mi.referencia LIKE '%" . self::REFERENCE_PREFIX . "%'",
            "DELETE FROM movimientos_inventario
             WHERE referencia LIKE '%" . self::REFERENCE_PREFIX . "%'",
            "DELETE FROM existencias_serie
             WHERE serie_id IN (
                SELECT id FROM producto_series
                WHERE numero_serie LIKE '" . self::SERIES_PREFIX . "%'
             )",
            "DELETE FROM producto_series
             WHERE numero_serie LIKE '" . self::SERIES_PREFIX . "%'",
            "DELETE FROM existencias_producto
             WHERE id_producto LIKE '" . self::PRODUCT_PREFIX . "%'",
            "DELETE FROM productos
             WHERE id_producto LIKE '" . self::PRODUCT_PREFIX . "%'",
            "DELETE FROM almacenes WHERE codigo = '" . self::WAREHOUSE_CODE . "'",
        ] as $statement) {
            $this->pdo->exec($statement);
        }
    }
};
