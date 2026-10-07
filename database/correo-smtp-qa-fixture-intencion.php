<?php

declare(strict_types=1);

use App\Core\Env;
use App\Domain\Mail\MailConfigurationService;
use App\Domain\Mail\ProductTicketEmailTemplatePayloadBuilder;
use App\Domain\Mail\ProductTicketEmailTemplateRenderer;
use App\Domain\Mail\QaMailContext;
use App\Domain\Mail\SmtpQaFixtureIntentService;
use App\Domain\Tickets\ProductTicketEmailNotificationService;
use App\Domain\Tickets\ProductTicketEmailOutboxService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\MailConfigurationRepository;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;
use App\Infrastructure\Repositories\SmtpQaFixtureIntentRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['audit', 'db:test', 'create'], true)) {
        throw new RuntimeException('Unknown CORREO-SMTP-QA-FIXTURE-INTENCION-1 command.');
    }

    $allowed = ['database', 'confirm-database', 'confirm-event', 'confirm-no-send'];
    if ($command === 'create') {
        $allowed[] = 'confirm-create-qa-fixture';
    }
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--')) {
            throw new RuntimeException('Unexpected CLI argument.');
        }
        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        if (!in_array($name, $allowed, true) || array_key_exists($name, $options)) {
            throw new RuntimeException('Unknown or duplicate SMTP QA fixture option.');
        }
        $options[$name] = $value;
    }

    $config = require BASE_PATH . '/bootstrap/database.php';
    $databaseConfig = $config->get('database', []);
    if (!is_array($databaseConfig)) {
        throw new RuntimeException('SMTP QA database configuration is unavailable.');
    }

    $connection = new ConnectionProvider($databaseConfig);
    $activeDatabase = (string) $connection->pdo()->query('SELECT DATABASE()')->fetchColumn();
    $context = QaMailContext::fromCli(
        (string) Env::get('MAIL_TEST_RECIPIENT', ''),
        (string) $config->get('app.env', 'production'),
        trim((string) ($options['database'] ?? '')),
        trim((string) ($options['confirm-database'] ?? '')),
        (string) ($databaseConfig['name'] ?? ''),
        $activeDatabase,
        trim((string) ($options['confirm-event'] ?? '')),
        (string) ($options['confirm-no-send'] ?? '')
    );

    $qaRepository = new SmtpQaFixtureIntentRepository($connection);
    $notificationService = new ProductTicketEmailNotificationService(
        new MailConfigurationService(new MailConfigurationRepository($connection)),
        new ProductTicketEmailOutboxService(
            new ProductTicketEmailOutboxRepository($connection),
            new ProductTicketEmailTemplateRenderer(),
            new ProductTicketEmailTemplatePayloadBuilder(
                (string) $config->get('app.url', ''),
                (string) $config->get('app.timezone', 'America/Mexico_City'),
                $context->environment() !== 'production'
            )
        )
    );
    $service = new SmtpQaFixtureIntentService(
        new ProductRequestTicketRepository($connection),
        $qaRepository,
        $notificationService
    );

    if ($command === 'audit') {
        $result = $service->audit($context);
    } elseif ($command === 'db:test') {
        $GLOBALS['smtp_qa_fixture_connection'] = $connection;
        $GLOBALS['smtp_qa_fixture_context'] = $context;
        $GLOBALS['smtp_qa_fixture_service'] = $service;
        $GLOBALS['smtp_qa_fixture_repository'] = $qaRepository;
        $GLOBALS['smtp_qa_fixture_notifications'] = $notificationService;
        $test = require BASE_PATH . '/database/tests/correo_smtp_qa_fixture_intencion_1_test.php';
        if (!$test instanceof DatabaseTest) {
            throw new RuntimeException('SMTP QA fixture DB test has an invalid contract.');
        }
        $result = $test->run($connection->pdo(), QaMailContext::DATABASE);
    } else {
        $result = $service->create(
            $context,
            (string) ($options['confirm-create-qa-fixture'] ?? '')
        );
    }

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, 'SMTP QA fixture failed with SQLSTATE[' . $exception->getCode() . '].' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
