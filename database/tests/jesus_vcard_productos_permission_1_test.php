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
use App\Domain\Vcards\VcardService;
use App\Http\Controllers\ProfileController;
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

if (!class_exists(JesusVcardProductsPermissionPhase::class)) {
    require BASE_PATH . '/database/jesus-vcard-productos-permission.php';
}

return new class implements DatabaseTest {
    private const USERNAME = 'qa_jesus_vcard_productos';
    private const EMAIL = 'qa_jesus_vcard_productos@example.test';
    private const PASSWORD = 'JesusVcardProductos123!';
    private const ROLE_CODE = 'QA_JESUS_VCARD_PRODUCTOS_ROL';
    private const LIMITED_USERNAME = 'qa_jesus_vcard_productos_limitado';
    private const LIMITED_ROLE_CODE = 'QA_JESUS_VCARD_PRODUCTOS_LIMITADO';
    private const PRODUCT_ID = 'QAJVCP1';

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
                    'PERMISOS-JESUS-VCARD-PRODUCTOS-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $productCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM productos')->fetchColumn();
        $inventoryCountBefore = $this->tableExists($pdo, 'existencias')
            ? (int) $pdo->query('SELECT COUNT(*) FROM existencias')->fetchColumn()
            : 0;
        $pricesCountBefore = $this->tableExists($pdo, 'precios_producto')
            ? (int) $pdo->query('SELECT COUNT(*) FROM precios_producto')->fetchColumn()
            : 0;
        $phase = new JesusVcardProductsPermissionPhase();
        $results = [];
        $pdo->beginTransaction();

        try {
            $this->insertFixture($pdo);
            $this->insertLimitedFixture($pdo);
            $unitId = $this->unitId($pdo);
            $this->insertProduct($pdo, $unitId);

            $diagnosisBefore = $phase->diagnose($pdo, self::USERNAME);
            $limitedProfileBefore = $this->profileController(
                $this->userId($pdo, self::USERNAME)
            )->index($this->request('GET', '/perfil'));
            $applyFirst = $phase->apply($pdo, self::USERNAME);
            $applySecond = $phase->apply($pdo, self::USERNAME);
            $diagnosisAfter = $phase->diagnose($pdo, self::USERNAME);
            $userId = $this->userId($pdo, self::USERNAME);
            $limitedUserId = $this->userId($pdo, self::LIMITED_USERNAME);
            $profileAfter = $this->profileController($userId)->index(
                $this->request('GET', '/perfil', ['producto' => 'QAJVCP'])
            );
            $addAfter = $this->profileController($userId)->addVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/agregar', [], [
                    'id_producto' => self::PRODUCT_ID,
                ])
            );
            $limitedAdd = $this->profileController($limitedUserId)->addVcardProduct(
                $this->request('POST', '/perfil/vcard/productos/agregar', [], [
                    'id_producto' => self::PRODUCT_ID,
                ])
            );
            $csrfStatus = (new CsrfMiddleware(
                new CsrfTokenService($this->session(), 7200)
            ))->process(
                $this->request('POST', '/perfil/vcard/productos/agregar'),
                static fn (Request $request): Response => Response::html('ok')
            )->status();
            $permissionStatus = (new PermissionMiddleware(
                $this->authForUser($limitedUserId),
                new PermissionService(new PermissionRepository($this->connection())),
                JesusVcardProductsPermissionPhase::PERMISSION
            ))->process(
                $this->request('POST', '/perfil/vcard/productos/agregar'),
                static fn (Request $request): Response => Response::html('ok')
            )->status();

            $results['diagnosis'] = [
                'permission_exists' => $diagnosisBefore['permission'] !== null,
                'fixture_user_exists' => $diagnosisBefore['user'] !== null,
                'fixture_has_role' => count($diagnosisBefore['roles']) === 1,
                'fixture_without_permission_before_apply' =>
                    $diagnosisBefore['has_permission'] === false,
                'profile_forms_hidden_before_apply' =>
                    $limitedProfileBefore->status() === 200
                    && !str_contains($limitedProfileBefore->body(), 'Productos en mi vCard'),
            ];
            $results['apply'] = [
                'selected_existing_role' =>
                    is_array($applyFirst['selected_role'])
                    && $applyFirst['selected_role']['codigo'] === self::ROLE_CODE,
                'first_run_assigned' => $applyFirst['status'] === 'assigned',
                'second_run_idempotent' => $applySecond['status'] === 'already_assigned',
                'after_has_permission' => $diagnosisAfter['has_permission'] === true,
                'no_duplicate_role_permissions' => $this->duplicates($pdo) === 0,
                'not_admin' => !$this->userHasRole($pdo, self::USERNAME, 'ADMIN'),
            ];
            $results['profile_and_routes'] = [
                'profile_shows_section_after_apply' =>
                    $profileAfter->status() === 200
                    && str_contains($profileAfter->body(), 'Productos en mi vCard'),
                'post_with_permission_not_403' => $addAfter->status() !== 403,
                'post_without_csrf_419' => $csrfStatus === 419,
                'limited_user_403' => $limitedAdd->status() === 403,
                'permission_middleware_403' => $permissionStatus === 403,
            ];
            $results['guardrails'] = [
                'users_not_converted_to_admin' => !$this->userHasRole($pdo, self::USERNAME, 'ADMIN'),
                'products_count_unchanged_except_fixture' =>
                    (int) $pdo->query('SELECT COUNT(*) FROM productos')->fetchColumn()
                    === $productCountBefore + 1,
                'inventory_count_unchanged' =>
                    !$this->tableExists($pdo, 'existencias')
                    || (int) $pdo->query('SELECT COUNT(*) FROM existencias')->fetchColumn()
                    === $inventoryCountBefore,
                'prices_count_unchanged' =>
                    !$this->tableExists($pdo, 'precios_producto')
                    || (int) $pdo->query('SELECT COUNT(*) FROM precios_producto')->fetchColumn()
                    === $pricesCountBefore,
                'no_migrations_modified' => !$this->fileContains('docs/jesus-vcard-productos-permission-1.md', 'database/migrations/'),
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
                'PERMISOS-JESUS-VCARD-PRODUCTOS-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'permission' => JesusVcardProductsPermissionPhase::PERMISSION,
            'fixture_username' => self::USERNAME,
            'selected_role' => self::ROLE_CODE,
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function insertFixture(PDO $pdo): void
    {
        $this->insertUser($pdo, self::USERNAME, self::EMAIL);
        $this->insertProfile($pdo, self::USERNAME);
        $roleId = $this->insertRole($pdo, self::ROLE_CODE);
        $this->assignPermission($pdo, $roleId, 'perfil.ver');
        $this->assignPermission($pdo, $roleId, 'vcard.ver');
        $this->assignRole($pdo, self::USERNAME, $roleId);
    }

    private function insertLimitedFixture(PDO $pdo): void
    {
        $this->insertUser($pdo, self::LIMITED_USERNAME, 'qa_jesus_vcard_productos_limitado@example.test');
        $this->insertProfile($pdo, self::LIMITED_USERNAME);
        $roleId = $this->insertRole($pdo, self::LIMITED_ROLE_CODE);
        $this->assignPermission($pdo, $roleId, 'perfil.ver');
        $this->assignPermission($pdo, $roleId, 'vcard.ver');
        $this->assignRole($pdo, self::LIMITED_USERNAME, $roleId);
    }

    private function insertUser(PDO $pdo, string $username, string $email): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo)
             VALUES (:username, :email, :password_hash, 1)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
        ]);
    }

    private function insertProfile(PDO $pdo, string $username): void
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
                \'Jesus\',
                \'Operativo\',
                \'5511112222\'
             )'
        );
        $statement->execute(['usuario_id' => $this->userId($pdo, $username)]);
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

    private function assignPermission(PDO $pdo, int $roleId, string $permission): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
             VALUES (:rol_id, :permiso_id, 1)'
        );
        $statement->execute([
            'rol_id' => $roleId,
            'permiso_id' => $this->permissionId($pdo, $permission),
        ]);
    }

    private function assignRole(PDO $pdo, string $username, int $roleId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        );
        $statement->execute([
            'usuario_id' => $this->userId($pdo, $username),
            'rol_id' => $roleId,
        ]);
    }

    private function insertProduct(PDO $pdo, int $unitId): void
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
                1
             )'
        );
        $statement->execute([
            'id_producto' => self::PRODUCT_ID,
            'descripcion' => 'QA Jesus vCard Producto',
            'descripcion_larga' => 'QA interno',
            'unidad_medida_id' => $unitId,
        ]);
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
            new VcardService(
                new UserVcardRepository($this->connection()),
                new VcardPrivacyRepository($this->connection()),
                new VcardPrivacyService(new VcardPrivacyRepository($this->connection()))
            ),
            new VcardPrivacyService(new VcardPrivacyRepository($this->connection())),
            new VcardProductService(
                new VcardProductRepository($this->connection()),
                new VcardService(
                    new UserVcardRepository($this->connection()),
                    new VcardPrivacyRepository($this->connection()),
                    new VcardPrivacyService(new VcardPrivacyRepository($this->connection()))
                )
            )
        );
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
            session_name('jesusvcardproductos1');
            session_id('jesusvcardproductos1' . bin2hex(random_bytes(4)));
            session_start();
        }

        return new Session([
            'name' => session_name(),
            'same_site' => 'Lax',
            'secure' => false,
            'gc_max_lifetime' => 7200,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $query = [], array $body = []): Request
    {
        return new Request($method, $path, $query, $body, ['host' => 'jesus-vcard-productos.example.test']);
    }

    private function connection(): ConnectionProvider
    {
        return $GLOBALS['jesus_vcard_productos_connection'];
    }

    private function config(): Config
    {
        return $GLOBALS['jesus_vcard_productos_config'];
    }

    private function userId(PDO $pdo, string $username): int
    {
        $statement = $pdo->prepare('SELECT id FROM usuarios WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('User not found: ' . $username);
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

    private function userHasRole(PDO $pdo, string $username, string $roleCode): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM usuarios u
             INNER JOIN usuario_roles ur
                ON ur.usuario_id = u.id
               AND ur.activo = 1
               AND ur.eliminado_en IS NULL
             INNER JOIN roles r
                ON r.id = ur.rol_id
               AND r.activo = 1
               AND r.eliminado_en IS NULL
             WHERE u.username = :username
               AND r.codigo = :role_code'
        );
        $statement->execute([
            'username' => $username,
            'role_code' => $roleCode,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function duplicates(PDO $pdo): int
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
                "username LIKE 'qa_jesus_vcard_productos%'"
            ),
            'roles_qa' => $this->countWhere(
                $pdo,
                'roles',
                "codigo LIKE 'QA_JESUS_VCARD_PRODUCTOS%'"
            ),
            'productos_qa' => $this->countWhere(
                $pdo,
                'productos',
                "id_producto = '" . self::PRODUCT_ID . "'"
            ),
            'rol_permiso_duplicates' => $this->duplicates($pdo),
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
