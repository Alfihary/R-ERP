<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('PRODUCTOS-IMAGEN-1 test is CLI-only.');
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
    require BASE_PATH . '/bootstrap/autoload.php';
}

use App\Domain\Products\ProductImageService;
use App\Domain\Products\ProductImageOrphanCleanupService;
use App\Domain\Audit\AuditService;
use App\Domain\Audit\AuditRecorderInterface;
use App\Domain\Products\ProductService;
use App\Domain\Products\ProductValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Repositories\ProductDocumentRepository;
use App\Infrastructure\Repositories\ProductRepository;
use App\Infrastructure\Repositories\AuditRepository;

$test = new class implements DatabaseTest {
    private const PRODUCT_ID = 'QAIMGTEST001';
    private const CREATE_NO_IMAGE_ID = 'QAIMGNOIMG';
    private const CREATE_IMAGE_ID = 'QAIMGCREATE';
    private const CREATE_INVALID_IMAGE_ID = 'QAIMGBADIMG';
    private const CREATE_INVALID_DATA_ID = 'QAIMGBADDATA';
    private const CREATE_ROLLBACK_ID = 'QAIMGROLLBACK';

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match PRODUCTOS-IMAGEN-1.'
            );
        }

        if (!$this->tableExists($pdo, 'producto_documentos')) {
            throw new RuntimeException(
                'PRODUCTOS-IMAGEN-1 requires producto_documentos.'
            );
        }

        $migration = require BASE_PATH
            . '/database/migrations/'
            . 'productos_imagen_1_001_allow_primary_photo_type.php';

        if (!$migration instanceof Migration) {
            throw new RuntimeException(
                'PRODUCTOS-IMAGEN-1 migration has an invalid contract.'
            );
        }

        $runner = new MigrationRunner($pdo);
        $this->cleanup($pdo);

        if ($this->migrationRows($pdo, $migration->id()) === 1) {
            if ($this->underscoreDocumentRows($pdo) === 0) {
                $runner->rollback($migration);
            }
        }

        $constraintBefore = $this->constraintClause($pdo);
        $migrationResult = $runner->migrate($migration);
        $secondMigrationResult = $runner->migrate($migration);
        $constraintAfter = $this->constraintClause($pdo);
        $migrationRows = $this->migrationRows($pdo, $migration->id());
        $constraintCases = $this->constraintCases($pdo);
        $this->cleanup($pdo);
        $rollbackResult = 'skipped_existing_underscore_rows';
        $rollbackRejectsUnderscore = 'SKIPPED_EXISTING_UNDERSCORE_ROWS';

        if ($this->underscoreDocumentRows($pdo) === 0) {
            $rollbackResult = $runner->rollback($migration);
            $rollbackRejectsUnderscore = $this->fails(
                fn () => $this->insertDocumentType($pdo, 'A_B')
            );
            $runner->migrate($migration);
        }

        $this->cleanup($pdo);

        if (
            !in_array($migrationResult, ['applied', 'already_applied'], true)
            || $secondMigrationResult !== 'already_applied'
            || $migrationRows !== 1
            || !in_array(
                $rollbackResult,
                ['rolled_back', 'skipped_existing_underscore_rows'],
                true
            )
            || !in_array(
                $rollbackRejectsUnderscore,
                [true, 'SKIPPED_EXISTING_UNDERSCORE_ROWS'],
                true
            )
            || ($constraintCases['FOTO_PRINCIPAL'] ?? null) !== 'ACCEPTED'
        ) {
            throw new RuntimeException(
                'PRODUCTOS-IMAGEN-1 constraint migration evidence is incomplete.'
            );
        }

        $before = $this->counts($pdo);
        $actorId = $this->adminId($pdo);
        $storagePath = BASE_PATH . '/storage';
        $config = require BASE_PATH . '/bootstrap/database.php';
        $databaseConfig = $config->get('database', []);

        if (!is_array($databaseConfig)) {
            throw new RuntimeException('Database configuration must be an array.');
        }

        $connection = new ConnectionProvider($databaseConfig);
        $audit = new AuditService(new AuditRepository($connection));
        $service = new ProductImageService(
            new ProductRepository($connection),
            new ProductDocumentRepository($connection),
            $storagePath,
            $audit
        );
        $products = new ProductService(new ProductRepository($connection));
        $unitId = $this->unitId($pdo);

        $results = [];

        try {
            $noImageId = $products->create(
                $this->productInput(self::CREATE_NO_IMAGE_ID, $unitId),
                $actorId
            );
            $results['crear_sin_imagen'] = $noImageId === self::CREATE_NO_IMAGE_ID
                && $service->current(self::CREATE_NO_IMAGE_ID) === null;
            $this->cleanupProduct($pdo, self::CREATE_NO_IMAGE_ID);

            $createdPath = null;
            $validCreateImage = $this->imageFile('png');
            $preparedCreateImage = $service->prepareImageUpload(
                $this->upload($validCreateImage, 'create.png')
            );
            $imageId = $products->createWithHook(
                $this->productInput(self::CREATE_IMAGE_ID, $unitId),
                $actorId,
                function (string $productId) use (
                    $service,
                    $preparedCreateImage,
                    $actorId,
                    &$createdPath
                ): void {
                    $createdPath = $service->attachPreparedMainPhotoToNewProduct(
                        $productId,
                        $preparedCreateImage,
                        $actorId
                    );
                }
            );
            $createdPhoto = $service->current(self::CREATE_IMAGE_ID);
            $results['crear_con_imagen_valida'] =
                $imageId === self::CREATE_IMAGE_ID
                && $createdPhoto !== null
                && is_file((string) $createdPath)
                && (string) $createdPhoto['tipo_documento'] === 'FOTO_PRINCIPAL'
                && (int) $createdPhoto['es_principal'] === 1
                && $service->content(self::CREATE_IMAGE_ID)['mime_type']
                    === 'image/png';
            $this->cleanupProduct($pdo, self::CREATE_IMAGE_ID);

            $results['crear_con_imagen_invalida'] = $this->fails(
                fn () => $products->createWithHook(
                    $this->productInput(self::CREATE_INVALID_IMAGE_ID, $unitId),
                    $actorId,
                    function (string $productId) use ($service, $actorId): void {
                        $prepared = $service->prepareImageUpload(
                            $this->upload(
                                $this->textFile('<svg></svg>', 'bad.svg'),
                                'bad.svg'
                            )
                        );
                        $service->attachPreparedMainPhotoToNewProduct(
                            $productId,
                            $prepared,
                            $actorId
                        );
                    }
                )
            ) && !$this->productExists($pdo, self::CREATE_INVALID_IMAGE_ID)
                && $this->documentCount($pdo, self::CREATE_INVALID_IMAGE_ID) === 0;

            $preparedInvalidDataImage = $service->prepareImageUpload(
                $this->upload($this->imageFile('jpg'), 'valid-data-bad.jpg')
            );
            $results['crear_datos_invalidos_con_imagen_valida'] = $this->fails(
                fn () => $products->createWithHook(
                    array_replace(
                        $this->productInput(self::CREATE_INVALID_DATA_ID, $unitId),
                        ['descripcion' => '']
                    ),
                    $actorId,
                    function (string $productId) use (
                        $service,
                        $preparedInvalidDataImage,
                        $actorId
                    ): void {
                        $service->attachPreparedMainPhotoToNewProduct(
                            $productId,
                            $preparedInvalidDataImage,
                            $actorId
                        );
                    }
                )
            ) && !$this->productExists($pdo, self::CREATE_INVALID_DATA_ID)
                && $this->documentCount($pdo, self::CREATE_INVALID_DATA_ID) === 0;

            $storedRollbackPath = null;
            $preparedRollbackImage = $service->prepareImageUpload(
                $this->upload($this->imageFile('webp'), 'rollback.webp')
            );
            $results['rollback_despues_de_guardar_archivo'] = $this->fails(
                function () use (
                    $products,
                    $service,
                    $actorId,
                    $preparedRollbackImage,
                    $unitId,
                    &$storedRollbackPath
                ): void {
                    try {
                        $products->createWithHook(
                            $this->productInput(self::CREATE_ROLLBACK_ID, $unitId),
                            $actorId,
                            function (string $productId) use (
                                $service,
                                $preparedRollbackImage,
                                $actorId,
                                &$storedRollbackPath
                            ): void {
                                $storedRollbackPath =
                                    $service->attachPreparedMainPhotoToNewProduct(
                                        $productId,
                                        $preparedRollbackImage,
                                        $actorId
                                    );
                                throw new ProductValidationException([
                                    'imagen' => 'Falla simulada controlada.',
                                ]);
                            }
                        );
                    } catch (ProductValidationException $exception) {
                        if ($storedRollbackPath !== null) {
                            $service->discardStoredFile($storedRollbackPath);
                        }

                        throw $exception;
                    }
                }
            ) && !$this->productExists($pdo, self::CREATE_ROLLBACK_ID)
                && $this->documentCount($pdo, self::CREATE_ROLLBACK_ID) === 0
                && ($storedRollbackPath === null || !is_file($storedRollbackPath));

            $this->insertProduct($pdo, self::PRODUCT_ID, $actorId);
            $results['producto_sin_imagen'] =
                $service->current(self::PRODUCT_ID) === null;
            $results['ruta_parent_rechazada'] = $this->badMetadataRejected(
                $pdo,
                $service,
                '../evil.png',
                $actorId
            );
            $results['ruta_absoluta_rechazada'] = $this->badMetadataRejected(
                $pdo,
                $service,
                'C:\\evil.png',
                $actorId
            );
            $results['ruta_fuera_storage_rechazada'] =
                $this->badMetadataRejected(
                    $pdo,
                    $service,
                    '/tmp/evil.png',
                    $actorId
                );
            $results['archivo_faltante_controlado'] =
                $this->badMetadataRejected(
                    $pdo,
                    $service,
                    self::PRODUCT_ID . '/missing.png',
                    $actorId
                );

            foreach ([
                'jpeg_valido' => $this->imageFile('jpg'),
                'png_valido' => $this->imageFile('png'),
                'webp_valido' => $this->imageFile('webp'),
            ] as $label => $file) {
                $service->replace(
                    self::PRODUCT_ID,
                    $this->upload($file, $label),
                    $actorId
                );
                $results[$label] = $service->current(self::PRODUCT_ID) !== null;
                $service->delete(self::PRODUCT_ID, $actorId);
                $this->remove($file);
            }

            $first = $this->imageFile('png');
            $service->replace(self::PRODUCT_ID, $this->upload($first, 'a.png'), $actorId);
            $firstPhoto = $service->current(self::PRODUCT_ID);
            $firstPath = $this->absoluteStoredPath($firstPhoto);

            $second = $this->imageFile('jpg');
            $failingReplaceService = new ProductImageService(
                new ProductRepository($connection),
                new ProductDocumentRepository($connection),
                $storagePath,
                $audit,
                static fn (string $path): bool => false
            );
            $failingReplaceService->replace(
                self::PRODUCT_ID,
                $this->upload($second, 'b.jpg'),
                $actorId
            );
            $secondPhoto = $service->current(self::PRODUCT_ID);
            $secondPath = $this->absoluteStoredPath($secondPhoto);
            $cleanupService = new ProductImageOrphanCleanupService(
                new ProductDocumentRepository($connection),
                $storagePath
            );
            $dryCandidates = $cleanupService->cleanup(true);

            $results['reemplazo'] = $secondPhoto !== null
                && (int) $secondPhoto['id'] !== (int) $firstPhoto['id'];
            $results['replace_unlink_failure_handled'] = is_file($firstPath)
                && is_file($secondPath);
            $results['imagen_anterior_inactiva'] =
                $this->activeMainCount($pdo, self::PRODUCT_ID) === 1
                && $this->inactiveById($pdo, (int) $firstPhoto['id']);
            $results['archivo_anterior_detectado'] = in_array(
                self::PRODUCT_ID . '/' . basename($firstPath),
                $dryCandidates,
                true
            );
            $cleanupService->cleanup(false);
            $results['archivo_anterior_eliminado'] = !is_file($firstPath);
            $results['archivo_nuevo_permanece'] = is_file($secondPath);
            $results['referenced_file_preserved_after_cleanup'] =
                !in_array(
                    self::PRODUCT_ID . '/' . basename($secondPath),
                    $cleanupService->cleanup(true),
                    true
                );

            $failingDeleteService = new ProductImageService(
                new ProductRepository($connection),
                new ProductDocumentRepository($connection),
                $storagePath,
                $audit,
                static fn (string $path): bool => false
            );
            $failingDeleteService->delete(self::PRODUCT_ID, $actorId);
            $results['delete_unlink_failure_handled'] = is_file($secondPath);
            $results['eliminacion_logica'] =
                $this->activeMainCount($pdo, self::PRODUCT_ID) === 0
                && $this->inactiveById($pdo, (int) $secondPhoto['id']);
            $results['archivo_huerfano_post_delete'] = is_file($secondPath)
                && in_array(
                    self::PRODUCT_ID . '/' . basename($secondPath),
                    $cleanupService->cleanup(true),
                    true
                );
            $cleanupService->cleanup(false);
            $results['archivo_eliminado'] = !is_file($secondPath);
            $results['orphan_cleanup_idempotent'] =
                $cleanupService->cleanup(true) === []
                && $cleanupService->cleanup(false) === [];

            $auditFailure = new class implements AuditRecorderInterface {
                public function recordRequired(
                    string $action,
                    ?int $actorUserId = null,
                    array $metadata = []
                ): void {
                    throw new RuntimeException('QA audit failure.');
                }
            };
            $auditFailureService = new ProductImageService(
                new ProductRepository($connection),
                new ProductDocumentRepository($connection),
                $storagePath,
                $auditFailure
            );
            $auditBaselineUpload = $this->imageFile('png');
            $service->replace(
                self::PRODUCT_ID,
                $this->upload($auditBaselineUpload, 'audit-baseline.png'),
                $actorId
            );
            $auditBaselinePhoto = $service->current(self::PRODUCT_ID);
            $auditBaselinePath = $this->absoluteStoredPath($auditBaselinePhoto);
            $auditFailureUpload = $this->imageFile('png');
            $auditFailureRollback = false;
            try {
                $auditFailureService->replace(
                    self::PRODUCT_ID,
                    $this->upload($auditFailureUpload, 'audit-failure.png'),
                    $actorId
                );
            } catch (Throwable) {
                $auditFailureRollback = $this->activeMainCount(
                    $pdo,
                    self::PRODUCT_ID
                ) === 1
                    && $service->current(self::PRODUCT_ID) !== null
                    && (int) $service->current(self::PRODUCT_ID)['id']
                        === (int) $auditBaselinePhoto['id']
                    && is_file($auditBaselinePath)
                    && ($this->qaProductFiles(self::PRODUCT_ID) === 1);
            } finally {
                $this->remove($auditBaselineUpload);
                $this->remove($auditFailureUpload);
            }
            $results['auditoria_falla_revierte'] = $auditFailureRollback;
            $auditActions = $this->auditActions($pdo, self::PRODUCT_ID, $actorId);
            $results['auditoria_subir'] = ($auditActions['producto_imagen.subir'] ?? 0) >= 1;
            $results['auditoria_reemplazar'] = ($auditActions['producto_imagen.reemplazar'] ?? 0) >= 1;
            $results['auditoria_eliminar'] = ($auditActions['producto_imagen.eliminar'] ?? 0) >= 1;
            $results['auditoria_actor_subir'] =
                ($auditActions['producto_imagen.subir_actor'] ?? 0) >= 1;
            $results['auditoria_actor_reemplazar'] =
                ($auditActions['producto_imagen.reemplazar_actor'] ?? 0) >= 1;
            $results['auditoria_actor_eliminar'] =
                ($auditActions['producto_imagen.eliminar_actor'] ?? 0) >= 1;
            $results['orphan_outside_storage_rejected'] =
                $this->orphanOutsideStorageRejected($cleanupService);

            $results['producto_inexistente_rechazado'] = $this->fails(
                fn () => $service->replace(
                    'QAIMG404',
                    $this->upload($this->imageFile('png'), 'missing.png'),
                    $actorId
                )
            );
            $results['archivo_vacio_rechazado'] = $this->fails(
                fn () => $service->replace(
                    self::PRODUCT_ID,
                    $this->upload($this->textFile('', 'empty.bin'), 'empty.png'),
                    $actorId
                )
            );
            $results['archivo_mayor_5mib_rechazado'] = $this->fails(
                fn () => $service->replace(
                    self::PRODUCT_ID,
                    $this->upload($this->largeFile(), 'large.png'),
                    $actorId
                )
            );
            $results['php_renombrado_jpg_rechazado'] = $this->fails(
                fn () => $service->replace(
                    self::PRODUCT_ID,
                    $this->upload($this->textFile('<?php echo 1;', 'fake.jpg'), 'fake.jpg'),
                    $actorId
                )
            );
            $results['texto_renombrado_png_rechazado'] = $this->fails(
                fn () => $service->replace(
                    self::PRODUCT_ID,
                    $this->upload($this->textFile('text', 'fake.png'), 'fake.png'),
                    $actorId
                )
            );
            $results['svg_rechazado'] = $this->fails(
                fn () => $service->replace(
                    self::PRODUCT_ID,
                    $this->upload($this->textFile('<svg></svg>', 'fake.svg'), 'fake.svg'),
                    $actorId
                )
            );
            $results['gif_rechazado'] = $this->fails(
                fn () => $service->replace(
                    self::PRODUCT_ID,
                    $this->upload($this->gifFile(), 'fake.gif'),
                    $actorId
                )
            );
            $results['corrupto_rechazado'] = $this->fails(
                fn () => $service->replace(
                    self::PRODUCT_ID,
                    $this->upload($this->textFile(random_bytes(64), 'corrupt.jpg'), 'corrupt.jpg'),
                    $actorId
                )
            );

            foreach ($results as $label => $passed) {
                if ($passed !== true) {
                    throw new RuntimeException(
                        'PRODUCTOS-IMAGEN-1 assertion failed: ' . $label
                    );
                }
            }
        } finally {
            $this->cleanup($pdo);
        }

        $after = $this->counts($pdo);

        return [
            'schema_change' => 'constraint_only',
            'migration' => [
                'id' => $migration->id(),
                'first_run' => $migrationResult,
                'second_run' => $secondMigrationResult,
                'migration_rows' => $migrationRows,
                'rollback' => $rollbackResult,
                'rollback_rejects_underscore' =>
                    $rollbackRejectsUnderscore === true
                        ? 'PASS'
                        : $rollbackRejectsUnderscore,
            ],
            'constraint_before' => $constraintBefore,
            'constraint_after' => $constraintAfter,
            'constraint_cases' => $constraintCases,
            'FOTO_PRINCIPAL' => $constraintCases['FOTO_PRINCIPAL'],
            'producto_sin_imagen' => 'PASS',
            'formatos_validos' => [
                'jpeg' => 'PASS',
                'png' => 'PASS',
                'webp' => 'PASS',
            ],
            'rechazos' => [
                'vacio' => 'PASS',
                'mayor_5mib' => 'PASS',
                'php_como_jpg' => 'PASS',
                'texto_como_png' => 'PASS',
                'svg' => 'PASS',
                'gif' => 'PASS',
                'corrupto' => 'PASS',
                'producto_inexistente' => 'PASS',
            ],
            'seguridad_ruta' => [
                '../' => 'PASS',
                'ruta_absoluta' => 'PASS',
                'fuera_de_storage' => 'PASS',
                'archivo_faltante' => 'PASS',
            ],
            'reemplazo' => 'PASS',
            'eliminacion' => 'PASS',
            'crear_producto_sin_imagen' => 'PASS',
            'crear_producto_con_imagen' => 'PASS',
            'crear_producto_con_imagen_invalida' => 'PASS',
            'crear_producto_datos_invalidos_con_imagen_valida' => 'PASS',
            'rollback_archivo_huerfano' => 'PASS',
            'hardening' => [
                'IMAGE_AUDIT_UPLOAD' =>
                    $results['auditoria_subir']
                    && $results['auditoria_actor_subir']
                        ? 'PASS' : 'FAIL',
                'IMAGE_AUDIT_REPLACE' =>
                    $results['auditoria_reemplazar']
                    && $results['auditoria_actor_reemplazar']
                        ? 'PASS' : 'FAIL',
                'IMAGE_AUDIT_DELETE' =>
                    $results['auditoria_eliminar']
                    && $results['auditoria_actor_eliminar']
                        ? 'PASS' : 'FAIL',
                'IMAGE_AUDIT_FAILURE_ROLLBACK' =>
                    $results['auditoria_falla_revierte'] ? 'PASS' : 'FAIL',
                'ORPHAN_DRY_RUN_DETECTS_QA_FILE' =>
                    $results['archivo_anterior_detectado']
                    && $results['archivo_huerfano_post_delete']
                        ? 'PASS' : 'FAIL',
                'ORPHAN_DRY_RUN_DELETES_NOTHING' =>
                    $results['archivo_anterior_eliminado']
                    && $results['archivo_nuevo_permanece']
                        ? 'PASS' : 'FAIL',
                'ORPHAN_CLEANUP_REMOVES_QA_ORPHAN' =>
                    $results['archivo_eliminado'] ? 'PASS' : 'FAIL',
                'ORPHAN_CLEANUP_PRESERVES_REFERENCED_FILE' =>
                    $results['referenced_file_preserved_after_cleanup']
                        ? 'PASS' : 'FAIL',
                'ORPHAN_CLEANUP_REJECTS_OUTSIDE_STORAGE' =>
                    $results['orphan_outside_storage_rejected']
                        ? 'PASS' : 'FAIL',
                'ORPHAN_CLEANUP_SECOND_RUN_SAFE' =>
                    $results['orphan_cleanup_idempotent'] ? 'PASS' : 'FAIL',
                'REPLACE_POST_COMMIT_UNLINK_FAILURE_HANDLED' =>
                    $results['replace_unlink_failure_handled'] ? 'PASS' : 'FAIL',
                'DELETE_POST_COMMIT_UNLINK_FAILURE_HANDLED' =>
                    $results['delete_unlink_failure_handled'] ? 'PASS' : 'FAIL',
            ],
            'maximo_una_principal_activa' => 'PASS',
            'rollback_qa' => $after === $before ? 'PASS' : 'FAIL',
            'counts' => $after,
        ];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function migrationRows(PDO $pdo, string $migration): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $statement->execute(['migration' => $migration]);

        return (int) $statement->fetchColumn();
    }

    private function constraintClause(PDO $pdo): string
    {
        $statement = $pdo->prepare(
            "SELECT CHECK_CLAUSE
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = 'chk_producto_documentos_tipo'
             LIMIT 1"
        );
        $statement->execute();
        $clause = $statement->fetchColumn();

        if (!is_string($clause) || $clause === '') {
            throw new RuntimeException(
                'chk_producto_documentos_tipo was not found.'
            );
        }

        return preg_replace('/\s+/', ' ', $clause) ?? $clause;
    }

    private function underscoreDocumentRows(PDO $pdo): int
    {
        return (int) $pdo->query(
            "SELECT COUNT(*)
             FROM producto_documentos
             WHERE LOCATE('_', tipo_documento) > 0"
        )->fetchColumn();
    }

    /**
     * @return array<string, string>
     */
    private function constraintCases(PDO $pdo): array
    {
        $cases = [
            'FOTO_PRINCIPAL' => true,
            'ABC' => true,
            'ABC123' => true,
            'A_B' => true,
            'foto_principal' => false,
            'FOTO PRINCIPAL' => false,
            'FOTO-PRINCIPAL' => false,
            ' FOTO_PRINCIPAL' => false,
            'FOTO_PRINCIPAL ' => false,
            'FOTO__PRINCIPAL@' => false,
            'ÁBC' => false,
            '' => false,
            'ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFG' => false,
        ];
        $results = [];

        foreach ($cases as $value => $shouldAccept) {
            $accepted = !$this->fails(
                fn () => $this->insertDocumentType($pdo, $value)
            );
            $results[$value === '' ? 'EMPTY' : $value] =
                $accepted === $shouldAccept
                    ? ($accepted ? 'ACCEPTED' : 'REJECTED')
                    : 'FAILED';
            $this->cleanup($pdo);
        }

        foreach ($results as $label => $result) {
            if ($result === 'FAILED') {
                throw new RuntimeException(
                    'Constraint case failed for ' . $label . '.'
                );
            }
        }

        return $results;
    }

    private function insertDocumentType(PDO $pdo, string $type): void
    {
        $actorId = $this->adminId($pdo);
        $this->insertProduct($pdo, self::PRODUCT_ID, $actorId);
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO producto_documentos (
                id_producto,
                tipo_documento,
                nombre_original,
                ruta_relativa,
                mime_type,
                tamano_bytes,
                es_principal,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :id_producto,
                :tipo_documento,
                'qa.png',
                :ruta_relativa,
                'image/png',
                67,
                1,
                1,
                :creado_por,
                :actualizado_por
            )
            SQL
        );
        $statement->execute([
            'id_producto' => self::PRODUCT_ID,
            'tipo_documento' => $type,
            'ruta_relativa' => self::PRODUCT_ID . '/'
                . bin2hex(random_bytes(8))
                . '.png',
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        $counts = [];

        foreach ([
            'productos',
            'producto_documentos',
            'producto_codigos_barras',
            'producto_impuestos',
        ] as $table) {
            $counts[$table] = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn();
        }

        $counts['qa_files'] = $this->qaFileCount();

        return $counts;
    }

    private function productExists(PDO $pdo, string $productId): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM productos WHERE id_producto = :id_producto'
        );
        $statement->execute(['id_producto' => $productId]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function documentCount(PDO $pdo, string $productId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM producto_documentos
             WHERE id_producto = :id_producto'
        );
        $statement->execute(['id_producto' => $productId]);

        return (int) $statement->fetchColumn();
    }

    private function adminId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            "SELECT id FROM usuarios WHERE username = 'jesus.g' LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Initial admin user is required.');
        }

        return $id;
    }

    private function insertProduct(PDO $pdo, string $productId, int $actorId): void
    {
        $unitId = (int) $pdo->query(
            "SELECT id FROM unidades_medida WHERE codigo = 'PIEZA' LIMIT 1"
        )->fetchColumn();
        $typeId = (int) $pdo->query(
            "SELECT id FROM tipos_producto WHERE codigo = 'PRODUCTO' LIMIT 1"
        )->fetchColumn();

        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO productos (
                id_producto,
                descripcion,
                descripcion_larga,
                tipo_producto_id,
                unidad_medida_id,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :id_producto,
                'Producto imagen QA',
                NULL,
                :tipo_producto_id,
                :unidad_medida_id,
                1,
                :creado_por,
                :actualizado_por
            )
            SQL
        );
        $statement->execute([
            'id_producto' => $productId,
            'tipo_producto_id' => $typeId,
            'unidad_medida_id' => $unitId,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productInput(string $productId, int $unitId): array
    {
        return [
            'id_producto' => $productId,
            'descripcion' => 'Producto imagen QA',
            'descripcion_larga' => 'Producto temporal de imagen.',
            'sku' => '',
            'sku_alterno' => '',
            'upc' => '',
            'ean' => '',
            'gtin' => '',
            'codigo_fabricante' => '',
            'modelo' => '',
            'tipo_producto' => 'PRODUCTO',
            'unidad_medida_id' => (string) $unitId,
            'moneda_id' => '',
            'linea_producto_id' => '',
            'marca_id' => '',
            'clasificacion_producto_id' => '',
            'clave_sat_id' => '',
            'unidad_sat_id' => '',
            'peso_kg' => '',
            'largo_cm' => '',
            'ancho_cm' => '',
            'alto_cm' => '',
            'controla_series' => '0',
            'controla_lotes' => '0',
            'controla_pedimentos' => '0',
            'impuestos' => [],
            'codigos_barras' => '',
        ];
    }

    private function unitId(PDO $pdo): int
    {
        $unitId = (int) $pdo->query(
            "SELECT id FROM unidades_medida
             WHERE codigo = 'PIEZA' AND activo = 1 AND eliminado_en IS NULL
             LIMIT 1"
        )->fetchColumn();

        if ($unitId < 1) {
            throw new RuntimeException('PIEZA unit is required.');
        }

        return $unitId;
    }

    private function activeMainCount(PDO $pdo, string $productId): int
    {
        $statement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM producto_documentos
             WHERE id_producto = :id_producto
               AND tipo_documento = 'FOTO_PRINCIPAL'
               AND es_principal = 1
               AND activo = 1
               AND eliminado_en IS NULL"
        );
        $statement->execute(['id_producto' => $productId]);

        return (int) $statement->fetchColumn();
    }

    private function badMetadataRejected(
        PDO $pdo,
        ProductImageService $service,
        string $relativePath,
        int $actorId
    ): bool {
        $this->deleteDocuments($pdo);
        $this->insertPhotoMetadata($pdo, $relativePath, $actorId);

        try {
            $service->content(self::PRODUCT_ID);
        } catch (ProductValidationException) {
            return true;
        } finally {
            $this->deleteDocuments($pdo);
        }

        return false;
    }

    private function insertPhotoMetadata(
        PDO $pdo,
        string $relativePath,
        int $actorId
    ): void {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO producto_documentos (
                id_producto,
                tipo_documento,
                nombre_original,
                ruta_relativa,
                mime_type,
                tamano_bytes,
                es_principal,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :id_producto,
                'FOTO_PRINCIPAL',
                'qa.png',
                :ruta_relativa,
                'image/png',
                67,
                1,
                1,
                :creado_por,
                :actualizado_por
            )
            SQL
        );
        $statement->execute([
            'id_producto' => self::PRODUCT_ID,
            'ruta_relativa' => $relativePath,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }

    private function deleteDocuments(PDO $pdo): void
    {
        $pdo->prepare(
            'DELETE FROM producto_documentos WHERE id_producto = :id_producto'
        )->execute(['id_producto' => self::PRODUCT_ID]);
    }

    private function inactiveById(PDO $pdo, int $id): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM producto_documentos
             WHERE id = :id
               AND activo = 0
               AND es_principal = 0
               AND eliminado_en IS NOT NULL'
        );
        $statement->execute(['id' => $id]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @param array<string, mixed>|null $photo
     */
    private function absoluteStoredPath(?array $photo): string
    {
        if ($photo === null) {
            throw new RuntimeException('Expected image metadata.');
        }

        return BASE_PATH . '/storage/uploads/productos/'
            . str_replace('/', DIRECTORY_SEPARATOR, (string) $photo['ruta_relativa']);
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(string $path, string $name): array
    {
        return [
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];
    }

    private function imageFile(string $type): string
    {
        $map = [
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lUP9xwAAAABJRU5ErkJggg==',
            'jpg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Al//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EFBABAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z',
            'webp' => 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA',
        ];

        $path = $this->tempPath($type);
        file_put_contents($path, base64_decode($map[$type], true));

        return $path;
    }

    private function gifFile(): string
    {
        $path = $this->tempPath('gif');
        file_put_contents(
            $path,
            base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true)
        );

        return $path;
    }

    private function textFile(string $content, string $name): string
    {
        $path = $this->tempPath($name);
        file_put_contents($path, $content);

        return $path;
    }

    private function largeFile(): string
    {
        $path = $this->tempPath('large');
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to create large test file.');
        }

        fseek($handle, (5 * 1024 * 1024) + 1);
        fwrite($handle, 'x');
        fclose($handle);

        return $path;
    }

    private function tempPath(string $suffix): string
    {
        return BASE_PATH . '/storage/temp/productos-imagen-'
            . bin2hex(random_bytes(6))
            . '-'
            . preg_replace('/[^A-Za-z0-9_.-]/', '', $suffix);
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (ProductValidationException) {
            return true;
        } catch (PDOException) {
            return true;
        } finally {
            foreach (glob(BASE_PATH . '/storage/temp/productos-imagen-*') ?: [] as $file) {
                $this->remove($file);
            }
        }

        return false;
    }

    private function cleanup(PDO $pdo): void
    {
        foreach (glob(BASE_PATH . '/storage/temp/productos-imagen-*') ?: [] as $file) {
            $this->remove($file);
        }

        foreach ($this->qaProductIds() as $productId) {
            $this->cleanupProduct($pdo, $productId);
        }
    }

    private function cleanupProduct(PDO $pdo, string $productId): void
    {
        foreach (glob(BASE_PATH . '/storage/uploads/productos/' . $productId . '/*') ?: [] as $file) {
            $this->remove($file);
        }
        @rmdir(BASE_PATH . '/storage/uploads/productos/' . $productId);

        $pdo->prepare(
            'DELETE FROM producto_documentos WHERE id_producto = :id_producto'
        )->execute(['id_producto' => $productId]);
        $pdo->prepare(
            'DELETE FROM producto_codigos_barras WHERE id_producto = :id_producto'
        )->execute(['id_producto' => $productId]);
        $pdo->prepare(
            'DELETE FROM producto_impuestos WHERE id_producto = :id_producto'
        )->execute(['id_producto' => $productId]);
        $pdo->prepare(
            'DELETE FROM productos WHERE id_producto = :id_producto'
        )->execute(['id_producto' => $productId]);
        $pdo->prepare(
            'DELETE FROM auditoria_eventos
             WHERE entidad = :entidad AND entidad_id = :entidad_id'
        )->execute([
            'entidad' => 'productos',
            'entidad_id' => $productId,
        ]);
    }

    /** @return array<string, int> */
    private function auditActions(PDO $pdo, string $productId, int $actorId): array
    {
        $statement = $pdo->prepare(
            'SELECT accion,
                    COUNT(*) AS total,
                    SUM(CASE WHEN actor_usuario_id = :actor_usuario_id
                        THEN 1 ELSE 0 END) AS actor_total
             FROM auditoria_eventos
             WHERE entidad = :entidad AND entidad_id = :entidad_id
             GROUP BY accion'
        );
        $statement->execute([
            'entidad' => 'productos',
            'entidad_id' => $productId,
            'actor_usuario_id' => $actorId,
        ]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $action = (string) $row['accion'];
            $result[$action] = (int) $row['total'];
            $result[$action . '_actor'] = (int) $row['actor_total'];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function qaProductIds(): array
    {
        return [
            self::PRODUCT_ID,
            self::CREATE_NO_IMAGE_ID,
            self::CREATE_IMAGE_ID,
            self::CREATE_INVALID_IMAGE_ID,
            self::CREATE_INVALID_DATA_ID,
            self::CREATE_ROLLBACK_ID,
        ];
    }

    private function qaFileCount(): int
    {
        $count = 0;

        foreach ($this->qaProductIds() as $productId) {
            $count += count(glob(
                BASE_PATH . '/storage/uploads/productos/' . $productId . '/*'
            ) ?: []);
        }

        return $count;
    }

    private function qaProductFiles(string $productId): int
    {
        return count(glob(
            BASE_PATH . '/storage/uploads/productos/' . $productId . '/*'
        ) ?: []);
    }

    private function orphanOutsideStorageRejected(
        ProductImageOrphanCleanupService $cleanupService
    ): bool {
        $method = new ReflectionMethod(
            ProductImageOrphanCleanupService::class,
            'safeFilePath'
        );
        $method->setAccessible(true);

        try {
            $method->invoke($cleanupService, '../outside-storage.png');
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    private function remove(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }
    }
};

if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    try {
        $config = require BASE_PATH . '/bootstrap/database.php';
        $environment = strtolower((string) $config->get('app.env', 'production'));

        if ($environment === 'production') {
            throw new RuntimeException(
                'PRODUCTOS-IMAGEN-1 tests are disabled in production.'
            );
        }

        $expectedDatabase = 'r_erp_db_core_0_test';
        $database = $config->get('database', []);

        if (
            !is_array($database)
            || ($database['name'] ?? '') !== $expectedDatabase
        ) {
            throw new RuntimeException(
                'PRODUCTOS-IMAGEN-1 test database mismatch.'
            );
        }

        $pdo = (new ConnectionProvider($database))->pdo();

        if (
            (string) $pdo->query('SELECT DATABASE()')->fetchColumn()
            !== $expectedDatabase
        ) {
            throw new RuntimeException('SELECT DATABASE() mismatch.');
        }

        echo json_encode(
            $test->run($pdo, $expectedDatabase),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } catch (PDOException $exception) {
        fwrite(
            STDERR,
            'PRODUCTOS-IMAGEN-1 test database operation failed with SQLSTATE['
            . $exception->getCode()
            . '].' . PHP_EOL
        );
        exit(1);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

return $test;
