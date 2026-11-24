<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Domain/Tickets/ProductTicketEmailOutboxRepositoryContract.php';

use App\Domain\Tickets\ProductTicketEmailOutboxRepositoryContract;
use App\Domain\Mail\MailConfigurationService;
use App\Domain\Mail\ProductTicketEmailTemplatePayloadBuilder;
use App\Domain\Mail\ProductTicketEmailTemplateRenderer;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Domain\Tickets\ProductTicketEmailNotificationService;
use App\Domain\Tickets\ProductTicketEmailOutboxService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\MailConfigurationRepository;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;

/**
 * Isolated QA harness contract for Ticket + Outbox atomicity.
 *
 * Runtime is explicit, doubly confirmed and restricted to the isolated QA DB.
 * Plan and contract modes never open PDO. Only enqueue persistence is tested.
 */

const TP_ATOMICITY_QA_DATABASE = 'r_erp_tickets_qa_2f';
const TP_ATOMICITY_QA_PROTECTED_DATABASE = 'r_erp_db_core_0_test';
const TP_ATOMICITY_QA_ACCOUNT = 'r_erp_tickets_qa_runner@localhost';
const TP_ATOMICITY_QA_HOST = 'GER005OTU';
const TP_ATOMICITY_QA_PORT = 3306;
const TP_ATOMICITY_QA_CONFIRMATION = 'YES_I_UNDERSTAND_THIS_WRITES_QA_DATA';

/** @return list<string> */
function tpAtomicityQaCaseIds(): array
{
    return ['QA-A', 'QA-B', 'QA-C', 'QA-D', 'QA-E', 'QA-F', 'QA-G', 'QA-H'];
}

/** @return list<array<string, mixed>> */
function tpAtomicityQaScenarioCatalog(): array
{
    return [
        ['id' => 'QA-A', 'operation' => 'crearTicket', 'failure_injected' => false, 'expected' => 'COMMIT_WITH_OUTBOX'],
        ['id' => 'QA-B', 'operation' => 'crearTicket', 'failure_injected' => true, 'expected' => 'ROLLBACK_NO_OUTBOX'],
        ['id' => 'QA-C', 'operation' => 'resolverPartida', 'failure_injected' => false, 'expected' => 'COMMIT_WITH_OUTBOX'],
        ['id' => 'QA-D', 'operation' => 'resolverPartida', 'failure_injected' => true, 'expected' => 'ROLLBACK_NO_OUTBOX'],
        ['id' => 'QA-E', 'operation' => 'cancelarTicket', 'failure_injected' => false, 'expected' => 'COMMIT_WITH_OUTBOX'],
        ['id' => 'QA-F', 'operation' => 'cancelarTicket', 'failure_injected' => true, 'expected' => 'ROLLBACK_NO_OUTBOX'],
        ['id' => 'QA-G', 'operation' => 'legitimateSkip', 'failure_injected' => false, 'expected' => 'COMMIT_WITHOUT_OUTBOX'],
        ['id' => 'QA-H', 'operation' => 'dedupe', 'failure_injected' => false, 'expected' => 'ONE_OUTBOX_ROW'],
    ];
}

/** @return array<string, string> */
function tpAtomicityQaParseArguments(array $argv): array
{
    array_shift($argv);
    if (($argv[0] ?? null) !== '--prepare') {
        throw new InvalidArgumentException('Explicit --prepare is required; operational execution is disabled.');
    }

    $allowed = ['database', 'confirm-database', 'confirmation', 'case'];
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('Invalid argument.');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($name, $allowed, true) || isset($options[$name]) || trim($value) === '') {
            throw new InvalidArgumentException('Unknown, duplicate, or empty argument.');
        }
        $options[$name] = $value;
    }

    foreach (['database', 'confirm-database', 'confirmation', 'case'] as $name) {
        if (!isset($options[$name])) {
            throw new InvalidArgumentException('Required argument is missing.');
        }
    }
    if ($options['database'] !== TP_ATOMICITY_QA_DATABASE
        || $options['confirm-database'] !== TP_ATOMICITY_QA_DATABASE
        || $options['database'] === TP_ATOMICITY_QA_PROTECTED_DATABASE
        || $options['confirmation'] !== TP_ATOMICITY_QA_CONFIRMATION
        || !in_array($options['case'], tpAtomicityQaCaseIds(), true)
    ) {
        throw new InvalidArgumentException('QA atomicity target or confirmation is invalid.');
    }

    return $options;
}

function tpAtomicityQaAssertEnvironment(array $environment): void
{
    $appEnv = strtolower(trim((string) ($environment['APP_ENV'] ?? '')));
    if (!in_array($appEnv, ['qa', 'test'], true)) {
        throw new RuntimeException('APP_ENV_GUARD_FAILED');
    }
    if (($environment['APP_DB_NAME'] ?? null) !== TP_ATOMICITY_QA_DATABASE
        || ($environment['APP_DB_NAME'] ?? null) === TP_ATOMICITY_QA_PROTECTED_DATABASE
        || ($environment['APP_DB_USER'] ?? null) !== 'r_erp_tickets_qa_runner'
        || trim((string) ($environment['APP_DB_HOST'] ?? '')) === ''
        || (int) ($environment['APP_DB_PORT'] ?? 0) !== TP_ATOMICITY_QA_PORT
        || ($environment['APP_DB_CHARSET'] ?? null) !== 'utf8mb4'
        || ($environment['APP_DB_PASSWORD'] ?? '') === '') {
        throw new RuntimeException('QA_DATABASE_ENVIRONMENT_GUARD_FAILED');
    }
}

function tpAtomicityQaAssertDatabaseIdentity(array $identity): void
{
    if (($identity['database'] ?? null) !== TP_ATOMICITY_QA_DATABASE
        || ($identity['database'] ?? null) === TP_ATOMICITY_QA_PROTECTED_DATABASE
        || ($identity['effective_user'] ?? null) !== TP_ATOMICITY_QA_ACCOUNT
        || ($identity['presented_user'] ?? null) !== TP_ATOMICITY_QA_ACCOUNT
        || ($identity['hostname'] ?? null) !== TP_ATOMICITY_QA_HOST
        || (int) ($identity['port'] ?? 0) !== TP_ATOMICITY_QA_PORT) {
        throw new RuntimeException('QA_DATABASE_IDENTITY_GUARD_FAILED');
    }
}

function tpAtomicityQaGenerateRunId(): string
{
    return 'TPATOMIC-' . gmdate('Ymd\\THis\\Z') . '-' . bin2hex(random_bytes(4));
}

/** @return array<string, list<int>> */
function tpAtomicityQaCreateIdRegistry(): array
{
    return [
        'created_ticket_ids' => [],
        'created_partida_ids' => [],
        'created_event_ids' => [],
        'created_attachment_ids' => [],
        'created_comment_ids' => [],
        'created_outbox_ids' => [],
    ];
}

/** @param array<string, list<int>> $registry */
function tpAtomicityQaAssertOwnedIds(array $registry): void
{
    foreach ($registry as $ids) {
        if (!is_array($ids)) {
            throw new InvalidArgumentException('ID registry must contain lists.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('Owned IDs must be positive integers.');
            }
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Owned IDs must be unique.');
        }
    }
}

/**
 * Build an exact-ID DELETE statement. Empty lists are rejected so a caller
 * cannot accidentally turn cleanup into a broad delete.
 *
 * @return array{sql:string,params:array<string,int>}
 */
function tpAtomicityQaBuildExactDelete(string $table, string $column, array $ids): array
{
    $allowedTables = [
        'documentos_folios',
        'tickets_productos_correos', 'tickets_productos_eventos',
        'tickets_productos_comentarios', 'tickets_productos_adjuntos',
        'tickets_productos_partidas', 'tickets_productos',
    ];
    if (!in_array($table, $allowedTables, true) || $column !== 'id' || $ids === []) {
        throw new InvalidArgumentException('EXACT_ID_CLEANUP_REQUIRED');
    }
    $params = [];
    $placeholders = [];
    foreach (array_values($ids) as $index => $id) {
        if (!is_int($id) || $id <= 0) {
            throw new InvalidArgumentException('Invalid cleanup ID.');
        }
        $name = ':id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    return [
        'sql' => 'DELETE FROM ' . $table . ' WHERE id IN (' . implode(', ', $placeholders) . ')',
        'params' => $params,
    ];
}

/** @return array<string, mixed> */
function tpAtomicityQaRuntimeAssertions(): array
{
    return [
        'success' => ['ticket' => 'exists', 'parts' => 'expected', 'events' => 'expected', 'outbox' => 'PENDIENTE'],
        'rollback' => ['ticket' => 'absent_or_original', 'parts' => 'absent_or_original', 'events' => 'absent', 'outbox' => 'absent'],
        'skip' => ['ticket' => 'committed', 'outbox' => 'absent', 'skip_reason' => 'expected'],
        'dedupe' => ['outbox_count' => 1],
    ];
}

/** @param list<int> $ticketIds @param list<int> $outboxIds */
function tpAtomicityQaBuildOrphanCheck(array $ticketIds, array $outboxIds): array
{
    if ($ticketIds === [] || $outboxIds === []) {
        throw new InvalidArgumentException('ORPHAN_CHECK_REQUIRES_EXACT_IDS');
    }
    foreach ([$ticketIds, $outboxIds] as $ids) {
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('ORPHAN_CHECK_ID_INVALID');
            }
        }
    }
    return [
        'sql' => 'SELECT c.id FROM tickets_productos_correos c LEFT JOIN tickets_productos t ON t.id = c.ticket_id WHERE c.id IN (:outbox_id) AND t.id IS NULL',
        'params' => ['outbox_id' => $outboxIds[0]],
    ];
}

/** @return array<string, string> */
function tpAtomicityQaCleanupPostconditions(): array
{
    return [
        'tickets' => 'COUNT_BY_OWNED_IDS=0', 'parts' => 'COUNT_BY_OWNED_IDS=0',
        'events' => 'COUNT_BY_OWNED_IDS=0', 'outbox' => 'COUNT_BY_OWNED_IDS=0',
    ];
}

function tpAtomicityQaPreserveTestAndCleanupFailures(): bool
{
    return true;
}

function tpAtomicityQaFailureInjectionSupported(): bool
{
    return true;
}

final class FailingProductTicketEmailOutboxRepository implements ProductTicketEmailOutboxRepositoryContract
{
    public function __construct(private readonly ProductTicketEmailOutboxRepositoryContract $real)
    {
    }

    public function findTicketContext(int $ticketId): ?array { return $this->real->findTicketContext($ticketId); }
    public function currentDatabaseName(): string { return $this->real->currentDatabaseName(); }
    public function qaFixtureRelationCounts(int $ticketId): array { return $this->real->qaFixtureRelationCounts($ticketId); }
    public function findLineContext(int $ticketId, int $partidaId): ?array { return $this->real->findLineContext($ticketId, $partidaId); }
    public function findLinesContext(int $ticketId): array { return $this->real->findLinesContext($ticketId); }
    public function countAttachments(int $ticketId): int { return $this->real->countAttachments($ticketId); }
    public function findByDedupeKey(string $dedupeKey): ?array { return $this->real->findByDedupeKey($dedupeKey); }

    public function insertPending(array $data): array
    {
        throw new RuntimeException('QA_INJECTED_OUTBOX_FAILURE');
    }

    public function markSent(int $id, ?int $expectedAttempts = null, ?string $expectedLastAttemptAt = null): array
    {
        return $this->real->markSent($id, $expectedAttempts, $expectedLastAttemptAt);
    }

    public function markError(int $id, string $safeMessage, ?int $expectedAttempts = null, ?string $expectedLastAttemptAt = null): array
    {
        return $this->real->markError($id, $safeMessage, $expectedAttempts, $expectedLastAttemptAt);
    }
}

function tpAtomicityQaRepositoryForCase(ProductTicketEmailOutboxRepositoryContract $real, string $caseId): ProductTicketEmailOutboxRepositoryContract
{
    if (in_array($caseId, ['QA-B', 'QA-D', 'QA-F'], true)) {
        return new FailingProductTicketEmailOutboxRepository($real);
    }
    if (in_array($caseId, ['QA-A', 'QA-C', 'QA-E', 'QA-G', 'QA-H'], true)) {
        return $real;
    }
    throw new InvalidArgumentException('Unknown atomicity case.');
}

function tpAtomicityQaModeForCase(string $caseId): string
{
    if (in_array($caseId, ['QA-B', 'QA-D', 'QA-F'], true)) {
        return 'FAIL_OUTBOX_INSERT';
    }
    if (in_array($caseId, ['QA-A', 'QA-C', 'QA-E', 'QA-G', 'QA-H'], true)) {
        return 'SUCCESS';
    }
    throw new InvalidArgumentException('Unknown atomicity case.');
}

function tpAtomicityQaBuildTicketServiceForQa(
    ConnectionProvider $connection,
    string $mode,
    string $baseUrl = 'http://localhost',
    string $timezone = 'UTC'
): ProductRequestTicketService {
    if (!in_array($mode, ['SUCCESS', 'FAIL_OUTBOX_INSERT'], true)) {
        throw new InvalidArgumentException('Unknown QA outbox mode.');
    }
    $realOutbox = new ProductTicketEmailOutboxRepository($connection);
    $outboxRepository = $mode === 'FAIL_OUTBOX_INSERT'
        ? new FailingProductTicketEmailOutboxRepository($realOutbox)
        : $realOutbox;
    $outbox = new ProductTicketEmailOutboxService(
        $outboxRepository,
        new ProductTicketEmailTemplateRenderer(),
        new ProductTicketEmailTemplatePayloadBuilder($baseUrl, $timezone, true)
    );
    $notifications = new ProductTicketEmailNotificationService(
        new MailConfigurationService(new MailConfigurationRepository($connection)),
        $outbox
    );
    return new ProductRequestTicketService(new ProductRequestTicketRepository($connection), $notifications);
}

/** @return array<string, mixed> */
function tpAtomicityQaRuntimePlan(): array
{
    $modes = [];
    foreach (tpAtomicityQaCaseIds() as $caseId) {
        $modes[$caseId] = tpAtomicityQaModeForCase($caseId);
    }
    return [
        'database' => TP_ATOMICITY_QA_DATABASE,
        'modes' => $modes,
        'cleanup' => 'EXACT_CREATED_IDS_ONLY',
        'smtp' => false,
        'outbox_processor' => false,
        'runtime_status' => 'IMPLEMENTED_REQUIRES_DOUBLE_CONFIRMATION',
        'cases' => tpAtomicityQaScenarioCatalog(),
        'confirmations' => [
            'TICKETS_QA_ATOMICITY_RUNTIME_CONFIRM' => 'RUN_QA_A_THROUGH_H_WITH_EXACT_ID_CLEANUP',
            'TICKETS_QA_ATOMICITY_DATABASE_CONFIRM' => TP_ATOMICITY_QA_DATABASE,
        ],
        'same_connection' => true,
        'mysql_connections' => 0,
        'write_sql_executed' => false,
        'qa_g_profile' => ['enviar_solicitante'=>0,'enviar_responsables'=>0,'to_json'=>null,'cc_json'=>null,'bcc_json'=>null],
        'series_counter' => 'ADVANCES_ON_COMMITTED_CREATIONS_NOT_RESET_BY_CLEANUP',
    ];
}

require_once __DIR__ . '/qa_tickets_atomicity_runtime_matrix.php';

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $entrypoint = $argv[1] ?? '';
    if ($entrypoint === '--plan') {
        echo json_encode(tpAtomicityQaRuntimePlan(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    if ($entrypoint === '--contract-test') {
        require_once __DIR__ . '/tests/qa_tickets_atomicity_runtime_matrix_db_free_test.php';
        echo json_encode(qaAtomicityMatrixContractTest(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }
    if ($entrypoint === '--runtime' && count($argv) === 2) {
        try {
            $result = QaTicketsAtomicityRuntimeMatrix::run();
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
            exit($result['status'] === 'PASS' ? 0 : 1);
        } catch (Throwable $error) {
            echo json_encode(['status'=>'BLOCKED','code'=>'QA_RUNTIME_PREFLIGHT_OR_EXECUTION_FAILED','smtp'=>false,'outbox_processor'=>false]) . PHP_EOL;
            exit(1);
        }
    }
    fwrite(STDERR, json_encode([
        'status' => $entrypoint === '--runtime' ? 'RUNTIME_REQUIRES_EXPLICIT_AUTHORIZATION' : 'PREPARATION_ONLY',
        'database' => TP_ATOMICITY_QA_DATABASE,
        'operational_execution' => false,
        'pdo_opened' => false,
        'smtp' => false,
        'outbox_processor' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
