<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tickets_productos_correo_atomicity_qa_runner.php';
require_once dirname(__DIR__, 2) . '/app/Domain/Tickets/ProductTicketEmailOutboxService.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(TP_ATOMICITY_QA_DATABASE === 'r_erp_tickets_qa_2f', 'QA database contract changed.');
$assert(TP_ATOMICITY_QA_DATABASE !== TP_ATOMICITY_QA_PROTECTED_DATABASE, 'Protected database must not be targetable.');
$assert(TP_ATOMICITY_QA_CONFIRMATION === 'YES_I_UNDERSTAND_THIS_WRITES_QA_DATA', 'Confirmation token must be explicit.');

$validArguments = [
    'runner', '--prepare', '--database=' . TP_ATOMICITY_QA_DATABASE,
    '--confirm-database=' . TP_ATOMICITY_QA_DATABASE,
    '--confirmation=' . TP_ATOMICITY_QA_CONFIRMATION, '--case=QA-A',
];
$assert(tpAtomicityQaParseArguments($validArguments)['case'] === 'QA-A', 'Valid preparation arguments rejected.');

$rejected = 0;
foreach ([
    ['runner'],
    ['runner', '--prepare', '--database=' . TP_ATOMICITY_QA_PROTECTED_DATABASE, '--confirm-database=' . TP_ATOMICITY_QA_PROTECTED_DATABASE, '--confirmation=' . TP_ATOMICITY_QA_CONFIRMATION, '--case=QA-A'],
    ['runner', '--prepare', '--database=' . TP_ATOMICITY_QA_DATABASE, '--confirm-database=' . TP_ATOMICITY_QA_DATABASE, '--confirmation=yes', '--case=QA-A'],
    ['runner', '--prepare', '--database=' . TP_ATOMICITY_QA_DATABASE, '--confirm-database=' . TP_ATOMICITY_QA_DATABASE, '--confirmation=' . TP_ATOMICITY_QA_CONFIRMATION, '--case=UNKNOWN'],
] as $argv) {
    try {
        tpAtomicityQaParseArguments($argv);
    } catch (Throwable) {
        $rejected++;
    }
}
$assert($rejected === 4, 'Fail-closed CLI guards failed.');

$validEnvironment = [
    'APP_ENV' => 'qa', 'APP_DB_NAME' => TP_ATOMICITY_QA_DATABASE,
    'APP_DB_USER' => 'r_erp_tickets_qa_runner', 'APP_DB_HOST' => 'localhost',
    'APP_DB_PORT' => '3306', 'APP_DB_CHARSET' => 'utf8mb4', 'APP_DB_PASSWORD' => 'opaque',
];
tpAtomicityQaAssertEnvironment($validEnvironment);
foreach ([['APP_ENV' => 'production'], ['APP_DB_NAME' => TP_ATOMICITY_QA_PROTECTED_DATABASE], ['APP_DB_USER' => 'consulta'], ['APP_DB_PASSWORD' => '']] as $change) {
    $invalid = array_merge($validEnvironment, $change);
    try {
        tpAtomicityQaAssertEnvironment($invalid);
        $assert(false, 'Invalid environment was accepted.');
    } catch (RuntimeException) {
    }
}

$validIdentity = ['database' => TP_ATOMICITY_QA_DATABASE, 'effective_user' => TP_ATOMICITY_QA_ACCOUNT, 'presented_user' => TP_ATOMICITY_QA_ACCOUNT, 'hostname' => TP_ATOMICITY_QA_HOST, 'port' => TP_ATOMICITY_QA_PORT];
tpAtomicityQaAssertDatabaseIdentity($validIdentity);
try {
    tpAtomicityQaAssertDatabaseIdentity(array_merge($validIdentity, ['database' => TP_ATOMICITY_QA_PROTECTED_DATABASE]));
    $assert(false, 'Protected database identity was accepted.');
} catch (RuntimeException) {
}

$registry = tpAtomicityQaCreateIdRegistry();
tpAtomicityQaAssertOwnedIds($registry);
$registry['created_ticket_ids'] = [101];
$registry['created_outbox_ids'] = [202];
tpAtomicityQaAssertOwnedIds($registry);
$delete = tpAtomicityQaBuildExactDelete('tickets_productos_correos', 'id', [202]);
$assert(str_contains($delete['sql'], 'WHERE id IN (:id_0)'), 'Exact cleanup predicate missing.');
try {
    tpAtomicityQaBuildExactDelete('tickets_productos_correos', 'id', []);
    $assert(false, 'Empty cleanup list was accepted.');
} catch (InvalidArgumentException) {
}
try {
    tpAtomicityQaBuildExactDelete('tickets_productos_correos', 'id', [0]);
    $assert(false, 'Invalid cleanup ID was accepted.');
} catch (InvalidArgumentException) {
}

$assert(count(tpAtomicityQaScenarioCatalog()) === 8, 'Atomicity scenario catalog incomplete.');
$assert(tpAtomicityQaFailureInjectionSupported() === true, 'Failure injection seam was not exposed by the QA harness.');
$expectedModes = ['QA-A' => 'SUCCESS', 'QA-B' => 'FAIL_OUTBOX_INSERT', 'QA-C' => 'SUCCESS', 'QA-D' => 'FAIL_OUTBOX_INSERT', 'QA-E' => 'SUCCESS', 'QA-F' => 'FAIL_OUTBOX_INSERT', 'QA-G' => 'SUCCESS', 'QA-H' => 'SUCCESS'];
foreach ($expectedModes as $caseId => $mode) {
    $assert(tpAtomicityQaModeForCase($caseId) === $mode, $caseId . ' mode mismatch.');
}
$assert(tpAtomicityQaRuntimePlan()['smtp'] === false, 'Runtime plan must disable SMTP.');
$assert(tpAtomicityQaRuntimePlan()['outbox_processor'] === false, 'Runtime plan must disable outbox processor.');
$reflection = new ReflectionClass(App\Domain\Tickets\ProductTicketEmailOutboxService::class);
$outboxParameter = $reflection->getConstructor()?->getParameters()[0] ?? null;
$assert($outboxParameter !== null && (string) $outboxParameter->getType() === 'App\\Domain\\Tickets\\ProductTicketEmailOutboxRepositoryContract', 'Outbox service does not depend on the contract.');

$fake = new class implements App\Domain\Tickets\ProductTicketEmailOutboxRepositoryContract {
    public function findTicketContext(int $ticketId): ?array { return null; }
    public function currentDatabaseName(): string { return 'r_erp_tickets_qa_2f'; }
    public function qaFixtureRelationCounts(int $ticketId): array { return ['lines' => 0, 'attachments' => 0]; }
    public function findLineContext(int $ticketId, int $partidaId): ?array { return null; }
    public function findLinesContext(int $ticketId): array { return []; }
    public function countAttachments(int $ticketId): int { return 0; }
    public function findByDedupeKey(string $dedupeKey): ?array { return null; }
    public function insertPending(array $data): array { throw new RuntimeException('QA_INJECTED_OUTBOX_FAILURE'); }
    public function markSent(int $id, ?int $expectedAttempts = null, ?string $expectedLastAttemptAt = null): array { return []; }
    public function markError(int $id, string $safeMessage, ?int $expectedAttempts = null, ?string $expectedLastAttemptAt = null): array { return []; }
};
try {
    $fake->insertPending([]);
    $assert(false, 'Failing double did not throw.');
} catch (RuntimeException $exception) {
    $assert($exception->getMessage() === 'QA_INJECTED_OUTBOX_FAILURE', 'Failure exception is not exact.');
}
$assert(tpAtomicityQaRepositoryForCase($fake, 'QA-A') === $fake, 'Success case did not retain the real repository.');
$assert(tpAtomicityQaRepositoryForCase($fake, 'QA-C') === $fake, 'Success case QA-C did not retain the real repository.');
$assert(tpAtomicityQaRepositoryForCase($fake, 'QA-E') === $fake, 'Success case QA-E did not retain the real repository.');
$assert(tpAtomicityQaRepositoryForCase($fake, 'QA-G') === $fake, 'Skip case did not retain the real repository.');
$assert(tpAtomicityQaRepositoryForCase($fake, 'QA-H') === $fake, 'Dedupe case did not retain the real repository.');
foreach (['QA-B', 'QA-D', 'QA-F'] as $failureCase) {
    $failureDouble = tpAtomicityQaRepositoryForCase($fake, $failureCase);
    $assert($failureDouble instanceof FailingProductTicketEmailOutboxRepository, $failureCase . ' did not select failing double.');
}
$assert(tpAtomicityQaRepositoryForCase($fake, 'QA-B') instanceof FailingProductTicketEmailOutboxRepository, 'QA-B factory wiring missing.');
$assert(tpAtomicityQaRuntimeAssertions()['dedupe']['outbox_count'] === 1, 'Dedupe assertion missing.');
$orphanCheck = tpAtomicityQaBuildOrphanCheck([101], [202]);
$assert(str_contains($orphanCheck['sql'], 'WHERE c.id IN (:outbox_id)'), 'Orphan check is not ID-scoped.');
$assert(tpAtomicityQaCleanupPostconditions()['outbox'] === 'COUNT_BY_OWNED_IDS=0', 'Cleanup postcondition missing.');
$assert(tpAtomicityQaPreserveTestAndCleanupFailures(), 'Cleanup failure must preserve original test result.');

$source = file_get_contents(dirname(__DIR__) . '/tickets_productos_correo_atomicity_qa_runner.php');
$assert(is_string($source), 'Runner source could not be read.');
$assert(!preg_match('/DELETE\\s+FROM\\s+(tickets_productos|tickets_productos_partidas|tickets_productos_eventos|tickets_productos_correos)\\s*;/', $source), 'Broad DELETE detected.');
$assert(!str_contains($source, 'MailOutboxProcessor'), 'Outbox processor path must not be loaded.');
$assert(!preg_match('/(?:->|::)(?:send|process|flush|worker)\\s*\\(/i', $source), 'SMTP or outbox processor call path detected.');
$assert(!str_contains($source, 'APP_DB_PASSWORD='), 'Password must not be hardcoded.');

echo "atomicity_guards=PASS\n";
echo "exact_id_cleanup=PASS\n";
echo "broad_delete_guard=PASS\n";
echo "smtp_processor_excluded=PASS\n";
echo "atomicity_scenarios=PASS\n";
echo "TICKETS_MAIL_OUTBOX_ATOMICITY_QA_HARNESS_DB_FREE=PASS\n";

require_once __DIR__ . '/qa_tickets_atomicity_runtime_matrix_db_free_test.php';
echo json_encode(qaAtomicityMatrixContractTest(), JSON_THROW_ON_ERROR) . PHP_EOL;
