<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Products\Import\ProductImportBusinessLookup;
use App\Domain\Products\Import\ProductImportBusinessValidator;
use App\Domain\Products\Import\ProductImportConfirmationService;
use App\Domain\Products\Import\ProductImportPreviewException;
use App\Domain\Products\Import\ProductImportPreviewService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Domain\Security\PermissionService;
use App\Http\Controllers\ProductImportController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Import\PrivateProductImportPreviewStore;
use App\Infrastructure\Import\ProductImportReader;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return static function (PDO $pdo, Config $config, string $expectedDatabase): array {
    $suite = new class($pdo, $config, $expectedDatabase) {
        private string $temp;
        private int $now = 1700000000;

        public function __construct(
            private readonly PDO $pdo,
            private readonly Config $config,
            private readonly string $expectedDatabase,
        ) {
        }

        /** @return array<string, mixed> */
        public function run(): array
        {
            if ((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn() !== $this->expectedDatabase) {
                throw new RuntimeException('The connected database does not match the confirmed database.');
            }
            $this->temp = BASE_PATH . '/storage/temp/product-import-preview-' . bin2hex(random_bytes(8));
            if (!mkdir($this->temp, 0700, true) && !is_dir($this->temp)) {
                throw new RuntimeException('Could not create the isolated preview test directory.');
            }
            $before = $this->snapshot();
            try {
                $checks = array_merge($this->serviceChecks(), $this->httpChecks());
            } finally {
                $this->removeTree($this->temp);
            }
            $after = $this->snapshot();
            $checks['business_tables_unchanged'] = hash_equals($before['all'], $after['all']);
            $checks['protected_product_unchanged'] = hash_equals($before['product'], $after['product']);
            $checks['protected_prices_unchanged'] = hash_equals($before['prices'], $after['prices']);
            $checks['protected_inventory_unchanged'] = hash_equals($before['inventory'], $after['inventory']);
            $checks['protected_prices_count_two'] = (int) $this->scalar(
                'SELECT COUNT(*) FROM producto_precios WHERE id_producto = :id',
                ['id' => '102016169'],
            ) === 2;
            $checks['test_storage_cleaned'] = !file_exists($this->temp);

            foreach ($checks as $name => $passed) {
                if ($passed !== true) {
                    throw new RuntimeException('Product import preview assertion failed: ' . $name . '.');
                }
            }
            return [
                'phase' => 'PRODUCTOS-IMPORTACION-PREVIEW-1',
                'status' => 'PASS',
                'checks_total' => count($checks),
                'checks' => $checks,
                'database' => $this->expectedDatabase,
                'business_db_writes' => 0,
                'smtp_used' => false,
                'preview_ttl_seconds' => ProductImportPreviewService::TTL_SECONDS,
                'visible_rows_limit' => ProductImportPreviewService::MAX_VISIBLE_ROWS,
                'cleanup' => 'complete',
            ];
        }

        /** @return array<string, bool> */
        private function serviceChecks(): array
        {
            $service = $this->service($this->temp . '/service');
            $binding = str_repeat('a', 64);
            $validPath = $this->csv('valid.csv', $this->validCsv('NEWPREVIEW1'));
            $valid = $service->create($this->upload($validPath, 'productos-validos.csv'), 10, $binding);
            $metadataPath = $this->metadataPath($this->temp . '/service', $valid['preview_id']);
            $metadata = json_decode((string) file_get_contents($metadataPath), true, 64, JSON_THROW_ON_ERROR);

            $errorPath = $this->csv('errors.csv', $this->validCsv('EXISTE1'));
            $withErrors = $service->create($this->upload($errorPath, 'productos-errores.csv'), 10, $binding);

            $unknownPath = $this->csv(
                'unknown.csv',
                "id_producto,descripcion,tipo_producto_codigo,unidad_medida_codigo,precio_lista\nNEWGLOBAL1,Producto,PRODUCTO,PZA,10\n",
            );
            $global = $service->create($this->upload($unknownPath, 'global.csv'), 10, $binding);

            $many = "id_producto,descripcion,tipo_producto_codigo,unidad_medida_codigo\n";
            for ($index = 1; $index <= 25; ++$index) {
                $many .= 'PREV' . $index . ',Producto ' . $index . ",PRODUCTO,PZA\n";
            }
            $manyPath = $this->csv('many.csv', $many);
            $manyPreview = $service->create($this->upload($manyPath, 'muchas-filas.csv'), 10, $binding);

            $ownership = $this->errorCode(fn () => $service->get($valid['preview_id'], 11, $binding));
            $bindingDenied = $this->errorCode(fn () => $service->get($valid['preview_id'], 10, str_repeat('b', 64)));
            $traversal = $this->errorCode(fn () => $service->get('../metadata', 10, $binding));

            $discardPath = $this->csv('discard.csv', $this->validCsv('NEWDISCARD1'));
            $discardPreview = $service->create($this->upload($discardPath, 'discard.csv'), 10, $binding);
            $discarded = $service->discard($discardPreview['preview_id'], 10, $binding);
            $discardedAgain = !$service->discard($discardPreview['preview_id'], 10, $binding);

            $missingPath = $this->csv('missing.csv', $this->validCsv('NEWMISSING1'));
            $missing = $service->create($this->upload($missingPath, 'missing.csv'), 10, $binding);
            @unlink($this->uploadPath($this->temp . '/service', $missing['preview_id'], 'csv'));
            $missingCode = $this->errorCode(fn () => $service->get($missing['preview_id'], 10, $binding));

            $corruptPath = $this->csv('corrupt.csv', $this->validCsv('NEWCORRUPT1'));
            $corrupt = $service->create($this->upload($corruptPath, 'corrupt.csv'), 10, $binding);
            file_put_contents($this->metadataPath($this->temp . '/service', $corrupt['preview_id']), '{not-json');
            $corruptCode = $this->errorCode(fn () => $service->get($corrupt['preview_id'], 10, $binding));

            $outsidePath = $this->csv('outside.csv', $this->validCsv('NEWOUTSIDE1'));
            $outside = $service->create($this->upload($outsidePath, 'outside.csv'), 10, $binding);
            $outsideMetaPath = $this->metadataPath($this->temp . '/service', $outside['preview_id']);
            $outsideMeta = json_decode((string) file_get_contents($outsideMetaPath), true, 64, JSON_THROW_ON_ERROR);
            $outsideMeta['stored_file'] = '../outside.csv';
            file_put_contents($outsideMetaPath, json_encode($outsideMeta, JSON_THROW_ON_ERROR));
            $outsideCode = $this->errorCode(fn () => $service->get($outside['preview_id'], 10, $binding));

            $expiryRoot = $this->temp . '/expiry';
            $expiryService = $this->service($expiryRoot);
            $expiryPath = $this->csv('expiry.csv', $this->validCsv('NEWEXPIRY1'));
            $expiring = $expiryService->create($this->upload($expiryPath, 'expiry.csv'), 10, $binding);
            $this->now += ProductImportPreviewService::TTL_SECONDS + 1;
            $expiredCode = $this->errorCode(fn () => $expiryService->get($expiring['preview_id'], 10, $binding));

            $cleanupPath = $this->csv('cleanup.csv', $this->validCsv('NEWCLEANUP1'));
            $cleanupPreview = $expiryService->create($this->upload($cleanupPath, 'cleanup.csv'), 10, $binding);
            $this->now += ProductImportPreviewService::TTL_SECONDS + 1;
            $cleanupCount = $expiryService->cleanupExpired();

            $corruptCleanupRoot = $this->temp . '/corrupt-cleanup';
            $corruptCleanupService = $this->service($corruptCleanupRoot);
            $corruptCleanupPath = $this->csv('corrupt-cleanup.csv', $this->validCsv('NEWCORRUPTCLEAN1'));
            $corruptCleanup = $corruptCleanupService->create(
                $this->upload($corruptCleanupPath, 'corrupt-cleanup.csv'),
                10,
                $binding,
            );
            $corruptCleanupMetadataPath = $this->metadataPath(
                $corruptCleanupRoot,
                $corruptCleanup['preview_id'],
            );
            $corruptCleanupMetadata = json_decode(
                (string) file_get_contents($corruptCleanupMetadataPath),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
            $corruptCleanupMetadata['schema_version'] = 99;
            file_put_contents(
                $corruptCleanupMetadataPath,
                json_encode($corruptCleanupMetadata, JSON_THROW_ON_ERROR),
            );
            $corruptCleanupCount = $corruptCleanupService->cleanupExpired();

            $encodedMetadata = (string) file_get_contents($metadataPath);
            return [
                'create_valid_preview' => $valid['status'] === 'ready' && $valid['invalid_rows'] === 0,
                'preview_with_errors' => $withErrors['status'] === 'errors' && $withErrors['invalid_rows'] === 1,
                'preview_id_random' => preg_match('/^[a-f0-9]{64}$/', $valid['preview_id']) === 1
                    && $valid['preview_id'] !== $withErrors['preview_id'],
                'sha256_correct' => hash_equals(
                    hash_file('sha256', $this->uploadPath($this->temp . '/service', $valid['preview_id'], 'csv')),
                    (string) $metadata['sha256'],
                ),
                'ownership_correct' => $ownership === 'preview_not_found',
                'session_binding_correct' => $bindingDenied === 'preview_not_found',
                'preview_expired' => $expiredCode === 'preview_expired',
                'metadata_json_valid' => is_array($metadata) && ($metadata['schema_version'] ?? 0) === 1,
                'no_php_serialize' => str_starts_with(ltrim($encodedMetadata), '{')
                    && $this->previewCodeAvoidsPhpSerialization(),
                'cleanup_manual' => $cleanupCount === 1 && !is_file($this->metadataPath($expiryRoot, $cleanupPreview['preview_id'])),
                'cleanup_corrupt_metadata' => $corruptCleanupCount === 1
                    && !is_file($corruptCleanupMetadataPath),
                'discard' => $discarded,
                'discard_idempotent' => $discardedAgain,
                'path_traversal_preview_id' => $traversal === 'preview_not_found',
                'path_outside_root' => $outsideCode === 'preview_metadata_corrupt',
                'missing_upload_file' => $missingCode === 'preview_file_missing',
                'corrupt_metadata' => $corruptCode === 'preview_metadata_corrupt',
                'visible_rows_max_20' => count($manyPreview['visible_rows']) === 20,
                'global_errors_separated' => count($global['global_errors']) === 1 && $global['row_errors'] === [],
                'valid_invalid_counts' => $valid['total_rows'] === 1 && $withErrors['valid_rows'] === 0,
                'no_business_write_calls' => $this->noBusinessWriteCalls(),
                'used_at_reserved' => array_key_exists('used_at', $metadata) && $metadata['used_at'] === null,
                'original_name_sanitized' => $valid['original_name'] === 'productos-validos.csv',
                'stored_path_not_exposed' => !array_key_exists('stored_file', $valid),
            ];
        }

        /** @return array<string, bool> */
        private function httpChecks(): array
        {
            $database = $this->config->get('database', []);
            if (!is_array($database)) {
                throw new RuntimeException('HTTP test database config is invalid.');
            }
            $provider = new ConnectionProvider($database);
            $sessionPath = $this->temp . '/sessions';
            if (!mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
                throw new RuntimeException('Could not create the isolated HTTP session directory.');
            }
            if (ini_set('session.save_path', $sessionPath) === false) {
                throw new RuntimeException('Could not isolate the HTTP test session storage.');
            }
            $session = new Session([
                'name' => 'preview_test_' . bin2hex(random_bytes(4)),
                'same_site' => 'Lax',
                'secure' => false,
                'gc_max_lifetime' => 7200,
            ]);
            $session->start();
            $csrf = new CsrfTokenService($session);
            $auth = new AuthService(new UserRepository($provider), $session);
            $permissions = new PermissionService(new PermissionRepository($provider));
            $scope = new ScopeContextService(new UserScopeService(new ScopeRepository($provider)), $session);
            $service = $this->service($this->temp . '/http');
            $confirmationService = $this->confirmationService($this->temp . '/http-confirmation');
            $controller = new ProductImportController(
                $this->config,
                $auth,
                $permissions,
                $scope,
                $csrf,
                $service,
                $confirmationService,
            );
            $router = new Router();
            $router->middleware(new CsrfMiddleware($csrf));
            $middleware = [
                new AuthMiddleware($auth),
                new PermissionMiddleware($auth, $permissions, 'productos.acceder'),
                new PermissionMiddleware($auth, $permissions, 'productos.crear'),
            ];
            $router->get('/productos/importar', fn (Request $request) => $controller->index($request), $middleware);
            $router->post('/productos/importar/validar', fn (Request $request) => $controller->validateFile($request), $middleware);
            $router->post('/productos/importar/descartar', fn (Request $request) => $controller->discard($request), $middleware);

            try {
                $guest = $router->dispatch(new Request('GET', '/productos/importar'));
                $authorized = $this->authorizedUser();
                $session->put('auth_user', $authorized);
                $getAllowed = $router->dispatch(new Request('GET', '/productos/importar'));
                $token = $csrf->token();
                $noCsrf = $router->dispatch(new Request('POST', '/productos/importar/validar'));

                $session->put('auth_user', ['user_id' => 2147483647, 'username' => 'qa-denied', 'email' => 'qa-denied@example.invalid']);
                $deniedGet = $router->dispatch(new Request('GET', '/productos/importar'));
                $deniedPost = $router->dispatch(new Request('POST', '/productos/importar/validar', [], ['_token' => $token]));

                $session->put('auth_user', $authorized);
                $httpCsv = $this->csv('http.csv', $this->validCsv('NEWHTTP1'));
                $validPost = $router->dispatch(new Request(
                    'POST',
                    '/productos/importar/validar',
                    [],
                    ['_token' => $token],
                    [],
                    ['archivo' => $this->upload($httpCsv, 'http.csv')],
                ));
                $metadataFiles = glob($this->temp . '/http/private/product_import_previews/metadata/*.json') ?: [];
                $httpPreviewId = $metadataFiles === [] ? '' : pathinfo($metadataFiles[0], PATHINFO_FILENAME);
                $discardNoCsrf = $router->dispatch(new Request('POST', '/productos/importar/descartar', [], ['preview_id' => $httpPreviewId]));

                $session->put('auth_user', ['user_id' => 2147483647, 'username' => 'qa-denied', 'email' => 'qa-denied@example.invalid']);
                $discardOther = $router->dispatch(new Request('POST', '/productos/importar/descartar', [], ['_token' => $token, 'preview_id' => $httpPreviewId]));

                $session->put('auth_user', $authorized);
                $discardOwn = $router->dispatch(new Request('POST', '/productos/importar/descartar', [], ['_token' => $token, 'preview_id' => $httpPreviewId]));

                return [
                    'http_guest_redirect' => $guest->status() === 302,
                    'http_authorized_get' => $getAllowed->status() === 200 && str_contains($getAllowed->body(), 'Importar productos'),
                    'http_without_permission_get' => $deniedGet->status() === 403,
                    'http_validate_without_csrf' => $noCsrf->status() === 419,
                    'http_validate_without_permission' => $deniedPost->status() === 403,
                    'http_validate_valid' => $validPost->status() === 302 && $httpPreviewId !== '',
                    'http_discard_without_csrf' => $discardNoCsrf->status() === 419,
                    'http_discard_other_user' => in_array($discardOther->status(), [403, 404], true),
                    'http_discard_own' => $discardOwn->status() === 302,
                ];
            } finally {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $session->invalidate();
                }
            }
        }

        private function service(string $root): ProductImportPreviewService
        {
            return new ProductImportPreviewService(
                new ProductImportReader(),
                new ProductImportBusinessValidator($this->lookup()),
                new PrivateProductImportPreviewStore(
                    $root,
                    true,
                    fn (): int => $this->now,
                ),
            );
        }

        private function confirmationService(string $root): ProductImportConfirmationService
        {
            return new ProductImportConfirmationService(
                new ProductImportReader(),
                new ProductImportBusinessValidator($this->lookup()),
                new PrivateProductImportPreviewStore(
                    $root,
                    true,
                    fn (): int => $this->now,
                ),
            );
        }

        private function lookup(): ProductImportBusinessLookup
        {
            return new class implements ProductImportBusinessLookup {
                public function resolveCatalogs(array $codesByField): array
                {
                    $result = [];
                    $id = 1;
                    foreach ($codesByField as $field => $codes) {
                        $result[$field] = [];
                        foreach ($codes as $code) {
                            $result[$field][$code] = ['id' => $id++, 'code' => $code];
                        }
                    }
                    return $result;
                }
                public function existingProductIds(array $productIds): array
                {
                    return in_array('EXISTE1', $productIds, true) ? ['EXISTE1' => true] : [];
                }
                public function conflictingSkus(array $skus): array { return []; }
                public function conflictingBarcodes(array $barcodes): array { return []; }
            };
        }

        /** @return array{user_id: int, username: string, email: string} */
        private function authorizedUser(): array
        {
            $statement = $this->pdo->query(
                "SELECT u.id, u.username, u.email
                 FROM usuarios u
                 INNER JOIN usuario_roles ur ON ur.usuario_id = u.id
                 INNER JOIN rol_permisos rp ON rp.rol_id = ur.rol_id
                 INNER JOIN permisos p ON p.id = rp.permiso_id
                 WHERE u.activo = 1 AND u.eliminado_en IS NULL
                   AND p.codigo IN ('productos.acceder', 'productos.crear')
                 GROUP BY u.id, u.username, u.email
                 HAVING COUNT(DISTINCT p.codigo) = 2
                 ORDER BY u.id LIMIT 1"
            );
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RuntimeException('No authorized product import HTTP test user is available.');
            }
            return ['user_id' => (int) $row['id'], 'username' => (string) $row['username'], 'email' => (string) $row['email']];
        }

        private function validCsv(string $productId): string
        {
            return "id_producto,descripcion,tipo_producto_codigo,unidad_medida_codigo\n"
                . $productId . ",Producto preview,PRODUCTO,PZA\n";
        }

        private function csv(string $name, string $contents): string
        {
            $path = $this->temp . '/' . $name;
            file_put_contents($path, $contents);
            return $path;
        }

        /** @return array{name: string, tmp_name: string, error: int, size: int, type: string} */
        private function upload(string $path, string $name): array
        {
            return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($path), 'type' => 'text/csv'];
        }

        private function metadataPath(string $root, string $id): string
        {
            return $root . '/private/product_import_previews/metadata/' . $id . '.json';
        }

        private function uploadPath(string $root, string $id, string $extension): string
        {
            return $root . '/private/product_import_previews/uploads/' . $id . '.' . $extension;
        }

        private function errorCode(callable $operation): string
        {
            try {
                $operation();
            } catch (ProductImportPreviewException $exception) {
                return $exception->errorCode;
            }
            return '';
        }

        private function noBusinessWriteCalls(): bool
        {
            $paths = [
                BASE_PATH . '/app/Domain/Products/Import/ProductImportPreviewService.php',
                BASE_PATH . '/app/Http/Controllers/ProductImportController.php',
            ];
            $source = implode('', array_map(static fn (string $path): string => (string) file_get_contents($path), $paths));
            return preg_match(
                '/ProductService|ProductRepository|->products?->(?:create|update)\s*\(|->replaceTaxes|->replaceBarcodes/i',
                $source,
            ) === 0;
        }

        private function previewCodeAvoidsPhpSerialization(): bool
        {
            $paths = [
                BASE_PATH . '/app/Domain/Products/Import/ProductImportPreviewService.php',
                BASE_PATH . '/app/Infrastructure/Import/PrivateProductImportPreviewStore.php',
            ];
            $source = implode('', array_map(static fn (string $path): string => (string) file_get_contents($path), $paths));
            return preg_match('/\b(?:un)?serialize\s*\(/i', $source) === 0;
        }

        /** @return array{all: string, product: string, prices: string, inventory: string} */
        private function snapshot(): array
        {
            $tables = [];
            foreach (['productos', 'producto_precios', 'existencias_producto', 'movimientos_inventario', 'tickets_productos', 'tickets_productos_correos'] as $table) {
                $columns = $this->pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN);
                $order = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
                $tables[$table] = $this->rowsHash('SELECT * FROM ' . $table . ' ORDER BY ' . $order);
            }
            return [
                'all' => hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR)),
                'product' => $this->rowsHash('SELECT * FROM productos WHERE id_producto = :id', ['id' => '102016169']),
                'prices' => $this->rowsHash('SELECT * FROM producto_precios WHERE id_producto = :id ORDER BY id', ['id' => '102016169']),
                'inventory' => $this->rowsHash('SELECT * FROM existencias_producto WHERE id_producto = :id ORDER BY id', ['id' => '102016169']),
            ];
        }

        /** @param array<string, string> $params */
        private function rowsHash(string $sql, array $params = []): string
        {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return hash('sha256', json_encode($statement->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR));
        }

        /** @param array<string, string> $params */
        private function scalar(string $sql, array $params = []): mixed
        {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return $statement->fetchColumn();
        }

        private function removeTree(string $path): void
        {
            if (!is_dir($path)) {
                return;
            }
            $items = scandir($path);
            if ($items === false) {
                return;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $child = $path . DIRECTORY_SEPARATOR . $item;
                is_dir($child) ? $this->removeTree($child) : @unlink($child);
            }
            @rmdir($path);
        }
    };

    return $suite->run();
};
