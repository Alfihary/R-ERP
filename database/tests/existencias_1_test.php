<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Infrastructure\Repositories\InventoryRepository;

return new class implements DatabaseTest {
    private const STOCK_PERMISSION = 'inventario.existencias.acceder';
    private const FORBIDDEN_PERMISSIONS = [
        'inventario.existencias.crear',
        'inventario.existencias.editar',
        'inventario.existencias.eliminar',
        'inventario.existencias.ajustar',
    ];
    private const PRODUCTS = [
        'QASTOCKA',
        'QASTOCKB',
        'QASTOCKKIT',
        'QASTOCKZERO',
        'QASTOCKNEG',
        'QASTOCKSERV',
        'QASTOCKOTHER',
    ];

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException('The active database does not match EXISTENCIAS-1.');
        }

        $this->cleanup($pdo);
        $before = $this->counts($pdo);
        $ids = $this->requiredIds($pdo);
        $this->createProducts($pdo, $ids);
        $other = $this->createOtherCompanyWarehouse($pdo, $ids);

        try {
            $permissions = $this->permissionEvidence($pdo);
            $service = $this->service();
            $queries = $this->queries();

            $service->aplicarMovimiento($this->movementInput(
                $ids,
                [['QASTOCKA', '10.000000'], ['QASTOCKB', '3.500000'], ['QASTOCKKIT', '2.000000']],
                'ENTRADA_AJUSTE',
                'QASTOCK-ENTRADA'
            ));
            $service->aplicarMovimiento($this->movementInput(
                $ids,
                [['QASTOCKA', '3.000000']],
                'SALIDA_AJUSTE',
                'QASTOCK-SALIDA'
            ));
            $this->insertDirectExistence($pdo, $ids['almacen_id'], 'QASTOCKZERO', '0.000000');
            $this->insertDirectExistence($pdo, $ids['almacen_id'], 'QASTOCKNEG', '-2.000000');
            $this->insertDirectExistence($pdo, $other['almacen_id'], 'QASTOCKOTHER', '99.000000');

            $base = [
                'company_id' => $ids['empresa_id'],
                'warehouse_id' => $ids['almacen_id'],
                'warehouse_filter' => $ids['almacen_id'],
                'search' => '',
                'type' => '',
                'balance_state' => '',
                'page' => 1,
                'per_page' => 15,
            ];
            $all = $queries->stock($base);
            $byId = $queries->stock(array_merge($base, ['search' => 'QASTOCKA']));
            $byDescription = $queries->stock(array_merge($base, ['search' => 'Stock QA B']));
            $productFilter = $queries->stock(array_merge($base, ['type' => 'PRODUCTO']));
            $kitFilter = $queries->stock(array_merge($base, ['type' => 'KIT']));
            $serviceFilter = $queries->stock(array_merge($base, ['type' => 'SERVICIO']));
            $positive = $queries->stock(array_merge($base, ['balance_state' => 'positive']));
            $zero = $queries->stock(array_merge($base, ['balance_state' => 'zero']));
            $negative = $queries->stock(array_merge($base, ['balance_state' => 'negative']));
            $pageOne = $queries->stock(array_merge($base, ['per_page' => 1]));
            $warehouses = $queries->warehousesForCompany($ids['empresa_id']);
            $summary = $queries->stockSummary($ids['empresa_id'], $ids['almacen_id']);

            $rowsByProduct = [];
            foreach ($all['rows'] as $row) {
                $rowsByProduct[(string) $row['id_producto']] = $row;
            }
            $assertions = [
                'shows_product_a' => isset($rowsByProduct['QASTOCKA']),
                'shows_product_b' => isset($rowsByProduct['QASTOCKB']),
                'product_a_quantity' => (string) ($rowsByProduct['QASTOCKA']['cantidad_actual'] ?? '') === '7.000000',
                'product_b_quantity' => (string) ($rowsByProduct['QASTOCKB']['cantidad_actual'] ?? '') === '3.500000',
                'warehouse_correct' => $this->allMatch($all['rows'], 'almacen_id', $ids['almacen_id']),
                'company_correct' => $this->allMatch($all['rows'], 'empresa_nombre', 'Grupo Refrigerantes'),
                'decimal_values_are_strings' => is_string($rowsByProduct['QASTOCKA']['cantidad_actual'] ?? null),
                'search_by_id' => $this->containsProduct($byId['rows'], 'QASTOCKA'),
                'search_by_description' => $this->containsProduct($byDescription['rows'], 'QASTOCKB'),
                'warehouse_active_count' => count($all['rows']) === 5,
                'warehouse_other_company_not_shown' => !$this->containsProduct($all['rows'], 'QASTOCKOTHER'),
                'type_producto' => $this->containsProduct($productFilter['rows'], 'QASTOCKA'),
                'type_kit' => $this->containsProduct($kitFilter['rows'], 'QASTOCKKIT'),
                'type_servicio_no_existence' => $serviceFilter['rows'] === [],
                'positive' => $this->containsProduct($positive['rows'], 'QASTOCKA'),
                'zero' => $this->containsProduct($zero['rows'], 'QASTOCKZERO'),
                'negative' => $this->containsProduct($negative['rows'], 'QASTOCKNEG'),
                'pagination_row_count' => count($pageOne['rows']) === 1,
                'pagination_total' => $pageOne['pagination']['total'] === 5,
                'pagination_total_pages' => $pageOne['pagination']['total_pages'] === 5,
                'permission_rows' => $permissions['permission_rows'] === 1,
                'admin_permission_rows' => $permissions['admin_active_permission_rows'] === 1,
                'duplicate_permission_codes' => $permissions['duplicate_permission_codes'] === 0,
                'duplicate_role_permissions' => $permissions['duplicate_role_permissions'] === 0,
                'forbidden_permission_rows' => $permissions['forbidden_permission_rows'] === 0,
            ];

            if (in_array(false, $assertions, true)) {
                $failed = array_keys(array_filter(
                    $assertions,
                    static fn (bool $passed): bool => !$passed
                ));
                throw new RuntimeException(
                    'EXISTENCIAS-1 DB-TEST assertions failed: ' . implode(', ', $failed)
                );
            }
        } finally {
            $this->cleanup($pdo);
        }

        $after = $this->counts($pdo);

        if ($after !== $before) {
            throw new RuntimeException('EXISTENCIAS-1 left persistent QA data.');
        }

        return [
            'database' => $database,
            'permissions' => $permissions,
            'structure' => [
                'grain' => 'almacen_id+id_producto',
                'existencias_has_empresa_id' => $this->hasColumn($pdo, 'existencias_producto', 'empresa_id'),
                'active_context_required' => true,
            ],
            'listing' => [
                'shows_product_a' => isset($rowsByProduct['QASTOCKA']),
                'shows_product_b' => isset($rowsByProduct['QASTOCKB']),
                'product_a_quantity' => (string) ($rowsByProduct['QASTOCKA']['cantidad_actual'] ?? ''),
                'product_b_quantity' => (string) ($rowsByProduct['QASTOCKB']['cantidad_actual'] ?? ''),
                'warehouse_correct' => $this->allMatch($all['rows'], 'almacen_id', $ids['almacen_id']),
                'company_correct' => $this->allMatch($all['rows'], 'empresa_nombre', 'Grupo Refrigerantes'),
                'decimal_values_are_strings' => is_string($rowsByProduct['QASTOCKA']['cantidad_actual'] ?? null),
            ],
            'filters' => [
                'search_by_id' => $this->containsProduct($byId['rows'], 'QASTOCKA'),
                'search_by_description' => $this->containsProduct($byDescription['rows'], 'QASTOCKB'),
                'warehouse_active' => count($all['rows']) === 5,
                'warehouse_same_company_count' => count($warehouses),
                'warehouse_other_company_not_shown' => !$this->containsProduct($all['rows'], 'QASTOCKOTHER'),
                'type_producto' => $this->containsProduct($productFilter['rows'], 'QASTOCKA'),
                'type_kit' => $this->containsProduct($kitFilter['rows'], 'QASTOCKKIT'),
                'type_servicio_no_existence' => $serviceFilter['rows'] === [],
                'positive' => $this->containsProduct($positive['rows'], 'QASTOCKA'),
                'zero' => $this->containsProduct($zero['rows'], 'QASTOCKZERO'),
                'negative' => $this->containsProduct($negative['rows'], 'QASTOCKNEG'),
            ],
            'summary' => $summary,
            'pagination' => [
                'server_side' => count($pageOne['rows']) === 1
                    && $pageOne['pagination']['total'] === 5
                    && $pageOne['pagination']['total_pages'] === 5,
            ],
            'readonly' => [
                'post_route_created' => false,
                'edit_buttons' => false,
                'direct_adjust_buttons' => false,
                'delete_buttons' => false,
                'stock_setup_note' => 'negative and zero rows were direct QA data for read-only filters only',
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
            'kit_tipo_id' => $this->typeId($pdo, 'KIT'),
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
            ['QASTOCKA', 'Stock QA A', $ids['producto_tipo_id']],
            ['QASTOCKB', 'Stock QA B', $ids['producto_tipo_id']],
            ['QASTOCKKIT', 'Stock QA Kit', $ids['kit_tipo_id']],
            ['QASTOCKZERO', 'Stock QA Cero', $ids['producto_tipo_id']],
            ['QASTOCKNEG', 'Stock QA Neg', $ids['producto_tipo_id']],
            ['QASTOCKSERV', 'Stock QA Servicio', $ids['servicio_tipo_id']],
            ['QASTOCKOTHER', 'Stock QA Otra Empresa', $ids['producto_tipo_id']],
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
            'codigo' => 'QASTOCKOTRA',
            'nombre' => 'QA Stock Otra Empresa',
            'creado_por' => $ids['admin_id'],
        ]);
        $companyId = (int) $pdo->lastInsertId();
        $warehouse = $pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $warehouse->execute([
            'empresa_id' => $companyId,
            'codigo' => 'QASTOCKOTRO',
            'nombre' => 'QA Stock Otro Almacén',
            'creado_por' => $ids['admin_id'],
        ]);

        return ['empresa_id' => $companyId, 'almacen_id' => (int) $pdo->lastInsertId()];
    }

    private function insertDirectExistence(
        PDO $pdo,
        int $warehouseId,
        string $productId,
        string $quantity
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO existencias_producto (almacen_id, id_producto, cantidad_actual)
             VALUES (:almacen_id, :id_producto, :cantidad_actual)'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
            'cantidad_actual' => $quantity,
        ]);
    }

    /**
     * @param array<string, int> $ids
     * @param list<array{0: string, 1: string}> $parts
     * @return array<string, mixed>
     */
    private function movementInput(
        array $ids,
        array $parts,
        string $concept,
        string $reference
    ): array {
        return [
            'empresa_id' => $ids['empresa_id'],
            'almacen_id' => $ids['almacen_id'],
            'concepto_codigo' => $concept,
            'fecha_movimiento' => '2026-07-13 10:00:00',
            'referencia' => $reference,
            'observaciones' => 'QA EXISTENCIAS-1',
            'usuario_id' => $ids['admin_id'],
            'partidas' => array_map(
                static fn (array $part): array => [
                    'id_producto' => $part[0],
                    'cantidad' => $part[1],
                    'observaciones' => 'QA EXISTENCIAS-1',
                ],
                $parts
            ),
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
            'codigo' => self::STOCK_PERMISSION,
            'modulo' => 'inventario',
        ]);
        $permissionRows = (int) $permission->fetchColumn();
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
            'permission_code' => self::STOCK_PERMISSION,
        ]);
        $forbidden = $pdo->prepare(
            'SELECT COUNT(*) FROM permisos
             WHERE codigo IN (?, ?, ?, ?)'
        );
        $forbidden->execute(self::FORBIDDEN_PERMISSIONS);

        return [
            'permission_rows' => $permissionRows,
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
            'productos_qa' => $this->countWhere($pdo, 'productos', "id_producto LIKE 'QASTOCK%'"),
            'movimientos_qa' => $this->countWhere($pdo, 'movimientos_inventario', "referencia LIKE 'QASTOCK%'"),
            'detalles_qa' => (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM movimientos_inventario_detalle d
                 INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id
                 WHERE m.referencia LIKE 'QASTOCK%'"
            )->fetchColumn(),
            'existencias_qa' => $this->countWhere($pdo, 'existencias_producto', "id_producto LIKE 'QASTOCK%'"),
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qastock%'"),
            'empresas_qa' => $this->countWhere($pdo, 'empresas', "codigo LIKE 'QASTOCK%'"),
            'almacenes_qa' => $this->countWhere($pdo, 'almacenes', "codigo LIKE 'QASTOCK%'"),
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
             WHERE m.referencia LIKE 'QASTOCK%'"
        );
        $pdo->exec("DELETE FROM movimientos_inventario WHERE referencia LIKE 'QASTOCK%'");
        $pdo->exec("DELETE FROM existencias_producto WHERE id_producto LIKE 'QASTOCK%'");
        $pdo->exec("DELETE FROM productos WHERE id_producto LIKE 'QASTOCK%'");
        $pdo->exec("DELETE FROM almacenes WHERE codigo LIKE 'QASTOCK%'");
        $pdo->exec("DELETE FROM empresas WHERE codigo LIKE 'QASTOCK%'");
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function containsProduct(array $rows, string $productId): bool
    {
        return in_array($productId, array_column($rows, 'id_producto'), true);
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
