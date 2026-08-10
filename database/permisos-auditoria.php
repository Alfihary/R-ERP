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

require_once BASE_PATH . '/app/Support/Security/helpers.php';

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
        throw new RuntimeException('Unknown PERMISOS-AUDITORIA-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if ($expectedDatabase === '' || $expectedDatabase !== $confirmedDatabase) {
        throw new RuntimeException('The database name must be confirmed twice.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('PERMISOS-AUDITORIA-1 CLI commands are disabled in production.');
    }

    $databaseConfig = $config->get('database', []);

    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $expectedDatabase) {
        throw new RuntimeException(
            'PERMISOS-AUDITORIA-1 configuration does not match the confirmed database.'
        );
    }

    $connection = new ConnectionProvider($databaseConfig);
    $GLOBALS['permisos_auditoria_connection'] = $connection;
    $GLOBALS['permisos_auditoria_config'] = $config;
    $pdo = $connection->pdo();
    $seed = require BASE_PATH . '/database/seeds/permisos_auditoria_1_seed.php';
    $test = require BASE_PATH . '/database/tests/permisos_auditoria_1_test.php';

    if (!$seed instanceof Seed) {
        throw new RuntimeException('PERMISOS-AUDITORIA-1 seed has an invalid contract.');
    }

    if (!$test instanceof DatabaseTest) {
        throw new RuntimeException('PERMISOS-AUDITORIA-1 test has an invalid contract.');
    }

    $result = match ($command) {
        'seed' => (static function () use ($pdo, $seed): array {
            $seed->run($pdo);

            return [
                'seed' => $seed->id(),
                'result' => 'applied',
            ];
        })(),
        'db:test' => $test->run($pdo, $expectedDatabase),
    };

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        'PERMISOS-AUDITORIA-1 database operation failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
