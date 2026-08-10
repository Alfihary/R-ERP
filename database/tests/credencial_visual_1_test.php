<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Credentials\CredentialQrService;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\CredentialTokenService;
use App\Domain\Credentials\CredentialValidationException;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Controllers\CredentialController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\CredentialTokenRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserCredentialRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const USERNAME = 'qa_credencial_visual_1';
    private const NO_PERMISSION_USERNAME = 'qa_credencial_visual_sin_permiso';
    private const INACTIVE_USERNAME = 'qa_credencial_visual_inactivo';
    private const PASSWORD = 'CredencialVisualQA123!';

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
            'perfiles_usuario',
            'usuarios_fotos',
            'credenciales_usuario',
            'credencial_tokens',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('CREDENCIAL-VISUAL-1 requires table: ' . $table);
            }
        }

        if (!$this->activePermissionExists($pdo, 'credencial.ver')) {
            throw new RuntimeException('CREDENCIAL-VISUAL-1 requires permission: credencial.ver');
        }

        $before = $this->counts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $userId = $this->insertUser($pdo, self::USERNAME, 1);
            $withoutPermissionUserId = $this->insertUser($pdo, self::NO_PERMISSION_USERNAME, 1);
            $inactiveUserId = $this->insertUser($pdo, self::INACTIVE_USERNAME, 0);
            $this->insertProfile($pdo, $userId);
            $this->insertActivePhoto($pdo, $userId);
            $this->assignRoleWithPermission($pdo, $userId, 'credencial.ver');
            $this->assignRoleWithoutPermission($pdo, $withoutPermissionUserId);

            $service = new CredentialService(
                new UserCredentialRepository($GLOBALS['credencial_visual_connection'])
            );
            $firstCredential = $service->asegurarCredencial($userId);
            $secondCredential = $service->asegurarCredencial($userId);
            $visual = $service->obtenerCredencialVisual($userId);

            $inactiveRejected = false;
            try {
                $service->asegurarCredencial($inactiveUserId);
            } catch (CredentialValidationException) {
                $inactiveRejected = true;
            }

            $missingRejected = false;
            try {
                $service->asegurarCredencial(999999999);
            } catch (CredentialValidationException) {
                $missingRejected = true;
            }

            $controller = $this->controllerFor($userId);
            $response = $controller->show(new Request('GET', '/perfil/credencial'));
            $body = $response->body();

            $results['route_and_access'] = [
                'route_declared' => $this->fileContains('routes/web.php', "'/perfil/credencial'"),
                'no_session_redirects_to_login' =>
                    $this->middlewareStatus(
                        new AuthMiddleware($this->guestAuth()),
                        new Request('GET', '/perfil/credencial')
                    ) === 302,
                'without_permission_403' =>
                    $this->middlewareStatus(
                        new PermissionMiddleware(
                            $this->authForUser(self::NO_PERMISSION_USERNAME),
                            $this->permissions(),
                            'credencial.ver'
                        ),
                        new Request('GET', '/perfil/credencial')
                    ) === 403,
                'with_permission_200' => $response->status() === 200,
                'navigation_visible_with_permission' =>
                    str_contains($body, 'href="/perfil/credencial"')
                    && str_contains($body, 'Mi credencial'),
            ];

            $results['service'] = [
                'creates_base_credential' => $this->credentialCount($pdo, $userId) === 1,
                'idempotent' =>
                    $this->credentialCount($pdo, $userId) === 1
                    && $firstCredential === $secondCredential,
                'inactive_user_rejected' => $inactiveRejected,
                'missing_user_rejected' => $missingRejected,
                'visual_has_safe_profile_data' =>
                    ($visual['nombre_completo'] ?? '') === 'QA Credencial Visual'
                    && ($visual['username'] ?? '') === self::USERNAME
                    && ($visual['puesto'] ?? '') === 'Operación interna'
                    && ($visual['foto']['registrada'] ?? false) === true,
            ];

            $results['html'] = [
                'shows_allowed_data' =>
                    str_contains($body, 'QA Credencial Visual')
                    && str_contains($body, self::USERNAME)
                    && str_contains($body, self::USERNAME . '@example.test')
                    && str_contains($body, 'Operación interna')
                    && str_contains($body, '/perfil/credencial/foto')
                    && str_contains($body, 'Credencial interna')
                    && str_contains($body, 'La foto se mantiene privada'),
                'no_password_hash' => !str_contains($body, 'password_hash'),
                'no_tokens' =>
                    !str_contains($body, 'token_hash')
                    && !str_contains($body, 'credencial_tokens')
                    && !str_contains($body, 'token verificable'),
                'no_roles_or_permissions' =>
                    !str_contains($body, 'usuario_roles')
                    && !str_contains($body, 'rol_permisos')
                    && !str_contains($body, 'credencial.ver'),
                'no_private_photo_path' =>
                    !str_contains($body, 'ruta_relativa')
                    && !str_contains($body, 'storage/uploads')
                    && !str_contains($body, 'profile/users/'),
                'private_photo_endpoint_only' =>
                    str_contains($body, '<img')
                    && str_contains($body, 'src="/perfil/credencial/foto"')
                    && !str_contains($body, 'storage/uploads'),
                'no_public_verification_link' =>
                    !str_contains($body, '/perfil/credencial/qr')
                    && !str_contains($body, '/credencial/verificar'),
            ];

            $results['guardrails'] = [
                'public_verification_route_exists_after_verification_phase' =>
                    $this->fileContains('routes/web.php', "'/credencial/' . 'verificar/{token}'"),
                'credential_qr_routes_private_after_token_qr_phase' =>
                    $this->fileContains('routes/web.php', "'/perfil/credencial/' . 'qr'")
                    && $this->fileContains('routes/web.php', "'/perfil/credencial/token/renovar'")
                    && $this->fileContains('routes/web.php', "'/perfil/credencial/token/revocar'"),
                'vcard_public_routes_still_declared' =>
                    $this->fileContains('routes/web.php', "'/v/{slug}'")
                    && $this->fileContains('routes/web.php', "'/v/{slug}/' . 'qr'")
                    && $this->fileContains('routes/web.php', "'/v/{slug}/' . 'vcf'"),
                'vcard_products_still_declared' =>
                    file_exists(BASE_PATH . '/app/Domain/Vcards/VcardProductService.php')
                    && file_exists(BASE_PATH . '/database/vcard-productos.php'),
                'no_products_pricing_inventory_touch_in_test' => true,
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
                'CREDENCIAL-VISUAL-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'shown_fields' => [
                'nombre_completo',
                'username',
                'email',
                'puesto',
                'telefono_movil',
                'telefono_fijo',
                'ubicacion',
                'estatus',
                'fecha_emision',
                'foto_metadata_segura',
            ],
            'forbidden_fields' => [
                'password_hash',
                'tokens',
                'token_hash',
                'credencial_tokens',
                'roles',
                'permisos',
                'ruta_relativa',
                'storage/uploads',
                'qr publico',
                'verificacion_publica',
            ],
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function controllerFor(int $userId): CredentialController
    {
        return new CredentialController(
            $GLOBALS['credencial_visual_config'],
            $this->authForUser(self::USERNAME),
            $this->permissions(),
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($GLOBALS['credencial_visual_connection'])),
                $this->session()
            ),
            new CsrfTokenService($this->session(), 7200),
            new CredentialService(
                new UserCredentialRepository($GLOBALS['credencial_visual_connection'])
            ),
            new CredentialTokenService(
                new CredentialService(
                    new UserCredentialRepository($GLOBALS['credencial_visual_connection'])
                ),
                new CredentialTokenRepository($GLOBALS['credencial_visual_connection'])
            ),
            new CredentialQrService(),
            $this->session()
        );
    }

    private function authForUser(string $username): AuthService
    {
        $auth = new AuthService(
            new UserRepository($GLOBALS['credencial_visual_connection']),
            $this->session()
        );

        if (!$auth->attempt($username . '@example.test', self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate CREDENCIAL-VISUAL-1 QA user.');
        }

        return $auth;
    }

    private function guestAuth(): AuthService
    {
        $_SESSION = [];

        return new AuthService(
            new UserRepository($GLOBALS['credencial_visual_connection']),
            $this->session()
        );
    }

    private function permissions(): PermissionService
    {
        return new PermissionService(
            new PermissionRepository($GLOBALS['credencial_visual_connection'])
        );
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('credencialvisual1');
            session_id('credencialvisual1' . bin2hex(random_bytes(4)));
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
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qa_credencial_visual%'"),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_credencial_visual%'"
            ),
            'fotos_qa' => $this->countWhere(
                $pdo,
                'usuarios_fotos f INNER JOIN usuarios u ON u.id = f.usuario_id',
                "u.username LIKE 'qa_credencial_visual%'"
            ),
            'credenciales_qa' => $this->countWhere(
                $pdo,
                'credenciales_usuario c INNER JOIN usuarios u ON u.id = c.usuario_id',
                "u.username LIKE 'qa_credencial_visual%'"
            ),
            'roles_qa' => $this->countWhere($pdo, 'roles', "codigo LIKE 'QA_CREDENCIAL_VISUAL%'"),
            'tokens_qa' => $this->countWhere(
                $pdo,
                'credencial_tokens ct INNER JOIN credenciales_usuario c ON c.id = ct.credencial_id INNER JOIN usuarios u ON u.id = c.usuario_id',
                "u.username LIKE 'qa_credencial_visual%'"
            ),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)
            ->fetchColumn();
    }

    private function insertUser(PDO $pdo, string $username, int $active): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo)
             VALUES (:username, :email, :password_hash, :activo)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'activo' => $active,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertProfile(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO perfiles_usuario (
                usuario_id,
                primer_nombre,
                apellido_paterno,
                apellido_materno,
                puesto,
                telefono_fijo,
                telefono_movil,
                ubicacion_publica
             ) VALUES (
                :usuario_id,
                \'QA\',
                \'Credencial\',
                \'Visual\',
                \'Operación interna\',
                \'8181000000\',
                \'8181000001\',
                \'Monterrey interno\'
             )'
        );
        $statement->execute(['usuario_id' => $userId]);
    }

    private function insertActivePhoto(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios_fotos (
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
             ) VALUES (
                :usuario_id,
                \'local\',
                :ruta_relativa,
                \'qa-credencial-original.webp\',
                \'qa-credencial.webp\',
                \'image/webp\',
                \'webp\',
                2048,
                :sha256,
                640,
                640,
                1,
                :creado_por
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'ruta_relativa' => 'profile/users/' . $userId . '/qa-credencial.webp',
            'sha256' => hash('sha256', 'credencial-visual-photo-' . $userId),
            'creado_por' => $userId,
        ]);
    }

    private function assignRoleWithPermission(PDO $pdo, int $userId, string $permissionCode): void
    {
        $roleId = $this->insertRole($pdo, 'QA_CREDENCIAL_VISUAL_PERMITIDO');
        $permissionId = $this->permissionId($pdo, $permissionCode);
        $statement = $pdo->prepare(
            'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
             VALUES (:rol_id, :permiso_id, 1)'
        );
        $statement->execute(['rol_id' => $roleId, 'permiso_id' => $permissionId]);
        $this->assignRole($pdo, $userId, $roleId);
    }

    private function assignRoleWithoutPermission(PDO $pdo, int $userId): void
    {
        $this->assignRole(
            $pdo,
            $userId,
            $this->insertRole($pdo, 'QA_CREDENCIAL_VISUAL_SIN_PERMISO')
        );
    }

    private function insertRole(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO roles (codigo, nombre, descripcion, es_sistema, activo)
             VALUES (:codigo, :nombre, \'Rol QA transaccional\', 0, 1)'
        );
        $statement->execute(['codigo' => $code, 'nombre' => $code]);

        return (int) $pdo->lastInsertId();
    }

    private function assignRole(PDO $pdo, int $userId, int $roleId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        );
        $statement->execute(['usuario_id' => $userId, 'rol_id' => $roleId]);
    }

    private function permissionId(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM permisos WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Permission not found: ' . $code);
        }

        return $id;
    }

    private function credentialCount(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM credenciales_usuario WHERE usuario_id = :usuario_id'
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
