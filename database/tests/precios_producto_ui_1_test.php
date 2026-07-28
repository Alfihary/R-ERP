<?php

declare(strict_types=1);

use App\Domain\Pricing\ProductPriceService;
use App\Domain\Products\ProductService;
use App\Domain\Products\ProductValidationException;
use App\Http\Controllers\ProductController;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PriceListRepository;
use App\Infrastructure\Repositories\ProductPriceHistoryRepository;
use App\Infrastructure\Repositories\ProductPriceRepository;
use App\Infrastructure\Repositories\ProductRepository;

return new class implements DatabaseTest {
    private const PRODUCT_NO_PRICES = 'QAPRCUI001';
    private const PRODUCT_INITIAL = 'QAPRCUI002';
    private const PRODUCT_EMPTY = 'QAPRCUI003';
    private const PRODUCT_NO_CURRENCY = 'QAPRCUI004';
    private const PRODUCT_MIN_OVER = 'QAPRCUI005';
    private const PRODUCT_DUPLICATE = 'QAPRCUI006';
    private const PRODUCT_NO_CHANGE = 'QAPRCUI007';
    private const PRODUCT_CURRENCY = 'QAPRCUI008';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($activeDatabase !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach ([
            'productos',
            'listas_precios',
            'producto_precios',
            'producto_precios_historial',
            'monedas',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'PRECIOS-PRODUCTO-UI-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $connection = $GLOBALS['precios_producto_ui_connection'];
        $pricing = new ProductPriceService(
            new ProductPriceRepository($connection),
            new PriceListRepository($connection),
            new ProductPriceHistoryRepository($connection)
        );
        $products = new ProductService(
            new ProductRepository($connection),
            $pricing
        );
        $lists = new PriceListRepository($connection);
        $results = [];

        $actorId = $this->adminId($pdo);
        $unitId = $this->unitId($pdo);
        $mxnId = $this->currencyId($pdo, 'MXN');
        $usdId = $this->currencyId($pdo, 'USD');
        $publicListId = $this->publicListId($pdo);

        $results['controller_normalization'] = [
            'empty_rows_ignored' => $this->normalizedRows([
                [
                    'lista_precio_id' => '',
                    'precio_lista' => '',
                    'precio_minimo' => '',
                ],
            ], false, true)['precios_iniciales'] === [],
            'partial_row_rejected' => $this->controllerFails([
                [
                    'lista_precio_id' => $publicListId,
                    'precio_lista' => '10.0000',
                    'precio_minimo' => '',
                ],
            ], false, true),
            'duplicate_lists_rejected' => $this->controllerFails([
                [
                    'lista_precio_id' => $publicListId,
                    'precio_lista' => '10.0000',
                    'precio_minimo' => '8.0000',
                ],
                [
                    'lista_precio_id' => $publicListId,
                    'precio_lista' => '11.0000',
                    'precio_minimo' => '9.0000',
                ],
            ], false, true),
            'unauthorized_price_input_ignored' => $this->normalizedRows([
                [
                    'lista_precio_id' => '',
                    'precio_lista' => '10.0000',
                    'precio_minimo' => '',
                ],
            ], false, false)['precios_iniciales'] === [],
        ];

        $pdo->beginTransaction();

        try {
            $secondaryListId = $lists->insert([
                'clave' => 'QA_UI_MAY',
                'nombre' => 'QA UI mayoreo',
                'observaciones' => 'Lista QA transitoria.',
                'incluye_impuestos' => 0,
                'es_predeterminada' => 0,
                'activo' => 1,
                'creado_por' => $actorId,
                'actualizado_por' => $actorId,
            ]);

            $products->create(
                $this->productInput(self::PRODUCT_NO_PRICES, $unitId, $mxnId),
                $actorId
            );
            $results['crear_sin_precios'] = [
                'product_created' =>
                    $this->productExists($pdo, self::PRODUCT_NO_PRICES),
                'no_prices_created' =>
                    $this->priceCount($pdo, self::PRODUCT_NO_PRICES) === 0,
            ];

            $products->create(
                $this->productInput(
                    self::PRODUCT_INITIAL,
                    $unitId,
                    $mxnId,
                    [
                        [
                            'lista_precio_id' => $publicListId,
                            'precio_lista' => '100.0000',
                            'precio_minimo' => '80.0000',
                        ],
                    ]
                ),
                $actorId
            );
            $initialPrice = $this->priceByProductAndList(
                $pdo,
                self::PRODUCT_INITIAL,
                $publicListId
            );
            $results['crear_con_precios_iniciales'] = [
                'price_created' => $initialPrice !== null,
                'history_creacion' =>
                    $this->historyCountByType(
                        $pdo,
                        self::PRODUCT_INITIAL,
                        'CREACION'
                    ) === 1,
            ];

            $products->create(
                $this->productInput(
                    self::PRODUCT_EMPTY,
                    $unitId,
                    $mxnId,
                    $this->normalizedRows([
                        [
                            'lista_precio_id' => '',
                            'precio_lista' => '',
                            'precio_minimo' => '',
                        ],
                    ], false, true)['precios_iniciales']
                ),
                $actorId
            );
            $results['fila_vacia_ignorada'] =
                $this->priceCount($pdo, self::PRODUCT_EMPTY) === 0;

            $results['rechazos_creacion'] = [
                'precio_sin_moneda' => $this->fails(
                    fn () => $products->create(
                        $this->productInput(
                            self::PRODUCT_NO_CURRENCY,
                            $unitId,
                            null,
                            [
                                [
                                    'lista_precio_id' => $publicListId,
                                    'precio_lista' => '10.0000',
                                    'precio_minimo' => '8.0000',
                                ],
                            ]
                        ),
                        $actorId
                    )
                ),
                'minimo_mayor_lista' => $this->fails(
                    fn () => $products->create(
                        $this->productInput(
                            self::PRODUCT_MIN_OVER,
                            $unitId,
                            $mxnId,
                            [
                                [
                                    'lista_precio_id' => $publicListId,
                                    'precio_lista' => '8.0000',
                                    'precio_minimo' => '10.0000',
                                ],
                            ]
                        ),
                        $actorId
                    )
                ),
                'listas_duplicadas' => $this->fails(
                    fn () => $products->create(
                        $this->productInput(
                            self::PRODUCT_DUPLICATE,
                            $unitId,
                            $mxnId,
                            [
                                [
                                    'lista_precio_id' => $publicListId,
                                    'precio_lista' => '10.0000',
                                    'precio_minimo' => '8.0000',
                                ],
                                [
                                    'lista_precio_id' => $publicListId,
                                    'precio_lista' => '11.0000',
                                    'precio_minimo' => '9.0000',
                                ],
                            ]
                        ),
                        $actorId
                    )
                ),
            ];

            $products->create(
                $this->productInput(
                    self::PRODUCT_NO_CHANGE,
                    $unitId,
                    $mxnId,
                    [
                        [
                            'lista_precio_id' => $publicListId,
                            'precio_lista' => '77.0000',
                            'precio_minimo' => '70.0000',
                        ],
                    ]
                ),
                $actorId
            );
            $products->update(
                self::PRODUCT_NO_CHANGE,
                $this->productInput(self::PRODUCT_NO_CHANGE, $unitId, $mxnId),
                $actorId
            );
            $unchangedPrice = $this->priceByProductAndList(
                $pdo,
                self::PRODUCT_NO_CHANGE,
                $publicListId
            );
            $results['editar_sin_cambio_moneda'] = [
                'price_unchanged' =>
                    $unchangedPrice !== null
                    && $unchangedPrice['precio_lista'] === '77.0000'
                    && $unchangedPrice['precio_minimo'] === '70.0000',
                'no_currency_history' =>
                    $this->historyCountByType(
                        $pdo,
                        self::PRODUCT_NO_CHANGE,
                        'CAMBIO_MONEDA'
                    ) === 0,
            ];

            $products->create(
                $this->productInput(
                    self::PRODUCT_CURRENCY,
                    $unitId,
                    $mxnId,
                    [
                        [
                            'lista_precio_id' => $publicListId,
                            'precio_lista' => '200.0000',
                            'precio_minimo' => '180.0000',
                        ],
                        [
                            'lista_precio_id' => $secondaryListId,
                            'precio_lista' => '190.0000',
                            'precio_minimo' => '170.0000',
                        ],
                    ]
                ),
                $actorId
            );
            $results['cambio_moneda_fila_parcial_rechazada'] =
                $this->controllerFails([
                    [
                        'lista_precio_id' => $publicListId,
                        'precio_lista' => '20.0000',
                        'precio_minimo' => '',
                    ],
                ], true, true);
            $products->update(
                self::PRODUCT_CURRENCY,
                $this->productInput(
                    self::PRODUCT_CURRENCY,
                    $unitId,
                    $usdId,
                    [],
                    $this->normalizedRows([
                        [
                            'lista_precio_id' => $publicListId,
                            'precio_lista' => '20.0000',
                            'precio_minimo' => '18.0000',
                        ],
                        [
                            'lista_precio_id' => '',
                            'precio_lista' => '',
                            'precio_minimo' => '',
                        ],
                    ], true, true)['precios_cambio_moneda']
                ),
                $actorId
            );
            $captured = $this->priceByProductAndList(
                $pdo,
                self::PRODUCT_CURRENCY,
                $publicListId
            );
            $review = $this->priceByProductAndList(
                $pdo,
                self::PRODUCT_CURRENCY,
                $secondaryListId
            );
            $results['editar_cambiando_moneda'] = [
                'captured_price_updated' =>
                    $captured !== null
                    && $captured['precio_lista'] === '20.0000'
                    && $captured['precio_minimo'] === '18.0000'
                    && (int) $captured['requiere_revision'] === 0,
                'empty_list_requires_review' =>
                    $review !== null
                    && $review['precio_lista'] === '0.0000'
                    && $review['precio_minimo'] === '0.0000'
                    && (int) $review['requiere_revision'] === 1,
                'history_cambio_moneda' =>
                    $this->historyCountByType(
                        $pdo,
                        self::PRODUCT_CURRENCY,
                        'CAMBIO_MONEDA'
                    ) === 2,
            ];

            $results['lecturas_para_vista'] = [
                'listas_activas_cargadas' =>
                    count($products->activePriceLists()) >= 1,
                'precios_existentes_cargados' =>
                    count($products->prices(self::PRODUCT_CURRENCY)) === 2,
                'form_declares_initial_section' =>
                    $this->fileContains(
                        'app/Views/products/form.php',
                        'Precios iniciales'
                    ),
                'form_declares_current_prices' =>
                    $this->fileContains(
                        'app/Views/products/form.php',
                        'Precios actuales'
                    ),
                'detail_declares_prices' =>
                    $this->fileContains(
                        'app/Views/products/detail.php',
                        'Precios del producto'
                    ),
            ];

            $during = $this->counts($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->counts($pdo);

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'PRECIOS-PRODUCTO-UI-1 assertions failed: '
                . json_encode(
                    $results,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
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

    /**
     * @param list<array<string, mixed>> $initialPrices
     * @param list<array<string, mixed>> $currencyPrices
     * @return array<string, mixed>
     */
    private function productInput(
        string $productId,
        int $unitId,
        ?int $currencyId,
        array $initialPrices = [],
        array $currencyPrices = []
    ): array {
        return [
            'id_producto' => $productId,
            'descripcion' => 'Producto QA precios UI',
            'descripcion_larga' => 'Producto transitorio de integración UI.',
            'tipo_producto' => 'PRODUCTO',
            'unidad_medida_id' => (string) $unitId,
            'moneda_id' => $currencyId === null ? '' : (string) $currencyId,
            'impuestos' => [],
            'codigos_barras' => '',
            'precios_iniciales' => $initialPrices,
            'precios_cambio_moneda' => $currencyPrices,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function normalizedRows(
        array $rows,
        bool $editing,
        bool $allowed
    ): array {
        $controller = (new ReflectionClass(ProductController::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ProductController::class, 'withPriceInput');
        $method->setAccessible(true);

        $input = $editing
            ? ['precios_cambio_moneda' => $rows]
            : ['precios_iniciales' => $rows];

        return $method->invoke($controller, $input, $editing, $allowed);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function controllerFails(
        array $rows,
        bool $editing,
        bool $allowed
    ): bool {
        try {
            $this->normalizedRows($rows, $editing, $allowed);
        } catch (ProductValidationException) {
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
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'productos_qa' => $this->countWhere(
                $pdo,
                'productos',
                "id_producto LIKE 'QAPRCUI%'"
            ),
            'listas_precios_qa' => $this->countWhere(
                $pdo,
                'listas_precios',
                "clave LIKE 'QA_UI_%'"
            ),
            'producto_precios_qa' => $this->countWhere(
                $pdo,
                'producto_precios',
                "id_producto LIKE 'QAPRCUI%'"
            ),
            'producto_precios_historial_qa' => $this->countWhere(
                $pdo,
                'producto_precios_historial',
                "id_producto LIKE 'QAPRCUI%'"
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
            throw new RuntimeException(
                'PRECIOS-PRODUCTO-UI-1 requires admin user.'
            );
        }

        return $id;
    }

    private function unitId(PDO $pdo): int
    {
        return $this->idByCode($pdo, 'unidades_medida', 'PIEZA');
    }

    private function currencyId(PDO $pdo, string $code): int
    {
        return $this->idByCode($pdo, 'monedas', $code);
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

    private function productExists(PDO $pdo, string $productId): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM productos WHERE id_producto = :id_producto'
        );
        $statement->execute(['id_producto' => $productId]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function priceCount(PDO $pdo, string $productId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM producto_precios
             WHERE id_producto = :id_producto'
        );
        $statement->execute(['id_producto' => $productId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function priceByProductAndList(
        PDO $pdo,
        string $productId,
        int $listId
    ): ?array {
        $statement = $pdo->prepare(
            'SELECT *
             FROM producto_precios
             WHERE id_producto = :id_producto
               AND lista_precio_id = :lista_precio_id
             LIMIT 1'
        );
        $statement->execute([
            'id_producto' => $productId,
            'lista_precio_id' => $listId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
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

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (ProductValidationException) {
            return true;
        }

        return false;
    }

    private function fileContains(string $relativePath, string $needle): bool
    {
        $contents = file_get_contents(BASE_PATH . '/' . $relativePath);

        return is_string($contents) && str_contains($contents, $needle);
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
