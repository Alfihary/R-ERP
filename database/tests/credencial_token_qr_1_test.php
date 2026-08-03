<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Credentials\CredentialQrService;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\CredentialTokenService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Controllers\CredentialController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\CredentialTokenRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserCredentialRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const USERNAME = 'qa_credencial_token_qr_1';
    private const NO_QR_USERNAME = 'qa_credencial_token_qr_sin_qr';
    private const NO_DOWNLOAD_USERNAME = 'qa_credencial_token_qr_sin_descarga';
    private const PASSWORD = 'CredencialTokenQrQA123!';

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
            'credenciales_usuario',
            'credencial_tokens',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('CREDENCIAL-TOKEN-QR-1 requires table: ' . $table);
            }
        }

        foreach (['credencial.ver', 'credencial.qr.ver', 'credencial.qr.descargar'] as $permission) {
            if (!$this->activePermissionExists($pdo, $permission)) {
                throw new RuntimeException('CREDENCIAL-TOKEN-QR-1 requires permission: ' . $permission);
            }
        }

        $before = $this->counts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $userId = $this->insertUser($pdo, self::USERNAME, 1);
            $noQrUserId = $this->insertUser($pdo, self::NO_QR_USERNAME, 1);
            $noDownloadUserId = $this->insertUser($pdo, self::NO_DOWNLOAD_USERNAME, 1);
            $this->insertProfile($pdo, $userId);
            $this->insertProfile($pdo, $noQrUserId);
            $this->insertProfile($pdo, $noDownloadUserId);
            $this->assignRoleWithPermissions($pdo, $userId, 'QA_CRED_TOKEN_FULL', [
                'credencial.ver',
                'credencial.qr.ver',
                'credencial.qr.descargar',
            ]);
            $this->assignRoleWithPermissions($pdo, $noQrUserId, 'QA_CRED_TOKEN_NO_QR', [
                'credencial.ver',
            ]);
            $this->assignRoleWithPermissions($pdo, $noDownloadUserId, 'QA_CRED_TOKEN_NO_DOWNLOAD', [
                'credencial.ver',
                'credencial.qr.ver',
            ]);

            $service = $this->tokenService();
            $first = $service->generarToken($userId);
            $firstHash = hash('sha256', (string) $first['token']);
            $credentialId = $this->credentialId($pdo, $userId);
            $second = $service->generarToken($userId);
            $secondHash = hash('sha256', (string) $second['token']);
            $stateAfterSecond = $service->obtenerEstadoToken($userId);
            $activeCountAfterSecond = $this->activeTokenCount($pdo, $credentialId);
            $service->revocarTokenActivo($userId);
            $stateAfterRevoke = $service->obtenerEstadoToken($userId);
            $renewedForQr = $service->generarToken($userId);

            $session = $this->session();
            $csrf = new CsrfTokenService($session, 7200);
            $controller = $this->controllerFor(self::USERNAME, $session, $csrf);
            $postResponse = $controller->renewToken(
                new Request('POST', '/perfil/credencial/token/renovar', [], [
                    '_token' => $csrf->token(),
                ])
            );
            $bodyAfterRenew = $controller->show(new Request('GET', '/perfil/credencial'))->body();
            $qrResponse = $controller->showQr(new Request('GET', '/perfil/credencial/qr'));
            $downloadResponse = $controller->downloadQr(
                new Request('GET', '/perfil/credencial/qr/descargar')
            );
            $revokeResponse = $controller->revokeToken(
                new Request('POST', '/perfil/credencial/token/revocar', [], [
                    '_token' => $csrf->token(),
                ])
            );
            $bodyAfterRevoke = $controller->show(new Request('GET', '/perfil/credencial'))->body();

            $qrContract = (new CredentialQrService())->generate(
                (string) $renewedForQr['verification_path']
            );

            $results['routes'] = [
                'qr_declared' => $this->fileContains('routes/web.php', "'/perfil/credencial/' . 'qr'"),
                'renew_declared' => $this->fileContains(
                    'routes/web.php',
                    "'/perfil/credencial/token/renovar'"
                ),
                'revoke_declared' => $this->fileContains(
                    'routes/web.php',
                    "'/perfil/credencial/token/revocar'"
                ),
                'download_declared' => $this->fileContains(
                    'routes/web.php',
                    "'/perfil/credencial/' . 'qr/descargar'"
                ),
                'public_verification_route_may_exist_after_public_phase' => true,
            ];

            $results['auth_permission_csrf'] = [
                'no_session_redirects_to_login' =>
                    $this->middlewareStatus(
                        new AuthMiddleware($this->guestAuth()),
                        new Request('GET', '/perfil/credencial/qr')
                    ) === 302,
                'without_qr_permission_403' =>
                    $this->middlewareStatus(
                        new PermissionMiddleware(
                            $this->authForUser(self::NO_QR_USERNAME),
                            $this->permissions(),
                            'credencial.qr.ver'
                        ),
                        new Request('GET', '/perfil/credencial/qr')
                    ) === 403,
                'without_download_permission_403' =>
                    $this->middlewareStatus(
                        new PermissionMiddleware(
                            $this->authForUser(self::NO_DOWNLOAD_USERNAME),
                            $this->permissions(),
                            'credencial.qr.descargar'
                        ),
                        new Request('GET', '/perfil/credencial/qr/descargar')
                    ) === 403,
                'renew_without_csrf_419' =>
                    $this->middlewareStatus(
                        new CsrfMiddleware($csrf),
                        new Request('POST', '/perfil/credencial/token/renovar')
                    ) === 419,
                'revoke_without_csrf_419' =>
                    $this->middlewareStatus(
                        new CsrfMiddleware($csrf),
                        new Request('POST', '/perfil/credencial/token/revocar')
                    ) === 419,
            ];

            $results['token'] = [
                'plain_token_shape' => preg_match('/^[a-f0-9]{64}$/', (string) $first['token']) === 1,
                'hash_created' => $this->tokenHashExists($pdo, $firstHash),
                'plain_token_not_stored' => !$this->databaseContains($pdo, (string) $first['token']),
                'second_token_differs' => $first['token'] !== $second['token'],
                'previous_revoked' => $this->tokenRevoked($pdo, $firstHash),
                'single_active_token' => $activeCountAfterSecond === 1,
                'state_after_second_active' => ($stateAfterSecond['activo'] ?? false) === true,
                'revoke_marks_revoked' => ($stateAfterRevoke['activo'] ?? true) === false,
                'no_plain_token_columns' =>
                    !$this->columnExists($pdo, 'credencial_tokens', 'token')
                    && !$this->columnExists($pdo, 'credencial_tokens', 'token_plano'),
            ];

            $results['qr'] = [
                'renew_redirects' => $postResponse->status() === 302,
                'qr_status_200' => $qrResponse->status() === 200,
                'qr_png_signature' => str_starts_with($qrResponse->body(), "\x89PNG\r\n\x1A\n"),
                'download_status_200' => $downloadResponse->status() === 200,
                'payload_future_verification_path' =>
                    $qrContract['payload'] === (string) $renewedForQr['verification_path']
                    && str_starts_with($qrContract['payload'], '/credencial/verificar/'),
                'png_not_persisted' => !$this->physicalQrExists(),
                'revoke_redirects' => $revokeResponse->status() === 302,
                'qr_unavailable_after_revoke' =>
                    $controller->showQr(new Request('GET', '/perfil/credencial/qr'))->status() === 404,
            ];

            $results['html'] = [
                'shows_token_state' =>
                    str_contains($bodyAfterRenew, 'Token y QR privado')
                    && str_contains($bodyAfterRenew, 'QR privado disponible')
                    && str_contains($bodyAfterRenew, 'La verificación pública aún no está habilitada'),
                'shows_revoked_state_after_revoke' =>
                    str_contains($bodyAfterRevoke, 'Inactivo')
                    && str_contains($bodyAfterRevoke, 'No hay token activo'),
                'no_token_hash' => !str_contains($bodyAfterRenew, 'token_hash'),
                'no_plain_token' => !str_contains($bodyAfterRenew, (string) $renewedForQr['token']),
                'no_private_data' =>
                    !str_contains($bodyAfterRenew, 'credencial_tokens')
                    && !str_contains($bodyAfterRenew, 'password_hash')
                    && !str_contains($bodyAfterRenew, 'usuario_roles')
                    && !str_contains($bodyAfterRenew, 'rol_permisos')
                    && !str_contains($bodyAfterRenew, 'ruta_relativa')
                    && !str_contains($bodyAfterRenew, 'storage/uploads'),
            ];

            $results['regression_surface'] = [
                'credential_visual_still_declared' =>
                    $this->fileContains('routes/web.php', "'/perfil/credencial'"),
                'vcard_public_still_declared' =>
                    $this->fileContains('routes/web.php', "'/v/{slug}'"),
                'vcard_qr_still_declared' =>
                    $this->fileContains('routes/web.php', "'/v/{slug}/' . 'qr'"),
                'vcard_vcf_still_declared' =>
                    $this->fileContains('routes/web.php', "'/v/{slug}/' . 'vcf'"),
                'vcard_products_still_available' =>
                    file_exists(BASE_PATH . '/app/Domain/Vcards/VcardProductService.php'),
                'no_legacy_public_credential_controller' =>
                    !file_exists(BASE_PATH . '/app/Http/Controllers/CredentialVerificationController.php'),
                'no_credential_js' =>
                    !file_exists(BASE_PATH . '/public/js/modules/credential.js'),
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
                'CREDENCIAL-TOKEN-QR-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'token_mechanism' => 'random_bytes(32) encoded as 64-character hex; DB stores sha256 hash only',
            'qr_payload' => '/credencial/verificar/{token}',
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function tokenService(): CredentialTokenService
    {
        return new CredentialTokenService(
            new CredentialService(
                new UserCredentialRepository($GLOBALS['credencial_token_qr_connection'])
            ),
            new CredentialTokenRepository($GLOBALS['credencial_token_qr_connection'])
        );
    }

    private function controllerFor(
        string $username,
        Session $session,
        CsrfTokenService $csrf
    ): CredentialController {
        return new CredentialController(
            $GLOBALS['credencial_token_qr_config'],
            $this->authForUser($username, $session),
            $this->permissions(),
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($GLOBALS['credencial_token_qr_connection'])),
                $session
            ),
            $csrf,
            new CredentialService(
                new UserCredentialRepository($GLOBALS['credencial_token_qr_connection'])
            ),
            $this->tokenService(),
            new CredentialQrService(),
            $session
        );
    }

    private function authForUser(string $username, ?Session $session = null): AuthService
    {
        $auth = new AuthService(
            new UserRepository($GLOBALS['credencial_token_qr_connection']),
            $session ?? $this->session()
        );

        if (!$auth->attempt($username . '@example.test', self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate CREDENCIAL-TOKEN-QR-1 QA user.');
        }

        return $auth;
    }

    private function guestAuth(): AuthService
    {
        $_SESSION = [];

        return new AuthService(
            new UserRepository($GLOBALS['credencial_token_qr_connection']),
            $this->session()
        );
    }

    private function permissions(): PermissionService
    {
        return new PermissionService(
            new PermissionRepository($GLOBALS['credencial_token_qr_connection'])
        );
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('credencialtokenqr1');
            session_id('credencialtokenqr1' . bin2hex(random_bytes(4)));
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
            static fn (Request $request) => Response::html('ok')
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

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

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
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qa_credencial_token_qr%'"),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_credencial_token_qr%'"
            ),
            'credenciales_qa' => $this->countWhere(
                $pdo,
                'credenciales_usuario c INNER JOIN usuarios u ON u.id = c.usuario_id',
                "u.username LIKE 'qa_credencial_token_qr%'"
            ),
            'tokens_qa' => $this->countWhere(
                $pdo,
                'credencial_tokens ct INNER JOIN credenciales_usuario c ON c.id = ct.credencial_id INNER JOIN usuarios u ON u.id = c.usuario_id',
                "u.username LIKE 'qa_credencial_token_qr%'"
            ),
            'roles_qa' => $this->countWhere($pdo, 'roles', "codigo LIKE 'QA_CRED_TOKEN%'"),
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
                puesto
             ) VALUES (
                :usuario_id,
                \'QA\',
                \'Credencial Token\',
                \'Operación interna\'
             )'
        );
        $statement->execute(['usuario_id' => $userId]);
    }

    /**
     * @param list<string> $permissionCodes
     */
    private function assignRoleWithPermissions(
        PDO $pdo,
        int $userId,
        string $roleCode,
        array $permissionCodes
    ): void {
        $roleId = $this->insertRole($pdo, $roleCode);

        foreach ($permissionCodes as $permissionCode) {
            $statement = $pdo->prepare(
                'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
                 VALUES (:rol_id, :permiso_id, 1)'
            );
            $statement->execute([
                'rol_id' => $roleId,
                'permiso_id' => $this->permissionId($pdo, $permissionCode),
            ]);
        }

        $statement = $pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        );
        $statement->execute(['usuario_id' => $userId, 'rol_id' => $roleId]);
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

    private function permissionId(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare('SELECT id FROM permisos WHERE codigo = :codigo LIMIT 1');
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Permission not found: ' . $code);
        }

        return $id;
    }

    private function credentialId(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM credenciales_usuario WHERE usuario_id = :usuario_id LIMIT 1'
        );
        $statement->execute(['usuario_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    private function tokenHashExists(PDO $pdo, string $hash): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM credencial_tokens WHERE token_hash = :token_hash'
        );
        $statement->execute(['token_hash' => $hash]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function tokenRevoked(PDO $pdo, string $hash): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM credencial_tokens
             WHERE token_hash = :token_hash
               AND activo = 0
               AND revocado_en IS NOT NULL'
        );
        $statement->execute(['token_hash' => $hash]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function activeTokenCount(PDO $pdo, int $credentialId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM credencial_tokens
             WHERE credencial_id = :credencial_id
               AND activo = 1
               AND revocado_en IS NULL'
        );
        $statement->execute(['credencial_id' => $credentialId]);

        return (int) $statement->fetchColumn();
    }

    private function databaseContains(PDO $pdo, string $needle): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM credencial_tokens
             WHERE token_hash = :needle_hash
                OR token_prefix = :needle_prefix'
        );
        $statement->execute([
            'needle_hash' => $needle,
            'needle_prefix' => $needle,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function physicalQrExists(): bool
    {
        foreach ([
            BASE_PATH . '/public/credencial-qr.png',
            BASE_PATH . '/public/qr/credencial-qr.png',
            BASE_PATH . '/storage/uploads/usuarios/credencial-qr.png',
        ] as $path) {
            if (file_exists($path)) {
                return true;
            }
        }

        return false;
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
