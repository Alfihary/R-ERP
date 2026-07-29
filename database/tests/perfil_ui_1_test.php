<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Profile\ProfileService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Controllers\ProfileController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ProfileRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserPhotoRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const USERNAME = 'qa_profile_ui_1';
    private const EMAIL = 'qa.profile.ui.1@example.test';
    private const OLD_PASSWORD = 'PerfilUiQA123!';
    private const NEW_PASSWORD = 'PerfilUiQA456!';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($activeDatabase !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach ([
            'usuarios',
            'roles',
            'permisos',
            'usuario_roles',
            'perfiles_usuario',
            'usuarios_fotos',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('PERFIL-UI-1 requires table: ' . $table);
            }
        }

        foreach ([
            'perfil.ver',
            'perfil.editar',
            'perfil.password.cambiar',
            'perfil.foto.actualizar',
            'perfil.foto.eliminar',
        ] as $permission) {
            if (!$this->activePermissionExists($pdo, $permission)) {
                throw new RuntimeException('PERFIL-UI-1 requires permission: ' . $permission);
            }
        }

        $before = $this->counts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $userId = $this->insertUser($pdo);
            $this->assignAdminRole($pdo, $userId);

            [$auth, $csrf, $controller] = $this->authenticatedStack($userId);
            $permissionService = new PermissionService(
                new PermissionRepository($GLOBALS['perfil_ui_connection'])
            );

            $results['routes'] = [
                'get_profile_declared' => $this->fileContains('routes/web.php', "'/perfil'"),
                'post_profile_update_declared' => $this->fileContains('routes/web.php', "'/perfil/actualizar'"),
                'password_declared' => $this->fileContains('routes/web.php', "'/perfil/password'"),
                'delete_photo_declared' => $this->fileContains('routes/web.php', "'/perfil/foto/eliminar'"),
                'no_upload_route' => !$this->fileContains('routes/web.php', "'/perfil/foto/subir'"),
                'no_public_vcard_routes' =>
                    !$this->fileContains('routes/web.php', '/vcf')
                    && !$this->fileContains('routes/web.php', '/qr')
                    && !$this->fileContains('routes/web.php', '/credencial'),
            ];

            $profileResponse = $controller->index(new Request('GET', '/perfil'));
            $profileBody = $profileResponse->body();

            $results['read_profile'] = [
                'admin_status_200' => $profileResponse->status() === 200,
                'renders_profile_title' => str_contains($profileBody, 'Mi perfil'),
                'renders_nav_profile' =>
                    $this->fileContains('app/Views/layouts/app.php', 'href="/perfil"')
                    && $this->fileContains('app/Views/layouts/app.php', 'Mi perfil'),
                'shows_account_but_not_sensitive_data' =>
                    str_contains($profileBody, self::USERNAME)
                    && str_contains($profileBody, self::EMAIL)
                    && !str_contains($profileBody, 'password_hash')
                    && !str_contains($profileBody, self::OLD_PASSWORD),
            ];

            $updated = $controller->update(new Request('POST', '/perfil/actualizar', [], [
                'primer_nombre' => '  QA Perfil  ',
                'apellido_paterno' => 'UI',
                'puesto' => 'Operación',
                'telefono_movil' => '5555555555',
                'sitio_web' => 'https://example.test/perfil',
                'username' => 'qa_profile_ui_changed',
                'password_hash' => 'not-allowed',
                'roles' => ['ADMIN'],
            ]));
            $stored = $this->profileByUser($pdo, $userId);
            $userAfterUpdate = $this->userById($pdo, $userId);

            $results['update_profile'] = [
                'redirect_after_update' => $updated->status() === 302,
                'allowed_fields_updated' =>
                    $stored !== null
                    && $stored['primer_nombre'] === 'QA Perfil'
                    && $stored['apellido_paterno'] === 'UI'
                    && $stored['puesto'] === 'Operación'
                    && $stored['sitio_web'] === 'https://example.test/perfil',
                'forbidden_fields_ignored_by_controller' =>
                    $userAfterUpdate['username'] === self::USERNAME
                    && password_verify(self::OLD_PASSWORD, (string) $userAfterUpdate['password_hash']),
            ];

            $invalidUrl = $controller->update(new Request('POST', '/perfil/actualizar', [], [
                'sitio_web' => 'ftp://example.test',
            ]));

            $results['validation'] = [
                'invalid_profile_data_422' => $invalidUrl->status() === 422,
                'error_body_safe' =>
                    !str_contains($invalidUrl->body(), 'password_hash')
                    && !str_contains($invalidUrl->body(), self::OLD_PASSWORD),
            ];

            $passwordForm = $controller->passwordForm(new Request('GET', '/perfil/password'));
            $badPassword = $controller->updatePassword(new Request('POST', '/perfil/password', [], [
                'password_actual' => 'incorrecta',
                'password_nueva' => self::NEW_PASSWORD,
                'password_confirmacion' => self::NEW_PASSWORD,
            ]));
            $goodPassword = $controller->updatePassword(new Request('POST', '/perfil/password', [], [
                'password_actual' => self::OLD_PASSWORD,
                'password_nueva' => self::NEW_PASSWORD,
                'password_confirmacion' => self::NEW_PASSWORD,
            ]));

            $results['password'] = [
                'form_status_200' => $passwordForm->status() === 200,
                'form_does_not_echo_values' =>
                    !str_contains($passwordForm->body(), self::OLD_PASSWORD)
                    && !str_contains($passwordForm->body(), self::NEW_PASSWORD),
                'wrong_current_422' => $badPassword->status() === 422,
                'valid_change_redirects' => $goodPassword->status() === 302,
                'hash_updated' => password_verify(
                    self::NEW_PASSWORD,
                    (string) $this->userById($pdo, $userId)['password_hash']
                ),
            ];

            $this->insertActivePhoto($pdo, $userId);
            $photoPage = $controller->index(new Request('GET', '/perfil'));
            $deletePhoto = $controller->deletePhoto(
                new Request('POST', '/perfil/foto/eliminar')
            );

            $results['photo'] = [
                'metadata_visible' =>
                    str_contains($photoPage->body(), 'qa-profile-ui.webp')
                    && str_contains($photoPage->body(), 'image/webp'),
                'private_path_not_visible' =>
                    !str_contains($photoPage->body(), 'profile/users/qa-profile-ui.webp')
                    && !str_contains($photoPage->body(), 'ruta_relativa'),
                'delete_redirects' => $deletePhoto->status() === 302,
                'active_photo_deleted' => $this->activePhotoCount($pdo, $userId) === 0,
            ];

            $results['auth_and_permissions'] = [
                'no_session_redirects_to_login' =>
                    $this->middlewareStatus(
                        new AuthMiddleware($this->guestAuth()),
                        new Request('GET', '/perfil')
                    ) === 302,
                'without_permission_403' =>
                    $this->middlewareStatus(
                        new PermissionMiddleware(
                            $this->authForUser($userId),
                            new PermissionService(new PermissionRepository($GLOBALS['perfil_ui_connection'])),
                            'perfil.inexistente'
                        ),
                        new Request('GET', '/perfil')
                    ) === 403,
                'post_without_csrf_419' =>
                    $this->middlewareStatus(
                        new CsrfMiddleware($csrf),
                        new Request('POST', '/perfil/actualizar')
                    ) === 419,
                'admin_has_profile_permissions' =>
                    $permissionService->allows($userId, 'perfil.ver')
                    && $permissionService->allows($userId, 'perfil.editar')
                    && $permissionService->allows($userId, 'perfil.password.cambiar')
                    && $permissionService->allows($userId, 'perfil.foto.eliminar'),
            ];

            $results['guardrails'] = [
                'no_public_controller' =>
                    !file_exists(BASE_PATH . '/app/Http/Controllers/PublicVcardController.php')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/CredentialController.php'),
                'no_profile_js' => !file_exists(BASE_PATH . '/public/js/modules/profile.js'),
                'no_products_pricing_inventory_touch_in_test' => true,
                'layout_uses_permission_flag' =>
                    $this->fileContains('app/Views/layouts/app.php', '$canAccessProfile'),
            ];

            $during = $this->counts($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->counts($pdo);

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'PERFIL-UI-1 assertions failed: '
                . json_encode(
                    $results,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
            'photo_upload_route' => false,
        ];
    }

    /**
     * @return array{0: AuthService, 1: CsrfTokenService, 2: ProfileController}
     */
    private function authenticatedStack(int $userId): array
    {
        $auth = $this->authForUser($userId);
        $csrf = new CsrfTokenService($this->session(), 7200);
        $controller = new ProfileController(
            $GLOBALS['perfil_ui_config'],
            $auth,
            new PermissionService(new PermissionRepository($GLOBALS['perfil_ui_connection'])),
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($GLOBALS['perfil_ui_connection'])),
                $this->session()
            ),
            $csrf,
            new ProfileService(
                new ProfileRepository($GLOBALS['perfil_ui_connection']),
                new UserPhotoRepository($GLOBALS['perfil_ui_connection'])
            )
        );

        return [$auth, $csrf, $controller];
    }

    private function authForUser(int $userId): AuthService
    {
        $auth = new AuthService(
            new UserRepository($GLOBALS['perfil_ui_connection']),
            $this->session()
        );

        if (!$auth->attempt(self::EMAIL, self::OLD_PASSWORD)
            && !$auth->attempt(self::EMAIL, self::NEW_PASSWORD)
        ) {
            throw new RuntimeException('Unable to authenticate PERFIL-UI-1 QA user.');
        }

        return $auth;
    }

    private function guestAuth(): AuthService
    {
        $_SESSION = [];

        return new AuthService(
            new UserRepository($GLOBALS['perfil_ui_connection']),
            $this->session()
        );
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('perfilui1');
            session_id('perfilui1' . bin2hex(random_bytes(4)));
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
            static fn (Request $request) => App\Core\Response::html('ok')
        )->status();
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

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'usuarios_qa' => $this->countWhere(
                $pdo,
                'usuarios',
                "username = '" . self::USERNAME . "'"
            ),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username = '" . self::USERNAME . "'"
            ),
            'fotos_qa' => $this->countWhere(
                $pdo,
                'usuarios_fotos f INNER JOIN usuarios u ON u.id = f.usuario_id',
                "u.username = '" . self::USERNAME . "'"
            ),
            'usuario_roles_qa' => $this->countWhere(
                $pdo,
                'usuario_roles ur INNER JOIN usuarios u ON u.id = ur.usuario_id',
                "u.username = '" . self::USERNAME . "'"
            ),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    private function insertUser(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO usuarios (
                username,
                email,
                password_hash,
                activo
            )
            VALUES (
                :username,
                :email,
                :password_hash,
                1
            )
            SQL
        );
        $statement->execute([
            'username' => self::USERNAME,
            'email' => self::EMAIL,
            'password_hash' => password_hash(self::OLD_PASSWORD, PASSWORD_DEFAULT),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function assignAdminRole(PDO $pdo, int $userId): void
    {
        $roleId = (int) $pdo->query(
            "SELECT id FROM roles WHERE codigo = 'ADMIN' LIMIT 1"
        )->fetchColumn();

        if ($roleId < 1) {
            throw new RuntimeException('PERFIL-UI-1 requires ADMIN role.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'rol_id' => $roleId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userById(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user)) {
            throw new RuntimeException('QA user not found.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profileByUser(PDO $pdo, int $userId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT * FROM perfiles_usuario WHERE usuario_id = :usuario_id LIMIT 1'
        );
        $statement->execute(['usuario_id' => $userId]);
        $profile = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($profile) ? $profile : null;
    }

    private function insertActivePhoto(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO usuarios_fotos (
                usuario_id,
                disco,
                ruta_relativa,
                nombre_original,
                nombre_archivo,
                mime,
                extension,
                tamano_bytes,
                sha256,
                ancho,
                alto,
                activa,
                creado_por
            )
            VALUES (
                :usuario_id,
                'local',
                'profile/users/qa-profile-ui.webp',
                'qa-profile-ui-original.webp',
                'qa-profile-ui.webp',
                'image/webp',
                'webp',
                2048,
                :sha256,
                640,
                640,
                1,
                :creado_por
            )
            SQL
        );
        $statement->execute([
            'usuario_id' => $userId,
            'sha256' => str_repeat('a', 64),
            'creado_por' => $userId,
        ]);
    }

    private function activePhotoCount(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM usuarios_fotos
             WHERE usuario_id = :usuario_id
               AND activa = 1
               AND reemplazada_en IS NULL
               AND eliminada_en IS NULL'
        );
        $statement->execute(['usuario_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    private function fileContains(string $relativePath, string $needle): bool
    {
        $contents = file_get_contents(BASE_PATH . '/' . $relativePath);

        return is_string($contents) && str_contains($contents, $needle);
    }

    private function allTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $item) {
            if (!$this->allTrue($item)) {
                return false;
            }
        }

        return true;
    }
};
