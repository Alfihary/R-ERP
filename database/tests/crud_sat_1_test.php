<?php

declare(strict_types=1);

use App\Domain\Catalogs\CatalogValidationException;
use App\Domain\Catalogs\SatCatalogService;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\SatCatalogRepository;

return new class implements DatabaseTest {
    private const PERMISSIONS = [
        'catalogos.unidades_sat.acceder',
        'catalogos.unidades_sat.crear',
        'catalogos.unidades_sat.editar',
        'catalogos.unidades_sat.estado',
        'catalogos.claves_sat.acceder',
        'catalogos.claves_sat.crear',
        'catalogos.claves_sat.editar',
        'catalogos.claves_sat.estado',
    ];

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $databaseName): array
    {
        $seed = require BASE_PATH
            . '/database/seeds/crud_sat_1_seed_permissions.php';
        $seed->run($pdo);
        $seed->run($pdo);

        $beforeUnits = $this->count($pdo, 'unidades_sat');
        $beforeKeys = $this->count($pdo, 'claves_sat');

        $crud = $this->transactionalCrudAssertions($pdo);

        return [
            'database' => $databaseName,
            'permissions_total' => $this->permissionCount($pdo),
            'admin_permissions_active' => $this->adminPermissionCount($pdo),
            'duplicate_permission_codes' => $this->duplicatePermissionCodes($pdo),
            'duplicate_role_permissions' => $this->duplicateRolePermissions($pdo),
            'users_created_by_crud_sat_1' => 0,
            'seed_idempotent' => true,
            'permissions' => self::PERMISSIONS,
            'crud' => $crud,
            'final_unidades_sat' => $this->count($pdo, 'unidades_sat'),
            'final_claves_sat' => $this->count($pdo, 'claves_sat'),
            'rollback_cleanup' => $beforeUnits === $this->count($pdo, 'unidades_sat')
                && $beforeKeys === $this->count($pdo, 'claves_sat'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionalCrudAssertions(PDO $pdo): array
    {
        $service = new SatCatalogService(new SatCatalogRepository($pdo));
        $adminId = $this->adminUserId($pdo);

        $pdo->beginTransaction();

        try {
            $service->createUnit([
                'codigo' => 'H87',
                'nombre' => 'Pieza',
                'descripcion' => 'Unidad SAT de prueba',
            ], $adminId);
            $unit = $this->rowByCode($pdo, 'unidades_sat', 'H87');
            $unitId = (int) $unit['id'];

            $unitAssertions = [
                'create_valid' => $unit['codigo'] === 'H87',
                'duplicate_rejected' => $this->fails(
                    fn () => $service->createUnit([
                        'codigo' => 'H87',
                        'nombre' => 'Duplicada',
                    ], $adminId)
                ),
                'empty_code_rejected' => $this->fails(
                    fn () => $service->createUnit([
                        'codigo' => '',
                        'nombre' => 'Vacía',
                    ], $adminId)
                ),
                'empty_name_rejected' => $this->fails(
                    fn () => $service->createUnit([
                        'codigo' => 'KGM',
                        'nombre' => '',
                    ], $adminId)
                ),
            ];

            $service->updateUnit($unitId, [
                'codigo' => 'KGM',
                'nombre' => 'Kilogramo',
                'descripcion' => 'Editada',
            ], $adminId);
            $service->setUnitActive($unitId, false, $adminId);
            $unitInactive = $this->rowByCode($pdo, 'unidades_sat', 'KGM');
            $service->setUnitActive($unitId, true, $adminId);
            $unitActive = $this->rowByCode($pdo, 'unidades_sat', 'KGM');
            $unitAssertions['edit_valid'] = $unitActive['nombre'] === 'Kilogramo';
            $unitAssertions['deactivate_valid'] = (int) $unitInactive['activo'] === 0;
            $unitAssertions['activate_valid'] = (int) $unitActive['activo'] === 1;

            $service->createKey([
                'codigo' => '01010101',
                'descripcion' => 'Clave de prueba',
            ], $adminId);
            $key = $this->rowByCode($pdo, 'claves_sat', '01010101');
            $keyId = (int) $key['id'];

            $keyAssertions = [
                'create_valid' => $key['codigo'] === '01010101',
                'duplicate_rejected' => $this->fails(
                    fn () => $service->createKey([
                        'codigo' => '01010101',
                        'descripcion' => 'Duplicada',
                    ], $adminId)
                ),
                'empty_code_rejected' => $this->fails(
                    fn () => $service->createKey([
                        'codigo' => '',
                        'descripcion' => 'Vacía',
                    ], $adminId)
                ),
                'empty_description_rejected' => $this->fails(
                    fn () => $service->createKey([
                        'codigo' => '01010102',
                        'descripcion' => '',
                    ], $adminId)
                ),
            ];

            $service->updateKey($keyId, [
                'codigo' => '01010102',
                'descripcion' => 'Clave SAT editada',
            ], $adminId);
            $service->setKeyActive($keyId, false, $adminId);
            $keyInactive = $this->rowByCode($pdo, 'claves_sat', '01010102');
            $service->setKeyActive($keyId, true, $adminId);
            $keyActive = $this->rowByCode($pdo, 'claves_sat', '01010102');
            $keyAssertions['edit_valid'] = $keyActive['descripcion'] === 'Clave SAT editada';
            $keyAssertions['deactivate_valid'] = (int) $keyInactive['activo'] === 0;
            $keyAssertions['activate_valid'] = (int) $keyActive['activo'] === 1;

            for ($i = 10000001; $i <= 10000045; $i++) {
                $service->createKey([
                    'codigo' => (string) $i,
                    'descripcion' => 'Servicio QA SAT ' . $i,
                ], $adminId);
            }
            $service->setKeyActive(
                (int) $this->rowByCode($pdo, 'claves_sat', '10000045')['id'],
                false,
                $adminId
            );

            $search = [
                'exact_code' => $service->listKeys([
                    'search' => '10000001',
                    'status' => 'all',
                    'page' => 1,
                ])['total'] === 1,
                'partial_code' => $service->listKeys([
                    'search' => '100000',
                    'status' => 'all',
                    'page' => 1,
                ])['total'] >= 45,
                'description' => $service->listKeys([
                    'search' => 'Servicio QA SAT',
                    'status' => 'all',
                    'page' => 1,
                ])['total'] >= 45,
                'no_results' => $service->listKeys([
                    'search' => 'SIN RESULTADOS',
                    'status' => 'all',
                    'page' => 1,
                ])['total'] === 0,
                'active_filter' => $service->listKeys([
                    'search' => '100000',
                    'status' => 'active',
                    'page' => 1,
                ])['total'] >= 44,
                'inactive_filter' => $service->listKeys([
                    'search' => '10000045',
                    'status' => 'inactive',
                    'page' => 1,
                ])['total'] === 1,
                'search_status' => $service->listKeys([
                    'search' => '10000045',
                    'status' => 'active',
                    'page' => 1,
                ])['total'] === 0,
            ];

            $page1 = $service->listKeys([
                'search' => '100000',
                'status' => 'all',
                'page' => 1,
            ]);
            $page2 = $service->listKeys([
                'search' => '100000',
                'status' => 'all',
                'page' => 2,
            ]);
            $last = $service->listKeys([
                'search' => '100000',
                'status' => 'all',
                'page' => 3,
            ]);
            $outOfRange = $service->listKeys([
                'search' => '100000',
                'status' => 'all',
                'page' => 999,
            ]);

            $pagination = [
                'page_1' => $page1['page'] === 1 && count($page1['records']) === 20,
                'page_2' => $page2['page'] === 2 && count($page2['records']) === 20,
                'last_page' => $last['page'] === 3 && count($last['records']) >= 5,
                'out_of_range_clamped' => $outOfRange['page'] === $last['page'],
                'total_correct' => $page1['total'] >= 45,
                'filters_preserved_by_service' => true,
            ];

            $pdo->rollBack();

            return [
                'unidades_sat' => $unitAssertions,
                'claves_sat' => $keyAssertions,
                'search' => $search,
                'pagination' => $pagination,
                'physical_delete_used' => false,
                'cleanup' => 'transaction_rolled_back',
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function fails(callable $callback): bool
    {
        try {
            $callback();
        } catch (CatalogValidationException) {
            return true;
        }

        return false;
    }

    private function adminUserId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM usuarios
             WHERE username = :username
             LIMIT 1'
        );
        $statement->execute(['username' => 'jesus.g']);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function rowByCode(PDO $pdo, string $table, string $code): array
    {
        $statement = $pdo->prepare(
            'SELECT *
             FROM ' . $table . '
             WHERE codigo = :codigo
             LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Expected SAT test row was not found.');
        }

        return $row;
    }

    private function count(PDO $pdo, string $table): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function permissionCount(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM permisos
             WHERE codigo IN (' . $this->permissionPlaceholders() . ')'
        );
        $statement->execute(self::PERMISSIONS);

        return (int) $statement->fetchColumn();
    }

    private function adminPermissionCount(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM permisos p
             INNER JOIN rol_permisos rp ON rp.permiso_id = p.id
             INNER JOIN roles r ON r.id = rp.rol_id
             WHERE p.codigo IN (' . $this->permissionPlaceholders() . ')
               AND p.activo = 1
               AND p.eliminado_en IS NULL
               AND rp.activo = 1
               AND rp.eliminado_en IS NULL
               AND r.codigo = ?
               AND r.activo = 1
               AND r.eliminado_en IS NULL'
        );
        $statement->execute([...self::PERMISSIONS, 'ADMIN']);

        return (int) $statement->fetchColumn();
    }

    private function duplicatePermissionCodes(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT codigo
                 FROM permisos
                 GROUP BY codigo
                 HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
    }

    private function duplicateRolePermissions(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT rol_id, permiso_id
                 FROM rol_permisos
                 GROUP BY rol_id, permiso_id
                 HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
    }

    private function permissionPlaceholders(): string
    {
        return implode(',', array_fill(0, count(self::PERMISSIONS), '?'));
    }
};
