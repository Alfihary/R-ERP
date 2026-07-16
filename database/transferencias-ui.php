<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
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

    if (!in_array($command, ['seed', 'db:test'], true)) {
        throw new RuntimeException('Unknown TRANSFERENCIAS-UI-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if ($expectedDatabase === '' || $expectedDatabase !== $confirmedDatabase) {
        throw new RuntimeException('The database name must be confirmed twice.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (
        strtolower((string) $config->get('app.env', 'production'))
        === 'production'
    ) {
        throw new RuntimeException(
            'TRANSFERENCIAS-UI-1 CLI commands are disabled in production.'
        );
    }

    $databaseConfig = $config->get('database', []);

    if (
        !is_array($databaseConfig)
        || ($databaseConfig['name'] ?? '') !== $expectedDatabase
    ) {
        throw new RuntimeException(
            'TRANSFERENCIAS-UI-1 configuration does not match the confirmed database.'
        );
    }

    $pdo = (new ConnectionProvider($databaseConfig))->pdo();
    $permissionSeed = require BASE_PATH
        . '/database/seeds/transferencias_ui_1_seed_permissions.php';
    $conceptSeed = require BASE_PATH
        . '/database/seeds/transferencias_1_seed_conceptos.php';
    $test = require BASE_PATH
        . '/database/tests/transferencias_ui_1_test.php';

    if (
        !$permissionSeed instanceof Seed
        || !$conceptSeed instanceof Seed
        || !$test instanceof DatabaseTest
    ) {
        throw new RuntimeException(
            'A TRANSFERENCIAS-UI-1 artifact has an invalid contract.'
        );
    }

    $result = match ($command) {
        'seed' => (static function () use ($permissionSeed, $pdo): array {
            $permissionSeed->run($pdo);
            return ['seed' => $permissionSeed->id(), 'result' => 'applied'];
        })(),
        'db:test' => (static function () use (
            $conceptSeed,
            $permissionSeed,
            $test,
            $pdo,
            $expectedDatabase
        ): array {
            $conceptSeed->run($pdo);
            $permissionSeed->run($pdo);
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
        'TRANSFERENCIAS-UI-1 database operation failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
