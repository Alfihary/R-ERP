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
        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        $options[$name] = $value;
    }

    if (!in_array($command, ['seed', 'rollback', 'functional:test'], true)) {
        throw new RuntimeException('Unknown CORREO-OUTBOX-UI-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');
    if ($expectedDatabase !== 'r_erp_db_core_0_test' || $confirmedDatabase !== $expectedDatabase) {
        throw new RuntimeException('The mail outbox UI database must be confirmed exactly.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';
    require_once BASE_PATH . '/app/Support/Security/helpers.php';
    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('CORREO-OUTBOX-UI-1 commands are disabled in production.');
    }
    $databaseConfig = $config->get('database', []);
    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $expectedDatabase) {
        throw new RuntimeException('Mail outbox UI configuration does not match the confirmed database.');
    }

    $connection = new ConnectionProvider($databaseConfig);
    $pdo = $connection->pdo();
    $GLOBALS['correo_outbox_ui_connection'] = $connection;
    $GLOBALS['correo_outbox_ui_config'] = $config;
    $seed = require BASE_PATH . '/database/seeds/correo_outbox_ui_1_seed_permissions.php';
    $test = require BASE_PATH . '/database/tests/correo_outbox_ui_1_test.php';
    if (!$seed instanceof Seed || !$test instanceof DatabaseTest) {
        throw new RuntimeException('CORREO-OUTBOX-UI-1 test contract is invalid.');
    }

    $result = match ($command) {
        'seed' => (static function () use ($seed, $pdo): array {
            $seed->run($pdo);
            return ['seed' => $seed->id(), 'result' => 'applied'];
        })(),
        'rollback' => (static function () use ($seed, $pdo): array {
            $seed->rollback($pdo);
            return ['seed' => $seed->id(), 'result' => 'rolled_back'];
        })(),
        'functional:test' => $test->run($pdo, $expectedDatabase),
    };

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, 'CORREO-OUTBOX-UI-1 DB operation failed with SQLSTATE[' . $exception->getCode() . '].' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
