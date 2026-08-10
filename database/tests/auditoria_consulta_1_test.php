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
use App\Infrastructure\Repositories\AuditQueryRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const USERNAME = 'qa_auditoria_consulta_1';
    private const NO_PERMISSION_USERNAME = 'qa_auditoria_consulta_sin_permiso';
    private const PASSWORD = 'AuditoriaConsultaQA123!';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach ([
            'usuarios',
            'roles',
            'permisos',
            'usuario_roles',
            'rol_permisos',
            'auditoria_eventos',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('AUDITORIA-CONSULTA-1 requires table: ' . $table);
            }
        }

        if (!$this->activePermissionExists($pdo, AuditController::PERMISSION)) {
            throw new RuntimeException(
                'AUDITORIA-CONSULTA-1 requires existing permission: ' . AuditController::PERMISSION
            );
        }

        $before = $this->counts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $userId = $this->insertUser($pdo, self::USERNAME);
            $withoutPermissionUserId = $this->insertUser($pdo, self::NO_PERMISSION_USERNAME);
            $this->assignRoleWithPermission($pdo, $userId, AuditController::PERMISSION);
            $this->assignRoleWithoutPermission($pdo, $withoutPermissionUserId);
            $this->insertEvents($pdo, $userId);

            $controller = $this->controllerFor(self::USERNAME);
            $defaultResponse = $controller->index(new Request('GET', '/auditoria'));
            $defaultBody = $defaultResponse->body();
            $privateBefore = $this->auditCount($pdo);

            $filteredByAction = $controller->index(new Request('GET', '/auditoria', [
                'accion' => 'qa.audit.consulta.ok',
            ]))->body();
            $filteredByResult = $controller->index(new Request('GET', '/auditoria', [
                'resultado' => 'fail',
            ]))->body();
            $filteredByActor = $controller->index(new Request('GET', '/auditoria', [
                'actor_usuario_id' => (string) $userId,
            ]))->body();
            $filteredByFrom = $controller->index(new Request('GET', '/auditoria', [
                'fecha_desde' => '2026-01-02',
            ]))->body();
            $filteredByTo = $controller->index(new Request('GET', '/auditoria', [
                'fecha_hasta' => '2026-01-02',
            ]))->body();
            $filteredByQ = $controller->index(new Request('GET', '/auditoria', [
                'q' => 'needle-metadata',
            ]))->body();
            $injectionBody = $controller->index(new Request('GET', '/auditoria', [
                'accion' => "' OR 1=1 --",
                'q' => "%' OR 1=1 --",
            ]))->body();
            $perPageResponse = $controller->index(new Request('GET', '/auditoria', [
                'per_page' => '999',
            ]));
            $pageTwoBody = $controller->index(new Request('GET', '/auditoria', [
                'per_page' => '1',
                'page' => '2',
            ]))->body();
            $privateAfter = $this->auditCount($pdo);

            $results['route_and_access'] = [
                'route_declared' => $this->fileContains('routes/web.php', "'/auditoria'"),
                'uses_formal_audit_permission' => AuditController::PERMISSION === 'auditoria.ver',
                'no_session_redirects_to_login' =>
                    $this->middlewareStatus(
                        new AuthMiddleware($this->guestAuth()),
                        new Request('GET', '/auditoria')
                    ) === 302,
                'without_permission_403' =>
                    $this->middlewareStatus(
                        new PermissionMiddleware(
                            $this->authForUser(self::NO_PERMISSION_USERNAME),
                            $this->permissions(),
                            AuditController::PERMISSION
                        ),
                        new Request('GET', '/auditoria')
                    ) === 403,
                'with_permission_200' => $defaultResponse->status() === 200,
                'navigation_visible_with_permission' =>
                    str_contains($defaultBody, 'href="/auditoria"')
                    && str_contains($defaultBody, 'Auditoria'),
            ];

            $results['read_only_surface'] = [
                'table_visible' =>
                    str_contains($defaultBody, '<table')
                    && str_contains($defaultBody, 'Eventos de auditoria'),
                'filters_visible' =>
                    str_contains($defaultBody, 'name="accion"')
                    && str_contains($defaultBody, 'name="resultado"')
                    && str_contains($defaultBody, 'name="actor_usuario_id"')
                    && str_contains($defaultBody, 'name="fecha_desde"')
                    && str_contains($defaultBody, 'name="fecha_hasta"')
                    && str_contains($defaultBody, 'name="q"'),
                'no_post_routes' => !$this->fileContains('routes/web.php', "post('/auditoria"),
                'no_edit_delete_export_buttons' =>
                    !str_contains($defaultBody, 'href="/auditoria/editar')
                    && !str_contains($defaultBody, 'href="/auditoria/eliminar')
                    && !str_contains($defaultBody, 'href="/auditoria/export')
                    && !str_contains($defaultBody, 'action="/auditoria/editar')
                    && !str_contains($defaultBody, 'action="/auditoria/eliminar')
                    && !str_contains($defaultBody, 'action="/auditoria/export'),
            ];

            $results['filters'] = [
                'action_filter' =>
                    str_contains($filteredByAction, 'qa.audit.consulta.ok')
                    && !str_contains($filteredByAction, 'qa.audit.consulta.fail'),
                'result_filter' =>
                    str_contains($filteredByResult, 'qa.audit.consulta.fail')
                    && str_contains($filteredByResult, 'fail'),
                'actor_filter' =>
                    str_contains($filteredByActor, self::USERNAME)
                    && !str_contains($filteredByActor, 'Publico'),
                'from_filter' =>
                    str_contains($filteredByFrom, 'qa.audit.consulta.fail')
                    && !str_contains($filteredByFrom, 'qa.audit.consulta.ok'),
                'to_filter' =>
                    str_contains($filteredByTo, 'qa.audit.consulta.ok')
                    && str_contains($filteredByTo, 'qa.audit.consulta.fail')
                    && !str_contains($filteredByTo, 'qa.audit.consulta.public'),
                'q_filter' =>
                    str_contains($filteredByQ, 'needle-metadata')
                    && str_contains($filteredByQ, 'qa.audit.consulta.fail'),
                'sql_injection_safe' =>
                    str_contains($injectionBody, 'No hay eventos para los filtros seleccionados.')
                    && $this->auditCount($pdo) === $privateAfter,
            ];

            $results['pagination'] = [
                'per_page_capped_to_default_on_invalid_high_value' =>
                    str_contains($perPageResponse->body(), 'Pagina 1 de 1'),
                'page_two_works' =>
                    str_contains($pageTwoBody, 'Pagina 2 de')
                    && str_contains($pageTwoBody, 'Anterior'),
                'desc_order' =>
                    strpos($defaultBody, 'qa.audit.consulta.fail') !== false
                    && strpos($defaultBody, 'qa.audit.consulta.ok') !== false
                    && strpos($defaultBody, 'qa.audit.consulta.fail')
                        < strpos($defaultBody, 'qa.audit.consulta.ok'),
            ];

            $results['metadata_security'] = [
                'metadata_details_visible' => str_contains($defaultBody, 'Ver metadata'),
                'html_metadata_escaped' =>
                    str_contains($defaultBody, '&lt;script&gt;alert(1)&lt;/script&gt;')
                    && !str_contains($defaultBody, '<script>alert(1)</script>'),
                'sensitive_metadata_redacted' =>
                    str_contains($defaultBody, '[REDACTED]')
                    && !str_contains($defaultBody, str_repeat('a', 64))
                    && !str_contains($defaultBody, 'storage/uploads/usuarios/private.png')
                    && !str_contains($defaultBody, 'password_hash_value'),
            ];

            $results['no_mutation'] = [
                'query_does_not_insert_events' => $privateBefore === $privateAfter,
                'query_does_not_update_events' =>
                    $this->eventUpdatedEvidence($pdo) === [
                        'qa.audit.consulta.ok',
                        'qa.audit.consulta.fail',
                        'qa.audit.consulta.public',
                    ],
            ];

            $results['regression_guards'] = [
                'audit_service_exists' => file_exists(BASE_PATH . '/app/Domain/Audit/AuditService.php'),
                'credential_routes_still_declared' =>
                    $this->fileContains('routes/web.php', "'/credencial/' . 'verificar/{token}'")
                    && $this->fileContains('routes/web.php', "'/perfil/credencial'"),
                'vcard_routes_still_declared' =>
                    $this->fileContains('routes/web.php', "'/v/{slug}'")
                    && $this->fileContains('routes/web.php', "'/v/{slug}/' . 'qr'"),
                'no_migrations_modified' => !$this->hasUncommittedPath('database/migrations'),
                'no_unexpected_seeds_modified' => $this->onlyExpectedUncommittedPaths(
                    'database/seeds',
                    ['database/seeds/permisos_auditoria_1_seed.php']
                ),
            ];

            $during = $this->counts($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->counts($pdo);
        $results['cleanup'] = [
            'persistent_counts_unchanged' => $before === $after,
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'AUDITORIA-CONSULTA-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'permission_used' => AuditController::PERMISSION,
            'route' => 'GET /auditoria',
            'cases' => $results,
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function controllerFor(string $username): AuditController
    {
        return new AuditController(
            $GLOBALS['auditoria_consulta_config'],
            $this->authForUser($username),
            $this->permissions(),
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($GLOBALS['auditoria_consulta_connection'])),
                $this->session()
            ),
            new CsrfTokenService($this->session(), 7200),
            new AuditQueryRepository($GLOBALS['auditoria_consulta_connection'])
        );
    }

    private function insertUser(PDO $pdo, string $username): int
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO usuarios (username, email, password_hash, activo)
            VALUES (:username, :email, :password_hash, 1)
            SQL
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
        $roleId = $this->insertRole($pdo, 'qa_auditoria_consulta_role_' . $userId);
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

    private function assignRoleWithoutPermission(PDO $pdo, int $userId): void
    {
        $roleId = $this->insertRole($pdo, 'qa_auditoria_consulta_empty_' . $userId);
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
            <<<'SQL'
            INSERT INTO auditoria_eventos (
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
            )
            SQL
        );

        foreach ([
            [
                'actor_usuario_id' => $userId,
                'accion' => 'qa.audit.consulta.ok',
                'resultado' => 'ok',
                'metadata_json' => json_encode(['safe' => 'first'], JSON_THROW_ON_ERROR),
                'creado_en' => '2026-01-01 10:00:00',
            ],
            [
                'actor_usuario_id' => $userId,
                'accion' => 'qa.audit.consulta.fail',
                'resultado' => 'fail',
                'metadata_json' => json_encode([
                    'needle' => 'needle-metadata',
                    'html' => '<script>alert(1)</script>',
                    'token' => str_repeat('a', 64),
                    'token_hash' => str_repeat('b', 64),
                    'password_hash' => 'password_hash_value',
                    'ruta_relativa' => 'storage/uploads/usuarios/private.png',
                ], JSON_THROW_ON_ERROR),
                'creado_en' => '2026-01-02 10:00:00',
            ],
            [
                'actor_usuario_id' => null,
                'accion' => 'qa.audit.consulta.public',
                'resultado' => 'ok',
                'metadata_json' => json_encode(['surface' => 'public'], JSON_THROW_ON_ERROR),
                'creado_en' => '2026-01-03 10:00:00',
            ],
        ] as $event) {
            $statement->execute([
                'actor_usuario_id' => $event['actor_usuario_id'],
                'accion' => $event['accion'],
                'entidad' => 'qa_auditoria',
                'entidad_id' => 'QA-' . $event['accion'],
                'resultado' => $event['resultado'],
                'ip' => '198.51.100.44',
                'user_agent' => 'QA Auditoria Consulta',
                'metadata_json' => $event['metadata_json'],
                'creado_en' => $event['creado_en'],
            ]);
        }
    }

    private function authForUser(string $username): AuthService
    {
        $_SESSION = [];
        $auth = new AuthService(
            new UserRepository($GLOBALS['auditoria_consulta_connection']),
            $this->session()
        );

        if (!$auth->attempt($username . '@example.test', self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate AUDITORIA-CONSULTA-1 QA user.');
        }

        return $auth;
    }

    private function guestAuth(): AuthService
    {
        $_SESSION = [];

        return new AuthService(
            new UserRepository($GLOBALS['auditoria_consulta_connection']),
            $this->session()
        );
    }

    private function permissions(): PermissionService
    {
        return new PermissionService(
            new PermissionRepository($GLOBALS['auditoria_consulta_connection'])
        );
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('auditoriaconsulta1');
            session_id('auditoriaconsulta1' . bin2hex(random_bytes(4)));
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
     * @return list<string>
     */
    private function eventUpdatedEvidence(PDO $pdo): array
    {
        $statement = $pdo->query(
            "SELECT accion
             FROM auditoria_eventos
             WHERE entidad = 'qa_auditoria'
             ORDER BY creado_en ASC"
        );

        return array_map(
            static fn (array $row): string => (string) $row['accion'],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'usuarios_qa' => $this->countWhere(
                $pdo,
                'usuarios',
                "username IN ('" . self::USERNAME . "', '" . self::NO_PERMISSION_USERNAME . "')"
            ),
            'roles_qa' => $this->countWhere($pdo, 'roles', "codigo LIKE 'qa_auditoria_consulta_%'"),
            'audit_qa' => $this->countWhere($pdo, 'auditoria_eventos', "entidad = 'qa_auditoria'"),
            'audit_total' => $this->auditCount($pdo),
        ];
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

    private function activePermissionExists(PDO $pdo, string $code): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM permisos
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['codigo' => $code]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function fileContains(string $path, string $needle): bool
    {
        $contents = file_get_contents(BASE_PATH . '/' . $path);

        return is_string($contents) && str_contains($contents, $needle);
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
     * @param list<string> $allowedPaths
     */
    private function onlyExpectedUncommittedPaths(string $path, array $allowedPaths): bool
    {
        exec('git status --short -- ' . escapeshellarg($path), $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Unable to inspect git status for ' . $path);
        }

        foreach ($output as $line) {
            $changedPath = trim(substr((string) $line, 3));

            if (!in_array($changedPath, $allowedPaths, true)) {
                return false;
            }
        }

        return true;
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
