<?php

declare(strict_types=1);

/**
 * RW-5: source-backed, DB-free contract for the future Tickets WRITE user.
 *
 * This test intentionally inspects only methods reachable from the closed
 * Tickets/outbox WRITE graphs. Read-only administrative query methods are not
 * part of this contract.
 */

const RW5_BASE = __DIR__ . '/../..';

function rw5_fail(string $message): never
{
    fwrite(STDERR, "RW_5_DB_FREE_TESTS=FAIL\n" . $message . "\n");
    exit(1);
}

function rw5_assert(bool $condition, string $message): void
{
    if (!$condition) {
        rw5_fail($message);
    }
}

/** @return array<string, string> */
function rw5_readSources(): array
{
    $files = [
        'tickets' => RW5_BASE . '/app/Infrastructure/Repositories/ProductRequestTicketRepository.php',
        'ticket_outbox' => RW5_BASE . '/app/Infrastructure/Repositories/ProductTicketEmailOutboxRepository.php',
        'mail_config' => RW5_BASE . '/app/Infrastructure/Repositories/MailConfigurationRepository.php',
        'admin_outbox' => RW5_BASE . '/app/Infrastructure/Repositories/MailOutboxActionRepository.php',
        'audit' => RW5_BASE . '/app/Infrastructure/Repositories/AuditRepository.php',
    ];

    $sources = [];
    foreach ($files as $key => $file) {
        rw5_assert(is_file($file), 'Missing source file: ' . $file);
        $source = file_get_contents($file);
        rw5_assert(is_string($source), 'Unable to read source file: ' . $file);
        $sources[$key] = $source;
    }

    return $sources;
}

/**
 * Extract a real PHP method body using the tokenizer rather than a detached
 * copy of SQL. This keeps the expected privilege matrix coupled to source.
 */
function rw5_methodBody(string $source, string $method): string
{
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
            continue;
        }

        $name = null;
        $openBrace = null;
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            if (is_array($token) && $token[0] === T_STRING) {
                $name = $token[1];
                break;
            }
            if ($token === '{' || $token === ';') {
                break;
            }
        }

        if ($name !== $method) {
            continue;
        }

        for ($cursor = $cursor + 1; $cursor < $count; $cursor++) {
            if ($tokens[$cursor] === '{') {
                $openBrace = $cursor;
                break;
            }
        }
        rw5_assert($openBrace !== null, 'Method has no body: ' . $method);

        $depth = 1;
        $body = '';
        for ($cursor = $openBrace + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }
            $body .= is_array($token) ? $token[1] : $token;
        }

        rw5_fail('Unclosed method body: ' . $method);
    }

    rw5_fail('Method not found: ' . $method);
}

/** @param list<string> $methods @return array<string, string> */
function rw5_selectedBody(string $source, array $methods): array
{
    $bodies = [];
    foreach ($methods as $method) {
        $bodies[$method] = rw5_methodBody($source, $method);
    }

    return $bodies;
}

/** @param array<string, string> $bodies @return array<string, array<string, true>> */
function rw5_inventory(array $bodies): array
{
    $inventory = [];

    foreach ($bodies as $method => $body) {
        $patterns = [
            'SELECT' => '/\b(?:FROM|JOIN)\s+`?([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)`?/i',
            'INSERT' => '/\bINSERT\s+INTO\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i',
            'UPDATE' => '/\bUPDATE\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i',
            'DELETE' => '/\bDELETE\s+FROM\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i',
        ];

        foreach ($patterns as $operation => $pattern) {
            preg_match_all($pattern, $body, $matches);
            foreach ($matches[1] ?? [] as $table) {
                $table = strtolower(trim((string) $table, "` \t\r\n"));
                if ($table === '' || $table === 'database()') {
                    continue;
                }
                $inventory[$table][$operation] = true;
            }
        }
    }

    ksort($inventory);
    foreach ($inventory as &$operations) {
        ksort($operations);
    }
    unset($operations);

    return $inventory;
}

/** @param array<string, array<string, true>> $inventory */
function rw5_appInventory(array $inventory): array
{
    $app = [];
    foreach ($inventory as $table => $operations) {
        if ($table === 'information_schema.tables') {
            continue;
        }
        $app[$table] = $operations;
    }

    return $app;
}

function rw5_normalize(array $value): array
{
    ksort($value);
    foreach ($value as &$item) {
        if (is_array($item)) {
            ksort($item);
        }
    }
    unset($item);

    return $value;
}

$sources = rw5_readSources();

$ticketMethods = [
    'activeUserExists', 'activeCompanyExists', 'warehouseById',
    'emitProductTicketFolio', 'findOrCreateProductTicketSeriesForUpdate',
    'findSeriesForUpdate', 'insertDocumentoFolio', 'updateSeriesNextNumber',
    'createTicket', 'createPartida', 'findTicketById', 'findTicketByIdForUpdate',
    'findPartidaByIdForUpdate', 'updatePartidaResolution', 'countPartidasByState',
    'updateTicketCounters', 'cancelTicket', 'agregarComentario', 'agregarAdjunto',
    'insertEvent', 'listPartidas', 'listComentarios', 'listAdjuntos', 'listEventos',
    'activeSatUnitById', 'resolveActiveSatUnit', 'activeSatKeyById', 'resolveActiveSatKey',
];

$outboxMethods = [
    'findTicketContext', 'qaFixtureRelationCounts', 'findLineContext', 'findLinesContext',
    'countAttachments', 'findByDedupeKey', 'insertPending', 'eligiblePreview',
    'claimNextEligible', 'claimEligibleById', 'recoverStale', 'markSent', 'markError',
    'findById', 'findByIdOrNull',
];

$configMethods = ['accounts', 'primaryAccount', 'rules'];
$adminMethods = ['findScopedForUpdate', 'retryError', 'cancelPendingOrError'];
$auditMethods = ['insertRequired', 'insertRow', 'tableExists'];

$bodies = [];
foreach (rw5_selectedBody($sources['tickets'], $ticketMethods) as $method => $body) {
    $bodies['tickets.' . $method] = $body;
}
foreach (rw5_selectedBody($sources['ticket_outbox'], $outboxMethods) as $method => $body) {
    $bodies['ticket_outbox.' . $method] = $body;
}
foreach (rw5_selectedBody($sources['mail_config'], $configMethods) as $method => $body) {
    $bodies['mail_config.' . $method] = $body;
}
foreach (rw5_selectedBody($sources['admin_outbox'], $adminMethods) as $method => $body) {
    $bodies['admin_outbox.' . $method] = $body;
}
foreach (rw5_selectedBody($sources['audit'], $auditMethods) as $method => $body) {
    $bodies['audit.' . $method] = $body;
}

$inventory = rw5_inventory($bodies);
$appInventory = rw5_appInventory($inventory);

$expected = [
    'almacenes' => ['SELECT' => true],
    'auditoria_eventos' => ['INSERT' => true],
    'claves_sat' => ['SELECT' => true],
    'documentos_folios' => ['INSERT' => true],
    'empresas' => ['SELECT' => true],
    'mail_accounts' => ['SELECT' => true],
    'monedas' => ['SELECT' => true],
    'series_documentales' => ['INSERT' => true, 'SELECT' => true, 'UPDATE' => true],
    'tickets_productos' => ['INSERT' => true, 'SELECT' => true, 'UPDATE' => true],
    'tickets_productos_adjuntos' => ['INSERT' => true, 'SELECT' => true],
    'tickets_productos_comentarios' => ['INSERT' => true, 'SELECT' => true],
    'tickets_productos_correos' => ['INSERT' => true, 'SELECT' => true, 'UPDATE' => true],
    'tickets_productos_correo_reglas' => ['SELECT' => true],
    'tickets_productos_eventos' => ['INSERT' => true, 'SELECT' => true],
    'tickets_productos_partidas' => ['INSERT' => true, 'SELECT' => true, 'UPDATE' => true],
    'unidades_sat' => ['SELECT' => true],
    'usuarios' => ['SELECT' => true],
];
$expected = rw5_normalize($expected);

rw5_assert(
    rw5_normalize($appInventory) === $expected,
    'Source-derived WRITE inventory differs from the exact contract. Observed=' . json_encode($appInventory)
);
rw5_assert(!isset($appInventory['information_schema.tables']), 'Metadata schema leaked into app-table grants.');
rw5_assert(($inventory['information_schema.tables']['SELECT'] ?? false) === true, 'Audit metadata SELECT was not detected.');

$allOperations = [];
foreach ($appInventory as $operations) {
    foreach ($operations as $operation => $_true) {
        $allOperations[$operation] = true;
    }
}
rw5_assert(!isset($allOperations['DELETE']), 'Unexpected DELETE detected in WRITE graph.');
rw5_assert(!preg_match('/\b(CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE)\b/i', implode("\n", $bodies)), 'DDL/admin SQL detected.');

$selectTables = [];
$insertTables = [];
$updateTables = [];
foreach ($appInventory as $table => $operations) {
    if (isset($operations['SELECT'])) {
        $selectTables[] = $table;
    }
    if (isset($operations['INSERT'])) {
        $insertTables[] = $table;
    }
    if (isset($operations['UPDATE'])) {
        $updateTables[] = $table;
    }
}
rw5_assert($selectTables !== [] && $insertTables !== [] && $updateTables !== [], 'Incomplete operation classes.');

$proposedGrants = [];
foreach ($expected as $table => $operations) {
    $privileges = array_keys($operations);
    sort($privileges);
    $proposedGrants[] = 'GRANT ' . implode(', ', $privileges)
        . " ON `r_erp_db_core_0_test`.`{$table}` TO 'r_erp_tickets_write'@'localhost';";
}
$grantSql = implode("\n", $proposedGrants);

rw5_assert(!str_contains($grantSql, '*.*'), 'Global grant detected.');
rw5_assert(!preg_match('/\bALL\b|GRANT OPTION|\bDELETE\b/i', $grantSql), 'Forbidden privilege in proposed grants.');
rw5_assert(!str_contains($grantSql, '<WRITE_PASSWORD_GENERATED_SECURELY>'), 'Password leaked into GRANT SQL.');

echo "RW_5_DB_FREE_TESTS=PASS\n";
echo 'WRITE_TABLES=' . implode(',', array_keys($expected)) . "\n";
echo 'SELECT_TABLES=' . implode(',', $selectTables) . "\n";
echo 'INSERT_TABLES=' . implode(',', $insertTables) . "\n";
echo 'UPDATE_TABLES=' . implode(',', $updateTables) . "\n";
echo "DELETE_REQUIRED=false\n";
echo "DDL_REQUIRED=false\n";
echo "TABLE_SPECIFIC_GRANTS=true\n";
