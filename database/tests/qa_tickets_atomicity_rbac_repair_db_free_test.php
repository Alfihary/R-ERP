<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/qa_tickets_atomicity_rbac_repair.php';

function qaTicketsAtomicityRbacRepairContractTest(): array
{
    $class = QaTicketsAtomicityRbacRepair::class;
    $assertions = 0;
    $assert = static function (bool $condition, string $name) use (&$assertions): void {
        $assertions++;
        if (!$condition) {
            throw new RuntimeException('TEST_FAILED_' . $name);
        }
    };
    $reject = static function (callable $operation, string $code) use ($assert): void {
        try {
            $operation();
        } catch (RuntimeException $error) {
            $assert($error->getMessage() === $code, 'EXPECTED_' . $code);
            return;
        }
        $assert(false, 'REJECTION_' . $code);
    };

    $expected = $class::expectedPermissions();
    $assert(count($expected) === 9, 'EXACT_COUNT');
    $assert(array_keys($expected) === [
        'tickets_productos.ver', 'tickets_productos.crear', 'tickets_productos.resolver',
        'tickets_productos.cancelar', 'tickets_productos.adjuntos.ver', 'tickets_productos.adjuntos.crear',
        'tickets_productos.comentarios.crear', 'tickets_productos.correo.reenviar', 'tickets_productos.eventos.ver',
    ], 'EXACT_KEYS');
    $assert($class::environmentKeys() === [
        'TICKETS_QA_RBAC_CONFIRM', 'TICKETS_QA_DATABASE_CONFIRM',
        'APP_ENV', 'APP_DB_HOST', 'APP_DB_PORT', 'APP_DB_NAME', 'APP_DB_USER', 'APP_DB_PASSWORD',
    ], 'ENVIRONMENT_KEYS_COMPLETE');

    $env = ['APP_ENV' => 'qa', 'APP_DB_NAME' => $class::DATABASE, 'APP_DB_USER' => $class::DB_USER, 'APP_DB_HOST' => 'localhost', 'APP_DB_PORT' => '3306', 'APP_DB_PASSWORD' => 'synthetic-value'] + $class::CONFIRMATIONS;
    $class::assertEnvironment($env, true);
    foreach (['production', 'local', '', 'development'] as $bad) {
        $reject(static fn () => $class::assertEnvironment(array_replace($env, ['APP_ENV' => $bad]), true), 'APP_ENV_GUARD_FAILED');
    }
    foreach (['APP_DB_NAME' => 'r_erp_db_core_0_test', 'APP_DB_USER' => 'root', 'APP_DB_HOST' => 'remote', 'APP_DB_PORT' => '3307', 'APP_DB_PASSWORD' => ''] as $key => $bad) {
        $codes = ['APP_DB_NAME' => 'DATABASE_ENV_GUARD_FAILED', 'APP_DB_USER' => 'ACCOUNT_ENV_GUARD_FAILED', 'APP_DB_HOST' => 'HOST_ENV_GUARD_FAILED', 'APP_DB_PORT' => 'PORT_ENV_GUARD_FAILED', 'APP_DB_PASSWORD' => 'CREDENTIAL_MISSING'];
        $reject(static fn () => $class::assertEnvironment(array_replace($env, [$key => $bad]), true), $codes[$key]);
    }
    foreach (array_keys($class::CONFIRMATIONS) as $key) {
        $reject(static fn () => $class::assertEnvironment(array_replace($env, [$key => 'WRONG']), true), 'DOUBLE_CONFIRMATION_FAILED');
    }

    $identity = ['database' => $class::DATABASE, 'login' => $class::ACCOUNT, 'effective' => $class::ACCOUNT, 'hostname' => $class::HOST, 'port' => 3306];
    $class::assertIdentity($identity);
    $reject(static fn () => $class::assertIdentity(array_replace($identity, ['database' => 'r_erp_db_core_0_test'])), 'DATABASE_IDENTITY_FAILED');
    $reject(static fn () => $class::assertIdentity(array_replace($identity, ['effective' => 'root@localhost'])), 'ACCOUNT_IDENTITY_FAILED');

    $rows = [];
    foreach ($expected as $code => $description) {
        $rows[] = ['id' => count($rows) + 1, 'codigo' => $code, 'modulo' => 'tickets_productos', 'nombre' => rtrim($description, '.'), 'descripcion' => $description, 'es_sistema' => 1, 'activo' => 1, 'eliminado_en' => null];
    }
    $classification = $class::classifyPermissions($rows);
    $assert($classification['missing'] === [] && $classification['conflicting'] === [], 'VALID_DEFINITIONS');
    $assert($class::missingAdminAssignments(array_keys($expected)) === [], 'ALL_ADMIN_ASSIGNMENTS');
    $assert($class::missingAdminAssignments(['tickets_productos.adjuntos.ver']) === array_values(array_diff(array_keys($expected), ['tickets_productos.adjuntos.ver'])), 'MISSING_ASSIGNMENTS');
    $assignment = $class::adminAssignmentQuery(1);
    $assert(!str_contains($assignment['sql'], '?'), 'NO_POSITIONAL_PLACEHOLDERS');
    $assert(substr_count($assignment['sql'], ':') === 10, 'EXPECTED_NAMED_PLACEHOLDER_COUNT');
    $assert(count($assignment['params']) === 10 && $assignment['params']['role_id'] === 1, 'ROLE_ID_BINDING');
    $assert(array_values(array_filter(array_keys($assignment['params']), static fn (string $key): bool => str_starts_with($key, 'perm_'))) === array_map(static fn (int $index): string => 'perm_' . $index, range(0, 8)), 'ALL_PERMISSION_BINDINGS');
    $assert(array_values(array_filter($assignment['params'], static fn (mixed $value): bool => is_string($value))) === array_keys($expected), 'EXACT_PERMISSION_BINDINGS');
    $assert(!str_contains($assignment['sql'], 'tickets_productos.'), 'NO_SQL_PERMISSION_INTERPOLATION');
    $assert(!str_contains($assignment['sql'], ' . $'), 'NO_SQL_STRING_INTERPOLATION');
    $conflicting = $rows;
    $conflicting[0]['descripcion'] = 'tampered';
    $reject(static fn () => $class::classifyPermissions($conflicting), 'PERMISSION_DEFINITION_CONFLICT');

    $source = file_get_contents(dirname(__DIR__) . '/qa_tickets_atomicity_rbac_repair.php');
    foreach (['DELETE FROM', 'UPDATE permisos', 'UPDATE rol_permisos', 'foreach (glob(', 'PHPMailer', 'DROP DATABASE', 'CREATE TABLE'] as $forbidden) {
        $assert(!str_contains($source, $forbidden), 'FORBIDDEN_' . md5($forbidden));
    }
    $assert(!str_contains($source, 'array_fill(0, count($codes), \'?\')'), 'NO_MIXED_ASSIGNMENT_BINDING');
    $assert(!str_contains($source, 'array_keys(self::CONFIRMATIONS) +'), 'NO_NUMERIC_ARRAY_PLUS');
    $plan = $class::plan();
    $assert($plan['pdo_opened'] === false && $plan['delete_sql'] === false && count($plan['expected_permissions']) === 9, 'PLAN_DB_FREE');

    return ['status' => 'PASS', 'assertions' => $assertions, 'allowlist' => 'PASS', 'guards' => 'PASS', 'insert_only' => true, 'idempotent' => true, 'mysql_connections' => 0, 'sql_executed' => false];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo json_encode(qaTicketsAtomicityRbacRepairContractTest(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
