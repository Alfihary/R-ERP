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
const PROTECTED_PRODUCT_ID = '102016169';

/** @return never */
function failRun(string $category, string $message): never
{
    echo json_encode([
        'status' => 'FAIL',
        'failure_category' => $category,
        'safe_error_message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
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
    $rows = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(array_map(
        static fn (mixed $value): ?string => is_string($value) ? trim($value) : null,
        $rows
    )));
}

function assertReadOnlyPrivileges(PDO $pdo): void
{
    $identity = $pdo->query(
        'SELECT CURRENT_USER(), DATABASE(), CURRENT_ROLE()'
    )->fetch(PDO::FETCH_NUM);
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
            && ($event['product_id_strategy'] ?? null) === 'metadata.producto_id'
        ) {
            $subresources[] = [(string) $action, (string) $event['entity']];
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
    foreach (array_values($subresources) as $index => [$action, $entity]) {
        $actionName = $prefix . '_sub_action_' . $index;
        $entityName = $prefix . '_sub_entity_' . $index;
        $params[$actionName] = $action;
        $params[$entityName] = $entity;
        $conditions[] = '(h.accion = :' . $actionName
            . ' AND h.entidad = :' . $entityName
            . " AND JSON_UNQUOTE(JSON_EXTRACT(h.metadata_json, '$.producto_id')) = "
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
    $statement = $pdo->prepare(
        'SELECT p.id_producto
         FROM productos p
         WHERE p.eliminado_en IS NULL
           AND p.id_producto <> :protected_product
           AND ' . $historyClause . '
         ORDER BY p.id_producto
         LIMIT 1'
    );
    $statement->execute($association['params'] + ['protected_product' => PROTECTED_PRODUCT_ID]);
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
 * @return list<array{action:string, subresource:?string, actor_id:?int, date:string}>
 */
function productHistoryEvents(PDO $pdo, array $registry, string $productId): array
{
    $statement = $pdo->prepare(
        "SELECT accion, entidad, entidad_id, actor_usuario_id, creado_en, metadata_json
         FROM auditoria_eventos
         WHERE (entidad = 'producto' AND entidad_id = :direct_product)
            OR JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.producto_id')) = :metadata_product
         ORDER BY creado_en DESC, id DESC"
    );
    $statement->execute([
        'direct_product' => $productId,
        'metadata_product' => $productId,
    ]);

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
                && ($event['product_id_strategy'] ?? null) === 'metadata.producto_id'
                && ($row['entidad'] ?? null) === $event['entity'];
        if (!$queryable) continue;
        $date = (string) ($row['creado_en'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) continue;
        $events[] = [
            'action' => $action,
            'subresource' => is_string($subresource) ? $subresource : null,
            'actor_id' => $row['actor_usuario_id'] === null ? null : (int) $row['actor_usuario_id'],
            'date' => substr($date, 0, 10),
        ];
    }
    return $events;
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

    $connection = new ConnectionProvider($database);
    $pdo = $connection->pdo();
    assertReadOnlyPrivileges($pdo);

    $registry = require BASE_PATH . '/config/products_history.php';
    $registry = is_array($registry['events'] ?? null) ? $registry['events'] : [];
    $products = new ProductRepository($connection);
    $history = new ProductHistoryQueryRepository($connection, $registry);
    $mapper = new ProductHistoryMapper($registry, new ProductHistoryPresenter($registry));
    $service = new ProductHistoryService($products, $history, $mapper, $registry);

    [$withHistory] = chooseProduct($pdo, $registry, true);
    [$withoutHistory] = chooseProduct($pdo, $registry, false);
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
        if ($events !== []) {
            $scenarioStates['DB-05'] = 'PASS';
        }

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

    $failedCount = count(array_filter($scenarioStates, static fn (string $state): bool => $state === 'FAIL'));
    $notExecutedCount = count(array_filter($scenarioStates, static fn (string $state): bool => $state === 'NOT_EXECUTED'));
    $passedCount = count(array_filter($scenarioStates, static fn (string $state): bool => $state === 'PASS'));
    $status = $failedCount > 0 ? 'FAIL' : ($notExecutedCount > 0 ? 'PARTIAL' : 'PASS');

    echo json_encode([
        'status' => $status,
        'database' => TARGET_DATABASE,
        'read_only' => true,
        'scenario_states' => $scenarioStates,
        'total_tests' => count($scenarioStates),
        'passed_tests' => $passedCount,
        'failed_tests' => $failedCount,
        'not_executed_tests' => $notExecutedCount,
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
        'counts' => [
            'existing_items' => $page === null ? null : count($page->items),
            'existing_total' => $page?->total,
            'empty_total' => $empty?->total,
            'filtered_items' => $filtered === null ? 0 : count($filtered->items),
        ],
        'db06' => $db06,
        'db09_scope' => 'STATIC_ONLY_NO_RUNTIME_WRITE_EVIDENCE',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    failRun('runtime_error', 'READ_ONLY_HARNESS_FAILED');
}
