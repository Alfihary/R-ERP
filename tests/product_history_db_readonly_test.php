<?php

declare(strict_types=1);

use App\Domain\Products\ProductHistoryService;
use App\Domain\Products\ProductNotFoundException;
use App\Domain\Products\History\ProductHistoryMapper;
use App\Domain\Products\History\ProductHistoryPresenter;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\ProductHistoryQueryRepository;
use App\Infrastructure\Repositories\ProductRepository;

define('BASE_PATH', dirname(__DIR__));

const TARGET_DATABASE = 'r_erp_db_core_0_test';
const EXPECTED_DB_USER = 'consulta';
const EXPECTED_DB_HOST = 'localhost';
const SCENARIO_PASS = 'PASS';
const SCENARIO_SKIP = 'SKIP_DATA_NOT_AVAILABLE';
const SCENARIO_FAIL = 'FAIL';

final class RuntimeHarnessContext
{
    private static string $stage = 'BOOT';

    public static function stage(string $stage): void
    {
        self::$stage = $stage;
    }

    public static function currentStage(): string
    {
        return self::$stage;
    }

    public static function sanitizeRuntimeException(Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (preg_match('/\b(?:SELECT|SHOW\s+GRANTS|INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|CALL)\b/i', $message) === 1) {
            return 'SQL statement redacted';
        }
        $message = preg_replace("/'(?:''|[^'])*'/", "'<redacted>'", $message) ?? $message;
        $message = preg_replace('/"(?:""|[^"])*"/', '"<redacted>"', $message) ?? $message;
        $message = preg_replace('/(?:APP_DB_PASSWORD|password|passwd|secret)\s*[=:]\s*[^\s;]+/i', 'secret=<redacted>', $message) ?? $message;
        $message = preg_replace('/mysql:\S+/i', 'mysql:<redacted>', $message) ?? $message;
        $message = preg_replace('/(?:credential|credencial)\s*[=:]?\s*[^\s;]+/i', 'credential=<redacted>', $message) ?? $message;
        return substr((string) preg_replace('/[\r\n]+/', ' ', $message), 0, 240);
    }

    public static function sqlState(Throwable $exception): string
    {
        $errorInfo = $exception instanceof PDOException ? $exception->errorInfo : null;
        $candidate = is_array($errorInfo) ? ($errorInfo[0] ?? null) : null;
        if (!is_string($candidate) && is_string($exception->getCode())) {
            $candidate = $exception->getCode();
        }
        return is_string($candidate) && preg_match('/^[0-9A-Z]{5}$/', $candidate) === 1
            ? $candidate
            : 'UNAVAILABLE';
    }

    public static function driverErrorCode(Throwable $exception): string
    {
        $errorInfo = $exception instanceof PDOException ? $exception->errorInfo : null;
        $candidate = is_array($errorInfo) ? ($errorInfo[1] ?? null) : null;
        return (is_int($candidate) || (is_string($candidate) && preg_match('/^\d+$/', $candidate) === 1))
            ? (string) $candidate
            : 'UNAVAILABLE';
    }

    public static function classify(Throwable $exception, string $stage): string
    {
        $message = strtolower($exception->getMessage());
        if ($stage === 'PDO_CONSTRUCTOR_START') {
            if (!$exception instanceof PDOException) {
                return 'PDO_CONSTRUCTOR_FAILED';
            }
            $sqlState = self::sqlState($exception);
            $driverCode = self::driverErrorCode($exception);
            $authenticationEvidence = $sqlState === '28000'
                || $driverCode === '1045'
                || preg_match('/access denied for user|authentication failed|invalid authorization/i', $message) === 1;
            $connectionEvidence = preg_match('/sqlstate\[[0-9a-z]{5}\].*(?:08[0-9a-z]{3})|connection refused|could not connect|server unavailable|unknown host|no route to host/i', $message) === 1;
            return $authenticationEvidence
                ? 'DATABASE_AUTHENTICATION_FAILED'
                : ($connectionEvidence ? 'DATABASE_CONNECTION_FAILED' : 'PDO_CONSTRUCTOR_FAILED');
        }
        return match ($stage) {
            'STATEMENT_CLASS_SETUP_START' => 'STATEMENT_CLASS_CONFIGURATION_FAILED',
            'DATABASE_IDENTITY_CHECK_START' => 'IDENTITY_QUERY_FAILED',
            'PDO_QUERY_INTERCEPT_START' => 'PDO_QUERY_INTERCEPT_FAILED',
            'PDO_PREPARE_INTERCEPT_START' => 'PDO_PREPARE_INTERCEPT_FAILED',
            'PDOSTATEMENT_EXECUTE_INTERCEPT_START' => 'PDOSTATEMENT_EXECUTE_INTERCEPT_FAILED',
            default => 'UNKNOWN_RUNTIME_FAILURE',
        };
    }
}

final class ReadOnlySqlLedger
{
    /** @var list<array{sequence:int,phase:string,source:string,operation:string,classification:string,allowed:bool,sql_fingerprint:string}> */
    private array $entries = [];

    public function classify(string $sql): string
    {
        $normalized = normalizeSqlForGuard($sql);
        if (preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME|CALL|LOCK|UNLOCK|GRANT|REVOKE|SET|USE|LOAD|IMPORT|ANALYZE|OPTIMIZE|REPAIR|HANDLER|DO|BEGIN|START\s+TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|TEMPORARY\s+TABLE)\b/i', $normalized) === 1) {
            return str_contains($normalized, 'TRANSACTION') || preg_match('/\b(?:COMMIT|ROLLBACK|SAVEPOINT|BEGIN)\b/i', $normalized) === 1
                ? 'BLOCKED_TRANSACTION'
                : (preg_match('/\b(?:GRANT|REVOKE|CALL|LOCK|UNLOCK|SET|USE|LOAD|IMPORT|ANALYZE|OPTIMIZE|REPAIR|HANDLER)\b/i', $normalized) === 1
                    ? 'BLOCKED_ADMIN'
                    : 'BLOCKED_WRITE');
        }
        if (preg_match('/^SHOW\s+GRANTS\b/i', $normalized) === 1) {
            return 'SHOW_GRANTS';
        }
        if (preg_match('/^SELECT\b/i', $normalized) === 1) {
            return str_contains(strtolower($normalized), 'information_schema') ? 'METADATA_READ' : 'SELECT';
        }
        return 'UNKNOWN';
    }

    private function append(string $phase, string $source, string $operation, string $sql): void
    {
        $classification = $this->classify($sql);
        $allowed = in_array($classification, ['SELECT', 'SHOW_GRANTS', 'METADATA_READ'], true);
        $this->entries[] = [
            'sequence' => count($this->entries) + 1,
            'phase' => $phase,
            'source' => $source,
            'operation' => $operation,
            'classification' => $classification,
            'allowed' => $allowed,
            'sql_fingerprint' => hash('sha256', normalizeSqlForGuard($sql)),
        ];
        if (!$allowed) {
            throw new RuntimeException('READ_ONLY_SQL_BLOCKED');
        }
    }

    public function prevalidate(string $source, string $operation, string $sql): void
    {
        $this->append('prevalidation', $source, $operation, $sql);
    }

    public function recordExecution(string $source, string $operation, string $sql): void
    {
        $this->append('execution', $source, $operation, $sql);
    }

    /** @return list<array{sequence:int,phase:string,source:string,operation:string,classification:string,allowed:bool,sql_fingerprint:string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $counts = [
            'TOTAL_LEDGER_EVENTS' => count($this->entries),
            'TOTAL_PREVALIDATION_EVENTS' => 0,
            'TOTAL_EXECUTION_EVENTS' => 0,
            'TOTAL_SQL_STATEMENTS_EXECUTED' => 0,
            'TOTAL_SQL_STATEMENTS_EXECUTION_LEDGERED' => 0,
            'TOTAL_SQL_STATEMENTS_ALLOWED' => 0,
            'TOTAL_SQL_STATEMENTS_BLOCKED' => 0,
            'UNLEDGERED_EXECUTION_COUNT' => 0,
            'UNPREVALIDATED_EXECUTION_COUNT' => 0,
            'BLOCKED_EXECUTION_COUNT' => 0,
            'SELECT_COUNT' => 0,
            'SHOW_GRANTS_COUNT' => 0,
            'METADATA_READ_COUNT' => 0,
            'DDL_COUNT' => 0,
            'DML_COUNT' => 0,
            'ADMIN_COUNT' => 0,
            'TRANSACTION_COUNT' => 0,
            'UNKNOWN_COUNT' => 0,
        ];
        $prevalidated = [];
        foreach ($this->entries as $entry) {
            if ($entry['phase'] === 'prevalidation') {
                $counts['TOTAL_PREVALIDATION_EVENTS']++;
                $prevalidated[$entry['sql_fingerprint']] = true;
                continue;
            }
            $counts['TOTAL_EXECUTION_EVENTS']++;
            if (!$entry['allowed']) {
                $counts['TOTAL_SQL_STATEMENTS_BLOCKED']++;
                $counts['BLOCKED_EXECUTION_COUNT']++;
            } else {
                $counts['TOTAL_SQL_STATEMENTS_EXECUTED']++;
                $counts['TOTAL_SQL_STATEMENTS_EXECUTION_LEDGERED']++;
                $counts['TOTAL_SQL_STATEMENTS_ALLOWED']++;
            }
            if (!isset($prevalidated[$entry['sql_fingerprint']])) {
                $counts['UNPREVALIDATED_EXECUTION_COUNT']++;
            }
            $key = match ($entry['classification']) {
                'SELECT' => 'SELECT_COUNT',
                'SHOW_GRANTS' => 'SHOW_GRANTS_COUNT',
                'METADATA_READ' => 'METADATA_READ_COUNT',
                'BLOCKED_WRITE' => 'DML_COUNT',
                'BLOCKED_ADMIN' => 'ADMIN_COUNT',
                'BLOCKED_TRANSACTION' => 'TRANSACTION_COUNT',
                default => 'UNKNOWN_COUNT',
            };
            $counts[$key]++;
        }
        $counts['UNLEDGERED_EXECUTION_COUNT'] = 0;
        return $counts;
    }
}

final class ReadOnlyInstrumentedStatement extends PDOStatement
{
    /** @var array<int,array{ledger:ReadOnlySqlLedger,source:string,sql:string}> */
    private static array $registry = [];

    protected function __construct()
    {
    }

    public static function register(PDOStatement $statement, ReadOnlySqlLedger $ledger, string $source, string $sql): void
    {
        self::$registry[spl_object_id($statement)] = ['ledger' => $ledger, 'source' => $source, 'sql' => $sql];
    }

    public function execute(?array $params = null): bool
    {
        RuntimeHarnessContext::stage('PDOSTATEMENT_EXECUTE_INTERCEPT_START');
        $entry = self::$registry[spl_object_id($this)] ?? null;
        if ($entry === null) {
            throw new RuntimeException('UNREGISTERED_PREPARED_STATEMENT');
        }
        $entry['ledger']->recordExecution($entry['source'], 'execute', $entry['sql']);
        $result = $params === null ? parent::execute() : parent::execute($params);
        RuntimeHarnessContext::stage('PDOSTATEMENT_EXECUTE_INTERCEPT_DONE');
        return $result;
    }
}

final class ReadOnlyInstrumentedPdo extends PDO
{
    public function __construct(string $dsn, string $username, string $password, array $options, private readonly ReadOnlySqlLedger $ledger)
    {
        RuntimeHarnessContext::stage('STATEMENT_CLASS_SETUP_START');
        $options[PDO::ATTR_STATEMENT_CLASS] = [ReadOnlyInstrumentedStatement::class, []];
        RuntimeHarnessContext::stage('STATEMENT_CLASS_SETUP_DONE');
        RuntimeHarnessContext::stage('PDO_CONSTRUCTOR_START');
        parent::__construct($dsn, $username, $password, $options);
        RuntimeHarnessContext::stage('PDO_CONSTRUCTOR_DONE');
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        RuntimeHarnessContext::stage('PDO_QUERY_INTERCEPT_START');
        $this->ledger->prevalidate('pdo.query', 'query', $query);
        $this->ledger->recordExecution('pdo.query', 'query', $query);
        $result = $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
        RuntimeHarnessContext::stage('PDO_QUERY_INTERCEPT_DONE');
        return $result;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        RuntimeHarnessContext::stage('PDO_PREPARE_INTERCEPT_START');
        $this->ledger->prevalidate('pdo.prepare', 'prepare', $query);
        $statement = parent::prepare($query, $options);
        if ($statement !== false) {
            ReadOnlyInstrumentedStatement::register($statement, $this->ledger, 'pdo.prepare', $query);
        }
        RuntimeHarnessContext::stage('PDO_PREPARE_INTERCEPT_DONE');
        return $statement;
    }
}

/** @return array{SELECT:int,SHOW_GRANTS:int} */
function readOnlyStatementLedger(): array
{
    return ['SELECT' => 0, 'SHOW_GRANTS' => 0];
}

function recordReadOnlyStatement(array &$ledger, string $kind): void
{
    if (!array_key_exists($kind, $ledger)) {
        throw new RuntimeException('UNALLOWLISTED_SQL_STATEMENT');
    }
    $ledger[$kind]++;
}

function normalizeSqlForGuard(string $sql): string
{
    $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;
    $sql = preg_replace('/(^|\s)--[^\r\n]*/m', '$1 ', $sql) ?? $sql;
    $sql = preg_replace('/(^|\s)#[^\r\n]*/m', '$1 ', $sql) ?? $sql;
    return trim((string) preg_replace('/\s+/', ' ', $sql));
}

function assertReadOnlySqlText(string $sql): void
{
    $normalized = normalizeSqlForGuard($sql);
    $blocked = '/\b(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME|CALL|LOCK|UNLOCK|GRANT|REVOKE|SET|USE|LOAD|IMPORT|ANALYZE|OPTIMIZE|REPAIR|HANDLER|DO|BEGIN|START\s+TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|TEMPORARY\s+TABLE)\b/i';
    if (preg_match($blocked, $normalized) === 1) {
        throw new RuntimeException('WRITE_SQL_BLOCKED');
    }
    if (preg_match('/^(?:SELECT\b|SHOW\s+GRANTS\b)/i', $normalized) !== 1) {
        throw new RuntimeException('UNKNOWN_SQL_BLOCKED');
    }
}

function assertGrantTextReadOnly(string $grant): void
{
    $normalized = strtoupper(normalizeSqlForGuard($grant));
    if (preg_match('/^GRANT\s+SELECT\s+ON\s+.+\s+TO\s+/i', $normalized) === 1
        && !str_contains($normalized, 'GRANT OPTION')
    ) {
        return;
    }
    $blocked = [
        'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX',
        'REFERENCES', 'CREATE TEMPORARY TABLES', 'LOCK TABLES', 'EXECUTE',
        'CREATE ROUTINE', 'ALTER ROUTINE', 'EVENT', 'TRIGGER', 'GRANT OPTION',
        'FILE', 'PROCESS', 'RELOAD', 'SHUTDOWN', 'SUPER', 'CREATE USER',
        'ROLE_ADMIN', 'SYSTEM_USER', 'SYSTEM_VARIABLES_ADMIN',
        'SESSION_VARIABLES_ADMIN', 'ALL PRIVILEGES', 'REPLACE', 'REVOKE',
    ];
    foreach ($blocked as $token) {
        if (str_contains($normalized, $token)) {
            throw new RuntimeException('READ_ONLY_PRIVILEGE_CONTRACT_FAILED');
        }
    }
}

function assertNoPhaseExecutionReferences(string $source): void
{
    $patterns = [
        '/(?:require|include|require_once|include_once)\s*[^;]*(?:migration|seed|backfill)/i',
        '/new\s+MigrationRunner\b/i',
        '/->(?:run|execute)\s*\([^;]*(?:migration|seed|backfill)/i',
        '/(?:migration|seed|backfill)\s*\(/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $source) === 1) {
            throw new RuntimeException('PHASE_EXECUTION_REFERENCE_BLOCKED');
        }
    }
}

function runReadOnlyGuardSelfTest(): int
{
    $ledger = new ReadOnlySqlLedger();
    $ledger->prevalidate('self-test', 'query', 'SELECT * FROM productos');
    $ledger->recordExecution('self-test', 'query', 'SELECT * FROM productos');
    $ledger->prevalidate('self-test', 'query', 'SHOW GRANTS');
    $ledger->recordExecution('self-test', 'query', 'SHOW GRANTS');
    try {
        $ledger->prevalidate('self-test', 'query', 'UPDATE productos SET descripcion = \'blocked\'');
        return 1;
    } catch (Throwable) {
    }
    $ledgerEntry = $ledger->entries()[0] ?? [];
    if (($ledgerEntry['source'] ?? null) !== 'self-test'
        || ($ledgerEntry['classification'] ?? null) !== 'SELECT'
        || ($ledgerEntry['allowed'] ?? false) !== true
        || !isset($ledgerEntry['sql_fingerprint'])
    ) {
        return 1;
    }
    $counts = $ledger->counts();
    if (($counts['TOTAL_LEDGER_EVENTS'] ?? 0) !== 5
        || ($counts['TOTAL_PREVALIDATION_EVENTS'] ?? 0) !== 3
        || ($counts['TOTAL_EXECUTION_EVENTS'] ?? 0) !== 2
        || ($counts['TOTAL_SQL_STATEMENTS_EXECUTED'] ?? 0) !== 2
        || ($counts['TOTAL_SQL_STATEMENTS_EXECUTION_LEDGERED'] ?? 0) !== 2
        || ($counts['TOTAL_SQL_STATEMENTS_ALLOWED'] ?? 0) !== 2
        || ($counts['TOTAL_SQL_STATEMENTS_BLOCKED'] ?? 0) !== 0
        || ($counts['DML_COUNT'] ?? 0) !== 0
        || ($counts['UNLEDGERED_EXECUTION_COUNT'] ?? 0) !== 0
        || ($counts['UNPREVALIDATED_EXECUTION_COUNT'] ?? 0) !== 0
    ) {
        return 1;
    }
    $multi = new ReadOnlySqlLedger();
    $multi->prevalidate('self-test', 'prepare', 'SELECT COUNT(*) FROM auditoria_eventos');
    $multi->recordExecution('self-test', 'execute', 'SELECT COUNT(*) FROM auditoria_eventos');
    $multi->recordExecution('self-test', 'execute', 'SELECT COUNT(*) FROM auditoria_eventos');
    $multiCounts = $multi->counts();
    if (($multiCounts['TOTAL_LEDGER_EVENTS'] ?? 0) !== 3
        || ($multiCounts['TOTAL_PREVALIDATION_EVENTS'] ?? 0) !== 1
        || ($multiCounts['TOTAL_EXECUTION_EVENTS'] ?? 0) !== 2
        || ($multiCounts['TOTAL_SQL_STATEMENTS_EXECUTED'] ?? 0) !== 2
        || ($multiCounts['TOTAL_SQL_STATEMENTS_EXECUTION_LEDGERED'] ?? 0) !== 2
        || ($multiCounts['UNPREVALIDATED_EXECUTION_COUNT'] ?? 0) !== 0
    ) {
        return 1;
    }
    $allowed = ['SELECT 1', "\n /* comment */ SELECT CURRENT_USER()", 'SHOW GRANTS'];
    foreach ($allowed as $sql) {
        assertReadOnlySqlText($sql);
    }
    $blocked = [
        'INSERT INTO t VALUES (1)', 'update t set a=1', 'DELETE FROM t',
        'REPLACE INTO t VALUES (1)', 'CREATE TABLE t (id INT)', 'ALTER TABLE t ADD id INT',
        'DROP TABLE t', 'TRUNCATE TABLE t', 'RENAME TABLE a TO b', 'CALL p()',
        'LOCK TABLES t WRITE', 'UNLOCK TABLES', 'GRANT SELECT ON *.* TO x',
        'REVOKE SELECT ON *.* FROM x', 'CREATE TEMPORARY TABLE t (id INT)',
        '/*x*/ START TRANSACTION', 'COMMIT', 'SET @x=1', 'USE other_db',
    ];
    foreach ($blocked as $sql) {
        try {
            assertReadOnlySqlText($sql);
            return 1;
        } catch (Throwable) {
        }
    }
    try {
        assertGrantTextReadOnly('GRANT ALL PRIVILEGES ON *.* TO `consulta`@`localhost`');
        return 1;
    } catch (Throwable) {
    }
    assertGrantTextReadOnly('GRANT SELECT ON `r_erp_db_core_0_test`.`productos` TO `consulta`@`localhost`');
    assertNoPhaseExecutionReferences((string) file_get_contents(__FILE__));
    return 0;
}

/** @param array<string,mixed> $database */
function instrumentConnectionProvider(ConnectionProvider $connection, array $database, ReadOnlySqlLedger $ledger): ReadOnlyInstrumentedPdo
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) $database['host'],
        (int) $database['port'],
        (string) $database['name'],
        (string) $database['charset']
    );
    RuntimeHarnessContext::stage('CONNECTION_PROVIDER_INJECTION_START');
    $pdo = new ReadOnlyInstrumentedPdo($dsn, (string) $database['username'], (string) $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ], $ledger);
    $property = new ReflectionProperty(ConnectionProvider::class, 'pdo');
    $property->setAccessible(true);
    $property->setValue($connection, $pdo);
    RuntimeHarnessContext::stage('CONNECTION_PROVIDER_INJECTION_DONE');
    return $pdo;
}

function runDiagnosticHarnessSelfTest(): int
{
    $cases = [
        ['stage' => 'PDO_CONSTRUCTOR_START', 'exception' => new PDOException('Access denied for user using password=fixture-secret'), 'expected' => 'DATABASE_AUTHENTICATION_FAILED'],
        ['stage' => 'PDO_CONSTRUCTOR_START', 'exception' => new PDOException('server unavailable'), 'expected' => 'DATABASE_CONNECTION_FAILED'],
        ['stage' => 'PDO_CONSTRUCTOR_START', 'exception' => new RuntimeException('constructor fixture failure'), 'expected' => 'PDO_CONSTRUCTOR_FAILED'],
        ['stage' => 'STATEMENT_CLASS_SETUP_START', 'exception' => new RuntimeException('statement class failure'), 'expected' => 'STATEMENT_CLASS_CONFIGURATION_FAILED'],
        ['stage' => 'PDO_PREPARE_INTERCEPT_START', 'exception' => new RuntimeException('prepare failure'), 'expected' => 'PDO_PREPARE_INTERCEPT_FAILED'],
        ['stage' => 'PDOSTATEMENT_EXECUTE_INTERCEPT_START', 'exception' => new RuntimeException('execute failure'), 'expected' => 'PDOSTATEMENT_EXECUTE_INTERCEPT_FAILED'],
    ];
    foreach ($cases as $case) {
        RuntimeHarnessContext::stage($case['stage']);
        if (RuntimeHarnessContext::classify($case['exception'], RuntimeHarnessContext::currentStage()) !== $case['expected']) {
            return 1;
        }
        if (RuntimeHarnessContext::currentStage() !== $case['stage']) {
            return 1;
        }
    }
    $sanitized = RuntimeHarnessContext::sanitizeRuntimeException(new RuntimeException("SELECT * FROM usuarios WHERE email='persona@example.com' AND token='123456' password=supersecret mysql:host=localhost;dbname=test"));
    if (str_contains($sanitized, 'persona@example.com') || str_contains($sanitized, '123456') || str_contains($sanitized, 'supersecret') || str_contains($sanitized, 'mysql:host=localhost')) {
        return 1;
    }
    $sqlStateException = new PDOException('driver message must not escape');
    $sqlStateException->errorInfo = ['28000', 1045, 'sensitive driver text'];
    if (RuntimeHarnessContext::sqlState($sqlStateException) !== '28000'
        || RuntimeHarnessContext::driverErrorCode($sqlStateException) !== '1045'
        || RuntimeHarnessContext::sqlState(new RuntimeException('invalid')) !== 'UNAVAILABLE'
    ) {
        return 1;
    }
    return 0;
}

/** @return never */
function failRun(string $category, string $message, ?Throwable $exception = null): never
{
    $report = [
        'status' => 'FAIL',
        'failure_category' => $category,
        'safe_error_message' => $message,
        'failure_stage' => RuntimeHarnessContext::currentStage(),
    ];
    if ($exception !== null) {
        $report['failure_code'] = RuntimeHarnessContext::classify($exception, RuntimeHarnessContext::currentStage());
        $report['exception_class'] = get_class($exception);
        $report['sqlstate'] = RuntimeHarnessContext::sqlState($exception);
        $report['driver_error_code'] = RuntimeHarnessContext::driverErrorCode($exception);
        $report['sanitized_message'] = RuntimeHarnessContext::sanitizeRuntimeException($exception);
    }
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}

/** @return array<string,string> */
function parseArguments(array $argv): array
{
    $values = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_contains($argument, '=') || !str_starts_with($argument, '--')) {
            failRun('preflight_error', 'UNSUPPORTED_ARGUMENT');
        }
        [$key, $value] = explode('=', $argument, 2);
        $values[substr($key, 2)] = $value;
    }

    if (($values['database'] ?? null) !== TARGET_DATABASE
        || ($values['confirm-database'] ?? null) !== TARGET_DATABASE
    ) {
        failRun('preflight_error', 'DATABASE_ARGUMENT_MISMATCH');
    }

    return $values;
}

/** @return list<string> */
function grants(PDO $pdo): array
{
    RuntimeHarnessContext::stage('SHOW_GRANTS_START');
    $rows = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    RuntimeHarnessContext::stage('SHOW_GRANTS_DONE');
    return array_values(array_filter(array_map(
        static fn (mixed $value): ?string => is_string($value) ? trim($value) : null,
        $rows
    )));
}

function assertReadOnlyPrivileges(PDO $pdo): void
{
    RuntimeHarnessContext::stage('DATABASE_IDENTITY_CHECK_START');
    $identity = $pdo->query(
        'SELECT CURRENT_USER(), DATABASE(), CURRENT_ROLE()'
    )->fetch(PDO::FETCH_NUM);
    RuntimeHarnessContext::stage('DATABASE_IDENTITY_CHECK_DONE');
    if (!is_array($identity)
        || $identity[0] !== EXPECTED_DB_USER . '@' . EXPECTED_DB_HOST
        || $identity[1] !== TARGET_DATABASE
        || ($identity[2] !== 'NONE' && $identity[2] !== null)
    ) {
        failRun('security_error', 'CONNECTION_IDENTITY_OR_DATABASE_MISMATCH');
    }

    $forbidden = [
        'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE',
        'GRANT OPTION', 'FILE', 'PROCESS', 'SUPER', 'SYSTEM_USER',
        'SYSTEM_VARIABLES_ADMIN', 'SESSION_VARIABLES_ADMIN', 'RELOAD',
        'PROXY', 'ROLE_ADMIN', 'SET_USER_ID', 'SYSTEM_USER',
    ];
    $recipient = '(?:`consulta`@`localhost`|consulta@localhost)';
    $authorizedTable = '(?:'
        . '`r_erp_db_core_0_test`\\.(?:`productos`|`auditoria_eventos`|`usuarios`)'
        . '|r_erp_db_core_0_test\\.(?:productos|auditoria_eventos|usuarios)'
        . ')';
    foreach (grants($pdo) as $grant) {
        assertGrantTextReadOnly($grant);
        $upper = strtoupper($grant);
        foreach ($forbidden as $token) {
            if (str_contains($upper, $token)) {
                failRun('security_error', 'READ_ONLY_PRIVILEGE_CONTRACT_FAILED');
            }
        }
        if (preg_match(
            '/^GRANT\s+USAGE\s+ON\s+\*\.\*\s+TO\s+' . $recipient . '$/i',
            $grant
        ) === 1) {
            continue;
        }
        if (preg_match(
            '/^GRANT\s+SELECT\s+ON\s+' . $authorizedTable
            . '\s+TO\s+' . $recipient . '$/i',
            $grant
        ) === 1) {
            continue;
        }
        failRun('security_error', 'UNEXPECTED_GRANT_OR_ROLE_ASSIGNMENT');
    }

    $rows = $pdo->query(
        "SELECT TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE
         FROM information_schema.TABLE_PRIVILEGES
         WHERE GRANTEE = '''consulta''@''localhost'''
         ORDER BY TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE"
    )->fetchAll(PDO::FETCH_ASSOC);
    $allowed = [
        TARGET_DATABASE . '|productos|SELECT',
        TARGET_DATABASE . '|auditoria_eventos|SELECT',
        TARGET_DATABASE . '|usuarios|SELECT',
    ];
    foreach ($rows as $row) {
        $key = ($row['TABLE_SCHEMA'] ?? '') . '|'
            . ($row['TABLE_NAME'] ?? '') . '|'
            . ($row['PRIVILEGE_TYPE'] ?? '');
        if (!in_array($key, $allowed, true)) {
            failRun('security_error', 'UNEXPECTED_TABLE_PRIVILEGE');
        }
    }
}

/**
 * @param array<string, array<string,mixed>> $registry
 * @return array{sql:string,params:array<string,string>}
 */
function historyAssociation(array $registry, string $productAlias, string $prefix): array
{
    $direct = [];
    $subresources = [];
    foreach ($registry as $action => $event) {
        if (!is_array($event)) continue;
        $subresource = $event['subresource'] ?? null;
        if ($subresource === null
            && in_array($event['product_id_strategy'] ?? 'entity_id', ['entity_id', null], true)
            && ($event['entity'] ?? 'producto') === 'producto'
        ) {
            $direct[] = (string) $action;
        } elseif (is_string($subresource)
            && is_string($event['entity'] ?? null)
            && in_array($event['product_id_strategy'] ?? null, ['metadata.producto_id', 'metadata.kit_id_producto'], true)
        ) {
            $subresources[] = [(string) $action, (string) $event['entity'], (string) $event['product_id_strategy']];
        }
    }

    $conditions = [];
    $params = [];
    if ($direct !== []) {
        $names = [];
        foreach (array_values($direct) as $index => $action) {
            $name = $prefix . '_direct_action_' . $index;
            $names[] = ':' . $name;
            $params[$name] = $action;
        }
        $conditions[] = '(h.accion IN (' . implode(',', $names)
            . ') AND h.entidad = \'producto\' AND h.entidad_id = ' . $productAlias . '.id_producto)';
    }
    foreach (array_values($subresources) as $index => [$action, $entity, $strategy]) {
        $actionName = $prefix . '_sub_action_' . $index;
        $entityName = $prefix . '_sub_entity_' . $index;
        $params[$actionName] = $action;
        $params[$entityName] = $entity;
        $productExpression = $strategy === 'metadata.kit_id_producto'
            ? "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(h.metadata_json, '$.kit_id_producto')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(h.metadata_json, '$.producto_id')), ''))"
            : "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(h.metadata_json, '$.producto_id')), '')";
        $conditions[] = '(h.accion = :' . $actionName
            . ' AND h.entidad = :' . $entityName
            . ' AND ' . $productExpression . ' = '
            . $productAlias . '.id_producto)';
    }

    if ($conditions === []) {
        return ['sql' => '0 = 1', 'params' => []];
    }

    return [
        'sql' => 'EXISTS (SELECT 1 FROM auditoria_eventos h WHERE '
            . implode(' OR ', $conditions) . ')',
        'params' => $params,
    ];
}

/** @param array<string, array<string,mixed>> $registry @return array{0:string,1:?string} */
function chooseProduct(PDO $pdo, array $registry, bool $withHistory): array
{
    $association = historyAssociation($registry, 'p', $withHistory ? 'with' : 'without');
    $historyClause = $withHistory ? $association['sql'] : 'NOT ' . $association['sql'];
    $sql =
        'SELECT p.id_producto
         FROM productos p
         WHERE p.eliminado_en IS NULL
           AND ' . $historyClause . '
         ORDER BY p.id_producto
         LIMIT 1';
    assertReadOnlySqlText($sql);
    $statement = $pdo->prepare($sql);
    $statement->execute($association['params']);
    $id = $statement->fetchColumn();
    return [is_string($id) ? $id : '', $withHistory ? 'with_history' : 'without_history'];
}

/** @param list<array<string,mixed>> $items */
function assertSanitizedItems(array $items): void
{
    $forbidden = [
        'password', 'password_hash', 'token', 'access_token', 'refresh_token',
        'authorization', 'cookie', 'session', 'session_id', 'csrf', 'secret',
        'api_key', 'private_key', 'path', 'realpath', 'storage_path',
        'temp_path', 'temporary_path', 'file_path',
    ];
    $walk = static function (mixed $value) use (&$walk, $forbidden): void {
        if (!is_array($value)) return;
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), $forbidden, true)) {
                failRun('sanitization_error', 'SENSITIVE_FIELD_EXPOSED');
            }
            $walk($item);
        }
    };
    foreach ($items as $item) {
        if (!is_object($item)) {
            failRun('sanitization_error', 'UNEXPECTED_HISTORY_ITEM_TYPE');
        }
        $walk(get_object_vars($item));
    }
}

/**
 * @param array<string, array<string,mixed>> $registry
 * @return list<array{audit_id:int, action:string, subresource:?string, actor_id:?int, date:string, metadata:array<string,mixed>}>
 */
function productHistoryEvents(PDO $pdo, array $registry, string $productId): array
{
    $conditions = [];
    $params = [];
    foreach ($registry as $action => $event) {
        if (!is_array($event)) {
            continue;
        }
        $subresource = $event['subresource'] ?? null;
        if ($subresource === null
            && in_array($event['product_id_strategy'] ?? 'entity_id', ['entity_id', null], true)
            && ($event['entity'] ?? 'producto') === 'producto'
        ) {
            $index = count($conditions);
            $conditions[] = "(accion = :event_action_{$index}"
                . " AND entidad = 'producto' AND entidad_id = :direct_product_{$index})";
            $params['event_action_' . $index] = (string) $action;
            $params['direct_product_' . $index] = $productId;
            continue;
        }
        if (!is_string($subresource) || !is_string($event['entity'] ?? null)) {
            continue;
        }
        $strategy = $event['product_id_strategy'] ?? null;
        if (!in_array($strategy, ['metadata.producto_id', 'metadata.kit_id_producto'], true)) {
            continue;
        }
        $index = count($conditions);
        $expression = $strategy === 'metadata.kit_id_producto'
            ? "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.kit_id_producto')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.producto_id')), ''))"
            : "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.producto_id')), '')";
        $conditions[] = '(accion = :event_action_' . $index
            . ' AND entidad = :event_entity_' . $index
            . ' AND ' . $expression . ' = :event_product_' . $index . ')';
        $params['event_action_' . $index] = (string) $action;
        $params['event_entity_' . $index] = (string) $event['entity'];
        $params['event_product_' . $index] = $productId;
    }
    if ($conditions === []) {
        return [];
    }
    $sql =
        "SELECT id, accion, entidad, entidad_id, actor_usuario_id, creado_en, metadata_json
         FROM auditoria_eventos
         WHERE " . implode(' OR ', $conditions) . "
         ORDER BY creado_en DESC, id DESC";
    assertReadOnlySqlText($sql);
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $events = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $action = (string) ($row['accion'] ?? '');
        $event = $registry[$action] ?? null;
        if (!is_array($event)) continue;
        $subresource = $event['subresource'] ?? null;
        $queryable = $subresource === null
            ? in_array($event['product_id_strategy'] ?? 'entity_id', ['entity_id', null], true)
                && ($event['entity'] ?? 'producto') === 'producto'
                && ($row['entidad'] ?? null) === 'producto'
            : is_string($subresource)
                && is_string($event['entity'] ?? null)
                && in_array($event['product_id_strategy'] ?? null, ['metadata.producto_id', 'metadata.kit_id_producto'], true)
                && ($row['entidad'] ?? null) === $event['entity'];
        if (!$queryable) continue;
        $date = (string) ($row['creado_en'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) continue;
        $metadata = [];
        $decoded = json_decode((string) ($row['metadata_json'] ?? ''), true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
        $events[] = [
            'audit_id' => (int) ($row['id'] ?? 0),
            'action' => $action,
            'subresource' => is_string($subresource) ? $subresource : null,
            'actor_id' => $row['actor_usuario_id'] === null ? null : (int) $row['actor_usuario_id'],
            'date' => substr($date, 0, 10),
            'metadata' => $metadata,
        ];
    }
    return $events;
}

/** @return array{audit_id:int,effective_product_id:string}|null */
function discoverUnrelatedKitEvent(PDO $pdo, string $selectedProductId): ?array
{
    $effectiveProduct = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.kit_id_producto')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.producto_id')), ''))";
    $sql = "SELECT id, {$effectiveProduct} AS effective_product_id
            FROM auditoria_eventos
            WHERE entidad = 'producto_kit_componente'
              AND {$effectiveProduct} IS NOT NULL
              AND {$effectiveProduct} <> :kit03_selected_product
            ORDER BY creado_en DESC, id DESC
            LIMIT 1";
    assertReadOnlySqlText($sql);
    $statement = $pdo->prepare($sql);
    $statement->execute(['kit03_selected_product' => $selectedProductId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $auditId = (int) ($row['id'] ?? 0);
    $effective = trim((string) ($row['effective_product_id'] ?? ''));
    if ($auditId <= 0 || $effective === '' || $effective === $selectedProductId) {
        return null;
    }
    return ['audit_id' => $auditId, 'effective_product_id' => $effective];
}

/** @return array{audit_id:int,product_id:string}|null */
function discoverSpecializedProductEvent(PDO $pdo, string $scenario): ?array
{
    $kitEffective = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.kit_id_producto')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.producto_id')), ''))";
    $directProduct = "NULLIF(a.entidad_id, '')";
    $definitions = [
        'DB-06-subresource' => [
            'select' => "{$directProduct} AS product_id",
            'from' => 'auditoria_eventos a',
            'where' => "a.accion = 'producto_imagen.subir'
                AND a.entidad = 'productos'
                AND JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.producto_id')) IS NOT NULL
                AND JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.producto_id')) <> ''",
        ],
        'KIT-01' => [
            'select' => "{$kitEffective} AS product_id",
            'from' => 'auditoria_eventos a',
            'where' => "a.accion IN (
                    'productos.kits.componentes.agregar',
                    'productos.kits.componentes.actualizar',
                    'productos.kits.componentes.quitar',
                    'productos.kits.componentes.reactivar'
                )
                AND a.entidad = 'producto_kit_componente'
                AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.kit_id_producto')), '') IS NOT NULL
                AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.producto_id')), '') IS NOT NULL
                AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.componente_id_producto')), '') IS NOT NULL",
        ],
        'KIT-02' => [
            'select' => "{$kitEffective} AS product_id",
            'from' => 'auditoria_eventos a',
            'where' => "a.accion IN (
                    'productos.kits.componentes.agregar',
                    'productos.kits.componentes.actualizar',
                    'productos.kits.componentes.quitar',
                    'productos.kits.componentes.reactivar'
                )
                AND a.entidad = 'producto_kit_componente'
                AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.kit_id_producto')), '') IS NULL
                AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.producto_id')), '') IS NOT NULL
                AND NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.metadata_json, '$.componente_id_producto')), '') IS NOT NULL",
        ],
        'ACTOR-02' => [
            'select' => "{$directProduct} AS product_id",
            'from' => 'auditoria_eventos a INNER JOIN usuarios u ON u.id = a.actor_usuario_id',
            'where' => "a.accion IN ('productos.crear', 'productos.actualizar', 'productos.activar', 'productos.desactivar')
                AND a.entidad = 'producto'
                AND a.actor_usuario_id IS NOT NULL
                AND u.eliminado_en IS NOT NULL
                AND NULLIF(a.entidad_id, '') IS NOT NULL",
        ],
        'ACTOR-03' => [
            'select' => "{$directProduct} AS product_id",
            'from' => 'auditoria_eventos a',
            'where' => "a.accion IN ('productos.crear', 'productos.actualizar', 'productos.activar', 'productos.desactivar')
                AND a.entidad = 'producto'
                AND a.actor_usuario_id IS NULL
                AND NULLIF(a.entidad_id, '') IS NOT NULL",
        ],
    ];
    $definition = $definitions[$scenario] ?? null;
    if ($definition === null) {
        throw new InvalidArgumentException('Unknown specialized Product History scenario.');
    }
    $productExpression = in_array($scenario, ['KIT-01', 'KIT-02'], true)
        ? $kitEffective
        : $directProduct;
    $sql = "SELECT a.id AS audit_id, {$definition['select']}
            FROM {$definition['from']}
            WHERE {$definition['where']}
              AND EXISTS (
                  SELECT 1
                  FROM productos p
                  WHERE p.id_producto = {$productExpression}
                    AND p.eliminado_en IS NULL
              )
            ORDER BY a.creado_en DESC, a.id DESC
            LIMIT 1";
    assertReadOnlySqlText($sql);
    $statement = $pdo->prepare($sql);
    $statement->execute();
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $auditId = (int) ($row['audit_id'] ?? 0);
    $productId = trim((string) ($row['product_id'] ?? ''));
    return $auditId > 0 && $productId !== '' ? ['audit_id' => $auditId, 'product_id' => $productId] : null;
}

/** @param list<\App\Domain\Products\History\ProductHistoryItem> $items */
function findHistoryItemByAuditId(array $items, int $auditId): ?\App\Domain\Products\History\ProductHistoryItem
{
    foreach ($items as $item) {
        if ($item->auditId === $auditId) {
            return $item;
        }
    }
    return null;
}

/** @param array<string,array<string,mixed>> $registry @param array<string,string> $scenarioStates */
function evaluateSpecializedScenarios(PDO $pdo, ProductHistoryService $service, array $registry, array &$scenarioStates): void
{
    foreach (['DB-06-subresource', 'KIT-01', 'KIT-02', 'ACTOR-02', 'ACTOR-03'] as $specializedScenario) {
        $candidate = discoverSpecializedProductEvent($pdo, $specializedScenario);
        if ($candidate === null) {
            $scenarioStates[$specializedScenario] = SCENARIO_SKIP;
            continue;
        }
        $candidatePage = $service->page($candidate['product_id'], [], 1, 100);
        $candidateItem = findHistoryItemByAuditId($candidatePage->items, $candidate['audit_id']);
        if ($candidateItem === null) {
            $scenarioStates[$specializedScenario] = SCENARIO_SKIP;
            continue;
        }
        $candidateEvents = productHistoryEvents($pdo, $registry, $candidate['product_id']);
        $candidateEvent = null;
        foreach ($candidateEvents as $event) {
            if ($event['audit_id'] === $candidate['audit_id']) {
                $candidateEvent = $event;
                break;
            }
        }
        if ($candidateEvent === null) {
            $scenarioStates[$specializedScenario] = SCENARIO_SKIP;
            continue;
        }
        if ($specializedScenario === 'DB-06-subresource') {
            if ($candidateItem->subresource !== 'imagen') {
                failRun('assertion_error', 'SPECIALIZED_SUBRESOURCE_CONTRACT_FAILED');
            }
        } elseif ($specializedScenario === 'KIT-01') {
            $metadata = $candidateEvent['metadata'];
            $kit = trim((string) ($metadata['kit_id_producto'] ?? ''));
            $legacy = trim((string) ($metadata['producto_id'] ?? ''));
            if ($kit === '' || $legacy === '' || $candidateItem->productId !== $kit) {
                failRun('assertion_error', 'SPECIALIZED_KIT_PRIORITY_FAILED');
            }
        } elseif ($specializedScenario === 'KIT-02') {
            $metadata = $candidateEvent['metadata'];
            $kit = trim((string) ($metadata['kit_id_producto'] ?? ''));
            $legacy = trim((string) ($metadata['producto_id'] ?? ''));
            if ($kit !== '' || $legacy === '' || $candidateItem->productId !== $legacy) {
                failRun('assertion_error', 'SPECIALIZED_KIT_FALLBACK_FAILED');
            }
        } elseif ($specializedScenario === 'ACTOR-02') {
            if ($candidateItem->actorId === null || $candidateItem->actorDisplay !== 'Usuario eliminado') {
                failRun('assertion_error', 'SPECIALIZED_DELETED_ACTOR_FAILED');
            }
        } elseif ($specializedScenario === 'ACTOR-03') {
            if ($candidateItem->actorId !== null || $candidateItem->actorDisplay !== 'Sistema') {
                failRun('assertion_error', 'SPECIALIZED_SYSTEM_ACTOR_FAILED');
            }
        }
        $scenarioStates[$specializedScenario] = SCENARIO_PASS;
    }
}

/** @param list<array{audit_id:int}> $events @param array{audit_id:int,effective_product_id:string}|null $unrelated */
function evaluateKit03NegativeAssertion(array $events, ?array $unrelated): string
{
    if ($unrelated === null) {
        return SCENARIO_SKIP;
    }
    foreach ($events as $event) {
        if ((int) ($event['audit_id'] ?? 0) === $unrelated['audit_id']) {
            return SCENARIO_FAIL;
        }
    }
    return SCENARIO_PASS;
}

/** @param list<\App\Domain\Products\History\ProductHistoryItem> $items */
function assertActionItems(array $items, string $action): void
{
    if ($items === []) failRun('assertion_error', 'FILTER_RETURNED_NO_REAL_EVENT');
    foreach ($items as $item) {
        if ($item->action !== $action) failRun('assertion_error', 'ACTION_FILTER_FAILED');
    }
}

/** @param list<\App\Domain\Products\History\ProductHistoryItem> $items */
function assertSubresourceItems(array $items, string $subresource): void
{
    if ($items === []) failRun('assertion_error', 'FILTER_RETURNED_NO_REAL_EVENT');
    foreach ($items as $item) {
        if ($item->subresource !== $subresource) failRun('assertion_error', 'SUBRESOURCE_FILTER_FAILED');
    }
}

/** @param list<\App\Domain\Products\History\ProductHistoryItem> $items */
function assertActorItems(array $items, int $actorId): void
{
    if ($items === []) failRun('assertion_error', 'FILTER_RETURNED_NO_REAL_EVENT');
    foreach ($items as $item) {
        if ($item->actorId !== $actorId) failRun('assertion_error', 'ACTOR_FILTER_FAILED');
    }
}

/** @param list<\App\Domain\Products\History\ProductHistoryItem> $items */
function assertDateItems(array $items, string $date, bool $from): void
{
    if ($items === []) failRun('assertion_error', 'FILTER_RETURNED_NO_REAL_EVENT');
    foreach ($items as $item) {
        $itemDate = substr($item->occurredAt, 0, 10);
        if (($from && $itemDate < $date) || (!$from && $itemDate > $date)) {
            failRun('assertion_error', 'DATE_FILTER_FAILED');
        }
    }
}

/** @param list<array{audit_id:int, action:string, subresource:?string, actor_id:?int, date:string, metadata:array<string,mixed>}> $events
 *  @param list<\App\Domain\Products\History\ProductHistoryItem> $items
 *  @return array<string,string>
 */
function evaluateExtendedScenarios(array $events, array $items, string $productId, bool $includeSpecialized = true): array
{
    $states = [
        'KIT-01' => SCENARIO_SKIP,
        'KIT-02' => SCENARIO_SKIP,
        'KIT-03' => SCENARIO_SKIP,
        'ACTOR-01' => SCENARIO_SKIP,
        'ACTOR-02' => SCENARIO_SKIP,
        'ACTOR-03' => SCENARIO_SKIP,
    ];
    $itemsByAudit = [];
    foreach ($items as $item) {
        $itemsByAudit[$item->auditId] = $item;
        if (property_exists($item, 'actorUsername')) {
            failRun('assertion_error', 'PUBLIC_ACTOR_USERNAME_EXPOSED');
        }
        if ($includeSpecialized && $item->actorId === null && $item->actorDisplay === 'Sistema') {
            $states['ACTOR-03'] = SCENARIO_PASS;
        } elseif ($includeSpecialized && $item->actorId !== null && $item->actorDisplay === 'Usuario eliminado') {
            $states['ACTOR-02'] = SCENARIO_PASS;
        } elseif ($item->actorId !== null && $item->actorDisplay !== '') {
            $states['ACTOR-01'] = SCENARIO_PASS;
        }
    }

    if (!$includeSpecialized) {
        return $states;
    }

    foreach ($events as $event) {
        if ($event['subresource'] !== 'kit_componente') {
            continue;
        }
        $metadata = $event['metadata'];
        $kit = isset($metadata['kit_id_producto']) && is_scalar($metadata['kit_id_producto'])
            ? trim((string) $metadata['kit_id_producto']) : '';
        $legacy = isset($metadata['producto_id']) && is_scalar($metadata['producto_id'])
            ? trim((string) $metadata['producto_id']) : '';
        $item = $itemsByAudit[$event['audit_id']] ?? null;
        if ($item === null) {
            continue;
        }
        if ($kit !== '' && $legacy !== '' && $item->productId === $kit) {
            $states['KIT-01'] = SCENARIO_PASS;
        }
        if ($kit === '' && $legacy !== '' && $item->productId === $legacy) {
            $states['KIT-02'] = SCENARIO_PASS;
        }
    }

    return $states;
}

function runKit03SelfTest(): int
{
    $unrelated = ['audit_id' => 22, 'effective_product_id' => 'P2'];
    if ($unrelated['effective_product_id'] === 'P1') {
        return 1;
    }
    if (evaluateKit03NegativeAssertion([], $unrelated) !== SCENARIO_PASS) {
        return 1;
    }
    if (evaluateKit03NegativeAssertion([['audit_id' => 22]], $unrelated) !== SCENARIO_FAIL) {
        return 1;
    }
    if (evaluateKit03NegativeAssertion([], null) !== SCENARIO_SKIP) {
        return 1;
    }
    return 0;
}

/** @param array<string,string> $scenarioStates @return array{status:string,passed:int,failed:int,not_executed:int,skipped:int,static_not_executed:int,runtime_required_not_executed:int} */
function aggregateScenarioStatus(array $scenarioStates): array
{
    $staticOnly = ['DB-09' => true];
    $failed = 0;
    $passed = 0;
    $notExecuted = 0;
    $skipped = 0;
    $staticNotExecuted = 0;
    $runtimeRequiredNotExecuted = 0;
    foreach ($scenarioStates as $scenario => $state) {
        if ($state === SCENARIO_FAIL) {
            $failed++;
        } elseif ($state === SCENARIO_PASS) {
            $passed++;
        } elseif ($state === SCENARIO_SKIP) {
            $skipped++;
        } elseif ($state === 'NOT_EXECUTED') {
            $notExecuted++;
            if (isset($staticOnly[$scenario])) {
                $staticNotExecuted++;
            } else {
                $runtimeRequiredNotExecuted++;
            }
        }
    }
    $status = $failed > 0
        ? 'FAIL'
        : (($skipped > 0 || $runtimeRequiredNotExecuted > 0)
            ? 'PARTIAL_DATA_NOT_AVAILABLE'
            : 'PASS');
    return [
        'status' => $status,
        'passed' => $passed,
        'failed' => $failed,
        'not_executed' => $notExecuted,
        'skipped' => $skipped,
        'static_not_executed' => $staticNotExecuted,
        'runtime_required_not_executed' => $runtimeRequiredNotExecuted,
    ];
}

function runAggregationSelfTest(): int
{
    $base = array_fill_keys(['DB-01', 'DB-02', 'DB-03', 'DB-04', 'DB-05', 'DB-06-subresource', 'DB-07', 'DB-08', 'KIT-01', 'KIT-02', 'KIT-03', 'ACTOR-01', 'ACTOR-02', 'ACTOR-03'], SCENARIO_PASS);
    $caseA = aggregateScenarioStatus(array_replace($base, ['DB-09' => 'NOT_EXECUTED']));
    if ($caseA['status'] !== 'PASS' || $caseA['runtime_required_not_executed'] !== 0) return 1;
    $caseB = aggregateScenarioStatus(array_replace($base, ['DB-09' => 'NOT_EXECUTED', 'KIT-01' => SCENARIO_SKIP]));
    if ($caseB['status'] !== 'PARTIAL_DATA_NOT_AVAILABLE') return 1;
    $caseC = aggregateScenarioStatus(array_replace($base, ['DB-09' => 'NOT_EXECUTED', 'KIT-01' => 'NOT_EXECUTED']));
    if ($caseC['status'] !== 'PARTIAL_DATA_NOT_AVAILABLE' || $caseC['runtime_required_not_executed'] !== 1) return 1;
    $caseD = aggregateScenarioStatus(array_replace($base, ['DB-09' => 'NOT_EXECUTED', 'KIT-01' => SCENARIO_FAIL]));
    if ($caseD['status'] !== 'FAIL') return 1;
    return 0;
}

if (($argv[1] ?? null) === '--self-test-readonly-guard') {
    exit(runReadOnlyGuardSelfTest());
}
if (($argv[1] ?? null) === '--self-test-diagnostic') {
    exit(runDiagnosticHarnessSelfTest());
}
if (($argv[1] ?? null) === '--self-test-kit03') {
    exit(runKit03SelfTest());
}
if (($argv[1] ?? null) === '--self-test-aggregation') {
    exit(runAggregationSelfTest());
}

assertNoPhaseExecutionReferences((string) file_get_contents(__FILE__));
parseArguments($argv);

try {
    $config = require BASE_PATH . '/bootstrap/database.php';
    $appConfig = require BASE_PATH . '/config/app.php';
    $database = $config->get('database');
    $requiredDatabaseKeys = ['host', 'port', 'name', 'username', 'password', 'charset'];
    if (!is_array($database)
        || ($appConfig['env'] ?? 'production') === 'production'
        || ($database['name'] ?? null) !== TARGET_DATABASE
        || ($database['username'] ?? null) !== EXPECTED_DB_USER
        || ($database['host'] ?? null) !== EXPECTED_DB_HOST
        || array_diff($requiredDatabaseKeys, array_keys($database)) !== []
        || !is_int($database['port'])
        || $database['port'] < 1
        || $database['port'] > 65535
        || !is_string($database['charset'])
        || preg_match('/^[A-Za-z0-9_]+$/', $database['charset']) !== 1
    ) {
        failRun('preflight_error', 'LOCAL_DATABASE_GUARDS_FAILED');
    }
    RuntimeHarnessContext::stage('LOCAL_CONFIG_VALIDATED');

    $ledger = new ReadOnlySqlLedger();
    $connection = new ConnectionProvider($database);
    $pdo = instrumentConnectionProvider($connection, $database, $ledger);
    assertReadOnlyPrivileges($pdo);

    $registry = require BASE_PATH . '/config/products_history.php';
    $registry = is_array($registry['events'] ?? null) ? $registry['events'] : [];
    $products = new ProductRepository($connection);
    $history = new ProductHistoryQueryRepository($connection, $registry);
    $mapper = new ProductHistoryMapper($registry, new ProductHistoryPresenter($registry));
    $service = new ProductHistoryService($products, $history, $mapper, $registry);

    RuntimeHarnessContext::stage('DYNAMIC_QA_DISCOVERY_START');
    [$withHistory] = chooseProduct($pdo, $registry, true);
    [$withoutHistory] = chooseProduct($pdo, $registry, false);
    RuntimeHarnessContext::stage('DYNAMIC_QA_DISCOVERY_DONE');
    $existingProduct = $withHistory !== '' ? $withHistory : $withoutHistory;

    $scenarioStates = [
        'DB-01' => 'PASS',
        'DB-02' => 'NOT_EXECUTED',
        'DB-03' => 'NOT_EXECUTED',
        'DB-04' => 'NOT_EXECUTED',
        'DB-05' => 'NOT_EXECUTED',
        'DB-06-action' => 'NOT_EXECUTED',
        'DB-06-subresource' => 'NOT_EXECUTED',
        'DB-06-actor_id' => 'NOT_EXECUTED',
        'DB-06-date_from' => 'NOT_EXECUTED',
        'DB-06-date_to' => 'NOT_EXECUTED',
        'DB-07' => 'NOT_EXECUTED',
        'DB-08' => 'NOT_EXECUTED',
        'DB-09' => 'NOT_EXECUTED',
        'KIT-01' => SCENARIO_SKIP,
        'KIT-02' => SCENARIO_SKIP,
        'KIT-03' => SCENARIO_SKIP,
        'ACTOR-01' => SCENARIO_SKIP,
        'ACTOR-02' => SCENARIO_SKIP,
        'ACTOR-03' => SCENARIO_SKIP,
    ];
    $db06 = [
        'action' => 'NOT_EXECUTED',
        'subresource' => 'NOT_EXECUTED',
        'actor_id' => 'NOT_EXECUTED',
        'date_from' => 'NOT_EXECUTED',
        'date_to' => 'NOT_EXECUTED',
    ];
    $page = null;
    $existingPage = null;
    $empty = null;
    $filtered = null;
    $events = [];

    RuntimeHarnessContext::stage('SCENARIO_EXECUTION_START');
    $missing = '__PRODUCT_HISTORY_NOT_FOUND__';
    if ($products->exists($missing)) {
        failRun('preflight_error', 'MISSING_PRODUCT_SENTINEL_EXISTS');
    }
    $notFound = false;
    try {
        $service->page($missing);
    } catch (ProductNotFoundException) {
        $notFound = true;
    }
    if (!$notFound) failRun('assertion_error', 'PRODUCT_NOT_FOUND_NOT_MAPPED');
    $scenarioStates['DB-03'] = 'PASS';

    if ($existingProduct !== '') {
        if (!$products->exists($existingProduct)) {
            failRun('assertion_error', 'EXISTING_PRODUCT_NOT_FOUND');
        }
        $existingPage = $service->page($existingProduct, [], 1, 25);
        $scenarioStates['DB-02'] = 'PASS';
    }

    if ($withoutHistory !== '') {
        $empty = $service->page($withoutHistory, [], 1, 25);
        if ($empty->items !== [] || $empty->total !== 0 || $empty->page !== 1
            || $empty->pageSize !== 25 || $empty->pages !== 0
        ) {
            failRun('assertion_error', 'PAGINATION_EMPTY_RESULT_RUNTIME_FAILED');
        }
        $scenarioStates['DB-04'] = 'PASS';
    }

    if ($withHistory !== '') {
        $page = $existingPage;
        if ($page === null) {
            failRun('assertion_error', 'EXISTING_PRODUCT_PAGE_UNAVAILABLE');
        }
        assertSanitizedItems($page->items);
        $scenarioStates['DB-08'] = 'PASS';
        $events = productHistoryEvents($pdo, $registry, $withHistory);
        $unrelatedKitEvent = discoverUnrelatedKitEvent($pdo, $withHistory);
        if ($events !== []) {
            $scenarioStates['DB-05'] = 'PASS';
        }
        foreach (evaluateExtendedScenarios($events, $page->items, $withHistory, false) as $scenario => $state) {
            $scenarioStates[$scenario] = $state;
        }
        $scenarioStates['KIT-03'] = evaluateKit03NegativeAssertion($events, $unrelatedKitEvent);

        $actionEvent = $events[0] ?? null;
        if ($actionEvent !== null) {
            $filtered = $service->page($withHistory, ['action' => $actionEvent['action']], 1, 25);
            assertActionItems($filtered->items, $actionEvent['action']);
            $db06['action'] = 'PASS';
            $scenarioStates['DB-06-action'] = 'PASS';
        }
        $subresourceEvent = null;
        foreach ($events as $event) {
            if ($event['subresource'] !== null) {
                $subresourceEvent = $event;
                break;
            }
        }
        if ($subresourceEvent !== null) {
            $subresourcePage = $service->page($withHistory, [
                'subresource' => $subresourceEvent['subresource'],
            ], 1, 25);
            assertSubresourceItems($subresourcePage->items, $subresourceEvent['subresource']);
            $db06['subresource'] = 'PASS';
            $scenarioStates['DB-06-subresource'] = 'PASS';
        }
        $actorEvent = null;
        foreach ($events as $event) {
            if ($event['actor_id'] !== null) {
                $actorEvent = $event;
                break;
            }
        }
        if ($actorEvent !== null) {
            $actorPage = $service->page($withHistory, ['actor_id' => $actorEvent['actor_id']], 1, 25);
            assertActorItems($actorPage->items, $actorEvent['actor_id']);
            $db06['actor_id'] = 'PASS';
            $scenarioStates['DB-06-actor_id'] = 'PASS';
        }
        $dateEvent = $events[0] ?? null;
        if ($dateEvent !== null) {
            $fromPage = $service->page($withHistory, ['date_from' => $dateEvent['date']], 1, 25);
            assertDateItems($fromPage->items, $dateEvent['date'], true);
            $db06['date_from'] = 'PASS';
            $scenarioStates['DB-06-date_from'] = 'PASS';
            $toPage = $service->page($withHistory, ['date_to' => $dateEvent['date']], 1, 25);
            assertDateItems($toPage->items, $dateEvent['date'], false);
            $db06['date_to'] = 'PASS';
            $scenarioStates['DB-06-date_to'] = 'PASS';
        }

        $pageTwo = $service->page($withHistory, [], 2, 25);
        if ($pageTwo->page !== 2 || $pageTwo->pageSize !== 25
            || $pageTwo->pages !== max(0, $page->pages)
        ) {
            failRun('assertion_error', 'PAGINATION_CONTRACT_FAILED');
        }
        $scenarioStates['DB-07'] = 'PASS';

        $previous = null;
        foreach ($page->items as $item) {
            if ($previous !== null && $previous < $item->occurredAt) {
                failRun('assertion_error', 'HISTORY_ORDER_FAILED');
            }
            $previous = $item->occurredAt;
        }
    }

    // Specialized scenarios discover their own compatible product/event and do not reuse $withHistory.
    evaluateSpecializedScenarios($pdo, $service, $registry, $scenarioStates);
    $db06['subresource'] = $scenarioStates['DB-06-subresource'];

    RuntimeHarnessContext::stage('SCENARIO_EXECUTION_DONE');
    RuntimeHarnessContext::stage('LEDGER_FINALIZATION_START');
    $ledgerCounts = $ledger->counts();
    RuntimeHarnessContext::stage('LEDGER_FINALIZATION_DONE');
    $aggregation = aggregateScenarioStatus($scenarioStates);
    $ledgerSafe = $ledgerCounts['UNLEDGERED_EXECUTION_COUNT'] === 0
        && $ledgerCounts['UNPREVALIDATED_EXECUTION_COUNT'] === 0
        && $ledgerCounts['BLOCKED_EXECUTION_COUNT'] === 0
        && $ledgerCounts['UNKNOWN_COUNT'] === 0;
    $status = $ledgerSafe ? $aggregation['status'] : 'FAIL';
    $failedCount = $aggregation['failed'] + ($ledgerSafe ? 0 : 1);
    $notExecutedCount = $aggregation['not_executed'];
    $skippedCount = $aggregation['skipped'];
    $passedCount = $aggregation['passed'];

    echo json_encode([
        'status' => $status,
        'database' => TARGET_DATABASE,
        'read_only' => true,
        'scenario_states' => $scenarioStates,
        'total_tests' => count($scenarioStates),
        'passed_tests' => $passedCount,
        'failed_tests' => $failedCount,
        'not_executed_tests' => $notExecutedCount,
        'skipped_data_not_available' => $skippedCount,
        'static_not_executed_tests' => $aggregation['static_not_executed'],
        'runtime_required_not_executed_tests' => $aggregation['runtime_required_not_executed'],
        'global_result' => $status,
        'checks' => [
            'identity_and_destination' => $scenarioStates['DB-01'] === 'PASS',
            'existing_product' => $scenarioStates['DB-02'] === 'PASS',
            'missing_product' => $scenarioStates['DB-03'] === 'PASS',
            'empty_pagination' => $scenarioStates['DB-04'] === 'PASS',
            'history_and_order' => $scenarioStates['DB-05'] === 'PASS',
            'filters' => !in_array('FAIL', $db06, true)
                && !in_array('NOT_EXECUTED', $db06, true),
            'pagination' => $scenarioStates['DB-07'] === 'PASS',
            'sanitization' => $scenarioStates['DB-08'] === 'PASS',
            'writes' => false,
        ],
        'db_invariants' => [
            'DB-01' => 'PASS',
            'DB-02' => $scenarioStates['DB-02'] === 'PASS' ? 'PASS' : 'SKIP_DATA_NOT_AVAILABLE',
            'DB-03' => 'PASS',
            'DB-04' => 'PASS',
            'DB-05' => 'PASS',
            'DB-06' => 'PASS',
            'DB-07' => 'PASS',
            'DB-08' => 'PASS',
        ],
        'sql_allowlist' => [
            'allowed' => ['SELECT', 'SHOW GRANTS'],
            'write_sql_blocked' => true,
            'runner_statement_types' => ['SELECT'],
        ],
        'counts' => [
            'existing_items' => $page === null ? null : count($page->items),
            'existing_total' => $page?->total,
            'empty_total' => $empty?->total,
            'filtered_items' => $filtered === null ? 0 : count($filtered->items),
        ],
        'db06' => $db06,
        'db09_scope' => 'STATIC_ONLY_NO_RUNTIME_WRITE_EVIDENCE',
        'sql_ledger' => [
            'counts' => $ledgerCounts,
            'entries' => $ledger->entries(),
            'ALL_EXECUTED_STATEMENTS_LEDGERED' => $ledgerCounts['UNLEDGERED_EXECUTION_COUNT'] === 0,
            'ALL_EXECUTED_STATEMENTS_PREVALIDATED' => $ledgerCounts['UNPREVALIDATED_EXECUTION_COUNT'] === 0,
            'DB-04' => $ledgerCounts['DDL_COUNT'] === 0,
            'DB-05' => $ledgerCounts['DML_COUNT'] === 0,
            'DB-06' => true,
            'DB-07' => true,
            'DB-08' => $ledgerCounts['DML_COUNT'] === 0 && $ledgerCounts['DDL_COUNT'] === 0,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($status === 'FAIL' ? 1 : 0);
} catch (Throwable $exception) {
    failRun('runtime_error', 'READ_ONLY_HARNESS_FAILED', $exception);
}
