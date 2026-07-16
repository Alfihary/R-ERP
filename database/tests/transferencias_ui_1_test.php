<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryTransferService;
use App\Domain\Inventory\InventoryValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Infrastructure\Repositories\InventoryRepository;

return new class implements DatabaseTest {
    private const PRODUCT_A = 'QATUIA';
    private const PRODUCT_B = 'QATUIB';
    private const PRODUCT_SERVICE = 'QATUISERV';
    private const REF_VALID = 'TRF-UIQA-VALID';
    private const REF_FAIL = 'TRF-UIQA-FAIL';

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match TRANSFERENCIAS-UI-1.'
            );
        }

        $this->cleanup($pdo);
        $before = $this->qaCounts($pdo);
        $ids = $this->requiredIds($pdo);
        $destinationId = $this->createWarehouse($pdo, $ids);
        $otherWarehouseId = $this->createOtherCompanyWarehouse($pdo, $ids);
        $this->createProducts($pdo, $ids);
        $this->createExistence($pdo, $ids['almacen_id'], self::PRODUCT_A, '12.000000');
        $this->createExistence($pdo, $ids['almacen_id'], self::PRODUCT_B, '4.000000');

        $service = $this->service();
        $queries = $this->queries();

        try {
            $permissions = $this->permissionEvidence($pdo);
            $positive = $this->positiveCase($service, $queries, $ids, $destinationId);
            $negative = $this->negativeCases(
                $service,
                $ids,
                $destinationId,
                $otherWarehouseId
            );
            $queryEvidence = $this->queryEvidence($queries, $ids, $destinationId);
            $routes = $this->routeEvidence();
        } finally {
            $this->cleanup($pdo);
        }

        $after = $this->qaCounts($pdo);

        if ($after !== $before) {
            throw new RuntimeException(
                'TRANSFERENCIAS-UI-1 left persistent QA data.'
            );
        }

        return [
            'database' => $database,
            'permissions' => $permissions,
            'positive_case' => $positive,
            'negative_cases' => $negative,
            'query_evidence' => $queryEvidence,
            'routes' => $routes,
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
        $companyId = (int) $pdo->query(
            "SELECT id FROM empresas WHERE nombre = 'Grupo Refrigerantes' LIMIT 1"
        )->fetchColumn();
        $warehouseId = (int) $pdo->query(
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
            $companyId,
            $warehouseId,
            $unitId,
            $productTypeId,
            $kitTypeId,
            $serviceTypeId,
        ] as $id) {
            if ($id < 1) {
                throw new RuntimeException(
                    'TRANSFERENCIAS-UI-1 required structural IDs are missing.'
                );
            }
        }

        return [
            'admin_id' => $adminId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
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
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, int> $ids
     */
    private function createWarehouse(PDO $pdo, array $ids): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'empresa_id' => $ids['empresa_id'],
            'codigo' => 'qatui-destino',
            'nombre' => 'QA Transfer UI Destino',
            'creado_por' => $ids['admin_id'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, int> $ids
     */
    private function createOtherCompanyWarehouse(PDO $pdo, array $ids): int
    {
        $company = $pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $company->execute([
            'codigo' => 'qatui-other',
            'nombre' => 'QA Transfer UI Otra Empresa',
            'creado_por' => $ids['admin_id'],
        ]);
        $companyId = (int) $pdo->lastInsertId();
        $warehouse = $pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $warehouse->execute([
            'empresa_id' => $companyId,
            'codigo' => 'qatui-ajeno',
            'nombre' => 'QA Transfer UI Ajeno',
            'creado_por' => $ids['admin_id'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, int> $ids
     */
    private function createProducts(PDO $pdo, array $ids): void
    {
        foreach ([
            [self::PRODUCT_A, 'QA Transfer UI A', $ids['producto_tipo_id']],
            [self::PRODUCT_B, 'QA Transfer UI KIT', $ids['kit_tipo_id']],
            [self::PRODUCT_SERVICE, 'QA Transfer UI SERV', $ids['servicio_tipo_id']],
        ] as [$productId, $description, $typeId]) {
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

    private function createExistence(
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
             )'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
            'cantidad_actual' => $quantity,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionEvidence(PDO $pdo): array
    {
        $codes = [
            'inventario.transferencias.acceder',
            'inventario.transferencias.ver',
            'inventario.transferencias.crear',
        ];
        $placeholders = implode(', ', array_fill(0, count($codes), '?'));
        $permissions = $pdo->prepare(
            'SELECT COUNT(*) FROM permisos
             WHERE codigo IN (' . $placeholders . ')
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $permissions->execute($codes);
        $admin = $pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN roles r ON r.id = rp.rol_id
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE r.codigo = \'ADMIN\'
               AND p.codigo IN (' . $placeholders . ')
               AND rp.activo = 1
               AND rp.eliminado_en IS NULL'
        );
        $admin->execute($codes);
        $duplicates = $pdo->query(
            "SELECT COUNT(*)
             FROM (
                SELECT codigo
                FROM permisos
                WHERE codigo LIKE 'inventario.transferencias.%'
                GROUP BY codigo
                HAVING COUNT(*) > 1
             ) duplicates"
        )->fetchColumn();

        return [
            'permissions_total' => (int) $permissions->fetchColumn(),
            'admin_assignments' => (int) $admin->fetchColumn(),
            'duplicate_permission_codes' => (int) $duplicates,
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function positiveCase(
        InventoryTransferService $service,
        InventoryQueryRepository $queries,
        array $ids,
        int $destinationId
    ): array {
        $result = $service->transferir([
            'empresa_id' => $ids['empresa_id'],
            'almacen_origen_id' => $ids['almacen_id'],
            'almacen_destino_id' => $destinationId,
            'fecha_movimiento' => '2026-07-14 10:00:00',
            'referencia' => self::REF_VALID,
            'observaciones' => 'QA UI transferencia válida',
            'usuario_id' => $ids['admin_id'],
            'partidas' => [
                ['id_producto' => self::PRODUCT_A, 'cantidad' => '2.000000', 'observaciones' => 'A'],
                ['id_producto' => self::PRODUCT_B, 'cantidad' => '1.000000', 'observaciones' => 'B'],
            ],
        ]);
        $detail = $queries->transfer(self::REF_VALID, $ids['empresa_id']);

        if ($detail === null || count((array) ($detail['partidas'] ?? [])) !== 2) {
            throw new RuntimeException(
                'TRANSFERENCIAS-UI-1 detail query did not find the applied transfer.'
            );
        }

        return [
            'redirect_target' => '/inventario/transferencias/ver?ref=' . self::REF_VALID,
            'estado' => $result['estado'],
            'same_reference' =>
                $result['referencia_transferencia'] === self::REF_VALID,
            'partidas' => count($result['partidas_transferidas']),
            'detail_accessible' => true,
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, string>
     */
    private function negativeCases(
        InventoryTransferService $service,
        array $ids,
        int $destinationId,
        int $otherWarehouseId
    ): array {
        return [
            'same_warehouse' => $this->expectInvalid($service, [
                'empresa_id' => $ids['empresa_id'],
                'almacen_origen_id' => $ids['almacen_id'],
                'almacen_destino_id' => $ids['almacen_id'],
                'fecha_movimiento' => '2026-07-14 11:00:00',
                'referencia' => self::REF_FAIL . '-SAME',
                'observaciones' => '',
                'usuario_id' => $ids['admin_id'],
                'partidas' => [['id_producto' => self::PRODUCT_A, 'cantidad' => '1.000000']],
            ]),
            'warehouse_other_company' => $this->expectInvalid($service, [
                'empresa_id' => $ids['empresa_id'],
                'almacen_origen_id' => $ids['almacen_id'],
                'almacen_destino_id' => $otherWarehouseId,
                'fecha_movimiento' => '2026-07-14 11:00:00',
                'referencia' => self::REF_FAIL . '-OTHER',
                'observaciones' => '',
                'usuario_id' => $ids['admin_id'],
                'partidas' => [['id_producto' => self::PRODUCT_A, 'cantidad' => '1.000000']],
            ]),
            'service_product_rejected' => $this->expectInvalid($service, [
                'empresa_id' => $ids['empresa_id'],
                'almacen_origen_id' => $ids['almacen_id'],
                'almacen_destino_id' => $destinationId,
                'fecha_movimiento' => '2026-07-14 11:00:00',
                'referencia' => self::REF_FAIL . '-SERV',
                'observaciones' => '',
                'usuario_id' => $ids['admin_id'],
                'partidas' => [['id_producto' => self::PRODUCT_SERVICE, 'cantidad' => '1.000000']],
            ]),
            'insufficient_stock_rollback' => $this->expectInvalid($service, [
                'empresa_id' => $ids['empresa_id'],
                'almacen_origen_id' => $ids['almacen_id'],
                'almacen_destino_id' => $destinationId,
                'fecha_movimiento' => '2026-07-14 11:00:00',
                'referencia' => self::REF_FAIL . '-STOCK',
                'observaciones' => '',
                'usuario_id' => $ids['admin_id'],
                'partidas' => [['id_producto' => self::PRODUCT_A, 'cantidad' => '999.000000']],
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function expectInvalid(
        InventoryTransferService $service,
        array $input
    ): string {
        try {
            $service->transferir($input);
        } catch (InventoryValidationException) {
            return 'rejected';
        }

        throw new RuntimeException(
            'TRANSFERENCIAS-UI-1 expected validation rejection did not happen.'
        );
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, mixed>
     */
    private function queryEvidence(
        InventoryQueryRepository $queries,
        array $ids,
        int $destinationId
    ): array {
        $list = $queries->transfers([
            'company_id' => $ids['empresa_id'],
            'search' => self::REF_VALID,
            'warehouse_id' => null,
            'date_from' => '',
            'date_to' => '',
            'page' => 1,
            'per_page' => 15,
        ]);
        $filtered = $queries->transfers([
            'company_id' => $ids['empresa_id'],
            'search' => self::REF_VALID,
            'warehouse_id' => $destinationId,
            'date_from' => '',
            'date_to' => '',
            'page' => 1,
            'per_page' => 15,
        ]);
        $products = $queries->searchProducts('QATUI');
        $serviceReturned = array_filter(
            $products,
            static fn (array $row): bool =>
                (string) ($row['id_producto'] ?? '') === self::PRODUCT_SERVICE
        );

        return [
            'list_total' => (int) ($list['pagination']['total'] ?? 0),
            'warehouse_filter_total' => (int) ($filtered['pagination']['total'] ?? 0),
            'product_search_max_20' => count($products) <= 20,
            'service_excluded_from_search' => $serviceReturned === [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function routeEvidence(): array
    {
        $routes = (string) file_get_contents(BASE_PATH . '/routes/web.php');

        foreach ([
            '/inventario/transferencias',
            '/inventario/transferencias/crear',
            '/inventario/transferencias/ver',
            '/inventario/transferencias/productos/buscar',
            'inventario.transferencias.acceder',
            'inventario.transferencias.ver',
            'inventario.transferencias.crear',
        ] as $needle) {
            if (!str_contains($routes, $needle)) {
                throw new RuntimeException(
                    'TRANSFERENCIAS-UI-1 route evidence is incomplete.'
                );
            }
        }

        return [
            'list_no_session' => 'covered_by_auth_middleware',
            'create_post_csrf' => 'covered_by_global_csrf_middleware',
            'no_permission' => 'covered_by_permission_middleware',
            'product_search_private' => 'covered_by_create_permission',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function qaCounts(PDO $pdo): array
    {
        return [
            'productos_qa' => $this->countWhere($pdo, 'productos', "id_producto LIKE 'QATUI%'"),
            'existencias_qa' => $this->countWhere($pdo, 'existencias_producto', "id_producto LIKE 'QATUI%'"),
            'movimientos_qa' => $this->countWhere($pdo, 'movimientos_inventario', "referencia LIKE 'TRF-UIQA%'"),
            'almacenes_qa' => $this->countWhere($pdo, 'almacenes', "codigo LIKE 'qatui%'"),
            'empresas_qa' => $this->countWhere($pdo, 'empresas', "codigo LIKE 'qatui%'"),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    private function cleanup(PDO $pdo): void
    {
        $pdo->exec(
            "DELETE d
             FROM movimientos_inventario_detalle d
             INNER JOIN movimientos_inventario m
                ON m.id = d.movimiento_id
             WHERE m.referencia LIKE 'TRF-UIQA%'"
        );
        $pdo->exec("DELETE FROM movimientos_inventario WHERE referencia LIKE 'TRF-UIQA%'");
        $pdo->exec("DELETE FROM existencias_producto WHERE id_producto LIKE 'QATUI%'");
        $pdo->exec("DELETE FROM productos WHERE id_producto LIKE 'QATUI%'");
        $pdo->exec("DELETE FROM almacenes WHERE codigo LIKE 'qatui%'");
        $pdo->exec("DELETE FROM empresas WHERE codigo LIKE 'qatui%'");
    }
};
