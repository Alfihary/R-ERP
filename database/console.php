<?php

declare(strict_types=1);

use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

/**
 * @param list<string> $arguments
 * @return array{command: string, options: array<string, string>}
 */
function parseArguments(array $arguments): array
{
    array_shift($arguments);
    $command = '';
    $options = [];

    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--')) {
            [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
            $options[$name] = $value;
            continue;
        }

        if ($command !== '') {
            throw new RuntimeException('Only one database command can be executed at a time.');
        }

        $command = $argument;
    }

    return ['command' => $command, 'options' => $options];
}

function printUsage(): void
{
    echo <<<'TEXT'
    DB-CORE-0 database runner

    Usage:
      php database/console.php <command> --database=<name> --confirm-database=<name>

    Commands:
      migrate         Apply DB-CORE-0 migration.
      rollback        Roll back DB-CORE-0 tables. Destructive.
      seed            Apply the structural ADMIN role seed.
      seed:rollback   Remove the ADMIN seed when it has no relations.
      db:test         Execute DB-TEST-CORE and roll back test data.
      status          List applied migrations.

    Safety:
      APP_ENV=production is always rejected.
      APP_DB_NAME and both command-line database names must match exactly.
      The runner never creates a database.

    TEXT;
}

try {
    $input = parseArguments($argv);
    $command = $input['command'];
    $options = $input['options'];

    if ($command === '' || in_array($command, ['help', '--help', '-h'], true)) {
        printUsage();
        exit($command === '' ? 1 : 0);
    }

    $allowedCommands = ['migrate', 'rollback', 'seed', 'seed:rollback', 'db:test', 'status'];

    if (!in_array($command, $allowedCommands, true)) {
        throw new RuntimeException('Unknown database command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if ($expectedDatabase === '' || $expectedDatabase !== $confirmedDatabase) {
        throw new RuntimeException(
            'The database name must be supplied twice and both values must match.'
        );
    }

    $config = require BASE_PATH . '/bootstrap/database.php';
    $environment = strtolower((string) $config->get('app.env', 'production'));

    if ($environment === 'production') {
        throw new RuntimeException('Database phase commands are disabled in production.');
    }

    $databaseConfig = $config->get('database', []);

    if (!is_array($databaseConfig)) {
        throw new RuntimeException('Database configuration must be an array.');
    }

    $configuredDatabase = (string) ($databaseConfig['name'] ?? '');

    if ($configuredDatabase !== $expectedDatabase) {
        throw new RuntimeException(
            'APP_DB_NAME does not match the explicitly confirmed test database.'
        );
    }

    if (($databaseConfig['charset'] ?? null) !== 'utf8mb4'
        || ($databaseConfig['collation'] ?? null) !== 'utf8mb4_unicode_ci'
    ) {
        throw new RuntimeException('DB-CORE-0 requires utf8mb4 and utf8mb4_unicode_ci.');
    }

    $pdo = Connection::create($databaseConfig);
    $migration = require BASE_PATH
        . '/database/migrations/db_core_0_001_create_core_identity_tables.php';
    $seed = require BASE_PATH . '/database/seeds/db_core_0_seed_admin_role.php';
    $test = require BASE_PATH . '/database/tests/db_core_0_test.php';

    if (!$migration instanceof Migration || !$seed instanceof Seed || !$test instanceof DatabaseTest) {
        throw new RuntimeException('A DB-CORE-0 database artifact has an invalid contract.');
    }

    $runner = new MigrationRunner($pdo);
    $result = match ($command) {
        'migrate' => ['migration' => $migration->id(), 'result' => $runner->migrate($migration)],
        'rollback' => ['migration' => $migration->id(), 'result' => $runner->rollback($migration)],
        'seed' => (static function () use ($seed, $pdo): array {
            $seed->run($pdo);
            return ['seed' => $seed->id(), 'result' => 'applied'];
        })(),
        'seed:rollback' => (static function () use ($seed, $pdo): array {
            $seed->rollback($pdo);
            return ['seed' => $seed->id(), 'result' => 'rolled_back'];
        })(),
        'db:test' => $test->run($pdo, $expectedDatabase),
        'status' => ['migrations' => $runner->status()],
    };

    echo json_encode(
        ['database' => $expectedDatabase, 'command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, 'Database operation failed with SQLSTATE[' . $exception->getCode() . '].' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
