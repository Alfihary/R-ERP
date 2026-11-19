<?php

declare(strict_types=1);

/**
 * Static security/regression harness for GET /productos/historial.
 *
 * This file intentionally inspects source tokens only. It must not load the
 * application bootstrap, start a session, open PDO, execute SQL, or issue HTTP.
 */

$basePath = dirname(__DIR__);

/** @return array{tokens:list<array{0:int,1:string,2:int}|string>, compact:string} */
function readTokens(string $basePath, string $relativePath): array
{
    $path = $basePath . DIRECTORY_SEPARATOR . $relativePath;
    if (!is_file($path)) {
        throw new RuntimeException('source_missing:' . $relativePath);
    }

    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('source_unreadable:' . $relativePath);
    }

    $tokens = token_get_all($source);
    $compact = '';
    foreach ($tokens as $token) {
        if (is_array($token)) {
            [$type, $text] = $token;
            if (in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $compact .= $text;
        } else {
            $compact .= $token;
        }
    }

    return ['tokens' => $tokens, 'compact' => $compact];
}

/** @param list<array{0:int,1:string,2:int}|string> $tokens */
function hasNamedDeclaration(array $tokens, int $declarationType, string $name): bool
{
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== $declarationType) {
            continue;
        }
        if (($tokens[$index + 1] ?? null) === $name) {
            return true;
        }
    }
    return false;
}

function checkStatus(
    string $id,
    string $status,
    string $evidence,
    array &$checks
): void {
    $allowed = ['STATIC_PASS', 'STATIC_FAIL', 'STATIC_UNVERIFIED', 'RUNTIME_REQUIRED'];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('invalid_check_status');
    }
    $checks[] = ['id' => $id, 'status' => $status, 'evidence' => $evidence];
}

function compactHas(string $compact, string $pattern): bool
{
    $handler = static function (int $severity, string $message): never {
        throw new RuntimeException('invalid_static_pattern:' . safeSummary($message));
    };
    set_error_handler($handler);
    try {
        $result = preg_match($pattern, $compact);
    } finally {
        restore_error_handler();
    }
    if ($result === false) {
        throw new RuntimeException('invalid_static_pattern');
    }
    return $result === 1;
}

/** @param list<array{0:int,1:string,2:int}|string> $tokens @param list<string> $names */
function hasExecutableName(array $tokens, array $names): bool
{
    foreach ($tokens as $token) {
        if (is_array($token) && $token[0] === T_STRING && in_array(strtoupper($token[1]), $names, true)) {
            return true;
        }
    }
    return false;
}

/** @param list<array{0:int,1:string,2:int}|string> $tokens */
function hasMutationSqlLiteral(array $tokens): bool
{
    foreach ($tokens as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|TRUNCATE)\b/i', $token[1]) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * Extracts a declared string array without executing the source file.
 * @param list<array{0:int,1:string,2:int}|string> $tokens
 * @return array{status:string, values:list<string>}
 */
function parseStringArrayConstant(array $tokens, string $name): array
{
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index] ?? null;
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== $name) {
            continue;
        }

        $previous = $tokens[$index - 1] ?? null;
        if ($previous === ':' || (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR], true))) {
            continue;
        }

        $cursor = $index + 1;
        while ($cursor < $count && is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $cursor++;
        }
        if (($tokens[$cursor] ?? null) !== '=') {
            continue;
        }
        $cursor++;
        while ($cursor < $count && is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $cursor++;
        }
        if (($tokens[$cursor] ?? null) !== '[') {
            return ['status' => 'unresolved', 'values' => []];
        }

        $cursor++;
        $values = [];
        $nestedDepth = 0;
        for (; $cursor < $count; $cursor++) {
            $part = $tokens[$cursor];
            if (is_array($part) && in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($part === '[') {
                $nestedDepth++;
                continue;
            }
            if ($part === ']') {
                if ($nestedDepth > 0) {
                    $nestedDepth--;
                    continue;
                }
                return ['status' => 'resolved', 'values' => $values];
            }
            if ($nestedDepth > 0 || $part === ',') {
                continue;
            }
            if (is_array($part) && $part[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = $part[1];
                if (strlen($literal) < 2 || $literal[0] !== $literal[strlen($literal) - 1]) {
                    return ['status' => 'unresolved', 'values' => []];
                }
                $values[] = str_replace(['\\' . $literal[0], '\\\\'], [$literal[0], '\\'], substr($literal, 1, -1));
                continue;
            }
            return ['status' => 'unresolved', 'values' => []];
        }
        return ['status' => 'unresolved', 'values' => []];
    }
    return ['status' => 'unresolved', 'values' => []];
}

function safeSummary(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9_.:\- ]+/', '', $value) ?? '';
    return substr($value, 0, 160);
}

$sourcePaths = [
    'controller' => 'app/Http/Controllers/ProductHistoryController.php',
    'service' => 'app/Domain/Products/ProductHistoryService.php',
    'repository' => 'app/Infrastructure/Repositories/ProductHistoryQueryRepository.php',
    'mapper' => 'app/Domain/Products/History/ProductHistoryMapper.php',
    'presenter' => 'app/Domain/Products/History/ProductHistoryPresenter.php',
    'item' => 'app/Domain/Products/History/ProductHistoryItem.php',
    'config' => 'config/products_history.php',
    'routes' => 'routes/web.php',
    'auth' => 'app/Http/Middlewares/AuthMiddleware.php',
    'permission' => 'app/Http/Middlewares/PermissionMiddleware.php',
    'error_middleware' => 'app/Http/Middlewares/ErrorHandlingMiddleware.php',
    'error_handler' => 'app/Core/ErrorHandler.php',
    'csrf' => 'app/Http/Middlewares/CsrfMiddleware.php',
    'runtime_runner' => 'tests/product_history_db_readonly_test.php',
];

$loaded = [];
$checks = [];
$staticFail = false;

try {
    foreach ($sourcePaths as $key => $path) {
        $loaded[$key] = readTokens($basePath, $path);
    }

    $controller = $loaded['controller']['compact'];
    $service = $loaded['service']['compact'];
    $repository = $loaded['repository']['compact'];
    $mapper = $loaded['mapper']['compact'];
    $presenter = $loaded['presenter']['compact'];
    $item = $loaded['item']['compact'];
    $config = $loaded['config']['compact'];
    $routes = $loaded['routes']['compact'];
    $auth = $loaded['auth']['compact'];
    $permission = $loaded['permission']['compact'];
    $errorMiddleware = $loaded['error_middleware']['compact'];
    $errorHandler = $loaded['error_handler']['compact'];
    $runtimeRunner = $loaded['runtime_runner']['compact'];

    $runnerScenarioContract = compactHas($runtimeRunner, '/KIT-01/')
        && compactHas($runtimeRunner, '/KIT-02/')
        && compactHas($runtimeRunner, '/KIT-03/')
        && compactHas($runtimeRunner, '/ACTOR-01/')
        && compactHas($runtimeRunner, '/ACTOR-02/')
        && compactHas($runtimeRunner, '/ACTOR-03/')
        && compactHas($runtimeRunner, '/SKIP_DATA_NOT_AVAILABLE/')
        && compactHas($runtimeRunner, '/PARTIAL_DATA_NOT_AVAILABLE/');
    checkStatus('SEC-26-runtime-scenario-contract', $runnerScenarioContract ? 'STATIC_PASS' : 'STATIC_FAIL', 'extended_runtime_scenarios_and_data_skip_statuses_declared', $checks);
    $runnerReadOnlyContract = compactHas($runtimeRunner, '/WRITE_SQL_BLOCKED/')
        && compactHas($runtimeRunner, '/SHOW GRANTS/')
        && compactHas($runtimeRunner, '/assertReadOnlyPrivileges/')
        && compactHas($runtimeRunner, '/normalizeSqlForGuard/')
        && compactHas($runtimeRunner, '/assertGrantTextReadOnly/')
        && compactHas($runtimeRunner, '/TEMPORARY\\s+TABLE/')
        && compactHas($runtimeRunner, '/ALL PRIVILEGES/');
    checkStatus('SEC-27-runtime-readonly-allowlist', $runnerReadOnlyContract ? 'STATIC_PASS' : 'STATIC_FAIL', 'runtime_runner_select_show_grants_only', $checks);
    checkStatus('SEC-28-runtime-no-hardcoded-product', !str_contains($runtimeRunner, '102016169') && !str_contains($runtimeRunner, 'PROTECTED_PRODUCT_ID') ? 'STATIC_PASS' : 'STATIC_FAIL', 'dynamic_product_selection_only', $checks);
    $interceptorContract = compactHas($runtimeRunner, '/ReadOnlyInstrumentedPdo/')
        && compactHas($runtimeRunner, '/functionquery\(/')
        && compactHas($runtimeRunner, '/functionprepare\(/')
        && compactHas($runtimeRunner, '/functionexecute\(/')
        && compactHas($runtimeRunner, '/sql_fingerprint/')
        && compactHas($runtimeRunner, '/ALL_EXECUTED_STATEMENTS_LEDGERED/');
    checkStatus('SEC-29-global-sql-interceptor', $interceptorContract ? 'STATIC_PASS' : 'STATIC_FAIL', 'pdo_query_prepare_execute_and_ledger_contract', $checks);
    $ledgerMetricsContract = compactHas($runtimeRunner, '/prevalidation/')
        && compactHas($runtimeRunner, '/execution/')
        && compactHas($runtimeRunner, '/TOTAL_SQL_STATEMENTS_EXECUTION_LEDGERED/')
        && compactHas($runtimeRunner, '/UNLEDGERED_EXECUTION_COUNT/');
    checkStatus('SEC-30-ledger-phase-metrics', $ledgerMetricsContract ? 'STATIC_PASS' : 'STATIC_FAIL', 'prevalidation_and_execution_metrics_are_separated', $checks);
    $diagnosticContract = compactHas($runtimeRunner, '/RuntimeHarnessContext/')
        && compactHas($runtimeRunner, '/sanitizeRuntimeException/')
        && compactHas($runtimeRunner, '/failure_stage/')
        && compactHas($runtimeRunner, '/exception_class/')
        && compactHas($runtimeRunner, '/PDO_CONSTRUCTOR_FAILED/')
        && compactHas($runtimeRunner, '/sqlstate/')
        && compactHas($runtimeRunner, '/driver_error_code/')
        && compactHas($runtimeRunner, '/SQL statement redacted/');
    checkStatus('SEC-31-harness-diagnostic-context', $diagnosticContract ? 'STATIC_PASS' : 'STATIC_FAIL', 'stage_exception_and_sanitized_message_preserved', $checks);

    $routePattern = '/\$router->get\(\x27\/productos\/historial\x27,.*?\$productHistoryController->index/s';
    $routeRegistered = compactHas($routes, $routePattern);
    $middlewareOrder = compactHas(
        $routes,
        '/\$productBaseMiddleware=\[.*?\$authMiddleware.*?productos\.acceder/s'
    ) && compactHas(
        $routes,
        '/\$productMiddleware=staticfunction\(string\$permission\).*?newPermissionMiddleware\(\$auth,\$permissions,\$permission\)/s'
    ) && compactHas(
        $routes,
        '/\$productMiddleware\(\x27productos\.ver\x27\)/'
    );

    $authCheckStatus = $routeRegistered
        && compactHas($routes, '/newAuthMiddleware\(/')
        && compactHas($auth, '/!\$this->auth->check\(\)/')
        ? 'STATIC_PASS'
        : 'STATIC_FAIL';
    checkStatus('SEC-01-auth-middleware', $authCheckStatus, $authCheckStatus === 'STATIC_PASS' ? 'route_and_auth_guard_present' : 'route_or_auth_guard_unproven', $checks);
    checkStatus('SEC-02-products-acceder', $middlewareOrder && compactHas($routes, '/productos\.acceder/') ? 'STATIC_PASS' : 'STATIC_FAIL', $middlewareOrder ? 'base_permission_present' : 'productos_acceder_not_structurally_proven', $checks);
    checkStatus('SEC-03-products-ver', $routeRegistered && compactHas($routes, '/\$productMiddleware\(\x27productos\.ver\x27\)/') ? 'STATIC_PASS' : 'STATIC_FAIL', 'route_permission_reference', $checks);
    checkStatus('SEC-04-permission-before-controller', $middlewareOrder && $routeRegistered ? 'STATIC_PASS' : 'STATIC_UNVERIFIED', 'declared_route_pipeline', $checks);
    checkStatus('SEC-05-middleware-order', $middlewareOrder ? 'STATIC_PASS' : 'STATIC_FAIL', $middlewareOrder ? 'Auth_then_base_and_action_permission' : 'order_not_proven', $checks);
    checkStatus('SEC-06-service-not-called-on-denial', 'RUNTIME_REQUIRED', 'requires_authenticated_http_or_instrumentation', $checks);

    $allowlistParsed = parseStringArrayConstant($loaded['controller']['tokens'], 'ALLOWED_QUERY_KEYS');
    $allowedKeys = $allowlistParsed['status'] === 'resolved';
    $expectedAllowedKeys = [
        'id_producto', 'action', 'subresource', 'actor_id',
        'date_from', 'date_to', 'page', 'page_size',
    ];
    $allowlistDuplicates = count($allowlistParsed['values']) !== count(array_unique($allowlistParsed['values']));
    $allowlistMissing = array_values(array_diff($expectedAllowedKeys, $allowlistParsed['values']));
    $allowlistExtra = array_values(array_diff($allowlistParsed['values'], $expectedAllowedKeys));
    $allowlistExactSet = $allowedKeys
        && !$allowlistDuplicates
        && $allowlistMissing === []
        && $allowlistExtra === []
        && count($allowlistParsed['values']) === count($expectedAllowedKeys);
    $allowedKeysConstantUsage = compactHas($controller, '/!in_array\(\$key,self::ALLOWED_QUERY_KEYS,true\)/');
    $unknownKeyReject = compactHas($controller, '/if\(!is_string\(\$key\)\|\|!in_array\(\$key,self::ALLOWED_QUERY_KEYS,true\)\)\{thrownewInvalidArgumentException/');
    $allowlistBeforeService = compactHas($controller, '/publicfunctionindex\(Request\$request\):Response\{.*?assertQueryContract\(\$query\).*?history->page\(/s');
    $scalarGuard = compactHas($controller, '/!is_string\(\$value\)&&!is_int\(\$value\)&&!is_float\(\$value\)/');
    $requiredId = compactHas($controller, '/requiredScalar\(\$query,\x27id_producto\x27\)/');
    $dateTrim = compactHas($controller, '/returntrim\(\(string\)\$query\[\$key\]\)/');
    $serviceDate = compactHas($service, '/createFromFormat\(\x27!Y-m-d\x27/');
    $serviceProduct404 = compactHas($service, '/ProductNotFoundException/');
    $serviceValidation = compactHas($service, '/InvalidArgumentException/');
    $servicePage = compactHas($controller, '/\$this->history->page\(/');

    checkStatus('SEC-07-id-contract', $allowedKeys && $scalarGuard && $requiredId ? 'STATIC_PASS' : 'STATIC_FAIL', 'allowed_keys_scalar_and_required_id', $checks);
    checkStatus('SEC-08-product-not-found', $serviceProduct404 && compactHas($controller, '/catch\(ProductNotFoundException\).*?404/s') ? 'RUNTIME_REQUIRED' : 'STATIC_FAIL', '404_mapping_requires_real_service_product_lookup', $checks);
    checkStatus('SEC-09-action-subresource', 'STATIC_UNVERIFIED', 'service_registry_validation_requires_runtime_values', $checks);
    checkStatus('SEC-10-actor-id', 'STATIC_UNVERIFIED', 'positive_integer_service_contract_requires_runtime_values', $checks);
    checkStatus('SEC-11-date-format', $dateTrim && $serviceDate ? 'STATIC_PASS' : 'STATIC_FAIL', 'public_trim_then_service_calendar_validation', $checks);
    checkStatus('SEC-12-date-order', 'STATIC_UNVERIFIED', 'service_date_order_requires_runtime_values', $checks);
    checkStatus('SEC-13-pagination', compactHas($service, '/positiveInt\(/') && compactHas($service, '/MAX_PAGE_SIZE|100/') ? 'STATIC_PASS' : 'STATIC_UNVERIFIED', 'positive_page_and_max_page_size', $checks);
    checkStatus('SEC-14-array-rejection', $scalarGuard ? 'STATIC_PASS' : 'STATIC_FAIL', 'controller_scalar_contract', $checks);
    $sec15 = $allowlistExactSet && $allowedKeysConstantUsage && $unknownKeyReject && $allowlistBeforeService
        ? 'STATIC_PASS'
        : ($allowlistParsed['status'] === 'unresolved' ? 'STATIC_UNVERIFIED' : 'STATIC_FAIL');
    checkStatus('SEC-15-unknown-parameter', $sec15, $sec15 === 'STATIC_PASS' ? 'exact_constant_content_usage_rejection_and_order_proven' : 'allowlist_contract_discrepancy_or_unresolved', $checks);
    checkStatus('SEC-16-duplicate-query-keys', 'STATIC_UNVERIFIED', 'PHP_query_parser_may_overwrite_duplicate_keys', $checks);

    $sensitive = compactHas($mapper, '/SENSITIVE_KEYS=\[.*?password.*?token.*?csrf/s');
    $paths = compactHas($mapper, '/PATH_KEYS=\[.*?realpath.*?storage_path/s');
    $recursiveSanitizer = compactHas($mapper, '/privatefunctionsanitize\(array\$value\):array.*?is_array\(\$item\).*?\$this->sanitize\(\$item\)/s');
    $rootFields = compactHas($controller, '/\x27items\x27.*?\x27total\x27.*?\x27page\x27.*?\x27page_size\x27.*?\x27pages\x27.*?\x27filters\x27/s');
    $itemFields = compactHas($controller, '/\x27audit_id\x27.*?\x27occurred_at\x27.*?\x27action\x27.*?\x27category\x27.*?\x27subresource\x27.*?\x27result\x27.*?\x27actor_id\x27.*?\x27actor_display\x27.*?\x27product_id\x27.*?\x27entity\x27.*?\x27entity_id\x27.*?\x27summary\x27.*?\x27changed_fields\x27.*?\x27before\x27.*?\x27after\x27.*?\x27metadata\x27.*?\x27completeness\x27/s');
    $errorSafe = compactHas($errorHandler, '/\$this->debug\?\(string\)\$exception:null/')
        && str_contains($errorMiddleware, 'catch(\\Throwable$exception)');

    checkStatus('SEC-17-sensitive-key-sanitization', $sensitive && $recursiveSanitizer ? 'STATIC_PASS' : 'STATIC_FAIL', 'recursive_sensitive_key_filter', $checks);
    checkStatus('SEC-18-path-sanitization', $paths && $recursiveSanitizer ? 'STATIC_PASS' : 'STATIC_FAIL', 'recursive_path_key_filter', $checks);
    checkStatus('SEC-19-public-item-contract', $rootFields && $itemFields ? 'STATIC_PASS' : 'STATIC_FAIL', 'six_root_and_seventeen_item_fields', $checks);
    checkStatus('SEC-20-debug-error-safety', $errorSafe ? 'STATIC_PASS' : 'STATIC_UNVERIFIED', 'requires_APP_DEBUG_false_for_runtime_safety', $checks);
    checkStatus('SEC-21-json-response', compactHas($controller, '/Response::json\(/') ? 'STATIC_PASS' : 'STATIC_FAIL', 'controller_uses_json_factory', $checks);
    checkStatus('SEC-22-authorized-event-filtering', compactHas($repository, '/registry/') && compactHas($service, '/isQueryable/') ? 'STATIC_PASS' : 'STATIC_UNVERIFIED', 'registry_and_queryable_event_contract', $checks);

    $actorPolicy = compactHas($mapper, '/actorDisplay/')
        && compactHas($mapper, '/Usuario eliminado/')
        && compactHas($mapper, '/Sistema/')
        && !compactHas($controller, '/\x27actor_username\x27/');
    checkStatus('SEC-23-global-actor-policy', $actorPolicy ? 'STATIC_PASS' : 'STATIC_FAIL', 'actor_id_and_actor_display_only_with_global_fallbacks', $checks);
    $kitAssociation = compactHas($repository, '~metadata\.kit_id_producto.*?metadata\.producto_id~s')
        && compactHas($repository, '~COALESCE\(~s');
    checkStatus('SEC-24-kit-association-priority', $kitAssociation ? 'STATIC_PASS' : 'STATIC_FAIL', 'kit_id_producto_precedes_producto_id', $checks);
    checkStatus('SEC-25-global-history-no-effective-scope', !compactHas($service, '/EffectiveScope/') && !compactHas($repository, '/empresa_id|almacen_id/') ? 'STATIC_PASS' : 'STATIC_FAIL', 'global_product_history_has_no_operational_scope_filter', $checks);

    $regressionFiles = [
        'app/Domain/Products/ProductService.php',
        'app/Domain/Products/ProductImageService.php',
        'app/Domain/Products/ProductSimilarService.php',
        'app/Domain/Products/ProductKitService.php',
        'app/Domain/Products/ProductVariantService.php',
        'app/Domain/Tickets/ProductRequestTicketService.php',
        'app/Domain/Products/Import/ProductImportBatchService.php',
    ];
    $missingRegression = [];
    foreach ($regressionFiles as $path) {
        if (!is_file($basePath . DIRECTORY_SEPARATOR . $path)) {
            $missingRegression[] = $path;
        }
    }
    checkStatus('REG-01-product-modules-present', 'STATIC_UNVERIFIED', $missingRegression === [] ? 'files_present_but_behavior_unverified' : 'related_source_missing', $checks);
    checkStatus('REG-02-route-surface', $routeRegistered ? 'STATIC_PASS' : 'STATIC_FAIL', 'history_route_declared', $checks);
    checkStatus('REG-03-no-controller-sql', hasExecutableName($loaded['controller']['tokens'], ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'ALTER', 'DROP', 'TRUNCATE']) ? 'STATIC_FAIL' : 'STATIC_UNVERIFIED', 'tokenized_executable_names_checked_but_call_graph_requires_runtime', $checks);
    checkStatus('REG-04-no-mutation-service-call', compactHas($controller, '/->(create|update|delete|save|store|remove)\(/') ? 'STATIC_FAIL' : 'STATIC_UNVERIFIED', 'controller_call_graph_requires_runtime_or_deeper_analysis', $checks);
    checkStatus('REG-05-audit-regression', is_file($basePath . DIRECTORY_SEPARATOR . 'app/Domain/Audit/AuditService.php') ? 'STATIC_UNVERIFIED' : 'STATIC_FAIL', 'audit_runtime_regression_requires_separate_tests', $checks);
    checkStatus('REG-06-permission-regression', compactHas($routes, '/productos\.ver/') ? 'STATIC_UNVERIFIED' : 'STATIC_FAIL', 'permission_runtime_regression_requires_authenticated_http', $checks);
    checkStatus('REG-07-data-scope', 'RUNTIME_REQUIRED', 'requires_authenticated_scope_and_database_data', $checks);
    checkStatus('REG-08-crud-regression', 'RUNTIME_REQUIRED', 'requires_existing_product_crud_regression_suite', $checks);
    checkStatus('REG-09-mail-ticket-regression', 'RUNTIME_REQUIRED', 'out_of_scope_for_static_history_harness', $checks);
    checkStatus('REG-10-visual-regression', 'RUNTIME_REQUIRED', 'UI_not_executed_by_this_harness', $checks);

    $repositoryMutation = hasMutationSqlLiteral($loaded['repository']['tokens']);
    $prepared = compactHas($repository, '/->prepare\(/') && compactHas($repository, '/->execute\(/');
    $serviceChain = compactHas($controller, '/\$this->history->page\(/') && compactHas($service, '/paginateForProduct\(/') && compactHas($service, '/ProductHistoryQueryRepository/');

    $selectSqlLiteralCount = 0;
    foreach ($loaded['repository']['tokens'] as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $literal = strtoupper($token[1]);
        if (str_contains($literal, 'SELECT')) {
            $selectSqlLiteralCount++;
        }
    }

    checkStatus('READ-01-controller-service-repository-chain', $serviceChain ? 'STATIC_PASS' : 'STATIC_FAIL', 'declared_history_read_chain', $checks);
    checkStatus('READ-02-prepared-select-boundary', $repositoryMutation ? 'STATIC_FAIL' : 'STATIC_UNVERIFIED', $prepared ? 'prepared_calls_present_but_full_call_graph_unverified' : 'prepared_calls_not_proven', $checks);
    checkStatus('READ-02-select-literals', $repositoryMutation ? 'STATIC_FAIL' : ($selectSqlLiteralCount > 0 ? 'STATIC_PASS' : 'STATIC_UNVERIFIED'), $selectSqlLiteralCount > 0 ? 'select_literals_detected' : 'select_literals_not_proven', $checks);
    checkStatus('READ-03-no-write-sql', $repositoryMutation ? 'STATIC_FAIL' : 'STATIC_UNVERIFIED', 'absence_of_mutation_literals_does_not_prove_all_dependencies', $checks);
    checkStatus('READ-04-runtime-read_only', 'RUNTIME_REQUIRED', 'requires_execution_with_database_guards', $checks);

    $registryShape = compactHas($config, '/\x27events\x27=>\[/')
        && compactHas($config, '/productos\.crear/')
        && compactHas($config, '/metadata\.producto_id/')
        && compactHas($config, '/none_batch/');
    $presenterShape = compactHas($presenter, '/publicfunctionpresent\(string\$action,\?string\$subresource\):array/')
        && compactHas($presenter, '/\x27category\x27/')
        && compactHas($presenter, '/\x27summary\x27/');
    $itemProperties = [
        'auditId', 'occurredAt', 'action', 'category', 'subresource', 'result',
        'actorId', 'actorDisplay', 'productId', 'entity', 'entityId', 'summary',
        'changedFields', 'before', 'after', 'metadata', 'completeness',
    ];
    $itemPropertyEvidence = true;
    foreach ($itemProperties as $property) {
        if (!compactHas($item, '/public[^;]*\$' . preg_quote($property, '/') . '\b/')) {
            $itemPropertyEvidence = false;
            break;
        }
    }
    checkStatus('EXP-REGISTRY-STRUCTURE', $registryShape ? 'STATIC_PASS' : 'STATIC_UNVERIFIED', $registryShape ? 'events_direct_subresource_and_batch_shapes_present' : 'registry_shape_not_fully_proven', $checks);
    checkStatus('EXP-PRESENTER-STRUCTURE', $presenterShape ? 'STATIC_PASS' : 'STATIC_UNVERIFIED', $presenterShape ? 'public_present_contract_and_safe_labels' : 'presenter_contract_not_fully_proven', $checks);
    checkStatus('EXP-ITEM-STRUCTURE', $itemPropertyEvidence ? 'STATIC_PASS' : 'STATIC_UNVERIFIED', $itemPropertyEvidence ? 'seventeen_public_properties_present' : 'item_properties_not_fully_proven', $checks);

    foreach ($checks as $check) {
        if ($check['status'] === 'STATIC_FAIL') {
            $staticFail = true;
            break;
        }
    }
} catch (Throwable $exception) {
    checkStatus('HARNESS_INTERNAL', 'STATIC_FAIL', safeSummary($exception->getMessage()), $checks);
    $staticFail = true;
}

$counts = [
    'STATIC_PASS' => 0,
    'STATIC_FAIL' => 0,
    'STATIC_UNVERIFIED' => 0,
    'RUNTIME_REQUIRED' => 0,
];
foreach ($checks as $check) {
    $counts[$check['status']]++;
}

$report = [
    'status' => $staticFail ? 'BLOCKED' : 'PASS_READY_FOR_STATIC_REVIEW',
    'mode' => 'STATIC_SOURCE_ANALYSIS_ONLY',
    'database_free' => true,
    'checks' => $checks,
    'counts' => $counts,
    'inspected_files' => array_values($sourcePaths),
    'side_effects' => [
        'bootstrap_loaded' => false,
        'routes_loaded_operatively' => false,
        'pdo_constructed' => false,
        'session_started' => false,
        'http_executed' => false,
        'sql_executed' => false,
        'smtp_executed' => false,
        'writes_performed' => false,
    ],
    'observations' => [
        'runtime_security_requires_authenticated_http_and_read_only_db_tests',
        'duplicate_query_key_detection_is_not_guaranteed_after_php_parsing',
        'debug_must_remain_disabled_for_public_error_safety',
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($staticFail ? 1 : 0);
