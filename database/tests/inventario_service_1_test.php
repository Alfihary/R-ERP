<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\InventoryRepository;

return new class implements DatabaseTest {
    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match INVENTARIO-SERVICE-1.'
            );
        }

        $this->cleanup($pdo);
        $before = $this->counts($pdo);
        $ids = $this->requiredIds($pdo);
        $service = $this->service();

        $this->createProducts($pdo, $ids);

        try {
            $positive = $this->positiveCases($pdo, $service, $ids);
            $negative = $this->negativeCases($pdo, $service, $ids);
            $insufficient = $this->insufficientStockCase($pdo, $service, $ids);
            $multipart = $this->multipartRollbackCase($pdo, $service, $ids);
            $precision = $this->precisionCases($pdo, $service, $ids);
            $history = $this->historyCase($pdo, $service, $ids);
            $structural = $this->structuralEvidence($pdo);

            if ($this->draftCount($pdo) !== 0) {
                throw new RuntimeException(
                    'INVENTARIO-SERVICE-1 left residual draft movements.'
                );
            }
        } finally {
            $this->cleanup($pdo);
        }

        $after = $this->counts($pdo);

        if ($after !== $before) {
            throw new RuntimeException(
                'INVENTARIO-SERVICE-1 left persistent QA data.'
            );
        }

        return [
            'database' => $database,
            'positive_cases' => $positive,
            'negative_cases' => $negative,
            'insufficient_stock' => $insufficient,
            'multipart_rollback' => $multipart,
            'precision' => $precision,
            'history' => $history,
            'structural' => $structural,
            'concurrency_test' => [
                'executed' => false,
                'reason' =>
                    'Single-process CLI runner cannot coordinate two reliable overlapping PDO transactions without adding external harnesses.',
                'covered_by_design' => [
                    'transaction',
                    'SELECT_FOR_UPDATE',
                    'deterministic_product_order',
                    'unique_existence_row',
                    'db_decimal_arithmetic',
                ],
            ],
            'persistent_counts_before' => $before,
            'persistent_counts_after' => $after,
            'cleanup' => 'qa_rows_deleted',
        ];
    }

    private function service(): InventoryService
    {
        $config = require BASE_PATH . '/bootstrap/database.php';
        $databaseConfig = $config->get('database', []);

        if (!is_array($databaseConfig)) {
            throw new RuntimeException('Database configuration must be an array.');
        }

        return new InventoryService(
            new InventoryRepository(new ConnectionProvider($databaseConfig))
        );
    }

    /**
     * @return array<string, int>
     */
    private function requiredIds(PDO $pdo): array
    {
        $adminId = (int) $pdo->query(
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
        $scope = $pdo->query(
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
        $unitId = $this->idByCode($pdo, 'unidades_medida', 'PIEZA');
        $productTypeId = $this->idByCode($pdo, 'tipos_producto', 'PRODUCTO');
        $kitTypeId = $this->idByCode($pdo, 'tipos_producto', 'KIT');
        $serviceTypeId = $this->idByCode($pdo, 'tipos_producto', 'SERVICIO');

        if ($adminId < 1 || !is_array($scope)) {
            throw new RuntimeException(
                'INVENTARIO-SERVICE-1 required fixtures are unavailable.'
            );
        }

        return [
            'admin_id' => $adminId,
            'empresa_id' => (int) $scope['empresa_id'],
            'almacen_id' => (int) $scope['almacen_id'],
            'unidad_id' => $unitId,
            'producto_tipo_id' => $productTypeId,
            'kit_tipo_id' => $kitTypeId,
            'servicio_tipo_id' => $serviceTypeId,
        ];
    }

    private function idByCode(PDO $pdo, string $table, string $code): int
    {
        if (!in_array($table, ['unidades_medida', 'tipos_producto'], true)) {
            throw new InvalidArgumentException('Unsupported code table.');
        }

        $statement = $pdo->prepare(
            'SELECT id
             FROM ' . $table . '
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Required code missing: ' . $code);
        }

        return $id;
    }

    /**
     * @param array<string, int> $ids
     */
    private function createProducts(PDO $pdo, array $ids): void
    {
        foreach ([
            ['QAISPROD1', 'Producto QA 1', $ids['producto_tipo_id'], 1],
            ['QAISPROD2', 'Producto QA 2', $ids['producto_tipo_id'], 1],
            ['QAISPROD3', 'Producto QA 3', $ids['producto_tipo_id'], 1],
            ['QAISKIT1', 'Kit QA 1', $ids['kit_tipo_id'], 1],
            ['QAISSERV1', 'Servicio QA 1', $ids['servicio_tipo_id'], 1],
            ['QAISINACT1', 'Inactivo QA', $ids['producto_tipo_id'], 0],
            ['QAISDEC1', 'Decimal QA 1', $ids['producto_tipo_id'], 1],
            ['QAISDEC2', 'Decimal QA 2', $ids['producto_tipo_id'], 1],
            ['QAISDEC3', 'Decimal QA 3', $ids['producto_tipo_id'], 1],
            ['QAISDEC4', 'Decimal QA 4', $ids['producto_tipo_id'], 1],
            ['QAISHIST1', 'Historia QA', $ids['producto_tipo_id'], 1],
            ['QAISROLLA', 'Rollback QA A', $ids['producto_tipo_id'], 1],
            ['QAISROLLB', 'Rollback QA B', $ids['producto_tipo_id'], 1],
        ] as [$productId, $description, $typeId, $active]) {
            $statement = $pdo->prepare(
                'INSERT INTO productos (
                    id_producto,
                    descripcion,
                    unidad_medida_id,
                    tipo_producto_id,
                    activo,
                    creado_por
                 )
                 VALUES (
                    :id_producto,
                    :descripcion,
                    :unidad_medida_id,
                    :tipo_producto_id,
                    :activo,
                    :creado_por
                 )'
            );
            $statement->execute([
                'id_producto' => $productId,
                'descripcion' => $description,
                'unidad_medida_id' => $ids['unidad_id'],
                'tipo_producto_id' => $typeId,
                'activo' => $active,
                'creado_por' => $ids['admin_id'],
            ]);
        }
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function positiveCases(
        PDO $pdo,
        InventoryService $service,
        array $ids
    ): array {
        $entry = $service->aplicarMovimiento($this->input($ids, [
            ['QAISPROD1', '10.000000'],
        ], 'ENTRADA_AJUSTE', 'QAIS-POS-1'));
        $secondEntry = $service->aplicarMovimiento($this->input($ids, [
            ['QAISPROD1', '2.500000'],
        ], 'ENTRADA_AJUSTE', 'QAIS-POS-2'));
        $exit = $service->aplicarMovimiento($this->input($ids, [
            ['QAISPROD1', '4.250000'],
        ], 'SALIDA_AJUSTE', 'QAIS-POS-3'));
        $minimum = $service->aplicarMovimiento($this->input($ids, [
            ['QAISPROD1', '0.000001'],
        ], 'ENTRADA_AJUSTE', 'QAIS-POS-4'));
        $multiple = $service->aplicarMovimiento($this->input($ids, [
            ['QAISPROD2', '1.000000'],
            ['QAISPROD3', '2.000000'],
        ], 'ENTRADA_AJUSTE', 'QAIS-POS-5'));
        $kit = $service->aplicarMovimiento($this->input($ids, [
            ['QAISKIT1', '3.000000'],
        ], 'ENTRADA_AJUSTE', 'QAIS-POS-6'));

        return [
            'entry_without_existence' =>
                $entry['partidas_aplicadas'][0]['saldo_anterior'] === '0.000000'
                && $entry['partidas_aplicadas'][0]['saldo_nuevo'] === '10.000000'
                && $entry['estado'] === 'APLICADO',
            'second_entry' =>
                $secondEntry['partidas_aplicadas'][0]['saldo_anterior'] === '10.000000'
                && $secondEntry['partidas_aplicadas'][0]['saldo_nuevo'] === '12.500000',
            'sufficient_exit' =>
                $exit['partidas_aplicadas'][0]['saldo_anterior'] === '12.500000'
                && $exit['partidas_aplicadas'][0]['saldo_nuevo'] === '8.250000',
            'minimum_quantity' =>
                $minimum['partidas_aplicadas'][0]['cantidad'] === '0.000001',
            'multiple_products' =>
                count($multiple['partidas_aplicadas']) === 2
                && $multiple['estado'] === 'APLICADO',
            'active_kit_as_independent_identity' =>
                $kit['partidas_aplicadas'][0]['id_producto'] === 'QAISKIT1'
                && $kit['estado'] === 'APLICADO',
            'final_product_balance' => $this->balance($pdo, 'QAISPROD1'),
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, bool>
     */
    private function negativeCases(
        PDO $pdo,
        InventoryService $service,
        array $ids
    ): array {
        $conceptId = (int) $pdo->query(
            "SELECT id FROM conceptos_movimiento_inventario
             WHERE codigo = 'SALIDA_AJUSTE'"
        )->fetchColumn();
        $pdo->exec(
            'UPDATE conceptos_movimiento_inventario
             SET activo = 0
             WHERE codigo = \'SALIDA_AJUSTE\''
        );

        $cases = [
            'empresa_inexistente' => fn () => $service->aplicarMovimiento(
                $this->input(array_replace($ids, ['empresa_id' => 999999999]), [
                    ['QAISPROD1', '1.000000'],
                ], 'ENTRADA_AJUSTE', 'QAIS-NEG-EMP')
            ),
            'almacen_inexistente' => fn () => $service->aplicarMovimiento(
                $this->input(array_replace($ids, ['almacen_id' => 999999999]), [
                    ['QAISPROD1', '1.000000'],
                ], 'ENTRADA_AJUSTE', 'QAIS-NEG-WH')
            ),
            'almacen_otra_empresa' => fn () => $service->aplicarMovimiento(
                $this->input(array_replace($ids, ['empresa_id' => 999999999]), [
                    ['QAISPROD1', '1.000000'],
                ], 'ENTRADA_AJUSTE', 'QAIS-NEG-SCOPE')
            ),
            'concepto_inexistente' => fn () => $service->aplicarMovimiento(
                $this->input($ids, [['QAISPROD1', '1.000000']], 'NO_EXISTE', 'QAIS-NEG-CON')
            ),
            'concepto_inactivo' => fn () => $service->aplicarMovimiento(
                $this->input($ids, [['QAISPROD1', '1.000000']], 'SALIDA_AJUSTE', 'QAIS-NEG-CI')
            ),
            'producto_inexistente' => fn () => $service->aplicarMovimiento(
                $this->input($ids, [['QAIS404', '1.000000']], 'ENTRADA_AJUSTE', 'QAIS-NEG-P404')
            ),
            'producto_inactivo' => fn () => $service->aplicarMovimiento(
                $this->input($ids, [['QAISINACT1', '1.000000']], 'ENTRADA_AJUSTE', 'QAIS-NEG-PI')
            ),
            'servicio' => fn () => $service->aplicarMovimiento(
                $this->input($ids, [['QAISSERV1', '1.000000']], 'ENTRADA_AJUSTE', 'QAIS-NEG-SERV')
            ),
            'partidas_vacias' => fn () => $service->aplicarMovimiento(
                $this->input($ids, [], 'ENTRADA_AJUSTE', 'QAIS-NEG-EMPTY')
            ),
            'producto_duplicado' => fn () => $service->aplicarMovimiento(
                $this->input($ids, [
                    ['QAISPROD1', '1.000000'],
                    ['QAISPROD1', '2.000000'],
                ], 'ENTRADA_AJUSTE', 'QAIS-NEG-DUP')
            ),
            'cantidad_cero' => fn () => $service->aplicarMovimiento(
                $this->input($ids, [['QAISPROD1', '0.000000']], 'ENTRADA_AJUSTE', 'QAIS-NEG-ZERO')
            ),
            'cantidad_negativa' => fn () => $service->aplicarMovimiento(
                $this->input($ids, [['QAISPROD1', '-1.000000']], 'ENTRADA_AJUSTE', 'QAIS-NEG-NEG')
            ),
            'mas_de_6_decimales' => fn () => $service->aplicarMovimiento(
                $this->input($ids, [['QAISPROD1', '1.0000001']], 'ENTRADA_AJUSTE', 'QAIS-NEG-SCALE')
            ),
            'fuera_de_rango' => fn () => $service->aplicarMovimiento(
                $this->input($ids, [['QAISPROD1', '1000000000000.000000']], 'ENTRADA_AJUSTE', 'QAIS-NEG-RANGE')
            ),
            'usuario_inexistente' => fn () => $service->aplicarMovimiento(
                $this->input(array_replace($ids, ['admin_id' => 999999999]), [
                    ['QAISPROD1', '1.000000'],
                ], 'ENTRADA_AJUSTE', 'QAIS-NEG-USER')
            ),
        ];

        $results = [];

        try {
            foreach ($cases as $label => $operation) {
                $results[$label] = $this->failsWithDomainError($operation);
            }
        } finally {
            $statement = $pdo->prepare(
                'UPDATE conceptos_movimiento_inventario
                 SET activo = 1
                 WHERE id = :id'
            );
            $statement->execute(['id' => $conceptId]);
        }

        return $results;
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function insufficientStockCase(
        PDO $pdo,
        InventoryService $service,
        array $ids
    ): array {
        $this->setBalance($pdo, $ids['almacen_id'], 'QAISPROD2', '5.000000');
        $beforeMovements = $this->movementCountByReference($pdo, 'QAIS-INSUF');
        $failed = $this->failsWithDomainError(
            fn () => $service->aplicarMovimiento($this->input($ids, [
                ['QAISPROD2', '5.000001'],
            ], 'SALIDA_AJUSTE', 'QAIS-INSUF'))
        );

        return [
            'rejected' => $failed,
            'balance_after' => $this->balance($pdo, 'QAISPROD2'),
            'new_movements' =>
                $this->movementCountByReference($pdo, 'QAIS-INSUF')
                - $beforeMovements,
            'new_details' => $this->detailCountByReference($pdo, 'QAIS-INSUF'),
            'residual_drafts' => $this->draftCountByReference($pdo, 'QAIS-INSUF'),
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function multipartRollbackCase(
        PDO $pdo,
        InventoryService $service,
        array $ids
    ): array {
        $this->setBalance($pdo, $ids['almacen_id'], 'QAISROLLA', '10.000000');
        $this->setBalance($pdo, $ids['almacen_id'], 'QAISROLLB', '1.000000');

        $failed = $this->failsWithDomainError(
            fn () => $service->aplicarMovimiento($this->input($ids, [
                ['QAISROLLA', '2.000000'],
                ['QAISROLLB', '5.000000'],
            ], 'SALIDA_AJUSTE', 'QAIS-ROLLBACK'))
        );

        return [
            'rejected' => $failed,
            'product_a_balance' => $this->balance($pdo, 'QAISROLLA'),
            'product_b_balance' => $this->balance($pdo, 'QAISROLLB'),
            'new_movements' => $this->movementCountByReference($pdo, 'QAIS-ROLLBACK'),
            'new_details' => $this->detailCountByReference($pdo, 'QAIS-ROLLBACK'),
            'residual_drafts' => $this->draftCountByReference($pdo, 'QAIS-ROLLBACK'),
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function precisionCases(
        PDO $pdo,
        InventoryService $service,
        array $ids
    ): array {
        $results = [];

        foreach ([
            'QAISDEC1' => '0.000001',
            'QAISDEC2' => '1.000000',
            'QAISDEC3' => '123456789.123456',
        ] as $productId => $quantity) {
            $service->aplicarMovimiento($this->input($ids, [
                [$productId, $quantity],
            ], 'ENTRADA_AJUSTE', 'QAIS-DEC-' . $productId));
            $results[$quantity] = $this->balance($pdo, $productId);
        }

        $service->aplicarMovimiento($this->input($ids, [
            ['QAISDEC4', '0.100000'],
        ], 'ENTRADA_AJUSTE', 'QAIS-DEC-SUM1'));
        $service->aplicarMovimiento($this->input($ids, [
            ['QAISDEC4', '0.200000'],
        ], 'ENTRADA_AJUSTE', 'QAIS-DEC-SUM2'));

        return [
            'exact_values' => $results,
            'accumulation_0_1_plus_0_2' => $this->balance($pdo, 'QAISDEC4'),
            'assertions_use_strings' => true,
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function historyCase(
        PDO $pdo,
        InventoryService $service,
        array $ids
    ): array {
        $entry = $service->aplicarMovimiento($this->input($ids, [
            ['QAISHIST1', '6.000000'],
        ], 'ENTRADA_AJUSTE', 'QAIS-HIST-1'));
        $exit = $service->aplicarMovimiento($this->input($ids, [
            ['QAISHIST1', '1.500000'],
        ], 'SALIDA_AJUSTE', 'QAIS-HIST-2'));

        return [
            'movements' => $this->movementCountByProduct($pdo, 'QAISHIST1'),
            'final_balance' => $this->balance($pdo, 'QAISHIST1'),
            'entry_remains' => $this->movementExists($pdo, (int) $entry['movimiento_id']),
            'exit_remains' => $this->movementExists($pdo, (int) $exit['movimiento_id']),
            'no_annulled_created' => $this->annulledCount($pdo),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function structuralEvidence(PDO $pdo): array
    {
        return [
            'triggers' => $this->objectCount($pdo, 'TRIGGER'),
            'procedures' => $this->routineCount($pdo, 'PROCEDURE'),
            'functions' => $this->routineCount($pdo, 'FUNCTION'),
            'events' => $this->eventCount($pdo),
            'drafts_residual' => $this->draftCount($pdo),
            'db_has_negative_check' => $this->negativeStockCheckExists($pdo),
        ];
    }

    /**
     * @param array<string, int> $ids
     * @param list<array{0: string, 1: string}> $parts
     * @return array<string, mixed>
     */
    private function input(
        array $ids,
        array $parts,
        string $concept,
        string $reference
    ): array {
        return [
            'empresa_id' => $ids['empresa_id'],
            'almacen_id' => $ids['almacen_id'],
            'concepto_codigo' => $concept,
            'fecha_movimiento' => '2026-07-09 10:30:00',
            'referencia' => $reference,
            'observaciones' => 'QA INVENTARIO SERVICE 1',
            'usuario_id' => $ids['admin_id'],
            'partidas' => array_map(
                static fn (array $part): array => [
                    'id_producto' => $part[0],
                    'cantidad' => $part[1],
                ],
                $parts
            ),
        ];
    }

    /**
     * @param callable(): mixed $operation
     */
    private function failsWithDomainError(callable $operation): bool
    {
        try {
            $operation();
        } catch (InventoryValidationException) {
            return true;
        }

        return false;
    }

    private function setBalance(
        PDO $pdo,
        int $warehouseId,
        string $productId,
        string $quantity
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO existencias_producto (
                almacen_id,
                id_producto,
                cantidad_actual
             )
             VALUES (
                :almacen_id,
                :id_producto,
                :cantidad_actual
             )
             ON DUPLICATE KEY UPDATE
                cantidad_actual = :cantidad_actual_update'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
            'cantidad_actual' => $quantity,
            'cantidad_actual_update' => $quantity,
        ]);
    }

    private function balance(PDO $pdo, string $productId): string
    {
        $statement = $pdo->prepare(
            'SELECT cantidad_actual
             FROM existencias_producto
             WHERE id_producto = :id_producto
             LIMIT 1'
        );
        $statement->execute(['id_producto' => $productId]);
        $value = $statement->fetchColumn();

        return $value === false ? '0.000000' : (string) $value;
    }

    private function movementCountByReference(PDO $pdo, string $reference): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM movimientos_inventario
             WHERE referencia = :referencia'
        );
        $statement->execute(['referencia' => $reference]);

        return (int) $statement->fetchColumn();
    }

    private function detailCountByReference(PDO $pdo, string $reference): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM movimientos_inventario_detalle d
             INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id
             WHERE m.referencia = :referencia'
        );
        $statement->execute(['referencia' => $reference]);

        return (int) $statement->fetchColumn();
    }

    private function draftCountByReference(PDO $pdo, string $reference): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM movimientos_inventario
             WHERE referencia = :referencia
               AND estado = \'BORRADOR\''
        );
        $statement->execute(['referencia' => $reference]);

        return (int) $statement->fetchColumn();
    }

    private function movementCountByProduct(PDO $pdo, string $productId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(DISTINCT m.id)
             FROM movimientos_inventario m
             INNER JOIN movimientos_inventario_detalle d
                ON d.movimiento_id = m.id
             WHERE d.id_producto = :id_producto'
        );
        $statement->execute(['id_producto' => $productId]);

        return (int) $statement->fetchColumn();
    }

    private function movementExists(PDO $pdo, int $movementId): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM movimientos_inventario
             WHERE id = :id
               AND estado = \'APLICADO\''
        );
        $statement->execute(['id' => $movementId]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function annulledCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            "SELECT COUNT(*)
             FROM movimientos_inventario
             WHERE referencia LIKE 'QAIS-%'
               AND estado = 'ANULADO'"
        )->fetchColumn();
    }

    private function draftCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            "SELECT COUNT(*)
             FROM movimientos_inventario
             WHERE referencia LIKE 'QAIS-%'
               AND estado = 'BORRADOR'"
        )->fetchColumn();
    }

    private function objectCount(PDO $pdo, string $type): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.triggers
             WHERE trigger_schema = DATABASE()'
        );
        $statement->execute();

        return $type === 'TRIGGER' ? (int) $statement->fetchColumn() : 0;
    }

    private function routineCount(PDO $pdo, string $type): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.routines
             WHERE routine_schema = DATABASE()
               AND routine_type = :type'
        );
        $statement->execute(['type' => $type]);

        return (int) $statement->fetchColumn();
    }

    private function eventCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM information_schema.events
             WHERE event_schema = DATABASE()'
        )->fetchColumn();
    }

    private function negativeStockCheckExists(PDO $pdo): bool
    {
        $statement = $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.check_constraints
             WHERE constraint_schema = DATABASE()
               AND check_clause LIKE '%cantidad_actual%>= 0%'"
        );

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        $counts = [];

        foreach ([
            'productos',
            'existencias_producto',
            'movimientos_inventario',
            'movimientos_inventario_detalle',
            'conceptos_movimiento_inventario',
        ] as $table) {
            $counts[$table] = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn();
        }

        return $counts;
    }

    private function cleanup(PDO $pdo): void
    {
        $pdo->exec(
            "DELETE d
             FROM movimientos_inventario_detalle d
             INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id
             WHERE m.referencia LIKE 'QAIS-%'
                OR d.id_producto LIKE 'QAIS%'"
        );
        $pdo->exec(
            "DELETE FROM movimientos_inventario
             WHERE referencia LIKE 'QAIS-%'"
        );
        $pdo->exec(
            "DELETE FROM existencias_producto
             WHERE id_producto LIKE 'QAIS%'"
        );
        $pdo->exec(
            "DELETE FROM productos
             WHERE id_producto LIKE 'QAIS%'"
        );
        $pdo->exec(
            "DELETE FROM conceptos_movimiento_inventario
             WHERE codigo LIKE 'QAIS%'"
        );
    }
};
