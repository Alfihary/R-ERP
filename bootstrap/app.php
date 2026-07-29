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
use App\Domain\Catalogs\ClassificationService;
use App\Domain\Catalogs\ExchangeRateService;
use App\Domain\Catalogs\SatCatalogService;
use App\Domain\Configuration\CompanyService;
use App\Domain\Configuration\WarehouseService;
use App\Domain\Folios\FolioService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryTransferService;
use App\Domain\Pricing\PriceListService;
use App\Domain\Pricing\ProductPriceService;
use App\Domain\Profile\ProfileService;
use App\Domain\Products\ProductImageService;
use App\Domain\Products\ProductService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\ErrorHandlingMiddleware;
use App\Http\Middlewares\SecurityHeadersMiddleware;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ClassificationController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\FolioSeriesController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryTransferController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProductPriceController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SatCatalogController;
use App\Http\Controllers\WarehouseController;
use App\Support\Security\CsrfTokenService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\CatalogRepository;
use App\Infrastructure\Repositories\ClassificationRepository;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ExchangeRateRepository;
use App\Infrastructure\Repositories\FolioRepository;
use App\Infrastructure\Repositories\FolioSeriesRepository;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Infrastructure\Repositories\InventoryRepository;
use App\Infrastructure\Repositories\PriceListRepository;
use App\Infrastructure\Repositories\ProductRepository;
use App\Infrastructure\Repositories\ProductDocumentRepository;
use App\Infrastructure\Repositories\ProductPriceHistoryRepository;
use App\Infrastructure\Repositories\ProductPriceRepository;
use App\Infrastructure\Repositories\ProfileRepository;
use App\Infrastructure\Repositories\SatCatalogRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserPhotoRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Repositories\WarehouseRepository;

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
$classifications = new ClassificationService(
    new ClassificationRepository($connection)
);
$exchangeRates = new ExchangeRateService(
    new ExchangeRateRepository($connection)
);
$satCatalogs = new SatCatalogService(new SatCatalogRepository($connection));
$companyRepository = new CompanyRepository($connection);
$warehouseRepository = new WarehouseRepository($connection);
$folioSeriesRepository = new FolioSeriesRepository($connection);
$folioService = new FolioService(new FolioRepository($connection));
$companies = new CompanyService($companyRepository);
$warehouses = new WarehouseService($warehouseRepository);
$productRepository = new ProductRepository($connection);
$productDocuments = new ProductDocumentRepository($connection);
$profiles = new ProfileService(
    new ProfileRepository($connection),
    new UserPhotoRepository($connection)
);
$productPrices = new ProductPriceService(
    new ProductPriceRepository($connection),
    new PriceListRepository($connection),
    new ProductPriceHistoryRepository($connection)
);
$priceLists = new PriceListService(new PriceListRepository($connection));
$products = new ProductService($productRepository, $productPrices);
$productImages = new ProductImageService(
    $productRepository,
    $productDocuments,
    (string) $config->get('paths.STORAGE_PATH', STORAGE_PATH)
);
$inventoryRepository = new InventoryRepository($connection);
$inventory = new InventoryService($inventoryRepository, $folioService);
$inventoryTransfers = new InventoryTransferService($inventoryRepository, $folioService);
$inventoryQueries = new InventoryQueryRepository($connection);
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
$classificationController = new ClassificationController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $catalogs,
    $classifications
);
$exchangeRateController = new ExchangeRateController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $catalogs,
    $exchangeRates
);
$satCatalogController = new SatCatalogController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $catalogs,
    $satCatalogs
);
$companyController = new CompanyController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $companies
);
$warehouseController = new WarehouseController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $warehouses,
    $companies
);
$folioSeriesController = new FolioSeriesController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $folioSeriesRepository
);
$profileController = new ProfileController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $profiles
);
$productController = new ProductController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $products,
    $productImages
);
$priceListController = new PriceListController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $priceLists
);
$productPriceController = new ProductPriceController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $productPrices
);
$inventoryController = new InventoryController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $inventory,
    $inventoryQueries
);
$inventoryTransferController = new InventoryTransferController(
    $config,
    $auth,
    $permissions,
    $scopeContext,
    $csrf,
    $inventoryTransfers,
    $inventoryQueries
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
    $catalogController,
    $classificationController,
    $exchangeRateController,
    $satCatalogController,
    $companyController,
    $warehouseController,
    $folioSeriesController,
    $profileController,
    $productController,
    $priceListController,
    $productPriceController,
    $inventoryController,
    $inventoryTransferController
);

return new App($router, $config, $debug, $errorHandler);
