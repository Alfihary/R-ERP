<?php

declare(strict_types=1);

use App\Infrastructure\Database\Connection;

define('BASE_PATH', dirname(__DIR__, 2));

const QA_DATABASE = 'r_erp_tickets_qa_2f';
const QA_ACCOUNT = 'r_erp_tickets_qa_runner@localhost';
const QA_USER = 'r_erp_tickets_qa_runner';
const QA_HOST = 'GER005OTU';
const QA_PORT = 3306;

$GLOBALS['qaDiagnosticContext'] = [
    'scenario' => null,
    'checkpoint' => 'startup',
    'rollback_status' => 'NOT_STARTED',
    'postcheck_status' => 'NOT_STARTED',
    'original_checkpoint' => null,
];

function setDiagnosticCheckpoint(string $checkpoint, ?string $scenario = null): void
{
    $GLOBALS['qaDiagnosticContext']['checkpoint'] = $checkpoint;
    if ($scenario !== null) {
        $GLOBALS['qaDiagnosticContext']['scenario'] = $scenario;
    }
}

function setDiagnosticStatus(string $field, string $status): void
{
    $GLOBALS['qaDiagnosticContext'][$field] = $status;
}

function diagnosticCategory(string $checkpoint): string
{
    if ($checkpoint === 'cli') {
        return 'CLI';
    }
    if ($checkpoint === 'environment') {
        return 'ENVIRONMENT';
    }
    if ($checkpoint === 'connection') {
        return 'DATABASE';
    }
    if (str_starts_with($checkpoint, 'identity')) {
        return 'IDENTITY';
    }
    if (str_starts_with($checkpoint, 'grants')) {
        return 'GRANTS';
    }
    if (str_starts_with($checkpoint, 'schema')) {
        return 'SCHEMA';
    }
    if (str_starts_with($checkpoint, 'structural_contracts')) {
        return 'STRUCTURAL_CONTRACTS';
    }
    if (str_starts_with($checkpoint, 'triggers')) {
        return 'TRIGGERS';
    }
    if (str_starts_with($checkpoint, 'baseline')) {
        return 'BASELINE';
    }
    if (str_starts_with($checkpoint, 'fixture_token')) {
        return 'FIXTURE_TOKEN';
    }
    if (str_starts_with($checkpoint, 'transaction')) {
        return 'TRANSACTION';
    }
    if (str_starts_with($checkpoint, 'first_series') || str_starts_with($checkpoint, 'second_series')) {
        return 'DATABASE';
    }
    if (str_starts_with($checkpoint, 'expected_result')) {
        return 'DUPLICATE_ASSERTION';
    }
    if ($checkpoint === 'rollback') {
        return 'ROLLBACK';
    }
    if ($checkpoint === 'postcheck') {
        return 'POSTCHECK';
    }
    if (str_starts_with($checkpoint, 'fixture_')) {
        return 'FIXTURE';
    }
    return 'UNKNOWN';
}

function diagnosticExceptionClass(Throwable $exception): string
{
    return $exception instanceof PDOException ? 'PDOException'
        : ($exception instanceof RuntimeException ? 'RuntimeException'
            : ($exception instanceof LogicException ? 'LogicException'
                : ($exception instanceof InvalidArgumentException ? 'InvalidArgumentException' : 'Throwable')));
}

function findPdoException(Throwable $exception): ?PDOException
{
    for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
        if ($current instanceof PDOException) {
            return $current;
        }
    }
    return null;
}

function emitSanitizedFailure(Throwable $exception): never
{
    $context = $GLOBALS['qaDiagnosticContext'];
    $pdoException = findPdoException($exception);
    $errorInfo = $pdoException?->errorInfo;
    $reportedCheckpoint = $context['rollback_status'] === 'FAILED_OR_UNCERTAIN'
        ? 'rollback'
        : ($context['original_checkpoint']
            ?? ($context['postcheck_status'] === 'FAILED_OR_UNCERTAIN' ? 'postcheck' : $context['checkpoint']));
    $payload = [
        'status' => $context['rollback_status'] === 'FAILED_OR_UNCERTAIN' || $context['postcheck_status'] === 'FAILED_OR_UNCERTAIN' ? 'BLOCKED' : 'FAIL',
        'scenario' => $context['scenario'],
        'checkpoint' => $reportedCheckpoint,
        'original_checkpoint' => $context['original_checkpoint'],
        'category' => diagnosticCategory($reportedCheckpoint),
        'exception_class' => diagnosticExceptionClass($exception),
        'sqlstate' => is_array($errorInfo) && isset($errorInfo[0]) ? (string) $errorInfo[0] : null,
        'mysql_error_code' => is_array($errorInfo) && isset($errorInfo[1]) ? (int) $errorInfo[1] : null,
        'rollback_status' => $context['rollback_status'],
        'postcheck_status' => $context['postcheck_status'],
        'exit_code' => 1,
    ];
    fwrite(STDERR, json_encode($payload, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}

function cliOptions(array $argv): array
{
    if (($argv[1] ?? null) !== 'preflight' && ($argv[1] ?? null) !== 'test') {
        throw new RuntimeException('Use preflight or test.');
    }

    $options = ['mode' => $argv[1]];
    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new RuntimeException('Invalid CLI argument.');
        }
        [$key, $value] = explode('=', substr($argument, 2), 2);
        $options[$key] = $value;
    }

    if ($options['mode'] === 'test'
        && (($options['database'] ?? null) !== QA_DATABASE
            || ($options['confirm-database'] ?? null) !== QA_DATABASE
            || ($options['account'] ?? null) !== QA_ACCOUNT
            || ($options['expected-host'] ?? null) !== QA_HOST
            || ($options['expected-port'] ?? null) !== (string) QA_PORT)
    ) {
        throw new RuntimeException('Explicit QA database, account and server confirmation are required.');
    }

    return $options;
}

/** @return array<string, string> */
function processEnvironment(): array
{
    $keys = ['APP_ENV', 'APP_DB_HOST', 'APP_DB_PORT', 'APP_DB_NAME', 'APP_DB_USER', 'APP_DB_PASSWORD', 'APP_DB_CHARSET', 'APP_DB_COLLATION'];
    $environment = [];
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value === false || $value === '') {
            throw new RuntimeException('Required process environment is missing.');
        }
        $environment[$key] = $value;
    }
    if (strtolower($environment['APP_ENV']) === 'production'
        || $environment['APP_DB_NAME'] !== QA_DATABASE
        || $environment['APP_DB_USER'] !== QA_USER
        || $environment['APP_DB_PORT'] !== (string) QA_PORT
        || $environment['APP_DB_CHARSET'] !== 'utf8mb4'
        || $environment['APP_DB_COLLATION'] !== 'utf8mb4_unicode_ci'
        || !in_array(strtolower($environment['APP_DB_HOST']), ['localhost', '127.0.0.1', 'ger005otu'], true)
    ) {
        throw new RuntimeException('Process environment does not match the approved QA runner target.');
    }
    return $environment;
}

function openQaConnection(array $environment): PDO
{
    require BASE_PATH . '/bootstrap/autoload.php';
    $config = require BASE_PATH . '/bootstrap/database.php';
    $database = $config->get('database', []);
    foreach (['host' => 'APP_DB_HOST', 'port' => 'APP_DB_PORT', 'name' => 'APP_DB_NAME', 'username' => 'APP_DB_USER', 'charset' => 'APP_DB_CHARSET', 'collation' => 'APP_DB_COLLATION'] as $field => $key) {
        if (($field === 'port' ? (string) ($database[$field] ?? '') : (string) ($database[$field] ?? '')) !== $environment[$key]) {
            throw new RuntimeException('Loaded database configuration does not match the QA process target.');
        }
    }
    return Connection::create($database);
}

function assertIdentityAndGrants(PDO $pdo): void
{
    $identity = $pdo->query('SELECT DATABASE(), USER(), CURRENT_USER(), @@hostname, @@port')->fetch(PDO::FETCH_NUM);
    if (!is_array($identity)) {
        throw new RuntimeException('QA connection identity is unavailable.');
    }
    $presented = (string) ($identity[1] ?? '');
    $presentedParts = strrpos($presented, '@');
    $presentedUser = $presentedParts === false ? '' : substr($presented, 0, $presentedParts);
    $presentedHost = $presentedParts === false ? '' : substr($presented, $presentedParts + 1);
    if ($identity[0] !== QA_DATABASE
        || $identity[2] !== QA_ACCOUNT
        || $identity[3] !== QA_HOST
        || (int) $identity[4] !== QA_PORT
        || $presentedUser !== QA_USER
        || !in_array(strtolower($presentedHost), ['localhost', '127.0.0.1', strtolower(QA_HOST)], true)
    ) {
        throw new RuntimeException('QA connection identity is not authorized.');
    }

    $rows = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    if ($rows === []) {
        throw new RuntimeException('QA runner grants are missing.');
    }
    $effectiveDml = [];
    foreach ($rows as $row) {
        $grant = trim((string) $row);
        if (stripos($grant, ' WITH GRANT OPTION') !== false) {
            throw new RuntimeException('QA runner grants include GRANT OPTION.');
        }
        if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/i', $grant, $matches) !== 1) {
            throw new RuntimeException('Unknown SHOW GRANTS format.');
        }
        $privileges = array_map(static fn (string $value): string => strtoupper(trim($value)), explode(',', $matches[1]));
        $scope = trim($matches[2]);
        if ($scope === '*.*') {
            if ($privileges !== ['USAGE']) {
                throw new RuntimeException('Unexpected global privilege.');
            }
            continue;
        }
        $scope = str_replace('`', '', $scope);
        if ($scope !== QA_DATABASE . '.*' || array_diff($privileges, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']) !== []) {
            throw new RuntimeException('Unexpected QA grant scope or privilege.');
        }
        foreach ($privileges as $privilege) {
            $effectiveDml[$privilege] = true;
        }
    }
    if (array_diff(['SELECT', 'INSERT', 'UPDATE', 'DELETE'], array_keys($effectiveDml)) !== []) {
        throw new RuntimeException('Required QA DML privileges are incomplete.');
    }
}

function assertSchema(PDO $pdo): void
{
    $tables = ['empresas', 'almacenes', 'series_documentales'];
    foreach ($tables as $table) {
        $statement = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $statement->execute(['table' => $table]);
        if ($statement->fetchColumn() !== 'InnoDB') {
            throw new RuntimeException('Required table engine is not InnoDB.');
        }
    }
    $migration = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration');
    $migration->execute(['migration' => 'folios_1_002_reserve_global_documentary_prefixes']);
    if ((int) $migration->fetchColumn() !== 1) {
        throw new RuntimeException('Global prefix migration is not registered exactly once.');
    }
    $column = $pdo->query("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME, EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'series_documentales' AND COLUMN_NAME = 'prefijo_reserva'")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($column)
        || strtoupper((string) $column['DATA_TYPE']) !== 'VARCHAR'
        || (int) $column['CHARACTER_MAXIMUM_LENGTH'] !== 20
        || $column['CHARACTER_SET_NAME'] !== 'ascii'
        || $column['COLLATION_NAME'] !== 'ascii_bin'
        || !str_contains(strtoupper((string) $column['EXTRA']), 'STORED GENERATED')
        || strtoupper(str_replace('`', '', (string) $column['GENERATION_EXPRESSION'])) !== 'UPPER(PREFIJO)'
    ) {
        throw new RuntimeException('Generated prefix reservation column contract is invalid.');
    }
    $index = $pdo->query("SELECT NON_UNIQUE, INDEX_NAME, COLUMN_NAME, SUB_PART, IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'series_documentales' AND INDEX_NAME = 'uq_series_documentales_prefijo_global' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    if (count($index) !== 1 || (int) $index[0]['NON_UNIQUE'] !== 0 || $index[0]['COLUMN_NAME'] !== 'prefijo_reserva' || $index[0]['SUB_PART'] !== null || $index[0]['IS_VISIBLE'] !== 'YES') {
        throw new RuntimeException('Global prefix unique index contract is invalid.');
    }
}

function assertStructuralContracts(PDO $pdo): void
{
    $foreignKeys = [
        'fk_almacenes_empresa' => 'empresa_id=empresas.id',
        'fk_series_documentales_empresa' => 'empresa_id=empresas.id',
        'fk_series_documentales_almacen' => 'almacen_id=almacenes.id',
        'fk_series_documentales_empresa_almacen' => 'empresa_id=almacenes.empresa_id,almacen_id=almacenes.id',
    ];
    foreach ($foreignKeys as $constraint => $expected) {
        $statement = $pdo->prepare(
            "SELECT GROUP_CONCAT(CONCAT(COLUMN_NAME, '=', REFERENCED_TABLE_NAME, '.', REFERENCED_COLUMN_NAME) ORDER BY ORDINAL_POSITION SEPARATOR ',')
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('almacenes', 'series_documentales') AND CONSTRAINT_NAME = :constraint"
        );
        $statement->execute(['constraint' => $constraint]);
        if ((string) $statement->fetchColumn() !== $expected) {
            throw new RuntimeException('Required foreign-key contract is invalid.');
        }
    }

    $scope = $pdo->query("SELECT COLUMN_NAME, SUB_PART, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'series_documentales' AND INDEX_NAME = 'uq_series_documentales_scope' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    if (count($scope) !== 4
        || array_column($scope, 'COLUMN_NAME') !== ['empresa_id', 'almacen_id', 'tipo_documento', 'codigo_serie']
        || array_filter($scope, static fn (array $row): bool => (int) $row['NON_UNIQUE'] !== 0 || $row['SUB_PART'] !== null) !== []
    ) {
        throw new RuntimeException('Required series scope unique index is invalid.');
    }

    $required = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'empresas' AND COLUMN_NAME IN ('codigo', 'nombre')) OR (TABLE_NAME = 'almacenes' AND COLUMN_NAME IN ('empresa_id', 'codigo', 'nombre')) OR (TABLE_NAME = 'series_documentales' AND COLUMN_NAME IN ('empresa_id', 'almacen_id', 'tipo_documento', 'codigo_serie', 'prefijo', 'codigo_almacen_snapshot')))")->fetchAll(PDO::FETCH_ASSOC);
    if (count($required) !== 11 || count(array_filter($required, static fn (array $row): bool => $row['IS_NULLABLE'] !== 'NO')) !== 0) {
        throw new RuntimeException('Required fixture columns are missing or nullable.');
    }
}

function assertNoUnexpectedTriggers(PDO $pdo): void
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN ('empresas', 'almacenes', 'series_documentales')");
    $statement->execute();
    if ((int) $statement->fetchColumn() !== 0) {
        throw new RuntimeException('Unexpected triggers are present on fixture tables.');
    }
}

function baseline(PDO $pdo): array
{
    $baseline = [];
    foreach (['empresas', 'almacenes', 'series_documentales', 'documentos_folios', 'tickets_productos'] as $table) {
        $baseline[$table] = $pdo->query('SELECT COUNT(*) AS row_count, COALESCE(MAX(id), 0) AS max_id, COALESCE(SUM(id), 0) AS id_sum FROM `' . $table . '`')->fetch(PDO::FETCH_ASSOC);
    }
    return $baseline;
}

function fixtureToken(): string
{
    return strtoupper(bin2hex(random_bytes(5)));
}

function assertFixtureTokenAbsent(PDO $pdo, string $token): void
{
    foreach ([
        ['empresas', 'codigo', 'qaf67c%' . strtolower($token)],
        ['almacenes', 'codigo', 'qaf67w%' . strtolower($token)],
        ['series_documentales', 'prefijo', 'F67%' . substr($token, 0, 4)],
    ] as [$table, $column, $pattern]) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` LIKE :pattern');
        $statement->execute(['pattern' => $pattern]);
        if ((int) $statement->fetchColumn() !== 0) {
            throw new RuntimeException('Generated QA fixture token is already present.');
        }
    }
}

function captureFixtureIds(PDO $pdo, string $token): array
{
    $ids = [];
    foreach ([
        ['empresas', 'codigo', 'qaf67c%' . strtolower($token)],
        ['almacenes', 'codigo', 'qaf67w%' . strtolower($token)],
        ['series_documentales', 'prefijo', 'F67%' . substr($token, 0, 4)],
    ] as [$table, $column, $pattern]) {
        $statement = $pdo->prepare('SELECT id FROM `' . $table . '` WHERE `' . $column . '` LIKE :pattern ORDER BY id');
        $statement->execute(['pattern' => $pattern]);
        $ids[$table] = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
    return $ids;
}

function assertFixtureIdsAbsent(PDO $pdo, array $ids): void
{
    foreach ($ids as $table => $tableIds) {
        foreach ($tableIds as $id) {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE id = :id');
            $statement->execute(['id' => $id]);
            if ((int) $statement->fetchColumn() !== 0) {
                throw new RuntimeException('BLOCKED_RESIDUE_OR_UNCERTAIN_STATE');
            }
        }
    }
}

function insertCompany(PDO $pdo, string $token, int $number): int
{
    $code = strtolower('qaf67c' . $number . $token);
    $statement = $pdo->prepare('INSERT INTO empresas (codigo, nombre, activo) VALUES (:codigo, :nombre, 1)');
    $statement->execute(['codigo' => $code, 'nombre' => 'QA F67 Company ' . $number . ' ' . $token]);
    $id = (int) $pdo->lastInsertId();
    if ($id < 1) {
        throw new RuntimeException('Company ID capture failed.');
    }
    return $id;
}

function insertWarehouse(PDO $pdo, int $companyId, string $token, int $number): int
{
    $code = strtolower('qaf67w' . $number . $token);
    $statement = $pdo->prepare('INSERT INTO almacenes (empresa_id, codigo, nombre, activo) VALUES (:empresa_id, :codigo, :nombre, 1)');
    $statement->execute(['empresa_id' => $companyId, 'codigo' => $code, 'nombre' => 'QA F67 Warehouse ' . $number . ' ' . $token]);
    $id = (int) $pdo->lastInsertId();
    if ($id < 1) {
        throw new RuntimeException('Warehouse ID capture failed.');
    }
    return $id;
}

function insertSeries(PDO $pdo, int $companyId, int $warehouseId, string $prefix, string $type, string $series, int $active = 1, ?string $deletedAt = null): int
{
    $statement = $pdo->prepare('INSERT INTO series_documentales (empresa_id, almacen_id, tipo_documento, codigo_serie, prefijo, codigo_almacen_snapshot, formato, separador, siguiente_numero, longitud, reinicio_anual, activo, eliminado_en) VALUES (:empresa_id, :almacen_id, :tipo_documento, :codigo_serie, :prefijo, :snapshot, :formato, :separador, 1, 6, 0, :activo, :eliminado_en)');
    $statement->execute(['empresa_id' => $companyId, 'almacen_id' => $warehouseId, 'tipo_documento' => $type, 'codigo_serie' => $series, 'prefijo' => $prefix, 'snapshot' => 'qaf67w' . substr(strtolower($prefix), -6), 'formato' => '{PREFIJO}-{ALMACEN}{NUMERO}', 'separador' => '-', 'activo' => $active, 'eliminado_en' => $deletedAt]);
    return (int) $pdo->lastInsertId();
}

function assertReservation(PDO $pdo, int $seriesId, string $prefix): void
{
    $statement = $pdo->prepare('SELECT prefijo_reserva FROM series_documentales WHERE id = :id');
    $statement->execute(['id' => $seriesId]);
    if ($statement->fetchColumn() !== $prefix) {
        throw new RuntimeException('Generated prefix reservation does not match the fixture.');
    }
}

function extractExpectedGlobalIndexReference(string $message): ?string
{
    $allowedReferences = [
        "'uq_series_documentales_prefijo_global'",
        '`uq_series_documentales_prefijo_global`',
        "'series_documentales.uq_series_documentales_prefijo_global'",
        '`series_documentales.uq_series_documentales_prefijo_global`',
        "'r_erp_tickets_qa_2f.series_documentales.uq_series_documentales_prefijo_global'",
        '`r_erp_tickets_qa_2f.series_documentales.uq_series_documentales_prefijo_global`',
        '`series_documentales`.`uq_series_documentales_prefijo_global`',
        '`r_erp_tickets_qa_2f`.`series_documentales`.`uq_series_documentales_prefijo_global`',
    ];

    foreach ($allowedReferences as $reference) {
        $pattern = '/\ADuplicate entry[ \t]+\'([^\'\\\\\r\n\t]*)\'[ \t]+(?i:for[ \t]+key)[ \t]+' . preg_quote($reference, '/') . '\z/';
        if (preg_match($pattern, $message) === 1) {
            return $reference;
        }
    }

    return null;
}

function isExpectedGlobalIndexReference(string $message): bool
{
    return extractExpectedGlobalIndexReference($message) !== null;
}

function isExpectedDuplicate(PDOException $exception): bool
{
    $info = $exception->errorInfo;
    $message = (string) ($info[2] ?? $exception->getMessage());
    return (string) ($info[0] ?? $exception->getCode()) === '23000'
        && (int) ($info[1] ?? 0) === 1062
        && isExpectedGlobalIndexReference($message);
}

/** @return array{duplicate_rejected: bool, constraint: string} */
function expectGlobalDuplicate(PDO $pdo, callable $secondInsert): array
{
    try {
        $secondInsert($pdo);
    } catch (PDOException $exception) {
        if (!isExpectedDuplicate($exception)) {
            throw new RuntimeException('FAIL_UNEXPECTED_SQL_ERROR', 0, $exception);
        }

        return ['duplicate_rejected' => true, 'constraint' => 'uq_series_documentales_prefijo_global'];
    }

    throw new RuntimeException('FAIL_UNEXPECTED_DUPLICATE_ACCEPTED');
}

function runScenario(PDO $pdo, string $name, string $expectedOutcome, callable $scenario): array
{
    setDiagnosticCheckpoint('fixture_token', $name);
    setDiagnosticStatus('rollback_status', 'NOT_APPLICABLE');
    setDiagnosticStatus('postcheck_status', 'NOT_STARTED');
    $token = fixtureToken();
    $result = [
        'scenario' => $name,
        'expected_outcome' => $expectedOutcome,
        'status' => 'FAIL',
        'rollback' => false,
        'constraint' => null,
        'duplicate_rejected' => false,
        'fixture_ids' => [],
    ];
    try {
        assertFixtureTokenAbsent($pdo, $token);
        setDiagnosticCheckpoint('transaction_begin', $name);
        $pdo->beginTransaction();
        $evidence = $scenario($pdo, $token, $result);
        setDiagnosticCheckpoint('expected_result', $name);
        if ($expectedOutcome === 'GLOBAL_DUPLICATE_REJECTED'
            && (($evidence['duplicate_rejected'] ?? false) !== true
                || ($evidence['constraint'] ?? null) !== 'uq_series_documentales_prefijo_global')) {
            $result['status'] = 'FAIL_EXPECTED_SECOND_INSERT_REJECTION_NOT_OBSERVED';
            throw new RuntimeException('FAIL_EXPECTED_SECOND_INSERT_REJECTION_NOT_OBSERVED');
        }
        if ($expectedOutcome === 'TWO_DISTINCT_PREFIXES_ACCEPTED' && (($evidence['accepted_count'] ?? 0) !== 2)) {
            $result['status'] = 'FAIL_UNEXPECTED_SQL_ERROR';
            throw new RuntimeException('FAIL_UNEXPECTED_SQL_ERROR');
        }
        $result['status'] = 'PASS';
        $result = array_merge($result, $evidence);
    } catch (Throwable $exception) {
        $GLOBALS['qaDiagnosticContext']['original_checkpoint'] = $GLOBALS['qaDiagnosticContext']['checkpoint'];
        if ($result['status'] === 'FAIL') {
            $result['status'] = $exception->getMessage() === 'FAIL_UNEXPECTED_DUPLICATE_ACCEPTED'
                ? 'FAIL_UNEXPECTED_DUPLICATE_ACCEPTED'
                : ($exception->getMessage() === 'FAIL_UNEXPECTED_SQL_ERROR'
                    ? 'FAIL_UNEXPECTED_SQL_ERROR'
                    : 'FAIL_FIRST_INSERT_OR_FIXTURE_ERROR');
        }
        throw $exception;
    } finally {
        $captureError = null;
        if ($pdo->inTransaction()) {
            setDiagnosticCheckpoint('rollback', $name);
            setDiagnosticStatus('rollback_status', 'IN_PROGRESS');
            try {
                $result['fixture_ids'] = captureFixtureIds($pdo, $token);
            } catch (Throwable $exception) {
                $captureError = $exception;
            }
        }
        if ($pdo->inTransaction()) {
            try {
                $rolledBack = $pdo->rollBack();
            } catch (Throwable $exception) {
                setDiagnosticStatus('rollback_status', 'FAILED_OR_UNCERTAIN');
                throw $exception;
            }
            if (!$rolledBack) {
                setDiagnosticStatus('rollback_status', 'FAILED_OR_UNCERTAIN');
                throw new RuntimeException('BLOCKED_ROLLBACK_OR_UNCERTAIN_STATE');
            }
            $result['rollback'] = true;
            setDiagnosticStatus('rollback_status', 'PASS');
        }
        if ($captureError instanceof Throwable) {
            throw $captureError;
        }
        setDiagnosticCheckpoint('postcheck', $name);
        setDiagnosticStatus('postcheck_status', 'IN_PROGRESS');
        try {
            assertFixtureIdsAbsent($pdo, $result['fixture_ids']);
            assertFixtureTokenAbsent($pdo, $token);
            assertSchema($pdo);
            assertStructuralContracts($pdo);
            setDiagnosticStatus('postcheck_status', 'PASS');
        } catch (Throwable $exception) {
            setDiagnosticStatus('postcheck_status', 'FAILED_OR_UNCERTAIN');
            throw $exception;
        }
    }
    return $result;
}

function runTest(PDO $pdo): array
{
    $results = [];
    $results[] = runScenario($pdo, 'A-global-basic', 'GLOBAL_DUPLICATE_REJECTED', static function (PDO $pdo, string $token): array {
        setDiagnosticCheckpoint('fixture_company', 'A-global-basic');
        $c1 = insertCompany($pdo, $token, 1); $c2 = insertCompany($pdo, $token, 2);
        setDiagnosticCheckpoint('fixture_warehouse', 'A-global-basic');
        $w1 = insertWarehouse($pdo, $c1, $token, 1); $w2 = insertWarehouse($pdo, $c2, $token, 2);
        $prefix = 'F67A' . substr($token, 0, 4);
        setDiagnosticCheckpoint('first_series', 'A-global-basic');
        $s1 = insertSeries($pdo, $c1, $w1, $prefix, 'TICKET_A', 'SERIE_A');
        setDiagnosticCheckpoint('first_series_verification', 'A-global-basic');
        assertReservation($pdo, $s1, $prefix);
        setDiagnosticCheckpoint('second_series', 'A-global-basic');
        return expectGlobalDuplicate($pdo, static fn (PDO $pdo): int => insertSeries($pdo, $c2, $w2, $prefix, 'TICKET_B', 'SERIE_B'));
    });
    $results[] = runScenario($pdo, 'B-different-companies', 'GLOBAL_DUPLICATE_REJECTED', static function (PDO $pdo, string $token): array {
        setDiagnosticCheckpoint('fixture_company', 'B-different-companies');
        $c1 = insertCompany($pdo, $token, 1); $c2 = insertCompany($pdo, $token, 2);
        setDiagnosticCheckpoint('fixture_warehouse', 'B-different-companies');
        $w1 = insertWarehouse($pdo, $c1, $token, 1); $w2 = insertWarehouse($pdo, $c2, $token, 2);
        $prefix = 'F67B' . substr($token, 0, 4);
        setDiagnosticCheckpoint('first_series', 'B-different-companies');
        $s1 = insertSeries($pdo, $c1, $w1, $prefix, 'TICKET_A', 'SERIE_A');
        setDiagnosticCheckpoint('first_series_verification', 'B-different-companies');
        assertReservation($pdo, $s1, $prefix);
        setDiagnosticCheckpoint('second_series', 'B-different-companies');
        return expectGlobalDuplicate($pdo, static fn (PDO $pdo): int => insertSeries($pdo, $c2, $w2, $prefix, 'TICKET_B', 'SERIE_B'));
    });
    $results[] = runScenario($pdo, 'C-different-warehouses', 'GLOBAL_DUPLICATE_REJECTED', static function (PDO $pdo, string $token): array {
        setDiagnosticCheckpoint('fixture_company', 'C-different-warehouses');
        $c = insertCompany($pdo, $token, 1); $w1 = insertWarehouse($pdo, $c, $token, 1); $w2 = insertWarehouse($pdo, $c, $token, 2);
        $prefix = 'F67C' . substr($token, 0, 4);
        setDiagnosticCheckpoint('fixture_warehouse', 'C-different-warehouses');
        setDiagnosticCheckpoint('first_series', 'C-different-warehouses');
        $s1 = insertSeries($pdo, $c, $w1, $prefix, 'TICKET_A', 'SERIE_A');
        setDiagnosticCheckpoint('first_series_verification', 'C-different-warehouses');
        assertReservation($pdo, $s1, $prefix);
        setDiagnosticCheckpoint('second_series', 'C-different-warehouses');
        return expectGlobalDuplicate($pdo, static fn (PDO $pdo): int => insertSeries($pdo, $c, $w2, $prefix, 'TICKET_B', 'SERIE_B'));
    });
    $results[] = runScenario($pdo, 'D-different-document-types', 'GLOBAL_DUPLICATE_REJECTED', static function (PDO $pdo, string $token): array {
        setDiagnosticCheckpoint('fixture_company', 'D-different-document-types');
        $c = insertCompany($pdo, $token, 1); $w = insertWarehouse($pdo, $c, $token, 1); $prefix = 'F67D' . substr($token, 0, 4);
        setDiagnosticCheckpoint('fixture_warehouse', 'D-different-document-types');
        setDiagnosticCheckpoint('first_series', 'D-different-document-types');
        $s1 = insertSeries($pdo, $c, $w, $prefix, 'TICKET_A', 'SERIE_A');
        setDiagnosticCheckpoint('first_series_verification', 'D-different-document-types');
        assertReservation($pdo, $s1, $prefix);
        setDiagnosticCheckpoint('second_series', 'D-different-document-types');
        return expectGlobalDuplicate($pdo, static fn (PDO $pdo): int => insertSeries($pdo, $c, $w, $prefix, 'TICKET_B', 'SERIE_B'));
    });
    $results[] = runScenario($pdo, 'E-inactive-series', 'GLOBAL_DUPLICATE_REJECTED', static function (PDO $pdo, string $token): array {
        setDiagnosticCheckpoint('fixture_company', 'E-inactive-series');
        $c = insertCompany($pdo, $token, 1); $w = insertWarehouse($pdo, $c, $token, 1); $prefix = 'F67E' . substr($token, 0, 4);
        setDiagnosticCheckpoint('fixture_warehouse', 'E-inactive-series');
        setDiagnosticCheckpoint('first_series', 'E-inactive-series');
        $s1 = insertSeries($pdo, $c, $w, $prefix, 'TICKET_A', 'SERIE_A', 0);
        setDiagnosticCheckpoint('first_series_verification', 'E-inactive-series');
        assertReservation($pdo, $s1, $prefix);
        setDiagnosticCheckpoint('second_series', 'E-inactive-series');
        return expectGlobalDuplicate($pdo, static fn (PDO $pdo): int => insertSeries($pdo, $c, $w, $prefix, 'TICKET_B', 'SERIE_B'));
    });
    $results[] = runScenario($pdo, 'F-logically-deleted-series', 'GLOBAL_DUPLICATE_REJECTED', static function (PDO $pdo, string $token): array {
        setDiagnosticCheckpoint('fixture_company', 'F-logically-deleted-series');
        $c = insertCompany($pdo, $token, 1); $w = insertWarehouse($pdo, $c, $token, 1); $prefix = 'F67F' . substr($token, 0, 4);
        setDiagnosticCheckpoint('fixture_warehouse', 'F-logically-deleted-series');
        setDiagnosticCheckpoint('first_series', 'F-logically-deleted-series');
        $s1 = insertSeries($pdo, $c, $w, $prefix, 'TICKET_A', 'SERIE_A', 0, '2099-01-01 00:00:00');
        setDiagnosticCheckpoint('first_series_verification', 'F-logically-deleted-series');
        assertReservation($pdo, $s1, $prefix);
        setDiagnosticCheckpoint('second_series', 'F-logically-deleted-series');
        return expectGlobalDuplicate($pdo, static fn (PDO $pdo): int => insertSeries($pdo, $c, $w, $prefix, 'TICKET_B', 'SERIE_B'));
    });
    $results[] = runScenario($pdo, 'G-different-prefixes', 'TWO_DISTINCT_PREFIXES_ACCEPTED', static function (PDO $pdo, string $token): array {
        setDiagnosticCheckpoint('fixture_company', 'G-different-prefixes');
        $c = insertCompany($pdo, $token, 1); $w = insertWarehouse($pdo, $c, $token, 1);
        setDiagnosticCheckpoint('fixture_warehouse', 'G-different-prefixes');
        setDiagnosticCheckpoint('first_series', 'G-different-prefixes');
        $prefix1 = 'F67G' . substr($token, 0, 4);
        $s1 = insertSeries($pdo, $c, $w, $prefix1, 'TICKET_A', 'SERIE_A');
        setDiagnosticCheckpoint('first_series_verification', 'G-different-prefixes');
        assertReservation($pdo, $s1, $prefix1);
        setDiagnosticCheckpoint('second_series', 'G-different-prefixes');
        $s2 = insertSeries($pdo, $c, $w, 'F67H' . substr($token, 0, 4), 'TICKET_B', 'SERIE_B');
        if ($s1 < 1 || $s2 < 1) {
            throw new RuntimeException('FAIL_UNEXPECTED_SQL_ERROR');
        }
        return ['accepted_count' => 2];
    });
    return $results;
}

function main(array $argv): void
{
    setDiagnosticCheckpoint('cli');
    $options = cliOptions($argv);
    setDiagnosticCheckpoint('environment');
    $environment = processEnvironment();
    setDiagnosticCheckpoint('connection');
    $pdo = openQaConnection($environment);
    setDiagnosticCheckpoint('identity');
    assertIdentityAndGrants($pdo);
    setDiagnosticCheckpoint('schema');
    assertSchema($pdo);
    setDiagnosticCheckpoint('structural_contracts');
    assertStructuralContracts($pdo);
    setDiagnosticCheckpoint('triggers');
    assertNoUnexpectedTriggers($pdo);
    if ($options['mode'] === 'preflight') {
        echo json_encode(['status' => 'PASS', 'mode' => 'preflight', 'database' => QA_DATABASE], JSON_THROW_ON_ERROR) . PHP_EOL;
        return;
    }
    setDiagnosticCheckpoint('baseline');
    $baseline = baseline($pdo);
    $results = runTest($pdo);
    $final = baseline($pdo);
    foreach ($baseline as $table => $count) {
        if ($final[$table] !== $count) {
            throw new RuntimeException('BLOCKED_RESIDUE_OR_UNCERTAIN_STATE');
        }
    }
    echo json_encode([
        'status' => 'PASS',
        'mode' => 'test',
        'database' => QA_DATABASE,
        'scenarios' => $results,
        'baseline' => $baseline,
        'final' => $final,
        'AUTO_INCREMENT_MAY_ADVANCE_AFTER_ROLLBACK' => true,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        main($argv);
    } catch (Throwable $exception) {
        emitSanitizedFailure($exception);
    }
}
