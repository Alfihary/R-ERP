<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Controllers\AuditController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Seed;
use App\Infrastructure\Repositories\AuditQueryRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const PERMISSION = 'auditoria.ver';
    private const TEMPORARY_PERMISSION = 'seguridad.rbac.ver';
    private const USERNAME_WITH_AUDIT = 'qa_permisos_auditoria_con_permiso';
    private const USERNAME_RBAC_ONLY = 'qa_permisos_auditoria_rbac_solo';
    private const PASSWORD = 'PermisosAuditoriaQA123!';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach (['usuarios', 'roles', 'permisos', 'usuario_roles', 'rol_permisos', 'auditoria_eventos'] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('PERMISOS-AUDITORIA-1 requires table: ' . $table);
            }
        }

        $seed = require BASE_PATH . '/database/seeds/permisos_auditoria_1_seed.php';

        if (!$seed instanceof Seed) {
            throw new RuntimeException('PERMISOS-AUDITORIA-1 seed has an invalid contract.');
        }

        $before = $this->persistentCounts($pdo);
        $permissionRowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM permisos')->fetchColumn();
        $rolesBefore = (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
        $usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();

        $seed->run($pdo);
        $firstSeed = $this->seedEvidence($pdo);
        $seed->run($pdo);
        $secondSeed = $this->seedEvidence($pdo);

        $results = [
            'seed' => [
                'seed_exists' => file_exists(BASE_PATH . '/database/seeds/permisos_auditoria_1_seed.php'),
                'seed_id' => $seed->id() === 'permisos_auditoria_1_seed',
                'permission_created' => $firstSeed['permission_rows'] === 1,
                'permission_active' => $firstSeed['permission_active_rows'] === 1,
                'admin_has_permission' => $firstSeed['admin_active_rows'] === 1,
                'idempotent_permission_rows' => $secondSeed['permission_rows'] === 1,
                'idempotent_admin_rows' => $secondSeed['admin_total_rows'] === 1,
                'duplicate_permissions' => $secondSeed['duplicate_permission_codes'] === 0,
                'duplicate_role_permissions' => $secondSeed['duplicate_role_permissions'] === 0,
                'permissions_not_removed' =>
                    (int) $pdo->query('SELECT COUNT(*) FROM permisos')->fetchColumn() >= $permissionRowsBefore,
                'roles_not_removed' =>
                    (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() === $rolesBefore,
                'users_not_modified' =>
                    (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === $usersBefore,
            ],
            'route_and_navigation_contract' => [
                'controller_uses_formal_permission' => AuditController::PERMISSION === self::PERMISSION,
                'route_declared' => $this->fileContains('routes/web.php', "'/auditoria'"),
                'route_uses_controller_permission' =>
                    $this->fileContains('routes/web.php', 'AuditController::PERMISSION'),
                'layout_uses_audit_flag' => $this->fileContains('app/Views/layouts/app.php', '$canAccessAudit'),
                'consulta_doc_updated' =>
                    $this->fileContains('docs/auditoria-consulta-1.md', '`auditoria.ver`')
                    && !$this->fileContains(
                        'docs/auditoria-consulta-1.md',
                        'Se usa temporalmente el permiso existente'
                    ),
                'consulta_test_updated' =>
                    $this->fileContains(
                        'database/tests/auditoria_consulta_1_test.php',
                        "'uses_formal_audit_permission'"
                    ),
            ],
            'read_only_contract' => [
                'no_post_route' => !$this->fileContains('routes/web.php', "post('/auditoria"),
                'no_put_route' => !$this->fileContains('routes/web.php', "put('/auditoria"),
                'no_patch_route' => !$this->fileContains('routes/web.php', "patch('/auditoria"),
                'no_delete_route' => !$this->fileContains('routes/web.php', "delete('/auditoria"),
            ],
            'guardrails' => [
                'no_migrations_modified' => !$this->hasUncommittedPath('database/migrations'),
                'audit_service_not_modified' => !$this->hasUncommittedPath('app/Domain/Audit/AuditService.php'),
                'audit_repository_not_modified' => !$this->hasUncommittedPath(
                    'app/Infrastructure/Repositories/AuditRepository.php'
                ),
                'product_price_inventory_not_modified' =>
                    !$this->hasUncommittedPath('app/Domain/Products')
                    && !$this->hasUncommittedPath('app/Domain/Pricing')
                    && !$this->hasUncommittedPath('app/Domain/Inventory'),
            ],
        ];

        $transientDuring = [];
        $pdo->beginTransaction();

        try {
            $auditUserId = $this->insertUser($pdo, self::USERNAME_WITH_AUDIT);
            $rbacOnlyUserId = $this->insertUser($pdo, self::USERNAME_RBAC_ONLY);
            $this->assignRoleWithPermission($pdo, $auditUserId, self::PERMISSION);
            $this->assignRoleWithPermission($pdo, $rbacOnlyUserId, self::TEMPORARY_PERMISSION);
            $this->insertEvents($pdo, $auditUserId);

            $withAuditController = $this->controllerFor(self::USERNAME_WITH_AUDIT);
            $withAuditResponse = $withAuditController->index(new Request('GET', '/auditoria'));
            $withAuditBody = $withAuditResponse->body();
            $rbacOnlyController = $this->controllerFor(self::USERNAME_RBAC_ONLY);
            $rbacOnlyBody = $rbacOnlyController->index(new Request('GET', '/auditoria'))->body();
            $auditBefore = $this->auditCount($pdo);

            $filteredByAction = $withAuditController->index(new Request('GET', '/auditoria', [
                'accion' => 'qa.permisos.audit.ok',
            ]))->body();
            $filteredByResult = $withAuditController->index(new Request('GET', '/auditoria', [
                'resultado' => 'fail',
            ]))->body();
            $filteredByActor = $withAuditController->index(new Request('GET', '/auditoria', [
                'actor_usuario_id' => (string) $auditUserId,
            ]))->body();
            $filteredByQ = $withAuditController->index(new Request('GET', '/auditoria', [
                'q' => 'qa-permisos-auditoria-needle',
            ]))->body();
            $auditAfter = $this->auditCount($pdo);

            $results['access'] = [
                'guest_redirects_to_login' =>
                    $this->middlewareStatus(
                        new AuthMiddleware($this->guestAuth()),
                        new Request('GET', '/auditoria')
                    ) === 302,
                'auditoria_permission_allows_access' =>
                    $this->middlewareStatus(
                        new PermissionMiddleware(
                            $this->authForUser(self::USERNAME_WITH_AUDIT),
                            $this->permissions(),
                            self::PERMISSION
                        ),
                        new Request('GET', '/auditoria')
                    ) === 200,
                'rbac_only_rejected' =>
                    $this->middlewareStatus(
                        new PermissionMiddleware(
                            $this->authForUser(self::USERNAME_RBAC_ONLY),
                            $this->permissions(),
                            self::PERMISSION
                        ),
                        new Request('GET', '/auditoria')
                    ) === 403,
                'navigation_visible_with_auditoria_permission' =>
                    $this->hasAuditNavigationLink($withAuditBody),
                'navigation_hidden_without_auditoria_permission' =>
                    !$this->hasAuditNavigationLink($rbacOnlyBody),
            ];

            $results['query_still_works'] = [
                'with_permission_200' => $withAuditResponse->status() === 200,
                'table_visible' => str_contains($withAuditBody, '<table'),
                'filters_visible' =>
                    str_contains($withAuditBody, 'name="accion"')
                    && str_contains($withAuditBody, 'name="resultado"')
                    && str_contains($withAuditBody, 'name="actor_usuario_id"')
                    && str_contains($withAuditBody, 'name="fecha_desde"')
                    && str_contains($withAuditBody, 'name="fecha_hasta"')
                    && str_contains($withAuditBody, 'name="q"'),
                'action_filter' =>
                    str_contains($filteredByAction, 'qa.permisos.audit.ok')
                    && !str_contains($filteredByAction, 'qa.permisos.audit.fail'),
                'result_filter' =>
                    str_contains($filteredByResult, 'qa.permisos.audit.fail')
                    && str_contains($filteredByResult, 'fail'),
                'actor_filter' =>
                    str_contains($filteredByActor, self::USERNAME_WITH_AUDIT)
                    && !str_contains($filteredByActor, 'Publico'),
                'q_filter' =>
                    str_contains($filteredByQ, 'qa-permisos-auditoria-needle')
                    && str_contains($filteredByQ, 'qa.permisos.audit.fail'),
                'query_does_not_insert_events' => $auditBefore === $auditAfter,
            ];

            $transientDuring = $this->transientCounts($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->persistentCounts($pdo);
        $results['cleanup'] = [
            'transient_qa_rolled_back' => $this->transientCounts($pdo) === [
                'usuarios_qa' => 0,
                'roles_qa' => 0,
                'audit_qa' => 0,
            ],
            'persistent_no_qa_counts_unchanged_except_audit_permission' =>
                $before['usuarios'] === $after['usuarios']
                && $before['roles'] === $after['roles']
                && $before['auditoria_eventos'] === $after['auditoria_eventos'],
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'PERMISOS-AUDITORIA-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'permission' => self::PERMISSION,
            'temporary_permission_no_longer_sufficient' => true,
            'seed' => [
                'id' => $seed->id(),
                'first_run' => $firstSeed,
                'second_run' => $secondSeed,
            ],
            'cases' => $results,
            'persistent_counts_before' => $before,
            'transient_counts_during' => $transientDuring,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back_for_qa_rows',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function seedEvidence(PDO $pdo): array
    {
        $permission = $pdo->prepare(
            'SELECT COUNT(*)
             FROM permisos
             WHERE codigo = :codigo'
        );
        $permission->execute(['codigo' => self::PERMISSION]);

        $permissionActive = $pdo->prepare(
            'SELECT COUNT(*)
             FROM permisos
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $permissionActive->execute(['codigo' => self::PERMISSION]);

        $admin = $pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN roles r ON r.id = rp.rol_id
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE r.codigo = :role_code
               AND p.codigo = :permission_code'
        );
        $admin->execute([
            'role_code' => 'ADMIN',
            'permission_code' => self::PERMISSION,
        ]);

        $adminActive = $pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN roles r ON r.id = rp.rol_id
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE r.codigo = :role_code
               AND r.activo = 1
               AND r.eliminado_en IS NULL
               AND p.codigo = :permission_code
               AND p.activo = 1
               AND p.eliminado_en IS NULL
               AND rp.activo = 1
               AND rp.eliminado_en IS NULL'
        );
        $adminActive->execute([
            'role_code' => 'ADMIN',
            'permission_code' => self::PERMISSION,
        ]);

        return [
            'permission_rows' => (int) $permission->fetchColumn(),
            'permission_active_rows' => (int) $permissionActive->fetchColumn(),
            'admin_total_rows' => (int) $admin->fetchColumn(),
            'admin_active_rows' => (int) $adminActive->fetchColumn(),
            'duplicate_permission_codes' => $this->duplicatePermissionCodes($pdo),
            'duplicate_role_permissions' => $this->duplicateRolePermissions($pdo),
        ];
    }

    private function duplicatePermissionCodes(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM (
                 SELECT codigo
                 FROM permisos
                 WHERE codigo = :codigo
                 GROUP BY codigo
                 HAVING COUNT(*) > 1
             ) duplicated'
        );
        $statement->execute(['codigo' => self::PERMISSION]);

        return (int) $statement->fetchColumn();
    }

    private function duplicateRolePermissions(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM (
                 SELECT rol_id, permiso_id
                 FROM rol_permisos
                 GROUP BY rol_id, permiso_id
                 HAVING COUNT(*) > 1
             ) duplicated'
        );
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function controllerFor(string $username): AuditController
    {
        return new AuditController(
            $GLOBALS['permisos_auditoria_config'],
            $this->authForUser($username),
            $this->permissions(),
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($GLOBALS['permisos_auditoria_connection'])),
                $this->session()
            ),
            new CsrfTokenService($this->session(), 7200),
            new AuditQueryRepository($GLOBALS['permisos_auditoria_connection'])
        );
    }

    private function insertUser(PDO $pdo, string $username): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo)
             VALUES (:username, :email, :password_hash, 1)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function assignRoleWithPermission(PDO $pdo, int $userId, string $permission): void
    {
        $roleId = $this->insertRole($pdo, 'qa_permisos_auditoria_role_' . $userId);
        $permissionId = $this->permissionId($pdo, $permission);

        $pdo->prepare(
            'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
             VALUES (:rol_id, :permiso_id, 1)'
        )->execute([
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);
        $pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        )->execute([
            'usuario_id' => $userId,
            'rol_id' => $roleId,
        ]);
    }

    private function insertRole(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO roles (codigo, nombre, es_sistema, activo)
             VALUES (:codigo, :nombre, 0, 1)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $code,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function permissionId(PDO $pdo, string $permission): int
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM permisos
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['codigo' => $permission]);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException('Permission not found: ' . $permission);
        }

        return (int) $id;
    }

    private function insertEvents(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO auditoria_eventos (
                actor_usuario_id,
                accion,
                entidad,
                entidad_id,
                resultado,
                ip,
                user_agent,
                metadata_json,
                creado_en
            ) VALUES (
                :actor_usuario_id,
                :accion,
                :entidad,
                :entidad_id,
                :resultado,
                :ip,
                :user_agent,
                :metadata_json,
                :creado_en
            )'
        );

        foreach ([
            [
                'actor_usuario_id' => $userId,
                'accion' => 'qa.permisos.audit.ok',
                'resultado' => 'ok',
                'metadata_json' => json_encode(['safe' => 'first'], JSON_THROW_ON_ERROR),
                'creado_en' => '2026-01-01 10:00:00',
            ],
            [
                'actor_usuario_id' => $userId,
                'accion' => 'qa.permisos.audit.fail',
                'resultado' => 'fail',
                'metadata_json' => json_encode(['needle' => 'qa-permisos-auditoria-needle'], JSON_THROW_ON_ERROR),
                'creado_en' => '2026-01-02 10:00:00',
            ],
            [
                'actor_usuario_id' => null,
                'accion' => 'qa.permisos.audit.public',
                'resultado' => 'ok',
                'metadata_json' => json_encode(['surface' => 'public'], JSON_THROW_ON_ERROR),
                'creado_en' => '2026-01-03 10:00:00',
            ],
        ] as $event) {
            $statement->execute([
                'actor_usuario_id' => $event['actor_usuario_id'],
                'accion' => $event['accion'],
                'entidad' => 'qa_permisos_auditoria',
                'entidad_id' => 'QA-' . $event['accion'],
                'resultado' => $event['resultado'],
                'ip' => '198.51.100.45',
                'user_agent' => 'QA Permisos Auditoria',
                'metadata_json' => $event['metadata_json'],
                'creado_en' => $event['creado_en'],
            ]);
        }
    }

    private function authForUser(string $username): AuthService
    {
        $_SESSION = [];
        $auth = new AuthService(
            new UserRepository($GLOBALS['permisos_auditoria_connection']),
            $this->session()
        );

        if (!$auth->attempt($username . '@example.test', self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate PERMISOS-AUDITORIA-1 QA user.');
        }

        return $auth;
    }

    private function guestAuth(): AuthService
    {
        $_SESSION = [];

        return new AuthService(
            new UserRepository($GLOBALS['permisos_auditoria_connection']),
            $this->session()
        );
    }

    private function permissions(): PermissionService
    {
        return new PermissionService(
            new PermissionRepository($GLOBALS['permisos_auditoria_connection'])
        );
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('permisosauditoria1');
            session_id('permisosauditoria1' . bin2hex(random_bytes(4)));
            session_start();
        }

        return new Session([
            'name' => session_name(),
            'same_site' => 'Lax',
            'secure' => false,
            'gc_max_lifetime' => 7200,
        ]);
    }

    private function middlewareStatus(object $middleware, Request $request): int
    {
        return $middleware->process(
            $request,
            static fn (Request $request): Response => Response::html('ok')
        )->status();
    }

    private function auditCount(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM auditoria_eventos')->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function persistentCounts(PDO $pdo): array
    {
        return [
            'usuarios' => (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn(),
            'roles' => (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn(),
            'permisos_auditoria' => $this->countWhere($pdo, 'permisos', "codigo = 'auditoria.ver'"),
            'admin_auditoria' => $this->adminAuditPermissionCount($pdo),
            'auditoria_eventos' => $this->auditCount($pdo),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function transientCounts(PDO $pdo): array
    {
        return [
            'usuarios_qa' => $this->countWhere(
                $pdo,
                'usuarios',
                "username IN ('" . self::USERNAME_WITH_AUDIT . "', '" . self::USERNAME_RBAC_ONLY . "')"
            ),
            'roles_qa' => $this->countWhere($pdo, 'roles', "codigo LIKE 'qa_permisos_auditoria_%'"),
            'audit_qa' => $this->countWhere($pdo, 'auditoria_eventos', "entidad = 'qa_permisos_auditoria'"),
        ];
    }

    private function adminAuditPermissionCount(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN roles r ON r.id = rp.rol_id
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE r.codigo = :role_code
               AND p.codigo = :permission_code'
        );
        $statement->execute([
            'role_code' => 'ADMIN',
            'permission_code' => self::PERMISSION,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)->fetchColumn();
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function fileContains(string $path, string $needle): bool
    {
        $contents = file_get_contents(BASE_PATH . '/' . $path);

        return is_string($contents) && str_contains($contents, $needle);
    }

    private function hasAuditNavigationLink(string $body): bool
    {
        return preg_match(
            '/<a\s+class="app-navigation__item[^"]*"\s+href="\/auditoria"[^>]*>\s*<span[^>]*>.*?<\/span>\s*Auditoria\s*<\/a>/s',
            $body
        ) === 1;
    }

    private function hasUncommittedPath(string $path): bool
    {
        exec('git status --short -- ' . escapeshellarg($path), $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Unable to inspect git status for ' . $path);
        }

        return $output !== [];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function allTrue(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                if (!$this->allTrue($value)) {
                    return false;
                }

                continue;
            }

            if ($value !== true) {
                return false;
            }
        }

        return true;
    }
};
