<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Domain\Vcards\VcardPrivacyService;
use App\Domain\Vcards\VcardProductService;
use App\Domain\Vcards\VcardQrService;
use App\Domain\Vcards\VcardService;
use App\Domain\Vcards\VcardVcfService;
use App\Http\Controllers\PublicVcardController;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\UserVcardRepository;
use App\Infrastructure\Repositories\VcardPrivacyRepository;
use App\Infrastructure\Repositories\VcardProductRepository;

return new class implements DatabaseTest {
    private const PASSWORD = 'VcardRedistribucionLayoutQa123!';
    private const USERNAME = 'qa_vcard_redistribucion_layout';
    private const SLUG = 'qa-vcard-redistribucion-layout';
    private const PRODUCT_ONE = 'QARLAY001';
    private const PRODUCT_TWO = 'QARLAY002';
    private const PRODUCT_THREE = 'QARLAY003';
    private const PRODUCT_FOUR = 'QARLAY004';
    private const PRODUCT_FIVE = 'QARLAY005';

    /** @var list<string> */
    private array $createdFiles = [];

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach ([
            'usuarios',
            'perfiles_usuario',
            'vcards_usuario',
            'vcard_privacidad',
            'vcard_productos',
            'productos',
            'producto_documentos',
            'unidades_medida',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'VCARD-PUBLICA-REDISTRIBUCION-LAYOUT-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $inventoryBefore = $this->optionalTableCount($pdo, 'inventario_existencias');
        $pricesBefore = $this->optionalTableCount($pdo, 'producto_precios');
        $results = [];
        $pdo->beginTransaction();

        try {
            $unitId = $this->unitId($pdo);
            $userId = $this->insertUser($pdo, self::USERNAME);
            $this->insertProfile($pdo, $userId);

            foreach ([
                self::PRODUCT_ONE => 'Mini Split Público',
                self::PRODUCT_TWO => 'Compresor Público',
                self::PRODUCT_THREE => 'Condensadora Pública',
                self::PRODUCT_FOUR => 'Controlador Público',
                self::PRODUCT_FIVE => 'Evaporador Público',
            ] as $productId => $description) {
                $this->insertProduct($pdo, $productId, $description, $unitId);
            }

            $this->publishVcard($userId, self::SLUG);
            $this->productService()->sincronizarProductos($userId, [
                ['id_producto' => self::PRODUCT_ONE, 'activo' => 1, 'destacado' => 1, 'orden' => 10, 'texto_publico' => 'Equipo público destacado.'],
                ['id_producto' => self::PRODUCT_TWO, 'activo' => 1, 'destacado' => 0, 'orden' => 20, 'texto_publico' => 'Equipo público seguro.'],
                ['id_producto' => self::PRODUCT_THREE, 'activo' => 1, 'destacado' => 0, 'orden' => 30, 'texto_publico' => 'Equipo público seguro.'],
                ['id_producto' => self::PRODUCT_FOUR, 'activo' => 1, 'destacado' => 0, 'orden' => 40, 'texto_publico' => 'Equipo público seguro.'],
                ['id_producto' => self::PRODUCT_FIVE, 'activo' => 1, 'destacado' => 0, 'orden' => 50, 'texto_publico' => 'Equipo visible en listado completo.'],
            ]);

            $imageBytes = $this->pngBytes();
            $relativePath = $this->writeImage(self::PRODUCT_ONE, 'layout.png', $imageBytes);
            $this->insertPhoto($pdo, $userId, self::PRODUCT_ONE, $relativePath, 'image/png', strlen($imageBytes));

            $controller = $this->controller();
            $public = $controller->show($this->request('/v/' . self::SLUG), ['slug' => self::SLUG]);
            $publicBody = $public->body();
            $full = $controller->products(
                $this->request('/v/' . self::SLUG . '/productos'),
                ['slug' => self::SLUG]
            );
            $fullBody = $full->body();
            $image = $controller->productImage(
                $this->request('/v/' . self::SLUG . '/productos/' . self::PRODUCT_ONE . '/imagen'),
                ['slug' => self::SLUG, 'id_producto' => self::PRODUCT_ONE]
            );
            $qr = $controller->qr($this->request('/v/' . self::SLUG . '/qr'), ['slug' => self::SLUG]);
            $vcf = $controller->vcf($this->request('/v/' . self::SLUG . '/vcf'), ['slug' => self::SLUG]);
            $photo = $controller->photo($this->request('/v/' . self::SLUG . '/foto'), ['slug' => self::SLUG]);
            $combinedHtml = $publicBody . $fullBody;

            $results['layout_distribution'] = [
                'public_route_200' => $public->status() === 200,
                'headline_visible' =>
                    str_contains($publicBody, 'QA Perfil Redistribuido')
                    && str_contains($publicBody, 'Systems'),
                'top_panel_present' =>
                    str_contains($publicBody, 'vcard-public__top-panel')
                    && str_contains($publicBody, 'vcard-public__contact-panel')
                    && str_contains($publicBody, 'vcard-public__actions'),
                'contact_cards_left_visible' =>
                    str_contains($publicBody, 'Móvil')
                    && str_contains($publicBody, 'Teléfono')
                    && str_contains($publicBody, 'Email')
                    && str_contains($publicBody, '5512345678')
                    && str_contains($publicBody, '8181000000')
                    && str_contains($publicBody, self::USERNAME . '@example.test'),
                'primary_actions_right_visible' =>
                    str_contains($publicBody, 'Llamar ahora')
                    && str_contains($publicBody, 'Enviar correo')
                    && str_contains($publicBody, 'Enviar WhatsApp')
                    && str_contains($publicBody, 'vcard-public__action--products-mobile')
                    && !str_contains($publicBody, '>Ver productos públicos<'),
                'products_action_mobile_only' =>
                    $this->fileContains('public/css/modules/vcard-public.css', '.vcard-public__action--products-mobile')
                    && $this->fileContains('public/css/modules/vcard-public.css', 'display: none;')
                    && $this->fileContains('public/css/modules/vcard-public.css', 'display: inline-flex;'),
                'footer_distribution_present' =>
                    str_contains($publicBody, 'vcard-public__footer')
                    && str_contains($publicBody, 'Redes y enlaces')
                    && str_contains($publicBody, 'Agregar a contactos')
                    && str_contains($publicBody, '/v/' . self::SLUG . '/vcf'),
                'public_profile_not_restored' =>
                    !str_contains($publicBody, 'Perfil público')
                    && !str_contains($publicBody, 'No hay datos adicionales publicados.'),
            ];

            $results['products_preview'] = [
                'products_section_visible' =>
                    str_contains($publicBody, '<h2>Productos</h2>')
                    && str_contains($publicBody, 'aria-label="Productos"'),
                'products_section_hidden_on_mobile' =>
                    str_contains($publicBody, 'vcard-public__section--products-preview')
                    && $this->fileContains('public/css/modules/vcard-public.css', '.vcard-public__section--products-preview')
                    && $this->fileContains('public/css/modules/vcard-public.css', 'display: none;'),
                'preview_first_four_visible' =>
                    str_contains($publicBody, self::PRODUCT_ONE)
                    && str_contains($publicBody, self::PRODUCT_TWO)
                    && str_contains($publicBody, self::PRODUCT_THREE)
                    && str_contains($publicBody, self::PRODUCT_FOUR),
                'preview_limited_to_four' => !str_contains($publicBody, self::PRODUCT_FIVE),
                'full_link_available' =>
                    substr_count($publicBody, 'href="/v/' . self::SLUG . '/productos"') === 2
                    && str_contains($publicBody, 'Ver todos los productos'),
                'full_listing_shows_fifth' =>
                    $full->status() === 200
                    && str_contains($fullBody, self::PRODUCT_FIVE),
                'whatsapp_cta_preserved' =>
                    substr_count($publicBody, 'Solicitar información') === 4
                    && str_contains($publicBody, 'https://wa.me/5215512345678?text='),
                'product_image_route_preserved' =>
                    str_contains($publicBody, '/v/' . self::SLUG . '/productos/' . self::PRODUCT_ONE . '/imagen')
                    && $image->status() === 200
                    && $image->body() === $imageBytes,
            ];

            $results['related_public_routes'] = [
                'qr_png_works' => $qr->status() === 200 && str_starts_with($qr->body(), "\x89PNG\r\n\x1A\n"),
                'vcf_works' => $vcf->status() === 200 && str_contains($vcf->body(), 'BEGIN:VCARD'),
                'photo_endpoint_controlled' => in_array($photo->status(), [200, 404], true),
                'credential_qr_route_unchanged' =>
                    $this->fileContains('routes/web.php', "'/perfil/credencial/' . 'qr'"),
            ];

            $results['privacy_and_scope'] = [
                'html_has_no_storage_uploads' => !str_contains($combinedHtml, 'storage/uploads'),
                'html_has_no_sensitive_paths' =>
                    !str_contains($combinedHtml, 'C:\\')
                    && !str_contains($combinedHtml, 'C:/'),
                'html_has_no_forbidden_fields' => !$this->containsAny($combinedHtml, [
                    'token_hash',
                    'password_hash',
                    'ruta_relativa',
                    'storage_path',
                    '/credencial/verificar/',
                    'precio',
                    'precio_minimo',
                    'stock',
                    'existencia',
                    'costo',
                    'proveedor',
                    'almacén',
                ]),
                'products_not_functionally_modified' =>
                    $this->productDescription($pdo, self::PRODUCT_ONE) === 'Mini Split Público',
                'inventory_not_modified' =>
                    $this->optionalTableCount($pdo, 'inventario_existencias') === $inventoryBefore,
                'prices_not_modified' =>
                    $this->optionalTableCount($pdo, 'producto_precios') === $pricesBefore,
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
                'VCARD-PUBLICA-REDISTRIBUCION-LAYOUT-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        if ($after !== $before) {
            throw new RuntimeException('VCARD-PUBLICA-REDISTRIBUCION-LAYOUT-1 transient data was not rolled back.');
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back_and_files_removed',
        ];
    }

    private function controller(): PublicVcardController
    {
        return new PublicVcardController(
            $this->config(),
            $this->vcardService(),
            new VcardVcfService(),
            new VcardQrService(),
            $this->productService()
        );
    }

    private function productService(): VcardProductService
    {
        return new VcardProductService(
            new VcardProductRepository($this->connection()),
            $this->vcardService()
        );
    }

    private function vcardService(): VcardService
    {
        return new VcardService(
            new UserVcardRepository($this->connection()),
            new VcardPrivacyRepository($this->connection()),
            $this->privacy()
        );
    }

    private function privacy(): VcardPrivacyService
    {
        return new VcardPrivacyService(new VcardPrivacyRepository($this->connection()));
    }

    private function connection(): ConnectionProvider
    {
        return $GLOBALS['vcard_publica_redistribucion_layout_connection'];
    }

    private function config(): Config
    {
        return $GLOBALS['vcard_publica_redistribucion_layout_config'];
    }

    private function request(string $path): Request
    {
        return new Request('GET', $path, [], [], ['host' => 'vcard-redistribucion-layout.example.test']);
    }

    private function publishVcard(int $userId, string $slug): array
    {
        $service = $this->vcardService();
        $privacy = $this->privacy();
        $vcard = $service->asegurarVcard($userId);
        $service->actualizarConfiguracion($userId, [
            'slug' => $slug,
            'titulo_publico' => 'Systems',
            'descripcion_publica' => 'Soluciones públicas de refrigeración.',
            'canal_contacto_preferido' => 'whatsapp',
        ]);
        $privacy->actualizarPrivacidad((int) $vcard['id'], [
            'foto' => false,
            'correo' => true,
            'telefono_fijo' => true,
            'telefono_movil' => true,
            'puesto' => true,
            'empresa' => true,
            'almacen' => false,
            'ubicacion' => true,
            'sitio_web' => true,
            'linkedin' => true,
            'facebook' => true,
            'instagram' => true,
            'whatsapp' => true,
            'google_maps' => true,
            'productos' => true,
        ]);

        return $service->publicar($userId);
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

    private function insertProfile(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO perfiles_usuario (
                usuario_id,
                primer_nombre,
                apellido_paterno,
                puesto,
                telefono_fijo,
                telefono_movil,
                whatsapp,
                sitio_web,
                linkedin_url,
                facebook_url,
                instagram_url,
                google_maps_url,
                ubicacion_publica
             ) VALUES (
                :usuario_id,
                \'QA Perfil\',
                \'Redistribuido\',
                \'Systems\',
                \'8181000000\',
                \'5512345678\',
                \'5215512345678\',
                \'https://gruporefrigerantes.example.test\',
                \'https://linkedin.example.test/grupo-refrigerantes\',
                \'https://facebook.example.test/grupo-refrigerantes\',
                \'https://instagram.example.test/grupo-refrigerantes\',
                \'https://maps.example.test/grupo-refrigerantes\',
                \'Monterrey, NL\'
             )'
        );
        $statement->execute(['usuario_id' => $userId]);
    }

    private function insertProduct(PDO $pdo, string $id, string $description, int $unitId): void
    {
        $pdo->prepare(
            'INSERT INTO productos (
                id_producto,
                descripcion,
                descripcion_larga,
                unidad_medida_id,
                activo
             ) VALUES (
                :id_producto,
                :descripcion,
                :descripcion_larga,
                :unidad_medida_id,
                1
             )'
        )->execute([
            'id_producto' => $id,
            'descripcion' => $description,
            'descripcion_larga' => 'Descripción interna QA no pública',
            'unidad_medida_id' => $unitId,
        ]);
    }

    private function insertPhoto(
        PDO $pdo,
        int $actorId,
        string $productId,
        string $relativePath,
        string $mimeType,
        int $size
    ): void {
        $pdo->prepare(
            'INSERT INTO producto_documentos (
                id_producto,
                tipo_documento,
                nombre_original,
                ruta_relativa,
                mime_type,
                tamano_bytes,
                es_principal,
                activo,
                creado_por,
                actualizado_por
             ) VALUES (
                :id_producto,
                \'FOTO_PRINCIPAL\',
                :nombre_original,
                :ruta_relativa,
                :mime_type,
                :tamano_bytes,
                1,
                1,
                :creado_por,
                :actualizado_por
             )'
        )->execute([
            'id_producto' => $productId,
            'nombre_original' => basename($relativePath),
            'ruta_relativa' => $relativePath,
            'mime_type' => $mimeType,
            'tamano_bytes' => $size,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }

    private function unitId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            "SELECT id FROM unidades_medida
             WHERE codigo = 'PIEZA'
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('No active PIEZA unit found.');
        }

        return $id;
    }

    private function writeImage(string $productId, string $filename, string $body): string
    {
        $relative = $productId . '/' . $filename;
        $path = $this->storageRoot() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create QA product image directory.');
        }

        if (file_put_contents($path, $body) === false) {
            throw new RuntimeException('Unable to write QA product image.');
        }

        $this->createdFiles[] = $path;

        return $relative;
    }

    private function storageRoot(): string
    {
        $storagePath = rtrim(
            (string) $this->config()->get('paths.STORAGE_PATH', STORAGE_PATH),
            '/\\'
        );

        return $storagePath . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, 'uploads/productos');
    }

    private function cleanupFiles(): void
    {
        foreach (array_reverse($this->createdFiles) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }

            $directory = dirname($path);
            $root = $this->normalizedPath($this->storageRoot());

            while (
                is_dir($directory)
                && $this->normalizedPath($directory) !== $root
                && str_starts_with($this->normalizedPath($directory), $root . '/')
            ) {
                $items = @scandir($directory);

                if ($items === false || array_diff($items, ['.', '..']) !== []) {
                    break;
                }

                @rmdir($directory);
                $directory = dirname($directory);
            }
        }

        $this->createdFiles = [];
    }

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
            true
        ) ?: '';
    }

    private function productDescription(PDO $pdo, string $productId): string
    {
        $statement = $pdo->prepare(
            'SELECT descripcion FROM productos WHERE id_producto = :id_producto LIMIT 1'
        );
        $statement->execute(['id_producto' => $productId]);

        return (string) $statement->fetchColumn();
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
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qa_vcard_redistribucion_layout%'"),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_vcard_redistribucion_layout%'"
            ),
            'productos_qa' => $this->countWhere($pdo, 'productos', "id_producto LIKE 'QARLAY%'"),
            'documentos_qa' => $this->countWhere($pdo, 'producto_documentos', "id_producto LIKE 'QARLAY%'"),
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_redistribucion_layout%'"
            ),
            'vcard_productos_qa' => $this->countWhere($pdo, 'vcard_productos', "id_producto LIKE 'QARLAY%'"),
        ];
    }

    private function optionalTableCount(PDO $pdo, string $table): int
    {
        if (!$this->tableExists($pdo, $table)) {
            return 0;
        }

        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)
            ->fetchColumn();
    }

    private function fileContains(string $relativePath, string $needle): bool
    {
        $contents = file_get_contents(BASE_PATH . '/' . $relativePath);

        return is_string($contents) && str_contains($contents, $needle);
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        $haystack = strtolower($haystack);

        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
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

    private function normalizedPath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
};
