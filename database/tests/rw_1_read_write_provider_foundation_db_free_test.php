<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('BASE_PATH', $root);
require_once $root . '/bootstrap/autoload.php';

use App\Core\Env;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\ReadConnectionProvider;
use App\Infrastructure\Database\WriteConnectionProvider;

$keys = [
    'APP_DB_HOST',
    'APP_DB_PORT',
    'APP_DB_NAME',
    'APP_DB_CHARSET',
    'APP_DB_COLLATION',
    'APP_DB_USER',
    'APP_DB_PASSWORD',
    'APP_DB_READ_USER',
    'APP_DB_READ_PASSWORD',
    'APP_DB_WRITE_USER',
    'APP_DB_WRITE_PASSWORD',
];
$snapshot = [];

$snapshotEnv = static function (array $keys): array {
    $snapshot = [];

    foreach ($keys as $key) {
        $environmentValue = getenv($key);
        $snapshot[$key] = [
            'environment' => [
                'exists' => $environmentValue !== false,
                'value' => $environmentValue === false ? null : (string) $environmentValue,
            ],
            'env' => [
                'exists' => array_key_exists($key, $_ENV),
                'value' => array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null,
            ],
            'server' => [
                'exists' => array_key_exists($key, $_SERVER),
                'value' => array_key_exists($key, $_SERVER) ? (string) $_SERVER[$key] : null,
            ],
        ];
    }

    return $snapshot;
};

$restoreEnv = static function (array $snapshot): void {
    foreach ($snapshot as $key => $sources) {
        if ($sources['environment']['exists']) {
            putenv($key . '=' . $sources['environment']['value']);
        } else {
            putenv($key);
        }

        if ($sources['env']['exists']) {
            $_ENV[$key] = $sources['env']['value'];
        } else {
            unset($_ENV[$key]);
        }

        if ($sources['server']['exists']) {
            $_SERVER[$key] = $sources['server']['value'];
        } else {
            unset($_SERVER[$key]);
        }
    }
};

$assertEnvMatchesSnapshot = static function (array $snapshot): void {
    foreach ($snapshot as $key => $sources) {
        $environmentValue = getenv($key);
        if (($environmentValue !== false) !== $sources['environment']['exists']) {
            throw new RuntimeException('Environment restoration failed for ' . $key . '.');
        }
        if ($sources['environment']['exists'] && (string) $environmentValue !== $sources['environment']['value']) {
            throw new RuntimeException('Environment value restoration failed for ' . $key . '.');
        }

        $envExists = array_key_exists($key, $_ENV);
        if ($envExists !== $sources['env']['exists']) {
            throw new RuntimeException('$_ENV restoration failed for ' . $key . '.');
        }
        if ($envExists && (string) $_ENV[$key] !== $sources['env']['value']) {
            throw new RuntimeException('$_ENV value restoration failed for ' . $key . '.');
        }

        $serverExists = array_key_exists($key, $_SERVER);
        if ($serverExists !== $sources['server']['exists']) {
            throw new RuntimeException('$_SERVER restoration failed for ' . $key . '.');
        }
        if ($serverExists && (string) $_SERVER[$key] !== $sources['server']['value']) {
            throw new RuntimeException('$_SERVER value restoration failed for ' . $key . '.');
        }
    }
};

$snapshot = $snapshotEnv($keys);

foreach ($keys as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

$set = static function (string $key, ?string $value): void {
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        return;
    }
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$absentProbeKey = 'RW_1_ABSENT_ENV_RESTORE_PROBE';
putenv($absentProbeKey);
unset($_ENV[$absentProbeKey], $_SERVER[$absentProbeKey]);
$absentProbeSnapshot = $snapshotEnv([$absentProbeKey]);
putenv($absentProbeKey . '=temporary-fixture');
$_ENV[$absentProbeKey] = 'temporary-fixture';
$_SERVER[$absentProbeKey] = 'temporary-fixture';
$restoreEnv($absentProbeSnapshot);
$assert(getenv($absentProbeKey) === false, 'RW-ENV-01 absent environment variable was not removed.');
$assert(!array_key_exists($absentProbeKey, $_ENV), 'RW-ENV-01 absent $_ENV variable was not removed.');
$assert(!array_key_exists($absentProbeKey, $_SERVER), 'RW-ENV-01 absent $_SERVER variable was not removed.');

try {
    $set('APP_DB_HOST', 'localhost');
    $set('APP_DB_PORT', '3306');
    $set('APP_DB_NAME', 'rw_foundation_db');
    $set('APP_DB_CHARSET', 'utf8mb4');
    $set('APP_DB_COLLATION', 'utf8mb4_unicode_ci');
    $set('APP_DB_USER', 'legacy_read');
    $set('APP_DB_PASSWORD', 'legacy-read-secret');
    $set('APP_DB_READ_USER', 'explicit_read');
    $set('APP_DB_READ_PASSWORD', 'explicit-read-secret');
    $set('APP_DB_WRITE_USER', 'explicit_write');
    $set('APP_DB_WRITE_PASSWORD', 'explicit-write-secret');

    $configPath = $root . '/config/database.php';
    $config = require $configPath;
    $assert(is_array($config['common'] ?? null), 'RW-CONFIG-01 common shape missing.');
    $assert(is_array($config['read'] ?? null), 'RW-CONFIG-01 read shape missing.');
    $assert(is_array($config['write'] ?? null), 'RW-CONFIG-01 write shape missing.');
    $assert($config['read']['user'] === 'explicit_read', 'RW-CONFIG-02 read user precedence failed.');
    $assert($config['read']['password'] === 'explicit-read-secret', 'RW-CONFIG-04 read password precedence failed.');
    $assert($config['write']['user'] === 'explicit_write', 'RW-CONFIG-05 write user source failed.');
    $assert($config['write']['password'] === 'explicit-write-secret', 'RW-CONFIG-06 write password source failed.');

    $set('APP_DB_READ_USER', null);
    $set('APP_DB_READ_PASSWORD', null);
    $set('APP_DB_WRITE_USER', null);
    $set('APP_DB_WRITE_PASSWORD', null);
    $legacyConfig = require $configPath;
    $assert($legacyConfig['read']['user'] === 'legacy_read', 'RW-CONFIG-03 legacy read user fallback failed.');
    $assert($legacyConfig['read']['password'] === 'legacy-read-secret', 'RW-CONFIG-04 legacy read password fallback failed.');

    $legacyOnlyConfig = $legacyConfig;
    $assert($legacyOnlyConfig['write']['user'] === '', 'RW-CONFIG-07 legacy user leaked into write config.');
    $assert($legacyOnlyConfig['write']['password'] === '', 'RW-CONFIG-07 legacy password leaked into write config.');
    $assert($legacyOnlyConfig['read']['user'] === 'legacy_read', 'RW-CONFIG-07 legacy read user was not effective.');
    $assert($legacyOnlyConfig['read']['password'] === 'legacy-read-secret', 'RW-CONFIG-07 legacy read password was not effective.');

    $assert(method_exists(ReadConnectionProvider::class, 'pdo'), 'RW-PROVIDER-01 read API missing.');
    $assert(method_exists(WriteConnectionProvider::class, 'pdo'), 'RW-PROVIDER-02 write API missing.');
    $assert((new ReadConnectionProvider($legacyConfig)) instanceof ReadConnectionProvider, 'Read provider construction failed.');
    $assert((new WriteConnectionProvider($config)) instanceof WriteConnectionProvider, 'Write provider construction failed.');
    $assert((new ConnectionProvider($legacyConfig)) instanceof ConnectionProvider, 'RW-LEGACY-01 legacy provider changed.');

    $missingUser = $config;
    $missingUser['write']['user'] = '';
    try {
        (new WriteConnectionProvider($missingUser))->pdo();
        throw new RuntimeException('RW-PROVIDER-03 missing write user was accepted.');
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() === 'Write database credentials are not configured.', 'Unexpected missing-user error.');
        $assert(!str_contains($exception->getMessage(), 'explicit-write-secret'), 'RW-PROVIDER-05 secret leaked.');
    }

    $missingPassword = $config;
    $missingPassword['write']['password'] = '';
    try {
        (new WriteConnectionProvider($missingPassword))->pdo();
        throw new RuntimeException('RW-PROVIDER-04 missing write password was accepted.');
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() === 'Write database credentials are not configured.', 'Unexpected missing-password error.');
        $assert(!str_contains($exception->getMessage(), 'explicit-write-secret'), 'RW-PROVIDER-05 secret leaked.');
    }

    $readOnlyCredentials = $legacyOnlyConfig;
    try {
        (new WriteConnectionProvider($readOnlyCredentials))->pdo();
        throw new RuntimeException('RW-PROVIDER-07 READ credentials enabled WRITE.');
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() === 'Write database credentials are not configured.', 'RW-PROVIDER-07 unexpected error.');
    }

    $source = (string) file_get_contents($root . '/app/Infrastructure/Database/Connection.php');
    $assert(!str_contains($source, 'ReadConnectionProvider'), 'RW-CONNECTION-01 routing leaked into Connection.php.');
    $assert(!str_contains($source, 'WriteConnectionProvider'), 'RW-CONNECTION-01 routing leaked into Connection.php.');
    $assert(str_contains($source, 'new PDO'), 'RW-CONNECTION-02 Connection::create no longer creates PDO.');
    $assert(str_contains((string) file_get_contents($root . '/app/Infrastructure/Database/ReadConnectionProvider.php'), 'Connection::create'), 'Read provider does not use Connection::create.');
    $assert(str_contains((string) file_get_contents($root . '/app/Infrastructure/Database/WriteConnectionProvider.php'), 'Connection::create'), 'Write provider does not use Connection::create.');

    echo "RW_1_DB_FREE_TESTS=PASS\n";
} finally {
    $restoreEnv($snapshot);
}

$assertEnvMatchesSnapshot($snapshot);
