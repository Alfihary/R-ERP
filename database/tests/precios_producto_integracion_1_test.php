<?php

declare(strict_types=1);

use App\Domain\Pricing\ProductPriceService;
use App\Domain\Products\ProductImageService;
use App\Domain\Products\ProductService;
use App\Domain\Products\ProductValidationException;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PriceListRepository;
use App\Infrastructure\Repositories\ProductDocumentRepository;
use App\Infrastructure\Repositories\ProductPriceHistoryRepository;
use App\Infrastructure\Repositories\ProductPriceRepository;
use App\Infrastructure\Repositories\ProductRepository;

return new class implements DatabaseTest {
    private const PRODUCT_INITIAL = 'QAPRCINT001';
    private const PRODUCT_IMAGE_ROLLBACK = 'QAPRCINT002';
    private const PRODUCT_CURRENCY = 'QAPRCINT003';
    private const PRODUCT_DUPLICATE = 'QAPRCINT004';
    private const PRODUCT_NO_PRICES = 'QAPRCINT005';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($activeDatabase !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach ([
            'productos',
            'producto_documentos',
            'listas_precios',
            'producto_precios',
            'producto_precios_historial',
            'monedas',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'PRECIOS-PRODUCTO-INTEGRACION-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $connection = $GLOBALS['precios_producto_integracion_connection'];
        $products = new ProductService(
            new ProductRepository($connection),
            new ProductPriceService(
                new ProductPriceRepository($connection),
                new PriceListRepository($connection),
                new ProductPriceHistoryRepository($connection)
            )
        );
        $images = new ProductImageService(
            new ProductRepository($connection),
            new ProductDocumentRepository($connection),
            sys_get_temp_dir()
        );
        $lists = new PriceListRepository($connection);
        $results = [];
        $storedRollbackImage = null;

        $actorId = $this->adminId($pdo);
        $unitId = $this->unitId($pdo);
        $mxnId = $this->currencyId($pdo, 'MXN');
        $usdId = $this->currencyId($pdo, 'USD');
        $publicListId = $this->publicListId($pdo);

        $tmpImage = $this->temporaryPng();
        $preparedImage = $images->prepareImageUpload([
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $tmpImage,
            'name' => 'qa-producto.png',
        ]);
        $imageRollbackRejected = $this->fails(
            function () use (
                $products,
                $images,
                $actorId,
                $unitId,
                $mxnId,
                &$storedRollbackImage,
                $preparedImage
            ): void {
                $products->createWithHook(
                    $this->productInput(
                        self::PRODUCT_IMAGE_ROLLBACK,
                        $unitId,
                        $mxnId,
                        [
                            [
                                'lista_precio_id' => 999999999,
                                'precio_lista' => '10.0000',
                                'precio_minimo' => '9.0000',
                            ],
                        ]
                    ),
                    $actorId,
                    function (string $productId) use (
                        $images,
                        $actorId,
                        &$storedRollbackImage,
                        $preparedImage
                    ): void {
                        $storedRollbackImage =
                            $images->attachPreparedMainPhotoToNewProduct(
                                $productId,
                                $preparedImage,
                                $actorId
                            );
                    }
                );
            }
        );
        if ($storedRollbackImage !== null) {
            $images->discardStoredFile($storedRollbackImage);
        }
        $results['rollback_producto_imagen_precios'] = [
            'invalid_price_rejected' => $imageRollbackRejected,
            'product_rolled_back' =>
                !$this->productExists($pdo, self::PRODUCT_IMAGE_ROLLBACK),
            'document_rolled_back' =>
                $this->documentCount($pdo, self::PRODUCT_IMAGE_ROLLBACK) === 0,
            'price_rolled_back' =>
                $this->priceCount($pdo, self::PRODUCT_IMAGE_ROLLBACK) === 0,
        ];

        $pdo->beginTransaction();

        try {
            $secondaryListId = $lists->insert([
                'clave' => 'QA_INT_MAY',
                'nombre' => 'QA integracion mayoreo',
                'observaciones' => 'Lista QA transitoria.',
                'incluye_impuestos' => 0,
                'es_predeterminada' => 0,
                'activo' => 1,
                'creado_por' => $actorId,
                'actualizado_por' => $actorId,
            ]);

            $createdId = $products->create(
                $this->productInput(
                    self::PRODUCT_INITIAL,
                    $unitId,
                    $mxnId,
                    [
                        [
                            'lista_precio_id' => $publicListId,
                            'precio_lista' => '125.5000',
                            'precio_minimo' => '100.0000',
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
            $results['creacion_con_precios_iniciales'] = [
                'product_created' => $createdId === self::PRODUCT_INITIAL,
                'price_created' => $initialPrice !== null,
                'price_currency_copied' =>
                    $initialPrice !== null
                    && (int) $initialPrice['moneda_id'] === $mxnId,
                'history_creacion' =>
                    $this->historyCountByType(
                        $pdo,
                        self::PRODUCT_INITIAL,
                        'CREACION'
                    ) === 1,
            ];

            $results['moneda_requerida_para_precios_iniciales'] = [
                'rejected' => $this->fails(
                    fn () => $products->create(
                        $this->productInput(
                            'QAPRCINTNOUSD',
                            $unitId,
                            null,
                            [
                                [
                                    'lista_precio_id' => $publicListId,
                                    'precio_lista' => '10.0000',
                                    'precio_minimo' => '9.0000',
                                ],
                            ]
                        ),
                        $actorId
                    )
                ),
                'product_not_created' =>
                    !$this->productExists($pdo, 'QAPRCINTNOUSD'),
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
                            'precio_minimo' => '160.0000',
                        ],
                        [
                            'lista_precio_id' => $secondaryListId,
                            'precio_lista' => '180.0000',
                            'precio_minimo' => '140.0000',
                        ],
                    ]
                ),
                $actorId
            );
            $products->update(
                self::PRODUCT_CURRENCY,
                $this->productInput(
                    self::PRODUCT_CURRENCY,
                    $unitId,
                    $usdId,
                    [],
                    [
                        [
                            'lista_precio_id' => $publicListId,
                            'precio_lista' => '20.0000',
                            'precio_minimo' => '16.0000',
                        ],
                    ]
                ),
                $actorId
            );
            $publicAfterChange = $this->priceByProductAndList(
                $pdo,
                self::PRODUCT_CURRENCY,
                $publicListId
            );
            $secondaryAfterChange = $this->priceByProductAndList(
                $pdo,
                self::PRODUCT_CURRENCY,
                $secondaryListId
            );
            $results['cambio_moneda_producto_con_precios'] = [
                'product_currency_updated' =>
                    $this->productCurrency($pdo, self::PRODUCT_CURRENCY) === $usdId,
                'captured_price_updated' =>
                    $publicAfterChange !== null
                    && (int) $publicAfterChange['moneda_id'] === $usdId
                    && $publicAfterChange['precio_lista'] === '20.0000'
                    && $publicAfterChange['precio_minimo'] === '16.0000'
                    && (int) $publicAfterChange['requiere_revision'] === 0,
                'uncaptured_price_requires_review' =>
                    $secondaryAfterChange !== null
                    && (int) $secondaryAfterChange['moneda_id'] === $usdId
                    && $secondaryAfterChange['precio_lista'] === '0.0000'
                    && $secondaryAfterChange['precio_minimo'] === '0.0000'
                    && (int) $secondaryAfterChange['requiere_revision'] === 1,
                'history_cambio_moneda_per_price' =>
                    $this->historyCountByType(
                        $pdo,
                        self::PRODUCT_CURRENCY,
                        'CAMBIO_MONEDA'
                    ) === 2,
            ];

            $products->create(
                $this->productInput(
                    self::PRODUCT_DUPLICATE,
                    $unitId,
                    $mxnId,
                    [
                        [
                            'lista_precio_id' => $publicListId,
                            'precio_lista' => '90.0000',
                            'precio_minimo' => '70.0000',
                        ],
                    ]
                ),
                $actorId
            );
            $duplicateRejected = $this->fails(
                fn () => $products->update(
                    self::PRODUCT_DUPLICATE,
                    $this->productInput(
                        self::PRODUCT_DUPLICATE,
                        $unitId,
                        $usdId,
                        [],
                        [
                            [
                                'lista_precio_id' => $publicListId,
                                'precio_lista' => '9.0000',
                                'precio_minimo' => '7.0000',
                            ],
                            [
                                'lista_precio_id' => $publicListId,
                                'precio_lista' => '8.0000',
                                'precio_minimo' => '6.0000',
                            ],
                        ]
                    ),
                    $actorId
                )
            );
            $results['cambio_moneda_rechaza_listas_duplicadas'] = [
                'rejected' => $duplicateRejected,
                'currency_not_changed' =>
                    $this->productCurrency($pdo, self::PRODUCT_DUPLICATE) === $mxnId,
            ];

            $products->create(
                $this->productInput(self::PRODUCT_NO_PRICES, $unitId, $mxnId),
                $actorId
            );
            $products->update(
                self::PRODUCT_NO_PRICES,
                $this->productInput(self::PRODUCT_NO_PRICES, $unitId, $usdId),
                $actorId
            );
            $results['producto_sin_precios_cambia_moneda_normal'] = [
                'currency_updated' =>
                    $this->productCurrency($pdo, self::PRODUCT_NO_PRICES) === $usdId,
                'no_price_created' =>
                    $this->priceCount($pdo, self::PRODUCT_NO_PRICES) === 0,
                'no_currency_history' =>
                    $this->historyCountByType(
                        $pdo,
                        self::PRODUCT_NO_PRICES,
                        'CAMBIO_MONEDA'
                    ) === 0,
            ];

            $during = $this->counts($pdo);
        } finally {
            if ($storedRollbackImage !== null) {
                $images->discardStoredFile($storedRollbackImage);
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->counts($pdo);

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'PRECIOS-PRODUCTO-INTEGRACION-1 assertions failed: '
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
     * @param list<array<string, mixed>> $currencyChangePrices
     * @return array<string, mixed>
     */
    private function productInput(
        string $productId,
        int $unitId,
        ?int $currencyId,
        array $initialPrices = [],
        array $currencyChangePrices = []
    ): array {
        return [
            'id_producto' => $productId,
            'descripcion' => 'Producto QA precios',
            'descripcion_larga' => 'Producto transitorio de integración QA.',
            'tipo_producto' => 'PRODUCTO',
            'unidad_medida_id' => (string) $unitId,
            'moneda_id' => $currencyId === null ? '' : (string) $currencyId,
            'impuestos' => [],
            'codigos_barras' => '',
            'precios_iniciales' => $initialPrices,
            'precios_cambio_moneda' => $currencyChangePrices,
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
                "id_producto LIKE 'QAPRCINT%'"
            ),
            'listas_precios_qa' => $this->countWhere(
                $pdo,
                'listas_precios',
                "clave LIKE 'QA_INT_%'"
            ),
            'producto_precios_qa' => $this->countWhere(
                $pdo,
                'producto_precios',
                "id_producto LIKE 'QAPRCINT%'"
            ),
            'producto_precios_historial_qa' => $this->countWhere(
                $pdo,
                'producto_precios_historial',
                "id_producto LIKE 'QAPRCINT%'"
            ),
            'producto_documentos_qa' => $this->countWhere(
                $pdo,
                'producto_documentos',
                "id_producto LIKE 'QAPRCINT%'"
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
                'PRECIOS-PRODUCTO-INTEGRACION-1 requires admin user.'
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

    private function productCurrency(PDO $pdo, string $productId): ?int
    {
        $statement = $pdo->prepare(
            'SELECT moneda_id FROM productos WHERE id_producto = :id_producto'
        );
        $statement->execute(['id_producto' => $productId]);
        $value = $statement->fetchColumn();

        return $value === null ? null : (int) $value;
    }

    private function temporaryPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'r_erp_pricing_image_');

        if ($path === false) {
            throw new RuntimeException('Could not create temporary image.');
        }

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
            true
        );

        if ($png === false || file_put_contents($path, $png) === false) {
            throw new RuntimeException('Could not write temporary image.');
        }

        return $path;
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
