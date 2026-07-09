<?php

declare(strict_types=1);

use App\Domain\Products\ProductImageService;
use App\Domain\Products\ProductValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Repositories\ProductDocumentRepository;
use App\Infrastructure\Repositories\ProductRepository;

return new class implements DatabaseTest {
    private const PRODUCT_ID = 'QAIMG001';

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
            $runner->rollback($migration);
        }

        $constraintBefore = $this->constraintClause($pdo);
        $migrationResult = $runner->migrate($migration);
        $secondMigrationResult = $runner->migrate($migration);
        $constraintAfter = $this->constraintClause($pdo);
        $migrationRows = $this->migrationRows($pdo, $migration->id());
        $constraintCases = $this->constraintCases($pdo);
        $this->cleanup($pdo);
        $rollbackResult = $runner->rollback($migration);
        $rollbackRejectsUnderscore = $this->fails(
            fn () => $this->insertDocumentType($pdo, 'A_B')
        );
        $runner->migrate($migration);
        $this->cleanup($pdo);

        if (
            !in_array($migrationResult, ['applied', 'already_applied'], true)
            || $secondMigrationResult !== 'already_applied'
            || $migrationRows !== 1
            || $rollbackResult !== 'rolled_back'
            || $rollbackRejectsUnderscore !== true
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
        $service = new ProductImageService(
            new ProductRepository($connection),
            new ProductDocumentRepository($connection),
            $storagePath
        );

        $results = [];

        try {
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
            $service->replace(self::PRODUCT_ID, $this->upload($second, 'b.jpg'), $actorId);
            $secondPhoto = $service->current(self::PRODUCT_ID);
            $secondPath = $this->absoluteStoredPath($secondPhoto);

            $results['reemplazo'] = $secondPhoto !== null
                && (int) $secondPhoto['id'] !== (int) $firstPhoto['id'];
            $results['imagen_anterior_inactiva'] =
                $this->activeMainCount($pdo, self::PRODUCT_ID) === 1
                && $this->inactiveById($pdo, (int) $firstPhoto['id']);
            $results['archivo_anterior_eliminado'] = !is_file($firstPath);
            $results['archivo_nuevo_permanece'] = is_file($secondPath);

            $service->delete(self::PRODUCT_ID, $actorId);
            $results['eliminacion_logica'] =
                $this->activeMainCount($pdo, self::PRODUCT_ID) === 0
                && $this->inactiveById($pdo, (int) $secondPhoto['id']);
            $results['archivo_eliminado'] = !is_file($secondPath);

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
                'rollback_rejects_underscore' => $rollbackRejectsUnderscore
                    ? 'PASS'
                    : 'FAIL',
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

        foreach (['productos', 'producto_documentos'] as $table) {
            $counts[$table] = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn();
        }

        $counts['qa_files'] = count(glob(
            BASE_PATH . '/storage/uploads/productos/' . self::PRODUCT_ID . '/*'
        ) ?: []);

        return $counts;
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
        foreach (glob(BASE_PATH . '/storage/uploads/productos/' . self::PRODUCT_ID . '/*') ?: [] as $file) {
            $this->remove($file);
        }
        @rmdir(BASE_PATH . '/storage/uploads/productos/' . self::PRODUCT_ID);

        $pdo->prepare(
            'DELETE FROM producto_documentos WHERE id_producto = :id_producto'
        )->execute(['id_producto' => self::PRODUCT_ID]);
        $pdo->prepare(
            'DELETE FROM productos WHERE id_producto = :id_producto'
        )->execute(['id_producto' => self::PRODUCT_ID]);
    }

    private function remove(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }
    }
};
