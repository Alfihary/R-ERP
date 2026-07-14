<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryTransferService;
use App\Domain\Inventory\InventoryValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\InventoryRepository;

return new class implements DatabaseTest {
    private const PRODUCT_IDS = [
        'QATRFA',
        'QATRFB',
        'QATRFC',
        'QATRFKIT',
        'QATRFSERV',
        'QATRFINACT',
        'QATRFDEC1',
        'QATRFDEC2',
        'QATRFDEC3',
        'QATRFDEC4',
        'QATRFROLLA',
        'QATRFROLLB',
    ];

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match TRANSFERENCIAS-1.'
            );
        }

        $this->cleanup($pdo);
        $before = $this->counts($pdo);
        $ids = $this->requiredIds($pdo);
        $ids['destino_id'] = $this->createWarehouse(
            $pdo,
            $ids['empresa_id'],
            'qatrf-destino',
            'QA Transfer Destino',
            $ids['admin_id']
        );
        $other = $this->createOtherCompanyWarehouse($pdo, $ids);
        $this->createProducts($pdo, $ids);
        $service = $this->service();

        try {
            $seed = $this->seedEvidence($pdo);
            $positive = $this->positiveCases($pdo, $service, $ids);
            $negative = $this->negativeCases($pdo, $service, $ids, $other);
            $rollback = $this->criticalRollbackCase($pdo, $service, $ids);
            $precision = $this->precisionCases($pdo, $service, $ids);
            $history = $this->historyEvidence($pdo, $positive['simple_reference']);

            if ($this->draftCount($pdo) !== 0) {
                throw new RuntimeException(
                    'TRANSFERENCIAS-1 left residual draft movements.'
                );
            }
        } finally {
            $this->cleanup($pdo);
        }

        $after = $this->counts($pdo);

        if ($after !== $before) {
            throw new RuntimeException(
                'TRANSFERENCIAS-1 left persistent QA data.'
            );
        }

        return [
            'database' => $database,
            'seed' => $seed,
            'positive_cases' => $positive,
            'negative_cases' => $negative,
            'critical_rollback' => $rollback,
            'precision' => $precision,
            'history' => $history,
            'concurrency' => [
                'real_tested' => false,
                'covered_by_design' => [
                    'single_transaction',
                    'SELECT_FOR_UPDATE',
                    'product_order_by_id_producto',
                    'origin_then_destination_lock',
                    'db_decimal_arithmetic',
                ],
                'pending' => 'INVENTARIO-TRANSFER-CONCURRENCY-QA-1',
            ],
            'simulated_failure_after_exit' => [
                'executed' => false,
                'reason' =>
                    'No production-safe hook was added; rollback is covered by multipartida insufficient stock after draft creation and before commit.',
            ],
            'persistent_counts_before' => $before,
            'persistent_counts_after' => $after,
            'cleanup' => 'qa_rows_deleted',
        ];
    }

    private function service(): InventoryTransferService
    {
        $config = require BASE_PATH . '/bootstrap/database.php';
        $databaseConfig = $config->get('database', []);

        if (!is_array($databaseConfig)) {
            throw new RuntimeException('Database configuration must be an array.');
        }

        return new InventoryTransferService(
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
        $empresaId = (int) $pdo->query(
            "SELECT id FROM empresas WHERE nombre = 'Grupo Refrigerantes' LIMIT 1"
        )->fetchColumn();
        $almacenId = (int) $pdo->query(
            "SELECT id FROM almacenes WHERE nombre = 'Almacén Principal' LIMIT 1"
        )->fetchColumn();
        $unitId = (int) $pdo->query(
            "SELECT id FROM unidades_medida WHERE codigo = 'PIEZA' LIMIT 1"
        )->fetchColumn();
        $productTypeId = $this->typeId($pdo, 'PRODUCTO');
        $kitTypeId = $this->typeId($pdo, 'KIT');
        $serviceTypeId = $this->typeId($pdo, 'SERVICIO');

        foreach ([
            $adminId,
            $empresaId,
            $almacenId,
            $unitId,
            $productTypeId,
            $kitTypeId,
            $serviceTypeId,
        ] as $id) {
            if ($id < 1) {
                throw new RuntimeException(
                    'TRANSFERENCIAS-1 required structural IDs are missing.'
                );
            }
        }

        return [
            'admin_id' => $adminId,
            'empresa_id' => $empresaId,
            'origen_id' => $almacenId,
            'unidad_id' => $unitId,
            'producto_tipo_id' => $productTypeId,
            'kit_tipo_id' => $kitTypeId,
            'servicio_tipo_id' => $serviceTypeId,
        ];
    }

    private function typeId(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM tipos_producto
             WHERE codigo = :codigo AND activo = 1 AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, int> $ids
     */
    private function createProducts(PDO $pdo, array $ids): void
    {
        foreach ([
            ['QATRFA', 'Transfer QA A', $ids['producto_tipo_id'], 1],
            ['QATRFB', 'Transfer QA B', $ids['producto_tipo_id'], 1],
            ['QATRFC', 'Transfer QA C', $ids['producto_tipo_id'], 1],
            ['QATRFKIT', 'Transfer QA KIT', $ids['kit_tipo_id'], 1],
            ['QATRFSERV', 'Transfer QA SERV', $ids['servicio_tipo_id'], 1],
            ['QATRFINACT', 'Transfer QA OFF', $ids['producto_tipo_id'], 0],
            ['QATRFDEC1', 'Transfer QA DEC1', $ids['producto_tipo_id'], 1],
            ['QATRFDEC2', 'Transfer QA DEC2', $ids['producto_tipo_id'], 1],
            ['QATRFDEC3', 'Transfer QA DEC3', $ids['producto_tipo_id'], 1],
            ['QATRFDEC4', 'Transfer QA DEC4', $ids['producto_tipo_id'], 1],
            ['QATRFROLLA', 'Transfer QA ROLLA', $ids['producto_tipo_id'], 1],
            ['QATRFROLLB', 'Transfer QA ROLLB', $ids['producto_tipo_id'], 1],
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
     * @return array<string, mixed>
     */
    private function seedEvidence(PDO $pdo): array
    {
        $statement = $pdo->query(
            "SELECT codigo, naturaleza, activo
             FROM conceptos_movimiento_inventario
             WHERE codigo IN ('TRANSFERENCIA_SALIDA', 'TRANSFERENCIA_ENTRADA')
             ORDER BY codigo"
        );
        $rows = $statement->fetchAll();

        return [
            'concepts_total' => count($rows),
            'active_total' => count(array_filter(
                $rows,
                static fn (array $row): bool => (int) $row['activo'] === 1
            )),
            'salida_nature' => $this->conceptNature($rows, 'TRANSFERENCIA_SALIDA'),
            'entrada_nature' => $this->conceptNature($rows, 'TRANSFERENCIA_ENTRADA'),
            'duplicate_codes' => $this->duplicateConceptCodes($pdo),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function conceptNature(array $rows, string $code): string
    {
        foreach ($rows as $row) {
            if ((string) $row['codigo'] === $code) {
                return (string) $row['naturaleza'];
            }
        }

        return '';
    }

    private function duplicateConceptCodes(PDO $pdo): int
    {
        return (int) $pdo->query(
            "SELECT COUNT(*)
             FROM (
                SELECT codigo
                FROM conceptos_movimiento_inventario
                WHERE codigo IN ('TRANSFERENCIA_SALIDA', 'TRANSFERENCIA_ENTRADA')
                GROUP BY codigo
                HAVING COUNT(*) > 1
             ) duplicates"
        )->fetchColumn();
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function positiveCases(
        PDO $pdo,
        InventoryTransferService $service,
        array $ids
    ): array {
        $this->setBalance($pdo, $ids['origen_id'], 'QATRFA', '10.000000');
        $simple = $service->transferir($this->input($ids, [
            ['QATRFA', '3.000000'],
        ], 'TRF-QA-SIMPLE'));
        $simpleOriginBalance = $this->balance($pdo, $ids['origen_id'], 'QATRFA');
        $simpleDestinationBalance = $this->balance(
            $pdo,
            $ids['destino_id'],
            'QATRFA'
        );

        $this->setBalance($pdo, $ids['origen_id'], 'QATRFA', '10.000000');
        $this->setBalance($pdo, $ids['destino_id'], 'QATRFA', '0.000000');
        $this->setBalance($pdo, $ids['origen_id'], 'QATRFB', '5.000000');
        $this->setBalance($pdo, $ids['destino_id'], 'QATRFB', '0.000000');
        $multipart = $service->transferir($this->input($ids, [
            ['QATRFA', '2.000000'],
            ['QATRFB', '1.500000'],
        ], 'TRF-QA-MULTI'));

        $this->setBalance($pdo, $ids['origen_id'], 'QATRFC', '0.000001');
        $minimum = $service->transferir($this->input($ids, [
            ['QATRFC', '0.000001'],
        ], 'TRF-QA-MIN'));

        $this->setBalance($pdo, $ids['origen_id'], 'QATRFKIT', '4.000000');
        $kit = $service->transferir($this->input($ids, [
            ['QATRFKIT', '1.000000'],
        ], 'TRF-QA-KIT'));

        return [
            'simple_reference' => $simple['referencia_transferencia'],
            'simple_origin_balance' => $simpleOriginBalance,
            'simple_destination_balance' => $simpleDestinationBalance,
            'simple_exit_applied' =>
                $this->movementState($pdo, (int) $simple['movimiento_salida_id']),
            'simple_entry_applied' =>
                $this->movementState($pdo, (int) $simple['movimiento_entrada_id']),
            'same_reference' =>
                $this->sameReference(
                    $pdo,
                    (int) $simple['movimiento_salida_id'],
                    (int) $simple['movimiento_entrada_id']
                ),
            'simple_exit_parts' =>
                $this->detailCountByMovement(
                    $pdo,
                    (int) $simple['movimiento_salida_id']
                ),
            'simple_entry_parts' =>
                $this->detailCountByMovement(
                    $pdo,
                    (int) $simple['movimiento_entrada_id']
                ),
            'multipart_origin_a' =>
                $this->balance($pdo, $ids['origen_id'], 'QATRFA'),
            'multipart_destination_a' =>
                $this->balance($pdo, $ids['destino_id'], 'QATRFA'),
            'multipart_origin_b' =>
                $this->balance($pdo, $ids['origen_id'], 'QATRFB'),
            'multipart_destination_b' =>
                $this->balance($pdo, $ids['destino_id'], 'QATRFB'),
            'multipart_parts' => count($multipart['partidas_transferidas']),
            'minimum_quantity' =>
                $minimum['partidas_transferidas'][0]['cantidad'] === '0.000001',
            'kit_as_identity' =>
                $kit['partidas_transferidas'][0]['id_producto'] === 'QATRFKIT',
        ];
    }

    /**
     * @param array<string, int> $ids
     * @param array<string, int> $other
     * @return array<string, bool>
     */
    private function negativeCases(
        PDO $pdo,
        InventoryTransferService $service,
        array $ids,
        array $other
    ): array {
        $this->setBalance($pdo, $ids['origen_id'], 'QATRFA', '10.000000');
        $conceptId = (int) $pdo->query(
            "SELECT id FROM conceptos_movimiento_inventario
             WHERE codigo = 'TRANSFERENCIA_SALIDA'"
        )->fetchColumn();
        $pdo->exec(
            "UPDATE conceptos_movimiento_inventario
             SET activo = 0
             WHERE codigo = 'TRANSFERENCIA_SALIDA'"
        );

        $cases = [
            'empresa_inexistente' => fn () => $service->transferir(
                $this->input(array_replace($ids, ['empresa_id' => 999999999]), [
                    ['QATRFA', '1.000000'],
                ], 'TRF-QA-NEG-EMP')
            ),
            'almacen_origen_inexistente' => fn () => $service->transferir(
                $this->input(array_replace($ids, ['origen_id' => 999999999]), [
                    ['QATRFA', '1.000000'],
                ], 'TRF-QA-NEG-ORI404')
            ),
            'almacen_destino_inexistente' => fn () => $service->transferir(
                $this->input(array_replace($ids, ['destino_id' => 999999999]), [
                    ['QATRFA', '1.000000'],
                ], 'TRF-QA-NEG-DES404')
            ),
            'origen_otra_empresa' => fn () => $service->transferir(
                $this->input(array_replace($ids, ['origen_id' => $other['almacen_id']]), [
                    ['QATRFA', '1.000000'],
                ], 'TRF-QA-NEG-ORIOTHER')
            ),
            'destino_otra_empresa' => fn () => $service->transferir(
                $this->input(array_replace($ids, ['destino_id' => $other['almacen_id']]), [
                    ['QATRFA', '1.000000'],
                ], 'TRF-QA-NEG-DESOTHER')
            ),
            'origen_igual_destino' => fn () => $service->transferir(
                $this->input(array_replace($ids, ['destino_id' => $ids['origen_id']]), [
                    ['QATRFA', '1.000000'],
                ], 'TRF-QA-NEG-SAME')
            ),
            'concepto_inactivo' => fn () => $service->transferir(
                $this->input($ids, [['QATRFA', '1.000000']], 'TRF-QA-NEG-CONCEPT')
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

        foreach ([
            'producto_inexistente' => [['QA404TRF', '1.000000']],
            'producto_inactivo' => [['QATRFINACT', '1.000000']],
            'servicio' => [['QATRFSERV', '1.000000']],
            'partidas_vacias' => [],
            'producto_duplicado' => [
                ['QATRFA', '1.000000'],
                ['QATRFA', '2.000000'],
            ],
            'cantidad_cero' => [['QATRFA', '0.000000']],
            'cantidad_negativa' => [['QATRFA', '-1.000000']],
            'mas_de_6_decimales' => [['QATRFA', '1.0000001']],
            'cantidad_fuera_de_rango' => [['QATRFA', '1000000000000.000000']],
        ] as $label => $parts) {
            $results[$label] = $this->failsWithDomainError(
                fn () => $service->transferir(
                    $this->input($ids, $parts, 'TRF-QA-NEG-' . strtoupper($label))
                )
            );
        }

        $results['saldo_insuficiente'] = $this->failsWithDomainError(
            fn () => $service->transferir(
                $this->input($ids, [['QATRFA', '999.000000']], 'TRF-QA-NEG-STOCK')
            )
        );
        $results['usuario_inexistente'] = $this->failsWithDomainError(
            fn () => $service->transferir(
                $this->input(array_replace($ids, ['admin_id' => 999999999]), [
                    ['QATRFA', '1.000000'],
                ], 'TRF-QA-NEG-USER')
            )
        );

        return $results;
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function criticalRollbackCase(
        PDO $pdo,
        InventoryTransferService $service,
        array $ids
    ): array {
        $this->setBalance($pdo, $ids['origen_id'], 'QATRFROLLA', '10.000000');
        $this->setBalance($pdo, $ids['destino_id'], 'QATRFROLLA', '0.000000');
        $this->setBalance($pdo, $ids['origen_id'], 'QATRFROLLB', '1.000000');
        $this->setBalance($pdo, $ids['destino_id'], 'QATRFROLLB', '0.000000');

        $failed = $this->failsWithDomainError(
            fn () => $service->transferir($this->input($ids, [
                ['QATRFROLLA', '2.000000'],
                ['QATRFROLLB', '5.000000'],
            ], 'TRF-QA-ROLLBACK'))
        );

        return [
            'rejected' => $failed,
            'a_origin' => $this->balance($pdo, $ids['origen_id'], 'QATRFROLLA'),
            'a_destination' =>
                $this->balance($pdo, $ids['destino_id'], 'QATRFROLLA'),
            'b_origin' => $this->balance($pdo, $ids['origen_id'], 'QATRFROLLB'),
            'b_destination' =>
                $this->balance($pdo, $ids['destino_id'], 'QATRFROLLB'),
            'persistent_movements' =>
                $this->movementCountByReference($pdo, 'TRF-QA-ROLLBACK'),
            'persistent_details' =>
                $this->detailCountByReference($pdo, 'TRF-QA-ROLLBACK'),
            'residual_drafts' =>
                $this->draftCountByReference($pdo, 'TRF-QA-ROLLBACK'),
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function precisionCases(
        PDO $pdo,
        InventoryTransferService $service,
        array $ids
    ): array {
        $results = [];

        foreach ([
            'QATRFDEC1' => '0.000001',
            'QATRFDEC2' => '1.000000',
            'QATRFDEC3' => '123456789.123456',
        ] as $productId => $quantity) {
            $this->setBalance($pdo, $ids['origen_id'], $productId, $quantity);
            $service->transferir($this->input($ids, [
                [$productId, $quantity],
            ], 'TRF-QA-DEC-' . $productId));
            $results[$quantity] = $this->balance(
                $pdo,
                $ids['destino_id'],
                $productId
            );
        }

        $this->setBalance($pdo, $ids['origen_id'], 'QATRFDEC4', '0.300000');
        $service->transferir($this->input($ids, [
            ['QATRFDEC4', '0.100000'],
        ], 'TRF-QA-DEC-SUM1'));
        $service->transferir($this->input($ids, [
            ['QATRFDEC4', '0.200000'],
        ], 'TRF-QA-DEC-SUM2'));

        return [
            'exact_values' => $results,
            'accumulation_0_1_plus_0_2' =>
                $this->balance($pdo, $ids['destino_id'], 'QATRFDEC4'),
            'assertions_use_strings' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyEvidence(PDO $pdo, string $reference): array
    {
        $statement = $pdo->prepare(
            "SELECT c.codigo, c.naturaleza, m.estado, m.referencia
             FROM movimientos_inventario m
             INNER JOIN conceptos_movimiento_inventario c
                ON c.id = m.concepto_movimiento_id
             WHERE m.referencia = :referencia
             ORDER BY c.naturaleza DESC"
        );
        $statement->execute(['referencia' => $reference]);
        $rows = $statement->fetchAll();

        return [
            'movements' => count($rows),
            'codes' => array_column($rows, 'codigo'),
            'states' => array_column($rows, 'estado'),
            'same_reference' => count(array_unique(array_column($rows, 'referencia'))) === 1,
        ];
    }

    private function createWarehouse(
        PDO $pdo,
        int $companyId,
        string $code,
        string $name,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO almacenes (
                empresa_id,
                codigo,
                nombre,
                activo,
                creado_por
             )
             VALUES (
                :empresa_id,
                :codigo,
                :nombre,
                1,
                :creado_por
             )'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, int>
     */
    private function createOtherCompanyWarehouse(PDO $pdo, array $ids): array
    {
        $statement = $pdo->prepare(
            "INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES ('qatrf-other', 'QA Transfer Otra Empresa', 1, :creado_por)"
        );
        $statement->execute(['creado_por' => $ids['admin_id']]);
        $companyId = (int) $pdo->lastInsertId();
        $warehouseId = $this->createWarehouse(
            $pdo,
            $companyId,
            'qatrf-other-wh',
            'QA Transfer Otro Almacén',
            $ids['admin_id']
        );

        return ['empresa_id' => $companyId, 'almacen_id' => $warehouseId];
    }

    /**
     * @param array<string, int> $ids
     * @param list<array{0: string, 1: string}> $parts
     * @return array<string, mixed>
     */
    private function input(array $ids, array $parts, string $reference): array
    {
        return [
            'empresa_id' => $ids['empresa_id'],
            'almacen_origen_id' => $ids['origen_id'],
            'almacen_destino_id' => $ids['destino_id'],
            'fecha_movimiento' => '2026-07-14 10:30:00',
            'referencia' => $reference,
            'observaciones' => 'QA TRANSFERENCIAS 1',
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

    private function balance(PDO $pdo, int $warehouseId, string $productId): string
    {
        $statement = $pdo->prepare(
            'SELECT cantidad_actual
             FROM existencias_producto
             WHERE almacen_id = :almacen_id
               AND id_producto = :id_producto
             LIMIT 1'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
        ]);
        $value = $statement->fetchColumn();

        return $value === false ? '0.000000' : (string) $value;
    }

    private function movementState(PDO $pdo, int $movementId): string
    {
        $statement = $pdo->prepare(
            'SELECT estado FROM movimientos_inventario WHERE id = :id'
        );
        $statement->execute(['id' => $movementId]);

        return (string) $statement->fetchColumn();
    }

    private function sameReference(PDO $pdo, int $exitId, int $entryId): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(DISTINCT referencia)
             FROM movimientos_inventario
             WHERE id IN (:exit_id, :entry_id)'
        );
        $statement->execute(['exit_id' => $exitId, 'entry_id' => $entryId]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function detailCountByMovement(PDO $pdo, int $movementId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM movimientos_inventario_detalle
             WHERE movimiento_id = :movimiento_id'
        );
        $statement->execute(['movimiento_id' => $movementId]);

        return (int) $statement->fetchColumn();
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
            "SELECT COUNT(*)
             FROM movimientos_inventario
             WHERE referencia = :referencia
               AND estado = 'BORRADOR'"
        );
        $statement->execute(['referencia' => $reference]);

        return (int) $statement->fetchColumn();
    }

    private function draftCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            "SELECT COUNT(*)
             FROM movimientos_inventario
             WHERE referencia LIKE 'TRF-QA-%'
               AND estado = 'BORRADOR'"
        )->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        $counts = [];

        foreach ([
            'empresas',
            'almacenes',
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
             WHERE m.referencia LIKE 'TRF-QA-%'
                OR d.id_producto LIKE 'QATRF%'"
        );
        $pdo->exec(
            "DELETE FROM movimientos_inventario
             WHERE referencia LIKE 'TRF-QA-%'"
        );
        $pdo->exec(
            "DELETE FROM existencias_producto
             WHERE id_producto LIKE 'QATRF%'"
        );
        $pdo->exec(
            "DELETE FROM producto_codigos_barras
             WHERE id_producto LIKE 'QATRF%'"
        );
        $pdo->exec(
            "DELETE FROM producto_impuestos
             WHERE id_producto LIKE 'QATRF%'"
        );
        $pdo->exec(
            "DELETE FROM producto_documentos
             WHERE id_producto LIKE 'QATRF%'"
        );
        $pdo->exec(
            "DELETE FROM productos
             WHERE id_producto LIKE 'QATRF%'"
        );
        $pdo->exec(
            "DELETE FROM usuario_almacenes
             WHERE almacen_id IN (
                SELECT id FROM almacenes WHERE codigo LIKE 'qatrf-%'
             )"
        );
        $pdo->exec(
            "DELETE FROM almacenes
             WHERE codigo LIKE 'qatrf-%'"
        );
        $pdo->exec(
            "DELETE FROM usuario_empresas
             WHERE empresa_id IN (
                SELECT id FROM empresas WHERE codigo LIKE 'qatrf-%'
             )"
        );
        $pdo->exec(
            "DELETE FROM empresas
             WHERE codigo LIKE 'qatrf-%'"
        );
    }
};
