<?php

declare(strict_types=1);

use App\Domain\Pricing\PriceAuthorizationService;
use App\Domain\Pricing\PricingValidationException;
use App\Domain\Pricing\ProductPriceService;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PriceAuthorizationRepository;
use App\Infrastructure\Repositories\PriceListRepository;
use App\Infrastructure\Repositories\ProductPriceHistoryRepository;
use App\Infrastructure\Repositories\ProductPriceRepository;

return new class implements DatabaseTest {
    private const PRODUCT_A = 'QAAUTP001';
    private const PRODUCT_B = 'QAAUTP002';
    private const PRODUCT_NO_PRICE = 'QAAUTP003';
    private const PRODUCT_REVIEW = 'QAAUTP004';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($activeDatabase !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach ([
            'autorizaciones_precio',
            'producto_precios',
            'listas_precios',
            'productos',
            'monedas',
            'usuarios',
            'permisos',
            'rol_permisos',
            'usuario_roles',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'PRECIOS-AUTORIZACIONES-SERVICE-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $connection = $GLOBALS['precios_autorizaciones_service_connection'];
        $priceRepository = new ProductPriceRepository($connection);
        $priceService = new ProductPriceService(
            $priceRepository,
            new PriceListRepository($connection),
            new ProductPriceHistoryRepository($connection)
        );
        $service = new PriceAuthorizationService(
            new PriceAuthorizationRepository($connection),
            $priceService,
            $priceRepository
        );
        $results = [
            'schema' => $this->schemaAssertions($pdo),
        ];

        $pdo->beginTransaction();

        try {
            $adminId = $this->adminId($pdo);
            $decisionUserId = $this->insertDecisionUser($pdo);
            $companyId = $this->companyId($pdo);
            $unitId = $this->unitId($pdo);
            $typeId = $this->productTypeId($pdo);
            $currencyId = $this->currencyId($pdo, 'MXN');
            $listId = $this->publicListId($pdo);

            $this->insertProduct($pdo, self::PRODUCT_A, $unitId, $typeId, $currencyId, $adminId);
            $this->insertProduct($pdo, self::PRODUCT_B, $unitId, $typeId, $currencyId, $adminId);
            $this->insertProduct($pdo, self::PRODUCT_NO_PRICE, $unitId, $typeId, $currencyId, $adminId);
            $this->insertProduct($pdo, self::PRODUCT_REVIEW, $unitId, $typeId, $currencyId, $adminId);

            $priceA = $priceService->crearPrecio([
                'id_producto' => self::PRODUCT_A,
                'lista_precio_id' => $listId,
                'precio_lista' => '100.0000',
                'precio_minimo' => '80.0000',
                'usuario_id' => $adminId,
                'motivo_cambio' => 'Precio QA para autorizaciones.',
            ]);
            $priceB = $priceService->crearPrecio([
                'id_producto' => self::PRODUCT_B,
                'lista_precio_id' => $listId,
                'precio_lista' => '100.0000',
                'precio_minimo' => '80.0000',
                'usuario_id' => $adminId,
                'motivo_cambio' => 'Precio QA alterno para autorizaciones.',
            ]);
            $reviewPrice = $priceService->crearPrecio([
                'id_producto' => self::PRODUCT_REVIEW,
                'lista_precio_id' => $listId,
                'precio_lista' => '100.0000',
                'precio_minimo' => '80.0000',
                'usuario_id' => $adminId,
                'motivo_cambio' => 'Precio QA en revisión.',
            ]);
            $this->markPriceForReview($pdo, (int) $reviewPrice['id']);

            $valid = $service->solicitar($this->requestInput(
                $companyId,
                1001,
                1,
                self::PRODUCT_A,
                $listId,
                '90.0000',
                $adminId
            ));
            $storedValid = $this->authorizationById($pdo, (int) $valid['id']);
            $results['solicitar'] = [
                'valid_between_minimum_and_list' =>
                    $valid['estatus'] === 'PENDIENTE',
                'snapshot_product' => $valid['id_producto'] === self::PRODUCT_A,
                'snapshot_product_price' =>
                    (int) $valid['producto_precio_id'] === (int) $priceA['id'],
                'snapshot_list' => (int) $valid['lista_precio_id'] === $listId,
                'snapshot_currency' => (int) $valid['moneda_id'] === $currencyId,
                'snapshot_prices' =>
                    $valid['precio_lista_referencia'] === '100.0000'
                    && $valid['precio_minimo_referencia'] === '80.0000'
                    && $valid['precio_solicitado'] === '90.0000',
                'db_status' => $storedValid['estatus'] === 'PENDIENTE',
            ];

            $results['solicitar_rechazos'] = [
                'price_gte_list_rejected' => $this->fails(
                    fn () => $service->solicitar($this->requestInput(
                        $companyId,
                        1002,
                        1,
                        self::PRODUCT_A,
                        $listId,
                        '100.0000',
                        $adminId
                    ))
                ),
                'price_below_minimum_rejected' => $this->fails(
                    fn () => $service->solicitar($this->requestInput(
                        $companyId,
                        1003,
                        1,
                        self::PRODUCT_A,
                        $listId,
                        '79.9999',
                        $adminId
                    ))
                ),
                'product_without_usable_price_rejected' => $this->fails(
                    fn () => $service->solicitar($this->requestInput(
                        $companyId,
                        1004,
                        1,
                        self::PRODUCT_NO_PRICE,
                        $listId,
                        '90.0000',
                        $adminId
                    ))
                ),
                'price_in_review_rejected' => $this->fails(
                    fn () => $service->solicitar($this->requestInput(
                        $companyId,
                        1005,
                        1,
                        self::PRODUCT_REVIEW,
                        $listId,
                        '90.0000',
                        $adminId
                    ))
                ),
                'duplicate_active_line_rejected' => $this->fails(
                    fn () => $service->solicitar($this->requestInput(
                        $companyId,
                        1001,
                        1,
                        self::PRODUCT_A,
                        $listId,
                        '90.0000',
                        $adminId
                    ))
                ),
            ];

            $approved = $service->aprobar([
                'autorizacion_id' => $valid['id'],
                'decidido_por' => $decisionUserId,
                'motivo_decision' => 'Autorización QA aprobada.',
            ]);
            $results['aprobar'] = [
                'pending_approved' => $approved['estatus'] === 'APROBADA',
                'decision_user_saved' => $approved['decidido_por'] === $decisionUserId,
                'decision_date_saved' => $approved['decidido_en'] !== null,
                'same_user_rejected' => $this->fails(
                    fn () => $service->aprobar([
                        'autorizacion_id' => $valid['id'],
                        'decidido_por' => $adminId,
                        'motivo_decision' => 'No debe aplicar.',
                    ])
                ),
                'non_pending_rejected' => $this->fails(
                    fn () => $service->aprobar([
                        'autorizacion_id' => $valid['id'],
                        'decidido_por' => $decisionUserId,
                        'motivo_decision' => 'No debe aplicar.',
                    ])
                ),
            ];

            $rejectTarget = $service->solicitar($this->requestInput(
                $companyId,
                1006,
                1,
                self::PRODUCT_A,
                $listId,
                '91.0000',
                $adminId
            ));
            $rejected = $service->rechazar([
                'autorizacion_id' => $rejectTarget['id'],
                'decidido_por' => $decisionUserId,
                'motivo_decision' => 'Rechazo QA.',
            ]);
            $sameUserRejectTarget = $service->solicitar($this->requestInput(
                $companyId,
                1007,
                1,
                self::PRODUCT_A,
                $listId,
                '92.0000',
                $adminId
            ));
            $results['rechazar'] = [
                'pending_rejected' => $rejected['estatus'] === 'RECHAZADA',
                'same_user_rejected' => $this->fails(
                    fn () => $service->rechazar([
                        'autorizacion_id' => $sameUserRejectTarget['id'],
                        'decidido_por' => $adminId,
                        'motivo_decision' => 'No debe aplicar.',
                    ])
                ),
            ];

            $cancelTarget = $service->solicitar($this->requestInput(
                $companyId,
                1008,
                1,
                self::PRODUCT_A,
                $listId,
                '93.0000',
                $adminId
            ));
            $cancelled = $service->cancelar([
                'autorizacion_id' => $cancelTarget['id'],
                'cancelado_por' => $adminId,
                'motivo_cancelacion' => 'Cancelación QA.',
            ]);
            $results['cancelar'] = [
                'pending_cancelled' => $cancelled['estatus'] === 'CANCELADA',
                'approved_rejected' => $this->fails(
                    fn () => $service->cancelar([
                        'autorizacion_id' => $valid['id'],
                        'cancelado_por' => $adminId,
                        'motivo_cancelacion' => 'No debe aplicar.',
                    ])
                ),
            ];

            $usageTarget = $service->solicitar($this->requestInput(
                $companyId,
                1009,
                1,
                self::PRODUCT_A,
                $listId,
                '94.0000',
                $adminId
            ));
            $usageApproved = $service->aprobar([
                'autorizacion_id' => $usageTarget['id'],
                'decidido_por' => $decisionUserId,
                'motivo_decision' => 'Autorización QA para uso.',
            ]);
            $usableBeforeUse = $service->obtenerUtilizableParaLinea([
                'documento_tipo' => 'COTIZACION',
                'documento_id' => 1009,
                'linea_id' => 1,
            ]);
            $used = $service->utilizar([
                'autorizacion_id' => $usageApproved['id'],
                'utilizado_por' => $decisionUserId,
            ]);
            $usableAfterUse = $service->obtenerUtilizableParaLinea([
                'documento_tipo' => 'COTIZACION',
                'documento_id' => 1009,
                'linea_id' => 1,
            ]);
            $results['utilizar'] = [
                'approved_used' => $used['estatus'] === 'UTILIZADA',
                'usage_user_saved' => $used['utilizado_por'] === $decisionUserId,
                'usage_date_saved' => $used['utilizado_en'] !== null,
                'pending_rejected' => $this->fails(
                    fn () => $service->utilizar([
                        'autorizacion_id' => $sameUserRejectTarget['id'],
                        'utilizado_por' => $decisionUserId,
                    ])
                ),
                'rejected_rejected' => $this->fails(
                    fn () => $service->utilizar([
                        'autorizacion_id' => $rejected['id'],
                        'utilizado_por' => $decisionUserId,
                    ])
                ),
                'cancelled_rejected' => $this->fails(
                    fn () => $service->utilizar([
                        'autorizacion_id' => $cancelled['id'],
                        'utilizado_por' => $decisionUserId,
                    ])
                ),
                'already_used_rejected' => $this->fails(
                    fn () => $service->utilizar([
                        'autorizacion_id' => $usageApproved['id'],
                        'utilizado_por' => $decisionUserId,
                    ])
                ),
                'usable_before_use' => $usableBeforeUse !== null,
                'usable_after_use_empty' => $usableAfterUse === null,
            ];

            $validationTarget = $service->solicitar($this->requestInput(
                $companyId,
                1010,
                1,
                self::PRODUCT_A,
                $listId,
                '95.0000',
                $adminId
            ));
            $service->aprobar([
                'autorizacion_id' => $validationTarget['id'],
                'decidido_por' => $decisionUserId,
                'motivo_decision' => 'Autorización QA para validación.',
            ]);
            $mismatchTarget = $service->solicitar($this->requestInput(
                $companyId,
                1011,
                1,
                self::PRODUCT_A,
                $listId,
                '96.0000',
                $adminId
            ));
            $service->aprobar([
                'autorizacion_id' => $mismatchTarget['id'],
                'decidido_por' => $decisionUserId,
                'motivo_decision' => 'Autorización QA mismatch.',
            ]);
            $results['validar_uso'] = [
                'permits_without_authorization_when_gte_list' =>
                    $service->validarUsoParaPrecio([
                        'documento_tipo' => 'COTIZACION',
                        'documento_id' => 2001,
                        'linea_id' => 1,
                        'id_producto' => self::PRODUCT_A,
                        'lista_precio_id' => $listId,
                        'precio_unitario' => '100.0000',
                    ])['permitido'] === true,
                'requires_authorization_between_min_and_list' =>
                    $service->validarUsoParaPrecio([
                        'documento_tipo' => 'COTIZACION',
                        'documento_id' => 2002,
                        'linea_id' => 1,
                        'id_producto' => self::PRODUCT_A,
                        'lista_precio_id' => $listId,
                        'precio_unitario' => '95.0000',
                    ])['resultado'] === 'AUTORIZACION_REQUERIDA',
                'accepts_matching_authorization' =>
                    $service->validarUsoParaPrecio([
                        'documento_tipo' => 'COTIZACION',
                        'documento_id' => 1010,
                        'linea_id' => 1,
                        'id_producto' => self::PRODUCT_A,
                        'lista_precio_id' => $listId,
                        'precio_unitario' => '95.0000',
                    ])['resultado'] === 'AUTORIZADO',
                'rejects_other_product' =>
                    $service->validarUsoParaPrecio([
                        'documento_tipo' => 'COTIZACION',
                        'documento_id' => 1010,
                        'linea_id' => 1,
                        'id_producto' => self::PRODUCT_B,
                        'lista_precio_id' => $listId,
                        'precio_unitario' => '95.0000',
                    ])['resultado'] === 'AUTORIZACION_NO_CORRESPONDE',
                'rejects_different_price' =>
                    $service->validarUsoParaPrecio([
                        'documento_tipo' => 'COTIZACION',
                        'documento_id' => 1011,
                        'linea_id' => 1,
                        'id_producto' => self::PRODUCT_A,
                        'lista_precio_id' => $listId,
                        'precio_unitario' => '95.0000',
                    ])['resultado'] === 'AUTORIZACION_NO_CORRESPONDE',
                'blocks_below_minimum' =>
                    $service->validarUsoParaPrecio([
                        'documento_tipo' => 'COTIZACION',
                        'documento_id' => 2003,
                        'linea_id' => 1,
                        'id_producto' => self::PRODUCT_A,
                        'lista_precio_id' => $listId,
                        'precio_unitario' => '79.9999',
                    ])['resultado'] === 'BLOQUEADO',
            ];

            $listed = $service->listar(['estatus' => 'APROBADA'], 1, 10);
            $view = $service->ver((int) $valid['id']);
            $results['listar_ver'] = [
                'list_total' => $listed['total'] >= 1,
                'list_rows' => $listed['rows'] !== [],
                'view_works' => $view !== null && $view['id'] === $valid['id'],
            ];
            $results['permissions'] = $this->permissionAssertions($pdo, $adminId);
            $results['forbidden_modules'] = [
                'ventas_tables_not_created' => !$this->tableExists($pdo, 'ventas'),
                'cotizaciones_tables_not_created' => !$this->tableExists($pdo, 'cotizaciones'),
                'pedidos_tables_not_created' => !$this->tableExists($pdo, 'pedidos'),
                'remisiones_tables_not_created' => !$this->tableExists($pdo, 'remisiones'),
                'facturas_tables_not_created' => !$this->tableExists($pdo, 'facturas'),
                'triggers' => $this->routineCount($pdo, 'TRIGGER') === 0,
                'procedures' => $this->routineCount($pdo, 'PROCEDURE') === 0,
                'functions' => $this->routineCount($pdo, 'FUNCTION') === 0,
                'events' => $this->eventCount($pdo) === 0,
            ];
            $during = $this->counts($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->counts($pdo);
        $results['cleanup'] = [
            'qa_authorizations_rolled_back' =>
                $after['autorizaciones_precio_qa'] === $before['autorizaciones_precio_qa'],
            'qa_products_rolled_back' =>
                $after['productos_qa'] === $before['productos_qa'],
            'qa_prices_rolled_back' =>
                $after['producto_precios_qa'] === $before['producto_precios_qa'],
            'qa_users_rolled_back' =>
                $after['usuarios_qa'] === $before['usuarios_qa'],
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'PRECIOS-AUTORIZACIONES-SERVICE-1 assertions failed: '
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
            'transient_counts_during' => $during ?? [],
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
            'productos_php_db_test_exception' =>
                'not_required_due_to_existing_real_product',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestInput(
        int $companyId,
        int $documentId,
        int $lineId,
        string $productId,
        int $listId,
        string $price,
        int $actorId
    ): array {
        return [
            'empresa_id' => $companyId,
            'documento_tipo' => 'COTIZACION',
            'documento_id' => $documentId,
            'linea_id' => $lineId,
            'documento_folio' => 'COT-QA-' . $documentId,
            'id_producto' => $productId,
            'lista_precio_id' => $listId,
            'precio_unitario_solicitado' => $price,
            'cantidad' => '1.0000',
            'solicitado_por' => $actorId,
            'motivo_solicitud' => 'Precio especial QA autorizado por gerencia.',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function schemaAssertions(PDO $pdo): array
    {
        $columns = $this->columns($pdo, 'autorizaciones_precio');

        return [
            'documento_id_bigint' =>
                ($columns['documento_id']['column_type'] ?? '') === 'bigint unsigned',
            'documento_partida_id_bigint' =>
                ($columns['documento_partida_id']['column_type'] ?? '') === 'bigint unsigned',
            'precio_solicitado_decimal' =>
                ($columns['precio_solicitado']['column_type'] ?? '') === 'decimal(14,4)',
            'status_column' => isset($columns['estatus']),
            'usage_columns' =>
                isset($columns['utilizado_en'], $columns['utilizado_por']),
            'active_unique_index' =>
                $this->indexExists($pdo, 'autorizaciones_precio', 'uq_autorizaciones_precio_activa_partida'),
        ];
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function columns(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);
        $columns = [];

        foreach ($statement->fetchAll() as $row) {
            $columns[(string) $row['COLUMN_NAME']] = [
                'column_type' => (string) $row['COLUMN_TYPE'],
                'is_nullable' => (string) $row['IS_NULLABLE'],
                'column_default' => $row['COLUMN_DEFAULT'] === null
                    ? null
                    : (string) $row['COLUMN_DEFAULT'],
            ];
        }

        return $columns;
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND INDEX_NAME = :index_name'
        );
        $statement->execute([
            'table_name' => $table,
            'index_name' => $index,
        ]);

        return (int) $statement->fetchColumn() > 0;
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

    private function routineCount(PDO $pdo, string $type): int
    {
        if ($type === 'TRIGGER') {
            return (int) $pdo->query(
                'SELECT COUNT(*)
                 FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE()'
            )->fetchColumn();
        }

        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = DATABASE()
               AND ROUTINE_TYPE = :routine_type'
        );
        $statement->execute(['routine_type' => $type]);

        return (int) $statement->fetchColumn();
    }

    private function eventCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM information_schema.EVENTS
             WHERE EVENT_SCHEMA = DATABASE()'
        )->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'autorizaciones_precio' =>
                (int) $pdo->query('SELECT COUNT(*) FROM autorizaciones_precio')->fetchColumn(),
            'autorizaciones_precio_qa' => $this->countWhere(
                $pdo,
                'autorizaciones_precio',
                "documento_folio LIKE 'COT-QA-%'"
            ),
            'productos' =>
                (int) $pdo->query('SELECT COUNT(*) FROM productos')->fetchColumn(),
            'productos_qa' => $this->countWhere(
                $pdo,
                'productos',
                "id_producto LIKE 'QAAUTP%'"
            ),
            'producto_precios' =>
                (int) $pdo->query('SELECT COUNT(*) FROM producto_precios')->fetchColumn(),
            'producto_precios_qa' => $this->countWhere(
                $pdo,
                'producto_precios',
                "id_producto LIKE 'QAAUTP%'"
            ),
            'usuarios' =>
                (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn(),
            'usuarios_qa' => $this->countWhere(
                $pdo,
                'usuarios',
                "username LIKE 'qa-price-auth-%'"
            ),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    private function insertDecisionUser(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo)
             VALUES (:username, :email, :password_hash, 1)'
        );
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $statement->execute([
            'username' => 'qa-price-auth-' . $suffix,
            'email' => 'qa-price-auth-' . $suffix . '@example.test',
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function adminId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            "SELECT id FROM usuarios WHERE username = 'jesus.g' LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException(
                'PRECIOS-AUTORIZACIONES-SERVICE-1 requires admin user.'
            );
        }

        return $id;
    }

    private function companyId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            'SELECT id
             FROM empresas
             WHERE activo = 1
               AND eliminado_en IS NULL
             ORDER BY id
             LIMIT 1'
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException(
                'PRECIOS-AUTORIZACIONES-SERVICE-1 requires active company.'
            );
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
        int $currencyId,
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
            'descripcion' => 'Producto autorización QA',
            'tipo_producto_id' => $typeId,
            'unidad_medida_id' => $unitId,
            'moneda_id' => $currencyId,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }

    private function markPriceForReview(PDO $pdo, int $priceId): void
    {
        $statement = $pdo->prepare(
            'UPDATE producto_precios
             SET precio_lista = 0.0000,
                 precio_minimo = 0.0000,
                 requiere_revision = 1
             WHERE id = :id'
        );
        $statement->execute(['id' => $priceId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizationById(PDO $pdo, int $id): array
    {
        $statement = $pdo->prepare(
            'SELECT * FROM autorizaciones_precio WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Expected authorization row not found.');
        }

        return $row;
    }

    /**
     * @return array<string, bool>
     */
    private function permissionAssertions(PDO $pdo, int $adminId): array
    {
        $required = [
            'precios.autorizaciones.acceder',
            'precios.autorizaciones.ver',
            'precios.autorizaciones.solicitar',
            'precios.autorizaciones.aprobar',
            'precios.autorizaciones.rechazar',
            'precios.autorizaciones.cancelar',
            'precios.autorizaciones.utilizar',
        ];
        $results = [];

        foreach ($required as $permission) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM permisos p
                 INNER JOIN rol_permisos rp ON rp.permiso_id = p.id
                 INNER JOIN usuario_roles ur ON ur.rol_id = rp.rol_id
                 INNER JOIN roles r ON r.id = rp.rol_id
                 WHERE ur.usuario_id = :usuario_id
                   AND p.codigo = :codigo
                   AND p.activo = 1
                   AND r.activo = 1
                   AND rp.activo = 1
                   AND ur.activo = 1'
            );
            $statement->execute([
                'usuario_id' => $adminId,
                'codigo' => $permission,
            ]);
            $results[$permission] = (int) $statement->fetchColumn() >= 1;
        }

        return $results;
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
