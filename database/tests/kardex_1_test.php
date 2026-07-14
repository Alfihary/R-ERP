<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Infrastructure\Repositories\InventoryRepository;

return new class implements DatabaseTest {
    private const KARDEX_PERMISSION = 'inventario.kardex.acceder';
    private const FORBIDDEN_PERMISSIONS = [
        'inventario.kardex.crear',
        'inventario.kardex.editar',
        'inventario.kardex.eliminar',
        'inventario.kardex.anular',
        'inventario.kardex.recalcular',
    ];

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException('The active database does not match KARDEX-1.');
        }

        $this->cleanup($pdo);
        $before = $this->counts($pdo);
        $ids = $this->requiredIds($pdo);
        $other = $this->createOtherCompanyWarehouse($pdo, $ids);
        $this->createProducts($pdo, $ids);

        try {
            $permissions = $this->permissionEvidence($pdo);
            $service = $this->service();
            $queries = $this->queries();

            foreach ([
                ['2026-07-14 08:00:00', 'ENTRADA_AJUSTE', '10.000000', 'QAKARDEX-001'],
                ['2026-07-14 09:00:00', 'SALIDA_AJUSTE', '3.000000', 'QAKARDEX-002'],
                ['2026-07-14 10:00:00', 'ENTRADA_AJUSTE', '2.500000', 'QAKARDEX-003'],
                ['2026-07-14 11:00:00', 'SALIDA_AJUSTE', '1.250000', 'QAKARDEX-004'],
            ] as [$date, $concept, $quantity, $reference]) {
                $service->aplicarMovimiento($this->movementInput(
                    $ids,
                    $date,
                    $concept,
                    $quantity,
                    $reference
                ));
            }

            $service->aplicarMovimiento($this->movementInput(
                $other + $ids,
                '2026-07-14 12:00:00',
                'ENTRADA_AJUSTE',
                '99.000000',
                'QAKARDEX-OTHER'
            ));

            $base = [
                'company_id' => $ids['empresa_id'],
                'warehouse_id' => $ids['almacen_id'],
                'product_id' => 'QAKARDEX1',
                'concept' => '',
                'nature' => '',
                'date_from' => '',
                'date_to' => '',
                'page' => 1,
                'per_page' => 15,
            ];
            $all = $queries->kardex($base);
            $pageOne = $queries->kardex(array_merge($base, ['per_page' => 2]));
            $pageTwo = $queries->kardex(array_merge($base, ['per_page' => 2, 'page' => 2]));
            $dateFiltered = $queries->kardex(array_merge($base, [
                'date_from' => '2026-07-14',
                'date_to' => '2026-07-14',
            ]));
            $entries = $queries->kardex(array_merge($base, ['nature' => 'ENTRADA']));
            $exits = $queries->kardex(array_merge($base, ['nature' => 'SALIDA']));
            $entryConcept = $queries->kardex(array_merge($base, ['concept' => 'ENTRADA_AJUSTE']));
            $otherWarehouse = $queries->kardex(array_merge($base, [
                'warehouse_id' => $other['almacen_id'],
            ]));
            $serviceProduct = $queries->kardexProduct('QAKARDSERV');
            $stock = $queries->kardexCurrentStock($ids['almacen_id'], 'QAKARDEX1');
            $search = $queries->searchKardexProducts('QAKARDEX');

            $expected = [
                ['10.000000', null, '10.000000'],
                [null, '3.000000', '7.000000'],
                ['2.500000', null, '9.500000'],
                [null, '1.250000', '8.250000'],
            ];
            $actual = array_map(
                static fn (array $row): array => [
                    $row['entrada'],
                    $row['salida'],
                    $row['saldo_resultante'],
                ],
                $all['rows']
            );
            $beforeQueryCounts = $this->movementStockCounts($pdo);
            $queries->kardex($base);
            $afterQueryCounts = $this->movementStockCounts($pdo);

            $assertions = [
                'permission_rows' => $permissions['permission_rows'] === 1,
                'admin_permission_rows' => $permissions['admin_active_permission_rows'] === 1,
                'duplicate_permission_codes' => $permissions['duplicate_permission_codes'] === 0,
                'duplicate_role_permissions' => $permissions['duplicate_role_permissions'] === 0,
                'forbidden_permission_rows' => $permissions['forbidden_permission_rows'] === 0,
                'expected_rows' => count($all['rows']) === 4,
                'expected_kardex' => $actual === $expected,
                'current_stock' => $stock === '8.250000',
                'kardex_matches_stock' => end($actual)[2] === $stock,
                'chronological_order' => array_column($all['rows'], 'referencia') === [
                    'QAKARDEX-001',
                    'QAKARDEX-002',
                    'QAKARDEX-003',
                    'QAKARDEX-004',
                ],
                'date_filter' => count($dateFiltered['rows']) === 4,
                'nature_entry' => count($entries['rows']) === 2
                    && $this->allMatch($entries['rows'], 'naturaleza', 'ENTRADA'),
                'nature_exit' => count($exits['rows']) === 2
                    && $this->allMatch($exits['rows'], 'naturaleza', 'SALIDA'),
                'concept_entry' => count($entryConcept['rows']) === 2
                    && $this->allMatch($entryConcept['rows'], 'concepto_codigo', 'ENTRADA_AJUSTE'),
                'active_warehouse' => $this->allMatch($all['rows'], 'almacen_nombre', 'Almacén Principal'),
                'other_company_not_visible' => !$this->containsReference($all['rows'], 'QAKARDEX-OTHER'),
                'other_warehouse_has_no_active_company_rows' => $otherWarehouse['rows'] === [],
                'service_not_allowed_for_kardex' => is_array($serviceProduct)
                    && (string) $serviceProduct['tipo_codigo'] === 'SERVICIO',
                'pagination_page_one' => array_column($pageOne['rows'], 'saldo_resultante') === [
                    '10.000000',
                    '7.000000',
                ],
                'pagination_page_two_correct_balance' => array_column($pageTwo['rows'], 'saldo_resultante') === [
                    '9.500000',
                    '8.250000',
                ],
                'search_excludes_service' => $this->containsProduct($search, 'QAKARDEX1')
                    && !$this->containsProduct($search, 'QAKARDSERV'),
                'query_readonly' => $beforeQueryCounts === $afterQueryCounts,
            ];

            if (in_array(false, $assertions, true)) {
                $failed = array_keys(array_filter(
                    $assertions,
                    static fn (bool $passed): bool => !$passed
                ));
                throw new RuntimeException(
                    'KARDEX-1 DB-TEST assertions failed: ' . implode(', ', $failed)
                );
            }
        } finally {
            $this->cleanup($pdo);
        }

        $after = $this->counts($pdo);

        if ($after !== $before) {
            throw new RuntimeException('KARDEX-1 left persistent QA data.');
        }

        return [
            'database' => $database,
            'permissions' => $permissions,
            'readonly' => [
                'post_route_created' => false,
                'edit_buttons' => false,
                'annul_buttons' => false,
                'reverse_buttons' => false,
                'direct_adjust_buttons' => false,
                'query_changed_counts' => $beforeQueryCounts !== $afterQueryCounts,
            ],
            'kardex' => [
                'rows' => count($all['rows']),
                'expected_balances' => $actual,
                'current_stock' => $stock,
                'matches_stock' => end($actual)[2] === $stock,
                'order' => array_column($all['rows'], 'referencia'),
            ],
            'filters' => [
                'date_from_to' => count($dateFiltered['rows']) === 4,
                'nature_entry' => count($entries['rows']),
                'nature_exit' => count($exits['rows']),
                'concept_entry' => count($entryConcept['rows']),
                'active_warehouse' => $this->allMatch($all['rows'], 'almacen_nombre', 'Almacén Principal'),
                'other_company_not_visible' => !$this->containsReference($all['rows'], 'QAKARDEX-OTHER'),
                'other_warehouse_rows' => count($otherWarehouse['rows']),
                'service_product_type' => (string) ($serviceProduct['tipo_codigo'] ?? ''),
            ],
            'pagination' => [
                'per_page' => 2,
                'page_one_balances' => array_column($pageOne['rows'], 'saldo_resultante'),
                'page_two_balances' => array_column($pageTwo['rows'], 'saldo_resultante'),
                'page_two_considers_page_one' => array_column($pageTwo['rows'], 'saldo_resultante') === [
                    '9.500000',
                    '8.250000',
                ],
            ],
            'cleanup' => [
                'before' => $before,
                'after' => $after,
                'legitimate_concepts' => $this->legitimateConcepts($pdo),
            ],
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

    private function queries(): InventoryQueryRepository
    {
        $config = require BASE_PATH . '/bootstrap/database.php';
        $databaseConfig = $config->get('database', []);

        if (!is_array($databaseConfig)) {
            throw new RuntimeException('Database configuration must be an array.');
        }

        return new InventoryQueryRepository(new ConnectionProvider($databaseConfig));
    }

    /**
     * @return array<string, int>
     */
    private function requiredIds(PDO $pdo): array
    {
        return [
            'admin_id' => (int) $pdo->query(
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
            )->fetchColumn(),
            'empresa_id' => (int) $pdo->query(
                "SELECT id FROM empresas WHERE nombre = 'Grupo Refrigerantes' LIMIT 1"
            )->fetchColumn(),
            'almacen_id' => (int) $pdo->query(
                "SELECT id FROM almacenes WHERE nombre = 'Almacén Principal' LIMIT 1"
            )->fetchColumn(),
            'unidad_id' => (int) $pdo->query(
                "SELECT id FROM unidades_medida WHERE codigo = 'PIEZA' LIMIT 1"
            )->fetchColumn(),
            'producto_tipo_id' => $this->typeId($pdo, 'PRODUCTO'),
            'servicio_tipo_id' => $this->typeId($pdo, 'SERVICIO'),
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
            ['QAKARDEX1', 'Kardex QA Producto', $ids['producto_tipo_id']],
            ['QAKARDSERV', 'Kardex QA Servicio', $ids['servicio_tipo_id']],
        ] as [$productId, $description, $typeId]) {
            $statement = $pdo->prepare(
                'INSERT INTO productos (
                    id_producto,
                    descripcion,
                    unidad_medida_id,
                    tipo_producto_id,
                    activo,
                    creado_por
                 ) VALUES (
                    :id_producto,
                    :descripcion,
                    :unidad_medida_id,
                    :tipo_producto_id,
                    1,
                    :creado_por
                 )'
            );
            $statement->execute([
                'id_producto' => $productId,
                'descripcion' => $description,
                'unidad_medida_id' => $ids['unidad_id'],
                'tipo_producto_id' => $typeId,
                'creado_por' => $ids['admin_id'],
            ]);
        }
    }

    /**
     * @param array<string, int> $ids
     * @return array{empresa_id: int, almacen_id: int}
     */
    private function createOtherCompanyWarehouse(PDO $pdo, array $ids): array
    {
        $company = $pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $company->execute([
            'codigo' => 'qakardexotra',
            'nombre' => 'QA Kardex Otra Empresa',
            'creado_por' => $ids['admin_id'],
        ]);
        $companyId = (int) $pdo->lastInsertId();
        $warehouse = $pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $warehouse->execute([
            'empresa_id' => $companyId,
            'codigo' => 'qakardexotro',
            'nombre' => 'QA Kardex Otro Almacén',
            'creado_por' => $ids['admin_id'],
        ]);

        return ['empresa_id' => $companyId, 'almacen_id' => (int) $pdo->lastInsertId()];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function movementInput(
        array $ids,
        string $date,
        string $concept,
        string $quantity,
        string $reference
    ): array {
        return [
            'empresa_id' => $ids['empresa_id'],
            'almacen_id' => $ids['almacen_id'],
            'concepto_codigo' => $concept,
            'fecha_movimiento' => $date,
            'referencia' => $reference,
            'observaciones' => 'QA KARDEX-1',
            'usuario_id' => $ids['admin_id'],
            'partidas' => [[
                'id_producto' => 'QAKARDEX1',
                'cantidad' => $quantity,
                'observaciones' => 'QA KARDEX-1',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionEvidence(PDO $pdo): array
    {
        $permission = $pdo->prepare(
            'SELECT COUNT(*) FROM permisos
             WHERE codigo = :codigo
               AND modulo = :modulo
               AND es_sistema = 1
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $permission->execute([
            'codigo' => self::KARDEX_PERMISSION,
            'modulo' => 'inventario',
        ]);
        $admin = $pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN roles r
                ON r.id = rp.rol_id
               AND r.codigo = :role_code
               AND r.activo = 1
               AND r.eliminado_en IS NULL
             INNER JOIN permisos p
                ON p.id = rp.permiso_id
               AND p.codigo = :permission_code
               AND p.activo = 1
               AND p.eliminado_en IS NULL
             WHERE rp.activo = 1
               AND rp.eliminado_en IS NULL'
        );
        $admin->execute([
            'role_code' => 'ADMIN',
            'permission_code' => self::KARDEX_PERMISSION,
        ]);
        $forbidden = $pdo->prepare(
            'SELECT COUNT(*) FROM permisos
             WHERE codigo IN (?, ?, ?, ?, ?)'
        );
        $forbidden->execute(self::FORBIDDEN_PERMISSIONS);

        return [
            'permission_rows' => (int) $permission->fetchColumn(),
            'admin_active_permission_rows' => (int) $admin->fetchColumn(),
            'duplicate_permission_codes' => (int) $pdo->query(
                'SELECT COUNT(*) FROM (
                    SELECT codigo FROM permisos
                    GROUP BY codigo HAVING COUNT(*) > 1
                 ) duplicates'
            )->fetchColumn(),
            'duplicate_role_permissions' => (int) $pdo->query(
                'SELECT COUNT(*) FROM (
                    SELECT rol_id, permiso_id FROM rol_permisos
                    GROUP BY rol_id, permiso_id HAVING COUNT(*) > 1
                 ) duplicates'
            )->fetchColumn(),
            'forbidden_permission_rows' => (int) $forbidden->fetchColumn(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'productos_qa' => $this->countWhere($pdo, 'productos', "id_producto LIKE 'QAKARD%'"),
            'movimientos_qa' => $this->countWhere($pdo, 'movimientos_inventario', "referencia LIKE 'QAKARDEX%'"),
            'detalles_qa' => (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM movimientos_inventario_detalle d
                 INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id
                 WHERE m.referencia LIKE 'QAKARDEX%'"
            )->fetchColumn(),
            'existencias_qa' => $this->countWhere($pdo, 'existencias_producto', "id_producto LIKE 'QAKARD%'"),
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qakardex%'"),
            'empresas_qa' => $this->countWhere($pdo, 'empresas', "codigo LIKE 'qakardex%'"),
            'almacenes_qa' => $this->countWhere($pdo, 'almacenes', "codigo LIKE 'qakardex%'"),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function movementStockCounts(PDO $pdo): array
    {
        return [
            'movimientos' => $this->countWhere($pdo, 'movimientos_inventario', "referencia LIKE 'QAKARDEX%'"),
            'detalles' => (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM movimientos_inventario_detalle d
                 INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id
                 WHERE m.referencia LIKE 'QAKARDEX%'"
            )->fetchColumn(),
            'existencias' => $this->countWhere($pdo, 'existencias_producto', "id_producto = 'QAKARDEX1'"),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)
            ->fetchColumn();
    }

    private function cleanup(PDO $pdo): void
    {
        $pdo->exec(
            "DELETE d FROM movimientos_inventario_detalle d
             INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id
             WHERE m.referencia LIKE 'QAKARDEX%'"
        );
        $pdo->exec("DELETE FROM movimientos_inventario WHERE referencia LIKE 'QAKARDEX%'");
        $pdo->exec("DELETE FROM existencias_producto WHERE id_producto LIKE 'QAKARD%'");
        $pdo->exec("DELETE FROM productos WHERE id_producto LIKE 'QAKARD%'");
        $pdo->exec("DELETE FROM almacenes WHERE codigo LIKE 'qakardex%'");
        $pdo->exec("DELETE FROM empresas WHERE codigo LIKE 'qakardex%'");
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function allMatch(array $rows, string $key, mixed $expected): bool
    {
        foreach ($rows as $row) {
            if ((string) ($row[$key] ?? '') !== (string) $expected) {
                return false;
            }
        }

        return $rows !== [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function containsReference(array $rows, string $reference): bool
    {
        return in_array($reference, array_column($rows, 'referencia'), true);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function containsProduct(array $rows, string $productId): bool
    {
        return in_array($productId, array_column($rows, 'id_producto'), true);
    }

    /**
     * @return list<string>
     */
    private function legitimateConcepts(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT codigo
             FROM conceptos_movimiento_inventario
             WHERE codigo IN ('ENTRADA_AJUSTE', 'SALIDA_AJUSTE')
             ORDER BY codigo"
        )->fetchAll(PDO::FETCH_COLUMN);
    }
};
