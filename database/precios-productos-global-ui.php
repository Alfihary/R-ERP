<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;

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

    if ($command !== 'db:test') {
        throw new RuntimeException(
            'Unknown PRECIOS-PRODUCTOS-GLOBAL-UI-1 CLI command.'
        );
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
    require_once BASE_PATH . '/app/Support/Security/helpers.php';

    if (
        strtolower((string) $config->get('app.env', 'production'))
        === 'production'
    ) {
        throw new RuntimeException(
            'PRECIOS-PRODUCTOS-GLOBAL-UI-1 CLI commands are disabled in production.'
        );
    }

    $databaseConfig = $config->get('database', []);

    if (
        !is_array($databaseConfig)
        || ($databaseConfig['name'] ?? '') !== $expectedDatabase
    ) {
        throw new RuntimeException(
            'PRECIOS-PRODUCTOS-GLOBAL-UI-1 configuration does not match the confirmed database.'
        );
    }

    $connection = new ConnectionProvider($databaseConfig);
    $GLOBALS['precios_productos_global_ui_connection'] = $connection;
    $GLOBALS['precios_productos_global_ui_config'] = $config;
    $pdo = $connection->pdo();
    $test = require BASE_PATH
        . '/database/tests/precios_productos_global_ui_1_test.php';

    if (!$test instanceof DatabaseTest) {
        throw new RuntimeException(
            'PRECIOS-PRODUCTOS-GLOBAL-UI-1 database test has an invalid contract.'
        );
    }

    echo json_encode(
        ['command' => $command, 'result' => $test->run($pdo, $expectedDatabase)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        'PRECIOS-PRODUCTOS-GLOBAL-UI-1 database operation failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
