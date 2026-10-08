<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));
try {
    $command = $argv[1] ?? '';
    $options = [];
    foreach (array_slice($argv, 2) as $arg) {
        if (!str_starts_with($arg, '--')) throw new RuntimeException('Invalid argument.');
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $options[$key] = $value;
    }
    if (!in_array($command, ['migrate', 'rollback', 'db:test', 'status'], true)) throw new RuntimeException('Usage: php database/passkeys.php migrate|rollback|db:test|status --database=NAME --confirm-database=NAME');
    $name = $options['database'] ?? '';
    if ($name === '' || $name !== ($options['confirm-database'] ?? '')) throw new RuntimeException('Confirm database twice.');
    $config = require BASE_PATH . '/bootstrap/database.php';
    if ($config->get('app.env') === 'production' || $config->get('database.name') !== $name) throw new RuntimeException('Configuration mismatch or production environment.');
    if ($command === 'db:test' && !str_ends_with($name, '_test')) throw new RuntimeException('DB-TEST requires a disposable database ending in _test.');
    $pdo = App\Infrastructure\Database\Connection::create($config->get('database'));
    $runner = new App\Infrastructure\Database\MigrationRunner($pdo);
    $migrationV1 = require __DIR__ . '/migrations/passkeys_mobile_1_001_create_user_passkeys.php';
    $migrationV2 = require __DIR__ . '/migrations/passkeys_security_2_001_add_credential_name.php';
    $result = match ($command) {
        'migrate' => ['v1' => $runner->migrate($migrationV1), 'v2' => $runner->migrate($migrationV2)],
        'rollback' => $runner->rollback($migrationV2),
        'status' => $runner->status(),
        'db:test' => [
            'v1' => (require __DIR__ . '/tests/passkeys_mobile_1_test.php')->run($pdo, $name),
            'v2' => (require __DIR__ . '/tests/passkeys_security_2_test.php')->run($pdo, $name),
        ],
    };
    echo json_encode(['database' => $name, 'result' => $result], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (PDOException $e) { fwrite(STDERR, 'Database failure: SQLSTATE ' . $e->getCode() . PHP_EOL); exit(1); }
catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
