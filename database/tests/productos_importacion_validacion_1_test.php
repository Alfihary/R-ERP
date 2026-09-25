<?php

declare(strict_types=1);

use App\Domain\Products\Import\ProductImportBusinessLookup;
use App\Domain\Products\Import\ProductImportBusinessValidator;
use App\Domain\Products\Import\ProductImportReadResult;
use App\Domain\Products\Import\ProductImportRow;
use App\Infrastructure\Repositories\PdoProductImportBusinessLookup;

return static function (PDO $pdo, string $expectedDatabase): array {
    $suite = new class($pdo, $expectedDatabase) {
        /** @var array<string, true> */
        private array $fakeExisting = ['102016169' => true];
        /** @var array<string, true> */
        private array $fakeSkuConflicts = ['USED-SKU' => true];
        /** @var array<string, true> */
        private array $fakeBarcodeConflicts = ['USED123' => true];
        /** @var array<string, array<string, array{id: int, code: string}>> */
        private array $fakeCatalogs;

        public function __construct(private readonly PDO $pdo, private readonly string $expectedDatabase)
        {
            $this->fakeCatalogs = [
                'tipo_producto_codigo' => $this->map(['PRODUCTO' => 1, 'SERVICIO' => 2, 'KIT' => 3]),
                'unidad_medida_codigo' => $this->map(['PZA' => 11]),
                'moneda_codigo' => $this->map(['MXN' => 21]),
                'linea_producto_codigo' => $this->map(['LINEA1' => 31]),
                'marca_codigo' => $this->map(['MARCA1' => 41]),
                'clasificacion_producto_codigo' => $this->map(['CLASE1' => 51]),
                'clave_sat_codigo' => $this->map(['43211500' => 61]),
                'unidad_sat_codigo' => $this->map(['H87' => 71]),
                'impuestos_codigos' => $this->map(['IVA16' => 81, 'IEPS8' => 82]),
            ];
        }

        /** @return array<string, mixed> */
        public function run(): array
        {
            if ((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn() !== $this->expectedDatabase) {
                throw new RuntimeException('The connected database does not match the confirmed database.');
            }
            $before = $this->snapshot();
            $checks = $this->functionalChecks();
            $checks = array_merge($checks, $this->databaseChecks());
            $after = $this->snapshot();

            $checks['db_snapshot_unchanged'] = hash_equals($before['all'], $after['all']);
            $checks['protected_product_unchanged'] = hash_equals($before['protected_product'], $after['protected_product']);
            $checks['protected_prices_unchanged'] = hash_equals($before['protected_prices'], $after['protected_prices']);
            $checks['protected_inventory_unchanged'] = hash_equals($before['protected_inventory'], $after['protected_inventory']);
            foreach ($before['tables'] as $table => $hash) {
                $checks['table_unchanged_' . $table] = hash_equals($hash, $after['tables'][$table]);
            }
            foreach ($checks as $name => $passed) {
                if ($passed !== true) {
                    throw new RuntimeException('Product import validation assertion failed: ' . $name . '.');
                }
            }
            return [
                'phase' => 'PRODUCTOS-IMPORTACION-VALIDADOR-NEGOCIO-1',
                'status' => 'PASS',
                'checks_total' => count($checks),
                'checks' => $checks,
                'database' => $this->expectedDatabase,
                'db_writes' => 0,
                'protected_product' => '102016169',
                'integrity' => [
                    'database_unchanged' => true,
                    'protected_product_unchanged' => true,
                    'protected_prices_unchanged' => true,
                    'protected_inventory_unchanged' => true,
                ],
                'smtp_used' => false,
            ];
        }

        /** @return array<string, bool> */
        private function functionalChecks(): array
        {
            $valid = $this->validate([$this->validRow()]);
            $missingIdHeader = $this->validate([$this->validRow()], array_values(array_diff(ProductImportBusinessValidator::REQUIRED_HEADERS, ['id_producto'])));
            $missingDescriptionHeader = $this->validate([$this->validRow()], ['id_producto', 'tipo_producto_codigo', 'unidad_medida_codigo']);
            $missingTypeHeader = $this->validate([$this->validRow()], ['id_producto', 'descripcion', 'unidad_medida_codigo']);
            $missingUnitHeader = $this->validate([$this->validRow()], ['id_producto', 'descripcion', 'tipo_producto_codigo']);
            $unknown = $this->validate([$this->validRow()], [...ProductImportBusinessValidator::REQUIRED_HEADERS, 'precio_lista']);
            $activeHeader = $this->validate([$this->validRow()], [...ProductImportBusinessValidator::REQUIRED_HEADERS, 'activo']);
            $forbiddenHeaders = ['tipo_inventario', 'precio_minimo', 'existencia', 'imagen', 'url_imagen', 'path', 'base64'];
            $emptyId = $this->with(['id_producto' => '']);
            $longId = $this->with(['id_producto' => 'ABCDEFGHIJKLMNOPQ']);
            $invalidId = $this->with(['id_producto' => 'ABC-1']);
            $duplicate = $this->validate([$this->validRow(), $this->with(['id_producto' => 'NEWPROD1'])]);
            $existing = $this->validate([$this->with(['id_producto' => '102016169'])]);
            $emptyDescription = $this->with(['descripcion' => '']);
            $longDescription = $this->with(['descripcion' => str_repeat('á', 41)]);
            $badLongDescription = $this->with(['descripcion_larga' => str_repeat('x', 256)]);
            $badType = $this->with(['tipo_producto_codigo' => 'NOEXISTE']);
            $badUnit = $this->with(['unidad_medida_codigo' => 'NOEXISTE']);
            $badCurrency = $this->with(['moneda_codigo' => 'USD404']);
            $badLine = $this->with(['linea_producto_codigo' => 'NOEXISTE']);
            $badBrand = $this->with(['marca_codigo' => 'NOEXISTE']);
            $badClass = $this->with(['clasificacion_producto_codigo' => 'NOEXISTE']);
            $badSatKey = $this->with(['clave_sat_codigo' => '00000000']);
            $badSatUnit = $this->with(['unidad_sat_codigo' => 'ZZZ']);
            $validDecimals = $this->with(['peso_kg' => '1.2345', 'largo_cm' => '2.123']);
            $negative = $this->with(['peso_kg' => '-1']);
            $zero = $this->with(['peso_kg' => '0']);
            $badDecimal = $this->with(['peso_kg' => '1,25']);
            $badScale = $this->with(['peso_kg' => '1.23456']);
            $booleanOne = $this->with(['controla_series' => '1']);
            $booleanZero = $this->with(['controla_series' => '0']);
            $booleanEmpty = $this->with(['controla_series' => '']);
            $badBoolean = $this->with(['controla_series' => 'true']);
            $servicePhysical = $this->with(['tipo_producto_codigo' => 'SERVICIO', 'peso_kg' => '1']);
            $serviceControl = $this->with(['tipo_producto_codigo' => 'SERVICIO', 'controla_lotes' => '1']);
            $validTaxes = $this->with(['impuestos_codigos' => ' IVA16 | IEPS8 ']);
            $badTax = $this->with(['impuestos_codigos' => 'IVA404']);
            $duplicateTax = $this->with(['impuestos_codigos' => 'IVA16|IVA16']);
            $emptyTax = $this->with(['impuestos_codigos' => 'IVA16||IEPS8']);
            $validBarcode = $this->with(['codigos_barras' => 'ABC123|XYZ9']);
            $duplicateBarcode = $this->with(['codigos_barras' => 'ABC123|ABC123']);
            $invalidBarcode = $this->with(['codigos_barras' => 'ABC-123']);
            $usedBarcode = $this->with(['codigos_barras' => 'USED123']);
            $usedSku = $this->with(['sku' => 'USED-SKU']);
            $invalidSku = $this->with(['sku' => 'SKU CON ESPACIO']);
            $validIdentifiers = $this->with(['sku' => 'SKU-1', 'sku_alterno' => 'ALT/1', 'upc' => '123456789012', 'ean' => '12345678', 'gtin' => '12345678901234', 'codigo_fabricante' => 'FAB_1', 'modelo' => 'Modelo á']);
            $badUpc = $this->with(['upc' => '123']);
            $badEan = $this->with(['ean' => '123456789']);
            $badGtin = $this->with(['gtin' => '12345678901']);
            $multiple = $this->with(['marca_codigo' => 'NO', 'moneda_codigo' => 'NO', 'peso_kg' => '-1']);
            $twoRows = $this->validate([$this->validRow(), $this->with(['id_producto' => 'NEWPROD2'])]);

            return [
                'headers_valid' => $valid->invalidRows() === 0,
                'missing_id_header' => $this->has($missingIdHeader, 'missing_required_header', 'id_producto'),
                'missing_description_header' => $this->has($missingDescriptionHeader, 'missing_required_header', 'descripcion'),
                'missing_type_header' => $this->has($missingTypeHeader, 'missing_required_header', 'tipo_producto_codigo'),
                'missing_unit_header' => $this->has($missingUnitHeader, 'missing_required_header', 'unidad_medida_codigo'),
                'unknown_header' => $this->has($unknown, 'unknown_header', 'precio_lista'),
                'price_header_rejected' => $this->has($unknown, 'unknown_header', 'precio_lista'),
                'active_header_rejected' => $this->has($activeHeader, 'unknown_header', 'activo'),
                'all_forbidden_headers_rejected' => array_reduce(
                    $forbiddenHeaders,
                    fn (bool $carry, string $header): bool => $carry && $this->has(
                        $this->validate([$this->validRow()], [...ProductImportBusinessValidator::REQUIRED_HEADERS, $header]),
                        'unknown_header',
                        $header,
                    ),
                    true,
                ),
                'valid_product_id' => !$this->has($valid, 'product_id_invalid'),
                'empty_product_id' => $this->caseHas($emptyId, 'product_id_required'),
                'long_product_id' => $this->caseHas($longId, 'product_id_too_long'),
                'invalid_product_id' => $this->caseHas($invalidId, 'product_id_invalid'),
                'product_id_normalized_uppercase' => $this->validate([$this->with(['id_producto' => 'lower1'])])->rows[0]->data['id_producto'] === 'LOWER1',
                'duplicate_product_exact' => $this->has($duplicate, 'duplicate_product_id_in_file'),
                'duplicate_product_case_insensitive' => $this->has($this->validate([$this->validRow(), $this->with(['id_producto' => 'newprod1'])]), 'duplicate_product_id_in_file'),
                'existing_product' => $this->has($existing, 'product_already_exists'),
                'new_product' => !$this->has($valid, 'product_already_exists'),
                'description_valid' => !$this->has($valid, 'required_field', 'descripcion'),
                'description_empty' => $this->caseHas($emptyDescription, 'required_field', 'descripcion'),
                'description_max_length' => $this->caseHas($longDescription, 'value_too_long', 'descripcion'),
                'long_description_max_length' => $this->caseHas($badLongDescription, 'value_too_long', 'descripcion_larga'),
                'type_exists' => !$this->has($valid, 'catalog_value_not_found', 'tipo_producto_codigo'),
                'type_missing' => $this->caseHas($badType, 'catalog_value_not_found', 'tipo_producto_codigo'),
                'unit_exists' => !$this->has($valid, 'catalog_value_not_found', 'unidad_medida_codigo'),
                'unit_missing' => $this->caseHas($badUnit, 'catalog_value_not_found', 'unidad_medida_codigo'),
                'currency_valid' => !$this->caseHas($this->with(['moneda_codigo' => 'MXN']), 'catalog_value_not_found', 'moneda_codigo'),
                'currency_missing' => $this->caseHas($badCurrency, 'catalog_value_not_found', 'moneda_codigo'),
                'line_valid_invalid' => !$this->caseHas($this->with(['linea_producto_codigo' => 'LINEA1']), 'catalog_value_not_found') && $this->caseHas($badLine, 'catalog_value_not_found'),
                'brand_valid_invalid' => !$this->caseHas($this->with(['marca_codigo' => 'MARCA1']), 'catalog_value_not_found') && $this->caseHas($badBrand, 'catalog_value_not_found'),
                'classification_valid_invalid' => !$this->caseHas($this->with(['clasificacion_producto_codigo' => 'CLASE1']), 'catalog_value_not_found') && $this->caseHas($badClass, 'catalog_value_not_found'),
                'sat_key_valid_invalid' => !$this->caseHas($this->with(['clave_sat_codigo' => '43211500']), 'catalog_value_not_found') && $this->caseHas($badSatKey, 'catalog_value_not_found'),
                'sat_unit_valid_invalid' => !$this->caseHas($this->with(['unidad_sat_codigo' => 'H87']), 'catalog_value_not_found') && $this->caseHas($badSatUnit, 'catalog_value_not_found'),
                'decimal_valid' => !$this->caseHas($validDecimals, 'invalid_decimal'),
                'decimal_negative' => $this->caseHas($negative, 'invalid_decimal'),
                'decimal_zero' => $this->caseHas($zero, 'invalid_decimal'),
                'decimal_locale_invalid' => $this->caseHas($badDecimal, 'invalid_decimal'),
                'decimal_scale' => $this->caseHas($badScale, 'invalid_decimal'),
                'boolean_one' => $this->validate([$booleanOne])->rows[0]->data['controla_series'] === 1,
                'boolean_zero' => $this->validate([$booleanZero])->rows[0]->data['controla_series'] === 0,
                'boolean_empty' => $this->validate([$booleanEmpty])->rows[0]->data['controla_series'] === 0,
                'boolean_invalid' => $this->caseHas($badBoolean, 'invalid_boolean'),
                'service_physical_rejected' => $this->caseHas($servicePhysical, 'service_physical_value_not_allowed'),
                'service_control_rejected' => $this->caseHas($serviceControl, 'service_control_not_allowed'),
                'taxes_valid' => !$this->caseHas($validTaxes, 'catalog_value_not_found'),
                'tax_missing' => $this->caseHas($badTax, 'catalog_value_not_found', 'impuestos_codigos'),
                'tax_duplicate' => $this->caseHas($duplicateTax, 'duplicate_list_item', 'impuestos_codigos'),
                'tax_empty_item' => $this->caseHas($emptyTax, 'empty_list_item', 'impuestos_codigos'),
                'barcodes_valid' => !$this->caseHas($validBarcode, 'invalid_barcode'),
                'barcode_duplicate' => $this->caseHas($duplicateBarcode, 'duplicate_list_item', 'codigos_barras'),
                'barcode_invalid' => $this->caseHas($invalidBarcode, 'invalid_barcode', 'codigos_barras'),
                'barcode_global_conflict' => $this->caseHas($usedBarcode, 'barcode_already_exists'),
                'sku_global_conflict' => $this->caseHas($usedSku, 'sku_already_exists'),
                'sku_invalid' => $this->caseHas($invalidSku, 'invalid_format', 'sku'),
                'identifiers_valid' => $this->validate([$validIdentifiers])->invalidRows() === 0,
                'upc_invalid' => $this->caseHas($badUpc, 'invalid_format', 'upc'),
                'ean_invalid' => $this->caseHas($badEan, 'invalid_format', 'ean'),
                'gtin_invalid' => $this->caseHas($badGtin, 'invalid_format', 'gtin'),
                'multiple_errors_same_row' => count($this->validate([$multiple])->rows[0]->errors) >= 3,
                'valid_row_result' => $valid->rows[0]->isValid(),
                'invalid_row_result' => !$this->validate([$badBrand])->rows[0]->isValid(),
                'result_totals' => $twoRows->toArray()['total_rows'] === 2 && $twoRows->validRows() === 2,
                'resolved_internal_catalog_refs' => isset($valid->rows[0]->resolvedReferences['tipo_producto_codigo']['id']),
                'optional_blank_is_null' => $valid->rows[0]->data['moneda_codigo'] === null,
            ];
        }

        /** @return array<string, bool> */
        private function databaseChecks(): array
        {
            $type = $this->activeCode('tipos_producto');
            $unit = $this->activeCode('unidades_medida');
            $lookup = new PdoProductImportBusinessLookup($this->pdo);
            $validator = new ProductImportBusinessValidator($lookup);
            $row = $this->validRow();
            $row['id_producto'] = 'QAVALREADONLY1';
            $row['tipo_producto_codigo'] = $type;
            $row['unidad_medida_codigo'] = $unit;
            $result = $validator->validate($this->read([$row]));
            $protected = $row;
            $protected['id_producto'] = '102016169';
            $protectedResult = $validator->validate($this->read([$protected]));

            return [
                'real_catalog_lookup' => $result->invalidRows() === 0,
                'real_existing_product_lookup' => $this->has($protectedResult, 'product_already_exists'),
                'batch_catalog_lookup' => $lookup->queryCount() <= 12,
                'batch_existing_products_lookup' => $lookup->queryCount() <= 12,
                'read_only_adapter_has_no_write_sql' => $this->adapterHasNoWriteSql(),
                'protected_product_exists' => (int) $this->scalar('SELECT COUNT(*) FROM productos WHERE id_producto = :id', ['id' => '102016169']) === 1,
                'protected_prices_count_two' => (int) $this->scalar('SELECT COUNT(*) FROM producto_precios WHERE id_producto = :id', ['id' => '102016169']) === 2,
            ];
        }

        /** @return array<string, mixed> */
        private function validRow(): array
        {
            return [
                'id_producto' => 'NEWPROD1', 'descripcion' => 'Producto nuevo',
                'descripcion_larga' => '', 'sku' => '', 'sku_alterno' => '',
                'upc' => '', 'ean' => '', 'gtin' => '', 'codigo_fabricante' => '',
                'modelo' => '', 'tipo_producto_codigo' => 'PRODUCTO',
                'unidad_medida_codigo' => 'PZA', 'moneda_codigo' => '',
                'linea_producto_codigo' => '', 'marca_codigo' => '',
                'clasificacion_producto_codigo' => '', 'clave_sat_codigo' => '',
                'unidad_sat_codigo' => '', 'peso_kg' => '', 'largo_cm' => '',
                'ancho_cm' => '', 'alto_cm' => '', 'controla_series' => '',
                'controla_lotes' => '', 'controla_pedimentos' => '',
                'impuestos_codigos' => '', 'codigos_barras' => '',
            ];
        }

        /** @param array<string, string> $changes @return array<string, string> */
        private function with(array $changes): array
        {
            return array_replace($this->validRow(), $changes);
        }

        /** @param list<array<string, string>> $rows @param list<string>|null $headers */
        private function validate(array $rows, ?array $headers = null): \App\Domain\Products\Import\ProductImportValidationResult
        {
            $lookup = new class($this->fakeCatalogs, $this->fakeExisting, $this->fakeSkuConflicts, $this->fakeBarcodeConflicts) implements ProductImportBusinessLookup {
                public function __construct(private array $catalogs, private array $existing, private array $skus, private array $barcodes) {}
                public function resolveCatalogs(array $codesByField): array { return $this->catalogs; }
                public function existingProductIds(array $productIds): array { return array_intersect_key($this->existing, array_fill_keys($productIds, true)); }
                public function conflictingSkus(array $skus): array { return array_intersect_key($this->skus, array_fill_keys($skus, true)); }
                public function conflictingBarcodes(array $barcodes): array { return array_intersect_key($this->barcodes, array_fill_keys($barcodes, true)); }
            };
            return (new ProductImportBusinessValidator($lookup))->validate($this->read($rows, $headers));
        }

        /** @param list<array<string, string>> $rows @param list<string>|null $headers */
        private function read(array $rows, ?array $headers = null): ProductImportReadResult
        {
            $headers ??= ProductImportBusinessValidator::CANONICAL_HEADERS;
            return new ProductImportReadResult('csv', $headers, array_map(
                static fn (array $row, int $index): ProductImportRow => new ProductImportRow($index + 2, $row),
                $rows,
                array_keys($rows),
            ), [], []);
        }

        private function caseHas(array $row, string $code, ?string $field = null): bool
        {
            return $this->has($this->validate([$row]), $code, $field);
        }

        private function has($result, string $code, ?string $field = null): bool
        {
            foreach ($result->errors as $error) {
                if ($error->code === $code && ($field === null || $error->field === $field)) {
                    return true;
                }
            }
            return false;
        }

        /** @param array<string, int> $values @return array<string, array{id: int, code: string}> */
        private function map(array $values): array
        {
            $result = [];
            foreach ($values as $code => $id) {
                $result[$code] = ['id' => $id, 'code' => $code];
            }
            return $result;
        }

        private function activeCode(string $table): string
        {
            $value = $this->pdo->query('SELECT codigo FROM ' . $table . ' WHERE activo = 1 AND eliminado_en IS NULL ORDER BY id LIMIT 1')->fetchColumn();
            if (!is_string($value) || $value === '') {
                throw new RuntimeException('A required active catalog is unavailable.');
            }
            return $value;
        }

        /** @return array{all: string, protected_product: string, protected_prices: string, protected_inventory: string, tables: array<string, string>} */
        private function snapshot(): array
        {
            $tables = [];
            foreach (['productos', 'producto_precios', 'existencias_producto', 'movimientos_inventario', 'tickets_productos', 'tickets_productos_correos'] as $table) {
                $tables[$table] = $this->tableHash($table);
            }
            $protectedProduct = $this->rowsHash('SELECT * FROM productos WHERE id_producto = :id', ['id' => '102016169']);
            $protectedPrices = $this->rowsHash('SELECT * FROM producto_precios WHERE id_producto = :id ORDER BY lista_precio_id, moneda_id', ['id' => '102016169']);
            $protectedInventory = $this->rowsHash('SELECT * FROM existencias_producto WHERE id_producto = :id ORDER BY almacen_id', ['id' => '102016169']);
            return [
                'all' => hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR)),
                'protected_product' => $protectedProduct,
                'protected_prices' => $protectedPrices,
                'protected_inventory' => $protectedInventory,
                'tables' => $tables,
            ];
        }

        private function tableHash(string $table): string
        {
            $columns = $this->pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN);
            $order = $columns === [] ? '1' : implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
            return $this->rowsHash('SELECT * FROM ' . $table . ' ORDER BY ' . $order);
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

        private function adapterHasNoWriteSql(): bool
        {
            $source = file_get_contents(BASE_PATH . '/app/Infrastructure/Repositories/PdoProductImportBusinessLookup.php');
            return is_string($source) && preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $source) === 0;
        }
    };

    return $suite->run();
};
