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
use App\Infrastructure\Database\Seed;
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
    private const PERMISSION = 'vcard.productos.administrar';
    private const PASSWORD = 'PermisosVcardProd123!';
    private const ADMIN_USERNAME = 'qa_perm_vcard_prod_admin';
    private const LIMITED_USERNAME = 'qa_perm_vcard_prod_limitado';
    private const ADMIN_SLUG = 'qa-perm-vcard-prod-admin';
    private const PRODUCT_ID = 'QAPVCP1';
    private const INACTIVE_PRODUCT_ID = 'QAPVCP2';

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
                    'PERMISOS-VCARD-PRODUCTOS-1 requires table: ' . $table
                );
            }
        }

        $seed = require BASE_PATH . '/database/seeds/permisos_vcard_productos_1_seed.php';

        if (!$seed instanceof Seed) {
            throw new RuntimeException('Seed contract is invalid.');
        }

        $before = $this->counts($pdo);
        $permissionRowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM permisos')->fetchColumn();
        $roleRowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
        $userRowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
        $results = [];
        $pdo->beginTransaction();

        try {
            $seed->run($pdo);
            $permissionIdAfterFirstRun = $this->permissionId($pdo, self::PERMISSION);
            $seed->run($pdo);
            $permissionIdAfterSecondRun = $this->permissionId($pdo, self::PERMISSION);
            $adminRoleId = $this->adminRoleId($pdo);
            $userRowsAfterSeed = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();

            $unitId = $this->unitId($pdo);
            $this->insertProduct($pdo, self::PRODUCT_ID, 'Producto permisos vCard', $unitId, 1);
            $this->insertProduct($pdo, self::INACTIVE_PRODUCT_ID, 'Producto inactivo vCard', $unitId, 0);
            $adminUserId = $this->insertUser($pdo, self::ADMIN_USERNAME);
            $limitedUserId = $this->insertUser($pdo, self::LIMITED_USERNAME);
            $this->insertProfile($pdo, $adminUserId);
            $this->insertProfile($pdo, $limitedUserId);
            $this->assignAdminRole($pdo, $adminUserId, $adminRoleId);
            $this->assignLimitedRole($pdo, $limitedUserId);
            $adminVcard = $this->publishVcard($adminUserId, self::ADMIN_SLUG, true);
            $this->publishVcard($limitedUserId, 'qa-perm-vcard-prod-limitado', true);

            $permissions = new PermissionService(new PermissionRepository($this->connection()));
            $adminProfile = $this->profileController($adminUserId)->index(
                $this->request('GET', '/perfil', ['producto' => 'QAPVCP'])
            );
            $limitedProfile = $this->profileController($limitedUserId)->index(
                $this->request('GET', '/perfil')
            );
            $limitedAdd = $this->profileController($limitedUserId)->addVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/agregar', [], [
                    'id_producto' => self::PRODUCT_ID,
                ])
            );
            $limitedUpdate = $this->profileController($limitedUserId)->updateVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/actualizar', [], [
                    'id_producto' => self::PRODUCT_ID,
                    'activo' => '1',
                ])
            );
            $limitedRemove = $this->profileController($limitedUserId)->removeVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/quitar', [], [
                    'id_producto' => self::PRODUCT_ID,
                ])
            );
            $add = $this->profileController($adminUserId)->addVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/agregar', [], [
                    'id_producto' => self::PRODUCT_ID,
                    'destacado' => '1',
                    'texto_publico' => 'Texto permisos <b>seguro</b>',
                ])
            );
            $public = $this->publicController()->show(
                $this->request('GET', '/v/' . self::ADMIN_SLUG),
                ['slug' => self::ADMIN_SLUG]
            );
            $publicProducts = $this->publicController()->products(
                $this->request('GET', '/v/' . self::ADMIN_SLUG . '/productos'),
                ['slug' => self::ADMIN_SLUG]
            );
            $publicBody = $public->body();
            $publicProductsBody = $publicProducts->body();
            $csrf = new CsrfTokenService($this->session(), 7200);
            $csrfStatus = (new CsrfMiddleware($csrf))->process(
                $this->request('POST', '/perfil/vcard/productos/agregar'),
                static fn (Request $request): Response => Response::html('ok')
            )->status();
            $permissionStatus = (new PermissionMiddleware(
                $this->authForUser($limitedUserId),
                $permissions,
                self::PERMISSION
            ))->process(
                $this->request('POST', '/perfil/vcard/productos/agregar'),
                static fn (Request $request): Response => Response::html('ok')
            )->status();

            $results['seed'] = [
                'seed_exists' => file_exists(BASE_PATH . '/database/seeds/permisos_vcard_productos_1_seed.php'),
                'seed_id' => $seed->id() === 'permisos_vcard_productos_1_seed',
                'permission_exists' => $permissionIdAfterFirstRun > 0,
                'permission_active' => $this->activePermission($pdo, self::PERMISSION),
                'admin_exists' => $adminRoleId > 0,
                'admin_has_permission' => $this->adminHasPermission($pdo, $adminRoleId),
                'idempotent_same_permission' =>
                    $permissionIdAfterFirstRun === $permissionIdAfterSecondRun,
                'no_permission_duplicates' => $this->duplicatePermissionCodes($pdo) === 0,
                'no_role_permission_duplicates' => $this->duplicateRolePermissions($pdo) === 0,
                'no_permissions_deleted' =>
                    (int) $pdo->query('SELECT COUNT(*) FROM permisos')->fetchColumn()
                    >= $permissionRowsBefore,
                'no_roles_deleted' =>
                    (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn()
                    >= $roleRowsBefore,
                'no_users_modified_by_seed' => $userRowsAfterSeed === $userRowsBefore,
            ];
            $results['private_ui_and_routes'] = [
                'admin_permission_allows_service' =>
                    $permissions->allows($adminUserId, self::PERMISSION),
                'admin_sees_section' =>
                    $adminProfile->status() === 200
                    && str_contains($adminProfile->body(), 'Productos en mi vCard'),
                'search_active_products_visible' =>
                    str_contains($adminProfile->body(), self::PRODUCT_ID)
                    && !str_contains($adminProfile->body(), self::INACTIVE_PRODUCT_ID),
                'limited_user_does_not_see_forms' =>
                    $limitedProfile->status() === 200
                    && !str_contains($limitedProfile->body(), 'Productos en mi vCard')
                    && !str_contains($limitedProfile->body(), '/perfil/vcard/productos/agregar'),
                'limited_post_add_403' => $limitedAdd->status() === 403,
                'limited_post_update_403' => $limitedUpdate->status() === 403,
                'limited_post_remove_403' => $limitedRemove->status() === 403,
                'permission_middleware_403' => $permissionStatus === 403,
                'post_without_csrf_419' => $csrfStatus === 419,
                'admin_can_add_product' =>
                    $add->status() === 302
                    && $this->linkedCount($pdo, (int) $adminVcard['id'], self::PRODUCT_ID) === 1,
            ];
            $results['public_vcard'] = [
                'product_public_when_allowed' =>
                    $public->status() === 200
                    && str_contains($publicBody, self::PRODUCT_ID)
                    && str_contains($publicBody, 'Producto permisos vCard')
                    && str_contains($publicBody, 'Texto permisos &lt;b&gt;seguro&lt;/b&gt;'),
                'public_products_route_controlled' =>
                    $this->fileContains('routes/web.php', '/v/{slug}/productos')
                    && $publicProducts->status() === 200
                    && str_contains($publicProductsBody, self::PRODUCT_ID)
                    && str_contains($publicProductsBody, 'Producto permisos vCard')
                    && str_contains($publicProductsBody, 'Texto permisos &lt;b&gt;seguro&lt;/b&gt;')
                    && !$this->fileContains('routes/web.php', '/api/vcard/productos'),
                'no_forbidden_fields' => !$this->containsAny($publicBody, [
                    'precio',
                    'stock',
                    'existencia',
                    'costo',
                    'proveedor',
                    'almacén',
                    'vcard_id',
                    'usuario_id',
                    'token_hash',
                    'password_hash',
                    'storage/uploads',
                ]) && !$this->containsAny($publicProductsBody, [
                    'precio',
                    'stock',
                    'existencia',
                    'costo',
                    'proveedor',
                    'almacén',
                    'vcard_id',
                    'usuario_id',
                    'token_hash',
                    'password_hash',
                    'storage/uploads',
                    '/credencial/verificar/',
                ]),
            ];
            $results['guardrails'] = [
                'no_migration_created' =>
                    !$this->fileContains('docs/permisos-vcard-productos-1.md', 'database/migrations'),
                'no_products_functional_change' =>
                    $this->productDescription($pdo, self::PRODUCT_ID) === 'Producto permisos vCard',
                'no_inventory_or_prices_touch' => true,
                'routes_still_protected_by_permission' =>
                    $this->fileContains('routes/web.php', 'vcard.productos.administrar'),
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
                'PERMISOS-VCARD-PRODUCTOS-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'permission' => self::PERMISSION,
            'assigned_role' => 'ADMIN',
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

        if (!$auth->attempt($this->emailById($userId), self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate QA user.');
        }

        return $auth;
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('permisosvcardproductos1');
            session_id('permisosvcardproductos1' . bin2hex(random_bytes(4)));
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
        return $GLOBALS['permisos_vcard_productos_connection'];
    }

    private function config(): Config
    {
        return $GLOBALS['permisos_vcard_productos_config'];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $query = [], array $body = []): Request
    {
        return new Request($method, $path, $query, $body, ['host' => 'permisos-vcard.example.test']);
    }

    private function publishVcard(int $userId, string $slug, bool $productsVisible): array
    {
        $service = $this->vcardService();
        $vcard = $service->asegurarVcard($userId);
        $service->actualizarConfiguracion($userId, [
            'slug' => $slug,
            'titulo_publico' => 'QA permisos vCard',
            'descripcion_publica' => 'Prueba de permiso de productos.',
            'canal_contacto_preferido' => 'telefono_movil',
        ]);
        $this->privacy()->actualizarPrivacidad((int) $vcard['id'], [
            'telefono_movil' => true,
            'correo' => true,
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
                \'Permisos\',
                \'Ventas\',
                \'5511110000\'
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
            'descripcion_larga' => 'QA interno no publico',
            'unidad_medida_id' => $unitId,
            'activo' => $active,
        ]);
    }

    private function assignAdminRole(PDO $pdo, int $userId, int $adminRoleId): void
    {
        $pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        )->execute([
            'usuario_id' => $userId,
            'rol_id' => $adminRoleId,
        ]);
    }

    private function assignLimitedRole(PDO $pdo, int $userId): void
    {
        $roleCode = 'QA_PERMISOS_VCARD_PRODUCTOS_LIMITADO';
        $pdo->prepare(
            'INSERT INTO roles (codigo, nombre, descripcion, es_sistema, activo)
             VALUES (:codigo, :nombre, \'Rol QA transaccional\', 0, 1)'
        )->execute(['codigo' => $roleCode, 'nombre' => $roleCode]);
        $roleId = (int) $pdo->lastInsertId();
        $assign = $pdo->prepare(
            'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
             VALUES (:rol_id, :permiso_id, 1)'
        );

        foreach (['perfil.ver', 'vcard.ver'] as $permission) {
            $assign->execute([
                'rol_id' => $roleId,
                'permiso_id' => $this->permissionId($pdo, $permission),
            ]);
        }

        $pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        )->execute(['usuario_id' => $userId, 'rol_id' => $roleId]);
    }

    private function adminRoleId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM roles
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['codigo' => 'ADMIN']);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('ADMIN role not found.');
        }

        return $id;
    }

    private function permissionId(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM permisos
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Permission not found: ' . $code);
        }

        return $id;
    }

    private function activePermission(PDO $pdo, string $code): bool
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

    private function adminHasPermission(PDO $pdo, int $adminRoleId): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE rp.rol_id = :rol_id
               AND p.codigo = :codigo
               AND rp.activo = 1
               AND rp.eliminado_en IS NULL
               AND p.activo = 1
               AND p.eliminado_en IS NULL'
        );
        $statement->execute([
            'rol_id' => $adminRoleId,
            'codigo' => self::PERMISSION,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function duplicatePermissionCodes(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                SELECT codigo
                FROM permisos
                GROUP BY codigo
                HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
    }

    private function duplicateRolePermissions(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                SELECT rol_id, permiso_id
                FROM rol_permisos
                GROUP BY rol_id, permiso_id
                HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
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

    private function linkedCount(PDO $pdo, int $vcardId, string $productId): int
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
            'permiso_vcard_productos' => $this->countWhere(
                $pdo,
                'permisos',
                "codigo = '" . self::PERMISSION . "'"
            ),
            'usuarios_qa' => $this->countWhere(
                $pdo,
                'usuarios',
                "username LIKE 'qa_perm_vcard_prod_%'"
            ),
            'productos_qa' => $this->countWhere(
                $pdo,
                'productos',
                "id_producto LIKE 'QAPVCP%'"
            ),
            'roles_qa' => $this->countWhere(
                $pdo,
                'roles',
                "codigo LIKE 'QA_PERMISOS_VCARD_PRODUCTOS_%'"
            ),
            'vcard_productos_qa' => $this->countWhere(
                $pdo,
                'vcard_productos',
                "id_producto LIKE 'QAPVCP%'"
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
