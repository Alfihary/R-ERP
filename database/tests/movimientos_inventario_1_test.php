<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Infrastructure\Repositories\InventoryRepository;

return new class implements DatabaseTest {
    private const PERMISSIONS = [
        'inventario.movimientos.acceder',
        'inventario.movimientos.ver',
        'inventario.movimientos.crear',
    ];

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match MOVIMIENTOS-INVENTARIO-1.'
            );
        }

        $this->cleanup($pdo);
        $before = $this->counts($pdo);
        $ids = $this->requiredIds($pdo);
        $service = $this->service();
        $queries = $this->queries();

        $this->createProducts($pdo, $ids);

        try {
            $permissions = $this->permissionEvidence($pdo);
            $entrada = $service->aplicarMovimiento($this->input(
                $ids,
                [['QAMOVPROD1', '10.000000']],
                'ENTRADA_AJUSTE',
                'QAMOV-ENTRADA'
            ));
            $salida = $service->aplicarMovimiento($this->input(
                $ids,
                [['QAMOVPROD1', '4.000000']],
                'SALIDA_AJUSTE',
                'QAMOV-SALIDA'
            ));
            $multipart = $service->aplicarMovimiento($this->input(
                $ids,
                [
                    ['QAMOVPROD2', '2.000000'],
                    ['QAMOVKIT1', '1.000000'],
                ],
                'ENTRADA_AJUSTE',
                'QAMOV-MULTI'
            ));
            $finalBalance = $this->balance($pdo, 'QAMOVPROD1');

            $rejects = $this->rejects($service, $ids);
            $list = $queries->movements([
                'company_id' => $ids['empresa_id'],
                'warehouse_id' => $ids['almacen_id'],
                'search' => 'QAMOV',
                'concept' => '',
                'status' => 'APLICADO',
                'date_from' => '',
                'date_to' => '',
                'page' => 1,
                'per_page' => 15,
            ]);
            $detail = $queries->movement(
                (int) $entrada['movimiento_id'],
                $ids['empresa_id'],
                $ids['almacen_id']
            );
            $search = $queries->searchProducts('QAMOV');

            if ($this->draftCount($pdo) !== 0) {
                throw new RuntimeException(
                    'MOVIMIENTOS-INVENTARIO-1 left residual drafts.'
                );
            }
        } finally {
            $this->cleanup($pdo);
        }

        $after = $this->counts($pdo);

        if ($after !== $before) {
            throw new RuntimeException(
                'MOVIMIENTOS-INVENTARIO-1 left persistent QA data.'
            );
        }

        return [
            'database' => $database,
            'permissions' => $permissions,
            'creation' => [
                'entrada_aplicada' => $entrada['estado'] === 'APLICADO',
                'salida_aplicada' => $salida['estado'] === 'APLICADO',
                'multipartida_aplicada' =>
                    count($multipart['partidas_aplicadas']) === 2,
                'kit_valido' =>
                    in_array(
                        'QAMOVKIT1',
                        array_column($multipart['partidas_aplicadas'], 'id_producto'),
                        true
                    ),
                'saldo_final_producto' => $finalBalance,
            ],
            'rejects' => $rejects,
            'listado' => [
                'rows' => count($list['rows']),
                'pagination_total' => $list['pagination']['total'],
            ],
            'detalle' => [
                'found' => $detail !== null,
                'partidas' => is_array($detail['partidas'] ?? null)
                    ? count($detail['partidas'])
                    : 0,
            ],
            'buscador' => [
                'max_20' => count($search) <= 20,
                'servicio_excluido' =>
                    !in_array('QAMOVSERV1', array_column($search, 'id_producto'), true),
                'producto_incluido' =>
                    in_array('QAMOVPROD1', array_column($search, 'id_producto'), true),
                'kit_incluido' =>
                    in_array('QAMOVKIT1', array_column($search, 'id_producto'), true),
            ],
            'cleanup' => [
                'before' => $before,
                'after' => $after,
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
                    'MOVIMIENTOS-INVENTARIO-1 required structural IDs are missing.'
                );
            }
        }

        return [
            'admin_id' => $adminId,
            'empresa_id' => $empresaId,
            'almacen_id' => $almacenId,
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
            ['QAMOVPROD1', 'Mov QA 1', $ids['producto_tipo_id'], 1],
            ['QAMOVPROD2', 'Mov QA 2', $ids['producto_tipo_id'], 1],
            ['QAMOVKIT1', 'Mov QA Kit', $ids['kit_tipo_id'], 1],
            ['QAMOVSERV1', 'Mov QA Servicio', $ids['servicio_tipo_id'], 1],
            ['QAMOVINACT1', 'Mov QA Inactivo', $ids['producto_tipo_id'], 0],
        ] as [$productId, $description, $typeId, $active]) {
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
    private function permissionEvidence(PDO $pdo): array
    {
        [$placeholders, $parameters] = $this->placeholders(self::PERMISSIONS);
        $in = implode(', ', $placeholders);
        $permissions = $pdo->prepare(
            'SELECT COUNT(*) FROM permisos
             WHERE codigo IN (' . $in . ')
               AND modulo = :modulo
               AND es_sistema = 1
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $permissions->execute($parameters + ['modulo' => 'inventario']);
        $permissionRows = (int) $permissions->fetchColumn();

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
               AND p.codigo IN (' . $in . ')
               AND p.activo = 1
               AND p.eliminado_en IS NULL
             WHERE rp.activo = 1
               AND rp.eliminado_en IS NULL'
        );
        $admin->execute($parameters + ['role_code' => 'ADMIN']);
        $adminRows = (int) $admin->fetchColumn();
        $duplicateCodes = (int) $pdo->query(
            'SELECT COUNT(*) FROM (
                SELECT codigo FROM permisos
                GROUP BY codigo HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
        $duplicateRelations = (int) $pdo->query(
            'SELECT COUNT(*) FROM (
                SELECT rol_id, permiso_id FROM rol_permisos
                GROUP BY rol_id, permiso_id HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();

        if (
            $permissionRows !== 3
            || $adminRows !== 3
            || $duplicateCodes !== 0
            || $duplicateRelations !== 0
        ) {
            throw new RuntimeException(
                'MOVIMIENTOS-INVENTARIO-1 permission evidence is invalid.'
            );
        }

        return [
            'codes' => self::PERMISSIONS,
            'permission_rows' => $permissionRows,
            'admin_active_permission_rows' => $adminRows,
            'duplicate_permission_codes' => $duplicateCodes,
            'duplicate_role_permissions' => $duplicateRelations,
        ];
    }

    /**
     * @param array<string, int> $ids
     * @return array<string, bool>
     */
    private function rejects(InventoryService $service, array $ids): array
    {
        return [
            'concepto_manipulado' => $this->fails(
                fn () => $service->aplicarMovimiento($this->input(
                    $ids,
                    [['QAMOVPROD1', '1.000000']],
                    'COMPRA',
                    'QAMOV-BAD-CONCEPT'
                ))
            ),
            'partidas_vacias' => $this->fails(
                fn () => $service->aplicarMovimiento($this->input(
                    $ids,
                    [],
                    'ENTRADA_AJUSTE',
                    'QAMOV-EMPTY'
                ))
            ),
            'producto_inexistente' => $this->fails(
                fn () => $service->aplicarMovimiento($this->input(
                    $ids,
                    [['QAMOV404', '1.000000']],
                    'ENTRADA_AJUSTE',
                    'QAMOV-P404'
                ))
            ),
            'producto_inactivo' => $this->fails(
                fn () => $service->aplicarMovimiento($this->input(
                    $ids,
                    [['QAMOVINACT1', '1.000000']],
                    'ENTRADA_AJUSTE',
                    'QAMOV-INACT'
                ))
            ),
            'servicio' => $this->fails(
                fn () => $service->aplicarMovimiento($this->input(
                    $ids,
                    [['QAMOVSERV1', '1.000000']],
                    'ENTRADA_AJUSTE',
                    'QAMOV-SERV'
                ))
            ),
            'producto_duplicado' => $this->fails(
                fn () => $service->aplicarMovimiento($this->input(
                    $ids,
                    [
                        ['QAMOVPROD1', '1.000000'],
                        ['QAMOVPROD1', '2.000000'],
                    ],
                    'ENTRADA_AJUSTE',
                    'QAMOV-DUP'
                ))
            ),
            'cantidad_cero' => $this->fails(
                fn () => $service->aplicarMovimiento($this->input(
                    $ids,
                    [['QAMOVPROD1', '0.000000']],
                    'ENTRADA_AJUSTE',
                    'QAMOV-ZERO'
                ))
            ),
            'cantidad_negativa' => $this->fails(
                fn () => $service->aplicarMovimiento($this->input(
                    $ids,
                    [['QAMOVPROD1', '-1.000000']],
                    'ENTRADA_AJUSTE',
                    'QAMOV-NEG'
                ))
            ),
            'mas_de_6_decimales' => $this->fails(
                fn () => $service->aplicarMovimiento($this->input(
                    $ids,
                    [['QAMOVPROD1', '1.0000001']],
                    'ENTRADA_AJUSTE',
                    'QAMOV-SCALE'
                ))
            ),
            'saldo_insuficiente' => $this->fails(
                fn () => $service->aplicarMovimiento($this->input(
                    $ids,
                    [['QAMOVPROD2', '999.000000']],
                    'SALIDA_AJUSTE',
                    'QAMOV-NOSTOCK'
                ))
            ),
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
            'fecha_movimiento' => '2026-07-13 10:00:00',
            'referencia' => $reference,
            'observaciones' => 'QA transitorio',
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

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (InventoryValidationException) {
            return true;
        }

        return false;
    }

    private function balance(PDO $pdo, string $productId): string
    {
        $statement = $pdo->prepare(
            'SELECT CAST(cantidad_actual AS CHAR)
             FROM existencias_producto
             WHERE id_producto = :id_producto
             LIMIT 1'
        );
        $statement->execute(['id_producto' => $productId]);

        return (string) $statement->fetchColumn();
    }

    private function draftCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM movimientos_inventario
             WHERE referencia LIKE 'QAMOV-%' AND estado = 'BORRADOR'"
        )->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'productos_qa' => $this->countLike($pdo, 'productos', 'id_producto'),
            'existencias_qa' => $this->countLike($pdo, 'existencias_producto', 'id_producto'),
            'movimientos_qa' => (int) $pdo->query(
                "SELECT COUNT(*) FROM movimientos_inventario
                 WHERE referencia LIKE 'QAMOV-%'"
            )->fetchColumn(),
            'detalles_qa' => (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM movimientos_inventario_detalle d
                 WHERE d.id_producto LIKE 'QAMOV%'"
            )->fetchColumn(),
            'conceptos_legitimos' => (int) $pdo->query(
                "SELECT COUNT(*) FROM conceptos_movimiento_inventario
                 WHERE codigo IN ('ENTRADA_AJUSTE', 'SALIDA_AJUSTE')"
            )->fetchColumn(),
        ];
    }

    private function countLike(PDO $pdo, string $table, string $column): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table
            . " WHERE " . $column . " LIKE 'QAMOV%'"
        )->fetchColumn();
    }

    private function cleanup(PDO $pdo): void
    {
        $ids = $pdo->query(
            "SELECT id FROM movimientos_inventario
             WHERE referencia LIKE 'QAMOV-%'"
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($ids !== []) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $pdo->prepare(
                'DELETE FROM movimientos_inventario_detalle
                 WHERE movimiento_id IN (' . $placeholders . ')'
            )->execute($ids);
            $pdo->prepare(
                'DELETE FROM movimientos_inventario
                 WHERE id IN (' . $placeholders . ')'
            )->execute($ids);
        }

        $pdo->exec("DELETE FROM existencias_producto WHERE id_producto LIKE 'QAMOV%'");
        $pdo->exec("DELETE FROM producto_impuestos WHERE id_producto LIKE 'QAMOV%'");
        $pdo->exec("DELETE FROM producto_codigos_barras WHERE id_producto LIKE 'QAMOV%'");
        $pdo->exec("DELETE FROM producto_documentos WHERE id_producto LIKE 'QAMOV%'");
        $pdo->exec("DELETE FROM productos WHERE id_producto LIKE 'QAMOV%'");
    }

    /**
     * @param list<string> $values
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function placeholders(array $values): array
    {
        $placeholders = [];
        $parameters = [];

        foreach ($values as $index => $value) {
            $key = 'code_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $value;
        }

        return [$placeholders, $parameters];
    }
};
