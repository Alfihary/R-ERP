<?php

declare(strict_types=1);

use App\Domain\Folios\FolioService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryTransferService;
use App\Domain\Inventory\InventoryValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\FolioRepository;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Infrastructure\Repositories\InventoryRepository;

return new class implements DatabaseTest {
    private const COMPANY = 'QAFIEMP';
    private const WAREHOUSE_BO = 'BO';
    private const WAREHOUSE_MTY = 'MTY';
    private const PRODUCT = 'QAFI0001';
    private const SERIAL_PRODUCT = 'QAFISERIE1';
    private const FORMAT = '{PREFIJO}-{ALMACEN}{NUMERO}';

    private PDO $pdo;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match FOLIOS-INVENTARIO-1.'
            );
        }

        $this->cleanup();
        $before = $this->qaCounts();
        $results = [];

        try {
            $ids = $this->fixtures();
            $services = $this->services();

            $results['migration_columns'] = $this->case(
                'migration_columns',
                fn (): bool => $this->columnsValid()
            );
            $results['migration_indexes'] = $this->case(
                'migration_indexes',
                fn (): bool => $this->indexesValid()
            );
            $results['migration_foreign_key'] = $this->case(
                'migration_foreign_key',
                fn (): bool => $this->foreignKeyValid()
            );
            $results['historic_null_supported'] = $this->case(
                'historic_null_supported',
                fn (): bool => $this->historicNullCase($ids)
            );
            $results['manual_movement_folios'] =
                $this->case(
                    'manual_movement_folios',
                    fn (): array => $this->manualMovementFolios($services['inventory'], $ids)
                );
            $results['missing_manual_series_rolls_back'] =
                $this->case(
                    'missing_manual_series_rolls_back',
                    fn (): array => $this->missingManualSeriesRollsBack($services['inventory'], $ids)
                );
            $results['functional_rollback_after_folio'] =
                $this->case(
                    'functional_rollback_after_folio',
                    fn (): array => $this->functionalRollbackAfterFolio($services['inventory'], $ids)
                );
            $results['transfer_folios'] =
                $this->case(
                    'transfer_folios',
                    fn (): array => $this->transferFolios($services['transfer'], $ids)
                );
            $results['missing_transfer_series_rolls_back'] =
                $this->case(
                    'missing_transfer_series_rolls_back',
                    fn (): array => $this->missingTransferSeriesRollsBack($services['transfer'], $ids)
                );
            $results['serialized_kardex_folio'] =
                $this->case(
                    'serialized_kardex_folio',
                    fn (): array => $this->serializedKardexFolio($services['inventory'], $services['queries'], $ids)
                );
            $results['queries_expose_folio_and_reference'] =
                $this->case(
                    'queries_expose_folio_and_reference',
                    fn (): array => $this->queriesExposeFolioAndReference($services['queries'], $ids)
                );

            foreach ($results as $label => $result) {
                if (
                    ($result !== true && !is_array($result))
                    || (is_array($result) && $this->containsFalse($result))
                ) {
                    throw new RuntimeException(
                        'FOLIOS-INVENTARIO-1 failed case: ' . $label
                    );
                }
            }

            $during = $this->qaCounts();
        } finally {
            $this->cleanup();
        }

        $after = $this->qaCounts();

        if (array_sum($after) !== 0) {
            throw new RuntimeException('FOLIOS-INVENTARIO-1 left QA data.');
        }

        return [
            'database' => $database,
            'migration' => [
                'columns' => $results['migration_columns'] ?? false,
                'indexes' => $results['migration_indexes'] ?? false,
                'foreign_key' => $results['migration_foreign_key'] ?? false,
            ],
            'cases' => $results,
            'qa_counts_before' => $before,
            'qa_counts_during' => $during ?? [],
            'qa_counts_after' => $after,
            'cleanup' => 'qa_rows_deleted',
        ];
    }

    /**
     * @param array<mixed> $values
     */
    private function containsFalse(array $values): bool
    {
        foreach ($values as $value) {
            if ($value === false) {
                return true;
            }

            if (is_array($value) && $this->containsFalse($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function case(string $label, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (PDOException $exception) {
            throw new RuntimeException(
                $label . ' failed with SQLSTATE[' . $exception->getCode() . '].'
            );
        }
    }

    /**
     * @return array{
     *     inventory: InventoryService,
     *     transfer: InventoryTransferService,
     *     queries: InventoryQueryRepository
     * }
     */
    private function services(): array
    {
        $config = require BASE_PATH . '/bootstrap/database.php';
        $databaseConfig = $config->get('database', []);

        if (!is_array($databaseConfig)) {
            throw new RuntimeException('Database configuration must be an array.');
        }

        $provider = new ConnectionProvider($databaseConfig);
        $inventory = new InventoryRepository($provider);
        $folios = new FolioService(new FolioRepository($provider));

        return [
            'inventory' => new InventoryService($inventory, $folios),
            'transfer' => new InventoryTransferService($inventory, $folios),
            'queries' => new InventoryQueryRepository($provider),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function fixtures(): array
    {
        $adminId = (int) $this->pdo->query(
            "SELECT id FROM usuarios WHERE activo = 1 AND eliminado_en IS NULL ORDER BY id LIMIT 1"
        )->fetchColumn();

        if ($adminId < 1) {
            throw new RuntimeException('FOLIOS-INVENTARIO-1 requires an active user.');
        }

        $companyId = $this->insertCompany();
        $boId = $this->insertWarehouse($companyId, self::WAREHOUSE_BO);
        $mtyId = $this->insertWarehouse($companyId, self::WAREHOUSE_MTY);
        $productTypeId = $this->productTypeId('PRODUCTO');
        $unitId = $this->unitId();
        $this->insertProduct(self::PRODUCT, $productTypeId, $unitId, false);
        $this->insertProduct(self::SERIAL_PRODUCT, $productTypeId, $unitId, true);
        $this->insertSeries($companyId, $boId, 'AJUSTE_INVENTARIO', 'AJ', 'AJ', 'BO');
        $this->insertSeries($companyId, $boId, 'TRANSFERENCIA_INVENTARIO', 'TR', 'TR', 'BO');
        $this->insertSeries($companyId, $mtyId, 'AJUSTE_INVENTARIO', 'AJ', 'AJ', 'MTY');

        return [
            'admin_id' => $adminId,
            'company_id' => $companyId,
            'bo_id' => $boId,
            'mty_id' => $mtyId,
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function manualMovementFolios(
        InventoryService $service,
        array $ids
    ): array {
        $first = $service->aplicarMovimiento($this->movementInput($ids, [
            'referencia' => 'QAFI-MANUAL-1',
        ]));
        $second = $service->aplicarMovimiento($this->movementInput($ids, [
            'referencia' => 'QAFI-MANUAL-2',
        ]));

        return [
            'first_folio' => $first['folio'],
            'second_folio' => $second['folio'],
            'first_expected' => $first['folio'] === 'AJ-BO000001',
            'second_expected' => $second['folio'] === 'AJ-BO000002',
            'reference_preserved' => $first['referencia'] === 'QAFI-MANUAL-1',
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function missingManualSeriesRollsBack(
        InventoryService $service,
        array $ids
    ): array {
        $before = $this->folioCount('QAFI-MISSING-MANUAL');
        $failed = $this->fails(fn () => $service->aplicarMovimiento(
            $this->movementInput($ids, [
                'almacen_id' => $ids['mty_id'],
                'referencia' => 'QAFI-MISSING-MANUAL',
                'folio' => [
                    'tipo_documento' => 'TRANSFERENCIA_INVENTARIO',
                    'codigo_serie' => 'TR',
                ],
            ])
        ));
        $after = $this->folioCount('QAFI-MISSING-MANUAL');

        return [
            'failed' => $failed,
            'folios_before' => $before,
            'folios_after' => $after,
            'no_folio_consumed' => $before === $after,
            'no_movement_created' =>
                $this->movementCount('QAFI-MISSING-MANUAL') === 0,
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function functionalRollbackAfterFolio(
        InventoryService $service,
        array $ids
    ): array {
        $before = $this->nextNumber($ids['bo_id'], 'AJUSTE_INVENTARIO', 'AJ');
        $failed = $this->fails(fn () => $service->aplicarMovimiento(
            $this->movementInput($ids, [
                'referencia' => 'QAFI-ROLLBACK-AJ',
                '__simulate_failure_after_folio' => true,
            ])
        ));
        $after = $this->nextNumber($ids['bo_id'], 'AJUSTE_INVENTARIO', 'AJ');

        return [
            'failed' => $failed,
            'next_before' => $before,
            'next_after' => $after,
            'serie_not_advanced' => $before === $after,
            'no_folio_consumed' => $this->folioCount('QAFI-ROLLBACK-AJ') === 0,
            'no_movement_created' => $this->movementCount('QAFI-ROLLBACK-AJ') === 0,
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function transferFolios(
        InventoryTransferService $service,
        array $ids
    ): array {
        $this->seedStock($ids['bo_id'], self::PRODUCT, '10.000000');
        $first = $service->transferir($this->transferInput($ids, [
            'referencia' => 'TRF-QAFI-1',
        ]));
        $second = $service->transferir($this->transferInput($ids, [
            'referencia' => 'TRF-QAFI-2',
        ]));
        $shared = $this->transferMovementFolios('TRF-QAFI-1');

        return [
            'first_folio' => $first['folio'],
            'second_folio' => $second['folio'],
            'first_expected' => $first['folio'] === 'TR-BO000001',
            'second_expected' => $second['folio'] === 'TR-BO000002',
            'shared_folio_id' => count(array_unique(array_column($shared, 'folio_id'))) === 1,
            'shared_folio' => count(array_unique(array_column($shared, 'folio'))) === 1,
            'reference_preserved' => $first['referencia_transferencia'] === 'TRF-QAFI-1',
            'origin_stock_moved' =>
                $this->stock($ids['bo_id'], self::PRODUCT) === '8.000000',
            'destination_stock_moved' =>
                $this->stock($ids['mty_id'], self::PRODUCT) === '2.000000',
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function missingTransferSeriesRollsBack(
        InventoryTransferService $service,
        array $ids
    ): array {
        $this->pdo->prepare(
            "UPDATE series_documentales
             SET activo = 0
             WHERE empresa_id = :empresa_id
               AND almacen_id = :almacen_id
               AND tipo_documento = 'TRANSFERENCIA_INVENTARIO'
               AND codigo_serie = 'TR'"
        )->execute([
            'empresa_id' => $ids['company_id'],
            'almacen_id' => $ids['bo_id'],
        ]);
        $failed = $this->fails(fn () => $service->transferir(
            $this->transferInput($ids, ['referencia' => 'TRF-QAFI-MISSING'])
        ));
        $this->pdo->prepare(
            "UPDATE series_documentales
             SET activo = 1
             WHERE empresa_id = :empresa_id
               AND almacen_id = :almacen_id
               AND tipo_documento = 'TRANSFERENCIA_INVENTARIO'
               AND codigo_serie = 'TR'"
        )->execute([
            'empresa_id' => $ids['company_id'],
            'almacen_id' => $ids['bo_id'],
        ]);

        return [
            'failed' => $failed,
            'no_folio_consumed' => $this->folioCount('TRF-QAFI-MISSING') === 0,
            'no_movement_created' => $this->movementCount('TRF-QAFI-MISSING') === 0,
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function serializedKardexFolio(
        InventoryService $service,
        InventoryQueryRepository $queries,
        array $ids
    ): array {
        $result = $service->aplicarMovimiento($this->movementInput($ids, [
            'referencia' => 'QAFI-SERIAL-1',
            'partidas' => [[
                'id_producto' => self::SERIAL_PRODUCT,
                'cantidad' => '1',
                'series' => ['QAFI-SN-001'],
            ]],
        ]));
        $kardex = $queries->serialKardex([
            'company_id' => $ids['company_id'],
            'warehouse_id' => $ids['bo_id'],
            'search' => 'QAFI-SN-001',
            'product_id' => '',
            'serial_number' => '',
            'date_from' => '',
            'date_to' => '',
            'page' => 1,
            'per_page' => 10,
        ]);
        $row = $kardex['rows'][0] ?? [];

        return [
            'movement_folio' => $result['folio'],
            'kardex_folio' => $row['folio'] ?? null,
            'matches' => ($row['folio'] ?? null) === $result['folio'],
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function queriesExposeFolioAndReference(
        InventoryQueryRepository $queries,
        array $ids
    ): array {
        $movements = $queries->movements([
            'company_id' => $ids['company_id'],
            'warehouse_id' => $ids['bo_id'],
            'search' => 'AJ-BO000001',
            'concept' => '',
            'status' => '',
            'date_from' => '',
            'date_to' => '',
            'page' => 1,
            'per_page' => 10,
        ]);
        $kardex = $queries->kardex([
            'company_id' => $ids['company_id'],
            'warehouse_id' => $ids['bo_id'],
            'product_id' => self::PRODUCT,
            'search' => '',
            'concept' => '',
            'nature' => '',
            'date_from' => '',
            'date_to' => '',
            'page' => 1,
            'per_page' => 10,
        ]);
        $transfer = $queries->transfer('TRF-QAFI-1', $ids['company_id']);

        return [
            'movement_has_folio' =>
                ($movements['rows'][0]['folio'] ?? null) === 'AJ-BO000001',
            'movement_has_reference' =>
                ($movements['rows'][0]['referencia'] ?? null) === 'QAFI-MANUAL-1',
            'kardex_has_folio' =>
                in_array('AJ-BO000001', array_column($kardex['rows'], 'folio'), true),
            'transfer_has_folio' => ($transfer['folio'] ?? null) === 'TR-BO000001',
            'transfer_has_reference' => ($transfer['referencia'] ?? null) === 'TRF-QAFI-1',
        ];
    }

    private function columnsValid(): bool
    {
        $statement = $this->pdo->query(
            "SELECT column_name, column_type, character_set_name, collation_name, is_nullable
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'movimientos_inventario'
               AND column_name IN ('folio_id', 'folio')"
        );
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $columns[(string) $row['column_name']] = [
                strtolower((string) $row['column_type']),
                $row['character_set_name'],
                $row['collation_name'],
                $row['is_nullable'],
            ];
        }

        return ($columns['folio_id'] ?? null) === ['bigint unsigned', null, null, 'YES']
            && ($columns['folio'] ?? null) === ['varchar(100)', 'ascii', 'ascii_bin', 'YES'];
    }

    private function indexesValid(): bool
    {
        $indexes = $this->indexNames('movimientos_inventario');

        return in_array('idx_movimientos_inventario_folio_id', $indexes, true)
            && in_array('idx_movimientos_inventario_folio', $indexes, true);
    }

    private function foreignKeyValid(): bool
    {
        $statement = $this->pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE()
               AND table_name = 'movimientos_inventario'
               AND column_name = 'folio_id'
               AND referenced_table_name = 'documentos_folios'
               AND referenced_column_name = 'id'"
        );

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @param array<string, int> $ids
     */
    private function historicNullCase(array $ids): bool
    {
        $movementId = $this->insertHistoricMovement($ids, 'QAFI-HISTORIC-NULL');
        $statement = $this->pdo->prepare(
            'SELECT folio_id, folio
             FROM movimientos_inventario
             WHERE id = :id'
        );
        $statement->execute(['id' => $movementId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            && $row['folio_id'] === null
            && $row['folio'] === null;
    }

    /**
     * @param array<string, int> $ids
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function movementInput(array $ids, array $overrides = []): array
    {
        return array_replace_recursive([
            'empresa_id' => $ids['company_id'],
            'almacen_id' => $ids['bo_id'],
            'concepto_codigo' => 'ENTRADA_AJUSTE',
            'fecha_movimiento' => '2026-07-21 10:00:00',
            'referencia' => 'QAFI-MOV',
            'observaciones' => 'QA FOLIOS INVENTARIO',
            'usuario_id' => $ids['admin_id'],
            'partidas' => [[
                'id_producto' => self::PRODUCT,
                'cantidad' => '1',
                'observaciones' => null,
                'series' => [],
            ]],
        ], $overrides);
    }

    /**
     * @param array<string, int> $ids
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function transferInput(array $ids, array $overrides = []): array
    {
        return array_replace_recursive([
            'empresa_id' => $ids['company_id'],
            'almacen_origen_id' => $ids['bo_id'],
            'almacen_destino_id' => $ids['mty_id'],
            'fecha_movimiento' => '2026-07-21 11:00:00',
            'referencia' => 'TRF-QAFI',
            'observaciones' => 'QA FOLIOS TRANSFERENCIA',
            'usuario_id' => $ids['admin_id'],
            'partidas' => [[
                'id_producto' => self::PRODUCT,
                'cantidad' => '1',
                'observaciones' => null,
                'series' => [],
            ]],
        ], $overrides);
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (InventoryValidationException) {
            return true;
        }

        return false;
    }

    private function insertCompany(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre)
             VALUES (:codigo, :nombre)'
        );
        $statement->execute([
            'codigo' => self::COMPANY,
            'nombre' => 'QA Folios Inventario Empresa',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertWarehouse(int $companyId, string $code): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, tipo_almacen)
             VALUES (:empresa_id, :codigo, :nombre, :tipo_almacen)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => 'QA Almacén ' . $code,
            'tipo_almacen' => 'GENERAL',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function productTypeId(string $code): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM tipos_producto WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);

        return (int) $statement->fetchColumn();
    }

    private function unitId(): int
    {
        return (int) $this->pdo->query(
            "SELECT id FROM unidades_medida WHERE codigo = 'PIEZA' LIMIT 1"
        )->fetchColumn();
    }

    private function insertProduct(
        string $productId,
        int $productTypeId,
        int $unitId,
        bool $tracksSeries
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO productos (
                id_producto,
                descripcion,
                descripcion_larga,
                tipo_producto_id,
                unidad_medida_id,
                controla_series
             ) VALUES (
                :id_producto,
                :descripcion,
                :descripcion_larga,
                :tipo_producto_id,
                :unidad_medida_id,
                :controla_series
             )'
        );
        $statement->execute([
            'id_producto' => $productId,
            'descripcion' => $tracksSeries ? 'QA Serie' : 'QA Producto',
            'descripcion_larga' => 'Producto QA FOLIOS-INVENTARIO-1',
            'tipo_producto_id' => $productTypeId,
            'unidad_medida_id' => $unitId,
            'controla_series' => $tracksSeries ? 1 : 0,
        ]);
    }

    private function insertSeries(
        int $companyId,
        int $warehouseId,
        string $type,
        string $series,
        string $prefix,
        string $warehouseCode
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO series_documentales (
                empresa_id,
                almacen_id,
                tipo_documento,
                codigo_serie,
                prefijo,
                codigo_almacen_snapshot,
                formato,
                siguiente_numero,
                longitud,
                activo
             ) VALUES (
                :empresa_id,
                :almacen_id,
                :tipo_documento,
                :codigo_serie,
                :prefijo,
                :codigo_almacen_snapshot,
                :formato,
                1,
                6,
                1
             )'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'tipo_documento' => $type,
            'codigo_serie' => $series,
            'prefijo' => $prefix,
            'codigo_almacen_snapshot' => $warehouseCode,
            'formato' => self::FORMAT,
        ]);
    }

    private function insertHistoricMovement(array $ids, string $reference): int
    {
        $conceptId = (int) $this->pdo->query(
            "SELECT id FROM conceptos_movimiento_inventario
             WHERE codigo = 'ENTRADA_AJUSTE'
             LIMIT 1"
        )->fetchColumn();
        $statement = $this->pdo->prepare(
            'INSERT INTO movimientos_inventario (
                empresa_id,
                almacen_id,
                concepto_movimiento_id,
                fecha_movimiento,
                estado,
                referencia,
                creado_por,
                aplicado_por,
                aplicado_en
             ) VALUES (
                :empresa_id,
                :almacen_id,
                :concepto_id,
                :fecha,
                \'APLICADO\',
                :referencia,
                :creado_por,
                :aplicado_por,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'empresa_id' => $ids['company_id'],
            'almacen_id' => $ids['bo_id'],
            'concepto_id' => $conceptId,
            'fecha' => '2026-07-21 09:00:00',
            'referencia' => $reference,
            'creado_por' => $ids['admin_id'],
            'aplicado_por' => $ids['admin_id'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedStock(
        int $warehouseId,
        string $productId,
        string $quantity
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO existencias_producto (
                almacen_id,
                id_producto,
                cantidad_actual
             ) VALUES (
                :almacen_id,
                :id_producto,
                :cantidad
             )
             ON DUPLICATE KEY UPDATE cantidad_actual = :cantidad_update'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
            'cantidad' => $quantity,
            'cantidad_update' => $quantity,
        ]);
    }

    /**
     * @return list<array{folio_id: int|null, folio: string|null}>
     */
    private function transferMovementFolios(string $reference): array
    {
        $statement = $this->pdo->prepare(
            'SELECT folio_id, folio
             FROM movimientos_inventario
             WHERE referencia = :referencia
             ORDER BY id'
        );
        $statement->execute(['referencia' => $reference]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function nextNumber(
        int $warehouseId,
        string $type,
        string $series
    ): int {
        $statement = $this->pdo->prepare(
            'SELECT siguiente_numero
             FROM series_documentales
             WHERE almacen_id = :almacen_id
               AND tipo_documento = :tipo_documento
               AND codigo_serie = :codigo_serie'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'tipo_documento' => $type,
            'codigo_serie' => $series,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function folioCount(string $reference): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM documentos_folios
             WHERE referencia_externa = :referencia'
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

    private function stock(int $warehouseId, string $productId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT CAST(cantidad_actual AS CHAR)
             FROM existencias_producto
             WHERE almacen_id = :almacen_id
               AND id_producto = :id_producto'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
        ]);

        $stock = $statement->fetchColumn();

        return is_string($stock) ? $stock : '0.000000';
    }

    /**
     * @return array<string, int>
     */
    private function qaCounts(): array
    {
        return [
            'empresas_qa' => $this->countWhere('empresas', "codigo = 'QAFIEMP'"),
            'almacenes_qa' => $this->countWhere('almacenes', "codigo IN ('BO', 'MTY') AND nombre LIKE 'QA Almacén %'"),
            'productos_qa' => $this->countWhere('productos', "id_producto IN ('QAFI0001', 'QAFISERIE1')"),
            'series_documentales_qa' => $this->countWhere('series_documentales', "codigo_almacen_snapshot IN ('BO', 'MTY') AND tipo_documento IN ('AJUSTE_INVENTARIO', 'TRANSFERENCIA_INVENTARIO')"),
            'documentos_folios_qa' => $this->countWhere('documentos_folios', "referencia_externa LIKE 'QAFI%' OR referencia_externa LIKE 'TRF-QAFI%'"),
            'movimientos_qa' => $this->countWhere('movimientos_inventario', "referencia LIKE 'QAFI%' OR referencia LIKE 'TRF-QAFI%'"),
            'existencias_producto_qa' => $this->countWhere('existencias_producto', "id_producto IN ('QAFI0001', 'QAFISERIE1')"),
            'producto_series_qa' => $this->countWhere('producto_series', "id_producto = 'QAFISERIE1'"),
        ];
    }

    private function cleanup(): void
    {
        $this->pdo->exec(
            "DELETE mds FROM movimiento_detalle_series mds
             INNER JOIN movimientos_inventario_detalle mid
                ON mid.id = mds.movimiento_detalle_id
             INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
             WHERE mi.referencia LIKE 'QAFI%' OR mi.referencia LIKE 'TRF-QAFI%'"
        );
        $this->pdo->exec(
            "DELETE mid FROM movimientos_inventario_detalle mid
             INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
             WHERE mi.referencia LIKE 'QAFI%' OR mi.referencia LIKE 'TRF-QAFI%'"
        );
        $this->pdo->exec(
            "DELETE FROM movimientos_inventario
             WHERE referencia LIKE 'QAFI%' OR referencia LIKE 'TRF-QAFI%'"
        );
        $this->pdo->exec(
            "DELETE FROM documentos_folios
             WHERE referencia_externa LIKE 'QAFI%'
                OR referencia_externa LIKE 'TRF-QAFI%'"
        );
        $this->pdo->exec(
            "DELETE FROM series_documentales
             WHERE codigo_almacen_snapshot IN ('BO', 'MTY')
               AND tipo_documento IN (
                    'AJUSTE_INVENTARIO',
                    'TRANSFERENCIA_INVENTARIO'
               )"
        );
        $this->pdo->exec(
            "DELETE FROM existencias_serie
             WHERE serie_id IN (
                SELECT id FROM producto_series WHERE id_producto = 'QAFISERIE1'
             )"
        );
        $this->pdo->exec("DELETE FROM producto_series WHERE id_producto = 'QAFISERIE1'");
        $this->pdo->exec(
            "DELETE FROM existencias_producto
             WHERE id_producto IN ('QAFI0001', 'QAFISERIE1')"
        );
        $this->pdo->exec(
            "DELETE FROM productos
             WHERE id_producto IN ('QAFI0001', 'QAFISERIE1')"
        );
        $this->pdo->exec(
            "DELETE FROM almacenes
             WHERE codigo IN ('BO', 'MTY') AND nombre LIKE 'QA Almacén %'"
        );
        $this->pdo->exec("DELETE FROM empresas WHERE codigo = 'QAFIEMP'");
    }

    private function countWhere(string $table, string $where): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT index_name
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
};
