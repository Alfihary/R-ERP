<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Domain\Vcards\VcardPrivacyService;
use App\Domain\Vcards\VcardService;
use App\Domain\Vcards\VcardVcfService;
use App\Http\Controllers\PublicVcardController;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\UserVcardRepository;
use App\Infrastructure\Repositories\VcardPrivacyRepository;

return new class implements DatabaseTest {
    private const PASSWORD = 'VcardVcfQa123!';
    private const USERNAME = 'qa_vcard_vcf_1';
    private const UNPUBLISHED_USERNAME = 'qa_vcard_vcf_unpublished';
    private const INACTIVE_USERNAME = 'qa_vcard_vcf_inactive';
    private const SLUG = 'qa-vcard-vcf';
    private const UNPUBLISHED_SLUG = 'qa-vcard-vcf-unpublished';
    private const INACTIVE_SLUG = 'qa-vcard-vcf-inactive';

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
                throw new RuntimeException('VCARD-VCF-1 requires table: ' . $table);
            }
        }

        $before = $this->counts($pdo);
        $vcfFilesBefore = $this->vcfFileCount();
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

            $vcard = $this->publishVcard($service, $privacy, $userId, self::SLUG);
            $unpublished = $this->publishVcard(
                $service,
                $privacy,
                $unpublishedUserId,
                self::UNPUBLISHED_SLUG
            );
            $service->despublicar($unpublishedUserId);

            $inactiveVcard = $this->publishVcard(
                $service,
                $privacy,
                $inactiveUserId,
                self::INACTIVE_SLUG
            );
            $this->deactivateUser($pdo, $inactiveUserId);
            $this->forcePublish($pdo, (int) $inactiveVcard['id'], self::INACTIVE_SLUG);

            $missing = $controller->vcf(
                new Request('GET', '/v/no-existe/vcf'),
                ['slug' => 'no-existe']
            );
            $unpublishedResponse = $controller->vcf(
                new Request('GET', '/v/' . self::UNPUBLISHED_SLUG . '/vcf'),
                ['slug' => self::UNPUBLISHED_SLUG]
            );
            $inactiveResponse = $controller->vcf(
                new Request('GET', '/v/' . self::INACTIVE_SLUG . '/vcf'),
                ['slug' => self::INACTIVE_SLUG]
            );
            $published = $controller->vcf(
                new Request('GET', '/v/' . self::SLUG . '/vcf'),
                ['slug' => self::SLUG]
            );
            $publicPage = $controller->show(
                new Request('GET', '/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $routeResponse = $this->routeResponse($controller, self::SLUG);
            $headers = $this->headers($published);
            $body = $published->body();

            $results['route_and_headers'] = [
                'route_vcf_exists' => $routeResponse->status() === 200,
                'missing_slug_404_empty' => $missing->status() === 404
                    && $missing->body() === '',
                'unpublished_404_empty' => $unpublishedResponse->status() === 404
                    && $unpublishedResponse->body() === '',
                'inactive_user_404_empty' => $inactiveResponse->status() === 404
                    && $inactiveResponse->body() === '',
                'published_status_200' => $published->status() === 200,
                'content_type' =>
                    ($headers['Content-Type'] ?? null) === 'text/vcard; charset=utf-8',
                'content_disposition_attachment' =>
                    ($headers['Content-Disposition'] ?? null)
                    === 'attachment; filename="contacto-' . self::SLUG . '.vcf"',
                'nosniff' => ($headers['X-Content-Type-Options'] ?? null) === 'nosniff',
                'cache_control_present' =>
                    isset($headers['Cache-Control'])
                    && str_contains($headers['Cache-Control'], 'max-age=300'),
            ];

            $results['vcf_content'] = [
                'contains_begin' => str_contains($body, 'BEGIN:VCARD'),
                'contains_end' => str_contains($body, 'END:VCARD'),
                'contains_version' => str_contains($body, 'VERSION:3.0'),
                'contains_visible_name' => str_contains($body, 'FN:QA VCF Público'),
                'contains_visible_title' => str_contains($body, 'TITLE:Ventas VCF'),
                'contains_visible_mobile' => str_contains($body, 'TEL;TYPE=CELL:5555553333'),
                'contains_visible_site' =>
                    str_contains($body, 'URL;TYPE=WORK:https://example.test/vcf'),
                'contains_visible_note' =>
                    str_contains($body, 'NOTE:Descripción pública segura\\nTEL:999'),
                'private_fields_absent' =>
                    !str_contains($body, self::USERNAME . '@example.test')
                    && !str_contains($body, '5555550000')
                    && !str_contains($body, 'https://linkedin.example.test/privado')
                    && !str_contains($body, 'https://facebook.example.test/privado')
                    && !str_contains($body, 'https://instagram.example.test/privado')
                    && !str_contains($body, 'https://maps.example.test/privado'),
                'no_sensitive_or_internal_data' =>
                    !str_contains($body, 'usuario_id')
                    && !str_contains($body, 'vcard_id')
                    && !str_contains($body, 'password_hash')
                    && !str_contains($body, self::PASSWORD)
                    && !str_contains($body, 'roles')
                    && !str_contains($body, 'permisos')
                    && !str_contains($body, 'token')
                    && !str_contains($body, 'ruta_relativa')
                    && !str_contains($body, 'storage/uploads'),
                'no_price_stock_cost' =>
                    !str_contains($body, 'precio')
                    && !str_contains($body, 'stock')
                    && !str_contains($body, 'costo'),
                'no_embedded_photo' => !str_contains($body, 'PHOTO'),
                'line_injection_escaped' =>
                    !str_contains($body, "\r\nTEL:999")
                    && !str_contains($body, "\nTEL:999"),
                'semicolon_and_comma_escaped' =>
                    str_contains($body, 'TEL:999\\,')
                    && str_contains($body, 'coma\\; punto\\;')
                    && str_contains($body, 'diagonal \\\\'),
            ];

            $results['public_view'] = [
                'public_page_status_200' => $publicPage->status() === 200,
                'shows_vcf_link' =>
                    str_contains($publicPage->body(), 'Descargar contacto')
                    && str_contains($publicPage->body(), '/v/' . self::SLUG . '/vcf'),
                'no_private_path_in_view' =>
                    !str_contains($publicPage->body(), 'ruta_relativa')
                    && !str_contains($publicPage->body(), 'storage/uploads'),
            ];

            $results['guardrails'] = [
                'no_qr_route' => !$this->fileContains('routes/web.php', '/v/{slug}/qr'),
                'public_products_route_declared' =>
                    $this->fileContains('routes/web.php', '/v/{slug}/productos'),
                'no_credential_verify_route' =>
                    !$this->fileContains('routes/web.php', '/credencial/verificar'),
                'no_physical_vcf_file_created' => $this->vcfFileCount() === $vcfFilesBefore,
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
                'VCARD-VCF-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'vcard_version' => (new VcardVcfService())->version(),
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function routeResponse(PublicVcardController $controller, string $slug): Response
    {
        $router = new Router();
        $router->get(
            '/v/{slug}/' . 'vcf',
            static fn (Request $request, array $params): Response =>
                $controller->vcf($request, $params)
        );

        return $router->dispatch(new Request('GET', '/v/' . $slug . '/vcf'));
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
            'titulo_publico' => 'Contacto público VCF',
            'descripcion_publica' => "Descripción pública segura\nTEL:999, coma; punto; y diagonal \\",
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
            new VcardVcfService()
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
        return $GLOBALS['vcard_vcf_connection'];
    }

    private function config(): Config
    {
        return $GLOBALS['vcard_vcf_config'];
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
                "username LIKE 'qa_vcard_vcf%'"
            ),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_vcard_vcf%'"
            ),
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_vcf%'"
            ),
            'privacidad_qa' => $this->countWhere(
                $pdo,
                'vcard_privacidad vp
                 INNER JOIN vcards_usuario v ON v.id = vp.vcard_id
                 INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_vcf%'"
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
                \'QA VCF\',
                \'Público\',
                \'Ventas VCF\',
                :telefono_fijo,
                \'5555553333\',
                \'https://example.test/vcf\',
                :linkedin_url,
                :facebook_url,
                :instagram_url,
                \'5555554444\',
                :google_maps_url,
                \'Monterrey VCF\'
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

    private function vcfFileCount(): int
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
                if ($file instanceof SplFileInfo && strtolower($file->getExtension()) === 'vcf') {
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
