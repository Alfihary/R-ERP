<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Seed;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/app/Support/Security/helpers.php';

final class JesusVcardProductsPermissionPhase
{
    public const USERNAME = 'jesus.g';
    public const PERMISSION = 'vcard.productos.administrar';

    /**
     * @return array<string, mixed>
     */
    public function diagnose(PDO $pdo, string $username = self::USERNAME): array
    {
        $user = $this->user($pdo, $username);
        $roles = $user === null ? [] : $this->roles($pdo, (int) $user['id']);
        $permission = $this->permission($pdo);
        $rolePermissions = $user === null ? [] : $this->rolePermissions($pdo, (int) $user['id']);

        return [
            'user' => $user === null ? null : [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'email' => (string) $user['email'],
                'activo' => (int) $user['activo'],
                'eliminado_en' => $user['eliminado_en'],
            ],
            'roles' => array_map(
                static fn (array $role): array => [
                    'id' => (int) $role['id'],
                    'codigo' => (string) $role['codigo'],
                    'nombre' => (string) $role['nombre'],
                    'activo' => (int) $role['activo'],
                ],
                $roles
            ),
            'permission' => $permission === null ? null : [
                'id' => (int) $permission['id'],
                'codigo' => (string) $permission['codigo'],
                'descripcion' => (string) ($permission['descripcion'] ?? ''),
                'activo' => (int) $permission['activo'],
            ],
            'role_permissions' => $rolePermissions,
            'has_permission' => $rolePermissions !== [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(PDO $pdo, string $username = self::USERNAME): array
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $before = $this->diagnose($pdo, $username);
            $user = $this->requireActiveUser($before, $username);
            $this->ensurePermission($pdo);
            $afterSeed = $this->diagnose($pdo, $username);

            if ($afterSeed['has_permission'] === true) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return [
                    'status' => 'already_assigned',
                    'username' => $username,
                    'selected_role' => $afterSeed['role_permissions'][0]['rol_codigo'] ?? null,
                    'reason' => 'A current role already grants the permission.',
                    'before' => $before,
                    'after' => $afterSeed,
                ];
            }

            $roles = $this->roles($pdo, (int) $user['id']);

            if ($roles === []) {
                throw new RuntimeException(
                    'BLOQUEADO: jesus.g has no active role. Create and assign an explicit operational role before granting this permission.'
                );
            }

            $selectedRole = $this->selectRole($roles);
            $permission = $this->requirePermission($pdo);
            $this->assignPermissionToRole($pdo, (int) $selectedRole['id'], (int) $permission['id']);
            $after = $this->diagnose($pdo, $username);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'status' => 'assigned',
                'username' => $username,
                'selected_role' => [
                    'id' => (int) $selectedRole['id'],
                    'codigo' => (string) $selectedRole['codigo'],
                    'nombre' => (string) $selectedRole['nombre'],
                ],
                'reason' => $this->selectionReason($selectedRole),
                'before' => $before,
                'after' => $after,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function user(PDO $pdo, string $username): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, username, email, activo, eliminado_en
             FROM usuarios
             WHERE username = :username
             LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($user) ? $user : null;
    }

    /**
     * @param array<string, mixed> $diagnosis
     * @return array<string, mixed>
     */
    private function requireActiveUser(array $diagnosis, string $username): array
    {
        if (!is_array($diagnosis['user'])) {
            throw new RuntimeException('BLOQUEADO: user not found: ' . $username);
        }

        if ((int) $diagnosis['user']['activo'] !== 1 || $diagnosis['user']['eliminado_en'] !== null) {
            throw new RuntimeException('BLOQUEADO: user is inactive or logically deleted: ' . $username);
        }

        return $diagnosis['user'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function roles(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            'SELECT r.id, r.codigo, r.nombre, r.es_sistema, r.activo
             FROM usuario_roles ur
             INNER JOIN roles r
                ON r.id = ur.rol_id
               AND r.activo = 1
               AND r.eliminado_en IS NULL
             WHERE ur.usuario_id = :usuario_id
               AND ur.activo = 1
               AND ur.eliminado_en IS NULL
             ORDER BY
                CASE WHEN r.codigo = \'ADMIN\' THEN 2 ELSE 0 END,
                r.es_sistema ASC,
                r.id ASC'
        );
        $statement->execute(['usuario_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function permission(PDO $pdo): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, codigo, descripcion, activo
             FROM permisos
             WHERE codigo = :codigo
             LIMIT 1'
        );
        $statement->execute(['codigo' => self::PERMISSION]);
        $permission = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($permission) ? $permission : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePermission(PDO $pdo): array
    {
        $permission = $this->permission($pdo);

        if ($permission === null
            || (int) $permission['activo'] !== 1
        ) {
            throw new RuntimeException('The vCard products permission is missing or inactive.');
        }

        return $permission;
    }

    /**
     * @return list<array<string, string>>
     */
    private function rolePermissions(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            'SELECT r.codigo AS rol_codigo,
                    r.nombre AS rol,
                    p.codigo AS permiso
             FROM usuarios u
             INNER JOIN usuario_roles ur
                ON ur.usuario_id = u.id
               AND ur.activo = 1
               AND ur.eliminado_en IS NULL
             INNER JOIN roles r
                ON r.id = ur.rol_id
               AND r.activo = 1
               AND r.eliminado_en IS NULL
             INNER JOIN rol_permisos rp
                ON rp.rol_id = r.id
               AND rp.activo = 1
               AND rp.eliminado_en IS NULL
             INNER JOIN permisos p
                ON p.id = rp.permiso_id
               AND p.activo = 1
               AND p.eliminado_en IS NULL
             WHERE u.id = :usuario_id
               AND u.activo = 1
               AND u.eliminado_en IS NULL
               AND p.codigo = :codigo
             ORDER BY r.id ASC'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'codigo' => self::PERMISSION,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function ensurePermission(PDO $pdo): void
    {
        $seed = require BASE_PATH . '/database/seeds/permisos_vcard_productos_1_seed.php';

        if (!$seed instanceof Seed) {
            throw new RuntimeException('PERMISOS-VCARD-PRODUCTOS-1 seed has an invalid contract.');
        }

        $seed->run($pdo);
    }

    /**
     * @param list<array<string, mixed>> $roles
     * @return array<string, mixed>
     */
    private function selectRole(array $roles): array
    {
        foreach ($roles as $role) {
            if ((string) $role['codigo'] !== 'ADMIN') {
                return $role;
            }
        }

        return $roles[0];
    }

    /**
     * @param array<string, mixed> $role
     */
    private function selectionReason(array $role): string
    {
        if ((string) $role['codigo'] === 'ADMIN') {
            return 'The user already has ADMIN as an active role; no ADMIN role was added.';
        }

        return 'Selected the first active non-ADMIN operational role assigned to the user.';
    }

    private function assignPermissionToRole(PDO $pdo, int $roleId, int $permissionId): void
    {
        $find = $pdo->prepare(
            'SELECT activo
             FROM rol_permisos
             WHERE rol_id = :rol_id
               AND permiso_id = :permiso_id
             LIMIT 1'
        );
        $find->execute([
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);

        if ($existing === false) {
            $insert = $pdo->prepare(
                'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
                 VALUES (:rol_id, :permiso_id, 1)'
            );
            $insert->execute([
                'rol_id' => $roleId,
                'permiso_id' => $permissionId,
            ]);
            return;
        }

        $update = $pdo->prepare(
            'UPDATE rol_permisos
             SET activo = 1,
                 eliminado_en = NULL,
                 eliminado_por = NULL
             WHERE rol_id = :rol_id
               AND permiso_id = :permiso_id'
        );
        $update->execute([
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

try {
    $command = $argv[1] ?? '';
    $options = [];

    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--')) {
            throw new RuntimeException('Unexpected CLI argument.');
        }

        [$name, $value] = array_pad(
            explode('=', substr($argument, 2), 2),
            2,
            ''
        );
        $options[$name] = $value;
    }

    if (!in_array($command, ['diagnose', 'apply', 'db:test'], true)) {
        throw new RuntimeException('Unknown PERMISOS-JESUS-VCARD-PRODUCTOS-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if ($expectedDatabase === '' || $expectedDatabase !== $confirmedDatabase) {
        throw new RuntimeException('The database name must be confirmed twice.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException(
            'PERMISOS-JESUS-VCARD-PRODUCTOS-1 CLI commands are disabled in production.'
        );
    }

    $databaseConfig = $config->get('database', []);

    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $expectedDatabase) {
        throw new RuntimeException(
            'PERMISOS-JESUS-VCARD-PRODUCTOS-1 configuration does not match the confirmed database.'
        );
    }

    $connection = new ConnectionProvider($databaseConfig);
    $GLOBALS['jesus_vcard_productos_connection'] = $connection;
    $GLOBALS['jesus_vcard_productos_config'] = $config;
    $pdo = $connection->pdo();
    $phase = new JesusVcardProductsPermissionPhase();

    $result = match ($command) {
        'diagnose' => $phase->diagnose($pdo),
        'apply' => $phase->apply($pdo),
        'db:test' => (static function () use ($pdo, $expectedDatabase): array {
            $test = require BASE_PATH . '/database/tests/jesus_vcard_productos_permission_1_test.php';

            if (!$test instanceof DatabaseTest) {
                throw new RuntimeException('PERMISOS-JESUS-VCARD-PRODUCTOS-1 test has an invalid contract.');
            }

            return $test->run($pdo, $expectedDatabase);
        })(),
    };

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        'PERMISOS-JESUS-VCARD-PRODUCTOS-1 database operation failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
