<?php

declare(strict_types=1);

use App\Core\Request;
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
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
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
    private const USERNAME = 'qa_vcard_publicacion_fix';
    private const EMAIL = 'qa.vcard.publicacion.fix@example.test';
    private const PASSWORD = 'VcardPublicacionFix123!';
    private const SLUG = 'qa-vcard-publicacion-fix';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($activeDatabase !== $expectedDatabase) {
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
                throw new RuntimeException('VCARD-PUBLICACION-FIX-1 requires table: ' . $table);
            }
        }

        foreach ([
            'perfil.ver',
            'vcard.ver',
            'vcard.editar',
            'vcard.publicar',
            'vcard.privacidad.editar',
        ] as $permission) {
            if (!$this->activePermissionExists($pdo, $permission)) {
                throw new RuntimeException('VCARD-PUBLICACION-FIX-1 requires permission: ' . $permission);
            }
        }

        $before = $this->counts($pdo);
        $jesusBefore = $this->jesusState($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $unitId = $this->unitId($pdo);
            $this->insertProduct($pdo, 'QAVPUBFIX1', 'Producto público QA', $unitId, 1);
            $userId = $this->insertUser($pdo);
            $this->insertProfile($pdo, $userId);
            $this->assignRoleWithPermissions($pdo, $userId, [
                'perfil.ver',
                'vcard.ver',
                'vcard.editar',
                'vcard.publicar',
                'vcard.privacidad.editar',
            ]);

            $profile = $this->profileController($userId);
            $public = $this->publicController();

            $profilePage = $profile->index($this->request('GET', '/perfil'));
            $saveConfig = $profile->updateVcard($this->request('POST', '/perfil/vcard/configuracion', [
                'slug' => self::SLUG,
                'titulo_publico' => 'Especialista HVAC',
                'descripcion_publica' => 'Atención técnica y comercial pública.',
                'canal_contacto_preferido' => 'whatsapp',
            ]));
            $savePrivacy = $profile->updateVcardPrivacy($this->request('POST', '/perfil/vcard/privacidad', [
                'foto' => '0',
                'correo' => '1',
                'telefono_fijo' => '0',
                'telefono_movil' => '1',
                'puesto' => '1',
                'empresa' => '0',
                'almacen' => '0',
                'ubicacion' => '0',
                'sitio_web' => '0',
                'linkedin' => '0',
                'facebook' => '0',
                'instagram' => '0',
                'whatsapp' => '1',
                'google_maps' => '0',
                'productos' => '1',
            ]));
            $publish = $profile->publishVcard($this->request('POST', '/perfil/vcard/publicar'));
            $vcardId = $this->vcardIdBySlug($pdo, self::SLUG);
            $this->linkProduct($pdo, $vcardId, 'QAVPUBFIX1');
            $published = $public->show($this->request('GET', '/v/' . self::SLUG), ['slug' => self::SLUG]);
            $publishedBody = $published->body();
            $qr = $public->qr($this->request('GET', '/v/' . self::SLUG . '/qr'), ['slug' => self::SLUG]);
            $vcf = $public->vcf($this->request('GET', '/v/' . self::SLUG . '/vcf'), ['slug' => self::SLUG]);
            $photo = $public->photo($this->request('GET', '/v/' . self::SLUG . '/foto'), ['slug' => self::SLUG]);
            $unpublish = $profile->unpublishVcard($this->request('POST', '/perfil/vcard/despublicar'));
            $afterUnpublish = $public->show($this->request('GET', '/v/' . self::SLUG), ['slug' => self::SLUG]);

            $results['private_profile_flow'] = [
                'profile_page_200' => $profilePage->status() === 200,
                'profile_shows_vcard_panel' =>
                    str_contains($profilePage->body(), 'vCard pública')
                    && str_contains($profilePage->body(), '/perfil/vcard/publicar'),
                'save_config_redirects' => $saveConfig->status() === 302,
                'save_privacy_redirects' => $savePrivacy->status() === 302,
                'publish_redirects' => $publish->status() === 302,
                'unpublish_redirects' => $unpublish->status() === 302,
            ];

            $results['public_vcard'] = [
                'published_slug_200' => $published->status() === 200,
                'not_found_message_absent' =>
                    !str_contains($publishedBody, 'Información no disponible')
                    && !str_contains($publishedBody, 'No es posible mostrar esta vCard pública.'),
                'public_content_present' =>
                    str_contains($publishedBody, 'Especialista HVAC')
                    && str_contains($publishedBody, 'Producto público QA'),
                'unpublished_returns_not_available' => $afterUnpublish->status() === 404,
            ];

            $results['privacy_and_security'] = [
                'products_visible_when_privacy_enabled' =>
                    str_contains($publishedBody, 'Producto público QA'),
                'price_stock_cost_provider_absent' =>
                    !$this->containsAny($publishedBody, [
                        'precio mínimo',
                        'lista de precio',
                        'costo',
                        'stock',
                        'existencia',
                        'proveedor',
                    ]),
                'sensitive_absent' =>
                    !$this->containsAny($publishedBody, [
                        'password_hash',
                        'token_hash',
                        'storage/uploads',
                        '/credencial/verificar/',
                    ]),
            ];

            $results['public_endpoints'] = [
                'qr_still_works' => $qr->status() === 200,
                'vcf_still_works' => $vcf->status() === 200,
                'photo_privacy_respected' => $photo->status() === 404,
            ];

            $this->forcePublishJesus($pdo, true);
            $jesus = $public->show($this->request('GET', '/v/jesus-g'), ['slug' => 'jesus-g']);
            $this->forcePublishJesus($pdo, false);
            $jesusUnpublished = $public->show($this->request('GET', '/v/jesus-g'), ['slug' => 'jesus-g']);
            $this->forcePublishJesus($pdo, true);
            $this->deactivateJesus($pdo);
            $jesusInactive = $public->show($this->request('GET', '/v/jesus-g'), ['slug' => 'jesus-g']);
            $this->activateJesusAndMarkDeleted($pdo);
            $jesusDeleted = $public->show($this->request('GET', '/v/jesus-g'), ['slug' => 'jesus-g']);

            $results['jesus_g_regression'] = [
                'published_loads_200' => $jesus->status() === 200,
                'unpublished_rejected' => $jesusUnpublished->status() === 404,
                'inactive_user_rejected' => $jesusInactive->status() === 404,
                'deleted_user_rejected' => $jesusDeleted->status() === 404,
            ];

            $results['routes_and_middleware'] = [
                'config_route_declared' => $this->fileContains('routes/web.php', "'/perfil/vcard/configuracion'"),
                'privacy_route_declared' => $this->fileContains('routes/web.php', "'/perfil/vcard/privacidad'"),
                'publish_route_declared' => $this->fileContains('routes/web.php', "'/perfil/vcard/publicar'"),
                'unpublish_route_declared' => $this->fileContains('routes/web.php', "'/perfil/vcard/despublicar'"),
                'post_without_csrf_419' =>
                    $this->middlewareStatus(
                        new CsrfMiddleware($this->csrf()),
                        new Request('POST', '/perfil/vcard/publicar')
                    ) === 419,
                'no_session_redirects_to_login' =>
                    $this->middlewareStatus(
                        new AuthMiddleware($this->guestAuth()),
                        new Request('POST', '/perfil/vcard/publicar')
                    ) === 302,
                'without_permission_403' =>
                    $this->middlewareStatus(
                        new PermissionMiddleware(
                            $this->authForUser($userId),
                            new PermissionService(new PermissionRepository($GLOBALS['vcard_publicacion_fix_connection'])),
                            'vcard.permiso_inexistente'
                        ),
                        new Request('POST', '/perfil/vcard/publicar')
                    ) === 403,
            ];

            $during = $this->counts($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->counts($pdo);
        $jesusAfter = $this->jesusState($pdo);

        $results['cleanup'] = [
            'transient_qa_rolled_back' => $before === $after,
            'jesus_state_unchanged' => $jesusBefore === $jesusAfter,
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'VCARD-PUBLICACION-FIX-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'root_cause' => 'jesus-g existed but was not published and privacy fields were disabled',
            'persistent_jesus_state_before' => $jesusBefore,
            'persistent_jesus_state_after' => $jesusAfter,
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function profileController(int $userId): ProfileController
    {
        $connection = $GLOBALS['vcard_publicacion_fix_connection'];
        $privacyRepository = new VcardPrivacyRepository($connection);
        $privacy = new VcardPrivacyService($privacyRepository);
        $vcards = new VcardService(
            new UserVcardRepository($connection),
            $privacyRepository,
            $privacy
        );

        return new ProfileController(
            $GLOBALS['vcard_publicacion_fix_config'],
            $this->authForUser($userId),
            new PermissionService(new PermissionRepository($connection)),
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($connection)),
                $this->session()
            ),
            $this->csrf(),
            new ProfileService(
                new ProfileRepository($connection),
                new UserPhotoRepository($connection)
            ),
            new UserPhotoStorage(
                (string) $GLOBALS['vcard_publicacion_fix_config']->get('paths.STORAGE_PATH', STORAGE_PATH),
                true
            ),
            $vcards,
            $privacy
        );
    }

    private function publicController(): PublicVcardController
    {
        $connection = $GLOBALS['vcard_publicacion_fix_connection'];
        $privacyRepository = new VcardPrivacyRepository($connection);
        $privacy = new VcardPrivacyService($privacyRepository);
        $vcards = new VcardService(
            new UserVcardRepository($connection),
            $privacyRepository,
            $privacy
        );

        return new PublicVcardController(
            $GLOBALS['vcard_publicacion_fix_config'],
            $vcards,
            new VcardVcfService(),
            new VcardQrService(),
            new VcardProductService(
                new VcardProductRepository($connection),
                $vcards
            )
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = []): Request
    {
        return new Request($method, $path, [], $body, [
            'host' => 'vcard-publicacion.example.test',
        ]);
    }

    private function csrf(): CsrfTokenService
    {
        return new CsrfTokenService($this->session(), 7200);
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('vcardpublicacionfix1');
            session_id('vcardpublicacionfix1' . bin2hex(random_bytes(4)));
            session_start();
        }

        return new Session([
            'name' => session_name(),
            'same_site' => 'Lax',
            'secure' => false,
            'gc_max_lifetime' => 7200,
        ]);
    }

    private function authForUser(int $userId): AuthService
    {
        $auth = new AuthService(
            new UserRepository($GLOBALS['vcard_publicacion_fix_connection']),
            $this->session()
        );

        if (!$auth->attempt(self::EMAIL, self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate VCARD-PUBLICACION-FIX-1 QA user.');
        }

        return $auth;
    }

    private function guestAuth(): AuthService
    {
        $_SESSION = [];

        return new AuthService(
            new UserRepository($GLOBALS['vcard_publicacion_fix_connection']),
            $this->session()
        );
    }

    private function middlewareStatus(object $middleware, Request $request): int
    {
        return $middleware->process(
            $request,
            static fn (Request $request) => App\Core\Response::html('ok')
        )->status();
    }

    private function insertUser(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo)
             VALUES (:username, :email, :password_hash, 1)'
        );
        $statement->execute([
            'username' => self::USERNAME,
            'email' => self::EMAIL,
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
                telefono_movil,
                whatsapp
            ) VALUES (
                :usuario_id,
                \'QA\',
                \'Publicación\',
                \'Especialista HVAC\',
                \'5512345678\',
                \'5215512345678\'
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
            'descripcion_larga' => 'Texto QA privado no público',
            'unidad_medida_id' => $unitId,
            'activo' => $active,
        ]);
    }

    private function linkProduct(PDO $pdo, int $vcardId, string $productId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO vcard_productos (
                vcard_id,
                id_producto,
                activo,
                destacado,
                orden,
                texto_publico
            ) VALUES (
                :vcard_id,
                :id_producto,
                1,
                1,
                1,
                \'Producto público vinculado por QA\'
            )'
        );
        $statement->execute([
            'vcard_id' => $vcardId,
            'id_producto' => $productId,
        ]);
    }

    /**
     * @param list<string> $permissionCodes
     */
    private function assignRoleWithPermissions(PDO $pdo, int $userId, array $permissionCodes): void
    {
        $roleId = $this->insertRole($pdo);
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

    private function insertRole(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO roles (codigo, nombre, descripcion, es_sistema, activo)
             VALUES (\'QA_VCARD_PUBLICACION_FIX\', \'QA_VCARD_PUBLICACION_FIX\', \'Rol QA transaccional\', 0, 1)'
        );
        $statement->execute();

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

    private function vcardIdBySlug(PDO $pdo, string $slug): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM vcards_usuario WHERE slug = :slug LIMIT 1'
        );
        $statement->execute(['slug' => $slug]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('vCard not found for slug: ' . $slug);
        }

        return $id;
    }

    private function forcePublishJesus(PDO $pdo, bool $published): void
    {
        $statement = $pdo->prepare(
            'UPDATE vcards_usuario
             SET publicada = :publicada,
                 publicado_en = CASE WHEN :publicada_fecha = 1 THEN CURRENT_TIMESTAMP ELSE publicado_en END,
                 despublicado_en = CASE WHEN :despublicada = 1 THEN CURRENT_TIMESTAMP ELSE NULL END
             WHERE slug = \'jesus-g\''
        );
        $statement->execute([
            'publicada' => $published ? 1 : 0,
            'publicada_fecha' => $published ? 1 : 0,
            'despublicada' => $published ? 0 : 1,
        ]);
        $pdo->prepare(
            'UPDATE vcard_privacidad
             SET visible = 1
             WHERE vcard_id IN (SELECT id FROM vcards_usuario WHERE slug = \'jesus-g\')
               AND campo IN (\'correo\', \'telefono_movil\', \'puesto\', \'productos\', \'whatsapp\')'
        )->execute();
    }

    private function deactivateJesus(PDO $pdo): void
    {
        $pdo->prepare(
            'UPDATE usuarios
             SET activo = 0
             WHERE id IN (SELECT usuario_id FROM vcards_usuario WHERE slug = \'jesus-g\')'
        )->execute();
    }

    private function activateJesusAndMarkDeleted(PDO $pdo): void
    {
        $pdo->prepare(
            'UPDATE usuarios
             SET activo = 1,
                 eliminado_en = CURRENT_TIMESTAMP
             WHERE id IN (SELECT usuario_id FROM vcards_usuario WHERE slug = \'jesus-g\')'
        )->execute();
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

    /**
     * @return array<string, mixed>
     */
    private function jesusState(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            'SELECT
                u.id AS user_id,
                u.username,
                u.activo,
                u.eliminado_en,
                v.id AS vcard_id,
                v.slug,
                v.publicada,
                v.publicado_en,
                v.despublicado_en
             FROM usuarios u
             LEFT JOIN vcards_usuario v ON v.usuario_id = u.id
             WHERE u.username = \'jesus.g\'
             LIMIT 1'
        );
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
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

    private function activePermissionExists(PDO $pdo, string $code): bool
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

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username = '" . self::USERNAME . "'"),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username = '" . self::USERNAME . "'"
            ),
            'productos_qa' => $this->countWhere($pdo, 'productos', "id_producto = 'QAVPUBFIX1'"),
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username = '" . self::USERNAME . "'"
            ),
            'vcard_productos_qa' => $this->countWhere(
                $pdo,
                'vcard_productos',
                "id_producto = 'QAVPUBFIX1'"
            ),
            'roles_qa' => $this->countWhere($pdo, 'roles', "codigo = 'QA_VCARD_PUBLICACION_FIX'"),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
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
