<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryTransferService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Infrastructure\Repositories\InventoryRepository;

return new class implements DatabaseTest {
    private const PERMISSION = 'inventario.kardex_series.acceder';
    private const FORBIDDEN_PERMISSIONS = [
        'inventario.kardex_series.crear',
        'inventario.kardex_series.editar',
        'inventario.kardex_series.eliminar',
        'inventario.kardex_series.anular',
        'inventario.kardex_series.transferir',
        'inventario.kardex_series.ajustar',
    ];
    private const PRODUCT_PREFIX = 'QAKDS';
    private const SERIES_PREFIX = 'QAKDS-SER-';
    private const REFERENCE_PREFIX = 'QA-KDS-';
    private const DESTINATION_WAREHOUSE_CODE = 'QAKDS-DESTINO';
    private const OTHER_COMPANY_CODE = 'QAKDS-EMPRESA';
    private const OTHER_WAREHOUSE_CODE = 'QAKDS-ALMACEN';

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
                'The active database does not match KARDEX-SERIES-1.'
            );
        }

        $this->cleanup();
        $before = $this->qaCounts();
        $this->ids = $this->requiredIds();
        $this->ids['destino_id'] = $this->createWarehouse(
            $this->ids['empresa_id'],
            self::DESTINATION_WAREHOUSE_CODE,
            'QA Kardex Series Destino'
        );
        $other = $this->createOtherCompanyWarehouse();
        $this->createProduct('QAKDS1', 'Kardex Serie Principal');
        $this->createProduct('QAKDS9', 'Kardex Serie Otra Empresa');
        $inventory = $this->inventoryService();
        $transfer = $this->transferService();
        $queries = $this->queries();

        try {
            $permissions = $this->permissionEvidence();

            $inventory->aplicarMovimiento($this->movementInput(
                $this->ids['empresa_id'],
                $this->ids['almacen_id'],
                'ENTRADA_AJUSTE',
                'QA-KDS-ENTRY',
                [
                    ['QAKDS1', '3.000000', [
                        'QAKDS-SER-S001',
                        'QAKDS-SER-S002',
                        'QAKDS-SER-S003',
                    ]],
                ],
                '2026-07-18 09:00:00'
            ));
            $inventory->aplicarMovimiento($this->movementInput(
                $this->ids['empresa_id'],
                $this->ids['almacen_id'],
                'SALIDA_AJUSTE',
                'QA-KDS-EXIT',
                [['QAKDS1', '1.000000', ['QAKDS-SER-S002']]],
                '2026-07-18 10:00:00'
            ));
            $transfer->transferir([
                'empresa_id' => $this->ids['empresa_id'],
                'almacen_origen_id' => $this->ids['almacen_id'],
                'almacen_destino_id' => $this->ids['destino_id'],
                'fecha_movimiento' => '2026-07-18 11:00:00',
                'referencia' => 'TRF-QA-KDS-001',
                'observaciones' => 'QA KARDEX-SERIES-1 transfer',
                'usuario_id' => $this->ids['usuario_id'],
                'partidas' => [[
                    'id_producto' => 'QAKDS1',
                    'cantidad' => '1.000000',
                    'observaciones' => null,
                    'series' => ['QAKDS-SER-S001'],
                ]],
            ]);
            $inventory->aplicarMovimiento($this->movementInput(
                $other['empresa_id'],
                $other['almacen_id'],
                'ENTRADA_AJUSTE',
                'QA-KDS-OTHER-CO',
                [['QAKDS9', '1.000000', ['QAKDS-SER-S999']]],
                '2026-07-18 12:00:00'
            ));

            $base = [
                'company_id' => $this->ids['empresa_id'],
                'search' => '',
                'product_id' => '',
                'serial_number' => '',
                'current_status' => '',
                'warehouse_id' => null,
                'date_from' => '',
                'date_to' => '',
                'page' => 1,
                'per_page' => 25,
            ];
            $all = $queries->serialKardex($base);
            $s001 = $queries->serialKardex(array_merge($base, [
                'serial_number' => 'S001',
            ]));
            $s002 = $queries->serialKardex(array_merge($base, [
                'serial_number' => 'S002',
            ]));
            $transferRows = $queries->serialKardex(array_merge($base, [
                'search' => 'TRF-QA-KDS-001',
            ]));
            $bySeriesQ = $queries->serialKardex(array_merge($base, [
                'search' => 'S001',
            ]));
            $byProductQ = $queries->serialKardex(array_merge($base, [
                'search' => 'QAKDS1',
            ]));
            $byDescriptionQ = $queries->serialKardex(array_merge($base, [
                'search' => 'Principal',
            ]));
            $byExactSeries = $queries->serialKardex(array_merge($base, [
                'serial_number' => 'QAKDS-SER-S001',
            ]));
            $byProduct = $queries->serialKardex(array_merge($base, [
                'product_id' => 'QAKDS1',
            ]));
            $inStock = $queries->serialKardex(array_merge($base, [
                'current_status' => 'EN_EXISTENCIA',
            ]));
            $outStock = $queries->serialKardex(array_merge($base, [
                'current_status' => 'FUERA_EXISTENCIA',
            ]));
            $byOriginWarehouse = $queries->serialKardex(array_merge($base, [
                'warehouse_id' => $this->ids['almacen_id'],
            ]));
            $byDate = $queries->serialKardex(array_merge($base, [
                'date_from' => '2026-07-18',
                'date_to' => '2026-07-18',
            ]));
            $pageOne = $queries->serialKardex(array_merge($base, [
                'page' => 1,
                'per_page' => 2,
            ]));
            $invalidPage = $queries->serialKardex(array_merge($base, [
                'page' => 999,
                'per_page' => 2,
            ]));
            $summaryS001 = $queries->serialKardexSummary(array_merge($base, [
                'serial_number' => 'QAKDS-SER-S001',
            ]));
            $summaryGeneral = $queries->serialKardexSummary($base);
            $queryCountsBefore = $this->qaCounts();
            $queries->serialKardex($base);
            $queries->serialKardexSummary($base);
            $queryCountsAfter = $this->qaCounts();

            $assertions = [
                'permission_rows' => $permissions['permission_rows'] === 1,
                'admin_permission_rows' =>
                    $permissions['admin_active_permission_rows'] === 1,
                'duplicate_permission_codes' =>
                    $permissions['duplicate_permission_codes'] === 0,
                'duplicate_role_permissions' =>
                    $permissions['duplicate_role_permissions'] === 0,
                'forbidden_permission_rows' =>
                    $permissions['forbidden_permission_rows'] === 0,
                'route_get_exists' => $this->routeFileContainsGet(),
                'no_post_route' => !$this->routeFileContainsPost(),
                'entry_visible' => $this->containsConcept($s001['rows'], 'ENTRADA_AJUSTE'),
                'exit_visible' => $this->containsConcept($s002['rows'], 'SALIDA_AJUSTE'),
                'out_stock_current_state' => $this->allMatch(
                    $s002['rows'],
                    'estado_actual',
                    'FUERA_EXISTENCIA'
                ),
                'out_stock_current_warehouse_null' => $this->allCurrentWarehouseNull($s002['rows']),
                'transfer_exit_visible' => $this->containsConcept(
                    $transferRows['rows'],
                    'TRANSFERENCIA_SALIDA'
                ),
                'transfer_entry_visible' => $this->containsConcept(
                    $transferRows['rows'],
                    'TRANSFERENCIA_ENTRADA'
                ),
                'transfer_reference_preserved' => $this->allMatch(
                    $transferRows['rows'],
                    'referencia',
                    'TRF-QA-KDS-001'
                ),
                'transfer_current_destination' =>
                    $summaryS001['almacen_actual_nombre'] === 'QA Kardex Series Destino',
                'q_by_series' => $this->containsSeries($bySeriesQ['rows'], 'QAKDS-SER-S001'),
                'q_by_product' => $this->containsSeries($byProductQ['rows'], 'QAKDS-SER-S003'),
                'q_by_description' => $this->containsSeries($byDescriptionQ['rows'], 'QAKDS-SER-S003'),
                'serial_number_filter' => $this->allMatch(
                    $byExactSeries['rows'],
                    'numero_serie',
                    'QAKDS-SER-S001'
                ),
                'product_id_filter' => $this->containsSeries($byProduct['rows'], 'QAKDS-SER-S001'),
                'status_in_stock' => $this->allMatch($inStock['rows'], 'estado_actual', 'EN_EXISTENCIA'),
                'status_out_stock' => $this->allMatch($outStock['rows'], 'estado_actual', 'FUERA_EXISTENCIA'),
                'warehouse_filter' => count($byOriginWarehouse['rows']) === 5,
                'date_filter' => $byDate['pagination']['total'] === 6,
                'pagination_limit' => count($pageOne['rows']) === 2
                    && $pageOne['pagination']['total'] === 6,
                'invalid_page_normalized' => $invalidPage['pagination']['page'] === 3,
                'other_company_hidden' => !$this->containsSeries($all['rows'], 'QAKDS-SER-S999'),
                'summary_selected_series' => $summaryS001['selected_series'] === true
                    && $summaryS001['numero_serie'] === 'QAKDS-SER-S001'
                    && $summaryS001['estado_actual'] === 'EN_EXISTENCIA'
                    && $summaryS001['total_movements'] === 3,
                'summary_general' => $summaryGeneral['selected_series'] === false
                    && $summaryGeneral['total_series'] === 3
                    && $summaryGeneral['total_movements'] === 6,
                'query_readonly' => $queryCountsBefore === $queryCountsAfter,
            ];

            if (in_array(false, $assertions, true)) {
                $failed = array_keys(array_filter(
                    $assertions,
                    static fn (bool $passed): bool => !$passed
                ));
                throw new RuntimeException(
                    'KARDEX-SERIES-1 DB-TEST assertions failed: '
                    . implode(', ', $failed)
                );
            }

            $during = $this->qaCounts();
        } finally {
            $this->cleanup();
        }

        $after = $this->qaCounts();

        if (array_sum($after) !== 0) {
            throw new RuntimeException('KARDEX-SERIES-1 left QA data.');
        }

        return [
            'database' => $database,
            'permissions' => $permissions,
            'route' => [
                'path' => '/inventario/kardex-series',
                'method' => 'GET',
                'post_route_created' => false,
                'no_permission' => 'covered_by_permission_middleware',
                'no_session' => 'covered_by_auth_middleware',
            ],
            'listing' => [
                'total' => $all['pagination']['total'],
                'entry_visible' => $this->containsConcept($s001['rows'], 'ENTRADA_AJUSTE'),
                'exit_visible' => $this->containsConcept($s002['rows'], 'SALIDA_AJUSTE'),
                'transfer_exit_visible' => $this->containsConcept(
                    $transferRows['rows'],
                    'TRANSFERENCIA_SALIDA'
                ),
                'transfer_entry_visible' => $this->containsConcept(
                    $transferRows['rows'],
                    'TRANSFERENCIA_ENTRADA'
                ),
                'current_state_s001' => $summaryS001['estado_actual'],
                'current_warehouse_s001' => $summaryS001['almacen_actual_nombre'],
            ],
            'filters' => [
                'q_by_series' => $this->containsSeries($bySeriesQ['rows'], 'QAKDS-SER-S001'),
                'q_by_product' => $this->containsSeries($byProductQ['rows'], 'QAKDS-SER-S003'),
                'q_by_description' => $this->containsSeries($byDescriptionQ['rows'], 'QAKDS-SER-S003'),
                'numero_serie' => count($byExactSeries['rows']),
                'id_producto' => count($byProduct['rows']),
                'estado_actual_en_existencia' => count($inStock['rows']),
                'estado_actual_fuera_existencia' => count($outStock['rows']),
                'almacen_id' => count($byOriginWarehouse['rows']),
                'fecha_desde_hasta' => $byDate['pagination']['total'],
            ],
            'summary' => [
                'selected_series' => $summaryS001['selected_series'],
                'general_total_series' => $summaryGeneral['total_series'],
                'general_total_movements' => $summaryGeneral['total_movements'],
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
            'nombre' => 'QA Kardex Series Empresa',
            'creado_por' => $this->ids['usuario_id'],
        ]);
        $companyId = (int) $this->pdo->lastInsertId();

        return [
            'empresa_id' => $companyId,
            'almacen_id' => $this->createWarehouse(
                $companyId,
                self::OTHER_WAREHOUSE_CODE,
                'QA Kardex Series Almacén'
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
        array $parts,
        string $date
    ): array {
        return [
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'concepto_codigo' => $concept,
            'fecha_movimiento' => $date,
            'referencia' => $reference,
            'observaciones' => 'QA KARDEX-SERIES-1',
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
    private function containsConcept(array $rows, string $concept): bool
    {
        foreach ($rows as $row) {
            if ((string) ($row['concepto_codigo'] ?? '') === $concept) {
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

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function allCurrentWarehouseNull(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['almacen_actual_id'] !== null) {
                return false;
            }
        }

        return $rows !== [];
    }

    private function routeFileContainsGet(): bool
    {
        $routes = file_get_contents(BASE_PATH . '/routes/web.php');

        if ($routes === false) {
            throw new RuntimeException('Unable to read routes file.');
        }

        return str_contains($routes, "\$router->get(\n        '/inventario/kardex-series'");
    }

    private function routeFileContainsPost(): bool
    {
        $routes = file_get_contents(BASE_PATH . '/routes/web.php');

        if ($routes === false) {
            throw new RuntimeException('Unable to read routes file.');
        }

        return str_contains($routes, "\$router->post(\n        '/inventario/kardex-series'");
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
                    OR referencia = 'TRF-QA-KDS-001'"
            )->fetchColumn(),
            'detalles_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM movimientos_inventario_detalle mid
                 INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
                 WHERE mi.referencia LIKE '" . self::REFERENCE_PREFIX . "%'
                    OR mi.referencia = 'TRF-QA-KDS-001'"
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
                    '" . self::DESTINATION_WAREHOUSE_CODE . "',
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
             WHERE mi.referencia LIKE '" . self::REFERENCE_PREFIX . "%'
                OR mi.referencia = 'TRF-QA-KDS-001'",
            "DELETE mds FROM movimiento_detalle_series mds
             INNER JOIN producto_series ps ON ps.id = mds.serie_id
             WHERE ps.numero_serie LIKE '" . self::SERIES_PREFIX . "%'",
            "DELETE mid FROM movimientos_inventario_detalle mid
             INNER JOIN movimientos_inventario mi ON mi.id = mid.movimiento_id
             WHERE mi.referencia LIKE '" . self::REFERENCE_PREFIX . "%'
                OR mi.referencia = 'TRF-QA-KDS-001'",
            "DELETE FROM movimientos_inventario
             WHERE referencia LIKE '" . self::REFERENCE_PREFIX . "%'
                OR referencia = 'TRF-QA-KDS-001'",
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
                '" . self::DESTINATION_WAREHOUSE_CODE . "',
                '" . self::OTHER_WAREHOUSE_CODE . "'
             )",
            "DELETE FROM empresas
             WHERE codigo = '" . self::OTHER_COMPANY_CODE . "'",
        ] as $statement) {
            $this->pdo->exec($statement);
        }
    }
};
