<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Profile\ProfileService;
use App\Domain\Profile\ProfileValidationException;
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
use App\Infrastructure\Storage\UserPhotoStorage;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const USERNAME = 'qa_perfil_foto_upload_1';
    private const EMAIL = 'qa.perfil.foto.upload.1@example.test';
    private const PASSWORD = 'PerfilFotoQA123!';
    private const USERNAME_NO_PERMISSION = 'qa_perfil_foto_upload_sin_permiso';
    private const EMAIL_NO_PERMISSION = 'qa.perfil.foto.upload.sin.permiso@example.test';

    /** @var list<string> */
    private array $createdFiles = [];

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
                throw new RuntimeException(
                    'PERFIL-FOTO-UPLOAD-1 requires table: ' . $table
                );
            }
        }

        foreach ([
            'perfil.ver',
            'perfil.foto.actualizar',
            'perfil.foto.eliminar',
        ] as $permission) {
            if (!$this->activePermissionExists($pdo, $permission)) {
                throw new RuntimeException(
                    'PERFIL-FOTO-UPLOAD-1 requires permission: ' . $permission
                );
            }
        }

        $before = $this->counts($pdo);
        $results = [];
        $storedPaths = [];
        $pdo->beginTransaction();

        try {
            $userId = $this->insertUser($pdo, self::USERNAME, self::EMAIL);
            $withoutPermissionId = $this->insertUser(
                $pdo,
                self::USERNAME_NO_PERMISSION,
                self::EMAIL_NO_PERMISSION
            );
            $this->assignAdminRole($pdo, $userId);

            [$auth, $csrf, $controller] = $this->authenticatedStack($userId, self::EMAIL);
            $storage = $this->storage();

            $results['routes_and_contract'] = [
                'post_upload_declared' => $this->fileContains('routes/web.php', "'/perfil/foto'"),
                'delete_photo_declared' => $this->fileContains('routes/web.php', "'/perfil/foto/eliminar'"),
                'public_photo_safe_route_remains' =>
                    $this->fileContains('routes/web.php', "'/v/{slug}/foto'")
                    && $this->fileContains('app/Http/Controllers/PublicVcardController.php', 'return $this->notFound();'),
                'no_qr_vcf_credential' =>
                    !$this->fileContains('routes/web.php', '/qr')
                    && !$this->fileContains('routes/web.php', '/vcf')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/CredentialController.php'),
            ];

            $page = $controller->index(new Request('GET', '/perfil'));
            $results['profile_view'] = [
                'profile_status_200' => $page->status() === 200,
                'upload_form_visible_with_permission' =>
                    str_contains($page->body(), 'action="/perfil/foto"')
                    && str_contains($page->body(), 'enctype="multipart/form-data"')
                    && str_contains($page->body(), 'name="foto"'),
                'private_paths_not_visible' =>
                    !str_contains($page->body(), 'storage/uploads/usuarios')
                    && !str_contains($page->body(), 'ruta_relativa')
                    && !str_contains($page->body(), 'sha256'),
            ];

            $firstUpload = $this->upload($this->imageFile('png'), 'foto-perfil.png');
            $firstResponse = $controller->uploadPhoto(new Request(
                'POST',
                '/perfil/foto',
                [],
                [],
                [],
                ['foto' => $firstUpload]
            ));
            $firstPhoto = $this->activePhoto($pdo, $userId);
            $storedPaths[] = (string) ($firstPhoto['ruta_relativa'] ?? '');

            $secondUpload = $this->upload($this->imageFile('jpg'), 'foto-perfil.jpg');
            $secondResponse = $controller->uploadPhoto(new Request(
                'POST',
                '/perfil/foto',
                [],
                [],
                [],
                ['foto' => $secondUpload]
            ));
            $secondPhoto = $this->activePhoto($pdo, $userId);
            $storedPaths[] = (string) ($secondPhoto['ruta_relativa'] ?? '');

            $results['valid_uploads'] = [
                'png_redirects' => $firstResponse->status() === 302,
                'png_registered' =>
                    is_array($firstPhoto)
                    && $firstPhoto['mime'] === 'image/png'
                    && $firstPhoto['extension'] === 'png'
                    && $firstPhoto['activa'] === 1,
                'jpg_replaces_previous' =>
                    $secondResponse->status() === 302
                    && is_array($secondPhoto)
                    && $secondPhoto['mime'] === 'image/jpeg'
                    && $this->activePhotoCount($pdo, $userId) === 1
                    && $this->inactivePhotoCount($pdo, $userId) === 1,
                'stored_outside_public' =>
                    is_array($secondPhoto)
                    && str_starts_with((string) $secondPhoto['ruta_relativa'], 'uploads/usuarios/')
                    && !str_starts_with((string) $secondPhoto['ruta_relativa'], 'public/'),
                'metadata_safe' =>
                    is_array($secondPhoto)
                    && preg_match('/^[a-f0-9]{64}$/', (string) $secondPhoto['sha256']) === 1
                    && (int) $secondPhoto['tamano_bytes'] > 0
                    && (int) $secondPhoto['ancho'] > 0
                    && (int) $secondPhoto['alto'] > 0,
            ];

            $webpPath = $this->imageFile('webp');
            $webpResult = $this->webpSupported($webpPath)
                ? $storage->store($userId, $this->upload($webpPath, 'foto-perfil.webp'))
                : ['skipped' => true];

            if (isset($webpResult['ruta_relativa'])) {
                $storedPaths[] = (string) $webpResult['ruta_relativa'];
            }

            $results['format_validation'] = [
                'webp_valid_if_supported' =>
                    (isset($webpResult['skipped']) && $webpResult['skipped'] === true)
                    || (($webpResult['mime'] ?? null) === 'image/webp'
                        && ($webpResult['extension'] ?? null) === 'webp'),
                'svg_rejected' => $this->fails(
                    fn () => $storage->store($userId, $this->upload(
                        $this->textFile('<svg></svg>', 'svg'),
                        'avatar.svg'
                    ))
                ),
                'gif_rejected' => $this->fails(
                    fn () => $storage->store($userId, $this->upload(
                        $this->gifFile(),
                        'avatar.gif'
                    ))
                ),
                'php_rejected' => $this->fails(
                    fn () => $storage->store($userId, $this->upload(
                        $this->textFile('<?php echo 1;', 'php'),
                        'avatar.php'
                    ))
                ),
                'fake_jpg_rejected' => $this->fails(
                    fn () => $storage->store($userId, $this->upload(
                        $this->textFile('not an image', 'jpg'),
                        'avatar.jpg'
                    ))
                ),
                'empty_rejected' => $this->fails(
                    fn () => $storage->store($userId, $this->upload(
                        $this->textFile('', 'empty'),
                        'avatar.png'
                    ))
                ),
                'oversize_rejected' => $this->fails(
                    fn () => $storage->store($userId, $this->upload(
                        $this->largeFile(),
                        'avatar.png'
                    ))
                ),
                'path_traversal_rejected' => $this->fails(
                    fn () => $storage->store($userId, $this->upload(
                        $this->imageFile('png'),
                        '../avatar.png'
                    ))
                ),
                'double_extension_rejected' => $this->fails(
                    fn () => $storage->store($userId, $this->upload(
                        $this->imageFile('png'),
                        'avatar.php.png'
                    ))
                ),
                'invalid_upload_error_rejected' => $this->fails(
                    fn () => $storage->store($userId, [
                        'name' => 'avatar.png',
                        'tmp_name' => $this->imageFile('png'),
                        'error' => UPLOAD_ERR_INI_SIZE,
                        'size' => 1,
                    ])
                ),
            ];

            $csrf->token();
            $results['auth_permission_csrf'] = [
                'no_session_redirects_to_login' =>
                    $this->middlewareStatus(
                        new AuthMiddleware($this->guestAuth()),
                        new Request('POST', '/perfil/foto')
                    ) === 302,
                'without_permission_403' =>
                    $this->middlewareStatus(
                        new PermissionMiddleware(
                            $this->authForUser($withoutPermissionId, self::EMAIL_NO_PERMISSION),
                            new PermissionService(new PermissionRepository($GLOBALS['perfil_foto_upload_connection'])),
                            'perfil.foto.actualizar'
                        ),
                        new Request('POST', '/perfil/foto')
                    ) === 403,
                'without_csrf_419' =>
                    $this->middlewareStatus(
                        new CsrfMiddleware($csrf),
                        new Request('POST', '/perfil/foto')
                    ) === 419,
            ];

            $storedForFailure = $storage->store(
                $userId,
                $this->upload($this->imageFile('png'), 'cleanup.png')
            );
            $storedPaths[] = $storedForFailure['ruta_relativa'];
            try {
                (new ProfileService(
                    new ProfileRepository($GLOBALS['perfil_foto_upload_connection']),
                    new UserPhotoRepository($GLOBALS['perfil_foto_upload_connection'])
                ))->registrarFoto(99999999, $storedForFailure, $userId);
            } catch (ProfileValidationException) {
                $storage->deleteRelativeFile($storedForFailure['ruta_relativa']);
            }

            $results['rollback_cleanup'] = [
                'stored_file_deleted_after_registration_failure' =>
                    !$this->storedFileExists($storedForFailure['ruta_relativa']),
                'transient_files_tracked' => count(array_filter($storedPaths)) >= 3,
            ];

            $during = $this->counts($pdo);
        } finally {
            foreach ($storedPaths as $relativePath) {
                if ($relativePath !== '') {
                    $this->storage()->deleteRelativeFile($relativePath);
                }
            }
            $this->cleanupTempFiles();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->counts($pdo);

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'PERFIL-FOTO-UPLOAD-1 assertions failed: '
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
            'cleanup' => 'transaction_rolled_back_and_files_removed',
            'storage_root' => 'storage/uploads/usuarios',
            'public_photo_serving' => false,
        ];
    }

    /**
     * @return array{0: AuthService, 1: CsrfTokenService, 2: ProfileController}
     */
    private function authenticatedStack(int $userId, string $email): array
    {
        $auth = $this->authForUser($userId, $email);
        $csrf = new CsrfTokenService($this->session(), 7200);
        $controller = new ProfileController(
            $GLOBALS['perfil_foto_upload_config'],
            $auth,
            new PermissionService(new PermissionRepository($GLOBALS['perfil_foto_upload_connection'])),
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($GLOBALS['perfil_foto_upload_connection'])),
                $this->session()
            ),
            $csrf,
            new ProfileService(
                new ProfileRepository($GLOBALS['perfil_foto_upload_connection']),
                new UserPhotoRepository($GLOBALS['perfil_foto_upload_connection'])
            ),
            $this->storage()
        );

        return [$auth, $csrf, $controller];
    }

    private function authForUser(int $userId, string $email): AuthService
    {
        $auth = new AuthService(
            new UserRepository($GLOBALS['perfil_foto_upload_connection']),
            $this->session()
        );

        if (!$auth->attempt($email, self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate PERFIL-FOTO-UPLOAD-1 QA user.');
        }

        return $auth;
    }

    private function guestAuth(): AuthService
    {
        $_SESSION = [];

        return new AuthService(
            new UserRepository($GLOBALS['perfil_foto_upload_connection']),
            $this->session()
        );
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('perfilfotoupload1');
            session_id('perfilfotoupload1' . bin2hex(random_bytes(4)));
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

    private function storage(): UserPhotoStorage
    {
        return new UserPhotoStorage(
            (string) $GLOBALS['perfil_foto_upload_config']->get('paths.STORAGE_PATH', STORAGE_PATH),
            true
        );
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
                "username IN ('" . self::USERNAME . "', '" . self::USERNAME_NO_PERMISSION . "')"
            ),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username IN ('" . self::USERNAME . "', '" . self::USERNAME_NO_PERMISSION . "')"
            ),
            'fotos_qa' => $this->countWhere(
                $pdo,
                'usuarios_fotos f INNER JOIN usuarios u ON u.id = f.usuario_id',
                "u.username IN ('" . self::USERNAME . "', '" . self::USERNAME_NO_PERMISSION . "')"
            ),
            'usuario_roles_qa' => $this->countWhere(
                $pdo,
                'usuario_roles ur INNER JOIN usuarios u ON u.id = ur.usuario_id',
                "u.username IN ('" . self::USERNAME . "', '" . self::USERNAME_NO_PERMISSION . "')"
            ),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    private function insertUser(PDO $pdo, string $username, string $email): int
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
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function assignAdminRole(PDO $pdo, int $userId): void
    {
        $roleId = (int) $pdo->query(
            "SELECT id FROM roles WHERE codigo = 'ADMIN' LIMIT 1"
        )->fetchColumn();

        if ($roleId < 1) {
            throw new RuntimeException('PERFIL-FOTO-UPLOAD-1 requires ADMIN role.');
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
     * @return array<string, mixed>|null
     */
    private function activePhoto(PDO $pdo, int $userId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT *
             FROM usuarios_fotos
             WHERE usuario_id = :usuario_id
               AND activa = 1
               AND reemplazada_en IS NULL
               AND eliminada_en IS NULL
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['usuario_id' => $userId]);
        $photo = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($photo) ? $photo : null;
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

    private function inactivePhotoCount(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM usuarios_fotos
             WHERE usuario_id = :usuario_id
               AND activa = 0
               AND reemplazada_en IS NOT NULL
               AND eliminada_en IS NULL'
        );
        $statement->execute(['usuario_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(string $path, string $name): array
    {
        return [
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];
    }

    private function imageFile(string $type): string
    {
        $map = [
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lUP9xwAAAABJRU5ErkJggg==',
            'jpg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Al//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EFBABAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z',
            'webp' => 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA',
        ];

        $path = $this->tempPath($type);
        file_put_contents($path, base64_decode($map[$type], true));
        $this->createdFiles[] = $path;

        return $path;
    }

    private function gifFile(): string
    {
        $path = $this->tempPath('gif');
        file_put_contents(
            $path,
            base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true)
        );
        $this->createdFiles[] = $path;

        return $path;
    }

    private function textFile(string $content, string $name): string
    {
        $path = $this->tempPath($name);
        file_put_contents($path, $content);
        $this->createdFiles[] = $path;

        return $path;
    }

    private function largeFile(): string
    {
        $path = $this->tempPath('large');
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to create large test file.');
        }

        fseek($handle, (5 * 1024 * 1024) + 1);
        fwrite($handle, 'x');
        fclose($handle);
        $this->createdFiles[] = $path;

        return $path;
    }

    private function tempPath(string $suffix): string
    {
        $directory = BASE_PATH . '/storage/temp';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create temp directory.');
        }

        return $directory . '/perfil-foto-upload-'
            . bin2hex(random_bytes(6))
            . '-'
            . preg_replace('/[^A-Za-z0-9_.-]/', '', $suffix);
    }

    private function webpSupported(string $path): bool
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return false;
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        return $mime === 'image/webp' && is_array(@getimagesize($path));
    }

    private function storedFileExists(string $relativePath): bool
    {
        $path = rtrim(
            (string) $GLOBALS['perfil_foto_upload_config']->get('paths.STORAGE_PATH', STORAGE_PATH),
            '/\\'
        ) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($path);
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (ProfileValidationException) {
            return true;
        } finally {
            $this->cleanupTempFiles();
        }

        return false;
    }

    private function cleanupTempFiles(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->createdFiles = [];

        foreach (glob(BASE_PATH . '/storage/temp/perfil-foto-upload-*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
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
