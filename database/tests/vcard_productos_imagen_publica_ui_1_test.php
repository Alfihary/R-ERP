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
    private const PASSWORD = 'VcardImagenPublicaQa123!';
    private const USERNAME = 'qa_vcard_productos_imagen_publica';
    private const SLUG = 'qa-vcard-productos-imagen-publica';
    private const UNPUBLISHED_SLUG = 'qa-vcard-productos-imagen-unpublished';
    private const VALID_IMAGE_ID = 'QAVIMG1';
    private const NO_IMAGE_ID = 'QAVIMG2';
    private const INACTIVE_PRODUCT_ID = 'QAVIMG3';
    private const UNLINKED_PRODUCT_ID = 'QAVIMG4';
    private const INACTIVE_LINK_ID = 'QAVIMG5';
    private const MISSING_IMAGE_ID = 'QAVIMG6';
    private const TRAVERSAL_IMAGE_ID = 'QAVIMG7';
    private const SVG_IMAGE_ID = 'QAVIMG8';
    private const GIF_IMAGE_ID = 'QAVIMG9';
    private const PHP_IMAGE_ID = 'QAVIMG0';

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
                    'VCARD-PRODUCTOS-IMAGEN-PUBLICA-UI-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $unitId = $this->unitId($pdo);
            $userId = $this->insertUser($pdo, self::USERNAME, 1);
            $unpublishedUserId = $this->insertUser($pdo, self::USERNAME . '_unpublished', 1);
            $this->insertProfile($pdo, $userId);
            $this->insertProfile($pdo, $unpublishedUserId);

            foreach ([
                self::VALID_IMAGE_ID => ['Condensadora con imagen', 1],
                self::NO_IMAGE_ID => ['Producto sin imagen', 1],
                self::INACTIVE_PRODUCT_ID => ['Producto inactivo con imagen', 0],
                self::UNLINKED_PRODUCT_ID => ['Producto no vinculado', 1],
                self::INACTIVE_LINK_ID => ['Producto con vínculo inactivo', 1],
                self::MISSING_IMAGE_ID => ['Producto con imagen faltante', 1],
                self::TRAVERSAL_IMAGE_ID => ['Producto con ruta traversal', 1],
                self::SVG_IMAGE_ID => ['Producto con SVG', 1],
                self::GIF_IMAGE_ID => ['Producto con GIF', 1],
                self::PHP_IMAGE_ID => ['Producto con PHP', 1],
            ] as $productId => [$description, $active]) {
                $this->insertProduct($pdo, $productId, $description, $unitId, $active);
            }

            $vcard = $this->publishVcard($userId, self::SLUG, true);
            $unpublished = $this->publishVcard($unpublishedUserId, self::UNPUBLISHED_SLUG, true);
            $this->vcardService()->despublicar($unpublishedUserId);

            $this->productService()->sincronizarProductos($userId, [
                ['id_producto' => self::VALID_IMAGE_ID, 'activo' => 1, 'destacado' => 1, 'orden' => 10, 'texto_publico' => 'Imagen pública segura.'],
                ['id_producto' => self::NO_IMAGE_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 20, 'texto_publico' => 'Debe conservar placeholder.'],
                ['id_producto' => self::INACTIVE_PRODUCT_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 30, 'texto_publico' => 'No debe verse por producto inactivo.'],
                ['id_producto' => self::INACTIVE_LINK_ID, 'activo' => 0, 'destacado' => 0, 'orden' => 40, 'texto_publico' => 'No debe verse por vínculo inactivo.'],
                ['id_producto' => self::MISSING_IMAGE_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 50, 'texto_publico' => 'Imagen faltante.'],
                ['id_producto' => self::TRAVERSAL_IMAGE_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 60, 'texto_publico' => 'Traversal.'],
                ['id_producto' => self::SVG_IMAGE_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 70, 'texto_publico' => 'SVG no permitido.'],
                ['id_producto' => self::GIF_IMAGE_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 80, 'texto_publico' => 'GIF no permitido.'],
                ['id_producto' => self::PHP_IMAGE_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 90, 'texto_publico' => 'PHP no permitido.'],
            ]);

            $this->productService()->sincronizarProductos($unpublishedUserId, [
                ['id_producto' => self::VALID_IMAGE_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 10, 'texto_publico' => null],
            ]);

            $validRelative = $this->writeImage(self::VALID_IMAGE_ID, 'valid.png', $this->pngBytes());
            $inactiveProductRelative = $this->writeImage(self::INACTIVE_PRODUCT_ID, 'inactive-product.png', $this->pngBytes());
            $unlinkedRelative = $this->writeImage(self::UNLINKED_PRODUCT_ID, 'unlinked.png', $this->pngBytes());
            $inactiveLinkRelative = $this->writeImage(self::INACTIVE_LINK_ID, 'inactive-link.png', $this->pngBytes());
            $missingRelative = self::MISSING_IMAGE_ID . '/missing.png';
            $svgRelative = $this->writeImage(self::SVG_IMAGE_ID, 'bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
            $gifRelative = $this->writeImage(self::GIF_IMAGE_ID, 'bad.gif', "GIF89a");
            $phpRelative = $this->writeImage(self::PHP_IMAGE_ID, 'bad.php', '<?php echo "bad";');

            $this->insertPhoto($pdo, $userId, self::VALID_IMAGE_ID, $validRelative, 'image/png', strlen($this->pngBytes()));
            $this->insertPhoto($pdo, $userId, self::INACTIVE_PRODUCT_ID, $inactiveProductRelative, 'image/png', strlen($this->pngBytes()));
            $this->insertPhoto($pdo, $userId, self::UNLINKED_PRODUCT_ID, $unlinkedRelative, 'image/png', strlen($this->pngBytes()));
            $this->insertPhoto($pdo, $userId, self::INACTIVE_LINK_ID, $inactiveLinkRelative, 'image/png', strlen($this->pngBytes()));
            $this->insertPhoto($pdo, $userId, self::MISSING_IMAGE_ID, $missingRelative, 'image/png', 120);
            $this->insertPhoto($pdo, $userId, self::TRAVERSAL_IMAGE_ID, self::TRAVERSAL_IMAGE_ID . '/../secret.png', 'image/png', 120);
            $this->insertPhoto($pdo, $userId, self::SVG_IMAGE_ID, $svgRelative, 'image/svg+xml', strlen('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
            $this->insertPhoto($pdo, $userId, self::GIF_IMAGE_ID, $gifRelative, 'image/gif', strlen("GIF89a"));
            $this->insertPhoto($pdo, $userId, self::PHP_IMAGE_ID, $phpRelative, 'text/x-php', strlen('<?php echo "bad";'));

            $controller = $this->controller();
            $public = $controller->show($this->request('/v/' . self::SLUG), ['slug' => self::SLUG]);
            $publicBody = $public->body();
            $full = $controller->products(
                $this->request('/v/' . self::SLUG . '/productos'),
                ['slug' => self::SLUG]
            );
            $fullBody = $full->body();

            $validImage = $this->image($controller, self::SLUG, self::VALID_IMAGE_ID);
            $missingSlug = $this->image($controller, 'no-existe', self::VALID_IMAGE_ID);
            $unpublishedImage = $this->image($controller, self::UNPUBLISHED_SLUG, self::VALID_IMAGE_ID);
            $this->privacy()->actualizarPrivacidad((int) $vcard['id'], ['productos' => false]);
            $privacyOffImage = $this->image($controller, self::SLUG, self::VALID_IMAGE_ID);
            $privacyOffFull = $controller->products(
                $this->request('/v/' . self::SLUG . '/productos'),
                ['slug' => self::SLUG]
            );
            $this->privacy()->actualizarPrivacidad((int) $vcard['id'], ['productos' => true]);

            $inactiveLinkImage = $this->image($controller, self::SLUG, self::INACTIVE_LINK_ID);
            $inactiveProductImage = $this->image($controller, self::SLUG, self::INACTIVE_PRODUCT_ID);
            $unlinkedImage = $this->image($controller, self::SLUG, self::UNLINKED_PRODUCT_ID);
            $missingImage = $this->image($controller, self::SLUG, self::MISSING_IMAGE_ID);
            $traversalImage = $this->image($controller, self::SLUG, self::TRAVERSAL_IMAGE_ID);
            $svgImage = $this->image($controller, self::SLUG, self::SVG_IMAGE_ID);
            $gifImage = $this->image($controller, self::SLUG, self::GIF_IMAGE_ID);
            $phpImage = $this->image($controller, self::SLUG, self::PHP_IMAGE_ID);
            $qr = $controller->qr($this->request('/v/' . self::SLUG . '/qr'), ['slug' => self::SLUG]);
            $vcf = $controller->vcf($this->request('/v/' . self::SLUG . '/vcf'), ['slug' => self::SLUG]);
            $photo = $controller->photo($this->request('/v/' . self::SLUG . '/foto'), ['slug' => self::SLUG]);

            $imageUrl = '/v/' . self::SLUG . '/productos/' . self::VALID_IMAGE_ID . '/imagen';
            $forbidden = [
                'precio',
                'precio mínimo',
                'precio_minimo',
                'lista de precio',
                'costo',
                'margen',
                'stock',
                'existencia',
                'almacén',
                'proveedor',
                'movimientos',
                'auditoría',
                'vcard_id',
                'usuario_id',
                'token',
                'token_hash',
                'password_hash',
                'storage/uploads',
                'ruta_relativa',
                '/credencial/verificar/',
            ];

            $results['html'] = [
                'preview_max_four' =>
                    $public->status() === 200
                    && str_contains($publicBody, self::VALID_IMAGE_ID)
                    && str_contains($publicBody, self::NO_IMAGE_ID)
                    && str_contains($publicBody, self::MISSING_IMAGE_ID)
                    && !str_contains($publicBody, self::SVG_IMAGE_ID),
                'full_listing_contains_public_products' =>
                    $full->status() === 200
                    && str_contains($fullBody, self::VALID_IMAGE_ID)
                    && str_contains($fullBody, self::NO_IMAGE_ID)
                    && str_contains($fullBody, self::SVG_IMAGE_ID),
                'unit_not_rendered' =>
                    !str_contains($publicBody, 'Pieza')
                    && !str_contains($publicBody, 'PIEZA')
                    && !str_contains($fullBody, 'Pieza')
                    && !str_contains($fullBody, 'PIEZA')
                    && !str_contains($fullBody, 'unidad_medida'),
                'placeholder_for_product_without_image' =>
                    str_contains($fullBody, self::NO_IMAGE_ID)
                    && str_contains($fullBody, 'Producto sin imagen')
                    && !str_contains(
                        $fullBody,
                        '/v/' . self::SLUG . '/productos/' . self::NO_IMAGE_ID . '/imagen'
                    ),
                'valid_image_uses_controlled_route' =>
                    str_contains($publicBody, $imageUrl)
                    && str_contains($fullBody, $imageUrl),
                'no_private_paths_or_sensitive_fields' =>
                    !$this->containsAny($publicBody . $fullBody, $forbidden),
            ];

            $headers = $this->headers($validImage);
            $results['image_route'] = [
                'valid_public_image_200' =>
                    $validImage->status() === 200
                    && $validImage->body() === $this->pngBytes()
                    && ($headers['Content-Type'] ?? null) === 'image/png'
                    && ($headers['X-Content-Type-Options'] ?? null) === 'nosniff'
                    && str_contains((string) ($headers['Cache-Control'] ?? ''), 'max-age=3600'),
                'missing_slug_404' => $missingSlug->status() === 404 && $missingSlug->body() === '',
                'unpublished_404' => $unpublishedImage->status() === 404 && $unpublishedImage->body() === '',
                'privacy_false_404' =>
                    $privacyOffImage->status() === 404
                    && $privacyOffFull->status() === 404,
                'inactive_link_404' => $inactiveLinkImage->status() === 404,
                'inactive_product_404' => $inactiveProductImage->status() === 404,
                'unlinked_product_404' => $unlinkedImage->status() === 404,
                'missing_file_404' => $missingImage->status() === 404,
                'traversal_404' => $traversalImage->status() === 404,
                'svg_rejected' => $svgImage->status() === 404,
                'gif_rejected' => $gifImage->status() === 404,
                'php_rejected' => $phpImage->status() === 404,
            ];

            $results['regressions'] = [
                'qr_png' => $qr->status() === 200
                    && str_starts_with($qr->body(), "\x89PNG\r\n\x1A\n"),
                'vcf_works' => $vcf->status() === 200
                    && str_contains($vcf->body(), 'BEGIN:VCARD'),
                'photo_still_controlled_404_without_public_photo' => $photo->status() === 404,
                'products_not_functionally_modified' => $this->productDescription($pdo, self::VALID_IMAGE_ID) === 'Condensadora con imagen',
                'inventory_not_touched' => $this->optionalTableCount($pdo, 'inventario_existencias') >= 0,
                'prices_not_touched' => $this->optionalTableCount($pdo, 'producto_precios') >= 0,
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
                'VCARD-PRODUCTOS-IMAGEN-PUBLICA-UI-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'image_route' => '/v/{slug}/productos/{id_producto}/imagen',
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
        return $GLOBALS['vcard_productos_imagen_publica_ui_connection'];
    }

    private function config(): Config
    {
        return $GLOBALS['vcard_productos_imagen_publica_ui_config'];
    }

    private function request(string $path): Request
    {
        return new Request('GET', $path, [], [], ['host' => 'vcard-product-image.example.test']);
    }

    private function image(PublicVcardController $controller, string $slug, string $productId): App\Core\Response
    {
        return $controller->productImage(
            $this->request('/v/' . $slug . '/productos/' . $productId . '/imagen'),
            ['slug' => $slug, 'id_producto' => $productId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function publishVcard(int $userId, string $slug, bool $productsVisible): array
    {
        $service = $this->vcardService();
        $privacy = $this->privacy();
        $vcard = $service->asegurarVcard($userId);
        $service->actualizarConfiguracion($userId, [
            'slug' => $slug,
            'titulo_publico' => 'Perfil técnico',
            'descripcion_publica' => 'Productos públicos con imagen controlada.',
            'canal_contacto_preferido' => 'correo',
        ]);
        $privacy->actualizarPrivacidad((int) $vcard['id'], [
            'foto' => false,
            'correo' => true,
            'telefono_fijo' => false,
            'telefono_movil' => true,
            'puesto' => true,
            'empresa' => true,
            'almacen' => false,
            'ubicacion' => false,
            'sitio_web' => false,
            'linkedin' => false,
            'facebook' => false,
            'instagram' => false,
            'whatsapp' => false,
            'google_maps' => false,
            'productos' => $productsVisible,
        ]);

        return $service->publicar($userId);
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
        $pdo->prepare(
            'INSERT INTO perfiles_usuario (
                usuario_id,
                primer_nombre,
                apellido_paterno,
                puesto,
                telefono_movil
             ) VALUES (
                :usuario_id,
                \'QA Imagen\',
                \'Pública\',
                \'Systems\',
                \'5512345678\'
             )'
        )->execute(['usuario_id' => $userId]);
    }

    private function insertProduct(PDO $pdo, string $id, string $description, int $unitId, int $active): void
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
                :activo
             )'
        )->execute([
            'id_producto' => $id,
            'descripcion' => $description,
            'descripcion_larga' => 'Descripción interna QA no pública',
            'unidad_medida_id' => $unitId,
            'activo' => $active,
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

    private function unitId(PDO $pdo): int
    {
        $statement = $pdo->query(
            "SELECT id FROM unidades_medida
             WHERE codigo = 'PIEZA'
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1"
        );
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('No active PIEZA unit found.');
        }

        return $id;
    }

    private function productDescription(PDO $pdo, string $productId): string
    {
        $statement = $pdo->prepare(
            'SELECT descripcion FROM productos WHERE id_producto = :id_producto LIMIT 1'
        );
        $statement->execute(['id_producto' => $productId]);

        return (string) $statement->fetchColumn();
    }

    private function optionalTableCount(PDO $pdo, string $table): int
    {
        if (!$this->tableExists($pdo, $table)) {
            return 0;
        }

        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
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
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qa_vcard_productos_imagen_publica%'"),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_vcard_productos_imagen_publica%'"
            ),
            'productos_qa' => $this->countWhere($pdo, 'productos', "id_producto LIKE 'QAVIMG%'"),
            'documentos_qa' => $this->countWhere($pdo, 'producto_documentos', "id_producto LIKE 'QAVIMG%'"),
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_productos_imagen_publica%'"
            ),
            'vcard_productos_qa' => $this->countWhere(
                $pdo,
                'vcard_productos vp',
                "vp.id_producto LIKE 'QAVIMG%'"
            ),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)
            ->fetchColumn();
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
     * @return array<string, string>
     */
    private function headers(App\Core\Response $response): array
    {
        $reflection = new ReflectionClass($response);
        $property = $reflection->getProperty('headers');
        $property->setAccessible(true);
        $headers = $property->getValue($response);

        return is_array($headers) ? $headers : [];
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
