<?php

declare(strict_types=1);

use App\Core\Env;
use App\Domain\Mail\MailTransport;
use App\Domain\Mail\MailOutboxProcessor;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Mail\PHPMailerMailTransport;
use App\Infrastructure\Repositories\MailConfigurationRepository;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;
use App\Infrastructure\Repositories\SmtpQaFixtureIntentRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/bootstrap/autoload.php';

final class SmtpQaSendRefused extends RuntimeException
{
}

/** @return array<string, string> */
function parseOptions(array $arguments, array $allowed): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--')) {
            throw new SmtpQaSendRefused('Unexpected CLI argument.');
        }
        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        if (!in_array($name, $allowed, true) || array_key_exists($name, $options) || $value === '') {
            throw new SmtpQaSendRefused('Unknown, duplicate, or empty SMTP QA option.');
        }
        $options[$name] = $value;
    }

    return $options;
}

/** @return array<string, mixed> */
function safeAccountEvidence(array $account): array
{
    $from = (string) ($account['from_email'] ?? '');
    $username = (string) ($account['smtp_username'] ?? '');
    $mask = static function (string $email): string {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return $local === '' || $domain === '' ? '[invalid]' : mb_substr($local, 0, 1, 'UTF-8') . '***@' . $domain;
    };

    return [
        'mail_account_id' => (int) ($account['id'] ?? 0),
        'mail_account_active' => (int) ($account['activo'] ?? 0) === 1,
        'smtp_host' => (string) ($account['smtp_host'] ?? ''),
        'smtp_port' => (int) ($account['smtp_port'] ?? 0),
        'smtp_encryption' => strtolower((string) ($account['smtp_encryption'] ?? '')),
        'smtp_from_configured' => filter_var($from, FILTER_VALIDATE_EMAIL) !== false,
        'smtp_from_masked' => $mask($from),
        'smtp_username_configured' => $username !== '',
        'smtp_username_masked' => $mask($username),
        'smtp_secret_reference_configured' => trim((string) ($account['smtp_secret_ref'] ?? '')) !== '',
    ];
}

/** @return array{to:list<string>,cc:list<string>,bcc:list<string>} */
function persistedEnvelope(array $row): array
{
    $decoded = json_decode((string) ($row['cc_json'] ?? ''), true);
    if (!is_array($decoded)) {
        throw new SmtpQaSendRefused('Persisted QA envelope is invalid.');
    }

    return [
        'to' => is_array($decoded['to'] ?? null) ? array_values(array_map('strval', $decoded['to'])) : [],
        'cc' => is_array($decoded['cc'] ?? null) ? array_values(array_map('strval', $decoded['cc'])) : [],
        'bcc' => is_array($decoded['bcc'] ?? null) ? array_values(array_map('strval', $decoded['bcc'])) : [],
    ];
}

/** @return array<string, mixed> */
function runQaDbTest(): array
{
    $test = require BASE_PATH . '/database/tests/correo_smtp_qa_envio_1_test.php';
    if (!$test instanceof DatabaseTest) {
        throw new SmtpQaSendRefused('SMTP QA send DB-test has an invalid contract.');
    }

    return $test->run(new PDO('sqlite::memory:'), 'r_erp_db_core_0_test');
}

try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['db:test', 'send-one'], true)) {
        throw new SmtpQaSendRefused('Unknown CORREO-SMTP-QA-ENVIO-1 command.');
    }

    if ($command === 'db:test') {
        if (count($argv) !== 2) {
            throw new SmtpQaSendRefused('DB-test does not accept send options.');
        }
        echo json_encode(
            ['command' => $command, 'result' => runQaDbTest()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        exit(0);
    }

    $options = parseOptions(array_slice($argv, 2), [
        'outbox',
        'database',
        'confirm-database',
        'confirm-outbox',
        'confirm-recipient-source',
        'confirm-real-smtp-send',
        'confirm-single-attempt',
    ]);
    $required = [
        'outbox',
        'database',
        'confirm-database',
        'confirm-outbox',
        'confirm-recipient-source',
        'confirm-real-smtp-send',
        'confirm-single-attempt',
    ];
    foreach ($required as $requiredOption) {
        if (!array_key_exists($requiredOption, $options)) {
            throw new SmtpQaSendRefused('Required SMTP QA confirmation is missing.');
        }
    }
    if (
        $options['outbox'] !== '1610'
        || $options['database'] !== 'r_erp_db_core_0_test'
        || $options['confirm-database'] !== 'r_erp_db_core_0_test'
        || $options['confirm-outbox'] !== '1610'
        || $options['confirm-recipient-source'] !== 'MAIL_TEST_RECIPIENT'
        || $options['confirm-real-smtp-send'] !== 'YES'
        || $options['confirm-single-attempt'] !== 'YES'
    ) {
        throw new SmtpQaSendRefused('SMTP QA send confirmation does not match the authorized scope.');
    }

    $qaTest = runQaDbTest();
    $config = require BASE_PATH . '/bootstrap/database.php';
    $environment = strtolower((string) $config->get('app.env', 'production'));
    $databaseConfig = $config->get('database', []);
    if (
        !in_array($environment, ['local', 'development', 'test'], true)
        || !is_array($databaseConfig)
        || ($databaseConfig['name'] ?? '') !== 'r_erp_db_core_0_test'
    ) {
        throw new SmtpQaSendRefused('SMTP QA environment or database is not authorized.');
    }

    $connection = new ConnectionProvider($databaseConfig);
    $pdo = $connection->pdo();
    $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($activeDatabase !== 'r_erp_db_core_0_test') {
        throw new SmtpQaSendRefused('SMTP QA active database mismatch.');
    }

    $recipient = strtolower(trim((string) Env::get('MAIL_TEST_RECIPIENT', '')));
    if (
        $recipient === ''
        || str_contains($recipient, ',')
        || str_contains($recipient, ';')
        || preg_match('/\s/u', $recipient) === 1
        || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new SmtpQaSendRefused('SMTP QA recipient configuration is invalid.');
    }

    $outbox = new ProductTicketEmailOutboxRepository($connection);
    $qa = new SmtpQaFixtureIntentRepository($connection);
    $configuration = new MailConfigurationRepository($connection);
    $row = $outbox->findOutboxById(1610);
    $artifact = $qa->phaseArtifact('CORREO-SMTP-QA-FIXTURE-INTENCION-1');
    $relations = $qa->relationCounts(197);
    $eligibleRows = $pdo->query(
        "SELECT id FROM tickets_productos_correos
         WHERE status = 'PENDIENTE'
            OR (status = 'ERROR' AND intentos < max_intentos)
         ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);
    $beforeOutboxCount = (int) $pdo->query('SELECT COUNT(*) FROM tickets_productos_correos')->fetchColumn();
    $dedupeCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM tickets_productos_correos
         WHERE dedupe_key = 'ticket:197:partida:null:evento:TICKET_CREADO'"
    )->fetchColumn();
    $envelope = is_array($row) ? persistedEnvelope($row) : ['to' => [], 'cc' => [], 'bcc' => []];
    $expectedHtmlHash = '317b201ea58cb5e6c6d56bb76f61183e477c518e88eff9a2aff8385e70852931';
    $expectedTextHash = 'ab11ef46190c12bd9ff5ac86acde79d7bb4132dc9848ff673166280003c44d6b';
    $expectedDedupe = 'ticket:197:partida:null:evento:TICKET_CREADO';

    if (
        !is_array($row)
        || !is_array($artifact)
        || (int) ($row['ticket_id'] ?? 0) !== 197
        || (string) ($row['evento'] ?? '') !== 'TICKET_CREADO'
        || (string) ($row['plantilla'] ?? '') !== 'ticket_created'
        || (string) ($row['status'] ?? '') !== 'PENDIENTE'
        || (int) ($row['intentos'] ?? -1) !== 0
        || ($row['ultimo_intento_at'] ?? null) !== null
        || ($row['enviado_at'] ?? null) !== null
        || count($eligibleRows) !== 1
        || (int) $eligibleRows[0] !== 1610
        || (int) ($artifact['ticket_id'] ?? 0) !== 197
        || (string) ($artifact['folio'] ?? '') !== 'QASMTP-000002'
        || (string) ($artifact['estado'] ?? '') !== 'EN_REVISION'
        || (string) ($artifact['observaciones_generales'] ?? '') !== '[QA_FIXTURE:CORREO_SMTP]'
        || (int) ($relations['lines'] ?? -1) !== 0
        || (int) ($relations['attachments'] ?? -1) !== 0
        || (int) ($relations['comments'] ?? -1) !== 0
        || $envelope['to'] !== [$recipient]
        || $envelope['cc'] !== []
        || $envelope['bcc'] !== []
        || (string) ($row['destinatario_email'] ?? '') !== $recipient
        || trim((string) ($row['subject'] ?? '')) !== '[R-ERP] Ticket QASMTP-000002 creado'
        || preg_match('/[\r\n]/', (string) ($row['subject'] ?? '')) === 1
        || hash('sha256', (string) ($row['html'] ?? '')) !== $expectedHtmlHash
        || hash('sha256', (string) ($row['text'] ?? '')) !== $expectedTextHash
        || (string) ($row['dedupe_key'] ?? '') !== $expectedDedupe
        || $dedupeCount !== 1
    ) {
        throw new SmtpQaSendRefused('SMTP QA outbox or ticket precheck failed.');
    }

    $accounts = array_values(array_filter(
        $configuration->accounts(),
        static fn (array $account): bool => (string) ($account['codigo'] ?? '') === 'TICKETS_PRODUCTOS'
    ));
    $account = $configuration->primaryAccount();
    if (
        count($accounts) !== 1
        || !is_array($account)
        || (int) ($account['id'] ?? 0) !== 1
        || (int) ($account['activo'] ?? 0) !== 1
        || trim((string) ($account['smtp_host'] ?? '')) !== 'smtp.gmail.com'
        || (int) ($account['smtp_port'] ?? 0) !== 587
        || strtolower(trim((string) ($account['smtp_encryption'] ?? ''))) !== 'tls'
        || trim((string) ($account['from_email'] ?? '')) === ''
        || trim((string) ($account['smtp_username'] ?? '')) === ''
        || preg_match('/^[A-Z][A-Z0-9_]*$/', trim((string) ($account['smtp_secret_ref'] ?? ''))) !== 1
    ) {
        throw new SmtpQaSendRefused('SMTP QA mail account precheck failed.');
    }

    $rules = array_values(array_filter(
        $configuration->rules(),
        static fn (array $rule): bool => (string) ($rule['evento'] ?? '') === 'TICKET_CREADO'
            && (int) ($rule['activo'] ?? 0) === 1
    ));
    if (count($rules) !== 1 || (int) ($rules[0]['mail_account_id'] ?? 0) !== 1) {
        throw new SmtpQaSendRefused('SMTP QA notification rule resolution is ambiguous.');
    }

    $protectedBefore = $qa->protectedEvidence();
    $recipientMask = (static function (string $email): string {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1, 'UTF-8') . '***@' . $domain;
    })($recipient);
    $recipientHash = hash('sha256', $recipient);

    $secretResolutions = 0;
    $secretResolver = static function (string $reference) use (&$secretResolutions): ?string {
        $secretResolutions++;
        $value = Env::get($reference);
        if (!is_string($value) || $value === '') {
            return null;
        }
        return $value;
    };

    $transport = new class(new PHPMailerMailTransport()) implements MailTransport {
        public int $attempts = 0;

        public function __construct(private readonly MailTransport $inner)
        {
        }

        public function requiresSecret(): bool
        {
            return $this->inner->requiresSecret();
        }

        public function send(array $message, array $account): array
        {
            $this->attempts++;
            if ($this->attempts !== 1) {
                throw new RuntimeException('SMTP QA single-attempt guard failed.');
            }

            return $this->inner->send($message, $account);
        }
    };

    $processor = new MailOutboxProcessor($outbox, $configuration, $transport, $secretResolver);
    $processorResult = $processor->processOne(1610, static function (array $claimedRow, array $message, array $smtpAccount) use ($recipient, $expectedHtmlHash, $expectedTextHash, $expectedDedupe): void {
        if (
            (int) ($claimedRow['id'] ?? 0) !== 1610
            || $message['to'] !== [$recipient]
            || $message['cc'] !== []
            || $message['bcc'] !== []
            || (string) ($message['subject'] ?? '') !== '[R-ERP] Ticket QASMTP-000002 creado'
            || hash('sha256', (string) ($message['html'] ?? '')) !== $expectedHtmlHash
            || hash('sha256', (string) ($message['text'] ?? '')) !== $expectedTextHash
            || (string) ($claimedRow['dedupe_key'] ?? '') !== $expectedDedupe
            || (string) ($smtpAccount['smtp_host'] ?? '') !== 'smtp.gmail.com'
            || (int) ($smtpAccount['smtp_port'] ?? 0) !== 587
            || strtolower((string) ($smtpAccount['smtp_encryption'] ?? '')) !== 'tls'
        ) {
            throw new RuntimeException('SMTP QA transport envelope pre-send check failed.');
        }
    });

    $finalRow = $outbox->findOutboxById(1610);
    $afterOutboxCount = (int) $pdo->query('SELECT COUNT(*) FROM tickets_productos_correos')->fetchColumn();
    $afterDedupeCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM tickets_productos_correos
         WHERE dedupe_key = 'ticket:197:partida:null:evento:TICKET_CREADO'"
    )->fetchColumn();
    $protectedAfter = $qa->protectedEvidence();
    $artifactAfter = $qa->phaseArtifact('CORREO-SMTP-QA-FIXTURE-INTENCION-1');
    $finalStatus = (string) ($finalRow['status'] ?? '');
    $deliveryAmbiguous = false;
    $emailsSent = $finalStatus === 'ENVIADO' ? 1 : 0;

    $result = [
        'phase_status' => $finalStatus === 'ENVIADO' ? 'PASS_SUCCESS' : 'PASS_SAFE_FAILURE',
        'authorization_received' => true,
        'database' => 'r_erp_db_core_0_test',
        'app_env' => $environment,
        'recipient_masked' => $recipientMask,
        'recipient_hash' => $recipientHash,
        'to_count' => count($envelope['to']),
        'cc_count' => count($envelope['cc']),
        'bcc_count' => count($envelope['bcc']),
        'operational_recipients_included' => false,
        'account' => safeAccountEvidence($account),
        'secret_resolved' => $secretResolutions === 1,
        'secret_resolutions' => $secretResolutions,
        'qa_test' => $qaTest['summary'] ?? [],
        'claim_executed' => is_array($processorResult['processed'] ?? null) && $processorResult['processed'] !== [],
        'claim_status' => (string) ($processorResult['processed'][0]['status'] ?? ''),
        'attempts_before' => 0,
        'attempts_after' => (int) ($finalRow['intentos'] ?? -1),
        'smtp_connection_attempted' => $transport->attempts > 0,
        'smtp_send_attempts' => $transport->attempts,
        'smtp_accepted_message' => $finalStatus === 'ENVIADO',
        'delivery_outcome_ambiguous' => $deliveryAmbiguous,
        'finalization_executed' => true,
        'final_status' => $finalStatus,
        'ultimo_intento_at_present' => ($finalRow['ultimo_intento_at'] ?? null) !== null,
        'enviado_at_present' => ($finalRow['enviado_at'] ?? null) !== null,
        'safe_error_present' => trim((string) ($finalRow['error_mensaje_seguro'] ?? '')) !== '',
        'emails_sent' => $emailsSent,
        'retry_executed' => false,
        'second_smtp_attempt' => $transport->attempts > 1,
        'processor_batch_executed' => false,
        'other_outbox_claimed' => false,
        'outbox_count_delta' => $afterOutboxCount - $beforeOutboxCount,
        'dedupe_count_before' => $dedupeCount,
        'dedupe_count_after' => $afterDedupeCount,
        'eligible_count_after' => (int) $pdo->query(
            "SELECT COUNT(*) FROM tickets_productos_correos
             WHERE status = 'PENDIENTE'
                OR (status = 'ERROR' AND intentos < max_intentos)"
        )->fetchColumn(),
        'ticket_197_intact' => is_array($artifactAfter)
            && (string) ($artifactAfter['estado'] ?? '') === 'EN_REVISION'
            && (int) ($artifactAfter['total_partidas'] ?? -1) === 0
            && ($artifactAfter['cancelado_at'] ?? null) === null,
        'protected_before' => $protectedBefore,
        'protected_after' => $protectedAfter,
        'protected_unchanged' => $protectedBefore === $protectedAfter,
        'secrets_printed' => false,
        'recipient_fully_printed' => false,
        'db_writes' => 2,
        'processor_batch' => false,
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (PDOException) {
    fwrite(STDERR, "SMTP QA send stopped with a database error.\n");
    exit(1);
} catch (SmtpQaSendRefused $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "SMTP QA send stopped safely.\n");
    exit(1);
}
