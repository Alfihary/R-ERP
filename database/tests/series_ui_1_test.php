<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryTransferService;
use App\Domain\Inventory\InventoryValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Infrastructure\Repositories\InventoryRepository;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryTransferController;

return new class implements DatabaseTest {
    private const PRODUCT_PREFIX = 'QASUI';
    private const SERIES_PREFIX = 'QASUI-SER-';
    private const REFERENCE_PREFIX = 'QA-SER-UI-';
    private const TRANSFER_REFERENCE_PREFIX = 'TRF-QA-SER-UI-';
    private const WAREHOUSE_CODE = 'QASUI-DEST';

    private PDO $pdo;
    /** @var array{empresa_id: int, almacen_id: int} */
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
                'The active database does not match SERIES-UI-1.'
            );
        }

        $this->cleanup();
        $before = $this->qaCounts();
        $results = [];

        try {
            $this->adminId = $this->adminId();
            $this->scope = $this->scope();
            $this->unitId = $this->idByCode('unidades_medida', 'PIEZA');
            $this->typeProductId = $this->idByCode('tipos_producto', 'PRODUCTO');
            $this->destinationWarehouseId = $this->createDestinationWarehouse();

            $this->createProduct('QASUISER1', 1);
            $this->createProduct('QASUISER2', 1);
            $this->createProduct('QASUINOR1', 0);

            $results['movement_post_parsing'] = $this->movementPostParsing();
            $results['transfer_post_parsing'] = $this->transferPostParsing();
            $results['product_search_exposes_series_flag'] =
                $this->productSearchExposesSeriesFlag();
            $results['movement_entry_serialized'] =
                $this->movementEntrySerialized();
            $results['movement_exit_serialized'] =
                $this->movementExitSerialized();
            $results['transfer_serialized'] = $this->transferSerialized();
            $results['non_serialized_without_series'] =
                $this->nonSerializedWithoutSeries();
            $results['non_serialized_with_series_rejected'] =
                $this->fails(fn () => $this->movement(
                    'ENTRADA_AJUSTE',
                    'QA-SER-UI-NON-SERIES',
                    [[
                        'id_producto' => 'QASUINOR1',
                        'cantidad' => '1.000000',
                        'series' => ['QASUI-SER-NON'],
                    ]]
                ));
            $results['serialized_without_series_rejected'] =
                $this->fails(fn () => $this->movement(
                    'ENTRADA_AJUSTE',
                    'QA-SER-UI-NO-SERIES',
                    [[
                        'id_producto' => 'QASUISER1',
                        'cantidad' => '1.000000',
                        'series' => [],
                    ]]
                ));
            $results['serialized_decimal_rejected'] =
                $this->fails(fn () => $this->movement(
                    'ENTRADA_AJUSTE',
                    'QA-SER-UI-DECIMAL',
                    [[
                        'id_producto' => 'QASUISER1',
                        'cantidad' => '1.500000',
                        'series' => ['QASUI-SER-DECIMAL'],
                    ]]
                ));

            foreach ($results as $label => $result) {
                if ($result !== true) {
                    throw new RuntimeException(
                        'SERIES-UI-1 failed case: ' . $label
                    );
                }
            }

            $during = $this->qaCounts();
        } finally {
            $this->cleanup();
        }

        $after = $this->qaCounts();

        if (array_sum($after) !== 0) {
            throw new RuntimeException('SERIES-UI-1 left QA data.');
        }

        return [
            'database' => $database,
            'ui_contract_active' => true,
            'manual_series_capture' => true,
            'series_search_endpoint_created' => false,
            'cases' => $results,
            'counts_before' => $before,
            'counts_during' => $during ?? [],
            'counts_after_cleanup' => $after,
            'cleanup' => 'qa_rows_deleted',
        ];
    }

    private function movementPostParsing(): bool
    {
        $parts = $this->invokePrivateParts(
            InventoryController::class,
            [
                'partidas' => [
                    [
                        'id_producto' => 'QASUISER1',
                        'cantidad' => '2',
                        'observaciones' => 'QA',
                        'series_text' => " QASUI-SER-PARSE-1 \n\nQASUI-SER-PARSE-2",
                    ],
                ],
            ]
        );

        return ($parts[0]['series'] ?? null) === [
            'QASUI-SER-PARSE-1',
            'QASUI-SER-PARSE-2',
        ];
    }

    private function transferPostParsing(): bool
    {
        $parts = $this->invokePrivateParts(
            InventoryTransferController::class,
            [
                'partidas' => [
                    [
                        'id_producto' => 'QASUISER1',
                        'cantidad' => '1',
                        'observaciones' => 'QA',
                        'series_text' => "QASUI-SER-TRF-PARSE",
                    ],
                ],
            ]
        );

        return ($parts[0]['series'] ?? null) === ['QASUI-SER-TRF-PARSE'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invokePrivateParts(string $controllerClass, array $input): array
    {
        $reflection = new ReflectionClass($controllerClass);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('parts');
        $method->setAccessible(true);
        $parts = $method->invoke($controller, $input);

        if (!is_array($parts)) {
            throw new RuntimeException('Controller parts parser returned invalid data.');
        }

        return $parts;
    }

    private function productSearchExposesSeriesFlag(): bool
    {
        $rows = $this->queries()->searchProducts('QASUI');
        $flags = [];

        foreach ($rows as $row) {
            $flags[(string) $row['id_producto']] =
                (int) ($row['controla_series'] ?? -1);
        }

        return ($flags['QASUISER1'] ?? null) === 1
            && ($flags['QASUINOR1'] ?? null) === 0;
    }

    private function movementEntrySerialized(): bool
    {
        $result = $this->movement('ENTRADA_AJUSTE', 'QA-SER-UI-ENTRY', [[
            'id_producto' => 'QASUISER1',
            'cantidad' => '2',
            'series' => ['QASUI-SER-001', 'QASUI-SER-002'],
        ]]);
        $movement = $this->queries()->movement(
            (int) $result['movimiento_id'],
            $this->scope['empresa_id'],
            $this->scope['almacen_id']
        );
        $series = is_array($movement['partidas'][0]['series'] ?? null)
            ? $movement['partidas'][0]['series']
            : [];

        return $series === ['QASUI-SER-001', 'QASUI-SER-002']
            && $this->seriesState('QASUI-SER-001') === [
                'almacen_id' => $this->scope['almacen_id'],
                'estado' => 'EN_EXISTENCIA',
            ];
    }

    private function movementExitSerialized(): bool
    {
        $this->movement('SALIDA_AJUSTE', 'QA-SER-UI-EXIT', [[
            'id_producto' => 'QASUISER1',
            'cantidad' => '1',
            'series' => ['QASUI-SER-002'],
        ]]);

        return $this->seriesState('QASUI-SER-002') === [
            'almacen_id' => null,
            'estado' => 'FUERA_EXISTENCIA',
        ];
    }

    private function transferSerialized(): bool
    {
        $this->movement('ENTRADA_AJUSTE', 'QA-SER-UI-TRF-IN', [[
            'id_producto' => 'QASUISER2',
            'cantidad' => '1',
            'series' => ['QASUI-SER-TRF-001'],
        ]]);
        $result = $this->transfer('TRF-QA-SER-UI-OK', [[
            'id_producto' => 'QASUISER2',
            'cantidad' => '1',
            'series' => ['QASUI-SER-TRF-001'],
        ]]);
        $transfer = $this->queries()->transfer(
            (string) $result['referencia_transferencia'],
            $this->scope['empresa_id']
        );
        $part = is_array($transfer['partidas'][0] ?? null)
            ? $transfer['partidas'][0]
            : [];

        return ($part['series_salida'] ?? null) === ['QASUI-SER-TRF-001']
            && ($part['series_entrada'] ?? null) === ['QASUI-SER-TRF-001']
            && $this->seriesState('QASUI-SER-TRF-001') === [
                'almacen_id' => $this->destinationWarehouseId,
                'estado' => 'EN_EXISTENCIA',
            ];
    }

    private function nonSerializedWithoutSeries(): bool
    {
        $this->movement('ENTRADA_AJUSTE', 'QA-SER-UI-NON-OK', [[
            'id_producto' => 'QASUINOR1',
            'cantidad' => '1.250000',
            'series' => [],
        ]]);

        return $this->aggregate($this->scope['almacen_id'], 'QASUINOR1')
            === '1.250000';
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    private function movement(string $concept, string $reference, array $parts): array
    {
        return $this->inventoryService()->aplicarMovimiento([
            'empresa_id' => $this->scope['empresa_id'],
            'almacen_id' => $this->scope['almacen_id'],
            'concepto_codigo' => $concept,
            'fecha_movimiento' => '2026-07-17 12:00:00',
            'referencia' => $reference,
            'usuario_id' => $this->adminId,
            'partidas' => $parts,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    private function transfer(string $reference, array $parts): array
    {
        return $this->transferService()->transferir([
            'empresa_id' => $this->scope['empresa_id'],
            'almacen_origen_id' => $this->scope['almacen_id'],
            'almacen_destino_id' => $this->destinationWarehouseId,
            'fecha_movimiento' => '2026-07-17 12:30:00',
            'referencia' => $reference,
            'usuario_id' => $this->adminId,
            'partidas' => $parts,
        ]);
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

    private function queries(): InventoryQueryRepository
    {
        return new InventoryQueryRepository(new ConnectionProvider(
            $this->databaseConfig()
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
            'INSERT INTO almacenes (empresa_id, codigo, nombre, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, :creado_por)'
        );
        $statement->execute([
            'empresa_id' => $this->scope['empresa_id'],
            'codigo' => self::WAREHOUSE_CODE,
            'nombre' => 'QA Series UI Destino',
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

    private function aggregate(int $warehouseId, string $productId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT cantidad_actual
             FROM existencias_producto
             WHERE almacen_id = :almacen_id
               AND id_producto = :id_producto'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
        ]);
        $value = $statement->fetchColumn();

        return $value === false
            ? '0.000000'
            : number_format((float) $value, 6, '.', '');
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
                 WHERE referencia LIKE '" . self::REFERENCE_PREFIX . "%'
                    OR referencia LIKE '" . self::TRANSFER_REFERENCE_PREFIX . "%'"
            )->fetchColumn(),
            'detalles_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM movimientos_inventario_detalle mid
                 INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
                 WHERE mi.referencia LIKE '" . self::REFERENCE_PREFIX . "%'
                    OR mi.referencia LIKE '" . self::TRANSFER_REFERENCE_PREFIX . "%'"
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
            'usuarios_qa' => 0,
        ];
    }

    private function cleanup(): void
    {
        $movementCondition = "mi.referencia LIKE '" . self::REFERENCE_PREFIX
            . "%' OR mi.referencia LIKE '" . self::TRANSFER_REFERENCE_PREFIX . "%'";

        foreach ([
            "DELETE mds FROM movimiento_detalle_series mds
             INNER JOIN movimientos_inventario_detalle mid
                ON mid.id = mds.movimiento_detalle_id
             INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
             WHERE " . $movementCondition,
            "DELETE mds FROM movimiento_detalle_series mds
             INNER JOIN producto_series ps ON ps.id = mds.serie_id
             WHERE ps.numero_serie LIKE '" . self::SERIES_PREFIX . "%'",
            "DELETE mid FROM movimientos_inventario_detalle mid
             INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
             WHERE " . $movementCondition,
            "DELETE FROM movimientos_inventario
             WHERE referencia LIKE '" . self::REFERENCE_PREFIX . "%'
                OR referencia LIKE '" . self::TRANSFER_REFERENCE_PREFIX . "%'",
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
