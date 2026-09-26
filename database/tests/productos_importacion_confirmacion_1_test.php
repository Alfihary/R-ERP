<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Products\Import\ProductImportBusinessValidator;
use App\Domain\Products\Import\ProductImportConfirmationException;
use App\Domain\Products\Import\ProductImportConfirmationService;
use App\Domain\Products\Import\ProductImportFileReader;
use App\Domain\Products\Import\ProductImportPreviewException;
use App\Domain\Products\Import\ProductImportPreviewService;
use App\Domain\Products\Import\ProductImportReadResult;
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
use App\Infrastructure\Repositories\PdoProductImportBusinessLookup;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return static function (PDO $pdo, Config $config, string $expectedDatabase): array {
    $suite = new class($pdo, $config, $expectedDatabase) {
        private const TABLES = [
            'productos',
            'producto_precios',
            'existencias_producto',
            'movimientos_inventario',
            'tickets_productos',
            'tickets_productos_correos',
        ];

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
            $this->temp = BASE_PATH . '/storage/temp/product-import-confirmation-' . bin2hex(random_bytes(8));
            if (!mkdir($this->temp, 0700, true) && !is_dir($this->temp)) {
                throw new RuntimeException('Could not create the isolated confirmation test directory.');
            }

            $before = $this->snapshot();
            try {
                $checks = array_merge(
                    $this->serviceChecks(),
                    $this->databaseChangeChecks(),
                    $this->httpChecks(),
                );
            } finally {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->removeTree($this->temp);
            }
            $after = $this->snapshot();
            foreach (self::TABLES as $table) {
                $checks['integrity_' . $table] = hash_equals($before[$table], $after[$table]);
            }
            $checks['protected_product_unchanged'] = hash_equals($before['protected_product'], $after['protected_product']);
            $checks['protected_prices_unchanged'] = hash_equals($before['protected_prices'], $after['protected_prices']);
            $checks['protected_inventory_unchanged'] = hash_equals($before['protected_inventory'], $after['protected_inventory']);
            $checks['protected_prices_count_two'] = (int) $this->scalar(
                'SELECT COUNT(*) FROM producto_precios WHERE id_producto = :id',
                ['id' => '102016169'],
            ) === 2;
            $checks['test_storage_cleaned'] = !file_exists($this->temp);

            foreach ($checks as $name => $passed) {
                if ($passed !== true) {
                    throw new RuntimeException('Product import confirmation assertion failed: ' . $name . '.');
                }
            }

            return [
                'phase' => 'PRODUCTOS-IMPORTACION-CONFIRMACION-1',
                'status' => 'PASS',
                'checks_total' => count($checks),
                'checks' => $checks,
                'database' => $this->expectedDatabase,
                'confirmation_ttl_seconds' => ProductImportConfirmationService::TTL_SECONDS,
                'single_active_token_policy' => 'replace_previous_unused',
                'db_writes_business' => 0,
                'transactional_test_writes_rolled_back' => true,
                'smtp_used' => false,
                'cleanup' => 'complete',
            ];
        }

        /** @return array<string, bool> */
        private function serviceChecks(): array
        {
            $root = $this->temp . '/service';
            [$previewService, $confirmationService, $store, $reader, $lookup] = $this->bundle($root);
            $binding = hash('sha256', 'confirmation-session-a');
            $valid = $previewService->create(
                $this->upload($this->csv('valid.csv', $this->validCsv('QACONFIRM1')), 'valid.csv'),
                11,
                $binding,
            );
            $readerAfterPreview = $reader->calls;
            $queriesAfterPreview = $lookup->queryCount();
            $confirmation = $confirmationService->confirm($valid['preview_id'], 11, $binding);
            $token = (string) $confirmation['confirmation_token'];
            $tokenPath = $this->confirmationPath($root, $token);
            $tokenMetadata = $this->json($tokenPath);
            $tokenIsPrivateJson = str_starts_with(ltrim((string) file_get_contents($tokenPath)), '{')
                && !str_contains($tokenPath, DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
            $previewNotConsumed = $this->json($this->metadataPath($root, $valid['preview_id']))['used_at'] === null;

            $invalidId = $this->confirmationCode(fn () => $confirmationService->confirm('../bad', 11, $binding));
            $ownership = $this->confirmationCode(fn () => $confirmationService->confirm($valid['preview_id'], 12, $binding));
            $session = $this->confirmationCode(fn () => $confirmationService->confirm(
                $valid['preview_id'],
                11,
                hash('sha256', 'confirmation-session-b'),
            ));

            $expired = $previewService->create(
                $this->upload($this->csv('expired.csv', $this->validCsv('QACONFIRM2')), 'expired.csv'),
                11,
                $binding,
            );
            $this->now += ProductImportPreviewService::TTL_SECONDS + 1;
            $expiredCode = $this->confirmationCode(fn () => $confirmationService->confirm($expired['preview_id'], 11, $binding));
            $this->now = 1700000000;

            $used = $previewService->create(
                $this->upload($this->csv('used.csv', $this->validCsv('QACONFIRM3')), 'used.csv'),
                11,
                $binding,
            );
            $this->mutateJson($this->metadataPath($root, $used['preview_id']), static function (array $metadata): array {
                $metadata['used_at'] = $metadata['created_at'] + 1;
                return $metadata;
            });
            $usedPreviewCode = $this->confirmationCode(fn () => $confirmationService->confirm($used['preview_id'], 11, $binding));

            $missing = $previewService->create(
                $this->upload($this->csv('missing.csv', $this->validCsv('QACONFIRM4')), 'missing.csv'),
                11,
                $binding,
            );
            @unlink($this->uploadPath($root, $missing['preview_id'], 'csv'));
            $missingCode = $this->confirmationCode(fn () => $confirmationService->confirm($missing['preview_id'], 11, $binding));

            $changed = $previewService->create(
                $this->upload($this->csv('changed.csv', $this->validCsv('QACONFIRM5')), 'changed.csv'),
                11,
                $binding,
            );
            file_put_contents($this->uploadPath($root, $changed['preview_id'], 'csv'), "\n", FILE_APPEND);
            $digestCode = $this->confirmationCode(fn () => $confirmationService->confirm($changed['preview_id'], 11, $binding));

            $invalid = $previewService->create(
                $this->upload($this->csv('invalid.csv', $this->validCsv('102016169')), 'invalid.csv'),
                11,
                $binding,
            );
            $invalidCurrent = null;
            $invalidCode = $this->confirmationCode(
                fn () => $confirmationService->confirm($invalid['preview_id'], 11, $binding),
                $invalidCurrent,
            );

            $multiple = $previewService->create(
                $this->upload($this->csv('multiple.csv', $this->validCsv('QACONFIRM6')), 'multiple.csv'),
                11,
                $binding,
            );
            $first = $confirmationService->confirm($multiple['preview_id'], 11, $binding);
            $second = $confirmationService->confirm($multiple['preview_id'], 11, $binding);
            $firstCode = $this->confirmationCode(fn () => $confirmationService->inspect($first['confirmation_token'], 11, $binding));
            $secondInspection = $confirmationService->inspect($second['confirmation_token'], 11, $binding);
            $singleActiveToken = $firstCode === 'confirmation_not_found'
                && $secondInspection['confirmation_token'] === $second['confirmation_token']
                && $this->confirmationCountForPreview($root, $multiple['preview_id']) === 1;

            $expiredTokenPreview = $previewService->create(
                $this->upload($this->csv('token-expired.csv', $this->validCsv('QACONFIRM7')), 'token-expired.csv'),
                11,
                $binding,
            );
            $expiredToken = $confirmationService->confirm($expiredTokenPreview['preview_id'], 11, $binding);
            $this->now += ProductImportConfirmationService::TTL_SECONDS + 1;
            $expiredTokenCode = $this->confirmationCode(
                fn () => $confirmationService->inspect($expiredToken['confirmation_token'], 11, $binding),
            );
            $cleanupConfirmation = $store->cleanupExpired();
            $this->now = 1700000000;

            $usedTokenPreview = $previewService->create(
                $this->upload($this->csv('token-used.csv', $this->validCsv('QACONFIRM8')), 'token-used.csv'),
                11,
                $binding,
            );
            $usedToken = $confirmationService->confirm($usedTokenPreview['preview_id'], 11, $binding);
            $this->mutateJson($this->confirmationPath($root, $usedToken['confirmation_token']), function (array $metadata): array {
                $metadata['used_at'] = $metadata['created_at'] + 1;
                $metadata['metadata_sha256'] = $this->confirmationMetadataDigest($metadata);
                return $metadata;
            });
            $usedTokenCode = $this->confirmationCode(
                fn () => $confirmationService->inspect($usedToken['confirmation_token'], 11, $binding),
            );

            $tamperedPreview = $previewService->create(
                $this->upload($this->csv('tampered.csv', $this->validCsv('QACONFIRM9')), 'tampered.csv'),
                11,
                $binding,
            );
            $tampered = $confirmationService->confirm($tamperedPreview['preview_id'], 11, $binding);
            $this->mutateJson($this->confirmationPath($root, $tampered['confirmation_token']), static function (array $metadata): array {
                $metadata['source_sha256'] = str_repeat('0', 64);
                return $metadata;
            });
            $tamperedCode = $this->confirmationCode(
                fn () => $confirmationService->inspect($tampered['confirmation_token'], 11, $binding),
            );

            $outside = $previewService->create(
                $this->upload($this->csv('outside.csv', $this->validCsv('QACONFIRMA')), 'outside.csv'),
                11,
                $binding,
            );
            $this->mutateJson($this->metadataPath($root, $outside['preview_id']), static function (array $metadata): array {
                $metadata['stored_file'] = '../../outside.csv';
                return $metadata;
            });
            $outsideCode = $this->confirmationCode(fn () => $confirmationService->confirm($outside['preview_id'], 11, $binding));

            $previewTraversal = $this->confirmationCode(fn () => $confirmationService->confirm(str_repeat('a', 63) . '/', 11, $binding));
            $tokenTraversal = $this->confirmationCode(fn () => $confirmationService->inspect('../token', 11, $binding));

            $corruptPreview = $previewService->create(
                $this->upload($this->csv('corrupt-preview.csv', $this->validCsv('QACONFIRMB')), 'corrupt-preview.csv'),
                11,
                $binding,
            );
            file_put_contents($this->metadataPath($root, $corruptPreview['preview_id']), '{broken');
            $cleanupCorrupt = $store->cleanupExpired();

            $cleanupPreview = $previewService->create(
                $this->upload($this->csv('cleanup-preview.csv', $this->validCsv('QACONFIRMC')), 'cleanup-preview.csv'),
                11,
                $binding,
            );
            $this->now += ProductImportPreviewService::TTL_SECONDS + 1;
            $cleanupExpiredPreview = $store->cleanupExpired();
            $this->now = 1700000000;

            return [
                'valid_confirmation' => $confirmation['status'] === 'CONFIRMATION_READY',
                'invalid_preview_id' => $invalidId === 'invalid_preview_id',
                'ownership_rejected' => $ownership === 'preview_not_found',
                'session_mismatch_rejected' => $session === 'preview_session_mismatch',
                'expired_preview_rejected' => $expiredCode === 'preview_expired',
                'used_preview_rejected' => $usedPreviewCode === 'preview_already_used',
                'source_missing_rejected' => $missingCode === 'preview_source_missing',
                'digest_mismatch_rejected' => $digestCode === 'preview_digest_mismatch',
                'reader_rerun' => $readerAfterPreview === 1 && $reader->calls > $readerAfterPreview,
                'validator_rerun' => $lookup->queryCount() > $queriesAfterPreview,
                'invalid_rows_rejected' => $invalidCode === 'confirmation_revalidation_failed'
                    && is_array($invalidCurrent)
                    && ($invalidCurrent['invalid_rows'] ?? 0) > 0,
                'token_random' => preg_match('/^[a-f0-9]{64}$/', $token) === 1
                    && $token !== $valid['preview_id'],
                'token_binding' => $tokenMetadata['preview_id'] === $valid['preview_id']
                    && $tokenMetadata['user_id'] === 11
                    && $tokenMetadata['session_binding'] === $binding
                    && $tokenMetadata['source_sha256'] === $confirmation['source_sha256']
                    && $tokenMetadata['result_sha256'] === $confirmation['result_sha256'],
                'token_ttl_600' => $tokenMetadata['expires_at'] - $tokenMetadata['created_at'] === 600,
                'token_json_private' => $tokenIsPrivateJson,
                'token_expired_rejected' => $expiredTokenCode === 'confirmation_expired',
                'token_used_rejected' => $usedTokenCode === 'confirmation_already_used',
                'single_active_token' => $singleActiveToken,
                'tampered_token_metadata_rejected' => $tamperedCode === 'confirmation_metadata_corrupt',
                'preview_traversal_rejected' => $previewTraversal === 'invalid_preview_id',
                'token_traversal_rejected' => $tokenTraversal === 'invalid_confirmation_token',
                'outside_path_rejected' => $outsideCode === 'preview_metadata_corrupt',
                'cleanup_confirmation' => $cleanupConfirmation >= 1
                    && !is_file($this->confirmationPath($root, $expiredToken['confirmation_token'])),
                'cleanup_corrupt_preview' => $cleanupCorrupt >= 1
                    && !is_file($this->metadataPath($root, $corruptPreview['preview_id'])),
                'cleanup_expired_preview' => $cleanupExpiredPreview >= 1
                    && !is_file($this->metadataPath($root, $cleanupPreview['preview_id']))
                    && !is_file($this->uploadPath($root, $cleanupPreview['preview_id'], 'csv')),
                'preview_not_consumed' => $previewNotConsumed,
            ];
        }

        /** @return array<string, bool> */
        private function databaseChangeChecks(): array
        {
            $root = $this->temp . '/database-change';
            [$previews, $confirmations] = $this->bundle($root);
            $binding = hash('sha256', 'database-change-session');
            $productId = 'QACONFDB1';
            $productPreview = $previews->create(
                $this->upload($this->csv('db-product.csv', $this->validCsv($productId)), 'db-product.csv'),
                21,
                $binding,
            );

            $unitId = (int) $this->scalar(
                "SELECT id FROM unidades_medida WHERE codigo = 'PIEZA' AND activo = 1 AND eliminado_en IS NULL",
            );
            $typeId = (int) $this->scalar(
                "SELECT id FROM tipos_producto WHERE codigo = 'PRODUCTO' AND activo = 1 AND eliminado_en IS NULL",
            );
            if ($unitId < 1 || $typeId < 1) {
                throw new RuntimeException('Product import confirmation DB fixtures are unavailable.');
            }

            $this->pdo->beginTransaction();
            try {
                $statement = $this->pdo->prepare(
                    'INSERT INTO productos (id_producto, descripcion, unidad_medida_id, tipo_producto_id)'
                    . ' VALUES (:id, :description, :unit, :type)',
                );
                $statement->execute([
                    'id' => $productId,
                    'description' => 'Producto QA confirmacion',
                    'unit' => $unitId,
                    'type' => $typeId,
                ]);
                $productCurrent = null;
                $productCode = $this->confirmationCode(
                    fn () => $confirmations->confirm($productPreview['preview_id'], 21, $binding),
                    $productCurrent,
                );
            } finally {
                $this->pdo->rollBack();
            }

            $catalogPreview = $previews->create(
                $this->upload($this->csv('db-catalog.csv', $this->validCsv('QACONFDB2')), 'db-catalog.csv'),
                21,
                $binding,
            );
            $this->pdo->beginTransaction();
            try {
                $statement = $this->pdo->prepare('UPDATE unidades_medida SET activo = 0 WHERE id = :id');
                $statement->execute(['id' => $unitId]);
                $catalogCurrent = null;
                $catalogCode = $this->confirmationCode(
                    fn () => $confirmations->confirm($catalogPreview['preview_id'], 21, $binding),
                    $catalogCurrent,
                );
            } finally {
                $this->pdo->rollBack();
            }

            return [
                'db_change_detected' => $productCode === 'confirmation_revalidation_failed'
                    && $this->previewHasError($productCurrent, 'product_already_exists'),
                'db_change_rolled_back' => (int) $this->scalar(
                    'SELECT COUNT(*) FROM productos WHERE id_producto = :id',
                    ['id' => $productId],
                ) === 0,
                'catalog_change_detected' => $catalogCode === 'confirmation_revalidation_failed'
                    && $this->previewHasError($catalogCurrent, 'catalog_value_not_found'),
                'catalog_change_rolled_back' => (int) $this->scalar(
                    'SELECT activo FROM unidades_medida WHERE id = :id',
                    ['id' => $unitId],
                ) === 1,
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
                throw new RuntimeException('Could not isolate HTTP session storage.');
            }
            $session = new Session([
                'name' => 'confirmation_test_' . bin2hex(random_bytes(4)),
                'same_site' => 'Lax',
                'secure' => false,
                'gc_max_lifetime' => 7200,
            ]);
            $session->start();
            $csrf = new CsrfTokenService($session);
            $auth = new AuthService(new UserRepository($provider), $session);
            $permissions = new PermissionService(new PermissionRepository($provider));
            $scope = new ScopeContextService(new UserScopeService(new ScopeRepository($provider)), $session);
            $root = $this->temp . '/http';
            [$previews, $confirmations] = $this->bundle($root);
            $controller = new ProductImportController(
                $this->config,
                $auth,
                $permissions,
                $scope,
                $csrf,
                $previews,
                $confirmations,
            );
            $router = new Router();
            $router->middleware(new CsrfMiddleware($csrf));
            $middleware = [
                new AuthMiddleware($auth),
                new PermissionMiddleware($auth, $permissions, 'productos.acceder'),
                new PermissionMiddleware($auth, $permissions, 'productos.crear'),
            ];
            $router->get('/productos/importar', fn (Request $request) => $controller->index($request), $middleware);
            $router->post('/productos/importar/confirmar', fn (Request $request) => $controller->confirm($request), $middleware);

            try {
                $guestToken = $csrf->token();
                $guest = $router->dispatch(new Request(
                    'POST',
                    '/productos/importar/confirmar',
                    [],
                    ['_token' => $guestToken, 'preview_id' => str_repeat('a', 64)],
                ));
                $user = $this->authorizedUser();
                $session->put('auth_user', $user);
                $authorizedGet = $router->dispatch(new Request('GET', '/productos/importar'));
                $token = $guestToken;
                $binding = hash('sha256', session_id());
                $valid = $previews->create(
                    $this->upload($this->csv('http-valid.csv', $this->validCsv('QACONFHTTP1')), 'http-valid.csv'),
                    $user['user_id'],
                    $binding,
                );
                $noCsrf = $router->dispatch(new Request(
                    'POST',
                    '/productos/importar/confirmar',
                    [],
                    ['preview_id' => $valid['preview_id']],
                ));

                $session->put('auth_user', ['user_id' => 2147483647, 'username' => 'qa-denied', 'email' => 'qa-denied@example.invalid']);
                $denied = $router->dispatch(new Request(
                    'POST',
                    '/productos/importar/confirmar',
                    [],
                    ['_token' => $token, 'preview_id' => $valid['preview_id']],
                ));

                $session->put('auth_user', $user);
                $validResponse = $router->dispatch(new Request(
                    'POST',
                    '/productos/importar/confirmar',
                    [],
                    ['_token' => $token, 'preview_id' => $valid['preview_id']],
                ));
                $foreign = $previews->create(
                    $this->upload($this->csv('http-foreign.csv', $this->validCsv('QACONFHTTP2')), 'http-foreign.csv'),
                    $user['user_id'] + 100000,
                    $binding,
                );
                $foreignResponse = $router->dispatch(new Request(
                    'POST',
                    '/productos/importar/confirmar',
                    [],
                    ['_token' => $token, 'preview_id' => $foreign['preview_id']],
                ));
                $invalid = $previews->create(
                    $this->upload($this->csv('http-invalid.csv', $this->validCsv('102016169')), 'http-invalid.csv'),
                    $user['user_id'],
                    $binding,
                );
                $invalidResponse = $router->dispatch(new Request(
                    'POST',
                    '/productos/importar/confirmar',
                    [],
                    ['_token' => $token, 'preview_id' => $invalid['preview_id']],
                ));
                $expired = $previews->create(
                    $this->upload($this->csv('http-expired.csv', $this->validCsv('QACONFHTTP3')), 'http-expired.csv'),
                    $user['user_id'],
                    $binding,
                );
                $this->now += ProductImportPreviewService::TTL_SECONDS + 1;
                $expiredResponse = $router->dispatch(new Request(
                    'POST',
                    '/productos/importar/confirmar',
                    [],
                    ['_token' => $token, 'preview_id' => $expired['preview_id']],
                ));
                $this->now = 1700000000;

                return [
                    'http_guest_redirect' => $guest->status() === 302,
                    'http_get_preview_regression' => $authorizedGet->status() === 200
                        && str_contains($authorizedGet->body(), 'Importar productos'),
                    'http_without_csrf' => $noCsrf->status() === 419,
                    'http_without_permission' => $denied->status() === 403,
                    'http_foreign_preview_safe' => in_array($foreignResponse->status(), [403, 404], true),
                    'http_expired_preview_safe' => $expiredResponse->status() === 410
                        && str_contains($expiredResponse->body(), 'preview_expired'),
                    'http_valid_confirmation_ready' => $validResponse->status() === 200
                        && str_contains($validResponse->body(), 'CONFIRMATION_READY')
                        && str_contains($validResponse->body(), 'Archivo revalidado correctamente')
                        && !str_contains($validResponse->body(), (string) ($this->newestConfirmationToken($root) ?? 'never-match')),
                    'http_invalid_preview_not_ready' => $invalidResponse->status() === 409
                        && !str_contains($invalidResponse->body(), 'CONFIRMATION_READY')
                        && str_contains($invalidResponse->body(), 'Los datos cambiaron desde la validación inicial')
                        && str_contains($invalidResponse->body(), 'product_already_exists'),
                ];
            } finally {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $session->invalidate();
                }
            }
        }

        /** @return array{ProductImportPreviewService, ProductImportConfirmationService, PrivateProductImportPreviewStore, object, PdoProductImportBusinessLookup} */
        private function bundle(string $root): array
        {
            $reader = new class implements ProductImportFileReader {
                public int $calls = 0;
                private ProductImportReader $delegate;
                public function __construct() { $this->delegate = new ProductImportReader(); }
                public function read(string $path): ProductImportReadResult
                {
                    ++$this->calls;
                    return $this->delegate->read($path);
                }
            };
            $lookup = new PdoProductImportBusinessLookup($this->pdo);
            $validator = new ProductImportBusinessValidator($lookup);
            $store = new PrivateProductImportPreviewStore($root, true, fn (): int => $this->now);
            return [
                new ProductImportPreviewService($reader, $validator, $store),
                new ProductImportConfirmationService($reader, $validator, $store),
                $store,
                $reader,
                $lookup,
            ];
        }

        /** @param callable(): mixed $operation */
        private function confirmationCode(callable $operation, ?array &$currentPreview = null): string
        {
            try {
                $operation();
            } catch (ProductImportConfirmationException $exception) {
                $currentPreview = $exception->currentPreview;
                return $exception->errorCode;
            }
            return '';
        }

        /** @param array<string, mixed>|null $preview */
        private function previewHasError(?array $preview, string $code): bool
        {
            if (!is_array($preview)) {
                return false;
            }
            foreach (array_merge($preview['global_errors'] ?? [], $preview['row_errors'] ?? []) as $error) {
                if (is_array($error) && ($error['code'] ?? null) === $code) {
                    return true;
                }
            }
            return false;
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
                 ORDER BY u.id LIMIT 1",
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
                . $productId . ",Producto confirmacion,PRODUCTO,PIEZA\n";
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
            return [
                'name' => $name,
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($path),
                'type' => 'text/csv',
            ];
        }

        private function storageRoot(string $root): string
        {
            return rtrim($root, '/\\') . '/private/product_import_previews';
        }

        private function metadataPath(string $root, string $previewId): string
        {
            return $this->storageRoot($root) . '/metadata/' . $previewId . '.json';
        }

        private function uploadPath(string $root, string $previewId, string $extension): string
        {
            return $this->storageRoot($root) . '/uploads/' . $previewId . '.' . $extension;
        }

        private function confirmationPath(string $root, string $token): string
        {
            return $this->storageRoot($root) . '/confirmations/' . $token . '.json';
        }

        /** @return array<string, mixed> */
        private function json(string $path): array
        {
            $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('Expected JSON object.');
            }
            return $decoded;
        }

        /** @param callable(array<string, mixed>): array<string, mixed> $callback */
        private function mutateJson(string $path, callable $callback): void
        {
            $value = $callback($this->json($path));
            file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        private function confirmationCountForPreview(string $root, string $previewId): int
        {
            $count = 0;
            foreach (glob($this->storageRoot($root) . '/confirmations/*.json') ?: [] as $path) {
                try {
                    if (($this->json($path)['preview_id'] ?? null) === $previewId) {
                        ++$count;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
            return $count;
        }

        private function newestConfirmationToken(string $root): ?string
        {
            $files = glob($this->storageRoot($root) . '/confirmations/*.json') ?: [];
            if ($files === []) {
                return null;
            }
            usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
            return pathinfo($files[0], PATHINFO_FILENAME);
        }

        /** @param array<string, mixed> $metadata */
        private function confirmationMetadataDigest(array $metadata): string
        {
            unset($metadata['metadata_sha256']);
            return hash('sha256', json_encode(
                $this->canonicalize($metadata),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            ));
        }

        private function canonicalize(mixed $value): mixed
        {
            if (!is_array($value)) {
                return $value;
            }
            if (array_is_list($value)) {
                return array_map([$this, 'canonicalize'], $value);
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $this->canonicalize($item);
            }
            return $value;
        }

        /** @return array<string, string> */
        private function snapshot(): array
        {
            $snapshot = [];
            foreach (self::TABLES as $table) {
                $rows = $this->pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
                $encodedRows = array_map(
                    static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    $rows,
                );
                sort($encodedRows, SORT_STRING);
                $snapshot[$table] = hash('sha256', implode("\n", $encodedRows));
            }
            $snapshot['protected_product'] = $this->rowHash(
                'SELECT * FROM productos WHERE id_producto = :id',
                ['id' => '102016169'],
            );
            $snapshot['protected_prices'] = $this->rowsHash(
                'SELECT * FROM producto_precios WHERE id_producto = :id',
                ['id' => '102016169'],
            );
            $snapshot['protected_inventory'] = $this->rowsHash(
                'SELECT * FROM existencias_producto WHERE id_producto = :id',
                ['id' => '102016169'],
            );
            return $snapshot;
        }

        /** @param array<string, mixed> $params */
        private function scalar(string $sql, array $params = []): mixed
        {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return $statement->fetchColumn();
        }

        /** @param array<string, mixed> $params */
        private function rowHash(string $sql, array $params): string
        {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return hash('sha256', json_encode($statement->fetch(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        }

        /** @param array<string, mixed> $params */
        private function rowsHash(string $sql, array $params): string
        {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $rows = array_map(
                static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                $statement->fetchAll(PDO::FETCH_ASSOC),
            );
            sort($rows, SORT_STRING);
            return hash('sha256', implode("\n", $rows));
        }

        private function removeTree(string $path): void
        {
            if (!is_dir($path)) {
                return;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
            @rmdir($path);
        }
    };

    return $suite->run();
};
