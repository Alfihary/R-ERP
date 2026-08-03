<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Vcards\VcardPrivacyService;
use App\Domain\Vcards\VcardProductService;
use App\Domain\Vcards\VcardQrService;
use App\Domain\Vcards\VcardService;
use App\Domain\Vcards\VcardValidationException;
use App\Domain\Vcards\VcardVcfService;
use App\Http\Controllers\PublicVcardController;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\UserVcardRepository;
use App\Infrastructure\Repositories\VcardPrivacyRepository;
use App\Infrastructure\Repositories\VcardProductRepository;

return new class implements DatabaseTest {
    private const PASSWORD = 'VcardProductosQa123!';
    private const USERNAME = 'qa_vcard_productos_1';
    private const INACTIVE_USERNAME = 'qa_vcard_productos_inactive';
    private const SLUG = 'qa-vcard-productos';
    private const INACTIVE_SLUG = 'qa-vcard-productos-inactive';

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
            'unidades_medida',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('VCARD-PRODUCTOS-1 requires table: ' . $table);
            }
        }

        $before = $this->counts($pdo);
        $service = $this->service();
        $privacy = $this->privacy();
        $controller = $this->controller();
        $results = [];
        $pdo->beginTransaction();

        try {
            $unitId = $this->unitId($pdo);
            $this->insertProduct($pdo, 'QAVCARDP1', 'Producto Público A', $unitId, 1);
            $this->insertProduct($pdo, 'QAVCARDP2', 'Producto Público B', $unitId, 1);
            $this->insertProduct($pdo, 'QAVCARDP3', 'Producto Inactivo', $unitId, 0);

            $userId = $this->insertUser($pdo, self::USERNAME, 1);
            $inactiveUserId = $this->insertUser($pdo, self::INACTIVE_USERNAME, 1);
            $this->insertProfile($pdo, $userId);
            $this->insertProfile($pdo, $inactiveUserId);
            $vcard = $this->publishVcard($userId, self::SLUG, false);
            $inactiveVcard = $this->publishVcard($inactiveUserId, self::INACTIVE_SLUG, true);

            $missingRejected = false;

            try {
                $service->sincronizarProductos($userId, [
                    ['id_producto' => 'NOEXISTE1', 'activo' => 1],
                ]);
            } catch (VcardValidationException) {
                $missingRejected = true;
            }

            $service->sincronizarProductos($userId, [
                [
                    'id_producto' => 'QAVCARDP2',
                    'activo' => 1,
                    'destacado' => 0,
                    'orden' => 20,
                    'texto_publico' => 'Texto público B',
                ],
                [
                    'id_producto' => 'QAVCARDP1',
                    'activo' => 1,
                    'destacado' => 1,
                    'orden' => 10,
                    'texto_publico' => 'Texto público A',
                ],
                [
                    'id_producto' => 'QAVCARDP1',
                    'activo' => 1,
                    'destacado' => 1,
                    'orden' => 99,
                    'texto_publico' => 'Duplicado ignorado',
                ],
                [
                    'id_producto' => 'QAVCARDP3',
                    'activo' => 1,
                    'destacado' => 0,
                    'orden' => 30,
                    'texto_publico' => 'No debe verse',
                ],
            ]);
            $service->sincronizarProductos($inactiveUserId, [
                ['id_producto' => 'QAVCARDP1', 'activo' => 1, 'orden' => 1],
            ]);

            $privacyFalse = $controller->show(
                $this->request('/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $privacy->actualizarPrivacidad((int) $vcard['id'], [
                'productos' => true,
            ]);
            $public = $controller->show(
                $this->request('/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $publicBody = $public->body();
            $publicProducts = $service->listarPublicosPorSlug(self::SLUG);
            $this->vcardService()->despublicar($userId);
            $unpublished = $controller->show(
                $this->request('/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $this->vcardService()->publicar($userId);
            $this->deactivateUser($pdo, $inactiveUserId);
            $this->forcePublish($pdo, (int) $inactiveVcard['id'], self::INACTIVE_SLUG);
            $inactive = $controller->show(
                $this->request('/v/' . self::INACTIVE_SLUG),
                ['slug' => self::INACTIVE_SLUG]
            );
            $qr = $controller->qr(
                $this->request('/v/' . self::SLUG . '/qr'),
                ['slug' => self::SLUG]
            );
            $vcf = $controller->vcf(
                $this->request('/v/' . self::SLUG . '/vcf'),
                ['slug' => self::SLUG]
            );
            $photo = $controller->photo(
                $this->request('/v/' . self::SLUG . '/foto'),
                ['slug' => self::SLUG]
            );

            $results['service'] = [
                'links_existing_product' => count($service->listarPrivados($userId)) === 3,
                'missing_product_rejected' => $missingRejected,
                'duplicates_avoided' => $this->countLinked($pdo, (int) $vcard['id'], 'QAVCARDP1') === 1,
                'ordered_products' =>
                    ($publicProducts[0]['id_producto'] ?? null) === 'QAVCARDP1'
                    && ($publicProducts[1]['id_producto'] ?? null) === 'QAVCARDP2',
                'inactive_product_hidden' =>
                    count($publicProducts) === 2
                    && !in_array('QAVCARDP3', array_column($publicProducts, 'id_producto'), true),
            ];

            $results['privacy'] = [
                'products_false_hides_section' =>
                    $privacyFalse->status() === 200
                    && !str_contains($privacyFalse->body(), 'Producto Público A')
                    && !str_contains($privacyFalse->body(), 'Producto Público B')
                    && !str_contains($privacyFalse->body(), 'Texto público A')
                    && !str_contains($privacyFalse->body(), 'Texto público B'),
                'products_true_shows_public_products' =>
                    $public->status() === 200
                    && str_contains($publicBody, 'Productos')
                    && str_contains($publicBody, 'Producto Público A')
                    && str_contains($publicBody, 'Producto Público B')
                    && str_contains($publicBody, 'Texto público A')
                    && str_contains($publicBody, 'Destacado'),
                'unpublished_hides_all' =>
                    $unpublished->status() === 404
                    && !str_contains($unpublished->body(), 'Producto Público A'),
                'inactive_user_hides_all' =>
                    $inactive->status() === 404
                    && !str_contains($inactive->body(), 'Producto Público A'),
            ];

            $results['leakage'] = [
                'no_price_min_cost_stock' =>
                    !$this->containsAny($publicBody, [
                        'precio',
                        'precio_minimo',
                        'costo',
                        'stock',
                        'existencia',
                        'almacén',
                        'proveedor',
                        'auditoría',
                    ]),
                'no_internal_ids_or_sensitive' =>
                    !str_contains($publicBody, 'vcard_id')
                    && !str_contains($publicBody, 'usuario_id')
                    && !str_contains($publicBody, 'password_hash')
                    && !str_contains($publicBody, self::PASSWORD)
                    && !str_contains($publicBody, 'roles')
                    && !str_contains($publicBody, 'permisos')
                    && !str_contains($publicBody, 'token'),
                'vcf_does_not_include_products' =>
                    $vcf->status() === 200
                    && !str_contains($vcf->body(), 'Producto Público A')
                    && !str_contains($vcf->body(), 'QAVCARDP1'),
            ];

            $results['regression_surface'] = [
                'qr_still_works' => $qr->status() === 200
                    && str_starts_with($qr->body(), "\x89PNG\r\n\x1A\n"),
                'vcf_still_works' => $vcf->status() === 200
                    && str_contains($vcf->body(), 'BEGIN:VCARD'),
                'photo_still_controlled' => $photo->status() === 404
                    && $photo->body() === '',
                'no_product_public_route' =>
                    !$this->fileContains('routes/web.php', '/v/{slug}/productos'),
                'no_credential_verify_route' =>
                    !$this->fileContains('routes/web.php', '/credencial/verificar'),
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
                'VCARD-PRODUCTOS-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'public_fields' => [
                'id_producto',
                'descripcion',
                'texto_publico',
                'destacado',
                'unidad',
                'marca',
                'linea',
                'clasificacion',
            ],
            'forbidden_fields' => [
                'precio',
                'precio_minimo',
                'costo',
                'stock',
                'existencia',
                'almacen',
                'proveedor',
                'auditoria',
            ],
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function service(): VcardProductService
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

    private function controller(): PublicVcardController
    {
        return new PublicVcardController(
            $this->config(),
            $this->vcardService(),
            new VcardVcfService(),
            new VcardQrService(),
            $this->service()
        );
    }

    private function connection(): ConnectionProvider
    {
        return $GLOBALS['vcard_productos_connection'];
    }

    private function config(): Config
    {
        return $GLOBALS['vcard_productos_config'];
    }

    private function request(string $path): Request
    {
        return new Request('GET', $path, [], [], ['host' => 'productos.example.test']);
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
            'titulo_publico' => 'Contacto productos QA',
            'descripcion_publica' => 'Descripción pública productos QA',
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
        $statement = $pdo->prepare(
            'INSERT INTO perfiles_usuario (
                usuario_id,
                primer_nombre,
                apellido_paterno,
                puesto,
                telefono_movil,
                sitio_web,
                ubicacion_publica
             ) VALUES (
                :usuario_id,
                \'QA\',
                \'Productos\',
                \'Ventas públicas\',
                \'5555553333\',
                \'https://example.test/productos\',
                \'Monterrey\'
             )'
        );
        $statement->execute(['usuario_id' => $userId]);
    }

    private function insertProduct(PDO $pdo, string $id, string $description, int $unitId, int $active): void
    {
        $statement = $pdo->prepare(
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
        );
        $statement->execute([
            'id_producto' => $id,
            'descripcion' => $description,
            'descripcion_larga' => 'Descripción larga QA no pública',
            'unidad_medida_id' => $unitId,
            'activo' => $active,
        ]);
    }

    private function unitId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            'SELECT id
             FROM unidades_medida
             WHERE activo = 1
               AND eliminado_en IS NULL
             ORDER BY id
             LIMIT 1'
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('No active unit found.');
        }

        return $id;
    }

    private function countLinked(PDO $pdo, int $vcardId, string $productId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM vcard_productos
             WHERE vcard_id = :vcard_id
               AND id_producto = :id_producto
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'vcard_id' => $vcardId,
            'id_producto' => $productId,
        ]);

        return (int) $statement->fetchColumn();
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
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qa_vcard_productos%'"),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_vcard_productos%'"
            ),
            'productos_qa' => $this->countWhere($pdo, 'productos', "id_producto LIKE 'QAVCARDP%'"),
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_productos%'"
            ),
            'vcard_productos_qa' => $this->countWhere(
                $pdo,
                'vcard_productos vp',
                "vp.id_producto LIKE 'QAVCARDP%'"
            ),
        ];
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
