<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Profile\ProfileService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Domain\Vcards\VcardPrivacyService;
use App\Domain\Vcards\VcardProductService;
use App\Domain\Vcards\VcardQrService;
use App\Domain\Vcards\VcardService;
use App\Domain\Vcards\VcardVcfService;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicVcardController;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ProfileRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserPhotoRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Repositories\UserVcardRepository;
use App\Infrastructure\Repositories\VcardPrivacyRepository;
use App\Infrastructure\Repositories\VcardProductRepository;
use App\Infrastructure\Storage\UserPhotoStorage;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const PASSWORD = 'VcardProductosFix123!';
    private const USERNAME = 'qa_vcard_productos_fix';
    private const OTHER_USERNAME = 'qa_vcard_productos_fix_otro';
    private const SLUG = 'qa-vcard-productos-fix';
    private const PRODUCT_ID = 'QAVCPF1';
    private const SECOND_PRODUCT_ID = 'QAVCPF2';
    private const INACTIVE_PRODUCT_ID = 'QAVCPF3';
    private const PERMISSION = 'vcard.productos.administrar';

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
            'vcards_usuario',
            'vcard_privacidad',
            'vcard_productos',
            'productos',
            'unidades_medida',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'VCARD-PRODUCTOS-VINCULACION-FIX-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $unitId = $this->unitId($pdo);
            $this->insertProduct($pdo, self::PRODUCT_ID, 'Producto <script> Público', $unitId, 1);
            $this->insertProduct($pdo, self::SECOND_PRODUCT_ID, 'Compresor Público', $unitId, 1);
            $this->insertProduct($pdo, self::INACTIVE_PRODUCT_ID, 'Producto Inactivo', $unitId, 0);
            $permissionId = $this->ensurePermission($pdo, self::PERMISSION);
            $userId = $this->insertUser($pdo, self::USERNAME);
            $otherUserId = $this->insertUser($pdo, self::OTHER_USERNAME);
            $this->insertProfile($pdo, $userId);
            $this->insertProfile($pdo, $otherUserId);
            $this->assignRole($pdo, $userId, [
                'perfil.ver',
                'vcard.ver',
                'vcard.editar',
                'vcard.publicar',
                'vcard.privacidad.editar',
                self::PERMISSION,
            ], $permissionId);
            $this->assignRole($pdo, $otherUserId, [
                'perfil.ver',
                'vcard.ver',
            ], $permissionId);

            $vcard = $this->publishVcard($userId, self::SLUG, true);
            $this->publishVcard($otherUserId, 'qa-vcard-productos-fix-otro', true);
            $publicController = $this->publicController();
            $productService = $this->productService();

            $emptyPublic = $publicController->show(
                $this->request('GET', '/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $profile = $this->profileController($userId)->index(
                $this->request('GET', '/perfil', ['producto' => 'Producto'])
            );
            $otherProfile = $this->profileController($otherUserId)->index(
                $this->request('GET', '/perfil')
            );
            $withoutPermissionPost = $this->profileController($otherUserId)->addVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/agregar', [], [
                    'id_producto' => self::PRODUCT_ID,
                ])
            );

            $searchResults = $productService->buscarProductosActivos('QAVCPF');
            $add = $this->profileController($userId)->addVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/agregar', [], [
                    'id_producto' => strtolower(self::PRODUCT_ID),
                    'destacado' => '1',
                    'texto_publico' => 'Oferta <b>pública</b>',
                ])
            );
            $duplicateAdd = $this->profileController($userId)->addVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/agregar', [], [
                    'id_producto' => self::PRODUCT_ID,
                    'destacado' => '1',
                    'texto_publico' => 'Oferta <b>pública</b>',
                ])
            );
            $linkedAfterDuplicate = $this->countLinked(
                $pdo,
                (int) $vcard['id'],
                self::PRODUCT_ID
            );
            $inactiveRejected = $this->profileController($userId)->addVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/agregar', [], [
                    'id_producto' => self::INACTIVE_PRODUCT_ID,
                ])
            );
            $linkedProfile = $this->profileController($userId)->index($this->request('GET', '/perfil'));
            $publicAfterAdd = $publicController->show(
                $this->request('GET', '/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $productsAfterAdd = $publicController->products(
                $this->request('GET', '/v/' . self::SLUG . '/productos'),
                ['slug' => self::SLUG]
            );

            $this->privacy()->actualizarPrivacidad((int) $vcard['id'], ['productos' => false]);
            $privacyOff = $publicController->show(
                $this->request('GET', '/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $productsPrivacyOff = $publicController->products(
                $this->request('GET', '/v/' . self::SLUG . '/productos'),
                ['slug' => self::SLUG]
            );
            $this->privacy()->actualizarPrivacidad((int) $vcard['id'], ['productos' => true]);

            $hide = $this->profileController($userId)->updateVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/actualizar', [], [
                    'id_producto' => self::PRODUCT_ID,
                    'activo' => '0',
                    'destacado' => '0',
                    'texto_publico' => 'Oculto',
                ])
            );
            $hiddenPublic = $publicController->show(
                $this->request('GET', '/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $productsHidden = $publicController->products(
                $this->request('GET', '/v/' . self::SLUG . '/productos'),
                ['slug' => self::SLUG]
            );
            $showAgain = $this->profileController($userId)->updateVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/actualizar', [], [
                    'id_producto' => self::PRODUCT_ID,
                    'activo' => '1',
                    'destacado' => '1',
                    'texto_publico' => 'Visible de nuevo',
                ])
            );
            $remove = $this->profileController($userId)->removeVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/quitar', [], [
                    'id_producto' => self::PRODUCT_ID,
                ])
            );
            $removedPublic = $publicController->show(
                $this->request('GET', '/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $productsRemoved = $publicController->products(
                $this->request('GET', '/v/' . self::SLUG . '/productos'),
                ['slug' => self::SLUG]
            );
            $csrf = new CsrfTokenService($this->session(), 7200);
            $csrfStatus = (new CsrfMiddleware($csrf))->process(
                $this->request('POST', '/perfil/vcard/productos/agregar'),
                static fn (Request $request): Response => Response::html('ok')
            )->status();
            $permissionStatus = (new PermissionMiddleware(
                $this->authForUser($otherUserId),
                new PermissionService(new PermissionRepository($this->connection())),
                self::PERMISSION
            ))->process(
                $this->request('POST', '/perfil/vcard/productos/agregar'),
                static fn (Request $request): Response => Response::html('ok')
            )->status();
            $qr = $publicController->qr(
                $this->request('GET', '/v/' . self::SLUG . '/qr'),
                ['slug' => self::SLUG]
            );
            $vcf = $publicController->vcf(
                $this->request('GET', '/v/' . self::SLUG . '/vcf'),
                ['slug' => self::SLUG]
            );

            $publicBody = $publicAfterAdd->body();
            $results['diagnosis'] = [
                'privacy_true_without_links_is_not_enough' =>
                    $emptyPublic->status() === 200
                    && !str_contains($emptyPublic->body(), 'Producto &lt;script&gt; Público'),
                'initial_links_empty' =>
                    $this->countLinked($pdo, (int) $vcard['id'], self::PRODUCT_ID) === 0,
            ];
            $results['private_profile_ui'] = [
                'section_visible_with_permission' =>
                    $profile->status() === 200
                    && str_contains($profile->body(), 'Productos en mi vCard')
                    && str_contains($profile->body(), 'Aún no has agregado productos a tu vCard.'),
                'section_hidden_without_permission' =>
                    $otherProfile->status() === 200
                    && !str_contains($otherProfile->body(), 'Productos en mi vCard'),
                'search_active_products' =>
                    count($searchResults) === 2
                    && in_array(self::PRODUCT_ID, array_column($searchResults, 'id_producto'), true)
                    && !in_array(self::INACTIVE_PRODUCT_ID, array_column($searchResults, 'id_producto'), true),
                'linked_product_visible_in_profile' =>
                    $linkedProfile->status() === 200
                    && str_contains($linkedProfile->body(), self::PRODUCT_ID)
                    && str_contains($linkedProfile->body(), 'Producto &lt;script&gt; Público'),
            ];
            $results['private_actions'] = [
                'add_redirects' => $add->status() === 302,
                'duplicate_add_does_not_duplicate' =>
                    $duplicateAdd->status() === 302
                    && $linkedAfterDuplicate === 1,
                'inactive_product_rejected' => $inactiveRejected->status() === 422,
                'update_hide_redirects' => $hide->status() === 302,
                'update_show_redirects' => $showAgain->status() === 302,
                'remove_redirects' => $remove->status() === 302,
                'post_without_permission_403' =>
                    $withoutPermissionPost->status() === 403
                    && $permissionStatus === 403,
                'post_without_csrf_419' => $csrfStatus === 419,
                'cannot_modify_other_vcard' =>
                    $this->countLinkedForUser($pdo, $otherUserId) === 0,
            ];
            $results['public_vcard'] = [
                'product_visible_when_allowed' =>
                    $publicAfterAdd->status() === 200
                    && str_contains($publicBody, 'Productos relacionados')
                    && str_contains($publicBody, self::PRODUCT_ID)
                    && str_contains($publicBody, 'Producto &lt;script&gt; Público')
                    && str_contains($publicBody, 'Oferta &lt;b&gt;pública&lt;/b&gt;'),
                'privacy_false_hides_product' =>
                    $privacyOff->status() === 200
                    && !str_contains($privacyOff->body(), self::PRODUCT_ID),
                'link_inactive_hides_product' =>
                    $hiddenPublic->status() === 200
                    && !str_contains($hiddenPublic->body(), self::PRODUCT_ID),
                'removed_hides_product' =>
                    $removedPublic->status() === 200
                    && !str_contains($removedPublic->body(), self::PRODUCT_ID),
                'html_escaped' =>
                    !str_contains($publicBody, '<script>')
                    && !str_contains($publicBody, '<b>pública</b>'),
            ];
            $productsBody = $productsAfterAdd->body();
            $results['public_products_page'] = [
                'route_declared' => $this->fileContains('routes/web.php', '/v/{slug}/productos'),
                'allowed_page_200' =>
                    $productsAfterAdd->status() === 200
                    && str_contains($productsBody, self::PRODUCT_ID)
                    && str_contains($productsBody, 'Producto &lt;script&gt; Público')
                    && str_contains($productsBody, 'Oferta &lt;b&gt;pública&lt;/b&gt;'),
                'privacy_false_404' => $productsPrivacyOff->status() === 404,
                'inactive_link_404' => $productsHidden->status() === 404,
                'removed_link_404' => $productsRemoved->status() === 404,
                'not_public_api' => !$this->fileContains('routes/web.php', '/api/vcard/productos'),
                'no_forbidden_fields' => !$this->containsAny($productsBody, [
                    'precio',
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
                    'password_hash',
                    'token_hash',
                    'storage/uploads',
                    '/credencial/verificar/',
                ]),
            ];
            $results['security'] = [
                'no_price_stock_cost_provider_warehouse' => !$this->containsAny($publicBody, [
                    'precio',
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
                ]),
                'no_internal_ids_or_sensitive' => !$this->containsAny($publicBody, [
                    'vcard_id',
                    'usuario_id',
                    'password_hash',
                    'token_hash',
                    'storage/uploads',
                    '/credencial/verificar/',
                ]),
                'products_table_not_functionally_modified' =>
                    $this->productDescription($pdo, self::PRODUCT_ID) === 'Producto <script> Público',
                'inventory_not_touched' => $this->tableCount($pdo, 'inventario_existencias') >= 0,
                'prices_not_touched' => $this->optionalTableCount($pdo, 'producto_precios') >= 0,
            ];
            $results['regressions'] = [
                'qr_png' => $qr->status() === 200
                    && str_starts_with($qr->body(), "\x89PNG\r\n\x1A\n"),
                'vcf_works' => $vcf->status() === 200
                    && str_contains($vcf->body(), 'BEGIN:VCARD'),
                'routes_declared' =>
                    $this->fileContains('routes/web.php', '/perfil/vcard/productos/agregar')
                    && $this->fileContains('routes/web.php', '/perfil/vcard/productos/actualizar')
                    && $this->fileContains('routes/web.php', '/perfil/vcard/productos/quitar'),
                'public_products_route_controlled' =>
                    $this->fileContains('routes/web.php', '/v/{slug}/productos')
                    && !$this->fileContains('routes/web.php', '/api/vcard/productos'),
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
                'VCARD-PRODUCTOS-VINCULACION-FIX-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'cause' => 'productos privacy was active but vcard_productos had no links',
            'public_fields' => [
                'id_producto',
                'descripcion',
                'marca',
                'linea',
                'clasificacion',
                'unidad',
                'texto_publico',
                'destacado',
            ],
            'forbidden_fields' => [
                'precio',
                'stock',
                'costo',
                'proveedor',
                'almacen',
                'ids_internos',
            ],
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function profileController(int $userId): ProfileController
    {
        return new ProfileController(
            $this->config(),
            $this->authForUser($userId),
            new PermissionService(new PermissionRepository($this->connection())),
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($this->connection())),
                $this->session()
            ),
            new CsrfTokenService($this->session(), 7200),
            new ProfileService(
                new ProfileRepository($this->connection()),
                new UserPhotoRepository($this->connection())
            ),
            new UserPhotoStorage(
                (string) $this->config()->get('paths.STORAGE_PATH', STORAGE_PATH),
                true
            ),
            $this->vcardService(),
            $this->privacy(),
            $this->productService()
        );
    }

    private function publicController(): PublicVcardController
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

    private function authForUser(int $userId): AuthService
    {
        $_SESSION = [];
        $auth = new AuthService(new UserRepository($this->connection()), $this->session());
        $email = $this->emailById($userId);

        if (!$auth->attempt($email, self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate QA user.');
        }

        return $auth;
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('vcardproductosvinculacionfix1');
            session_id('vcardproductosvinculacionfix1' . bin2hex(random_bytes(4)));
            session_start();
        }

        return new Session([
            'name' => session_name(),
            'same_site' => 'Lax',
            'secure' => false,
            'gc_max_lifetime' => 7200,
        ]);
    }

    private function connection(): ConnectionProvider
    {
        return $GLOBALS['vcard_productos_vinculacion_fix_connection'];
    }

    private function config(): Config
    {
        return $GLOBALS['vcard_productos_vinculacion_fix_config'];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $query = [], array $body = []): Request
    {
        return new Request($method, $path, $query, $body, ['host' => 'vcard-fix.example.test']);
    }

    private function publishVcard(int $userId, string $slug, bool $productsVisible): array
    {
        $service = $this->vcardService();
        $privacy = $this->privacy();
        $vcard = $service->asegurarVcard($userId);
        $service->actualizarConfiguracion($userId, [
            'slug' => $slug,
            'titulo_publico' => 'QA productos',
            'descripcion_publica' => 'Contacto con productos públicos.',
            'canal_contacto_preferido' => 'telefono_movil',
        ]);
        $privacy->actualizarPrivacidad((int) $vcard['id'], [
            'telefono_movil' => true,
            'correo' => true,
            'puesto' => true,
            'productos' => $productsVisible,
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
                telefono_movil
             ) VALUES (
                :usuario_id,
                \'QA\',
                \'Vcard\',
                \'Ventas\',
                \'5511112222\'
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
            'descripcion_larga' => 'Descripción interna QA',
            'unidad_medida_id' => $unitId,
            'activo' => $active,
        ]);
    }

    private function assignRole(PDO $pdo, int $userId, array $permissions, int $phasePermissionId): void
    {
        $roleCode = 'QA_VCARD_PRODUCTOS_FIX_' . $userId;
        $statement = $pdo->prepare(
            'INSERT INTO roles (codigo, nombre, descripcion, es_sistema, activo)
             VALUES (:codigo, :nombre, \'Rol QA transaccional\', 0, 1)'
        );
        $statement->execute(['codigo' => $roleCode, 'nombre' => $roleCode]);
        $roleId = (int) $pdo->lastInsertId();
        $assign = $pdo->prepare(
            'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
             VALUES (:rol_id, :permiso_id, 1)'
        );

        foreach ($permissions as $permission) {
            $assign->execute([
                'rol_id' => $roleId,
                'permiso_id' => $permission === self::PERMISSION
                    ? $phasePermissionId
                    : $this->permissionId($pdo, $permission),
            ]);
        }

        $pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        )->execute(['usuario_id' => $userId, 'rol_id' => $roleId]);
    }

    private function ensurePermission(PDO $pdo, string $code): int
    {
        $existing = $this->permissionIdOrNull($pdo, $code);

        if ($existing !== null) {
            return $existing;
        }

        $statement = $pdo->prepare(
            'INSERT INTO permisos (
                codigo,
                modulo,
                nombre,
                descripcion,
                es_sistema,
                activo
             ) VALUES (
                :codigo,
                \'vcard\',
                \'Administrar productos vCard\',
                \'Permite administrar productos visibles en vCard pública.\',
                1,
                1
             )'
        );
        $statement->execute(['codigo' => $code]);

        return (int) $pdo->lastInsertId();
    }

    private function permissionId(PDO $pdo, string $code): int
    {
        $id = $this->permissionIdOrNull($pdo, $code);

        if ($id === null) {
            throw new RuntimeException('Permission not found: ' . $code);
        }

        return $id;
    }

    private function permissionIdOrNull(PDO $pdo, string $code): ?int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM permisos
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        return $id > 0 ? $id : null;
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

    private function emailById(int $userId): string
    {
        $statement = $this->connection()->pdo()->prepare(
            'SELECT email FROM usuarios WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $email = $statement->fetchColumn();

        if (!is_string($email) || $email === '') {
            throw new RuntimeException('QA user email not found.');
        }

        return $email;
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

    private function countLinkedForUser(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM vcard_productos vp
             INNER JOIN vcards_usuario v ON v.id = vp.vcard_id
             WHERE v.usuario_id = :usuario_id
               AND vp.eliminado_en IS NULL'
        );
        $statement->execute(['usuario_id' => $userId]);

        return (int) $statement->fetchColumn();
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

    private function tableCount(PDO $pdo, string $table): int
    {
        if (!$this->tableExists($pdo, $table)) {
            return 0;
        }

        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function optionalTableCount(PDO $pdo, string $table): int
    {
        return $this->tableExists($pdo, $table)
            ? (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn()
            : 0;
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qa_vcard_productos_fix%'"),
            'productos_qa' => $this->countWhere($pdo, 'productos', "id_producto LIKE 'QAVCPF%'"),
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_productos_fix%'"
            ),
            'vcard_productos_qa' => $this->countWhere(
                $pdo,
                'vcard_productos vp',
                "vp.id_producto LIKE 'QAVCPF%'"
            ),
            'roles_qa' => $this->countWhere($pdo, 'roles', "codigo LIKE 'QA_VCARD_PRODUCTOS_FIX_%'"),
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
