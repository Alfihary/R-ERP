<?php

declare(strict_types=1);

use App\Core\Env;
use App\Domain\Mail\MailOutboxProcessor;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Mail\FakeMailTransport;
use App\Infrastructure\Mail\PHPMailerMailTransport;
use App\Infrastructure\Repositories\MailConfigurationRepository;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;

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

    if (!in_array($command, ['functional:test', 'dry-run', 'process'], true)) {
        throw new RuntimeException('Unknown TP-PARTIDAS-ESTADOS-CORREO-PROCESADOR-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');
    if ($expectedDatabase !== 'r_erp_db_core_0_test' || $confirmedDatabase !== $expectedDatabase) {
        throw new RuntimeException('The mail processor database must be confirmed exactly.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';
    $environment = strtolower((string) $config->get('app.env', 'production'));
    if (!in_array($environment, ['local', 'development', 'test'], true)) {
        throw new RuntimeException('Mail processor commands are disabled in this environment.');
    }
    $databaseConfig = $config->get('database', []);
    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $expectedDatabase) {
        throw new RuntimeException('Mail processor configuration does not match the confirmed database.');
    }

    $limit = isset($options['limit']) ? filter_var($options['limit'], FILTER_VALIDATE_INT) : 10;
    if (!is_int($limit) || $limit < 1 || $limit > MailOutboxProcessor::MAX_BATCH_SIZE) {
        throw new RuntimeException('The mail processor limit must be between 1 and 10.');
    }

    $connection = new ConnectionProvider($databaseConfig);

    if ($command === 'functional:test') {
        $GLOBALS['tp_mail_processor_connection'] = $connection;
        $test = require BASE_PATH . '/database/tests/tickets_productos_partidas_estados_correo_procesador_1_test.php';
        if (!$test instanceof DatabaseTest) {
            throw new RuntimeException('Mail processor functional test has an invalid contract.');
        }
        $result = $test->run($connection->pdo(), $expectedDatabase);
    } else {
        $transport = $command === 'process'
            ? new PHPMailerMailTransport()
            : new FakeMailTransport();
        $secretResolver = $command === 'process'
            ? static fn (string $key): ?string => Env::get($key)
            : static function (): ?string {
                throw new RuntimeException('Dry-run must not resolve SMTP secrets.');
            };
        $processor = new MailOutboxProcessor(
            new ProductTicketEmailOutboxRepository($connection),
            new MailConfigurationRepository($connection),
            $transport,
            $secretResolver
        );

        if ($command === 'process') {
            if (($options['confirm-real-email'] ?? '') !== 'YES') {
                throw new RuntimeException('Real email processing requires --confirm-real-email=YES.');
            }
            $result = $processor->process($limit);
        } else {
            $result = [
                'eligible' => $processor->dryRun($limit),
                'database_changed' => false,
                'secret_resolved' => false,
                'smtp_used' => false,
            ];
        }
    }

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, 'Mail processor DB operation failed with SQLSTATE[' . $exception->getCode() . '].' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
