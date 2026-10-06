<?php

declare(strict_types=1);

use App\Core\Env;
use App\Domain\Mail\QaMailContext;
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
        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        $options[$name] = $value;
    }

    if (!in_array($command, ['contract:test', 'db:test'], true)) {
        throw new RuntimeException('Unknown CORREO-SMTP-QA-OVERRIDE-IMPLEMENTACION-1 command.');
    }

    $requestedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');
    $event = trim($options['confirm-event'] ?? '');
    $confirmNoSend = $options['confirm-no-send'] ?? '';

    $config = require BASE_PATH . '/bootstrap/database.php';
    $databaseConfig = $config->get('database', []);
    if (!is_array($databaseConfig)) {
        throw new RuntimeException('SMTP QA database configuration is unavailable.');
    }

    $connection = new ConnectionProvider($databaseConfig);
    $pdo = $connection->pdo();
    $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $context = QaMailContext::fromCli(
        (string) Env::get('MAIL_TEST_RECIPIENT', ''),
        (string) $config->get('app.env', 'production'),
        $requestedDatabase,
        $confirmedDatabase,
        (string) ($databaseConfig['name'] ?? ''),
        $activeDatabase,
        $event,
        $confirmNoSend
    );

    [$localPart, $domain] = array_pad(explode('@', $context->recipient(), 2), 2, '');
    $safeContext = [
        'environment' => $context->environment(),
        'database' => $context->databaseName(),
        'event' => $context->event(),
        'recipient_configured' => true,
        'recipient_masked' => mb_substr($localPart, 0, 1, 'UTF-8') . '***@' . $domain,
        'to_count' => 1,
        'cc_count' => 0,
        'bcc_count' => 0,
        'processor_executed' => false,
        'smtp_connections' => 0,
        'emails_sent' => 0,
        'secret_resolutions' => 0,
    ];

    if ($command === 'contract:test') {
        $result = [
            'context' => $safeContext,
            'database_changed' => false,
            'write_refused_without_db_test' => true,
        ];
    } else {
        $GLOBALS['smtp_qa_override_connection'] = $connection;
        $GLOBALS['smtp_qa_override_context'] = $context;
        $test = require BASE_PATH . '/database/tests/correo_smtp_qa_override_implementacion_1_test.php';
        if (!$test instanceof DatabaseTest) {
            throw new RuntimeException('SMTP QA override DB test has an invalid contract.');
        }
        $result = [
            'context' => $safeContext,
            'test' => $test->run($pdo, QaMailContext::DATABASE),
        ];
    }

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, 'SMTP QA override DB test failed with SQLSTATE[' . $exception->getCode() . '].' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
