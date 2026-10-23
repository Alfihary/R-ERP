<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/bootstrap/autoload.php';

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

function check(bool $condition, string $scenario, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($scenario . ': ' . $message);
    }
}

/** @return array{status:string,error:?string} */
function runGroup(string $name, callable $test): array
{
    try {
        $test();
        return ['status' => 'PASS', 'error' => null];
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $safe = preg_replace('/[\r\n]+/', ' ', $message) ?: 'UNEXPECTED_COMPONENT_FAILURE';
        return [
            'status' => 'FAIL',
            'error' => substr($safe, 0, 240),
        ];
    }
}

$executed = [];
$failures = [];

$record = static function (string $name, callable $test) use (&$executed, &$failures): void {
    $result = runGroup($name, $test);
    $executed[$name] = $result['status'];
    if ($result['status'] === 'FAIL') {
        $failures[$name] = $result['error'];
    }
};

$record('HTTP-C01_REQUEST', static function (): void {
    $request = new Request('GET', '/productos/historial', [
        'id_producto' => 'QATEST001',
        'page' => '2',
    ]);
    check($request->method() === 'GET', 'HTTP-C01', 'GET method was not preserved.');
    check($request->path() === '/productos/historial', 'HTTP-C01', 'Request path was not normalized.');
    check($request->query()['id_producto'] === 'QATEST001', 'HTTP-C01', 'Scalar query was not preserved.');
    check($request->query()['page'] === '2', 'HTTP-C01', 'Pagination query was not preserved.');

    $postRequest = new Request('POST', '/productos/historial', []);
    check($postRequest->method() === 'POST', 'HTTP-C01', 'POST method was not preserved.');

    $missingRequest = new Request('GET', '/productos/historial', []);
    check(!array_key_exists('id_producto', $missingRequest->query()), 'HTTP-C01', 'Missing parameter was not preserved.');

    $emptyRequest = new Request('GET', '/productos/historial', ['id_producto' => '']);
    check($emptyRequest->query()['id_producto'] === '', 'HTTP-C01', 'Empty parameter was not preserved.');

    $arrayRequest = new Request('GET', '/productos/historial', ['id_producto' => ['QATEST001']]);
    check(is_array($arrayRequest->query()['id_producto']), 'HTTP-C01', 'Array query input was not preserved for Controller validation.');
});

$record('HTTP-C02_RESPONSE', static function (): void {
    $json = Response::json(['items' => [], 'total' => 0]);
    check($json->status() === 200, 'HTTP-C02', 'JSON response status mismatch.');
    $decoded = json_decode($json->body(), true);
    check(is_array($decoded) && ($decoded['items'] ?? null) === [] && ($decoded['total'] ?? null) === 0, 'HTTP-C02', 'JSON response body mismatch.');
    $headersProperty = new ReflectionProperty(Response::class, 'headers');
    $jsonHeaders = $headersProperty->getValue($json);
    check(($jsonHeaders['Content-Type'] ?? null) === 'application/json; charset=UTF-8', 'HTTP-C02', 'JSON Content-Type mismatch.');

    $redirect = Response::redirect('/login');
    check($redirect->status() === 302 && $redirect->body() === '', 'HTTP-C02', 'Redirect response mismatch.');
    $redirectHeaders = $headersProperty->getValue($redirect);
    check(($redirectHeaders['Location'] ?? null) === '/login', 'HTTP-C02', 'Redirect Location mismatch.');

    $html = Response::html('<p>ok</p>');
    check($html->status() === 200 && $html->body() === '<p>ok</p>', 'HTTP-C02', 'HTML response mismatch.');
});

$record('HTTP-C03_ROUTER', static function (): void {
    $router = new Router();
    $router->get('/productos/historial', static fn (Request $request): Response => Response::json(['route' => 'get']));
    $router->post('/productos/historial', static fn (Request $request): Response => Response::json(['route' => 'post']));
    $getResponse = $router->dispatch(new Request('GET', '/productos/historial'));
    check($getResponse->status() === 200, 'HTTP-C03', 'GET route did not resolve.');
    $postResponse = $router->dispatch(new Request('POST', '/productos/historial'));
    check($postResponse->status() === 200, 'HTTP-C03', 'POST route did not resolve when explicitly registered.');
    $missingResponse = $router->dispatch(new Request('GET', '/productos/no-existe'));
    check($missingResponse->status() === 404, 'HTTP-C03', 'Unknown route did not return 404.');
});

$record('HTTP-C05_ERROR_HANDLER', static function (): void {
    $handler = new ErrorHandler(false);
    $errorResponse = $handler->render(new RuntimeException('synthetic isolated failure'));
    check($errorResponse->status() === 500, 'HTTP-C05', 'ErrorHandler did not return 500.');
    check(!str_contains($errorResponse->body(), 'synthetic isolated failure'), 'HTTP-C05', 'Internal exception leaked with debug disabled.');
    check(!str_contains($errorResponse->body(), BASE_PATH), 'HTTP-C05', 'Internal path leaked with debug disabled.');
});

$staticOnly = [
    'AUTH_MIDDLEWARE_LOGIN_REDIRECT',
    'PERMISSION_PRODUCT_ACCESS_403',
    'PERMISSION_PRODUCT_VIEW_403',
    'MIDDLEWARE_ORDER_AUTH_ACCESS_VIEW_CONTROLLER',
    'CONTROLLER_400_404_200_MAPPING',
    'CONTROLLER_SIX_FIELD_ROOT_PAYLOAD',
    'CONTROLLER_SEVENTEEN_FIELD_ITEM_PAYLOAD',
    'PUBLIC_DATE_FORMAT_YYYY_MM_DD',
    'POST_CSRF_419_AND_ROUTE_404_CONTRACT',
];
$requiresRealDb = [
    'PRODUCT_HISTORY_CONTROLLER_SERVICE_PATH',
    'AUTH_SERVICE_WITH_USER_REPOSITORY',
    'PERMISSION_SERVICE_WITH_REAL_USER_SCOPE',
    'END_TO_END_GET_PRODUCT_HISTORY',
];
$scenarioStates = [
    ...$executed,
    'HTTP-C04_CSRF' => 'NOT_EXECUTED',
    'HTTP-C06_CONTROLLER' => 'NOT_EXECUTED',
    'HTTP-C07_AUTH_PERMISSION' => 'NOT_EXECUTED',
    'HTTP-C08_ENDPOINT_END_TO_END' => 'NOT_EXECUTED',
];
$failedCount = count($failures);
$passedCount = count(array_filter($executed, static fn (string $state): bool => $state === 'PASS'));
$notExecutedCount = count(array_filter(
    $scenarioStates,
    static fn (string $state): bool => $state === 'NOT_EXECUTED'
));
$status = $failedCount > 0 ? 'FAIL' : 'PASS';

echo json_encode([
    'status' => $status,
    'database_free' => true,
    'bootstrap_loaded' => false,
    'pdo_constructed' => false,
    'session_started' => false,
    'executed_component_tests' => $executed,
    'scenario_states' => $scenarioStates,
    'passed_tests' => $passedCount,
    'failed_tests' => $failedCount,
    'not_executed_tests' => $notExecutedCount,
    'failures' => $failures,
    'static_only_contracts' => $staticOnly,
    'requires_real_db' => $requiresRealDb,
    'not_executed' => [
        'CSRF_MIDDLEWARE_SESSION_PATH',
        'PRODUCT_HISTORY_CONTROLLER',
        'AUTH_MIDDLEWARE_WITH_REAL_AUTH_SERVICE',
        'PERMISSION_MIDDLEWARE_WITH_REAL_PERMISSION_SERVICE',
        'END_TO_END_ENDPOINT',
    ],
    'end_to_end_pass' => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failedCount > 0 ? 1 : 0);
