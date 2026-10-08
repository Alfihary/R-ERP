<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Domain\Tickets\ProductRequestTicketValidationException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/bootstrap/autoload.php';
require BASE_PATH . '/app/Support/Security/helpers.php';

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

    if ($command !== 'functional:test') {
        throw new RuntimeException('Unknown TP-PARTIDAS-ESTADOS-MULTIPARTIDA-RESPUESTAS-UI-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if ($expectedDatabase === '' || $expectedDatabase !== $confirmedDatabase) {
        throw new RuntimeException('The database name must be confirmed twice.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('TP-PARTIDAS-ESTADOS-MULTIPARTIDA-RESPUESTAS-UI-1 CLI commands are disabled in production.');
    }

    $databaseConfig = $config->get('database', []);

    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $expectedDatabase) {
        throw new RuntimeException('TP-PARTIDAS-ESTADOS-MULTIPARTIDA-RESPUESTAS-UI-1 configuration does not match the confirmed database.');
    }

    $connection = new ConnectionProvider($databaseConfig);
    $GLOBALS['tp_product_ticket_multipart_connection'] = $connection;
    $pdo = $connection->pdo();
    $runner = new MigrationRunner($pdo);
    $baseMigration = require BASE_PATH . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';
    $approvalMigration = require BASE_PATH . '/database/migrations/tp_partidas_estados_aprobacion_captura_1_001_add_authorized_line_fields.php';
    $test = require BASE_PATH . '/database/tests/tickets_productos_partidas_estados_multipartida_respuestas_ui_1_test.php';

    if (!$baseMigration instanceof Migration || !$approvalMigration instanceof Migration) {
        throw new RuntimeException('TP-PARTIDAS-ESTADOS-MULTIPARTIDA-RESPUESTAS-UI-1 migration contract is invalid.');
    }

    if (!$test instanceof DatabaseTest) {
        throw new RuntimeException('TP-PARTIDAS-ESTADOS-MULTIPARTIDA-RESPUESTAS-UI-1 test has an invalid contract.');
    }

    $baseState = $runner->migrate($baseMigration);
    if (!tpMultipartApprovalColumnsExist($pdo) && tpMultipartMigrationRowExists($pdo, $approvalMigration->id())) {
        $runner->rollback($approvalMigration);
    }
    $approvalState = $runner->migrate($approvalMigration);
    $result = $test->run($pdo, $expectedDatabase) + [
        'base_migration' => $baseMigration->id(),
        'base_migration_state' => $baseState,
        'approval_migration' => $approvalMigration->id(),
        'approval_migration_state' => $approvalState,
    ];

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        'TP-PARTIDAS-ESTADOS-MULTIPARTIDA-RESPUESTAS-UI-1 database operation failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (ProductRequestTicketValidationException $exception) {
    fwrite(
        STDERR,
        'TP-PARTIDAS-ESTADOS-MULTIPARTIDA-RESPUESTAS-UI-1 validation failed: '
        . json_encode($exception->errors(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

function tpMultipartApprovalColumnsExist(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        <<<'SQL'
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = :table
          AND column_name IN (
              'clave_autorizada',
              'descripcion_autorizada',
              'unidad_sat_id_autorizada',
              'clave_sat_id_autorizada'
          )
        SQL
    );
    $statement->execute(['table' => 'tickets_productos_partidas']);

    return (int) $statement->fetchColumn() === 4;
}

function tpMultipartMigrationRowExists(PDO $pdo, string $migration): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM schema_migrations
         WHERE migration = :migration'
    );
    $statement->execute(['migration' => $migration]);

    return (int) $statement->fetchColumn() === 1;
}
