<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\MigrationRunner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

try {
    $command = $argv[1] ?? '';
    if ($command !== 'db:test') {
        throw new RuntimeException('Unknown PRODUCTOS-4 idempotency CLI command.');
    }

    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--')) {
            throw new RuntimeException('Unexpected CLI argument.');
        }
        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        $options[$name] = $value;
    }

    $expected = trim($options['database'] ?? '');
    $confirmed = trim($options['confirm-database'] ?? '');
    if ($expected === '' || $expected !== $confirmed) {
        throw new RuntimeException('The database name must be confirmed twice.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';
    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('Product import idempotency tests are disabled in production.');
    }
    $databaseConfig = $config->get('database', []);
    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $expected) {
        throw new RuntimeException('Configuration does not match the confirmed database.');
    }

    $pdo = (new ConnectionProvider($databaseConfig))->pdo();
    $migration = require BASE_PATH
        . '/database/migrations/productos_importacion_idempotencia_1_001_create_producto_importaciones.php';
    $migrationStatus = (new MigrationRunner($pdo))->migrate($migration);
    $test = require BASE_PATH . '/database/tests/productos_importacion_idempotencia_1_test.php';
    if (!is_callable($test)) {
        throw new RuntimeException('The idempotency test has an invalid contract.');
    }

    echo json_encode(
        ['migration' => $migrationStatus, 'result' => $test($pdo, $config, $expected)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, 'Product import idempotency DB test failed with SQLSTATE[' . $exception->getCode() . '].' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
