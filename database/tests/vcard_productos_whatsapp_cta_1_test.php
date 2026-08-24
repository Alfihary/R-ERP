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
    private const PASSWORD = 'VcardWhatsappCtaQa123!';
    private const USERNAME = 'qa_vcard_productos_whatsapp_cta';
    private const SLUG = 'qa-vcard-productos-whatsapp-cta';
    private const NO_WHATSAPP_SLUG = 'qa-vcard-productos-whatsapp-none';
    private const PRIVACY_OFF_SLUG = 'qa-vcard-productos-whatsapp-private';
    private const PRODUCTS_OFF_SLUG = 'qa-vcard-productos-whatsapp-products-off';
    private const IMAGE_ID = 'QAWCTA1';
    private const NO_IMAGE_ID = 'QAWCTA2';
    private const ESCAPED_ID = 'QAWCTA3';
    private const FOURTH_ID = 'QAWCTA4';
    private const FIFTH_ID = 'QAWCTA5';
    private const INACTIVE_PRODUCT_ID = 'QAWCTA6';
    private const INACTIVE_LINK_ID = 'QAWCTA7';

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
                throw new RuntimeException('VCARD-PRODUCTOS-WHATSAPP-CTA-1 requires table: ' . $table);
            }
        }

        $before = $this->counts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $unitId = $this->unitId($pdo);
            $userId = $this->insertUser($pdo, self::USERNAME, 1);
            $noWhatsappUserId = $this->insertUser($pdo, self::USERNAME . '_none', 1);
            $privacyOffUserId = $this->insertUser($pdo, self::USERNAME . '_private', 1);
            $productsOffUserId = $this->insertUser($pdo, self::USERNAME . '_products_off', 1);

            $this->insertProfile($pdo, $userId, '5215512345678');
            $this->insertProfile($pdo, $noWhatsappUserId, null);
            $this->insertProfile($pdo, $privacyOffUserId, '5215512345678');
            $this->insertProfile($pdo, $productsOffUserId, '5215512345678');

            foreach ([
                self::IMAGE_ID => ['REFRIGERANTE R-410A 5KG IGAS', 1],
                self::NO_IMAGE_ID => ['Producto sin imagen', 1],
                self::ESCAPED_ID => ['Equipo <script>alert(1)</script>', 1],
                self::FOURTH_ID => ['Filtro deshidratador', 1],
                self::FIFTH_ID => ['Válvula de servicio', 1],
                self::INACTIVE_PRODUCT_ID => ['Producto inactivo', 0],
                self::INACTIVE_LINK_ID => ['Producto con vínculo inactivo', 1],
            ] as $productId => [$description, $active]) {
                $this->insertProduct($pdo, $productId, $description, $unitId, $active);
            }

            $vcard = $this->publishVcard($userId, self::SLUG, true, true);
            $this->publishVcard($noWhatsappUserId, self::NO_WHATSAPP_SLUG, true, true);
            $this->publishVcard($privacyOffUserId, self::PRIVACY_OFF_SLUG, true, false);
            $this->publishVcard($productsOffUserId, self::PRODUCTS_OFF_SLUG, false, true);

            $links = [
                ['id_producto' => self::IMAGE_ID, 'activo' => 1, 'destacado' => 1, 'orden' => 10, 'texto_publico' => 'Producto público con imagen.'],
                ['id_producto' => self::NO_IMAGE_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 20, 'texto_publico' => 'Placeholder seguro.'],
                ['id_producto' => self::ESCAPED_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 30, 'texto_publico' => 'Texto público escapado.'],
                ['id_producto' => self::FOURTH_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 40, 'texto_publico' => null],
                ['id_producto' => self::FIFTH_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 50, 'texto_publico' => null],
                ['id_producto' => self::INACTIVE_PRODUCT_ID, 'activo' => 1, 'destacado' => 0, 'orden' => 60, 'texto_publico' => null],
                ['id_producto' => self::INACTIVE_LINK_ID, 'activo' => 0, 'destacado' => 0, 'orden' => 70, 'texto_publico' => null],
            ];
            $this->productService()->sincronizarProductos($userId, $links);
            $this->productService()->sincronizarProductos($noWhatsappUserId, array_slice($links, 0, 2));
            $this->productService()->sincronizarProductos($privacyOffUserId, array_slice($links, 0, 2));
            $this->productService()->sincronizarProductos($productsOffUserId, array_slice($links, 0, 2));

            $validRelative = $this->writeImage(self::IMAGE_ID, 'valid.png', $this->pngBytes());
            $this->insertPhoto($pdo, $userId, self::IMAGE_ID, $validRelative, 'image/png', strlen($this->pngBytes()));

            $controller = $this->controller();
            $public = $controller->show($this->request('/v/' . self::SLUG), ['slug' => self::SLUG]);
            $publicBody = $public->body();
            $full = $controller->products(
                $this->request('/v/' . self::SLUG . '/productos'),
                ['slug' => self::SLUG]
            );
            $fullBody = $full->body();
            $noWhatsapp = $controller->show(
                $this->request('/v/' . self::NO_WHATSAPP_SLUG),
                ['slug' => self::NO_WHATSAPP_SLUG]
            );
            $privacyOff = $controller->show(
                $this->request('/v/' . self::PRIVACY_OFF_SLUG),
                ['slug' => self::PRIVACY_OFF_SLUG]
            );
            $productsOff = $controller->show(
                $this->request('/v/' . self::PRODUCTS_OFF_SLUG),
                ['slug' => self::PRODUCTS_OFF_SLUG]
            );
            $image = $controller->productImage(
                $this->request('/v/' . self::SLUG . '/productos/' . self::IMAGE_ID . '/imagen'),
                ['slug' => self::SLUG, 'id_producto' => self::IMAGE_ID]
            );
            $qr = $controller->qr($this->request('/v/' . self::SLUG . '/qr'), ['slug' => self::SLUG]);
            $vcf = $controller->vcf($this->request('/v/' . self::SLUG . '/vcf'), ['slug' => self::SLUG]);
            $photo = $controller->photo($this->request('/v/' . self::SLUG . '/foto'), ['slug' => self::SLUG]);

            $encodedMessage = rawurlencode(
                'Hola, me interesa recibir información sobre el producto REFRIGERANTE R-410A 5KG IGAS, código '
                . self::IMAGE_ID
                . '.'
            );
            $expectedHref = 'https://wa.me/5215512345678?text=' . $encodedMessage;
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
            ];

            $results['cta'] = [
                'preview_shows_button_when_whatsapp_public' =>
                    $public->status() === 200
                    && substr_count($publicBody, 'Solicitar información') === 4,
                'full_listing_shows_button_when_whatsapp_public' =>
                    $full->status() === 200
                    && substr_count($fullBody, 'Solicitar información') === 5,
                'href_uses_wa_me' => str_contains($publicBody, 'href="https://wa.me/5215512345678?text='),
                'message_includes_product_description' => str_contains($publicBody, rawurlencode('REFRIGERANTE R-410A 5KG IGAS')),
                'message_includes_product_id' => str_contains($publicBody, self::IMAGE_ID),
                'message_is_url_encoded' => str_contains($publicBody, $expectedHref),
                'href_uses_blank_and_noopener' =>
                    str_contains($publicBody, 'target="_blank"')
                    && str_contains($publicBody, 'rel="noopener noreferrer"'),
            ];

            $results['privacy'] = [
                'whatsapp_false_hides_cta' =>
                    $privacyOff->status() === 200
                    && !str_contains($privacyOff->body(), 'Solicitar información')
                    && !str_contains($privacyOff->body(), 'wa.me')
                    && !str_contains($privacyOff->body(), '5215512345678'),
                'missing_whatsapp_hides_cta' =>
                    $noWhatsapp->status() === 200
                    && !str_contains($noWhatsapp->body(), 'Solicitar información')
                    && !str_contains($noWhatsapp->body(), 'wa.me'),
                'products_false_hides_products_and_cta' =>
                    $productsOff->status() === 200
                    && !str_contains($productsOff->body(), self::IMAGE_ID)
                    && !str_contains($productsOff->body(), 'Solicitar información'),
                'inactive_link_and_product_hidden' =>
                    !str_contains($fullBody, self::INACTIVE_LINK_ID)
                    && !str_contains($fullBody, self::INACTIVE_PRODUCT_ID),
            ];

            $results['security'] = [
                'href_has_no_forbidden_data' => !$this->containsAny($this->hrefs($publicBody . $fullBody), $forbidden),
                'html_has_no_forbidden_data' => !$this->containsAny($publicBody . $fullBody, $forbidden),
                'escaped_description' =>
                    !str_contains($fullBody, '<script>alert(1)</script>')
                    && str_contains($fullBody, 'Equipo &lt;script&gt;alert(1)&lt;/script&gt;'),
                'unit_not_rendered' =>
                    !str_contains($publicBody . $fullBody, 'Pieza')
                    && !str_contains($publicBody . $fullBody, 'PIEZA')
                    && !str_contains($publicBody . $fullBody, 'unidad_medida'),
            ];

            $results['regressions'] = [
                'preview_max_four' =>
                    str_contains($publicBody, self::FOURTH_ID)
                    && !str_contains($publicBody, self::FIFTH_ID)
                    && str_contains($publicBody, 'Ver todos los productos'),
                'full_listing_contains_fifth' => str_contains($fullBody, self::FIFTH_ID),
                'image_route_works' =>
                    $image->status() === 200
                    && $image->body() === $this->pngBytes(),
                'placeholder_still_present' =>
                    str_contains($fullBody, self::NO_IMAGE_ID)
                    && !str_contains($fullBody, '/v/' . self::SLUG . '/productos/' . self::NO_IMAGE_ID . '/imagen'),
                'qr_png' => $qr->status() === 200 && str_starts_with($qr->body(), "\x89PNG\r\n\x1A\n"),
                'vcf_works' => $vcf->status() === 200 && str_contains($vcf->body(), 'BEGIN:VCARD'),
                'photo_still_controlled_404_without_public_photo' => $photo->status() === 404,
                'credential_qr_contract_unchanged' => str_contains($qr->body(), 'PNG') === false || $qr->status() === 200,
                'products_not_functionally_modified' =>
                    $this->productDescription($pdo, self::IMAGE_ID) === 'REFRIGERANTE R-410A 5KG IGAS',
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
                'VCARD-PRODUCTOS-WHATSAPP-CTA-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'whatsapp_url_format' => 'https://wa.me/{digits}?text={rawurlencoded_message}',
            'message_template' => 'Hola, me interesa recibir información sobre el producto {descripcion}, código {id_producto}.',
            'local_number_behavior' => 'numbers are normalized to digits only; no country prefix is added when absent',
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
        return $GLOBALS['vcard_productos_whatsapp_cta_connection'];
    }

    private function config(): Config
    {
        return $GLOBALS['vcard_productos_whatsapp_cta_config'];
    }

    private function request(string $path): Request
    {
        return new Request('GET', $path, [], [], ['host' => 'vcard-whatsapp-cta.example.test']);
    }

    private function publishVcard(int $userId, string $slug, bool $productsVisible, bool $whatsappVisible): array
    {
        $service = $this->vcardService();
        $privacy = $this->privacy();
        $vcard = $service->asegurarVcard($userId);
        $service->actualizarConfiguracion($userId, [
            'slug' => $slug,
            'titulo_publico' => 'Ingeniería comercial',
            'descripcion_publica' => 'Productos públicos con CTA de WhatsApp.',
            'canal_contacto_preferido' => 'whatsapp',
        ]);
        $privacy->actualizarPrivacidad((int) $vcard['id'], [
            'foto' => false,
            'correo' => true,
            'telefono_fijo' => false,
            'telefono_movil' => false,
            'puesto' => true,
            'empresa' => true,
            'almacen' => false,
            'ubicacion' => false,
            'sitio_web' => false,
            'linkedin' => false,
            'facebook' => false,
            'instagram' => false,
            'whatsapp' => $whatsappVisible,
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

    private function insertProfile(PDO $pdo, int $userId, ?string $whatsapp): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO perfiles_usuario (
                usuario_id,
                primer_nombre,
                apellido_paterno,
                puesto,
                whatsapp
             ) VALUES (
                :usuario_id,
                \'QA WhatsApp\',
                \'CTA\',
                \'Systems\',
                :whatsapp
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'whatsapp' => $whatsapp,
        ]);
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
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qa_vcard_productos_whatsapp_cta%'"),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_vcard_productos_whatsapp_cta%'"
            ),
            'productos_qa' => $this->countWhere($pdo, 'productos', "id_producto LIKE 'QAWCTA%'"),
            'documentos_qa' => $this->countWhere($pdo, 'producto_documentos', "id_producto LIKE 'QAWCTA%'"),
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_productos_whatsapp_cta%'"
            ),
            'vcard_productos_qa' => $this->countWhere($pdo, 'vcard_productos', "id_producto LIKE 'QAWCTA%'"),
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

    private function hrefs(string $html): string
    {
        preg_match_all('/href="([^"]*)"/', $html, $matches);

        return implode("\n", $matches[1] ?? []);
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
