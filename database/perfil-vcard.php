<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

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

    if (!in_array($command, ['migrate', 'rollback', 'seed', 'db:test', 'status'], true)) {
        throw new RuntimeException('Unknown PERFIL-VCARD-DB-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if (
        $expectedDatabase === ''
        || $expectedDatabase !== $confirmedDatabase
    ) {
        throw new RuntimeException(
            'The database name must be confirmed twice.'
        );
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (
        strtolower((string) $config->get('app.env', 'production'))
        === 'production'
    ) {
        throw new RuntimeException(
            'PERFIL-VCARD-DB-1 CLI commands are disabled in production.'
        );
    }

    $databaseConfig = $config->get('database', []);

    if (
        !is_array($databaseConfig)
        || ($databaseConfig['name'] ?? '') !== $expectedDatabase
    ) {
        throw new RuntimeException(
            'PERFIL-VCARD-DB-1 configuration does not match the confirmed database.'
        );
    }

    $pdo = (new ConnectionProvider($databaseConfig))->pdo();
    $migration = require BASE_PATH
        . '/database/migrations/perfil_vcard_1_001_create_profile_vcard_tables.php';
    $permissionSeed = require BASE_PATH
        . '/database/seeds/perfil_vcard_1_seed_permissions.php';
    $test = require BASE_PATH
        . '/database/tests/perfil_vcard_1_test.php';

    if (!$migration instanceof Migration) {
        throw new RuntimeException(
            'PERFIL-VCARD-DB-1 migration has an invalid contract.'
        );
    }

    if (!$permissionSeed instanceof Seed) {
        throw new RuntimeException(
            'PERFIL-VCARD-DB-1 seed has an invalid contract.'
        );
    }

    if (!$test instanceof DatabaseTest) {
        throw new RuntimeException(
            'PERFIL-VCARD-DB-1 test has an invalid contract.'
        );
    }

    $runner = new MigrationRunner($pdo);

    $result = match ($command) {
        'migrate' => [
            'migration' => $migration->id(),
            'result' => $runner->migrate($migration),
        ],
        'rollback' => [
            'migration' => $migration->id(),
            'result' => (static function () use (
                $pdo,
                $permissionSeed,
                $runner,
                $migration
            ): string {
                $permissionSeed->rollback($pdo);
                return $runner->rollback($migration);
            })(),
        ],
        'seed' => (static function () use ($pdo, $permissionSeed): array {
            $permissionSeed->run($pdo);

            return [
                'permissions' => $permissionSeed->id(),
                'result' => 'applied',
            ];
        })(),
        'db:test' => $test->run($pdo, $expectedDatabase),
        'status' => ['migrations' => $runner->status()],
    };

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        'PERFIL-VCARD-DB-1 database operation failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
