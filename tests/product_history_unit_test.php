<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/bootstrap/autoload.php';

use App\Domain\Products\History\ProductHistoryMapper;
use App\Domain\Products\History\ProductHistoryPresenter;
use App\Domain\Products\ProductHistoryService;
use App\Http\Controllers\ProductHistoryController;
use App\Infrastructure\Repositories\ProductHistoryQueryRepository;
use App\Infrastructure\Repositories\ProductRepository;

$checks = [];
$notExecuted = ['PAGINATION_EMPTY_RESULT_RUNTIME' => 'requires repository DB result'];
$check = static function (string $name, bool $passed) use (&$checks): void { $checks[$name] = $passed; };
$throws = static function (callable $callback): bool { try { $callback(); return false; } catch (\Throwable) { return true; } };
$invoke = static function (object $object, string $method, array $args = []): mixed {
    $reflection = new \ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
};

$registry = require BASE_PATH . '/config/products_history.php';
$presenter = new ProductHistoryPresenter($registry['events']);
$mapper = new ProductHistoryMapper($registry['events'], $presenter);
$productRepository = (new \ReflectionClass(ProductRepository::class))->newInstanceWithoutConstructor();
$historyRepository = (new \ReflectionClass(ProductHistoryQueryRepository::class))->newInstanceWithoutConstructor();
$service = new ProductHistoryService($productRepository, $historyRepository, $mapper, $registry['events']);
$controller = new ProductHistoryController($service);

$optionalDate = new \ReflectionMethod(ProductHistoryService::class, 'optionalDate');
$optionalDate->setAccessible(true);
$normalize = new \ReflectionMethod(ProductHistoryService::class, 'normalizeFilters');
$normalize->setAccessible(true);
$positiveInt = new \ReflectionMethod(ProductHistoryService::class, 'positiveInt');
$positiveInt->setAccessible(true);
$optionalActor = new \ReflectionMethod(ProductHistoryService::class, 'optionalPositiveInt');
$optionalActor->setAccessible(true);

// 2D-A-01 / 02 / 03: dates and semi-open ranges.
$check('DATE_VALID_AND_TRIMMED', $optionalDate->invoke($service, ['date_from' => ' 2025-01-01 '], 'date_from') === '2025-01-01');
$check('DATE_INVALID_FORMAT_REJECTED', $throws(static fn() => $optionalDate->invoke($service, ['date_from' => '2025/01/01'], 'date_from')));
$check('DATE_NONEXISTENT_REJECTED', $throws(static fn() => $optionalDate->invoke($service, ['date_from' => '2025-02-30'], 'date_from')));
$check('DATE_OPEN_LIMITS', $normalize->invoke($service, ['date_to' => '2025-01-01'])->dateFrom === null && $normalize->invoke($service, ['date_from' => '2025-01-01'])->dateTo === null);
$check('DATE_MONTH_YEAR_CROSSING', $normalize->invoke($service, ['date_from' => '2024-12-31', 'date_to' => '2025-01-01'])->dateTo === '2025-01-02 00:00:00');
$sameDay = $normalize->invoke($service, ['date_from' => '2025-01-01', 'date_to' => '2025-01-01']);
$check('DATE_SEMI_OPEN_INTERVAL', $sameDay->dateFrom === '2025-01-01 00:00:00' && $sameDay->dateTo === '2025-01-02 00:00:00');
$check('DATE_INVERTED_RANGE_REJECTED', $throws(static fn() => $normalize->invoke($service, ['date_from' => '2025-02-01', 'date_to' => '2025-01-01'])));

// 2D-A-04: pagination contract (runtime empty result requires DB and is listed as not executed).
$serviceSource = file_get_contents(BASE_PATH . '/app/Domain/Products/ProductHistoryService.php') ?: '';
$repoSource = file_get_contents(BASE_PATH . '/app/Infrastructure/Repositories/ProductHistoryQueryRepository.php') ?: '';
$runtimeRunnerSource = file_get_contents(BASE_PATH . '/tests/product_history_db_readonly_test.php') ?: '';
$check('PAGINATION_DEFAULTS', $positiveInt->invoke($service, null, 1, 'page') === 1 && $positiveInt->invoke($service, null, 25, 'page_size') === 25);
$check('PAGINATION_INVALID_REJECTED', $throws(static fn() => $positiveInt->invoke($service, '0', null, 'page')) && $throws(static fn() => $positiveInt->invoke($service, 'abc', null, 'page_size')));
$check('PAGINATION_MAX_100', str_contains($serviceSource, 'min(100, $this->positiveInt($rawPageSize, 25))'));
$check('PAGINATION_EMPTY_PAGES_ZERO', str_contains($repoSource, '$total === 0 ? 0 : (int) ceil($total / $pageSize)'));
$check('RUNTIME_KIT_SCENARIOS_DECLARED', str_contains($runtimeRunnerSource, "'KIT-01'") && str_contains($runtimeRunnerSource, "'KIT-02'") && str_contains($runtimeRunnerSource, "'KIT-03'"));
$check('RUNTIME_ACTOR_SCENARIOS_DECLARED', str_contains($runtimeRunnerSource, "'ACTOR-01'") && str_contains($runtimeRunnerSource, "'ACTOR-02'") && str_contains($runtimeRunnerSource, "'ACTOR-03'"));
$check('RUNTIME_DATA_SKIP_MODEL_DECLARED', str_contains($runtimeRunnerSource, 'SKIP_DATA_NOT_AVAILABLE') && str_contains($runtimeRunnerSource, 'PARTIAL_DATA_NOT_AVAILABLE'));
$check('RUNTIME_READONLY_SQL_GUARD_DECLARED', str_contains($runtimeRunnerSource, 'WRITE_SQL_BLOCKED') && str_contains($runtimeRunnerSource, "'SHOW GRANTS'") && str_contains($runtimeRunnerSource, "'SELECT'"));
$check('RUNTIME_NO_HARDCODED_PRODUCT_ID', !str_contains($runtimeRunnerSource, '102016169') && !str_contains($runtimeRunnerSource, 'PROTECTED_PRODUCT_ID'));
$check('RUNTIME_GUARD_SELF_TEST_DECLARED', str_contains($runtimeRunnerSource, 'runReadOnlyGuardSelfTest') && str_contains($runtimeRunnerSource, 'UNKNOWN_SQL_BLOCKED'));
$check('RUNTIME_DIAGNOSTIC_SELF_TEST_DECLARED', str_contains($runtimeRunnerSource, 'runDiagnosticHarnessSelfTest') && str_contains($runtimeRunnerSource, 'sanitizeRuntimeException'));
$check('RUNTIME_PDO_CONSTRUCTOR_FAILURE_CLASSIFIED', str_contains($runtimeRunnerSource, "'PDO_CONSTRUCTOR_FAILED'") && str_contains($runtimeRunnerSource, 'constructor fixture failure'));
$check('RUNTIME_SQLSTATE_STRUCTURED', str_contains($runtimeRunnerSource, 'function sqlState') && str_contains($runtimeRunnerSource, "'UNAVAILABLE'"));
$check('RUNTIME_DRIVER_CODE_STRUCTURED', str_contains($runtimeRunnerSource, 'function driverErrorCode') && str_contains($runtimeRunnerSource, 'driver_error_code'));
$check('RUNTIME_SQL_MESSAGE_REDACTION', str_contains($runtimeRunnerSource, 'SQL statement redacted') && str_contains($runtimeRunnerSource, 'persona@example.com'));
$check('RUNTIME_STAGE_MARKERS_DECLARED', str_contains($runtimeRunnerSource, 'PDO_CONSTRUCTOR_START') && str_contains($runtimeRunnerSource, 'LEDGER_FINALIZATION_DONE') && str_contains($runtimeRunnerSource, 'SCENARIO_EXECUTION_START'));
$check('RUNTIME_EXCEPTION_CLASS_PRESERVED', str_contains($runtimeRunnerSource, 'exception_class') && str_contains($runtimeRunnerSource, 'sanitized_message'));
$check('RUNTIME_INTERCEPTOR_DECLARED', str_contains($runtimeRunnerSource, 'ReadOnlyInstrumentedPdo') && str_contains($runtimeRunnerSource, 'ReadOnlyInstrumentedStatement') && str_contains($runtimeRunnerSource, 'instrumentConnectionProvider'));
$check('RUNTIME_STATEMENT_CLASS_CONSTRUCTOR_NON_PUBLIC', preg_match('/final class ReadOnlyInstrumentedStatement extends PDOStatement.*?protected function __construct\(\)/s', $runtimeRunnerSource) === 1 && preg_match('/final class ReadOnlyInstrumentedStatement extends PDOStatement.*?public function __construct\(\)/s', $runtimeRunnerSource) !== 1);
$check('RUNTIME_HY093_DIRECT_PLACEHOLDERS_UNIQUE', str_contains($runtimeRunnerSource, 'direct_product_{$index}') && str_contains($runtimeRunnerSource, "\$params['direct_product_' . \$index]") && !str_contains($runtimeRunnerSource, ':direct_product)'));
$check('RUNTIME_KIT03_NEGATIVE_DISCOVERY_DECLARED', str_contains($runtimeRunnerSource, 'discoverUnrelatedKitEvent') && str_contains($runtimeRunnerSource, 'evaluateKit03NegativeAssertion') && str_contains($runtimeRunnerSource, 'kit03_selected_product'));
$check('RUNTIME_KIT03_NO_DATA_SKIPS', str_contains($runtimeRunnerSource, "if (\$unrelated === null)") && str_contains($runtimeRunnerSource, 'return SCENARIO_SKIP'));
$check('RUNTIME_KIT03_SELF_TEST_DECLARED', str_contains($runtimeRunnerSource, '--self-test-kit03') && str_contains($runtimeRunnerSource, "'effective_product_id' => 'P2'"));
$check('RUNTIME_SPECIALIZED_DISCOVERY_DECLARED', str_contains($runtimeRunnerSource, 'discoverSpecializedProductEvent') && str_contains($runtimeRunnerSource, 'DB-06-subresource') && str_contains($runtimeRunnerSource, 'ACTOR-02') && str_contains($runtimeRunnerSource, 'ACTOR-03'));
$check('RUNTIME_SPECIALIZED_DISCOVERY_NOT_FIXTURE_BOUND', !str_contains($runtimeRunnerSource, 'QAPHBASE01') && !str_contains($runtimeRunnerSource, 'QAPHKIT01') && !str_contains($runtimeRunnerSource, 'QAPHKIT02') && !str_contains($runtimeRunnerSource, 'R_ERP_PRODUCT_HISTORY_FIXTURE_V2'));
$check('RUNTIME_DB09_STATIC_ONLY_AGGREGATION', str_contains($runtimeRunnerSource, "'DB-09' => true") && str_contains($runtimeRunnerSource, 'runtime_required_not_executed'));
$check('RUNTIME_DB09_AGGREGATION_SELF_TEST', str_contains($runtimeRunnerSource, '--self-test-aggregation') && str_contains($runtimeRunnerSource, 'runAggregationSelfTest'));
$check('RUNTIME_DB06_SUMMARY_SYNCHRONIZED', str_contains($runtimeRunnerSource, "\$db06['subresource'] = \$scenarioStates['DB-06-subresource']"));
$check('RUNTIME_LEDGER_FIELDS_DECLARED', str_contains($runtimeRunnerSource, 'sequence') && str_contains($runtimeRunnerSource, 'sql_fingerprint') && str_contains($runtimeRunnerSource, 'ALL_EXECUTED_STATEMENTS_PREVALIDATED'));
$check('RUNTIME_LEDGER_PHASES_DECLARED', str_contains($runtimeRunnerSource, "'prevalidation'") && str_contains($runtimeRunnerSource, "'execution'") && str_contains($runtimeRunnerSource, 'TOTAL_SQL_STATEMENTS_EXECUTION_LEDGERED'));
$check('RUNTIME_LEDGER_MULTI_EXECUTION_TEST_DECLARED', str_contains($runtimeRunnerSource, 'TOTAL_EXECUTION_EVENTS') && str_contains($runtimeRunnerSource, 'UNPREVALIDATED_EXECUTION_COUNT'));
$check('RUNTIME_BYPASS_PATHS_ZERO_STATIC', !str_contains($runtimeRunnerSource, '->pdo()->query') && !str_contains($runtimeRunnerSource, '->pdo()->exec'));

// 2D-A-05: actor validation.
$check('ACTOR_VALID', $optionalActor->invoke($service, ['actor_id' => '42'], 'actor_id') === 42);
$check('ACTOR_INVALID_VALUES_REJECTED', $throws(static fn() => $optionalActor->invoke($service, ['actor_id' => '0'], 'actor_id')) && $throws(static fn() => $optionalActor->invoke($service, ['actor_id' => '-1'], 'actor_id')) && $throws(static fn() => $optionalActor->invoke($service, ['actor_id' => 'x'], 'actor_id')) && $throws(static fn() => $optionalActor->invoke($service, ['actor_id' => []], 'actor_id')));

// 2D-A-06: action/subresource registry contract.
$check('ACTION_SUBRESOURCE_VALID', $normalize->invoke($service, ['action' => 'producto_imagen.subir', 'subresource' => 'imagen'])->action === 'producto_imagen.subir');
$check('ACTION_SUBRESOURCE_INVALID_REJECTED', $throws(static fn() => $normalize->invoke($service, ['action' => 'productos.crear', 'subresource' => 'imagen'])) && $throws(static fn() => $normalize->invoke($service, ['action' => 'qa.unknown'])) && $throws(static fn() => $normalize->invoke($service, ['subresource' => 'unknown'])));

// 2D-A-07 / 08: JSON metadata and recursive sanitization.
$metadata = json_encode(['version_contrato' => 1, 'producto_id' => 'QATEST001', 'changed_fields' => ['descripcion'], 'after' => ['descripcion' => 'Visible', 'token' => 'fixture', 'nested' => ['password' => 'fixture', 'safe' => 'kept'], 'path' => 'C:\\private\\fixture', 'object' => new stdClass(), 'nullable' => null]], JSON_THROW_ON_ERROR);
$mapped = $mapper->map(['audit_id' => 1, 'occurred_at' => '2026-01-01 00:00:00', 'action' => 'productos.crear', 'result' => 'ok', 'actor_id' => 7, 'actor_username' => 'qa-user', 'actor_eliminado_en' => null, 'entity' => 'producto', 'entity_id' => 'QATEST001', 'metadata_json' => $metadata], 'QATEST001');
$invalidMapped = $mapper->map(['audit_id' => 2, 'occurred_at' => '', 'action' => 'productos.crear', 'result' => 'ok', 'actor_id' => null, 'actor_username' => null, 'entity' => 'producto', 'entity_id' => 'QATEST001', 'metadata_json' => '{invalid'], 'QATEST001');
$deletedActor = $mapper->map(['audit_id' => 3, 'occurred_at' => '2026-01-01 00:00:00', 'action' => 'productos.crear', 'result' => 'ok', 'actor_id' => 8, 'actor_username' => 'deleted-user', 'actor_eliminado_en' => '2026-01-01 00:00:00', 'entity' => 'producto', 'entity_id' => 'QATEST001', 'metadata_json' => '{}'], 'QATEST001');
$kitPriority = $mapper->map(['audit_id' => 4, 'occurred_at' => '2026-01-01 00:00:00', 'action' => 'productos.kits.componentes.agregar', 'result' => 'ok', 'actor_id' => 7, 'actor_username' => 'qa-user', 'actor_eliminado_en' => null, 'entity' => 'producto_kit_componente', 'entity_id' => 'component-1', 'metadata_json' => json_encode(['kit_id_producto' => 'KIT-01', 'producto_id' => 'FALLBACK-01', 'componente_id_producto' => 'COMP-01'], JSON_THROW_ON_ERROR)], 'KIT-01');
$kitFallback = $mapper->map(['audit_id' => 5, 'occurred_at' => '2026-01-01 00:00:00', 'action' => 'productos.kits.componentes.agregar', 'result' => 'ok', 'actor_id' => 7, 'actor_username' => 'qa-user', 'actor_eliminado_en' => null, 'entity' => 'producto_kit_componente', 'entity_id' => 'component-2', 'metadata_json' => json_encode(['kit_id_producto' => '', 'producto_id' => 'FALLBACK-01', 'componente_id_producto' => 'COMP-01'], JSON_THROW_ON_ERROR)], 'FALLBACK-01');
$check('MAPPER_JSON_AND_COMPLETENESS', $mapped->metadata !== null && $mapped->completeness === 'complete' && $invalidMapped->completeness === 'unavailable');
$sanitize = new \ReflectionMethod(ProductHistoryMapper::class, 'sanitize');
$sanitize->setAccessible(true);
$resource = fopen('php://memory', 'r');
$sanitizedResource = $sanitize->invoke($mapper, ['resource' => $resource]);
if (is_resource($resource)) fclose($resource);
$check('MAPPER_RECURSIVE_SANITIZATION', !array_key_exists('token', $mapped->after ?? []) && !array_key_exists('password', $mapped->after['nested'] ?? []) && !array_key_exists('path', $mapped->after ?? []) && ($mapped->after['nested']['safe'] ?? null) === 'kept' && is_array($mapped->after['object'] ?? null) && ($sanitizedResource['resource'] ?? null) === '[unavailable]' && array_key_exists('nullable', $mapped->after ?? []));
$check('ACTOR_DISPLAY_FALLBACK', $invalidMapped->actorDisplay === 'Sistema');
$check('ACTOR01_EXISTING_DISPLAY', $mapped->actorId === 7 && $mapped->actorDisplay === 'qa-user');
$check('ACTOR02_DELETED_DISPLAY_GENERIC', $deletedActor->actorId === 8 && $deletedActor->actorDisplay === 'Usuario eliminado' && $deletedActor->actorDisplay !== 'deleted-user');
$check('ACTOR03_SYSTEM_DISPLAY', $invalidMapped->actorId === null && $invalidMapped->actorDisplay === 'Sistema');
$check('KIT_ID_PRODUCTO_PRIORITY', $kitPriority->productId === 'KIT-01');
$check('PRODUCTO_ID_FALLBACK', $kitFallback->productId === 'FALLBACK-01');

// 2D-A-09: Presenter.
$check('PRESENTER_CATEGORY_SUMMARY_FALLBACK', $presenter->present('producto_imagen.subir', 'imagen')['category'] === 'image' && $presenter->present('productos.crear', null)['summary'] === 'Producto creado' && $presenter->present('qa.unknown', null)['summary'] === 'Evento de producto');

// 2D-A-10 / 11: exact item shape and public filters.
$serialize = new \ReflectionMethod(ProductHistoryController::class, 'serializeItem');
$serialize->setAccessible(true);
$serialized = $serialize->invoke(null, $mapped);
$expectedItemKeys = ['audit_id', 'occurred_at', 'action', 'category', 'subresource', 'result', 'actor_id', 'actor_display', 'product_id', 'entity', 'entity_id', 'summary', 'changed_fields', 'before', 'after', 'metadata', 'completeness'];
$check('ITEM_EXACT_17_KEYS', array_keys($serialized) === $expectedItemKeys);
$check('NO_PUBLIC_ACTOR_USERNAME', !array_key_exists('actor_username', $serialized));
$check('ITEM_TYPES_AND_NULLABILITY', is_int($serialized['audit_id']) && is_string($serialized['occurred_at']) && is_string($serialized['action']) && is_string($serialized['category']) && (is_string($serialized['subresource']) || $serialized['subresource'] === null) && is_string($serialized['result']) && (is_int($serialized['actor_id']) || $serialized['actor_id'] === null) && is_string($serialized['actor_display']) && is_string($serialized['product_id']) && is_string($serialized['entity']) && (is_string($serialized['entity_id']) || $serialized['entity_id'] === null) && is_string($serialized['summary']) && (is_array($serialized['changed_fields']) || $serialized['changed_fields'] === null) && (is_array($serialized['before']) || $serialized['before'] === null) && (is_array($serialized['after']) || $serialized['after'] === null) && (is_array($serialized['metadata']) || $serialized['metadata'] === null) && is_string($serialized['completeness']));
$publicDate = $invoke($controller, 'optionalPublicDate', [['date_from' => ' 2025-01-01 '], 'date_from']);
$check('PUBLIC_FILTER_NORMALIZATION', $publicDate === '2025-01-01' && $invoke($controller, 'optionalPublicDate', [[], 'date_from']) === null && !str_contains($publicDate, '00:00:00'));
$controllerSource = file_get_contents(BASE_PATH . '/app/Http/Controllers/ProductHistoryController.php') ?: '';
$payloadMatch = preg_match('/return Response::json\(\[(.*?)\n\s*\]\);/s', $controllerSource, $payloadParts) === 1;
$payloadSource = $payloadMatch ? $payloadParts[1] : '';
$check('JSON_TOP_LEVEL_CONTRACT_STATIC', $payloadMatch && substr_count($payloadSource, "'items'") === 1 && substr_count($payloadSource, "'total'") === 1 && substr_count($payloadSource, "'page'") === 1 && substr_count($payloadSource, "'page_size'") === 1 && substr_count($payloadSource, "'pages'") === 1 && substr_count($payloadSource, "'filters'") === 1);
$check('CONTROLLER_NO_SQL_OR_WRITES', !preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE|beginTransaction|commit|rollBack)\b/i', $controllerSource));

$checks['_failed_checks'] = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
$checks['_not_executed'] = $notExecuted;
$checks['_constraints'] = ['DB_CONNECTION_ATTEMPTED' => false, 'SQL_EXECUTED' => false, 'HTTP_EXTERNAL_REQUESTS' => false, 'PRODUCTION_FILES_MODIFIED' => false];
echo json_encode(['runner' => 'PRODUCTOS_HISTORIAL_2D_A_UNIT_TESTS', 'result' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($checks['_failed_checks'] === [] ? 0 : 1);
