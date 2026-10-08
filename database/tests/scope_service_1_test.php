<?php

declare(strict_types=1);

use App\Domain\Scope\EffectiveScope;
use App\Domain\Scope\ScopeRepositoryInterface;
use App\Domain\Scope\UserScopeService;
use App\Infrastructure\Database\DatabaseTest;

if (!isset($userScope, $initialAdminUsername, $initialAdminEmail)
    || !$userScope instanceof UserScopeService
    || !is_string($initialAdminUsername)
    || !is_string($initialAdminEmail)
) {
    throw new RuntimeException(
        'SCOPE-SERVICE-1 DB-TEST requires the service and configured administrator.'
    );
}

return new class(
    $userScope,
    $initialAdminUsername,
    $initialAdminEmail
) implements DatabaseTest {
    public function __construct(
        private readonly UserScopeService $userScope,
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
            throw new RuntimeException(
                'The active database does not match SCOPE-SERVICE-1 DB-TEST.'
            );
        }

        $this->assertPrerequisites($pdo);
        $adminId = $this->adminId($pdo);
        $persistentBefore = $this->persistentCounts($pdo);
        $adminScope = $this->userScope->resolveForUser($adminId);
        $this->assertAdminScope($adminScope);

        $results = [];
        $pdo->beginTransaction();

        try {
            $emptyUserId = $this->insertUserWithoutScope($pdo, $adminId);
            $emptyScope = $this->userScope->resolveForUser($emptyUserId);
            $this->assertEmpty($emptyScope, 'User without assignments');
            $results['empty_user_controlled'] = true;

            $companyId = (int) $adminScope->defaultCompany()['id'];
            $warehouseId = (int) $adminScope->defaultWarehouse()['id'];

            $this->setActive($pdo, 'empresas', 'id', $companyId, false);
            $this->assertEmpty(
                $this->userScope->resolveForUser($adminId),
                'Inactive company'
            );
            $this->setActive($pdo, 'empresas', 'id', $companyId, true);
            $results['inactive_company_denied'] = true;

            $this->setActive($pdo, 'almacenes', 'id', $warehouseId, false);
            $this->assertCompanyOnly(
                $this->userScope->resolveForUser($adminId),
                'Inactive warehouse'
            );
            $this->setActive($pdo, 'almacenes', 'id', $warehouseId, true);
            $results['inactive_warehouse_denied'] = true;

            $this->setUserCompanyState($pdo, $adminId, $companyId, false, false);
            $this->assertEmpty(
                $this->userScope->resolveForUser($adminId),
                'Inactive user-company relation'
            );
            $this->setUserCompanyState($pdo, $adminId, $companyId, true, false);
            $results['inactive_user_company_denied'] = true;

            $this->setUserWarehouseState(
                $pdo,
                $adminId,
                $warehouseId,
                false,
                false
            );
            $this->assertCompanyOnly(
                $this->userScope->resolveForUser($adminId),
                'Inactive user-warehouse relation'
            );
            $this->setUserWarehouseState(
                $pdo,
                $adminId,
                $warehouseId,
                true,
                false
            );
            $results['inactive_user_warehouse_denied'] = true;

            $logicalDeletionResults = $this->testLogicalDeletions(
                $pdo,
                $adminId,
                $companyId,
                $warehouseId
            );
            $results['logical_deletions_denied'] = $logicalDeletionResults;

            $mismatchedScope = $this->mismatchedWarehouseScope();
            $this->assertCompanyOnly(
                $mismatchedScope,
                'Warehouse outside an allowed company'
            );
            $results['warehouse_outside_company_denied'] = true;

            $browserInput = [
                'empresa_id' => PHP_INT_MAX,
                'almacen_id' => PHP_INT_MAX,
            ];
            $browserSafeScope = $this->userScope->resolveForUser($adminId);

            if ($browserSafeScope->defaultCompany()['id'] === $browserInput['empresa_id']
                || $browserSafeScope->defaultWarehouse()['id']
                    === $browserInput['almacen_id']
            ) {
                throw new RuntimeException('Browser parameters altered effective scope.');
            }

            $results['browser_parameters_ignored'] = true;
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $persistentAfter = $this->persistentCounts($pdo);

        if ($persistentAfter !== $persistentBefore) {
            throw new RuntimeException(
                'SCOPE-SERVICE-1 DB-TEST left persistent records behind.'
            );
        }

        return [
            'database' => $currentDatabase,
            'admin_scope' => [
                'companies' => count($adminScope->companies()),
                'warehouses' => count($adminScope->warehouses()),
                'default_company' => $adminScope->defaultCompany()['name'],
                'default_warehouse' => $adminScope->defaultWarehouse()['name'],
                'has_scope' => $adminScope->hasScope(),
            ],
            'assertions' => $results,
            'persistent_counts_before' => $persistentBefore,
            'persistent_counts_after' => $persistentAfter,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function assertPrerequisites(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $statement->execute([
            'migration' => 'db_scope_1_001_create_scope_tables',
        ]);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('SCOPE-SERVICE-1 requires DB-SCOPE-1.');
        }
    }

    private function adminId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM usuarios
             WHERE username = :username
               AND email = :email
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'username' => $this->adminUsername,
            'email' => $this->adminEmail,
        ]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException(
                'SCOPE-SERVICE-1 requires the active configured administrator.'
            );
        }

        return $id;
    }

    private function assertAdminScope(EffectiveScope $scope): void
    {
        if (count($scope->companies()) !== 1
            || count($scope->warehouses()) !== 1
            || !$scope->hasScope()
            || ($scope->defaultCompany()['name'] ?? '') !== 'Grupo Refrigerantes'
            || ($scope->defaultWarehouse()['name'] ?? '') !== 'Almacén Principal'
        ) {
            throw new RuntimeException(
                'The administrator effective scope does not match DB-SCOPE-1.'
            );
        }
    }

    private function assertEmpty(EffectiveScope $scope, string $case): void
    {
        if ($scope->companies() !== []
            || $scope->warehouses() !== []
            || $scope->defaultCompany() !== null
            || $scope->defaultWarehouse() !== null
            || $scope->hasScope()
        ) {
            throw new RuntimeException($case . ' should produce empty scope.');
        }
    }

    private function assertCompanyOnly(EffectiveScope $scope, string $case): void
    {
        if (count($scope->companies()) !== 1
            || $scope->warehouses() !== []
            || $scope->defaultCompany() === null
            || $scope->defaultWarehouse() !== null
            || $scope->hasScope()
        ) {
            throw new RuntimeException(
                $case . ' should not produce operational warehouse scope.'
            );
        }
    }

    private function insertUserWithoutScope(PDO $pdo, int $adminId): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (
                username,
                email,
                password_hash,
                activo,
                creado_por
             ) VALUES (
                :username,
                :email,
                :password_hash,
                1,
                :creado_por
             )'
        );
        $statement->execute([
            'username' => 'scope.service.empty',
            'email' => 'scope-service-empty@example.test',
            'password_hash' => password_hash(
                bin2hex(random_bytes(32)),
                PASSWORD_DEFAULT
            ),
            'creado_por' => $adminId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function setActive(
        PDO $pdo,
        string $table,
        string $key,
        int $id,
        bool $active
    ): void {
        $allowed = [
            'empresas' => 'id',
            'almacenes' => 'id',
        ];

        if (($allowed[$table] ?? null) !== $key) {
            throw new InvalidArgumentException('Unsafe scope state update.');
        }

        $statement = $pdo->prepare(
            'UPDATE ' . $table . '
             SET activo = :activo
             WHERE ' . $key . ' = :id'
        );
        $statement->execute([
            'activo' => $active ? 1 : 0,
            'id' => $id,
        ]);
    }

    private function setUserCompanyState(
        PDO $pdo,
        int $userId,
        int $companyId,
        bool $active,
        bool $deleted
    ): void {
        $statement = $pdo->prepare(
            'UPDATE usuario_empresas
             SET activo = :activo,
                 eliminado_en = ' . ($deleted ? 'CURRENT_TIMESTAMP' : 'NULL') . ',
                 eliminado_por = ' . ($deleted ? ':eliminado_por' : 'NULL') . '
             WHERE usuario_id = :usuario_id
               AND empresa_id = :empresa_id'
        );
        $parameters = [
            'activo' => $active ? 1 : 0,
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
        ];

        if ($deleted) {
            $parameters['eliminado_por'] = $userId;
        }

        $statement->execute($parameters);
    }

    private function setUserWarehouseState(
        PDO $pdo,
        int $userId,
        int $warehouseId,
        bool $active,
        bool $deleted
    ): void {
        $statement = $pdo->prepare(
            'UPDATE usuario_almacenes
             SET activo = :activo,
                 eliminado_en = ' . ($deleted ? 'CURRENT_TIMESTAMP' : 'NULL') . ',
                 eliminado_por = ' . ($deleted ? ':eliminado_por' : 'NULL') . '
             WHERE usuario_id = :usuario_id
               AND almacen_id = :almacen_id'
        );
        $parameters = [
            'activo' => $active ? 1 : 0,
            'usuario_id' => $userId,
            'almacen_id' => $warehouseId,
        ];

        if ($deleted) {
            $parameters['eliminado_por'] = $userId;
        }

        $statement->execute($parameters);
    }

    /**
     * @return array<string, bool>
     */
    private function testLogicalDeletions(
        PDO $pdo,
        int $userId,
        int $companyId,
        int $warehouseId
    ): array {
        $this->setDeleted($pdo, 'empresas', $companyId, $userId, true);
        $this->assertEmpty(
            $this->userScope->resolveForUser($userId),
            'Logically deleted company'
        );
        $this->setDeleted($pdo, 'empresas', $companyId, $userId, false);

        $this->setDeleted($pdo, 'almacenes', $warehouseId, $userId, true);
        $this->assertCompanyOnly(
            $this->userScope->resolveForUser($userId),
            'Logically deleted warehouse'
        );
        $this->setDeleted($pdo, 'almacenes', $warehouseId, $userId, false);

        $this->setUserCompanyState($pdo, $userId, $companyId, true, true);
        $this->assertEmpty(
            $this->userScope->resolveForUser($userId),
            'Logically deleted user-company relation'
        );
        $this->setUserCompanyState($pdo, $userId, $companyId, true, false);

        $this->setUserWarehouseState($pdo, $userId, $warehouseId, true, true);
        $this->assertCompanyOnly(
            $this->userScope->resolveForUser($userId),
            'Logically deleted user-warehouse relation'
        );
        $this->setUserWarehouseState($pdo, $userId, $warehouseId, true, false);

        return [
            'company' => true,
            'warehouse' => true,
            'user_company' => true,
            'user_warehouse' => true,
        ];
    }

    private function setDeleted(
        PDO $pdo,
        string $table,
        int $id,
        int $userId,
        bool $deleted
    ): void {
        if (!in_array($table, ['empresas', 'almacenes'], true)) {
            throw new InvalidArgumentException('Unsafe logical deletion update.');
        }

        $statement = $pdo->prepare(
            'UPDATE ' . $table . '
             SET eliminado_en = ' . ($deleted ? 'CURRENT_TIMESTAMP' : 'NULL') . ',
                 eliminado_por = ' . ($deleted ? ':eliminado_por' : 'NULL') . '
             WHERE id = :id'
        );
        $parameters = ['id' => $id];

        if ($deleted) {
            $parameters['eliminado_por'] = $userId;
        }

        $statement->execute($parameters);
    }

    private function mismatchedWarehouseScope(): EffectiveScope
    {
        $service = new UserScopeService(
            new class implements ScopeRepositoryInterface {
                public function companiesForUser(int $userId): array
                {
                    return [[
                        'id' => 10,
                        'code' => 'allowed-company',
                        'name' => 'Allowed Company',
                    ]];
                }

                public function warehousesForUser(int $userId): array
                {
                    return [[
                        'id' => 20,
                        'company_id' => 999,
                        'code' => 'outside-company',
                        'name' => 'Outside Company Warehouse',
                    ]];
                }
            }
        );

        return $service->resolveForUser(1);
    }

    /**
     * @return array<string, int>
     */
    private function persistentCounts(PDO $pdo): array
    {
        return [
            'usuarios' => (int) $pdo->query(
                'SELECT COUNT(*) FROM usuarios'
            )->fetchColumn(),
            'empresas' => (int) $pdo->query(
                'SELECT COUNT(*) FROM empresas'
            )->fetchColumn(),
            'almacenes' => (int) $pdo->query(
                'SELECT COUNT(*) FROM almacenes'
            )->fetchColumn(),
            'usuario_empresas' => (int) $pdo->query(
                'SELECT COUNT(*) FROM usuario_empresas'
            )->fetchColumn(),
            'usuario_almacenes' => (int) $pdo->query(
                'SELECT COUNT(*) FROM usuario_almacenes'
            )->fetchColumn(),
            'permisos' => (int) $pdo->query(
                'SELECT COUNT(*) FROM permisos'
            )->fetchColumn(),
        ];
    }
};
