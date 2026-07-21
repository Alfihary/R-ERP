<?php

declare(strict_types=1);

use App\Domain\Configuration\CompanyService;
use App\Domain\Configuration\ConfigurationValidationException;
use App\Domain\Configuration\WarehouseService;
use App\Domain\Scope\UserScopeService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\WarehouseRepository;

return new class implements DatabaseTest {
    private const COMPANY_CODE = 'QA-CONFIG-EMPRESA';
    private const COMPANY_CODE_MIN = 'GR';
    private const COMPANY_CODE_NORMALIZED = 'GRUPO-REFRIGERANTES-QA';
    private const COMPANY_CODE_SECOND = 'QA-CONFIG-SEGUNDA';
    private const WAREHOUSE_CODE = 'BO';
    private const WAREHOUSE_CODE_NORMALIZED = 'BODEGA-PRINCIPAL';
    private const WAREHOUSE_CODE_SECOND = 'QA-CONFIG-ALM-2';
    private const WAREHOUSE_CODE_SHARED = 'QA-CONFIG-SHARED';

    private PDO $pdo;
    private int $adminId;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match CONFIG-OPERACION.'
            );
        }

        $this->cleanup();
        $before = $this->qaCounts();
        $results = [];

        try {
            $this->adminId = $this->adminId();
            $results['columns_exist'] = $this->columnsExist();
            $results['code_check_migration'] = $this->codeCheckMigrationCases();
            $results['permissions'] = $this->permissionsValid();
            $results['companies'] = $this->companyCases();
            $results['warehouses'] = $this->warehouseCases();
            $results['admin_relations_idempotent'] =
                $this->adminRelationsIdempotent();
            $results['context_still_resolves'] = $this->contextStillResolves();

            foreach ($results as $label => $result) {
                if ($result !== true && !is_array($result)) {
                    throw new RuntimeException(
                        'CONFIG-OPERACION failed case: ' . $label
                    );
                }
            }

            $during = $this->qaCounts();
        } finally {
            $this->cleanup();
        }

        $after = $this->qaCounts();

        if (array_sum($after) !== 0) {
            throw new RuntimeException('CONFIG-OPERACION left QA data.');
        }

        return [
            'database' => $database,
            'columns_exist' => true,
            'permissions_total' => $this->permissionCount(),
            'permissions_admin_active' => $this->adminPermissionCount(),
            'permission_duplicates' => $this->permissionDuplicates(),
            'relation_duplicates' => $this->relationDuplicates(),
            'cases' => $results,
            'counts_before' => $before,
            'counts_during' => $during ?? [],
            'counts_after_cleanup' => $after,
            'cleanup' => 'qa_rows_deleted',
        ];
    }

    private function columnsExist(): bool
    {
        $expected = [
            'empresas' => [
                'razon_social',
                'nombre_comercial',
                'rfc',
                'regimen_fiscal',
                'telefono',
                'email',
                'sitio_web',
                'pais',
                'estado',
                'municipio',
                'colonia',
                'calle',
                'numero_exterior',
                'numero_interior',
                'codigo_postal',
                'logo_path',
                'color_primario',
            ],
            'almacenes' => [
                'tipo_almacen',
                'responsable',
                'telefono',
                'email',
                'pais',
                'estado',
                'municipio',
                'colonia',
                'calle',
                'numero_exterior',
                'numero_interior',
                'codigo_postal',
                'permite_ventas',
                'permite_compras',
                'permite_inventario',
                'permite_transferencias',
                'es_principal',
                'principal_empresa_id',
            ],
        ];

        foreach ($expected as $table => $columns) {
            foreach ($columns as $column) {
                if (!$this->columnExists($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function permissionsValid(): bool
    {
        return $this->permissionCount() === 10
            && $this->adminPermissionCount() === 10
            && $this->permissionDuplicates() === 0
            && $this->relationDuplicates() === 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function codeCheckMigrationCases(): array
    {
        $migration = require BASE_PATH
            . '/database/migrations/config_operacion_codigos_upper_1_001_uppercase_company_warehouse_codes.php';

        if (!$migration instanceof Migration) {
            throw new RuntimeException('CONFIG-OPERACION-CODIGOS-UPPER-1 migration is invalid.');
        }

        $runner = new MigrationRunner($this->pdo);
        $uppercaseBeforeRollback = $this->checkUsesMinimum('chk_empresas_codigo', 2)
            && $this->checkUsesMinimum('chk_almacenes_codigo', 2)
            && $this->checkUsesCaseFunction('chk_empresas_codigo', 'upper')
            && $this->checkUsesCaseFunction('chk_almacenes_codigo', 'upper')
            && $this->dbAcceptsCompanyCode('ZZ')
            && $this->dbAcceptsWarehouseCode('ZZ')
            && !$this->dbAcceptsCompanyCode('zz')
            && !$this->dbAcceptsWarehouseCode('zz')
            && $this->codesStoredUppercase();

        $rollback = $runner->rollback($migration);
        $rollbackRestoredLowercaseMinTwo = $this->checkUsesMinimum('chk_empresas_codigo', 2)
            && $this->checkUsesMinimum('chk_almacenes_codigo', 2)
            && $this->checkUsesCaseFunction('chk_empresas_codigo', 'lower')
            && $this->checkUsesCaseFunction('chk_almacenes_codigo', 'lower')
            && $this->dbAcceptsCompanyCode('zz')
            && $this->dbAcceptsWarehouseCode('zz')
            && !$this->dbAcceptsCompanyCode('ZZ')
            && !$this->dbAcceptsWarehouseCode('ZZ');

        $migrate = $runner->migrate($migration);
        $migrateSecondRun = $runner->migrate($migration);
        $uppercaseAfterMigrate = $this->checkUsesMinimum('chk_empresas_codigo', 2)
            && $this->checkUsesMinimum('chk_almacenes_codigo', 2)
            && $this->checkUsesCaseFunction('chk_empresas_codigo', 'upper')
            && $this->checkUsesCaseFunction('chk_almacenes_codigo', 'upper')
            && $this->dbAcceptsCompanyCode('ZZ')
            && $this->dbAcceptsWarehouseCode('ZZ')
            && !$this->dbAcceptsCompanyCode('zz')
            && !$this->dbAcceptsWarehouseCode('zz')
            && $this->codesStoredUppercase();

        if (
            !$uppercaseBeforeRollback
            || $rollback !== 'rolled_back'
            || !$rollbackRestoredLowercaseMinTwo
            || $migrate !== 'applied'
            || $migrateSecondRun !== 'already_applied'
            || !$uppercaseAfterMigrate
        ) {
            throw new RuntimeException(
                'CONFIG-OPERACION-CODIGOS-UPPER-1 migration verification failed.'
            );
        }

        return [
            'uppercase_before_rollback' => $uppercaseBeforeRollback,
            'rollback' => $rollback,
            'rollback_restored_lowercase_min_two' => $rollbackRestoredLowercaseMinTwo,
            'migrate' => $migrate,
            'migrate_second_run' => $migrateSecondRun,
            'uppercase_after_migrate' => $uppercaseAfterMigrate,
        ];
    }

    private function companyCases(): bool
    {
        $service = $this->companies();
        $contextCompany = $this->activeContext()['company'];
        $fullId = $service->create($this->fullCompany(), $this->adminId);
        $minimalId = $service->create([
            'codigo' => 'gr',
            'nombre' => 'QA Empresa mínima',
        ], $this->adminId);
        $normalizedId = $service->create([
            'codigo' => 'grupo refrigerantes qa',
            'nombre' => 'QA Empresa Normalizable',
        ], $this->adminId);

        $service->update($fullId, array_replace($this->fullCompany(), [
            'nombre' => 'QA Empresa Editada',
            'email' => 'qa-edicion@example.test',
        ]), $this->adminId);
        $service->setActive($minimalId, false, $this->adminId, null);
        $service->setActive($minimalId, true, $this->adminId, null);

        $updatedFull = $service->get($fullId);
        $normalized = $service->get($normalizedId);

        return $normalized['codigo'] === self::COMPANY_CODE_NORMALIZED
            && $updatedFull['telefono'] === '5555555555'
            && $updatedFull['email'] === 'qa-edicion@example.test'
            && $this->fails(fn () => $service->create([
            'codigo' => '',
            'nombre' => 'QA sin código',
        ], $this->adminId))
            && $this->fails(fn () => $service->create([
                'codigo' => 'G',
                'nombre' => 'QA código corto',
            ], $this->adminId))
            && $this->fails(fn () => $service->create([
                'codigo' => 'GR@',
                'nombre' => 'QA código inseguro',
            ], $this->adminId))
            && $this->fails(fn () => $service->create([
                'codigo' => 'ÑANDU',
                'nombre' => 'QA código con acento',
            ], $this->adminId))
            && $this->fails(fn () => $service->create([
                'codigo' => self::COMPANY_CODE,
                'nombre' => 'QA duplicado',
            ], $this->adminId))
            && $this->fails(fn () => $service->update($fullId, array_replace($this->fullCompany(), [
                'codigo' => 'g',
                'nombre' => 'QA edición inválida',
            ]), $this->adminId))
            && $this->fails(fn () => $service->create([
                'codigo' => 'QA-CONFIG-BAD-EMAIL',
                'nombre' => 'QA email inválido',
                'email' => 'no-es-email',
            ], $this->adminId))
            && $this->fails(fn () => $service->create([
                'codigo' => 'QA-CONFIG-BAD-RFC',
                'nombre' => 'QA RFC inválido',
                'rfc' => 'INVALIDO',
            ], $this->adminId))
            && $this->fails(fn () => $service->setActive(
                $contextCompany,
                false,
                $this->adminId,
                ['id' => $contextCompany]
            ));
    }

    private function warehouseCases(): bool
    {
        $companyA = $this->companyId(self::COMPANY_CODE);
        $companyB = $this->companies()->create([
            'codigo' => self::COMPANY_CODE_SECOND,
            'nombre' => 'QA Empresa segunda',
        ], $this->adminId);
        $service = $this->warehouses();
        $contextWarehouse = $this->activeContext()['warehouse'];

        $warehouseA = $service->create($this->warehouse($companyA, 'bo'), $this->adminId);
        $warehouseB = $service->create(
            array_replace(
                $this->warehouse($companyA, self::WAREHOUSE_CODE_SECOND),
                ['es_principal' => '1']
            ),
            $this->adminId
        );
        $service->create($this->warehouse($companyA, self::WAREHOUSE_CODE_SHARED), $this->adminId);
        $service->create($this->warehouse($companyB, self::WAREHOUSE_CODE_SHARED), $this->adminId);
        $normalizedId = $service->create(
            $this->warehouse($companyA, 'Bodega Principal'),
            $this->adminId
        );
        $service->update(
            $warehouseA,
            array_replace($this->warehouse($companyA, self::WAREHOUSE_CODE), [
                'nombre' => 'QA Almacén Editado',
                'tipo_almacen' => 'SERVICIO',
                'email' => 'qa-almacen-edicion@example.test',
            ]),
            $this->adminId
        );
        $service->setActive($warehouseA, false, $this->adminId, null);
        $service->setActive($warehouseA, true, $this->adminId, null);
        $updatedWarehouse = $service->get($warehouseA);
        $normalizedWarehouse = $service->get($normalizedId);

        return $this->principalCount($companyA) === 1
            && $normalizedWarehouse['codigo'] === self::WAREHOUSE_CODE_NORMALIZED
            && $updatedWarehouse['telefono'] === '5555555555'
            && $updatedWarehouse['email'] === 'qa-almacen-edicion@example.test'
            && $this->fails(fn () => $service->create(
            $this->warehouse(99999999, 'qa-config-noempresa'),
            $this->adminId
        ))
            && $this->fails(fn () => $service->create(
                $this->warehouse($companyA, self::WAREHOUSE_CODE),
                $this->adminId
            ))
            && $this->fails(fn () => $service->create(
                $this->warehouse($companyA, 'B'),
                $this->adminId
            ))
            && $this->fails(fn () => $service->create(
                $this->warehouse($companyA, 'BO@'),
                $this->adminId
            ))
            && $this->fails(fn () => $service->create(
                $this->warehouse($companyA, 'ÑANDU'),
                $this->adminId
            ))
            && $this->fails(fn () => $service->update($warehouseA, array_replace(
                $this->warehouse($companyA, self::WAREHOUSE_CODE),
                ['codigo' => 'B']
            ), $this->adminId))
            && $this->fails(fn () => $service->create(
                array_replace($this->warehouse($companyA, 'QA-CONFIG-TIPO-BAD'), [
                    'tipo_almacen' => 'NO_VALIDO',
                ]),
                $this->adminId
            ))
            && $this->fails(fn () => $service->create(
                array_replace($this->warehouse($companyA, 'QA-CONFIG-MAIL-BAD'), [
                    'email' => 'no-es-email',
                ]),
                $this->adminId
            ))
            && $this->fails(fn () => $service->setActive(
                $contextWarehouse,
                false,
                $this->adminId,
                ['id' => $contextWarehouse]
            ))
            && $this->warehouses()->get($warehouseB)['es_principal'] == 1;
    }

    private function adminRelationsIdempotent(): bool
    {
        $companyId = $this->companyId(self::COMPANY_CODE);
        $warehouseId = $this->warehouseIdForCompany(
            self::WAREHOUSE_CODE,
            $companyId
        );
        $this->companyRepository()->assignUser($companyId, $this->adminId);
        $this->companyRepository()->assignUser($companyId, $this->adminId);
        $this->warehouseRepository()->assignUser(
            $warehouseId,
            $companyId,
            $this->adminId
        );
        $this->warehouseRepository()->assignUser(
            $warehouseId,
            $companyId,
            $this->adminId
        );

        return $this->relationCount('usuario_empresas', $companyId) === 1
            && $this->relationCount('usuario_almacenes', $warehouseId) === 1;
    }

    private function contextStillResolves(): bool
    {
        $resolved = (new UserScopeService(
            new ScopeRepository($this->provider())
        ))->resolveForUser($this->adminId);

        $companyIds = [];

        foreach ($resolved->companies() as $company) {
            $companyIds[(int) $company['id']] = true;
        }

        foreach ($resolved->warehouses() as $warehouse) {
            if (isset($companyIds[(int) $warehouse['company_id']])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function fullCompany(): array
    {
        return [
            'codigo' => self::COMPANY_CODE,
            'nombre' => 'QA Empresa Configuración',
            'razon_social' => 'QA Empresa Configuración SA de CV',
            'nombre_comercial' => 'QA Config',
            'rfc' => 'QAC010101AB1',
            'regimen_fiscal' => '601',
            'telefono' => '5555555555',
            'email' => 'qa@example.test',
            'sitio_web' => 'https://example.test',
            'pais' => 'México',
            'estado' => 'Ciudad de México',
            'municipio' => 'Benito Juárez',
            'colonia' => 'QA',
            'calle' => 'Calle QA',
            'numero_exterior' => '1',
            'numero_interior' => 'A',
            'codigo_postal' => '03100',
            'logo_path' => 'storage/logos/qa.png',
            'color_primario' => '#1A2B3C',
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function warehouse(int $companyId, string $code): array
    {
        return [
            'empresa_id' => (string) $companyId,
            'codigo' => $code,
            'nombre' => 'QA Almacén Configuración',
            'tipo_almacen' => 'GENERAL',
            'responsable' => 'Responsable QA',
            'telefono' => '5555555555',
            'email' => 'qa-almacen@example.test',
            'pais' => 'México',
            'estado' => 'Ciudad de México',
            'municipio' => 'Benito Juárez',
            'colonia' => 'QA',
            'calle' => 'Calle QA',
            'numero_exterior' => '2',
            'numero_interior' => 'B',
            'codigo_postal' => '03100',
            'permite_ventas' => '1',
            'permite_compras' => '1',
            'permite_inventario' => '1',
            'permite_transferencias' => '1',
            'es_principal' => '0',
        ];
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (ConfigurationValidationException) {
            return true;
        }

        return false;
    }

    private function companies(): CompanyService
    {
        return new CompanyService($this->companyRepository());
    }

    private function warehouses(): WarehouseService
    {
        return new WarehouseService($this->warehouseRepository());
    }

    private function companyRepository(): CompanyRepository
    {
        return new CompanyRepository($this->provider());
    }

    private function warehouseRepository(): WarehouseRepository
    {
        return new WarehouseRepository($this->provider());
    }

    private function provider(): ConnectionProvider
    {
        return new ConnectionProvider($this->databaseConfig());
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
     * @return array{company: int, warehouse: int}
     */
    private function activeContext(): array
    {
        $row = $this->pdo->query(
            'SELECT e.id AS empresa_id, a.id AS almacen_id
             FROM empresas e
             INNER JOIN almacenes a ON a.empresa_id = e.id
             WHERE e.codigo = "GRUPO-REFRIGERANTES"
               AND a.codigo = "PRINCIPAL"
             LIMIT 1'
        )->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Initial active context was not found.');
        }

        return [
            'company' => (int) $row['empresa_id'],
            'warehouse' => (int) $row['almacen_id'],
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

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function checkUsesMinimum(string $constraint, int $minimum): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT CHECK_CLAUSE
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = :constraint_name
             LIMIT 1'
        );
        $statement->execute(['constraint_name' => $constraint]);
        $clause = (string) $statement->fetchColumn();
        $normalized = strtolower(str_replace(['`', ' '], '', $clause));

        return str_contains(
            $normalized,
            'char_length(codigo)between' . $minimum . 'and64'
        );
    }

    private function checkUsesCaseFunction(string $constraint, string $function): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT CHECK_CLAUSE
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = :constraint_name
             LIMIT 1'
        );
        $statement->execute(['constraint_name' => $constraint]);
        $clause = (string) $statement->fetchColumn();
        $normalized = strtolower(str_replace(['`', ' '], '', $clause));

        return str_contains($normalized, 'codigo=' . $function . '(codigo)');
    }

    private function codesStoredUppercase(): bool
    {
        $statement = $this->pdo->query(
            'SELECT COUNT(*)
             FROM empresas
             WHERE codigo <> UPPER(codigo)'
        );
        $lowerCompanies = (int) $statement->fetchColumn();
        $statement = $this->pdo->query(
            'SELECT COUNT(*)
             FROM almacenes
             WHERE codigo <> UPPER(codigo)'
        );
        $lowerWarehouses = (int) $statement->fetchColumn();

        return $lowerCompanies === 0 && $lowerWarehouses === 0;
    }

    private function dbAcceptsCompanyCode(string $code): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO empresas (codigo, nombre) VALUES (:codigo, :nombre)'
            );
            $statement->execute([
                'codigo' => $code,
                'nombre' => 'QA Código CHECK',
            ]);

            return true;
        } catch (PDOException) {
            return false;
        } finally {
            $statement = $this->pdo->prepare(
                'DELETE FROM empresas WHERE codigo = :codigo'
            );
            $statement->execute(['codigo' => $code]);
        }
    }

    private function dbAcceptsWarehouseCode(string $code): bool
    {
        $companyCode = strtolower($code) === $code
            ? 'qa-config-check-parent'
            : 'QA-CONFIG-CHECK-PARENT';
        $companyId = 0;

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO empresas (codigo, nombre) VALUES (:codigo, :nombre)'
            );
            $statement->execute([
                'codigo' => $companyCode,
                'nombre' => 'QA Empresa CHECK',
            ]);
            $companyId = (int) $this->pdo->lastInsertId();

            $statement = $this->pdo->prepare(
                'INSERT INTO almacenes (empresa_id, codigo, nombre)
                 VALUES (:empresa_id, :codigo, :nombre)'
            );
            $statement->execute([
                'empresa_id' => $companyId,
                'codigo' => $code,
                'nombre' => 'QA Almacén CHECK',
            ]);

            return true;
        } catch (PDOException) {
            return false;
        } finally {
            if ($companyId > 0) {
                $statement = $this->pdo->prepare(
                    'DELETE FROM almacenes WHERE empresa_id = :empresa_id'
                );
                $statement->execute(['empresa_id' => $companyId]);
            }

            $statement = $this->pdo->prepare(
                'DELETE FROM empresas WHERE codigo = :codigo'
            );
            $statement->execute(['codigo' => $companyCode]);
        }
    }

    private function permissionCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM permisos
             WHERE codigo LIKE 'configuracion.empresas.%'
                OR codigo LIKE 'configuracion.almacenes.%'"
        )->fetchColumn();
    }

    private function adminPermissionCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*)
             FROM permisos p
             INNER JOIN rol_permisos rp
                ON rp.permiso_id = p.id
               AND rp.activo = 1
               AND rp.eliminado_en IS NULL
             INNER JOIN roles r
                ON r.id = rp.rol_id
               AND r.codigo = 'ADMIN'
               AND r.activo = 1
               AND r.eliminado_en IS NULL
             WHERE p.activo = 1
               AND p.eliminado_en IS NULL
               AND (
                   p.codigo LIKE 'configuracion.empresas.%'
                   OR p.codigo LIKE 'configuracion.almacenes.%'
               )"
        )->fetchColumn();
    }

    private function permissionDuplicates(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*)
             FROM (
                SELECT codigo
                FROM permisos
                WHERE codigo LIKE 'configuracion.empresas.%'
                   OR codigo LIKE 'configuracion.almacenes.%'
                GROUP BY codigo
                HAVING COUNT(*) > 1
             ) duplicates"
        )->fetchColumn();
    }

    private function relationDuplicates(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*)
             FROM (
                SELECT rp.rol_id, rp.permiso_id
                FROM rol_permisos rp
                INNER JOIN permisos p ON p.id = rp.permiso_id
                WHERE p.codigo LIKE 'configuracion.empresas.%'
                   OR p.codigo LIKE 'configuracion.almacenes.%'
                GROUP BY rp.rol_id, rp.permiso_id
                HAVING COUNT(*) > 1
             ) duplicates"
        )->fetchColumn();
    }

    private function companyId(string $code): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM empresas WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('QA company was not found.');
        }

        return $id;
    }

    private function warehouseId(string $code): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM almacenes WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('QA warehouse was not found.');
        }

        return $id;
    }

    private function warehouseIdForCompany(string $code, int $companyId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id
             FROM almacenes
             WHERE empresa_id = :empresa_id
               AND codigo = :codigo
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
        ]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('QA warehouse was not found for company.');
        }

        return $id;
    }

    private function principalCount(int $companyId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM almacenes
             WHERE empresa_id = :empresa_id
               AND es_principal = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['empresa_id' => $companyId]);

        return (int) $statement->fetchColumn();
    }

    private function relationCount(string $table, int $id): int
    {
        $sql = match ($table) {
            'usuario_empresas' =>
                'SELECT COUNT(*) FROM usuario_empresas
                 WHERE usuario_id = :usuario_id AND empresa_id = :id',
            'usuario_almacenes' =>
                'SELECT COUNT(*) FROM usuario_almacenes
                 WHERE usuario_id = :usuario_id AND almacen_id = :id',
            default => throw new InvalidArgumentException('Invalid relation table.'),
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['usuario_id' => $this->adminId, 'id' => $id]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function qaCounts(): array
    {
        return [
            'empresas_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM empresas
                 WHERE codigo LIKE 'qa-config-%'
                    OR codigo LIKE 'QA-CONFIG-%'
                    OR codigo IN ('gr', 'gr-empresa', 'GR', 'GRUPO-REFRIGERANTES-QA')"
            )->fetchColumn(),
            'almacenes_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM almacenes a
                 INNER JOIN empresas e ON e.id = a.empresa_id
                 WHERE a.codigo LIKE 'qa-config-%'
                    OR a.codigo LIKE 'QA-CONFIG-%'
                    OR (
                        a.codigo IN ('bo', 'bodega-principal', 'BO', 'BODEGA-PRINCIPAL')
                        AND (
                            e.codigo LIKE 'qa-config-%'
                            OR e.codigo LIKE 'QA-CONFIG-%'
                            OR e.codigo IN ('gr', 'gr-empresa', 'GR', 'GRUPO-REFRIGERANTES-QA')
                        )
                    )"
            )->fetchColumn(),
            'usuario_empresas_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM usuario_empresas ue
                 INNER JOIN empresas e ON e.id = ue.empresa_id
                 WHERE e.codigo LIKE 'qa-config-%'
                    OR e.codigo LIKE 'QA-CONFIG-%'
                    OR e.codigo IN ('gr', 'gr-empresa', 'GR', 'GRUPO-REFRIGERANTES-QA')"
            )->fetchColumn(),
            'usuario_almacenes_qa' => (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM usuario_almacenes ua
                 INNER JOIN almacenes a ON a.id = ua.almacen_id
                 INNER JOIN empresas e ON e.id = a.empresa_id
                 WHERE a.codigo LIKE 'qa-config-%'
                    OR a.codigo LIKE 'QA-CONFIG-%'
                    OR (
                        a.codigo IN ('bo', 'bodega-principal', 'BO', 'BODEGA-PRINCIPAL')
                        AND (
                            e.codigo LIKE 'qa-config-%'
                            OR e.codigo LIKE 'QA-CONFIG-%'
                            OR e.codigo IN ('gr', 'gr-empresa', 'GR', 'GRUPO-REFRIGERANTES-QA')
                        )
                    )"
            )->fetchColumn(),
            'usuarios_qa' => 0,
        ];
    }

    private function cleanup(): void
    {
        foreach ([
            "DELETE ua FROM usuario_almacenes ua
             INNER JOIN almacenes a ON a.id = ua.almacen_id
             INNER JOIN empresas e ON e.id = a.empresa_id
             WHERE a.codigo LIKE 'qa-config-%'
                OR a.codigo LIKE 'QA-CONFIG-%'
                OR (
                    a.codigo IN ('bo', 'bodega-principal', 'BO', 'BODEGA-PRINCIPAL')
                    AND (
                        e.codigo LIKE 'qa-config-%'
                        OR e.codigo LIKE 'QA-CONFIG-%'
                        OR e.codigo IN ('gr', 'gr-empresa', 'GR', 'GRUPO-REFRIGERANTES-QA')
                    )
                )",
            "DELETE ue FROM usuario_empresas ue
             INNER JOIN empresas e ON e.id = ue.empresa_id
             WHERE e.codigo LIKE 'qa-config-%'
                OR e.codigo LIKE 'QA-CONFIG-%'
                OR e.codigo IN ('gr', 'gr-empresa', 'GR', 'GRUPO-REFRIGERANTES-QA')",
            "DELETE a FROM almacenes a
             INNER JOIN empresas e ON e.id = a.empresa_id
             WHERE a.codigo LIKE 'qa-config-%'
                OR a.codigo LIKE 'QA-CONFIG-%'
                OR (
                    a.codigo IN ('bo', 'bodega-principal', 'BO', 'BODEGA-PRINCIPAL')
                    AND (
                        e.codigo LIKE 'qa-config-%'
                        OR e.codigo LIKE 'QA-CONFIG-%'
                        OR e.codigo IN ('gr', 'gr-empresa', 'GR', 'GRUPO-REFRIGERANTES-QA')
                    )
                )",
            "DELETE FROM empresas
             WHERE codigo LIKE 'qa-config-%'
                OR codigo LIKE 'QA-CONFIG-%'
                OR codigo IN ('gr', 'gr-empresa', 'GR', 'GRUPO-REFRIGERANTES-QA')",
        ] as $statement) {
            $this->pdo->exec($statement);
        }
    }
};
