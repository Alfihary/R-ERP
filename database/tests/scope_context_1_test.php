<?php

declare(strict_types=1);

use App\Core\Session;
use App\Domain\Scope\ScopeContext;
use App\Domain\Scope\ScopeContextService;
use App\Infrastructure\Database\DatabaseTest;

if (!isset(
    $scopeContext,
    $session,
    $initialAdminUsername,
    $initialAdminEmail
)
    || !$scopeContext instanceof ScopeContextService
    || !$session instanceof Session
    || !is_string($initialAdminUsername)
    || !is_string($initialAdminEmail)
) {
    throw new RuntimeException(
        'SCOPE-CONTEXT-1 DB-TEST requires context, session and administrator.'
    );
}

return new class(
    $scopeContext,
    $session,
    $initialAdminUsername,
    $initialAdminEmail
) implements DatabaseTest {
    public function __construct(
        private readonly ScopeContextService $scopeContext,
        private readonly Session $session,
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
                'The active database does not match SCOPE-CONTEXT-1 DB-TEST.'
            );
        }

        $this->assertPrerequisites($pdo);
        $adminId = $this->adminId($pdo);
        $persistentBefore = $this->persistentCounts($pdo);
        $this->scopeContext->clear();
        $automatic = $this->scopeContext->resolveForUser($adminId);
        $this->assertAutomaticContext($automatic);
        $this->assertMinimalSession(
            (int) $automatic->activeCompany()['id'],
            (int) $automatic->activeWarehouse()['id']
        );

        $assertions = [
            'automatic_single_pair' => true,
            'minimal_session_ids_only' => true,
        ];

        $pdo->beginTransaction();

        try {
            $seedCompanyId = (int) $automatic->activeCompany()['id'];
            $seedWarehouseId = (int) $automatic->activeWarehouse()['id'];
            $secondCompanyId = $this->insertCompany(
                $pdo,
                'scope-context-second',
                'Scope Context Second',
                $adminId
            );
            $secondWarehouseId = $this->insertWarehouse(
                $pdo,
                $secondCompanyId,
                'second',
                'Second Warehouse',
                $adminId
            );
            $unassignedWarehouseId = $this->insertWarehouse(
                $pdo,
                $secondCompanyId,
                'not-assigned',
                'Not Assigned Warehouse',
                $adminId
            );
            $outsideCompanyId = $this->insertCompany(
                $pdo,
                'scope-context-outside',
                'Scope Context Outside',
                $adminId
            );
            $outsideWarehouseId = $this->insertWarehouse(
                $pdo,
                $outsideCompanyId,
                'outside',
                'Outside Warehouse',
                $adminId
            );
            $this->assignCompany($pdo, $adminId, $secondCompanyId);
            $this->assignWarehouse(
                $pdo,
                $adminId,
                $secondCompanyId,
                $secondWarehouseId
            );

            $this->scopeContext->clear();
            $multiple = $this->scopeContext->resolveForUser($adminId);

            if (!$multiple->requiresSelection()
                || $multiple->hasActiveContext()
            ) {
                throw new RuntimeException(
                    'Multiple scope pairs should require explicit selection.'
                );
            }

            $assertions['multiple_pairs_require_selection'] = true;

            if (!$this->scopeContext->changeForUser(
                $adminId,
                $secondCompanyId,
                $secondWarehouseId
            )) {
                throw new RuntimeException('A valid context change was rejected.');
            }

            $changed = $this->scopeContext->resolveForUser($adminId);
            $this->assertActivePair(
                $changed,
                $secondCompanyId,
                $secondWarehouseId
            );
            $this->assertMinimalSession($secondCompanyId, $secondWarehouseId);
            $assertions['valid_context_change'] = true;

            if ($this->scopeContext->changeForUser(
                $adminId,
                $outsideCompanyId,
                $outsideWarehouseId
            )) {
                throw new RuntimeException('A company outside scope was accepted.');
            }
            $this->assertActivePair(
                $this->scopeContext->resolveForUser($adminId),
                $secondCompanyId,
                $secondWarehouseId
            );
            $assertions['outside_company_rejected'] = true;

            if ($this->scopeContext->changeForUser(
                $adminId,
                $secondCompanyId,
                $unassignedWarehouseId
            )) {
                throw new RuntimeException('A warehouse outside scope was accepted.');
            }
            $assertions['outside_warehouse_rejected'] = true;

            if ($this->scopeContext->changeForUser(
                $adminId,
                $seedCompanyId,
                $secondWarehouseId
            )) {
                throw new RuntimeException('A mismatched company-warehouse pair was accepted.');
            }
            $assertions['mismatched_pair_rejected'] = true;

            $this->session->put(
                ScopeContextService::COMPANY_SESSION_KEY,
                PHP_INT_MAX
            );
            $this->session->put(
                ScopeContextService::WAREHOUSE_SESSION_KEY,
                PHP_INT_MAX
            );
            $cleaned = $this->scopeContext->resolveForUser($adminId);

            if ($cleaned->hasActiveContext()
                || $this->session->get(
                    ScopeContextService::COMPANY_SESSION_KEY
                ) !== null
                || $this->session->get(
                    ScopeContextService::WAREHOUSE_SESSION_KEY
                ) !== null
            ) {
                throw new RuntimeException('Invalid session context was not cleared.');
            }
            $assertions['invalid_session_context_cleared'] = true;

            $emptyUserId = $this->insertUserWithoutScope($pdo, $adminId);
            $empty = $this->scopeContext->resolveForUser($emptyUserId);

            if ($empty->hasScope()
                || $empty->hasActiveContext()
                || $empty->activeCompany() !== null
                || $empty->activeWarehouse() !== null
            ) {
                throw new RuntimeException(
                    'A user without scope did not receive controlled empty context.'
                );
            }
            $assertions['empty_scope_controlled'] = true;
        } finally {
            $this->scopeContext->clear();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $persistentAfter = $this->persistentCounts($pdo);

        if ($persistentAfter !== $persistentBefore) {
            throw new RuntimeException(
                'SCOPE-CONTEXT-1 DB-TEST left persistent records behind.'
            );
        }

        return [
            'database' => $currentDatabase,
            'automatic_context' => [
                'company' => $automatic->activeCompany()['name'],
                'warehouse' => $automatic->activeWarehouse()['name'],
                'has_active_context' => $automatic->hasActiveContext(),
            ],
            'session_keys' => [
                ScopeContextService::COMPANY_SESSION_KEY,
                ScopeContextService::WAREHOUSE_SESSION_KEY,
            ],
            'assertions' => $assertions,
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
            throw new RuntimeException('SCOPE-CONTEXT-1 requires DB-SCOPE-1.');
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
                'SCOPE-CONTEXT-1 requires the active configured administrator.'
            );
        }

        return $id;
    }

    private function assertAutomaticContext(ScopeContext $context): void
    {
        if (!$context->hasScope()
            || !$context->hasActiveContext()
            || $context->requiresSelection()
            || ($context->activeCompany()['name'] ?? '') !== 'Grupo Refrigerantes'
            || ($context->activeWarehouse()['name'] ?? '') !== 'Almacén Principal'
        ) {
            throw new RuntimeException('The single scope pair was not selected automatically.');
        }
    }

    private function assertActivePair(
        ScopeContext $context,
        int $companyId,
        int $warehouseId
    ): void {
        if (!$context->hasActiveContext()
            || ($context->activeCompany()['id'] ?? 0) !== $companyId
            || ($context->activeWarehouse()['id'] ?? 0) !== $warehouseId
        ) {
            throw new RuntimeException('The active context pair does not match.');
        }
    }

    private function assertMinimalSession(int $companyId, int $warehouseId): void
    {
        $scopeKeys = array_values(array_filter(
            array_keys($_SESSION),
            static fn (string $key): bool => str_starts_with($key, 'active_')
        ));
        sort($scopeKeys);
        $expected = [
            ScopeContextService::COMPANY_SESSION_KEY,
            ScopeContextService::WAREHOUSE_SESSION_KEY,
        ];
        sort($expected);

        if ($scopeKeys !== $expected
            || $this->session->get(
                ScopeContextService::COMPANY_SESSION_KEY
            ) !== $companyId
            || $this->session->get(
                ScopeContextService::WAREHOUSE_SESSION_KEY
            ) !== $warehouseId
        ) {
            throw new RuntimeException('Session context is not the minimal ID pair.');
        }

        foreach ($scopeKeys as $key) {
            if (!is_int($this->session->get($key))) {
                throw new RuntimeException('Session context must contain only integers.');
            }
        }
    }

    private function insertCompany(
        PDO $pdo,
        string $code,
        string $name,
        int $adminId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $adminId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertWarehouse(
        PDO $pdo,
        int $companyId,
        string $code,
        string $name,
        int $adminId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO almacenes (
                empresa_id,
                codigo,
                nombre,
                activo,
                creado_por
             ) VALUES (
                :empresa_id,
                :codigo,
                :nombre,
                1,
                :creado_por
             )'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $adminId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function assignCompany(PDO $pdo, int $userId, int $companyId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuario_empresas (
                usuario_id,
                empresa_id,
                activo,
                creado_por
             ) VALUES (
                :usuario_id,
                :empresa_id,
                1,
                :creado_por
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'creado_por' => $userId,
        ]);
    }

    private function assignWarehouse(
        PDO $pdo,
        int $userId,
        int $companyId,
        int $warehouseId
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO usuario_almacenes (
                usuario_id,
                empresa_id,
                almacen_id,
                activo,
                creado_por
             ) VALUES (
                :usuario_id,
                :empresa_id,
                :almacen_id,
                1,
                :creado_por
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'creado_por' => $userId,
        ]);
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
            'username' => 'scope.context.empty',
            'email' => 'scope-context-empty@example.test',
            'password_hash' => password_hash(
                bin2hex(random_bytes(32)),
                PASSWORD_DEFAULT
            ),
            'creado_por' => $adminId,
        ]);

        return (int) $pdo->lastInsertId();
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
