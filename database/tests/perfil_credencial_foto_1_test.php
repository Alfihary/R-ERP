<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Credentials\CredentialQrService;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\CredentialTokenService;
use App\Domain\Credentials\CredentialVerificationService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Domain\Vcards\VcardPrivacyService;
use App\Domain\Vcards\VcardService;
use App\Http\Controllers\CredentialController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\CredentialTokenRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserCredentialRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Repositories\UserVcardRepository;
use App\Infrastructure\Repositories\VcardPrivacyRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const USER_PREFIX = 'qa_perfil_credencial_foto_';
    private const PASSWORD = 'PerfilCredencialFotoQA123!';

    /** @var list<string> */
    private array $createdFiles = [];

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
            'vcards_usuario',
            'vcard_privacidad',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('PERFIL-CREDENCIAL-FOTO-1 requires table: ' . $table);
            }
        }

        if (!$this->activePermissionExists($pdo, 'credencial.ver')) {
            throw new RuntimeException('PERFIL-CREDENCIAL-FOTO-1 requires permission: credencial.ver');
        }

        $before = $this->counts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $jpg = $this->scenario($pdo, 'jpg', 'jpg', true);
            $png = $this->scenario($pdo, 'png', 'png', true);
            $webp = $this->scenario($pdo, 'webp', 'webp', true);
            $noPhoto = $this->scenario($pdo, 'sin_foto', null, false);
            $missing = $this->scenario($pdo, 'inexistente', 'jpg', false);
            $empty = $this->scenario($pdo, 'vacio', 'empty', true);
            $invalidMime = $this->scenario($pdo, 'mime_invalido', 'text-as-jpg', true);
            $svg = $this->scenario($pdo, 'svg', 'svg', true);
            $gif = $this->scenario($pdo, 'gif', 'gif', true);
            $php = $this->scenario($pdo, 'php', 'php', true);
            $double = $this->scenario($pdo, 'doble_ext', 'double-jpg', true);
            $traversal = $this->scenario($pdo, 'traversal', 'traversal', true);
            $withoutPermission = $this->insertUser($pdo, self::USER_PREFIX . 'sin_permiso', 1);

            $jpgResponse = $this->controllerFor($jpg['username'])->photo(
                new Request('GET', '/perfil/credencial/foto')
            );
            $pngResponse = $this->controllerFor($png['username'])->photo(
                new Request('GET', '/perfil/credencial/foto')
            );
            $webpResponse = $this->controllerFor($webp['username'])->photo(
                new Request('GET', '/perfil/credencial/foto')
            );
            $notFoundResponse = $this->controllerFor($noPhoto['username'])->photo(
                new Request('GET', '/perfil/credencial/foto')
            );
            $showBody = $this->controllerFor($jpg['username'])->show(
                new Request('GET', '/perfil/credencial')
            )->body();
            $placeholderBody = $this->controllerFor($noPhoto['username'])->show(
                new Request('GET', '/perfil/credencial')
            )->body();

            $results['route_and_access'] = [
                'private_photo_route_declared' =>
                    $this->fileContains('routes/web.php', "'/perfil/credencial/foto'"),
                'no_session_redirects_to_login' =>
                    $this->middlewareStatus(
                        new AuthMiddleware($this->guestAuth()),
                        new Request('GET', '/perfil/credencial/foto')
                    ) === 302,
                'without_permission_403' =>
                    $this->middlewareStatus(
                        new PermissionMiddleware(
                            $this->authForUser(self::USER_PREFIX . 'sin_permiso'),
                            $this->permissions(),
                            'credencial.ver'
                        ),
                        new Request('GET', '/perfil/credencial/foto')
                    ) === 403,
                'without_permission_user_created' => $withoutPermission > 0,
            ];

            $results['valid_images'] = [
                'jpg_200' => $jpgResponse->status() === 200,
                'jpg_content_type' => $this->header($jpgResponse, 'Content-Type') === 'image/jpeg',
                'png_200' => $pngResponse->status() === 200,
                'png_content_type' => $this->header($pngResponse, 'Content-Type') === 'image/png',
                'webp_200' => $webpResponse->status() === 200,
                'webp_content_type' => $this->header($webpResponse, 'Content-Type') === 'image/webp',
                'nosniff' => $this->header($jpgResponse, 'X-Content-Type-Options') === 'nosniff',
                'private_cache' => $this->header($jpgResponse, 'Cache-Control') === 'private, max-age=300',
                'referrer_policy' =>
                    $this->header($jpgResponse, 'Referrer-Policy') === 'strict-origin-when-cross-origin',
                'frame_options' => $this->header($jpgResponse, 'X-Frame-Options') === 'DENY',
            ];

            $invalidCases = [
                'no_active_photo_404' => $notFoundResponse,
                'missing_file_404' => $this->controllerFor($missing['username'])->photo(new Request('GET', '/perfil/credencial/foto')),
                'empty_file_404' => $this->controllerFor($empty['username'])->photo(new Request('GET', '/perfil/credencial/foto')),
                'invalid_mime_404' => $this->controllerFor($invalidMime['username'])->photo(new Request('GET', '/perfil/credencial/foto')),
                'svg_404' => $this->controllerFor($svg['username'])->photo(new Request('GET', '/perfil/credencial/foto')),
                'gif_404' => $this->controllerFor($gif['username'])->photo(new Request('GET', '/perfil/credencial/foto')),
                'php_404' => $this->controllerFor($php['username'])->photo(new Request('GET', '/perfil/credencial/foto')),
                'double_extension_404' => $this->controllerFor($double['username'])->photo(new Request('GET', '/perfil/credencial/foto')),
                'traversal_404' => $this->controllerFor($traversal['username'])->photo(new Request('GET', '/perfil/credencial/foto')),
            ];

            $results['invalid_images'] = [];
            foreach ($invalidCases as $name => $response) {
                $results['invalid_images'][$name] = $response->status() === 404
                    && $this->header($response, 'Content-Type') === 'text/plain; charset=utf-8'
                    && $this->header($response, 'Cache-Control') === 'no-store'
                    && $this->header($response, 'X-Content-Type-Options') === 'nosniff';
            }

            $results['html'] = [
                'private_view_uses_private_endpoint' =>
                    str_contains($showBody, '<img')
                    && str_contains($showBody, 'src="/perfil/credencial/foto"'),
                'placeholder_without_photo' =>
                    str_contains($placeholderBody, 'credential-card__photo-placeholder'),
                'private_view_no_sensitive_paths' =>
                    !str_contains($showBody, 'ruta_relativa')
                    && !str_contains($showBody, 'storage/uploads')
                    && !str_contains($showBody, 'uploads/usuarios')
                    && !str_contains($showBody, DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR),
                'public_verification_has_no_photo' =>
                    !str_contains(file_get_contents(BASE_PATH . '/app/Views/credentials/verify.php') ?: '', '<img')
                    && !str_contains(file_get_contents(BASE_PATH . '/app/Views/credentials/verify.php') ?: '', '/perfil/credencial/foto')
                    && !$this->fileContains('routes/web.php', '/credencial/verificar/{token}/foto'),
            ];

            $results['compatibility'] = [
                'credential_visual_still_declared' =>
                    $this->fileContains('routes/web.php', "'/perfil/credencial'"),
                'credential_qr_still_declared' =>
                    $this->fileContains('routes/web.php', "'/perfil/credencial/' . 'qr'"),
                'credential_qr_download_still_declared' =>
                    $this->fileContains('routes/web.php', "'/perfil/credencial/' . 'qr/descargar'"),
                'public_credential_verification_still_declared' =>
                    $this->fileContains('routes/web.php', "'/credencial/' . 'verificar/{token}'"),
                'vcard_public_still_declared' =>
                    $this->fileContains('routes/web.php', "'/v/{slug}'"),
                'vcard_photo_still_declared' =>
                    $this->fileContains('routes/web.php', "'/v/{slug}/foto'"),
                'vcard_qr_still_declared' =>
                    $this->fileContains('routes/web.php', "'/v/{slug}/' . 'qr'"),
                'vcard_vcf_still_declared' =>
                    $this->fileContains('routes/web.php', "'/v/{slug}/' . 'vcf'"),
                'vcard_products_still_declared' =>
                    file_exists(BASE_PATH . '/database/vcard-productos.php'),
            ];

            $during = $this->counts($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->cleanupFiles();
        }

        $after = $this->counts($pdo);

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'PERFIL-CREDENCIAL-FOTO-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'route' => 'GET /perfil/credencial/foto',
            'headers' => [
                '200' => [
                    'Content-Type' => 'image/jpeg|image/png|image/webp',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, max-age=300',
                    'Referrer-Policy' => 'strict-origin-when-cross-origin',
                    'X-Frame-Options' => 'DENY',
                ],
                '404' => [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                    'Referrer-Policy' => 'strict-origin-when-cross-origin',
                    'X-Frame-Options' => 'DENY',
                ],
            ],
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back_and_files_removed',
        ];
    }

    /**
     * @return array{username: string}
     */
    private function scenario(PDO $pdo, string $suffix, ?string $type, bool $createFile): array
    {
        $username = self::USER_PREFIX . $suffix;
        $userId = $this->insertUser($pdo, $username, 1);
        $this->insertProfile($pdo, $userId);
        $this->assignRoleWithPermission($pdo, $userId, 'credencial.ver');

        if ($type !== null) {
            [$path, $relative, $mime, $extension, $size] = $this->photoFixture($userId, $type, $createFile);
            $this->insertPhoto($pdo, $userId, $relative, $mime, $extension, $size);

            if ($path !== null) {
                $this->createdFiles[] = $path;
            }
        }

        return ['username' => $username];
    }

    /**
     * @return array{0: string|null, 1: string, 2: string, 3: string, 4: int}
     */
    private function photoFixture(int $userId, string $type, bool $createFile): array
    {
        $extension = match ($type) {
            'png' => 'png',
            'webp' => 'webp',
            'empty' => 'png',
            'text-as-jpg' => 'jpg',
            'svg' => 'png',
            'gif' => 'png',
            'php' => 'jpg',
            'double-jpg' => 'jpg',
            'traversal' => 'jpg',
            default => 'jpg',
        };
        $mime = match ($type) {
            'png', 'empty' => 'image/png',
            'webp' => 'image/webp',
            'svg' => 'image/png',
            'gif' => 'image/png',
            'php' => 'image/jpeg',
            default => 'image/jpeg',
        };
        $name = match ($type) {
            'double-jpg' => 'avatar.php.jpg',
            'traversal' => 'avatar.jpg',
            default => 'avatar.' . $extension,
        };
        $relative = 'uploads/usuarios/' . $userId . '/fotos/' . $name;

        if ($type === 'traversal') {
            $relative = 'uploads/usuarios/' . $userId . '/../avatar.jpg';
        }

        if (!$createFile) {
            return [null, $relative, $mime, $extension, 1024];
        }

        $path = $this->storageRoot() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, 'uploads/usuarios/' . $userId . '/fotos/' . $name);
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create QA photo directory.');
        }

        file_put_contents($path, $this->fixtureBytes($type));

        return [$path, $relative, $mime, $extension, max(1, (int) filesize($path))];
    }

    private function fixtureBytes(string $type): string
    {
        return match ($type) {
            'png' => base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
                true
            ) ?: '',
            'webp' => base64_decode(
                'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA',
                true
            ) ?: '',
            'empty' => '',
            'text-as-jpg' => 'not an image',
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            'gif' => "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;",
            'php' => '<?php echo 1;',
            default => base64_decode(
                '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z',
                true
            ) ?: '',
        };
    }

    private function controllerFor(string $username): CredentialController
    {
        $session = $this->session();

        return new CredentialController(
            $GLOBALS['perfil_credencial_foto_config'],
            $this->authForUser($username),
            $this->permissions(),
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($GLOBALS['perfil_credencial_foto_connection'])),
                $session
            ),
            new CsrfTokenService($session, 7200),
            new CredentialService(
                new UserCredentialRepository($GLOBALS['perfil_credencial_foto_connection'])
            ),
            new CredentialTokenService(
                new CredentialService(
                    new UserCredentialRepository($GLOBALS['perfil_credencial_foto_connection'])
                ),
                new CredentialTokenRepository($GLOBALS['perfil_credencial_foto_connection'])
            ),
            new CredentialQrService(),
            $this->vcardService(),
            $session
        );
    }

    private function vcardService(): VcardService
    {
        $privacy = new VcardPrivacyService(
            new VcardPrivacyRepository($GLOBALS['perfil_credencial_foto_connection'])
        );

        return new VcardService(
            new UserVcardRepository($GLOBALS['perfil_credencial_foto_connection']),
            new VcardPrivacyRepository($GLOBALS['perfil_credencial_foto_connection']),
            $privacy
        );
    }

    private function authForUser(string $username): AuthService
    {
        $_SESSION = [];
        $auth = new AuthService(
            new UserRepository($GLOBALS['perfil_credencial_foto_connection']),
            $this->session()
        );

        if (!$auth->attempt($username . '@example.test', self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate PERFIL-CREDENCIAL-FOTO-1 QA user.');
        }

        return $auth;
    }

    private function guestAuth(): AuthService
    {
        $_SESSION = [];

        return new AuthService(
            new UserRepository($GLOBALS['perfil_credencial_foto_connection']),
            $this->session()
        );
    }

    private function permissions(): PermissionService
    {
        return new PermissionService(
            new PermissionRepository($GLOBALS['perfil_credencial_foto_connection'])
        );
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('perfilcredencialfoto1');
            session_id('perfilcredencialfoto1' . bin2hex(random_bytes(4)));
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

    /**
     * @return array<string, string>
     */
    private function responseHeaders(Response $response): array
    {
        $reflection = new ReflectionClass($response);
        $property = $reflection->getProperty('headers');
        $value = $property->getValue($response);

        return is_array($value) ? $value : [];
    }

    private function header(Response $response, string $name): ?string
    {
        return $this->responseHeaders($response)[$name] ?? null;
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
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE '" . self::USER_PREFIX . "%'"),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE '" . self::USER_PREFIX . "%'"
            ),
            'fotos_qa' => $this->countWhere(
                $pdo,
                'usuarios_fotos f INNER JOIN usuarios u ON u.id = f.usuario_id',
                "u.username LIKE '" . self::USER_PREFIX . "%'"
            ),
            'credenciales_qa' => $this->countWhere(
                $pdo,
                'credenciales_usuario c INNER JOIN usuarios u ON u.id = c.usuario_id',
                "u.username LIKE '" . self::USER_PREFIX . "%'"
            ),
            'roles_qa' => $this->countWhere($pdo, 'roles', "codigo LIKE 'QA_PERFIL_CREDENCIAL_FOTO%'"),
            'tokens_qa' => $this->countWhere(
                $pdo,
                'credencial_tokens ct INNER JOIN credenciales_usuario c ON c.id = ct.credencial_id INNER JOIN usuarios u ON u.id = c.usuario_id',
                "u.username LIKE '" . self::USER_PREFIX . "%'"
            ),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)->fetchColumn();
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
                ubicacion_publica
             ) VALUES (
                :usuario_id,
                \'QA\',
                \'Foto\',
                \'Credencial\',
                \'Operación interna\',
                \'Monterrey interno\'
             )'
        );
        $statement->execute(['usuario_id' => $userId]);
    }

    private function insertPhoto(
        PDO $pdo,
        int $userId,
        string $relative,
        string $mime,
        string $extension,
        int $size
    ): void {
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
                \'qa-original.\' ,
                :nombre_archivo,
                :mime,
                :extension,
                :tamano_bytes,
                :sha256,
                1,
                1,
                1,
                :creado_por
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'ruta_relativa' => $relative,
            'nombre_archivo' => basename(str_replace('\\', '/', $relative)),
            'mime' => $mime,
            'extension' => $extension,
            'tamano_bytes' => $size,
            'sha256' => hash('sha256', $relative),
            'creado_por' => $userId,
        ]);
    }

    private function assignRoleWithPermission(PDO $pdo, int $userId, string $permissionCode): void
    {
        $roleId = $this->insertRole($pdo, 'QA_PERFIL_CREDENCIAL_FOTO_' . strtoupper(substr(md5((string) $userId), 0, 8)));
        $permissionId = $this->permissionId($pdo, $permissionCode);
        $statement = $pdo->prepare(
            'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
             VALUES (:rol_id, :permiso_id, 1)'
        );
        $statement->execute(['rol_id' => $roleId, 'permiso_id' => $permissionId]);
        $this->assignRole($pdo, $userId, $roleId);
    }

    private function insertRole(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO roles (codigo, nombre, descripcion, es_sistema, activo)
             VALUES (:codigo, :nombre, \'Rol QA transaccional\', 0, 1)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => str_replace('_', ' ', $code),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function permissionId(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM permisos WHERE codigo = :codigo AND activo = 1 AND eliminado_en IS NULL LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $permissionId = $statement->fetchColumn();

        if ($permissionId === false) {
            throw new RuntimeException('Missing permission: ' . $code);
        }

        return (int) $permissionId;
    }

    private function assignRole(PDO $pdo, int $userId, int $roleId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        );
        $statement->execute(['usuario_id' => $userId, 'rol_id' => $roleId]);
    }

    private function storageRoot(): string
    {
        return rtrim(
            (string) $GLOBALS['perfil_credencial_foto_config']->get('paths.STORAGE_PATH', STORAGE_PATH),
            '/\\'
        );
    }

    private function cleanupFiles(): void
    {
        foreach (array_reverse($this->createdFiles) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function fileContains(string $relativePath, string $needle): bool
    {
        $path = BASE_PATH . '/' . ltrim($relativePath, '/');

        return is_file($path) && str_contains((string) file_get_contents($path), $needle);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function allTrue(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                if (!$this->allTrue($item)) {
                    return false;
                }

                continue;
            }

            if ($item !== true) {
                return false;
            }
        }

        return true;
    }
};
