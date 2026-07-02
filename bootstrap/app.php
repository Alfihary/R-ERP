<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;
use App\Core\Router;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Catalogs\CatalogService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\ErrorHandlingMiddleware;
use App\Http\Middlewares\SecurityHeadersMiddleware;
use App\Http\Controllers\CatalogController;
use App\Support\Security\CsrfTokenService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\CatalogRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;

if (!defined('BASE_PATH')) {
    throw new RuntimeException('BASE_PATH must be defined before bootstrapping the application.');
}

require BASE_PATH . '/bootstrap/autoload.php';

require BASE_PATH . '/app/Support/Security/helpers.php';

Env::load(BASE_PATH . '/.env');

$paths = require BASE_PATH . '/config/paths.php';

foreach ($paths as $constant => $path) {
    if (!defined($constant)) {
        define($constant, $path);
    }
}

$config = new Config([
    'app' => require CONFIG_PATH . '/app.php',
    'auth' => require CONFIG_PATH . '/auth.php',
    'database' => require CONFIG_PATH . '/database.php',
    'paths' => $paths,
    'security' => require CONFIG_PATH . '/security.php',
    'session' => require CONFIG_PATH . '/session.php',
]);

$timezone = (string) $config->get('app.timezone', 'UTC');
new DateTimeZone($timezone);
date_default_timezone_set($timezone);

$environment = strtolower((string) $config->get('app.env', 'production'));
$debug = $environment !== 'production' && (bool) $config->get('app.debug', false);

ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');

$sessionConfig = $config->get('session', []);

if (!is_array($sessionConfig)) {
    throw new RuntimeException('Session configuration must be an array.');
}

$session = new Session($sessionConfig);
$session->start();

$csrfTtl = (int) $config->get('security.csrf_ttl_seconds', 7200);
$csrf = new CsrfTokenService($session, $csrfTtl);
$errorHandler = new ErrorHandler($debug);
$databaseConfig = $config->get('database', []);

if (!is_array($databaseConfig)) {
    throw new RuntimeException('Database configuration must be an array.');
}

$connection = new ConnectionProvider($databaseConfig);
$auth = new AuthService(new UserRepository($connection), $session);
$permissions = new PermissionService(new PermissionRepository($connection));
$catalogs = new CatalogService(new CatalogRepository($connection));
$userScope = new UserScopeService(new ScopeRepository($connection));
$scopeContext = new ScopeContextService($userScope, $session);
$catalogController = new CatalogController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $catalogs
);

$router = new Router();
$router->middleware(new SecurityHeadersMiddleware());
$router->middleware(new ErrorHandlingMiddleware($errorHandler));
$router->middleware(new CsrfMiddleware($csrf));

$registerRoutes = require ROUTES_PATH . '/web.php';
$registerRoutes(
    $router,
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $catalogController
);

return new App($router, $config, $debug, $errorHandler);
