<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Domain\Vcards\VcardPrivacyService;
use App\Domain\Vcards\VcardProductService;
use App\Domain\Vcards\VcardQrService;
use App\Domain\Vcards\VcardService;
use App\Domain\Vcards\VcardVcfService;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\PublicVcardController;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\CredentialTokenRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserCredentialRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Repositories\UserVcardRepository;
use App\Infrastructure\Repositories\VcardPrivacyRepository;
use App\Infrastructure\Repositories\VcardProductRepository;
use App\Domain\Auth\AuthService;
use App\Domain\Credentials\CredentialQrService;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\CredentialTokenService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const PASSWORD = 'VcardPublicaUiProductosQa123!';
    private const USERNAME = 'qa_vcard_publica_ui_productos_1';
    private const INACTIVE_USERNAME = 'qa_vcard_publica_ui_productos_inactive';
    private const SLUG = 'qa-vcard-publica-ui-productos';
    private const INACTIVE_SLUG = 'qa-vcard-publica-ui-productos-inactive';

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
            'credenciales_usuario',
            'roles',
            'permisos',
            'usuario_roles',
            'rol_permisos',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('VCARD-PUBLICA-UI-PRODUCTOS-1 requires table: ' . $table);
            }
        }

        $before = $this->counts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $unitId = $this->unitId($pdo);
            $this->insertProduct($pdo, 'QAVPUBUI1', 'Refrigerante Público A', $unitId, 1);
            $this->insertProduct($pdo, 'QAVPUBUI2', 'Filtro Público B', $unitId, 1);
            $this->insertProduct($pdo, 'QAVPUBUI3', 'Producto Oculto', $unitId, 0);

            $userId = $this->insertUser($pdo, self::USERNAME, 1);
            $inactiveUserId = $this->insertUser($pdo, self::INACTIVE_USERNAME, 1);
            $this->insertProfile($pdo, $userId, true);
            $this->insertProfile($pdo, $inactiveUserId, false);
            $this->assignRoleWithPermissions($pdo, $userId, [
                'credencial.ver',
                'credencial.qr.ver',
                'credencial.qr.descargar',
            ]);
            $vcard = $this->publishVcard($userId, self::SLUG, true);
            $inactiveVcard = $this->publishVcard($inactiveUserId, self::INACTIVE_SLUG, true);

            $this->productService()->sincronizarProductos($userId, [
                [
                    'id_producto' => 'QAVPUBUI1',
                    'activo' => 1,
                    'destacado' => 1,
                    'orden' => 10,
                    'texto_publico' => 'Solución pública segura & <script>alert(1)</script>',
                ],
                [
                    'id_producto' => 'QAVPUBUI2',
                    'activo' => 1,
                    'destacado' => 0,
                    'orden' => 20,
                    'texto_publico' => 'Producto para catálogo público.',
                ],
                [
                    'id_producto' => 'QAVPUBUI3',
                    'activo' => 1,
                    'destacado' => 0,
                    'orden' => 30,
                    'texto_publico' => 'No debe aparecer.',
                ],
            ]);

            $controller = $this->controller();
            $public = $controller->show($this->request('/v/' . self::SLUG), ['slug' => self::SLUG]);
            $body = $public->body();

            $this->privacy()->actualizarPrivacidad((int) $vcard['id'], ['productos' => false]);
            $productsHidden = $controller->show(
                $this->request('/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $this->privacy()->actualizarPrivacidad((int) $vcard['id'], ['productos' => true]);

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

            $qr = $controller->qr($this->request('/v/' . self::SLUG . '/qr'), ['slug' => self::SLUG]);
            $vcf = $controller->vcf($this->request('/v/' . self::SLUG . '/vcf'), ['slug' => self::SLUG]);
            $photo = $controller->photo($this->request('/v/' . self::SLUG . '/foto'), ['slug' => self::SLUG]);
            $credential = $this->credentialController($userId)->show(
                new Request('GET', '/perfil/credencial')
            );

            $results['public_vcard_ui'] = [
                'published_200' => $public->status() === 200,
                'layout_present' =>
                    str_contains($body, 'vcard-public__card')
                    && str_contains($body, 'vcard-public__brand')
                    && str_contains($body, 'vcard-public__content'),
                'corporate_content_present' =>
                    str_contains($body, 'Grupo Refrigerantes')
                    && str_contains($body, 'Sistemas de Refrigeración y Climatización')
                    && str_contains($body, 'Innovación • Eficiencia • Confianza'),
                'public_name_and_role_present' =>
                    str_contains($body, 'QA &lt;script&gt; Pública')
                    && str_contains($body, 'Ingeniería comercial'),
                'actions_present_when_data_visible' =>
                    str_contains($body, 'Llamar ahora')
                    && str_contains($body, 'Enviar correo')
                    && str_contains($body, 'Enviar WhatsApp')
                    && str_contains($body, 'Agregar a contactos')
                    && str_contains($body, 'QR público'),
                'vcf_and_qr_links_present' =>
                    str_contains($body, '/v/' . self::SLUG . '/vcf')
                    && str_contains($body, '/v/' . self::SLUG . '/qr'),
                'social_links_present_if_visible' =>
                    str_contains($body, 'Sitio web')
                    && str_contains($body, 'LinkedIn')
                    && str_contains($body, 'Mapa'),
            ];

            $results['products'] = [
                'shown_when_privacy_enabled' =>
                    str_contains($body, 'Productos relacionados')
                    && str_contains($body, 'QAVPUBUI1')
                    && str_contains($body, 'Refrigerante Público A')
                    && str_contains($body, 'Filtro Público B')
                    && str_contains($body, 'Destacado'),
                'hidden_when_privacy_disabled' =>
                    $productsHidden->status() === 200
                    && !str_contains($productsHidden->body(), 'Refrigerante Público A')
                    && !str_contains($productsHidden->body(), 'Filtro Público B'),
                'hidden_when_unpublished' =>
                    $unpublished->status() === 404
                    && !str_contains($unpublished->body(), 'Refrigerante Público A'),
                'hidden_when_user_inactive' =>
                    $inactive->status() === 404
                    && !str_contains($inactive->body(), 'Refrigerante Público A'),
                'inactive_product_hidden' => !str_contains($body, 'Producto Oculto'),
                'public_html_escaped' =>
                    str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;')
                    && !str_contains($body, '<script>alert(1)</script>'),
            ];

            $results['security'] = [
                'forbidden_fields_absent' => !$this->containsAny($body, [
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
                'no_raw_private_terms' =>
                    !str_contains($body, self::PASSWORD)
                    && !str_contains($body, 'ruta_relativa')
                    && !str_contains($body, 'credencial_tokens'),
            ];

            $results['compatibility'] = [
                'qr_png' => $qr->status() === 200 && str_starts_with($qr->body(), "\x89PNG\r\n\x1A\n"),
                'vcf_works' => $vcf->status() === 200 && str_contains($vcf->body(), 'BEGIN:VCARD'),
                'photo_privacy_respected' => $photo->status() === 404 && $photo->body() === '',
                'credential_private_loads' => $credential->status() === 200,
                'credential_qr_points_to_public_vcard' =>
                    str_contains($credential->body(), 'QR hacia vCard pública')
                    && str_contains($credential->body(), '/v/' . self::SLUG),
                'no_public_products_route' =>
                    !$this->fileContains('routes/web.php', '/v/{slug}/productos'),
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
                'VCARD-PUBLICA-UI-PRODUCTOS-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'public_product_fields' => [
                'id_producto',
                'descripcion',
                'marca',
                'linea',
                'clasificacion',
                'unidad',
                'texto_publico',
                'destacado',
            ],
            'excluded_fields' => [
                'precio',
                'precio_minimo',
                'lista_precio',
                'costo',
                'margen',
                'stock',
                'existencia',
                'almacen',
                'proveedor',
                'movimientos',
                'auditoria',
                'ids_internos',
                'paths_privados',
            ],
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
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

    private function credentialController(int $userId): CredentialController
    {
        $auth = new AuthService(new UserRepository($this->connection()), $this->session());

        if (!$auth->attempt(self::USERNAME . '@example.test', self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate QA credential user.');
        }

        return new CredentialController(
            $this->config(),
            $auth,
            new PermissionService(new PermissionRepository($this->connection())),
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($this->connection())),
                $this->session()
            ),
            new CsrfTokenService($this->session(), 7200),
            new CredentialService(new UserCredentialRepository($this->connection())),
            new CredentialTokenService(
                new CredentialService(new UserCredentialRepository($this->connection())),
                new CredentialTokenRepository($this->connection())
            ),
            new CredentialQrService(),
            $this->vcardService(),
            $this->session()
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
        return $GLOBALS['vcard_publica_ui_productos_connection'];
    }

    private function config(): Config
    {
        return $GLOBALS['vcard_publica_ui_productos_config'];
    }

    private function session(): App\Core\Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('vcardpublicauiproductos1');
            session_id('vcardpublicauiproductos1' . bin2hex(random_bytes(4)));
            session_start();
        }

        return new App\Core\Session([
            'name' => session_name(),
            'same_site' => 'Lax',
            'secure' => false,
            'gc_max_lifetime' => 7200,
        ]);
    }

    private function request(string $path): Request
    {
        return new Request('GET', $path, [], [], ['host' => 'vcard-ui.example.test']);
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
            'titulo_publico' => 'Ingeniería comercial',
            'descripcion_publica' => 'Soluciones corporativas para refrigeración.',
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
            'facebook' => false,
            'instagram' => false,
            'whatsapp' => true,
            'google_maps' => true,
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

    private function insertProfile(PDO $pdo, int $userId, bool $withEscapedName): void
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
                google_maps_url,
                ubicacion_publica
             ) VALUES (
                :usuario_id,
                :primer_nombre,
                \'Pública\',
                \'Ingeniería comercial\',
                \'8181000000\',
                \'5512345678\',
                \'5215512345678\',
                \'https://gruporefrigerantes.example.test\',
                \'https://linkedin.example.test/grupo-refrigerantes\',
                \'https://maps.example.test/grupo-refrigerantes\',
                \'Monterrey, NL\'
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'primer_nombre' => $withEscapedName ? 'QA <script>' : 'QA Inactiva',
        ]);
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
            'descripcion_larga' => 'Descripción interna QA no pública',
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

    /**
     * @param list<string> $permissionCodes
     */
    private function assignRoleWithPermissions(PDO $pdo, int $userId, array $permissionCodes): void
    {
        $roleId = $this->insertRole($pdo, 'QA_VCARD_PUBLICA_UI_PRODUCTOS');
        $statement = $pdo->prepare(
            'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
             VALUES (:rol_id, :permiso_id, 1)'
        );

        foreach ($permissionCodes as $permissionCode) {
            $statement->execute([
                'rol_id' => $roleId,
                'permiso_id' => $this->permissionId($pdo, $permissionCode),
            ]);
        }

        $pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        )->execute([
            'usuario_id' => $userId,
            'rol_id' => $roleId,
        ]);
    }

    private function insertRole(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO roles (codigo, nombre, descripcion, es_sistema, activo)
             VALUES (:codigo, :nombre, \'Rol QA transaccional\', 0, 1)'
        );
        $statement->execute(['codigo' => $code, 'nombre' => $code]);

        return (int) $pdo->lastInsertId();
    }

    private function permissionId(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM permisos WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Permission not found: ' . $code);
        }

        return $id;
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
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qa_vcard_publica_ui_productos%'"),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_vcard_publica_ui_productos%'"
            ),
            'productos_qa' => $this->countWhere($pdo, 'productos', "id_producto LIKE 'QAVPUBUI%'"),
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_publica_ui_productos%'"
            ),
            'vcard_productos_qa' => $this->countWhere(
                $pdo,
                'vcard_productos vp',
                "vp.id_producto LIKE 'QAVPUBUI%'"
            ),
            'roles_qa' => $this->countWhere($pdo, 'roles', "codigo = 'QA_VCARD_PUBLICA_UI_PRODUCTOS'"),
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
