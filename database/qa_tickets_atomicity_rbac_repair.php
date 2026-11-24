<?php

declare(strict_types=1);

/**
 * QA-only, insert-only repair route for the Tickets RBAC seed drift.
 * --plan and --contract-test are DB-free. --inspect is SELECT-only.
 * --execute requires the exact QA target and two independent confirmations.
 */
final class QaTicketsAtomicityRbacRepair
{
    public const DATABASE = 'r_erp_tickets_qa_2f';
    public const ACCOUNT = 'r_erp_tickets_qa_setup@localhost';
    public const DB_USER = 'r_erp_tickets_qa_setup';
    public const HOST = 'GER005OTU';
    public const PORT = 3306;
    public const ADMIN_CODE = 'ADMIN';
    public const CONFIRMATIONS = [
        'TICKETS_QA_RBAC_CONFIRM' => 'APPLY_TICKETS_RBAC_INSERT_ONLY',
        'TICKETS_QA_DATABASE_CONFIRM' => self::DATABASE,
    ];

    /** @return array<string, string> */
    public static function expectedPermissions(): array
    {
        require_once __DIR__ . '/../app/Infrastructure/Database/Seed.php';
        $seed = require __DIR__ . '/seeds/tickets_productos_partidas_estados_1_seed_permissions.php';
        $reflection = new ReflectionClass($seed);
        $permissions = $reflection->getConstant('PERMISSIONS');
        if (!is_array($permissions) || count($permissions) !== 9) {
            throw new RuntimeException('RBAC_SOURCE_CONTRACT_INVALID');
        }
        foreach ($permissions as $code => $description) {
            if (!is_string($code) || !is_string($description) || $code === '' || $description === '') {
                throw new RuntimeException('RBAC_SOURCE_CONTRACT_INVALID');
            }
        }
        return $permissions;
    }

    private static function demand(bool $condition, string $code): void
    {
        if (!$condition) {
            throw new RuntimeException($code);
        }
    }

    /** @param array<string, string|null> $env */
    public static function assertEnvironment(array $env, bool $execute): void
    {
        self::demand(in_array(strtolower((string) ($env['APP_ENV'] ?? '')), ['qa', 'test'], true), 'APP_ENV_GUARD_FAILED');
        self::demand(($env['APP_DB_NAME'] ?? null) === self::DATABASE, 'DATABASE_ENV_GUARD_FAILED');
        $expectedUser = $execute ? self::DB_USER : 'r_erp_tickets_qa_runner';
        self::demand(($env['APP_DB_USER'] ?? null) === $expectedUser, 'ACCOUNT_ENV_GUARD_FAILED');
        self::demand(in_array(strtolower((string) ($env['APP_DB_HOST'] ?? '')), ['localhost', '127.0.0.1', 'ger005otu'], true), 'HOST_ENV_GUARD_FAILED');
        self::demand((string) ($env['APP_DB_PORT'] ?? '') === (string) self::PORT, 'PORT_ENV_GUARD_FAILED');
        self::demand(is_string($env['APP_DB_PASSWORD'] ?? null) && $env['APP_DB_PASSWORD'] !== '', 'CREDENTIAL_MISSING');
        if ($execute) {
            foreach (self::CONFIRMATIONS as $name => $value) {
                self::demand(($env[$name] ?? null) === $value, 'DOUBLE_CONFIRMATION_FAILED');
            }
        }
    }

    /** @param array{database:string,login:string,effective:string,hostname:string,port:int} $identity */
    public static function assertIdentity(array $identity): void
    {
        self::demand($identity['database'] === self::DATABASE, 'DATABASE_IDENTITY_FAILED');
        $runtimeAccount = 'r_erp_tickets_qa_runner@localhost';
        self::demand(
            ($identity['login'] === self::ACCOUNT && $identity['effective'] === self::ACCOUNT)
            || ($identity['login'] === $runtimeAccount && $identity['effective'] === $runtimeAccount),
            'ACCOUNT_IDENTITY_FAILED'
        );
        self::demand(strcasecmp($identity['hostname'], self::HOST) === 0 && $identity['port'] === self::PORT, 'SERVER_IDENTITY_FAILED');
    }

    /** @param list<array<string, mixed>> $rows */
    public static function classifyPermissions(array $rows): array
    {
        $expected = self::expectedPermissions();
        $present = [];
        $conflicts = [];
        foreach ($rows as $row) {
            $code = (string) ($row['codigo'] ?? '');
            if (!array_key_exists($code, $expected)) {
                continue;
            }
            $valid = ($row['modulo'] ?? null) === 'tickets_productos'
                && ($row['nombre'] ?? null) === rtrim($expected[$code], '.')
                && ($row['descripcion'] ?? null) === $expected[$code]
                && (int) ($row['es_sistema'] ?? 0) === 1
                && (int) ($row['activo'] ?? 0) === 1
                && ($row['eliminado_en'] ?? null) === null;
            if (!$valid) {
                $conflicts[] = $code;
                continue;
            }
            $present[$code] = (int) ($row['id'] ?? 0);
        }
        self::demand($conflicts === [], 'PERMISSION_DEFINITION_CONFLICT');
        $missing = array_values(array_diff(array_keys($expected), array_keys($present)));
        return ['expected' => array_keys($expected), 'present' => array_keys($present), 'missing' => $missing, 'conflicting' => $conflicts, 'ids' => $present];
    }

    /** @param array<string, mixed> $role */
    public static function assertAdminRole(array $role): void
    {
        self::demand(($role['codigo'] ?? null) === self::ADMIN_CODE, 'ADMIN_ROLE_CODE_MISMATCH');
        self::demand((int) ($role['es_sistema'] ?? 0) === 1 && (int) ($role['activo'] ?? 0) === 1 && ($role['eliminado_en'] ?? null) === null, 'ADMIN_ROLE_NOT_ACTIVE');
    }

    /** @param list<string> $assignedCodes */
    public static function missingAdminAssignments(array $assignedCodes): array
    {
        return array_values(array_diff(array_keys(self::expectedPermissions()), $assignedCodes));
    }

    /** @return array{sql:string,params:array<string,int|string>} */
    public static function adminAssignmentQuery(int $adminRoleId): array
    {
        self::demand($adminRoleId > 0, 'ADMIN_ROLE_ID_INVALID');
        $codes = array_keys(self::expectedPermissions());
        $placeholders = [];
        $params = ['role_id' => $adminRoleId];
        foreach ($codes as $index => $code) {
            $name = 'perm_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $code;
        }

        return [
            'sql' => "SELECT p.codigo FROM rol_permisos rp
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE rp.rol_id = :role_id AND rp.activo = 1 AND rp.eliminado_en IS NULL
               AND p.codigo IN (" . implode(',', $placeholders) . ')',
            'params' => $params,
        ];
    }

    public static function plan(): array
    {
        return [
            'mode' => 'DB_FREE_PLAN',
            'database' => self::DATABASE,
            'account' => self::ACCOUNT,
            'expected_permissions' => array_keys(self::expectedPermissions()),
            'strategy' => 'INSERT_ONLY_MISSING_PERMISSIONS_AND_ADMIN_ROLE_RELATIONS',
            'admin_role' => self::ADMIN_CODE,
            'delete_sql' => false,
            'broad_update' => false,
            'pdo_opened' => false,
            'sql_executed' => false,
        ];
    }

    /** @return list<string> */
    public static function environmentKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::CONFIRMATIONS),
            ['APP_ENV', 'APP_DB_HOST', 'APP_DB_PORT', 'APP_DB_NAME', 'APP_DB_USER', 'APP_DB_PASSWORD']
        )));
    }

    private static function connect(array $env): PDO
    {
        $pdo = new PDO(
            'mysql:host=' . $env['APP_DB_HOST'] . ';port=' . self::PORT . ';dbname=' . self::DATABASE . ';charset=utf8mb4',
            self::DB_USER,
            $env['APP_DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        self::assertIdentity([
            'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
            'login' => (string) $pdo->query('SELECT USER()')->fetchColumn(),
            'effective' => (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn(),
            'hostname' => (string) $pdo->query('SELECT @@hostname')->fetchColumn(),
            'port' => (int) $pdo->query('SELECT @@port')->fetchColumn(),
        ]);
        return $pdo;
    }

    /** @return array<string, mixed> */
    private static function inspect(PDO $pdo): array
    {
        $rows = $pdo->prepare(
            "SELECT codigo, modulo, nombre, descripcion, es_sistema, activo, eliminado_en, id
             FROM permisos WHERE codigo IN (" . implode(',', array_fill(0, count(self::expectedPermissions()), '?')) . ")"
        );
        $codes = array_keys(self::expectedPermissions());
        $rows->execute($codes);
        $permissions = self::classifyPermissions($rows->fetchAll(PDO::FETCH_ASSOC));

        $roleStatement = $pdo->prepare('SELECT id, codigo, es_sistema, activo, eliminado_en FROM roles WHERE codigo = :codigo LIMIT 1');
        $roleStatement->execute(['codigo' => self::ADMIN_CODE]);
        $role = $roleStatement->fetch(PDO::FETCH_ASSOC);
        self::demand(is_array($role), 'ADMIN_ROLE_MISSING');
        self::assertAdminRole($role);

        $assignment = self::adminAssignmentQuery((int) $role['id']);
        $assignmentStatement = $pdo->prepare($assignment['sql']);
        $assignmentStatement->execute($assignment['params']);
        $assigned = array_map('strval', $assignmentStatement->fetchAll(PDO::FETCH_COLUMN));

        return [
            'EXPECTED_PERMISSIONS' => $permissions['expected'],
            'PRESENT_PERMISSIONS' => $permissions['present'],
            'MISSING_PERMISSIONS' => $permissions['missing'],
            'CONFLICTING_PERMISSIONS' => $permissions['conflicting'],
            'ADMIN_ROLE_FOUND' => true,
            'ADMIN_ROLE_ID' => (int) $role['id'],
            'ADMIN_ROLE_ACTIVE' => true,
            'MISSING_ADMIN_ASSIGNMENTS' => self::missingAdminAssignments($assigned),
            'write_sql_executed' => false,
        ];
    }

    public static function execute(PDO $pdo): array
    {
        $before = self::inspect($pdo);
        $expected = self::expectedPermissions();
        $pdo->beginTransaction();
        try {
            foreach ($before['MISSING_PERMISSIONS'] as $code) {
                $statement = $pdo->prepare(
                    'INSERT INTO permisos (codigo, modulo, nombre, descripcion, es_sistema, activo)
                     VALUES (:codigo, :modulo, :nombre, :descripcion, 1, 1)'
                );
                $statement->execute(['codigo' => $code, 'modulo' => 'tickets_productos', 'nombre' => rtrim($expected[$code], '.'), 'descripcion' => $expected[$code]]);
            }
            $ids = [];
            $statement = $pdo->prepare('SELECT id, codigo FROM permisos WHERE codigo = :codigo LIMIT 1');
            foreach (array_keys($expected) as $code) {
                $statement->execute(['codigo' => $code]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                self::demand(is_array($row), 'PERMISSION_POSTCHECK_FAILED');
                $ids[$code] = (int) $row['id'];
            }
            $roleId = (int) $before['ADMIN_ROLE_ID'];
            $find = $pdo->prepare('SELECT 1 FROM rol_permisos WHERE rol_id = :role_id AND permiso_id = :permission_id LIMIT 1');
            $insert = $pdo->prepare('INSERT INTO rol_permisos (rol_id, permiso_id, activo) VALUES (:role_id, :permission_id, 1)');
            foreach ($ids as $code => $permissionId) {
                $find->execute(['role_id' => $roleId, 'permission_id' => $permissionId]);
                if ($find->fetchColumn() === false) {
                    $insert->execute(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('RBAC_REPAIR_STOPPED', 0, $error);
        }
        $after = self::inspect($pdo);
        self::demand($after['MISSING_PERMISSIONS'] === [] && $after['MISSING_ADMIN_ASSIGNMENTS'] === [], 'RBAC_POSTCHECK_FAILED');
        return ['status' => 'PASS', 'mode' => 'EXECUTE', 'postcheck' => $after, 'insert_only' => true, 'delete_sql' => false];
    }

    /** @param list<string> $argv */
    public static function run(array $argv): array
    {
        self::demand(count($argv) === 2 && in_array($argv[1], ['--plan', '--contract-test', '--inspect', '--execute'], true), 'CLI_MODE_REQUIRED');
        if ($argv[1] === '--plan') {
            return self::plan();
        }
        if ($argv[1] === '--contract-test') {
            require_once __DIR__ . '/tests/qa_tickets_atomicity_rbac_repair_db_free_test.php';
            return qaTicketsAtomicityRbacRepairContractTest();
        }
        $env = [];
        foreach (self::environmentKeys() as $key) {
            $env[$key] = getenv($key);
        }
        self::assertEnvironment($env, $argv[1] === '--execute');
        $pdo = self::connect($env);
        $inspection = self::inspect($pdo);
        if ($argv[1] === '--inspect') {
            return ['status' => 'PASS', 'mode' => 'INSPECT'] + $inspection;
        }
        return self::execute($pdo);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        echo json_encode(QaTicketsAtomicityRbacRepair::run($argv), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (Throwable $error) {
        $code = preg_match('/^[A-Z0-9_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'QA_RBAC_REPAIR_BLOCKED';
        echo json_encode(['status' => 'BLOCKED', 'code' => $code], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(1);
    }
}
