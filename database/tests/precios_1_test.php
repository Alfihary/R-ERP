<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;

return new class implements DatabaseTest {
    private const PRODUCT_ID = 'QAPRECIO001';
    private const PRODUCT_NO_CURRENCY_ID = 'QAPRECIONOMON';
    private const PRICE_PERMISSIONS = [
        'precios.listas.acceder',
        'precios.listas.ver',
        'precios.listas.crear',
        'precios.listas.editar',
        'precios.listas.activar',
        'precios.listas.eliminar',
        'precios.listas.predeterminada',
        'precios.productos.acceder',
        'precios.productos.ver',
        'precios.productos.crear',
        'precios.productos.editar',
        'precios.productos.desactivar',
        'precios.productos.reactivar',
        'precios.productos.historial',
        'precios.autorizaciones.acceder',
        'precios.autorizaciones.ver',
        'precios.autorizaciones.solicitar',
        'precios.autorizaciones.aprobar',
        'precios.autorizaciones.rechazar',
        'precios.autorizaciones.cancelar',
        'precios.autorizaciones.utilizar',
    ];

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match PRECIOS-DB-1.'
            );
        }

        $migration = require BASE_PATH
            . '/database/migrations/precios_1_001_create_price_tables.php';
        $permissionSeed = require BASE_PATH
            . '/database/seeds/precios_1_seed_permissions.php';
        $listSeed = require BASE_PATH
            . '/database/seeds/precios_1_seed_initial_price_lists.php';

        if (!$migration instanceof Migration) {
            throw new RuntimeException(
                'PRECIOS-DB-1 migration has an invalid contract.'
            );
        }

        foreach ([$permissionSeed, $listSeed] as $seed) {
            if (!$seed instanceof Seed) {
                throw new RuntimeException(
                    'PRECIOS-DB-1 seed has an invalid contract.'
                );
            }
        }

        $runner = new MigrationRunner($pdo);
        $migrationFirst = $runner->migrate($migration);
        $migrationSecond = $runner->migrate($migration);

        $permissionSeed->run($pdo);
        $permissionSeed->run($pdo);
        $listSeed->run($pdo);
        $listSeed->run($pdo);

        $schema = $this->schemaEvidence($pdo);
        $seed = $this->seedEvidence($pdo);
        $before = $this->counts($pdo);
        $cases = $this->functionalCases($pdo);
        $after = $this->counts($pdo);

        if (
            !in_array($migrationFirst, ['applied', 'already_applied'], true)
            || $migrationSecond !== 'already_applied'
            || $schema['tables_exist'] !== true
            || $seed['publico_rows'] !== 1
            || $seed['publico_predeterminada_activa'] !== 1
            || $seed['permission_rows'] !== count(self::PRICE_PERMISSIONS)
            || $seed['admin_active_permission_rows'] !== count(self::PRICE_PERMISSIONS)
            || $seed['duplicate_permission_codes'] !== 0
            || $seed['duplicate_role_permissions'] !== 0
            || $cases['all_passed'] !== true
            || $before !== $after
        ) {
            throw new RuntimeException(
                'PRECIOS-DB-1 assertions are incomplete.'
            );
        }

        return [
            'database' => $database,
            'migration' => [
                'id' => $migration->id(),
                'first_run' => $migrationFirst,
                'second_run' => $migrationSecond,
                'migration_rows' => $this->migrationRows($pdo, $migration->id()),
            ],
            'schema' => $schema,
            'seed' => $seed,
            'functional' => $cases,
            'persistent_counts_before' => $before,
            'persistent_counts_after' => $after,
            'cleanup' => $before === $after
                ? 'transaction_rolled_back'
                : 'dirty',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaEvidence(PDO $pdo): array
    {
        $tables = [
            'listas_precios',
            'producto_precios',
            'producto_precios_historial',
            'autorizaciones_precio',
        ];

        $existing = [];
        foreach ($tables as $table) {
            $existing[$table] = $this->tableExists($pdo, $table);
        }

        return [
            'tables' => $existing,
            'tables_exist' => !in_array(false, $existing, true),
            'engines' => $this->engines($pdo, $tables),
            'indexes' => $this->indexes($pdo),
            'foreign_keys' => $this->foreignKeys($pdo),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seedEvidence(PDO $pdo): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::PRICE_PERMISSIONS), '?'));

        $permissions = $pdo->prepare(
            'SELECT COUNT(*) FROM permisos WHERE codigo IN (' . $placeholders . ')'
        );
        $permissions->execute(self::PRICE_PERMISSIONS);

        $adminPermissions = $pdo->prepare(
            'SELECT COUNT(*)
             FROM permisos p
             INNER JOIN rol_permisos rp
                ON rp.permiso_id = p.id
               AND rp.activo = 1
               AND rp.eliminado_en IS NULL
             INNER JOIN roles r
                ON r.id = rp.rol_id
               AND r.codigo = ?
               AND r.activo = 1
               AND r.eliminado_en IS NULL
             WHERE p.codigo IN (' . $placeholders . ')
               AND p.activo = 1
               AND p.eliminado_en IS NULL'
        );
        $adminPermissions->execute(['ADMIN', ...self::PRICE_PERMISSIONS]);

        $duplicateCodes = $pdo->prepare(
            'SELECT COUNT(*)
             FROM (
                 SELECT codigo
                 FROM permisos
                 WHERE codigo IN (' . $placeholders . ')
                 GROUP BY codigo
                 HAVING COUNT(*) > 1
             ) duplicated'
        );
        $duplicateCodes->execute(self::PRICE_PERMISSIONS);

        return [
            'publico_rows' => (int) $pdo->query(
                "SELECT COUNT(*) FROM listas_precios WHERE clave = 'PUBLICO'"
            )->fetchColumn(),
            'publico_predeterminada_activa' => (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM listas_precios
                 WHERE clave = 'PUBLICO'
                   AND es_predeterminada = 1
                   AND activo = 1
                   AND eliminado_en IS NULL"
            )->fetchColumn(),
            'permission_rows' => (int) $permissions->fetchColumn(),
            'admin_active_permission_rows' => (int) $adminPermissions->fetchColumn(),
            'duplicate_permission_codes' => (int) $duplicateCodes->fetchColumn(),
            'duplicate_role_permissions' => $this->duplicateRolePermissions($pdo),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function functionalCases(PDO $pdo): array
    {
        $actorId = $this->adminId($pdo);
        $companyId = $this->companyId($pdo);
        $unitId = $this->unitId($pdo);
        $typeId = $this->productTypeId($pdo);
        $mxnId = $this->currencyId($pdo, 'MXN');
        $usdId = $this->currencyId($pdo, 'USD');
        $publicListId = $this->publicListId($pdo);

        $results = [];
        $pdo->beginTransaction();

        try {
            $this->insertProduct($pdo, self::PRODUCT_ID, $unitId, $typeId, $mxnId, $actorId);
            $this->insertProduct(
                $pdo,
                self::PRODUCT_NO_CURRENCY_ID,
                $unitId,
                $typeId,
                null,
                $actorId
            );
            $extraListId = $this->insertList($pdo, 'QA_PRECIO');

            $results['publico_inserted'] = $publicListId > 0;
            $results['duplicate_list_key_rejected'] = $this->fails(
                fn () => $this->insertList($pdo, 'PUBLICO')
            );
            $results['single_default_active_rejected'] = $this->fails(
                fn () => $this->insertList($pdo, 'QA_DEFAULT', true)
            );
            $results['inactive_default_rejected'] = $this->fails(
                fn () => $this->insertList($pdo, 'QA_INACTIVE_DEFAULT', true, false)
            );
            $results['price_minimum_over_list_rejected'] = $this->fails(
                fn () => $this->insertPrice(
                    $pdo,
                    self::PRODUCT_ID,
                    $extraListId,
                    $mxnId,
                    '10.0000',
                    '11.0000'
                )
            );
            $results['review_non_zero_rejected'] = $this->fails(
                fn () => $this->insertPrice(
                    $pdo,
                    self::PRODUCT_ID,
                    $extraListId,
                    $mxnId,
                    '10.0000',
                    '9.0000',
                    true
                )
            );
            $reviewPriceId = $this->insertPrice(
                $pdo,
                self::PRODUCT_ID,
                $extraListId,
                $mxnId,
                '0.0000',
                '0.0000',
                true
            );
            $results['review_zero_accepted'] = $reviewPriceId > 0;
            $results['duplicate_product_list_rejected'] = $this->fails(
                fn () => $this->insertPrice(
                    $pdo,
                    self::PRODUCT_ID,
                    $extraListId,
                    $mxnId,
                    '12.0000',
                    '10.0000'
                )
            );
            $results['price_unknown_product_rejected'] = $this->fails(
                fn () => $this->insertPrice(
                    $pdo,
                    'QAPRECIOMISSING',
                    $publicListId,
                    $mxnId,
                    '12.0000',
                    '10.0000'
                )
            );
            $results['price_unknown_list_rejected'] = $this->fails(
                fn () => $this->insertPrice(
                    $pdo,
                    self::PRODUCT_ID,
                    999999999,
                    $mxnId,
                    '12.0000',
                    '10.0000'
                )
            );
            $results['price_unknown_currency_rejected'] = $this->fails(
                fn () => $this->insertPrice(
                    $pdo,
                    self::PRODUCT_ID,
                    $publicListId,
                    999999999,
                    '12.0000',
                    '10.0000'
                )
            );

            $histCreation = $this->insertHistory(
                $pdo,
                $reviewPriceId,
                self::PRODUCT_ID,
                $extraListId,
                null,
                $mxnId,
                null,
                null,
                '0.0000',
                '0.0000',
                true,
                'CREACION',
                $actorId
            );
            $histCurrency = $this->insertHistory(
                $pdo,
                $reviewPriceId,
                self::PRODUCT_ID,
                $extraListId,
                $mxnId,
                $usdId,
                '10.0000',
                '9.0000',
                '0.0000',
                '0.0000',
                true,
                'CAMBIO_MONEDA',
                $actorId
            );
            $results['history_creation_null_previous_accepted'] = $histCreation > 0;
            $results['history_currency_change_accepted'] = $histCurrency > 0;
            $results['history_review_non_zero_rejected'] = $this->fails(
                fn () => $this->insertHistory(
                    $pdo,
                    $reviewPriceId,
                    self::PRODUCT_ID,
                    $extraListId,
                    $mxnId,
                    $usdId,
                    '10.0000',
                    '9.0000',
                    '11.0000',
                    '10.0000',
                    true,
                    'CAMBIO_MONEDA',
                    $actorId
                )
            );

            $authId = $this->insertAuthorization(
                $pdo,
                'AUT-QA-001',
                $companyId,
                $reviewPriceId,
                self::PRODUCT_ID,
                $extraListId,
                $mxnId,
                '100.0000',
                '80.0000',
                '90.0000',
                $actorId
            );
            $results['authorization_between_minimum_and_list_accepted'] = $authId > 0;
            $results['authorization_below_minimum_rejected'] = $this->fails(
                fn () => $this->insertAuthorization(
                    $pdo,
                    'AUT-QA-002',
                    $companyId,
                    $reviewPriceId,
                    self::PRODUCT_ID,
                    $extraListId,
                    $mxnId,
                    '100.0000',
                    '80.0000',
                    '79.9999',
                    $actorId,
                    2001
                )
            );
            $results['authorization_equal_list_rejected'] = $this->fails(
                fn () => $this->insertAuthorization(
                    $pdo,
                    'AUT-QA-003',
                    $companyId,
                    $reviewPriceId,
                    self::PRODUCT_ID,
                    $extraListId,
                    $mxnId,
                    '100.0000',
                    '80.0000',
                    '100.0000',
                    $actorId,
                    2002
                )
            );
            $results['authorization_same_decisor_rejected'] = $this->fails(
                fn () => $this->insertAuthorization(
                    $pdo,
                    'AUT-QA-004',
                    $companyId,
                    $reviewPriceId,
                    self::PRODUCT_ID,
                    $extraListId,
                    $mxnId,
                    '100.0000',
                    '80.0000',
                    '90.0000',
                    $actorId,
                    2003,
                    'APROBADA',
                    $actorId
                )
            );
            $results['single_active_authorization_per_line_rejected'] =
                $this->fails(
                    fn () => $this->insertAuthorization(
                        $pdo,
                        'AUT-QA-005',
                        $companyId,
                        $reviewPriceId,
                        self::PRODUCT_ID,
                        $extraListId,
                        $mxnId,
                        '100.0000',
                        '80.0000',
                        '90.0000',
                        $actorId
                    )
                );

            $results['no_product_without_currency_price_enforced_by_future_service'] =
                $this->productCurrency($pdo, self::PRODUCT_NO_CURRENCY_ID) === null;
        } finally {
            $pdo->rollBack();
        }

        foreach ($results as $result) {
            if ($result !== true) {
                return $results + ['all_passed' => false];
            }
        }

        return $results + ['all_passed' => true];
    }

    private function insertList(
        PDO $pdo,
        string $key,
        bool $default = false,
        bool $active = true
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO listas_precios (
                clave,
                nombre,
                observaciones,
                incluye_impuestos,
                es_predeterminada,
                activo
             ) VALUES (
                :clave,
                :nombre,
                NULL,
                0,
                :es_predeterminada,
                :activo
             )'
        );
        $statement->execute([
            'clave' => $key,
            'nombre' => 'Lista QA ' . $key,
            'es_predeterminada' => $default ? 1 : 0,
            'activo' => $active ? 1 : 0,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertPrice(
        PDO $pdo,
        string $productId,
        int $listId,
        int $currencyId,
        string $listPrice,
        string $minimumPrice,
        bool $review = false
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO producto_precios (
                id_producto,
                lista_precio_id,
                moneda_id,
                precio_lista,
                precio_minimo,
                requiere_revision
             ) VALUES (
                :id_producto,
                :lista_precio_id,
                :moneda_id,
                :precio_lista,
                :precio_minimo,
                :requiere_revision
             )'
        );
        $statement->execute([
            'id_producto' => $productId,
            'lista_precio_id' => $listId,
            'moneda_id' => $currencyId,
            'precio_lista' => $listPrice,
            'precio_minimo' => $minimumPrice,
            'requiere_revision' => $review ? 1 : 0,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertHistory(
        PDO $pdo,
        int $priceId,
        string $productId,
        int $listId,
        ?int $previousCurrencyId,
        int $newCurrencyId,
        ?string $previousListPrice,
        ?string $previousMinimumPrice,
        string $newListPrice,
        string $newMinimumPrice,
        bool $newReview,
        string $changeType,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO producto_precios_historial (
                producto_precio_id,
                id_producto,
                lista_precio_id,
                moneda_id_anterior,
                moneda_id_nueva,
                precio_lista_anterior,
                precio_minimo_anterior,
                incluye_impuestos_anterior,
                requiere_revision_anterior,
                activo_anterior,
                precio_lista_nuevo,
                precio_minimo_nuevo,
                incluye_impuestos_nuevo,
                requiere_revision_nuevo,
                activo_nuevo,
                tipo_cambio,
                motivo_cambio,
                cambiado_por
             ) VALUES (
                :producto_precio_id,
                :id_producto,
                :lista_precio_id,
                :moneda_id_anterior,
                :moneda_id_nueva,
                :precio_lista_anterior,
                :precio_minimo_anterior,
                0,
                0,
                1,
                :precio_lista_nuevo,
                :precio_minimo_nuevo,
                0,
                :requiere_revision_nuevo,
                1,
                :tipo_cambio,
                :motivo_cambio,
                :cambiado_por
             )'
        );
        $statement->execute([
            'producto_precio_id' => $priceId,
            'id_producto' => $productId,
            'lista_precio_id' => $listId,
            'moneda_id_anterior' => $previousCurrencyId,
            'moneda_id_nueva' => $newCurrencyId,
            'precio_lista_anterior' => $previousListPrice,
            'precio_minimo_anterior' => $previousMinimumPrice,
            'precio_lista_nuevo' => $newListPrice,
            'precio_minimo_nuevo' => $newMinimumPrice,
            'requiere_revision_nuevo' => $newReview ? 1 : 0,
            'tipo_cambio' => $changeType,
            'motivo_cambio' => 'Cambio QA de precio.',
            'cambiado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertAuthorization(
        PDO $pdo,
        string $folio,
        int $companyId,
        int $priceId,
        string $productId,
        int $listId,
        int $currencyId,
        string $listPrice,
        string $minimumPrice,
        string $requestedPrice,
        int $actorId,
        int $lineId = 1001,
        string $status = 'PENDIENTE',
        ?int $deciderId = null
    ): int {
        $decidedAt = $deciderId === null ? null : '2030-01-01 00:00:00';
        $statement = $pdo->prepare(
            'INSERT INTO autorizaciones_precio (
                folio,
                empresa_id,
                documento_tipo,
                documento_id,
                documento_partida_id,
                documento_folio,
                producto_precio_id,
                id_producto,
                lista_precio_id,
                moneda_id,
                precio_lista_referencia,
                precio_minimo_referencia,
                precio_solicitado,
                cantidad,
                incluye_impuestos,
                motivo_solicitud,
                estatus,
                solicitado_por,
                decidido_en,
                decidido_por
             ) VALUES (
                :folio,
                :empresa_id,
                \'VENTA_QA\',
                1001,
                :documento_partida_id,
                \'QA-VENTA-001\',
                :producto_precio_id,
                :id_producto,
                :lista_precio_id,
                :moneda_id,
                :precio_lista_referencia,
                :precio_minimo_referencia,
                :precio_solicitado,
                1.0000,
                0,
                \'Solicitud QA de precio.\',
                :estatus,
                :solicitado_por,
                :decidido_en,
                :decidido_por
             )'
        );
        $statement->execute([
            'folio' => $folio,
            'empresa_id' => $companyId,
            'documento_partida_id' => $lineId,
            'producto_precio_id' => $priceId,
            'id_producto' => $productId,
            'lista_precio_id' => $listId,
            'moneda_id' => $currencyId,
            'precio_lista_referencia' => $listPrice,
            'precio_minimo_referencia' => $minimumPrice,
            'precio_solicitado' => $requestedPrice,
            'estatus' => $status,
            'solicitado_por' => $actorId,
            'decidido_en' => $decidedAt,
            'decidido_por' => $deciderId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertProduct(
        PDO $pdo,
        string $productId,
        int $unitId,
        int $typeId,
        ?int $currencyId,
        int $actorId
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO productos (
                id_producto,
                descripcion,
                descripcion_larga,
                tipo_producto_id,
                unidad_medida_id,
                moneda_id,
                activo,
                creado_por,
                actualizado_por
             ) VALUES (
                :id_producto,
                :descripcion,
                NULL,
                :tipo_producto_id,
                :unidad_medida_id,
                :moneda_id,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'id_producto' => $productId,
            'descripcion' => 'Producto precio QA',
            'tipo_producto_id' => $typeId,
            'unidad_medida_id' => $unitId,
            'moneda_id' => $currencyId,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (PDOException) {
            return true;
        }

        return false;
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

    /**
     * @param list<string> $tables
     * @return array<string, string>
     */
    private function engines(PDO $pdo, array $tables): array
    {
        $engines = [];
        $statement = $pdo->prepare(
            'SELECT ENGINE
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );

        foreach ($tables as $table) {
            $statement->execute(['table_name' => $table]);
            $engines[$table] = (string) $statement->fetchColumn();
        }

        return $engines;
    }

    /**
     * @return list<string>
     */
    private function indexes(PDO $pdo): array
    {
        $statement = $pdo->query(
            "SELECT CONCAT(TABLE_NAME, '.', INDEX_NAME)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN (
                   'listas_precios',
                   'producto_precios',
                   'producto_precios_historial',
                   'autorizaciones_precio'
               )
             GROUP BY TABLE_NAME, INDEX_NAME
             ORDER BY TABLE_NAME, INDEX_NAME"
        );

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string, string>
     */
    private function foreignKeys(PDO $pdo): array
    {
        $statement = $pdo->query(
            "SELECT
                CONSTRAINT_NAME,
                CONCAT(
                    TABLE_NAME,
                    '.',
                    GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION),
                    '->',
                    REFERENCED_TABLE_NAME,
                    '.',
                    GROUP_CONCAT(
                        REFERENCED_COLUMN_NAME
                        ORDER BY ORDINAL_POSITION
                    )
                ) AS relation
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN (
                   'listas_precios',
                   'producto_precios',
                   'producto_precios_historial',
                   'autorizaciones_precio'
               )
               AND REFERENCED_TABLE_NAME IS NOT NULL
             GROUP BY CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
             ORDER BY CONSTRAINT_NAME"
        );

        $keys = [];
        foreach ($statement->fetchAll() as $row) {
            $keys[(string) $row['CONSTRAINT_NAME']] = (string) $row['relation'];
        }

        return $keys;
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        $counts = [];
        foreach ([
            'listas_precios',
            'producto_precios',
            'producto_precios_historial',
            'autorizaciones_precio',
            'productos',
        ] as $table) {
            $counts[$table] = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn();
        }

        return $counts;
    }

    private function migrationRows(PDO $pdo, string $migration): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration'
        );
        $statement->execute(['migration' => $migration]);

        return (int) $statement->fetchColumn();
    }

    private function duplicateRolePermissions(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT rol_id, permiso_id
                 FROM rol_permisos
                 GROUP BY rol_id, permiso_id
                 HAVING COUNT(*) > 1
             ) duplicated'
        )->fetchColumn();
    }

    private function adminId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            "SELECT id FROM usuarios WHERE username = 'jesus.g' LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('PRECIOS-DB-1 requires initial admin.');
        }

        return $id;
    }

    private function companyId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            'SELECT id FROM empresas WHERE activo = 1 AND eliminado_en IS NULL ORDER BY id LIMIT 1'
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('PRECIOS-DB-1 requires an active company.');
        }

        return $id;
    }

    private function unitId(PDO $pdo): int
    {
        return $this->idByCode($pdo, 'unidades_medida', 'PIEZA');
    }

    private function productTypeId(PDO $pdo): int
    {
        return $this->idByCode($pdo, 'tipos_producto', 'PRODUCTO');
    }

    private function currencyId(PDO $pdo, string $code): int
    {
        return $this->idByCode($pdo, 'monedas', $code);
    }

    private function publicListId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            "SELECT id FROM listas_precios WHERE clave = 'PUBLICO' LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('PUBLICO price list is required.');
        }

        return $id;
    }

    private function idByCode(PDO $pdo, string $table, string $code): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM ' . $table . ' WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException($table . ' code is required: ' . $code);
        }

        return $id;
    }

    private function productCurrency(PDO $pdo, string $productId): ?int
    {
        $statement = $pdo->prepare(
            'SELECT moneda_id FROM productos WHERE id_producto = :id_producto'
        );
        $statement->execute(['id_producto' => $productId]);
        $value = $statement->fetchColumn();

        return $value === null ? null : (int) $value;
    }
};
