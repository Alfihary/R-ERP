<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

if (!isset($initialAdminUsername, $initialAdminEmail)
    || !is_string($initialAdminUsername)
    || !is_string($initialAdminEmail)
) {
    throw new RuntimeException('DB-SCOPE-1 DB-TEST requires the configured administrator.');
}

return new class($initialAdminUsername, $initialAdminEmail) implements DatabaseTest {
    /**
     * @var list<string>
     */
    private const TABLES = [
        'empresas',
        'almacenes',
        'usuario_empresas',
        'usuario_almacenes',
    ];

    public function __construct(
        private readonly string $adminUsername,
        private readonly string $adminEmail
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $currentDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($currentDatabase !== $expectedDatabase) {
            throw new RuntimeException('The active database does not match DB-TEST-SCOPE.');
        }

        $this->assertPrerequisites($pdo);
        $metadata = $this->tableMetadata($pdo, $expectedDatabase);

        if (count($metadata) !== count(self::TABLES)) {
            throw new RuntimeException('DB-SCOPE-1 does not contain every expected table.');
        }

        foreach ($metadata as $table) {
            if (strtoupper((string) $table['engine']) !== 'INNODB') {
                throw new RuntimeException('Every DB-SCOPE-1 table must use InnoDB.');
            }

            if (strtolower((string) $table['table_collation']) !== 'utf8mb4_unicode_ci') {
                throw new RuntimeException(
                    'Every DB-SCOPE-1 table must use utf8mb4_unicode_ci.'
                );
            }
        }

        $indexes = $this->indexNames($pdo, $expectedDatabase);
        $requiredIndexes = [
            'empresas.PRIMARY',
            'empresas.uq_empresas_codigo',
            'almacenes.PRIMARY',
            'almacenes.uq_almacenes_empresa_codigo',
            'almacenes.uq_almacenes_empresa_id',
            'usuario_empresas.PRIMARY',
            'usuario_empresas.idx_usuario_empresas_empresa_activo',
            'usuario_almacenes.PRIMARY',
            'usuario_almacenes.idx_usuario_almacenes_usuario_empresa',
            'usuario_almacenes.idx_usuario_almacenes_empresa_almacen',
            'usuario_almacenes.idx_usuario_almacenes_almacen_activo',
        ];

        foreach ($requiredIndexes as $requiredIndex) {
            if (!in_array($requiredIndex, $indexes, true)) {
                throw new RuntimeException('Required index is missing: ' . $requiredIndex);
            }
        }

        $foreignKeys = $this->foreignKeyNames($pdo, $expectedDatabase);
        $requiredForeignKeys = [
            'fk_almacenes_empresa',
            'fk_usuario_empresas_usuario',
            'fk_usuario_empresas_empresa',
            'fk_usuario_almacenes_usuario',
            'fk_usuario_almacenes_almacen',
            'fk_usuario_almacenes_usuario_empresa',
            'fk_usuario_almacenes_empresa_almacen',
        ];

        foreach ($requiredForeignKeys as $requiredForeignKey) {
            if (!in_array($requiredForeignKey, $foreignKeys, true)) {
                throw new RuntimeException(
                    'Required foreign key is missing: ' . $requiredForeignKey
                );
            }
        }

        $adminId = $this->initialAdminId($pdo);
        $seed = $this->seedEvidence($pdo, $adminId);

        if ($seed['empresas'] !== 1
            || $seed['almacenes'] !== 1
            || $seed['usuario_empresas'] !== 1
            || $seed['usuario_almacenes'] !== 1
        ) {
            throw new RuntimeException('DB-SCOPE-1 persistent seed counts are invalid.');
        }

        if ($this->duplicateCount($pdo, 'empresas', ['codigo']) !== 0
            || $this->duplicateCount(
                $pdo,
                'almacenes',
                ['empresa_id', 'codigo']
            ) !== 0
            || $this->duplicateCount(
                $pdo,
                'usuario_empresas',
                ['usuario_id', 'empresa_id']
            ) !== 0
            || $this->duplicateCount(
                $pdo,
                'usuario_almacenes',
                ['usuario_id', 'almacen_id']
            ) !== 0
        ) {
            throw new RuntimeException('DB-SCOPE-1 contains duplicate scope records.');
        }

        $expectedFailures = [];
        $pdo->beginTransaction();

        try {
            $companyA = $this->insertCompany(
                $pdo,
                'db-scope-valid-a',
                'DB Scope Valid A',
                $adminId
            );
            $warehouseA = $this->insertWarehouse(
                $pdo,
                $companyA,
                'valid-a',
                'DB Scope Warehouse A',
                $adminId
            );
            $this->insertUserCompany($pdo, $adminId, $companyA, $adminId);
            $this->insertUserWarehouse(
                $pdo,
                $adminId,
                $companyA,
                $warehouseA,
                $adminId
            );

            $companyB = $this->insertCompany(
                $pdo,
                'db-scope-valid-b',
                'DB Scope Valid B',
                $adminId
            );
            $warehouseB = $this->insertWarehouse(
                $pdo,
                $companyB,
                'valid-b',
                'DB Scope Warehouse B',
                $adminId
            );

            $invalidCompanyId = (int) $pdo->query(
                'SELECT COALESCE(MAX(id), 0) + 100000 FROM empresas'
            )->fetchColumn();
            $invalidUserId = (int) $pdo->query(
                'SELECT COALESCE(MAX(id), 0) + 100000 FROM usuarios'
            )->fetchColumn();

            $this->expectConstraintFailure(
                fn () => $this->insertCompany(
                    $pdo,
                    'grupo-refrigerantes',
                    'Duplicate Company',
                    $adminId
                ),
                'duplicate_company_code',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertWarehouse(
                    $pdo,
                    $companyA,
                    'valid-a',
                    'Duplicate Warehouse',
                    $adminId
                ),
                'duplicate_warehouse_code_per_company',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertWarehouse(
                    $pdo,
                    $invalidCompanyId,
                    'orphan',
                    'Orphan Warehouse',
                    $adminId
                ),
                'orphan_warehouse',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserCompany(
                    $pdo,
                    $invalidUserId,
                    $companyA,
                    $adminId
                ),
                'invalid_user_company',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserCompany(
                    $pdo,
                    $adminId,
                    $companyA,
                    $adminId
                ),
                'duplicate_user_company',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserWarehouse(
                    $pdo,
                    $adminId,
                    $companyA,
                    $warehouseA,
                    $adminId
                ),
                'duplicate_user_warehouse',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserWarehouse(
                    $pdo,
                    $adminId,
                    $companyA,
                    $warehouseB,
                    $adminId
                ),
                'warehouse_from_different_company',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserWarehouse(
                    $pdo,
                    $adminId,
                    $companyB,
                    $warehouseB,
                    $adminId
                ),
                'warehouse_without_user_company',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                function () use ($pdo, $adminId): void {
                    $statement = $pdo->prepare(
                        'INSERT INTO empresas (
                            codigo,
                            nombre,
                            activo,
                            creado_por
                         )
                         VALUES (:codigo, :nombre, :activo, :creado_por)'
                    );
                    $statement->execute([
                        'codigo' => 'db-scope-invalid-active',
                        'nombre' => 'Invalid Active Company',
                        'activo' => 2,
                        'creado_por' => $adminId,
                    ]);
                },
                'invalid_company_active_state',
                $expectedFailures
            );

            $countUserCompany = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM usuario_empresas
                 WHERE empresa_id = :empresa_id'
            );
            $countUserCompany->execute(['empresa_id' => $companyA]);
            $countUserWarehouse = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM usuario_almacenes
                 WHERE almacen_id = :almacen_id'
            );
            $countUserWarehouse->execute(['almacen_id' => $warehouseA]);

            $transientCounts = [
                'empresas' => (int) $pdo->query(
                    "SELECT COUNT(*) FROM empresas
                     WHERE codigo IN ('db-scope-valid-a', 'db-scope-valid-b')"
                )->fetchColumn(),
                'almacenes' => (int) $pdo->query(
                    "SELECT COUNT(*) FROM almacenes
                     WHERE codigo IN ('valid-a', 'valid-b')"
                )->fetchColumn(),
                'usuario_empresas' => (int) $countUserCompany->fetchColumn(),
                'usuario_almacenes' => (int) $countUserWarehouse->fetchColumn(),
            ];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $requiredFailures = [
            'duplicate_company_code',
            'duplicate_warehouse_code_per_company',
            'orphan_warehouse',
            'invalid_user_company',
            'duplicate_user_company',
            'duplicate_user_warehouse',
            'warehouse_from_different_company',
            'warehouse_without_user_company',
            'invalid_company_active_state',
        ];
        sort($expectedFailures);
        sort($requiredFailures);

        if ($expectedFailures !== $requiredFailures) {
            throw new RuntimeException('DB-TEST-SCOPE did not reject every invalid case.');
        }

        if ($transientCounts !== [
            'empresas' => 2,
            'almacenes' => 2,
            'usuario_empresas' => 1,
            'usuario_almacenes' => 1,
        ]) {
            throw new RuntimeException('DB-TEST-SCOPE valid transient records were not accepted.');
        }

        return [
            'database' => $currentDatabase,
            'mysql_version' => (string) $pdo->query('SELECT VERSION()')->fetchColumn(),
            'tables' => array_column($metadata, 'table_name'),
            'engines' => array_values(array_unique(array_column($metadata, 'engine'))),
            'collations' => array_values(
                array_unique(array_column($metadata, 'table_collation'))
            ),
            'required_indexes' => $requiredIndexes,
            'required_foreign_keys' => $requiredForeignKeys,
            'seed_counts' => $seed,
            'duplicate_counts' => [
                'empresas' => 0,
                'almacenes' => 0,
                'usuario_empresas' => 0,
                'usuario_almacenes' => 0,
            ],
            'valid_transient_counts' => $transientCounts,
            'expected_failures' => $expectedFailures,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function assertPrerequisites(PDO $pdo): void
    {
        $migration = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration IN (:core_migration, :scope_migration)'
        );
        $migration->execute([
            'core_migration' => 'db_core_0_001_create_core_identity_tables',
            'scope_migration' => 'db_scope_1_001_create_scope_tables',
        ]);

        if ((int) $migration->fetchColumn() !== 2) {
            throw new RuntimeException('DB-TEST-SCOPE requires DB-CORE-0 and DB-SCOPE-1.');
        }

        $permissions = $pdo->prepare(
            'SELECT COUNT(*)
             FROM permisos
             WHERE codigo IN (:permission_1, :permission_2, :permission_3)
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $permissions->execute([
            'permission_1' => 'sistema.acceder',
            'permission_2' => 'sistema.app.ver',
            'permission_3' => 'seguridad.rbac.ver',
        ]);

        if ((int) $permissions->fetchColumn() !== 3) {
            throw new RuntimeException('DB-TEST-SCOPE requires the RBAC-0 base permissions.');
        }
    }

    /**
     * @return list<array{table_name: string, engine: string, table_collation: string}>
     */
    private function tableMetadata(PDO $pdo, string $database): array
    {
        $placeholders = [];
        $parameters = ['database' => $database];

        foreach (self::TABLES as $index => $table) {
            $key = 'table_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $table;
        }

        $statement = $pdo->prepare(
            'SELECT
                 table_name AS table_name,
                 engine AS engine,
                 table_collation AS table_collation
             FROM information_schema.tables
             WHERE table_schema = :database
               AND table_name IN (' . implode(', ', $placeholders) . ')
             ORDER BY table_name'
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * @return list<string>
     */
    private function indexNames(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT DISTINCT CONCAT(table_name, '.', index_name) AS index_key
             FROM information_schema.statistics
             WHERE table_schema = :database
               AND table_name IN (
                   'empresas',
                   'almacenes',
                   'usuario_empresas',
                   'usuario_almacenes'
               )
             ORDER BY index_key"
        );
        $statement->execute(['database' => $database]);

        return array_column($statement->fetchAll(), 'index_key');
    }

    /**
     * @return list<string>
     */
    private function foreignKeyNames(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT DISTINCT constraint_name AS constraint_name
             FROM information_schema.referential_constraints
             WHERE constraint_schema = :database
               AND table_name IN (
                   'empresas',
                   'almacenes',
                   'usuario_empresas',
                   'usuario_almacenes'
               )
             ORDER BY constraint_name"
        );
        $statement->execute(['database' => $database]);

        return array_column($statement->fetchAll(), 'constraint_name');
    }

    /**
     * @return array{empresas: int, almacenes: int, usuario_empresas: int, usuario_almacenes: int}
     */
    private function seedEvidence(PDO $pdo, int $adminId): array
    {
        $company = $pdo->prepare(
            'SELECT id
             FROM empresas
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $company->execute(['codigo' => 'grupo-refrigerantes']);
        $companyId = $company->fetchColumn();

        if ($companyId === false) {
            throw new RuntimeException('The DB-SCOPE-1 company seed is missing.');
        }

        $warehouse = $pdo->prepare(
            'SELECT id
             FROM almacenes
             WHERE empresa_id = :empresa_id
               AND codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $warehouse->execute([
            'empresa_id' => $companyId,
            'codigo' => 'principal',
        ]);
        $warehouseId = $warehouse->fetchColumn();

        if ($warehouseId === false) {
            throw new RuntimeException('The DB-SCOPE-1 warehouse seed is missing.');
        }

        $userCompany = $pdo->prepare(
            'SELECT COUNT(*)
             FROM usuario_empresas
             WHERE usuario_id = :usuario_id
               AND empresa_id = :empresa_id
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $userCompany->execute([
            'usuario_id' => $adminId,
            'empresa_id' => $companyId,
        ]);

        $userWarehouse = $pdo->prepare(
            'SELECT COUNT(*)
             FROM usuario_almacenes
             WHERE usuario_id = :usuario_id
               AND empresa_id = :empresa_id
               AND almacen_id = :almacen_id
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $userWarehouse->execute([
            'usuario_id' => $adminId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
        ]);

        return [
            'empresas' => (int) $pdo->query('SELECT COUNT(*) FROM empresas')
                ->fetchColumn(),
            'almacenes' => (int) $pdo->query('SELECT COUNT(*) FROM almacenes')
                ->fetchColumn(),
            'usuario_empresas' => (int) $userCompany->fetchColumn(),
            'usuario_almacenes' => (int) $userWarehouse->fetchColumn(),
        ];
    }

    private function initialAdminId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM usuarios
             WHERE username = :username
               AND email = :email
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'username' => strtolower(trim($this->adminUsername)),
            'email' => strtolower(trim($this->adminEmail)),
        ]);
        $userId = $statement->fetchColumn();

        if ($userId === false) {
            throw new RuntimeException('The configured initial administrator is missing.');
        }

        return (int) $userId;
    }

    /**
     * @param list<string> $columns
     */
    private function duplicateCount(PDO $pdo, string $table, array $columns): int
    {
        $allowed = [
            'empresas' => ['codigo'],
            'almacenes' => ['empresa_id', 'codigo'],
            'usuario_empresas' => ['usuario_id', 'empresa_id'],
            'usuario_almacenes' => ['usuario_id', 'almacen_id'],
        ];

        if (($allowed[$table] ?? null) !== $columns) {
            throw new InvalidArgumentException('Unsafe duplicate check definition.');
        }

        $group = implode(', ', $columns);

        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT ' . $group . '
                 FROM ' . $table . '
                 GROUP BY ' . $group . '
                 HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
    }

    private function insertCompany(
        PDO $pdo,
        string $code,
        string $name,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO empresas (
                codigo,
                nombre,
                activo,
                creado_por,
                actualizado_por
             )
             VALUES (:codigo, :nombre, 1, :creado_por, :actualizado_por)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertWarehouse(
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
                creado_por,
                actualizado_por
             )
             VALUES (
                :empresa_id,
                :codigo,
                :nombre,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertUserCompany(
        PDO $pdo,
        int $userId,
        int $companyId,
        int $actorId
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO usuario_empresas (
                usuario_id,
                empresa_id,
                activo,
                creado_por,
                actualizado_por
             )
             VALUES (
                :usuario_id,
                :empresa_id,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }

    private function insertUserWarehouse(
        PDO $pdo,
        int $userId,
        int $companyId,
        int $warehouseId,
        int $actorId
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO usuario_almacenes (
                usuario_id,
                empresa_id,
                almacen_id,
                activo,
                creado_por,
                actualizado_por
             )
             VALUES (
                :usuario_id,
                :empresa_id,
                :almacen_id,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }

    /**
     * @param list<string> $failures
     */
    private function expectConstraintFailure(
        callable $operation,
        string $label,
        array &$failures
    ): void {
        try {
            $operation();
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            if (!in_array($driverCode, [1062, 1452, 3819, 4025], true)) {
                throw new RuntimeException(
                    'Unexpected database error while testing: ' . $label,
                    0,
                    $exception
                );
            }

            $failures[] = $label;
            return;
        }

        throw new RuntimeException('Expected constraint failure was accepted: ' . $label);
    }
};
