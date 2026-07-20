<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Infrastructure\Repositories\InventoryRepository;

return new class implements DatabaseTest {
    private const PERMISSION = 'inventario.existencias_series.acceder';
    private const FORBIDDEN_PERMISSIONS = [
        'inventario.existencias_series.crear',
        'inventario.existencias_series.editar',
        'inventario.existencias_series.eliminar',
        'inventario.existencias_series.ajustar',
        'inventario.existencias_series.transferir',
    ];
    private const PRODUCT_PREFIX = 'QASSTK';
    private const SERIES_PREFIX = 'QASSTK-SER-';
    private const REFERENCE_PREFIX = 'QA-SER-STOCK-';
    private const WAREHOUSE_CODE = 'qasstk-other';
    private const OTHER_COMPANY_CODE = 'qasstk-empresa';
    private const OTHER_WAREHOUSE_CODE = 'qasstk-almacen';

    private PDO $pdo;
    /** @var array<string, int> */
    private array $ids = [];

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match EXISTENCIAS-SERIES-1.'
            );
        }

        $this->cleanup();
        $before = $this->qaCounts();
        $this->ids = $this->requiredIds();
        $otherWarehouseId = $this->createWarehouse(
            $this->ids['empresa_id'],
            self::WAREHOUSE_CODE,
            'QA Series Existencias Alterno'
        );
        $otherCompany = $this->createOtherCompanyWarehouse();
        $this->createProduct('QASSTK1', 'Serie QA Principal');
        $this->createProduct('QASSTK2', 'Serie QA Paginación');
        $service = $this->service();
        $queries = $this->queries();

        try {
            $permissions = $this->permissionEvidence();

            $service->aplicarMovimiento($this->movementInput(
                $this->ids['empresa_id'],
                $this->ids['almacen_id'],
                'ENTRADA_AJUSTE',
                'QA-SER-STOCK-ENTRY',
                [
                    ['QASSTK1', '2.000000', ['QASSTK-SER-001', 'QASSTK-SER-002']],
                    ['QASSTK2', '3.000000', ['QASSTK-SER-003', 'QASSTK-SER-004', 'QASSTK-SER-005']],
                ]
            ));
            $service->aplicarMovimiento($this->movementInput(
                $this->ids['empresa_id'],
                $this->ids['almacen_id'],
                'SALIDA_AJUSTE',
                'QA-SER-STOCK-EXIT',
                [['QASSTK1', '1.000000', ['QASSTK-SER-001']]]
            ));
            $service->aplicarMovimiento($this->movementInput(
                $this->ids['empresa_id'],
                $otherWarehouseId,
                'ENTRADA_AJUSTE',
                'QA-SER-STOCK-OTHER-WH',
                [['QASSTK1', '1.000000', ['QASSTK-SER-900']]]
            ));
            $service->aplicarMovimiento($this->movementInput(
                $otherCompany['empresa_id'],
                $otherCompany['almacen_id'],
                'ENTRADA_AJUSTE',
                'QA-SER-STOCK-OTHER-CO',
                [['QASSTK1', '1.000000', ['QASSTK-SER-999']]]
            ));

            $base = [
                'company_id' => $this->ids['empresa_id'],
                'warehouse_id' => $this->ids['almacen_id'],
                'search' => '',
                'product_id' => '',
                'status' => '',
                'warehouse_filter' => null,
                'page' => 1,
                'per_page' => 25,
            ];
            $all = $queries->serialStock($base);
            $bySeries = $queries->serialStock(array_merge($base, ['search' => 'SER-002']));
            $byProduct = $queries->serialStock(array_merge($base, ['search' => 'QASSTK1']));
            $byDescription = $queries->serialStock(array_merge($base, ['search' => 'Principal']));
            $onlyProduct = $queries->serialStock(array_merge($base, ['product_id' => 'QASSTK2']));
            $inStock = $queries->serialStock(array_merge($base, ['status' => 'EN_EXISTENCIA']));
            $outStock = $queries->serialStock(array_merge($base, ['status' => 'FUERA_EXISTENCIA']));
            $byWarehouse = $queries->serialStock(array_merge($base, [
                'warehouse_filter' => $this->ids['almacen_id'],
            ]));
            $pageOne = $queries->serialStock(array_merge($base, ['per_page' => 2]));
            $invalidPage = $queries->serialStock(array_merge($base, [
                'per_page' => 2,
                'page' => 999,
            ]));
            $queryCountsBefore = $this->qaCounts();
            $queries->serialStock($base);
            $queryCountsAfter = $this->qaCounts();

            $rowsBySeries = [];
            foreach ($all['rows'] as $row) {
                $rowsBySeries[(string) $row['numero_serie']] = $row;
            }

            $assertions = [
                'permission_rows' => $permissions['permission_rows'] === 1,
                'admin_permission_rows' => $permissions['admin_active_permission_rows'] === 1,
                'duplicate_permission_codes' => $permissions['duplicate_permission_codes'] === 0,
                'duplicate_role_permissions' => $permissions['duplicate_role_permissions'] === 0,
                'forbidden_permission_rows' => $permissions['forbidden_permission_rows'] === 0,
                'shows_in_stock_001_or_002' => isset($rowsBySeries['QASSTK-SER-002']),
                'shows_out_of_stock_001' => isset($rowsBySeries['QASSTK-SER-001'])
                    && (string) $rowsBySeries['QASSTK-SER-001']['estado'] === 'FUERA_EXISTENCIA'
                    && $rowsBySeries['QASSTK-SER-001']['almacen_id'] === null,
                'warehouse_correct_for_in_stock' => (int) ($rowsBySeries['QASSTK-SER-002']['almacen_id'] ?? 0) === $this->ids['almacen_id'],
                'q_by_series' => $this->containsSeries($bySeries['rows'], 'QASSTK-SER-002'),
                'q_by_product' => $this->containsSeries($byProduct['rows'], 'QASSTK-SER-001'),
                'q_by_description' => $this->containsSeries($byDescription['rows'], 'QASSTK-SER-001'),
                'product_id_filter' => $this->containsSeries($onlyProduct['rows'], 'QASSTK-SER-003')
                    && !$this->containsSeries($onlyProduct['rows'], 'QASSTK-SER-001'),
                'status_in_stock' => count($inStock['rows']) === 4
                    && $this->allMatch($inStock['rows'], 'estado', 'EN_EXISTENCIA'),
                'status_out_stock' => count($outStock['rows']) === 1
                    && $this->containsSeries($outStock['rows'], 'QASSTK-SER-001'),
                'warehouse_filter' => count($byWarehouse['rows']) === 4
                    && !$this->containsSeries($byWarehouse['rows'], 'QASSTK-SER-900'),
                'pagination_limit' => count($pageOne['rows']) === 2
                    && $pageOne['pagination']['total'] === 5,
                'invalid_page_normalized' => $invalidPage['pagination']['page'] === 3,
                'other_company_hidden' => !$this->containsSeries($all['rows'], 'QASSTK-SER-999'),
                'active_context_warehouse_only' => !$this->containsSeries($all['rows'], 'QASSTK-SER-900'),
                'query_readonly' => $queryCountsBefore === $queryCountsAfter,
                'no_post_route' => !$this->routeFileContainsPost(),
            ];

            if (in_array(false, $assertions, true)) {
                $failed = array_keys(array_filter(
                    $assertions,
                    static fn (bool $passed): bool => !$passed
                ));
                throw new RuntimeException(
                    'EXISTENCIAS-SERIES-1 DB-TEST assertions failed: '
                    . implode(', ', $failed)
                );
            }

            $during = $this->qaCounts();
        } finally {
            $this->cleanup();
        }

        $after = $this->qaCounts();

        if (array_sum($after) !== 0) {
            throw new RuntimeException(
                'EXISTENCIAS-SERIES-1 left persistent QA data.'
            );
        }

        return [
            'database' => $database,
            'permissions' => $permissions,
            'route' => [
                'path' => '/inventario/existencias-series',
                'method' => 'GET',
                'post_route_created' => false,
                'no_permission' => 'covered_by_permission_middleware',
                'no_session' => 'covered_by_auth_middleware',
            ],
            'listing' => [
                'total' => $all['pagination']['total'],
                'in_stock_visible' => $this->containsSeries($all['rows'], 'QASSTK-SER-002'),
                'out_of_stock_visible' => $this->containsSeries($all['rows'], 'QASSTK-SER-001'),
                'out_of_stock_warehouse_is_null' => $rowsBySeries['QASSTK-SER-001']['almacen_id'] === null,
            ],
            'filters' => [
                'q_by_series' => $this->containsSeries($bySeries['rows'], 'QASSTK-SER-002'),
                'q_by_product' => $this->containsSeries($byProduct['rows'], 'QASSTK-SER-001'),
                'q_by_description' => $this->containsSeries($byDescription['rows'], 'QASSTK-SER-001'),
                'product_id' => $this->containsSeries($onlyProduct['rows'], 'QASSTK-SER-003'),
                'estado_en_existencia' => count($inStock['rows']),
                'estado_fuera_existencia' => count($outStock['rows']),
                'almacen_id' => count($byWarehouse['rows']),
            ],
            'pagination' => [
                'limit_applied' => count($pageOne['rows']) === 2,
                'invalid_page_normalized_to' => $invalidPage['pagination']['page'],
                'total_pages' => $pageOne['pagination']['total_pages'],
            ],
            'readonly' => [
                'query_changed_counts' => $queryCountsBefore !== $queryCountsAfter,
                'post_route_created' => false,
                'write_actions' => false,
            ],
            'cleanup' => [
                'before' => $before,
                'during' => $during ?? [],
                'after' => $after,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function requiredIds(): array
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
            'usuario_id' => $this->adminId(),
            'unidad_id' => $this->idByCode('unidades_medida', 'PIEZA'),
            'tipo_producto_id' => $this->idByCode('tipos_producto', 'PRODUCTO'),
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

    private function createProduct(string $productId, string $description): void
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
                1,
                :creado_por
             )'
        );
        $statement->execute([
            'id_producto' => $productId,
            'descripcion' => $description,
            'unidad_medida_id' => $this->ids['unidad_id'],
            'tipo_producto_id' => $this->ids['tipo_producto_id'],
            'creado_por' => $this->ids['usuario_id'],
        ]);
    }

    private function createWarehouse(int $companyId, string $code, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, :creado_por)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $this->ids['usuario_id'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{empresa_id: int, almacen_id: int}
     */
    private function createOtherCompanyWarehouse(): array
    {
        $company = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, creado_por)
             VALUES (:codigo, :nombre, :creado_por)'
        );
        $company->execute([
            'codigo' => self::OTHER_COMPANY_CODE,
            'nombre' => 'QA Series Existencias Empresa',
            'creado_por' => $this->ids['usuario_id'],
        ]);
        $companyId = (int) $this->pdo->lastInsertId();

        return [
            'empresa_id' => $companyId,
            'almacen_id' => $this->createWarehouse(
                $companyId,
                self::OTHER_WAREHOUSE_CODE,
                'QA Series Existencias Almacén'
            ),
        ];
    }

    /**
     * @param list<array{0: string, 1: string, 2: list<string>}> $parts
     * @return array<string, mixed>
     */
    private function movementInput(
        int $companyId,
        int $warehouseId,
        string $concept,
        string $reference,
        array $parts
    ): array {
        return [
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'concepto_codigo' => $concept,
            'fecha_movimiento' => '2026-07-17 12:00:00',
            'referencia' => $reference,
            'observaciones' => 'QA EXISTENCIAS-SERIES-1',
            'partidas' => array_map(
                static fn (array $part): array => [
                    'id_producto' => $part[0],
                    'cantidad' => $part[1],
                    'series' => $part[2],
                ],
                $parts
            ),
            'usuario_id' => $this->ids['usuario_id'],
        ];
    }

    private function service(): InventoryService
    {
        return new InventoryService(new InventoryRepository(
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

    /**
     * @return array<string, int>
     */
    private function permissionEvidence(): array
    {
        $codeList = "'" . implode("','", array_map(
            static fn (string $code): string => str_replace("'", "''", $code),
            self::FORBIDDEN_PERMISSIONS
        )) . "'";

        return [
            'permission_rows' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM permisos
                 WHERE codigo = '" . self::PERMISSION . "'
                   AND activo = 1
                   AND eliminado_en IS NULL"
            )->fetchColumn(),
            'admin_active_permission_rows' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM rol_permisos rp
                 INNER JOIN permisos p ON p.id = rp.permiso_id
                 INNER JOIN roles r ON r.id = rp.rol_id
                 WHERE p.codigo = '" . self::PERMISSION . "'
                   AND r.codigo = 'ADMIN'
                   AND rp.activo = 1
                   AND rp.eliminado_en IS NULL
                   AND p.activo = 1
                   AND p.eliminado_en IS NULL"
            )->fetchColumn(),
            'duplicate_permission_codes' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM (
                    SELECT codigo FROM permisos
                    WHERE codigo = '" . self::PERMISSION . "'
                    GROUP BY codigo HAVING COUNT(*) > 1
                 ) duplicates"
            )->fetchColumn(),
            'duplicate_role_permissions' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM (
                    SELECT rp.rol_id, rp.permiso_id
                    FROM rol_permisos rp
                    INNER JOIN permisos p ON p.id = rp.permiso_id
                    WHERE p.codigo = '" . self::PERMISSION . "'
                    GROUP BY rp.rol_id, rp.permiso_id
                    HAVING COUNT(*) > 1
                 ) duplicates"
            )->fetchColumn(),
            'forbidden_permission_rows' => (int) $this->pdo->query(
                'SELECT COUNT(*) FROM permisos WHERE codigo IN (' . $codeList . ')'
            )->fetchColumn(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function containsSeries(array $rows, string $series): bool
    {
        foreach ($rows as $row) {
            if ((string) ($row['numero_serie'] ?? '') === $series) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function allMatch(array $rows, string $key, string $value): bool
    {
        foreach ($rows as $row) {
            if ((string) ($row[$key] ?? '') !== $value) {
                return false;
            }
        }

        return $rows !== [];
    }

    private function routeFileContainsPost(): bool
    {
        $routes = file_get_contents(BASE_PATH . '/routes/web.php');

        if ($routes === false) {
            throw new RuntimeException('Unable to read routes file.');
        }

        return str_contains($routes, "\$router->post(\n        '/inventario/existencias-series'");
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
                 WHERE referencia LIKE '" . self::REFERENCE_PREFIX . "%'"
            )->fetchColumn(),
            'detalles_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM movimientos_inventario_detalle mid
                 INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
                 WHERE mi.referencia LIKE '" . self::REFERENCE_PREFIX . "%'"
            )->fetchColumn(),
            'existencias_producto_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM existencias_producto
                 WHERE id_producto LIKE '" . self::PRODUCT_PREFIX . "%'"
            )->fetchColumn(),
            'empresas_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM empresas
                 WHERE codigo = '" . self::OTHER_COMPANY_CODE . "'"
            )->fetchColumn(),
            'almacenes_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM almacenes
                 WHERE codigo IN (
                    '" . self::WAREHOUSE_CODE . "',
                    '" . self::OTHER_WAREHOUSE_CODE . "'
                 )"
            )->fetchColumn(),
            'usuarios_qa' => 0,
        ];
    }

    private function cleanup(): void
    {
        foreach ([
            "DELETE mds FROM movimiento_detalle_series mds
             INNER JOIN movimientos_inventario_detalle mid
                ON mid.id = mds.movimiento_detalle_id
             INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
             WHERE mi.referencia LIKE '" . self::REFERENCE_PREFIX . "%'",
            "DELETE mds FROM movimiento_detalle_series mds
             INNER JOIN producto_series ps ON ps.id = mds.serie_id
             WHERE ps.numero_serie LIKE '" . self::SERIES_PREFIX . "%'",
            "DELETE mid FROM movimientos_inventario_detalle mid
             INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
             WHERE mi.referencia LIKE '" . self::REFERENCE_PREFIX . "%'",
            "DELETE FROM movimientos_inventario
             WHERE referencia LIKE '" . self::REFERENCE_PREFIX . "%'",
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
            "DELETE FROM almacenes
             WHERE codigo IN (
                '" . self::WAREHOUSE_CODE . "',
                '" . self::OTHER_WAREHOUSE_CODE . "'
             )",
            "DELETE FROM empresas
             WHERE codigo = '" . self::OTHER_COMPANY_CODE . "'",
        ] as $statement) {
            $this->pdo->exec($statement);
        }
    }
};
