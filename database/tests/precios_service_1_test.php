<?php

declare(strict_types=1);

use App\Domain\Pricing\PricingValidationException;
use App\Domain\Pricing\ProductPriceService;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PriceListRepository;
use App\Infrastructure\Repositories\ProductPriceHistoryRepository;
use App\Infrastructure\Repositories\ProductPriceRepository;

return new class implements DatabaseTest {
    private const PRODUCT_ID = 'QAPRCSVC001';
    private const PRODUCT_NO_CURRENCY_ID = 'QAPRCSVC002';
    private const INITIAL_PRODUCT_ID = 'QAPRCSVC003';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($activeDatabase !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        $requiredTables = [
            'listas_precios',
            'producto_precios',
            'producto_precios_historial',
            'productos',
            'monedas',
        ];

        foreach ($requiredTables as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'PRECIOS-SERVICE-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $service = new ProductPriceService(
            new ProductPriceRepository($GLOBALS['precios_service_connection']),
            new PriceListRepository($GLOBALS['precios_service_connection']),
            new ProductPriceHistoryRepository($GLOBALS['precios_service_connection'])
        );
        $lists = new PriceListRepository($GLOBALS['precios_service_connection']);
        $results = [];

        $pdo->beginTransaction();

        try {
            $actorId = $this->adminId($pdo);
            $unitId = $this->unitId($pdo);
            $typeId = $this->productTypeId($pdo);
            $mxnId = $this->currencyId($pdo, 'MXN');
            $usdId = $this->currencyId($pdo, 'USD');
            $publicListId = $this->publicListId($pdo);

            $taxIncludedListId = $lists->insert([
                'clave' => 'QA_PRECIO_INC',
                'nombre' => 'QA precio con impuesto',
                'observaciones' => 'Lista QA transitoria.',
                'incluye_impuestos' => 1,
                'es_predeterminada' => 0,
                'activo' => 1,
                'creado_por' => $actorId,
                'actualizado_por' => $actorId,
            ]);
            $wholesaleListId = $lists->insert([
                'clave' => 'QA_PRECIO_MAY',
                'nombre' => 'QA precio mayoreo',
                'observaciones' => 'Lista QA transitoria.',
                'incluye_impuestos' => 0,
                'es_predeterminada' => 0,
                'activo' => 1,
                'creado_por' => $actorId,
                'actualizado_por' => $actorId,
            ]);

            $results['price_list_repository'] = [
                'find_by_id' => $lists->findById($taxIncludedListId) !== null,
                'find_active_by_id' =>
                    $lists->findActiveById($taxIncludedListId) !== null,
                'find_default_publico' =>
                    ($lists->findDefault()['clave'] ?? '') === 'PUBLICO',
                'list_active_contains_qa' =>
                    $this->containsList($lists->listActive(), $taxIncludedListId),
                'exists_clave' => $lists->existsClave('QA_PRECIO_INC'),
                'exists_clave_excluding_self' =>
                    !$lists->existsClave('QA_PRECIO_INC', $taxIncludedListId),
            ];

            $this->insertProduct($pdo, self::PRODUCT_ID, $unitId, $typeId, $mxnId, $actorId);
            $this->insertProduct(
                $pdo,
                self::PRODUCT_NO_CURRENCY_ID,
                $unitId,
                $typeId,
                null,
                $actorId
            );
            $this->insertProduct(
                $pdo,
                self::INITIAL_PRODUCT_ID,
                $unitId,
                $typeId,
                $mxnId,
                $actorId
            );

            $price = $service->crearPrecio([
                'id_producto' => self::PRODUCT_ID,
                'lista_precio_id' => $publicListId,
                'precio_lista' => '100',
                'precio_minimo' => '80',
                'usuario_id' => $actorId,
                'motivo_cambio' => 'Alta QA de precio público.',
            ]);
            $taxPrice = $service->crearPrecio([
                'id_producto' => self::PRODUCT_ID,
                'lista_precio_id' => $taxIncludedListId,
                'precio_lista' => '200.0000',
                'precio_minimo' => '150.0000',
                'usuario_id' => $actorId,
                'motivo_cambio' => 'Alta QA de precio con impuesto.',
            ]);

            $historyCreation = $service->listarHistorialProductoPrecio(
                (int) $price['id']
            );
            $results['crear_precio'] = [
                'created_price' => (int) $price['id'] > 0,
                'moneda_copiada' => (int) $price['moneda_id'] === $mxnId,
                'incluye_impuestos_publico' =>
                    (int) $price['incluye_impuestos'] === 0,
                'incluye_impuestos_lista' =>
                    (int) $taxPrice['incluye_impuestos'] === 1,
                'history_creacion' =>
                    ($historyCreation[0]['tipo_cambio'] ?? '') === 'CREACION',
                'history_previous_null' =>
                    ($historyCreation[0]['moneda_id_anterior'] ?? null) === null
                    && ($historyCreation[0]['precio_lista_anterior'] ?? null) === null,
            ];

            $results['crear_precio_rechazos'] = [
                'producto_sin_moneda' => $this->fails(
                    fn () => $service->crearPrecio([
                        'id_producto' => self::PRODUCT_NO_CURRENCY_ID,
                        'lista_precio_id' => $publicListId,
                        'precio_lista' => '10.0000',
                        'precio_minimo' => '8.0000',
                        'usuario_id' => $actorId,
                        'motivo_cambio' => 'Debe fallar.',
                    ])
                ),
                'minimo_mayor_lista' => $this->fails(
                    fn () => $service->crearPrecio([
                        'id_producto' => self::PRODUCT_NO_CURRENCY_ID,
                        'lista_precio_id' => $taxIncludedListId,
                        'precio_lista' => '8.0000',
                        'precio_minimo' => '10.0000',
                        'usuario_id' => $actorId,
                        'motivo_cambio' => 'Debe fallar.',
                    ])
                ),
                'duplicado_producto_lista' => $this->fails(
                    fn () => $service->crearPrecio([
                        'id_producto' => self::PRODUCT_ID,
                        'lista_precio_id' => $publicListId,
                        'precio_lista' => '110.0000',
                        'precio_minimo' => '90.0000',
                        'usuario_id' => $actorId,
                        'motivo_cambio' => 'Debe fallar.',
                    ])
                ),
            ];

            $initialPrices = $service->crearPreciosInicialesProducto(
                self::INITIAL_PRODUCT_ID,
                [
                    [
                        'lista_precio_id' => $publicListId,
                        'precio_lista' => '50.0000',
                        'precio_minimo' => '40.0000',
                    ],
                    [
                        'lista_precio_id' => $wholesaleListId,
                        'precio_lista' => '45.0000',
                        'precio_minimo' => '35.0000',
                    ],
                ],
                $actorId
            );
            $emptyInitialPrices = $service->crearPreciosInicialesProducto(
                self::PRODUCT_NO_CURRENCY_ID,
                [],
                $actorId
            );
            $results['crear_precios_iniciales'] = [
                'created_two' => count($initialPrices) === 2,
                'empty_returns_empty' => $emptyInitialPrices === [],
                'duplicate_lists_rejected' => $this->fails(
                    fn () => $service->crearPreciosInicialesProducto(
                        self::INITIAL_PRODUCT_ID,
                        [
                            [
                                'lista_precio_id' => $taxIncludedListId,
                                'precio_lista' => '60.0000',
                                'precio_minimo' => '50.0000',
                            ],
                            [
                                'lista_precio_id' => $taxIncludedListId,
                                'precio_lista' => '70.0000',
                                'precio_minimo' => '60.0000',
                            ],
                        ],
                        $actorId
                    )
                ),
            ];

            $currencyDuplicateRejected = $this->fails(
                fn () => $service->cambiarMonedaProductoConPrecios([
                    'id_producto' => self::PRODUCT_ID,
                    'moneda_id_nueva' => $usdId,
                    'usuario_id' => $actorId,
                    'motivo_cambio' => 'Debe fallar por lista duplicada.',
                    'precios_actualizados' => [
                        [
                            'lista_precio_id' => $publicListId,
                            'precio_lista' => '110.0000',
                            'precio_minimo' => '90.0000',
                        ],
                        [
                            'lista_precio_id' => $publicListId,
                            'precio_lista' => '111.0000',
                            'precio_minimo' => '91.0000',
                        ],
                    ],
                ])
            );
            $currencyChange = $service->cambiarMonedaProductoConPrecios([
                'id_producto' => self::PRODUCT_ID,
                'moneda_id_nueva' => $usdId,
                'usuario_id' => $actorId,
                'motivo_cambio' => 'Cambio QA de moneda comercial.',
                'precios_actualizados' => [
                    [
                        'lista_precio_id' => $publicListId,
                        'precio_lista' => '110.0000',
                        'precio_minimo' => '90.0000',
                    ],
                ],
            ]);
            $publicAfterCurrency = $service->obtenerPrecioUtilizable(
                self::PRODUCT_ID,
                $publicListId
            );
            $reviewPrice = $this->priceById($pdo, (int) $taxPrice['id']);
            $historyAfterCurrency = $this->historyCountByType(
                $pdo,
                self::PRODUCT_ID,
                'CAMBIO_MONEDA'
            );
            $results['cambio_moneda'] = [
                'duplicate_lists_rejected' => $currencyDuplicateRejected,
                'changed' => $currencyChange['changed'] === true,
                'product_currency_updated' =>
                    $this->productCurrency($pdo, self::PRODUCT_ID) === $usdId,
                'captured_price_updated' =>
                    $publicAfterCurrency !== null
                    && $publicAfterCurrency['precio_lista'] === '110.0000'
                    && $publicAfterCurrency['precio_minimo'] === '90.0000',
                'uncaptured_zero_review' =>
                    $reviewPrice['precio_lista'] === '0.0000'
                    && $reviewPrice['precio_minimo'] === '0.0000'
                    && (int) $reviewPrice['requiere_revision'] === 1,
                'history_per_price' => $historyAfterCurrency === 2,
            ];

            $results['precio_utilizable'] = [
                'publico_usable' => $publicAfterCurrency !== null,
                'revision_not_usable' =>
                    $service->obtenerPrecioUtilizable(
                        self::PRODUCT_ID,
                        $taxIncludedListId
                    ) === null,
            ];
            $results['evaluacion'] = [
                'permitido' => $service->evaluarPrecioSolicitado([
                    'id_producto' => self::PRODUCT_ID,
                    'lista_precio_id' => $publicListId,
                    'precio_unitario' => '110.0000',
                ])['resultado'] === 'PERMITIDO',
                'requiere_autorizacion' => $service->evaluarPrecioSolicitado([
                    'id_producto' => self::PRODUCT_ID,
                    'lista_precio_id' => $publicListId,
                    'precio_unitario' => '100.0000',
                ])['resultado'] === 'REQUIERE_AUTORIZACION',
                'bloqueado' => $service->evaluarPrecioSolicitado([
                    'id_producto' => self::PRODUCT_ID,
                    'lista_precio_id' => $publicListId,
                    'precio_unitario' => '89.9999',
                ])['resultado'] === 'BLOQUEADO',
                'precio_en_revision' => $service->evaluarPrecioSolicitado([
                    'id_producto' => self::PRODUCT_ID,
                    'lista_precio_id' => $taxIncludedListId,
                    'precio_unitario' => '1.0000',
                ])['resultado'] === 'PRECIO_EN_REVISION',
                'sin_precio' => $service->evaluarPrecioSolicitado([
                    'id_producto' => self::PRODUCT_ID,
                    'lista_precio_id' => $wholesaleListId,
                    'precio_unitario' => '1.0000',
                ])['resultado'] === 'SIN_PRECIO',
            ];

            $updatedReview = $service->actualizarPrecio([
                'producto_precio_id' => (int) $taxPrice['id'],
                'precio_lista' => '220.0000',
                'precio_minimo' => '180.0000',
                'usuario_id' => $actorId,
                'motivo_cambio' => 'Corrección QA de precio en revisión.',
            ]);
            $results['actualizar_precio'] = [
                'updated_values' =>
                    $updatedReview['precio_lista'] === '220.0000'
                    && $updatedReview['precio_minimo'] === '180.0000',
                'review_removed' =>
                    (int) $updatedReview['requiere_revision'] === 0,
                'history_actualizacion' =>
                    $this->latestHistoryType($pdo, (int) $taxPrice['id'])
                    === 'ACTUALIZACION',
            ];

            $deactivated = $service->desactivarPrecio(
                (int) $taxPrice['id'],
                $actorId,
                'Desactivación QA.'
            );
            $reactivated = $service->reactivarPrecio(
                (int) $taxPrice['id'],
                $actorId,
                'Reactivación QA.'
            );
            $results['activar_desactivar'] = [
                'desactivated' => (int) $deactivated['activo'] === 0,
                'history_desactivacion' =>
                    $this->historyCountByType(
                        $pdo,
                        self::PRODUCT_ID,
                        'DESACTIVACION'
                    ) === 1,
                'reactivated' => (int) $reactivated['activo'] === 1,
                'history_reactivacion' =>
                    $this->historyCountByType(
                        $pdo,
                        self::PRODUCT_ID,
                        'REACTIVACION'
                    ) === 1,
            ];

            $initialCurrencyChange = $service->cambiarMonedaProductoConPrecios([
                'id_producto' => self::INITIAL_PRODUCT_ID,
                'moneda_id_nueva' => $usdId,
                'usuario_id' => $actorId,
                'motivo_cambio' => 'Cambio QA sin precios capturados.',
                'precios_actualizados' => [],
            ]);
            $results['reactivar_rechazo_revision'] =
                count($initialCurrencyChange['precios_en_revision']) === 2
                && $this->fails(
                    fn () => $service->reactivarPrecio(
                        (int) $initialCurrencyChange['precios_en_revision'][0],
                        $actorId,
                        'Debe fallar por revisión.'
                    )
                );

            $results['consultas'] = [
                'listar_historial' =>
                    count($service->listarHistorialProductoPrecio((int) $price['id'])) >= 2,
                'listar_precios_producto' =>
                    count($service->listarPreciosProducto(self::PRODUCT_ID)) === 2,
            ];

            $during = $this->counts($pdo);
        } finally {
            $pdo->rollBack();
        }

        $after = $this->counts($pdo);

        if (!$this->allTrue($results)) {
            throw new RuntimeException('PRECIOS-SERVICE-1 assertions failed.');
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
            'productos_php_db_test_exception' =>
                'not_required_due_to_existing_real_product',
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

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'productos_qa' => $this->countWhere(
                $pdo,
                'productos',
                "id_producto LIKE 'QAPRCSVC%'"
            ),
            'listas_precios_qa' => $this->countWhere(
                $pdo,
                'listas_precios',
                "clave LIKE 'QA_PRECIO_%'"
            ),
            'producto_precios_qa' => $this->countWhere(
                $pdo,
                'producto_precios',
                "id_producto LIKE 'QAPRCSVC%'"
            ),
            'producto_precios_historial_qa' => $this->countWhere(
                $pdo,
                'producto_precios_historial',
                "id_producto LIKE 'QAPRCSVC%'"
            ),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    private function adminId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            "SELECT id FROM usuarios WHERE username = 'jesus.g' LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('PRECIOS-SERVICE-1 requires admin user.');
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
        } catch (PricingValidationException) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $lists
     */
    private function containsList(array $lists, int $listId): bool
    {
        foreach ($lists as $list) {
            if ((int) $list['id'] === $listId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function priceById(PDO $pdo, int $priceId): array
    {
        $statement = $pdo->prepare(
            'SELECT * FROM producto_precios WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $priceId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Expected price row not found.');
        }

        return $row;
    }

    private function historyCountByType(
        PDO $pdo,
        string $productId,
        string $type
    ): int {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM producto_precios_historial
             WHERE id_producto = :id_producto
               AND tipo_cambio = :tipo_cambio'
        );
        $statement->execute([
            'id_producto' => $productId,
            'tipo_cambio' => $type,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function latestHistoryType(PDO $pdo, int $priceId): string
    {
        $statement = $pdo->prepare(
            'SELECT tipo_cambio
             FROM producto_precios_historial
             WHERE producto_precio_id = :producto_precio_id
             ORDER BY cambiado_en DESC, id DESC
             LIMIT 1'
        );
        $statement->execute(['producto_precio_id' => $priceId]);

        return (string) $statement->fetchColumn();
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

    private function allTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $item) {
            if (!$this->allTrue($item)) {
                return false;
            }
        }

        return true;
    }
};
