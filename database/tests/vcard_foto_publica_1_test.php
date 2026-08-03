<?php

declare(strict_types=1);

use App\Core\Response;
use App\Core\Request;
use App\Domain\Vcards\VcardPrivacyService;
use App\Domain\Vcards\VcardService;
use App\Http\Controllers\PublicVcardController;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\UserVcardRepository;
use App\Infrastructure\Repositories\VcardPrivacyRepository;

return new class implements DatabaseTest {
    private const PASSWORD = 'VcardFotoPublicaQA123!';
    private const USER_PREFIX = 'qa_vcard_foto_publica_';
    private const SLUG_PREFIX = 'qa-vcard-foto-publica-';

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
            'perfiles_usuario',
            'usuarios_fotos',
            'vcards_usuario',
            'vcard_privacidad',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'VCARD-FOTO-PUBLICA-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $controller = $this->controller();
        $results = [];
        $pdo->beginTransaction();

        try {
            $jpg = $this->createScenario($pdo, 'jpg', true, true, true, 'jpg', true);
            $png = $this->createScenario($pdo, 'png', true, true, true, 'png', true);
            $webpSupported = $this->webpSupported();
            $webp = $webpSupported
                ? $this->createScenario($pdo, 'webp', true, true, true, 'webp', true)
                : null;
            $unpublished = $this->createScenario($pdo, 'unpublished', true, false, true, 'png', true);
            $inactive = $this->createScenario($pdo, 'inactive', false, true, true, 'png', true);
            $private = $this->createScenario($pdo, 'private', true, true, false, 'png', true);
            $noPhoto = $this->createScenario($pdo, 'no-photo', true, true, true, null, true);
            $missing = $this->createScenario($pdo, 'missing', true, true, true, 'png', false);
            $badMime = $this->createScenario($pdo, 'bad-mime', true, true, true, 'png', true, 'image/jpeg');
            $traversal = $this->createScenario($pdo, 'traversal', true, true, true, 'png', true, null, 'uploads/usuarios/../secret.png');
            $empty = $this->createScenario($pdo, 'empty', true, true, true, 'empty', true);

            $missingSlug = $controller->photo(
                new Request('GET', '/v/no-existe/foto'),
                ['slug' => 'no-existe']
            );
            $unpublishedResponse = $this->photo($controller, $unpublished['slug']);
            $inactiveResponse = $this->photo($controller, $inactive['slug']);
            $privateResponse = $this->photo($controller, $private['slug']);
            $noPhotoResponse = $this->photo($controller, $noPhoto['slug']);
            $missingResponse = $this->photo($controller, $missing['slug']);
            $badMimeResponse = $this->photo($controller, $badMime['slug']);
            $traversalResponse = $this->photo($controller, $traversal['slug']);
            $emptyResponse = $this->photo($controller, $empty['slug']);
            $jpgResponse = $this->photo($controller, $jpg['slug']);
            $pngResponse = $this->photo($controller, $png['slug']);
            $webpResponse = $webp === null ? null : $this->photo($controller, $webp['slug']);
            $publicPage = $controller->show(
                new Request('GET', '/v/' . $jpg['slug']),
                ['slug' => $jpg['slug']]
            );
            $disallowedMimeResponse = $this->invokeServePhoto($controller, [
                'ruta_relativa' => $this->relativeFromPath((string) $jpg['path']),
                'mime' => 'text/html',
                'extension' => 'html',
                'tamano_bytes' => filesize((string) $jpg['path']),
            ]);

            $results['not_found_cases'] = [
                'missing_slug_404_empty' => $missingSlug->status() === 404
                    && $missingSlug->body() === '',
                'unpublished_404_empty' => $unpublishedResponse->status() === 404
                    && $unpublishedResponse->body() === '',
                'inactive_user_404_empty' => $inactiveResponse->status() === 404
                    && $inactiveResponse->body() === '',
                'privacy_false_404_empty' => $privateResponse->status() === 404
                    && $privateResponse->body() === '',
                'no_active_photo_404_empty' => $noPhotoResponse->status() === 404
                    && $noPhotoResponse->body() === '',
                'missing_file_404_empty' => $missingResponse->status() === 404
                    && $missingResponse->body() === '',
                'disallowed_metadata_mime_404_empty' =>
                    $disallowedMimeResponse->status() === 404
                    && $disallowedMimeResponse->body() === '',
                'metadata_mime_mismatch_404_empty' => $badMimeResponse->status() === 404
                    && $badMimeResponse->body() === '',
                'traversal_404_empty' => $traversalResponse->status() === 404
                    && $traversalResponse->body() === '',
                'empty_file_404_empty' => $emptyResponse->status() === 404
                    && $emptyResponse->body() === '',
            ];

            $jpgHeaders = $this->headers($jpgResponse);
            $pngHeaders = $this->headers($pngResponse);
            $webpHeaders = $webpResponse === null ? [] : $this->headers($webpResponse);

            $results['successful_serving'] = [
                'jpg_status_200' => $jpgResponse->status() === 200,
                'jpg_content_type' => ($jpgHeaders['Content-Type'] ?? null) === 'image/jpeg',
                'jpg_nosniff' => ($jpgHeaders['X-Content-Type-Options'] ?? null) === 'nosniff',
                'jpg_body_matches_file' => $jpgResponse->body() === file_get_contents($jpg['path']),
                'png_status_200' => $pngResponse->status() === 200,
                'png_content_type' => ($pngHeaders['Content-Type'] ?? null) === 'image/png',
                'png_nosniff' => ($pngHeaders['X-Content-Type-Options'] ?? null) === 'nosniff',
                'png_body_matches_file' => $pngResponse->body() === file_get_contents($png['path']),
                'webp_valid_if_supported' => !$webpSupported
                    || (
                        $webpResponse instanceof Response
                        && $webpResponse->status() === 200
                        && ($webpHeaders['Content-Type'] ?? null) === 'image/webp'
                        && ($webpHeaders['X-Content-Type-Options'] ?? null) === 'nosniff'
                    ),
                'cache_control_present' =>
                    isset($jpgHeaders['Cache-Control'])
                    && str_contains($jpgHeaders['Cache-Control'], 'max-age=3600'),
            ];

            $results['privacy_and_leakage'] = [
                'no_private_path_in_binary' =>
                    !str_contains($jpgResponse->body(), 'ruta_relativa')
                    && !str_contains($jpgResponse->body(), 'storage/uploads/usuarios')
                    && !str_contains($jpgResponse->body(), (string) $jpg['path']),
                'view_uses_public_photo_url' =>
                    $publicPage->status() === 200
                    && str_contains($publicPage->body(), '/v/' . rawurlencode($jpg['slug']) . '/foto'),
                'view_does_not_expose_private_path' =>
                    !str_contains($publicPage->body(), 'ruta_relativa')
                    && !str_contains($publicPage->body(), 'storage/uploads/usuarios')
                    && !str_contains($publicPage->body(), (string) $jpg['path']),
            ];

            $results['guardrails'] = [
                'no_qr' =>
                    !$this->fileContains('routes/web.php', '/v/{slug}/qr')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/QrController.php'),
                'no_vcf' =>
                    !$this->fileContains('routes/web.php', '/v/{slug}/vcf')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/VcfController.php'),
                'no_public_credential_verification' =>
                    !$this->fileContains('routes/web.php', '/credencial/verificar')
                    && !$this->fileContains('routes/web.php', '/perfil/credencial/qr')
                    && !$this->fileContains('routes/web.php', '/perfil/credencial/qr/descargar'),
                'no_vcard_products' =>
                    !$this->fileContains('routes/web.php', '/v/{slug}/productos')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/VcardProductController.php'),
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
                'VCARD-FOTO-PUBLICA-1 assertions failed: '
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
            'photo_endpoint_mode' => 'controlled_public_serving',
        ];
    }

    /**
     * @return array{slug: string, path: string|null}
     */
    private function createScenario(
        PDO $pdo,
        string $suffix,
        bool $activeUser,
        bool $published,
        bool $photoVisible,
        ?string $fileType,
        bool $createFile,
        ?string $mimeOverride = null,
        ?string $relativeOverride = null
    ): array {
        $username = self::USER_PREFIX . str_replace('-', '_', $suffix);
        $slug = self::SLUG_PREFIX . $suffix;
        $userId = $this->insertUser($pdo, $username, $activeUser ? 1 : 0);
        $this->insertProfile($pdo, $userId);
        $vcardId = $this->insertVcard($pdo, $userId, $slug, $published);
        $this->insertPrivacy($pdo, $vcardId, 'foto', $photoVisible);

        $path = null;

        if ($fileType !== null) {
            $path = $createFile ? $this->photoFile($userId, $fileType) : null;
            $relative = $relativeOverride
                ?? ('uploads/usuarios/' . $userId . '/fotos/' . basename((string) $path));
            $this->insertPhoto(
                $pdo,
                $userId,
                $relative,
                $mimeOverride ?? $this->mimeForType($fileType),
                $this->extensionForType($fileType),
                $path !== null && is_file($path) ? max(1, (int) filesize($path)) : 1024
            );
        }

        return ['slug' => $slug, 'path' => $path];
    }

    private function controller(): PublicVcardController
    {
        return new PublicVcardController(
            $GLOBALS['vcard_foto_publica_config'],
            $this->service()
        );
    }

    private function service(): VcardService
    {
        $privacy = new VcardPrivacyService(
            new VcardPrivacyRepository($GLOBALS['vcard_foto_publica_connection'])
        );

        return new VcardService(
            new UserVcardRepository($GLOBALS['vcard_foto_publica_connection']),
            new VcardPrivacyRepository($GLOBALS['vcard_foto_publica_connection']),
            $privacy
        );
    }

    private function photo(PublicVcardController $controller, string $slug): Response
    {
        return $controller->photo(
            new Request('GET', '/v/' . $slug . '/foto'),
            ['slug' => $slug]
        );
    }

    /**
     * @param array<string, mixed> $photo
     */
    private function invokeServePhoto(
        PublicVcardController $controller,
        array $photo
    ): Response {
        $method = new ReflectionMethod(PublicVcardController::class, 'servePhoto');
        $method->setAccessible(true);
        $response = $method->invoke($controller, $photo);

        if (!$response instanceof Response) {
            throw new RuntimeException('Unexpected photo response.');
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Response $response): array
    {
        $property = new ReflectionProperty(Response::class, 'headers');
        $property->setAccessible(true);
        $headers = $property->getValue($response);

        return is_array($headers) ? $headers : [];
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

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'usuarios_qa' => $this->countWhere(
                $pdo,
                'usuarios',
                "username LIKE '" . self::USER_PREFIX . "%'"
            ),
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
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE '" . self::USER_PREFIX . "%'"
            ),
            'privacidad_qa' => $this->countWhere(
                $pdo,
                'vcard_privacidad vp
                 INNER JOIN vcards_usuario v ON v.id = vp.vcard_id
                 INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE '" . self::USER_PREFIX . "%'"
            ),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    private function insertUser(PDO $pdo, string $username, int $active): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (
                username,
                email,
                password_hash,
                activo
             ) VALUES (
                :username,
                :email,
                :password_hash,
                :activo
             )'
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
                apellido_paterno
             ) VALUES (
                :usuario_id,
                \'QA\',
                \'Foto Pública\'
             )'
        );
        $statement->execute(['usuario_id' => $userId]);
    }

    private function insertVcard(PDO $pdo, int $userId, string $slug, bool $published): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO vcards_usuario (
                usuario_id,
                slug,
                titulo_publico,
                descripcion_publica,
                publicada,
                publicado_en,
                despublicado_en
             ) VALUES (
                :usuario_id,
                :slug,
                \'QA Foto Pública\',
                \'vCard pública con foto controlada\',
                :publicada,
                CASE WHEN :publicada_para_publicado = 1 THEN CURRENT_TIMESTAMP ELSE NULL END,
                CASE WHEN :publicada_para_despublicado = 0 THEN CURRENT_TIMESTAMP ELSE NULL END
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'slug' => $slug,
            'publicada' => $published ? 1 : 0,
            'publicada_para_publicado' => $published ? 1 : 0,
            'publicada_para_despublicado' => $published ? 1 : 0,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertPrivacy(PDO $pdo, int $vcardId, string $field, bool $visible): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO vcard_privacidad (vcard_id, campo, visible)
             VALUES (:vcard_id, :campo, :visible)'
        );
        $statement->execute([
            'vcard_id' => $vcardId,
            'campo' => $field,
            'visible' => $visible ? 1 : 0,
        ]);
    }

    private function insertPhoto(
        PDO $pdo,
        int $userId,
        string $relativePath,
        string $mime,
        string $extension,
        int $size
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios_fotos (
                usuario_id,
                ruta_relativa,
                nombre_original,
                nombre_archivo,
                mime,
                extension,
                tamano_bytes,
                sha256,
                ancho,
                alto,
                creado_por
             ) VALUES (
                :usuario_id,
                :ruta_relativa,
                :nombre_original,
                :nombre_archivo,
                :mime,
                :extension,
                :tamano_bytes,
                :sha256,
                1,
                1,
                :creado_por
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'ruta_relativa' => $relativePath,
            'nombre_original' => 'qa-vcard-foto-publica.' . $extension,
            'nombre_archivo' => basename($relativePath),
            'mime' => $mime,
            'extension' => $extension,
            'tamano_bytes' => $size,
            'sha256' => hash('sha256', $relativePath . '|' . $mime . '|' . random_bytes(8)),
            'creado_por' => $userId,
        ]);
    }

    private function photoFile(int $userId, string $type): string
    {
        $directory = $this->storageRoot() . DIRECTORY_SEPARATOR
            . $userId . DIRECTORY_SEPARATOR . 'fotos';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create photo fixture directory.');
        }

        $extension = $this->extensionForType($type);
        $path = $directory . DIRECTORY_SEPARATOR
            . 'qa-vcard-foto-publica-' . bin2hex(random_bytes(6)) . '.' . $extension;

        if ($type === 'empty') {
            file_put_contents($path, '');
        } else {
            file_put_contents($path, base64_decode($this->imageBase64($type), true));
        }

        $this->createdFiles[] = $path;

        return $path;
    }

    private function relativeFromPath(string $path): string
    {
        $root = rtrim($this->storageRoot(), '/\\') . DIRECTORY_SEPARATOR;
        $normalizedRoot = str_replace('\\', '/', $root);
        $normalizedPath = str_replace('\\', '/', $path);

        if (!str_starts_with($normalizedPath, $normalizedRoot)) {
            throw new RuntimeException('Fixture path is outside storage root.');
        }

        return 'uploads/usuarios/' . substr($normalizedPath, strlen($normalizedRoot));
    }

    private function storageRoot(): string
    {
        return rtrim(
            (string) $GLOBALS['vcard_foto_publica_config']->get('paths.STORAGE_PATH', STORAGE_PATH),
            '/\\'
        ) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, 'uploads/usuarios');
    }

    private function mimeForType(string $type): string
    {
        return match ($type) {
            'jpg' => 'image/jpeg',
            'png', 'empty' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function extensionForType(string $type): string
    {
        return match ($type) {
            'jpg' => 'jpg',
            'png', 'empty' => 'png',
            'webp' => 'webp',
            default => 'bin',
        };
    }

    private function imageBase64(string $type): string
    {
        return match ($type) {
            'jpg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Al//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EFBABAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z',
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lUP9xwAAAABJRU5ErkJggg==',
            'webp' => 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA',
            default => '',
        };
    }

    private function webpSupported(): bool
    {
        $path = $this->photoFile(0, 'webp');
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

    private function cleanupFiles(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->createdFiles = [];

        foreach (glob($this->storageRoot() . DIRECTORY_SEPARATOR . '0' . DIRECTORY_SEPARATOR . 'fotos' . DIRECTORY_SEPARATOR . 'qa-vcard-foto-publica-*') ?: [] as $file) {
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
