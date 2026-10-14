<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

try {
    $command = $argv[1] ?? '';
    if ($command !== 'db:test') {
        throw new RuntimeException('Unknown definitive product import command.');
    }
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--')) {
            throw new RuntimeException('Unexpected CLI argument.');
        }
        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        $options[$name] = $value;
    }
    $expected = trim((string) ($options['database'] ?? ''));
    $confirmed = trim((string) ($options['confirm-database'] ?? ''));
    if ($expected === '' || $expected !== $confirmed) {
        throw new RuntimeException('The database name must be confirmed twice.');
    }
    $config = require BASE_PATH . '/bootstrap/database.php';
    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('Definitive import tests are disabled in production.');
    }
    $database = $config->get('database', []);
    if (!is_array($database) || (string) ($database['name'] ?? '') !== $expected) {
        throw new RuntimeException('Configuration does not match the confirmed database.');
    }
    $pdo = (new ConnectionProvider($database))->pdo();
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
        throw new RuntimeException('The connected database does not match the confirmed database.');
    }
    $test = require BASE_PATH . '/database/tests/productos_importacion_definitiva_2_test.php';
    if (!is_callable($test)) {
        throw new RuntimeException('The definitive import test has an invalid contract.');
    }
    echo json_encode(
        ['result' => $test($pdo, $config, $expected)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, 'Definitive import DB test failed with SQLSTATE[' . $exception->getCode() . '].' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
