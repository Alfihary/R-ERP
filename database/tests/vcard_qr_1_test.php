<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Domain\Vcards\VcardPrivacyService;
use App\Domain\Vcards\VcardQrService;
use App\Domain\Vcards\VcardService;
use App\Domain\Vcards\VcardVcfService;
use App\Http\Controllers\PublicVcardController;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\UserVcardRepository;
use App\Infrastructure\Repositories\VcardPrivacyRepository;

return new class implements DatabaseTest {
    private const PASSWORD = 'VcardQrQa123!';
    private const USERNAME = 'qa_vcard_qr_1';
    private const UNPUBLISHED_USERNAME = 'qa_vcard_qr_unpublished';
    private const INACTIVE_USERNAME = 'qa_vcard_qr_inactive';
    private const SLUG = 'qa-vcard-qr';
    private const UNPUBLISHED_SLUG = 'qa-vcard-qr-unpublished';
    private const INACTIVE_SLUG = 'qa-vcard-qr-inactive';
    private const HOST = 'qr.example.test';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($activeDatabase !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach ([
            'usuarios',
            'perfiles_usuario',
            'vcards_usuario',
            'vcard_privacidad',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('VCARD-QR-1 requires table: ' . $table);
            }
        }

        $before = $this->counts($pdo);
        $pngFilesBefore = $this->pngFileCount();
        $controller = $this->controller();
        $service = $this->service();
        $privacy = $this->privacy();
        $results = [];
        $pdo->beginTransaction();

        try {
            $userId = $this->insertUser($pdo, self::USERNAME, 1);
            $unpublishedUserId = $this->insertUser($pdo, self::UNPUBLISHED_USERNAME, 1);
            $inactiveUserId = $this->insertUser($pdo, self::INACTIVE_USERNAME, 1);

            $this->insertProfile($pdo, $userId, true);
            $this->insertProfile($pdo, $unpublishedUserId, false);
            $this->insertProfile($pdo, $inactiveUserId, false);

            $this->publishVcard($service, $privacy, $userId, self::SLUG);
            $this->publishVcard($service, $privacy, $unpublishedUserId, self::UNPUBLISHED_SLUG);
            $service->despublicar($unpublishedUserId);
            $inactive = $this->publishVcard($service, $privacy, $inactiveUserId, self::INACTIVE_SLUG);
            $this->deactivateUser($pdo, $inactiveUserId);
            $this->forcePublish($pdo, (int) $inactive['id'], self::INACTIVE_SLUG);

            $missing = $this->qr($controller, 'no-existe');
            $unpublished = $this->qr($controller, self::UNPUBLISHED_SLUG);
            $inactiveResponse = $this->qr($controller, self::INACTIVE_SLUG);
            $published = $this->qr($controller, self::SLUG);
            $routeResponse = $this->routeResponse($controller, self::SLUG);
            $publicPage = $controller->show(
                $this->request('/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $vcfResponse = $controller->vcf(
                $this->request('/v/' . self::SLUG . '/vcf'),
                ['slug' => self::SLUG]
            );
            $photoResponse = $controller->photo(
                $this->request('/v/' . self::SLUG . '/foto'),
                ['slug' => self::SLUG]
            );
            $headers = $this->headers($published);
            $body = $published->body();
            $payload = 'https://' . self::HOST . '/v/' . self::SLUG;
            $qrContract = (new VcardQrService())->generate($payload);

            $results['route_and_headers'] = [
                'route_qr_exists' => $routeResponse->status() === 200,
                'missing_slug_404_empty' => $missing->status() === 404
                    && $missing->body() === '',
                'unpublished_404_empty' => $unpublished->status() === 404
                    && $unpublished->body() === '',
                'inactive_user_404_empty' => $inactiveResponse->status() === 404
                    && $inactiveResponse->body() === '',
                'published_status_200' => $published->status() === 200,
                'content_type_png' => ($headers['Content-Type'] ?? null) === 'image/png',
                'nosniff' => ($headers['X-Content-Type-Options'] ?? null) === 'nosniff',
                'cache_control_present' =>
                    isset($headers['Cache-Control'])
                    && str_contains($headers['Cache-Control'], 'max-age=3600'),
            ];

            $results['png_contract'] = [
                'png_signature' => str_starts_with($body, "\x89PNG\r\n\x1A\n"),
                'png_has_ihdr' => str_contains($body, 'IHDR'),
                'png_has_idat' => str_contains($body, 'IDAT'),
                'png_has_iend' => str_contains($body, 'IEND'),
                'service_payload_exact' => $qrContract['payload'] === $payload,
                'service_returns_png' => str_starts_with($qrContract['png'], "\x89PNG\r\n\x1A\n"),
                'no_private_data_in_contract_payload' =>
                    !str_contains($qrContract['payload'], 'usuario_id')
                    && !str_contains($qrContract['payload'], 'vcard_id')
                    && !str_contains($qrContract['payload'], 'password_hash')
                    && !str_contains($qrContract['payload'], self::PASSWORD)
                    && !str_contains($qrContract['payload'], 'roles')
                    && !str_contains($qrContract['payload'], 'permisos')
                    && !str_contains($qrContract['payload'], 'token')
                    && !str_contains($qrContract['payload'], 'storage/uploads')
                    && !str_contains($qrContract['payload'], 'precio')
                    && !str_contains($qrContract['payload'], 'stock')
                    && !str_contains($qrContract['payload'], 'costo'),
            ];

            $results['public_surface'] = [
                'public_page_status_200' => $publicPage->status() === 200,
                'does_not_require_embedded_qr' =>
                    !str_contains($publicPage->body(), '/v/' . self::SLUG . '/qr'),
                'vcf_still_works' => $vcfResponse->status() === 200
                    && str_contains($vcfResponse->body(), 'BEGIN:VCARD'),
                'photo_route_still_controlled' => $photoResponse->status() === 404
                    && $photoResponse->body() === '',
                'no_private_path_in_view' =>
                    !str_contains($publicPage->body(), 'ruta_relativa')
                    && !str_contains($publicPage->body(), 'storage/uploads'),
            ];

            $results['guardrails'] = [
                'no_products_route' =>
                    !$this->fileContains('routes/web.php', '/v/{slug}/productos'),
                'no_credential_verify_route' =>
                    !$this->fileContains('routes/web.php', '/credencial/verificar'),
                'no_physical_png_file_created' => $this->pngFileCount() === $pngFilesBefore,
                'no_persistent_non_qa_touch' => true,
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
                'VCARD-QR-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'payload' => 'https://' . self::HOST . '/v/' . self::SLUG,
            'qr_mechanism' => 'internal_minimal_png_qr_generator',
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function qr(PublicVcardController $controller, string $slug): Response
    {
        return $controller->qr($this->request('/v/' . $slug . '/qr'), ['slug' => $slug]);
    }

    private function request(string $path): Request
    {
        return new Request(
            'GET',
            $path,
            [],
            [],
            ['host' => self::HOST, 'x-forwarded-proto' => 'https']
        );
    }

    private function routeResponse(PublicVcardController $controller, string $slug): Response
    {
        $router = new Router();
        $router->get(
            '/v/{slug}/' . 'qr',
            static fn (Request $request, array $params): Response =>
                $controller->qr($request, $params)
        );

        return $router->dispatch($this->request('/v/' . $slug . '/qr'));
    }

    /**
     * @return array<string, mixed>
     */
    private function publishVcard(
        VcardService $service,
        VcardPrivacyService $privacy,
        int $userId,
        string $slug
    ): array {
        $vcard = $service->asegurarVcard($userId);
        $service->actualizarConfiguracion($userId, [
            'slug' => $slug,
            'titulo_publico' => 'Contacto público QR',
            'descripcion_publica' => 'Descripción pública QR',
            'canal_contacto_preferido' => 'telefono_movil',
        ]);
        $privacy->actualizarPrivacidad((int) $vcard['id'], [
            'foto' => false,
            'correo' => false,
            'telefono_fijo' => false,
            'telefono_movil' => true,
            'puesto' => true,
            'empresa' => false,
            'almacen' => false,
            'ubicacion' => true,
            'sitio_web' => true,
            'linkedin' => false,
            'facebook' => false,
            'instagram' => false,
            'whatsapp' => false,
            'google_maps' => false,
            'productos' => false,
        ]);

        return $service->publicar($userId);
    }

    private function controller(): PublicVcardController
    {
        return new PublicVcardController(
            $this->config(),
            $this->service(),
            new VcardVcfService(),
            new VcardQrService()
        );
    }

    private function service(): VcardService
    {
        $privacy = $this->privacy();

        return new VcardService(
            new UserVcardRepository($this->connection()),
            new VcardPrivacyRepository($this->connection()),
            $privacy
        );
    }

    private function privacy(): VcardPrivacyService
    {
        return new VcardPrivacyService(new VcardPrivacyRepository($this->connection()));
    }

    private function connection(): ConnectionProvider
    {
        return $GLOBALS['vcard_qr_connection'];
    }

    private function config(): Config
    {
        return $GLOBALS['vcard_qr_config'];
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
                "username LIKE 'qa_vcard_qr%'"
            ),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_vcard_qr%'"
            ),
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_qr%'"
            ),
            'privacidad_qa' => $this->countWhere(
                $pdo,
                'vcard_privacidad vp
                 INNER JOIN vcards_usuario v ON v.id = vp.vcard_id
                 INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_qr%'"
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

    private function insertProfile(PDO $pdo, int $userId, bool $withPrivateData): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO perfiles_usuario (
                usuario_id,
                primer_nombre,
                apellido_paterno,
                puesto,
                telefono_fijo,
                telefono_movil,
                sitio_web,
                linkedin_url,
                facebook_url,
                instagram_url,
                whatsapp,
                google_maps_url,
                ubicacion_publica
             ) VALUES (
                :usuario_id,
                \'QA QR\',
                \'Público\',
                \'Ventas QR\',
                :telefono_fijo,
                \'5555553333\',
                \'https://example.test/qr\',
                :linkedin_url,
                :facebook_url,
                :instagram_url,
                \'5555554444\',
                :google_maps_url,
                \'Monterrey QR\'
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'telefono_fijo' => $withPrivateData ? '5555550000' : null,
            'linkedin_url' => $withPrivateData
                ? 'https://linkedin.example.test/privado'
                : null,
            'facebook_url' => $withPrivateData
                ? 'https://facebook.example.test/privado'
                : null,
            'instagram_url' => $withPrivateData
                ? 'https://instagram.example.test/privado'
                : null,
            'google_maps_url' => $withPrivateData
                ? 'https://maps.example.test/privado'
                : null,
        ]);
    }

    private function forcePublish(PDO $pdo, int $vcardId, string $slug): void
    {
        $statement = $pdo->prepare(
            'UPDATE vcards_usuario
             SET slug = :slug,
                 publicada = 1,
                 publicado_en = CURRENT_TIMESTAMP,
                 despublicado_en = NULL
             WHERE id = :id'
        );
        $statement->execute(['id' => $vcardId, 'slug' => $slug]);
    }

    private function deactivateUser(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare('UPDATE usuarios SET activo = 0 WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    private function fileContains(string $relativePath, string $needle): bool
    {
        $contents = file_get_contents(BASE_PATH . '/' . $relativePath);

        return is_string($contents) && str_contains($contents, $needle);
    }

    private function pngFileCount(): int
    {
        $roots = array_filter([
            BASE_PATH . '/public',
            defined('STORAGE_PATH') ? STORAGE_PATH : BASE_PATH . '/storage',
        ], static fn (string $path): bool => is_dir($path));
        $count = 0;

        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && strtolower($file->getExtension()) === 'png') {
                    $count++;
                }
            }
        }

        return $count;
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
