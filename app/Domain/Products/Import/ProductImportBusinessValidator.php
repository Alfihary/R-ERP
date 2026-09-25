<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportBusinessValidator
{
    public const CANONICAL_HEADERS = [
        'id_producto', 'descripcion', 'descripcion_larga', 'sku',
        'sku_alterno', 'upc', 'ean', 'gtin', 'codigo_fabricante', 'modelo',
        'tipo_producto_codigo', 'unidad_medida_codigo', 'moneda_codigo',
        'linea_producto_codigo', 'marca_codigo',
        'clasificacion_producto_codigo', 'clave_sat_codigo',
        'unidad_sat_codigo', 'peso_kg', 'largo_cm', 'ancho_cm', 'alto_cm',
        'controla_series', 'controla_lotes', 'controla_pedimentos',
        'impuestos_codigos', 'codigos_barras',
    ];

    public const REQUIRED_HEADERS = [
        'id_producto', 'descripcion', 'tipo_producto_codigo',
        'unidad_medida_codigo',
    ];

    private const CATALOG_FIELDS = [
        'tipo_producto_codigo', 'unidad_medida_codigo', 'moneda_codigo',
        'linea_producto_codigo', 'marca_codigo',
        'clasificacion_producto_codigo', 'clave_sat_codigo',
        'unidad_sat_codigo', 'impuestos_codigos',
    ];

    public function __construct(private readonly ProductImportBusinessLookup $lookup)
    {
    }

    public function validate(ProductImportReadResult $input): ProductImportValidationResult
    {
        $globalErrors = $this->validateHeaders($input->headers);
        if ($globalErrors !== []) {
            return new ProductImportValidationResult([], $globalErrors, $input->totalRows());
        }

        $prepared = [];
        $codesByField = array_fill_keys(self::CATALOG_FIELDS, []);
        $productIds = [];
        $skus = [];
        $barcodes = [];

        foreach ($input->rows as $row) {
            $data = $this->normalizeRow($row->values);
            $prepared[] = ['source' => $row, 'data' => $data];
            if (is_string($data['id_producto']) && $data['id_producto'] !== '') {
                $productIds[] = $data['id_producto'];
            }
            if (is_string($data['sku']) && $data['sku'] !== '') {
                $skus[] = $data['sku'];
            }
            foreach (array_slice(self::CATALOG_FIELDS, 0, 8) as $field) {
                if (is_string($data[$field]) && $data[$field] !== '') {
                    $codesByField[$field][] = $data[$field];
                }
            }
            foreach ($this->splitList((string) $data['impuestos_codigos'])['values'] as $code) {
                $codesByField['impuestos_codigos'][] = $code;
            }
            foreach ($this->splitList((string) $data['codigos_barras'])['values'] as $barcode) {
                $barcodes[] = $barcode;
            }
        }

        $codesByField = array_map([$this, 'uniqueStrings'], $codesByField);
        $catalogs = $this->lookup->resolveCatalogs($codesByField);
        $existing = $this->lookup->existingProductIds($this->uniqueStrings($productIds));
        $skuConflicts = $this->lookup->conflictingSkus($this->uniqueStrings($skus));
        $barcodeConflicts = $this->lookup->conflictingBarcodes($this->uniqueStrings($barcodes));
        $duplicateIds = $this->duplicates($productIds);
        $duplicateSkus = $this->duplicates($skus);
        $duplicateBarcodes = $this->duplicates($barcodes);
        $validatedRows = [];
        $allErrors = [];

        foreach ($prepared as $item) {
            /** @var ProductImportRow $source */
            $source = $item['source'];
            /** @var array<string, mixed> $data */
            $data = $item['data'];
            [$errors, $references] = $this->validateRow(
                $source->rowNumber,
                $data,
                $catalogs,
                $existing,
                $skuConflicts,
                $barcodeConflicts,
                $duplicateIds,
                $duplicateSkus,
                $duplicateBarcodes,
            );
            array_push($allErrors, ...$errors);
            $validatedRows[] = new ProductImportValidatedRow(
                $source->rowNumber,
                $data,
                $references,
                $errors,
            );
        }

        return new ProductImportValidationResult($validatedRows, $allErrors, $input->totalRows());
    }

    /** @param list<string> $headers @return list<ProductImportValidationError> */
    private function validateHeaders(array $headers): array
    {
        $errors = [];
        foreach (self::REQUIRED_HEADERS as $required) {
            if (!in_array($required, $headers, true)) {
                $errors[] = $this->error(null, $required, 'missing_required_header', 'Falta un encabezado obligatorio.');
            }
        }
        foreach ($headers as $header) {
            if (!in_array($header, self::CANONICAL_HEADERS, true)) {
                $errors[] = $this->error(null, $header, 'unknown_header', 'El encabezado no pertenece al contrato de importación.');
            }
        }
        return $errors;
    }

    /** @param array<string, string> $values @return array<string, mixed> */
    private function normalizeRow(array $values): array
    {
        $data = [];
        foreach (self::CANONICAL_HEADERS as $field) {
            $value = trim((string) ($values[$field] ?? ''));
            if (in_array($field, [
                'id_producto', 'sku', 'sku_alterno', 'codigo_fabricante',
                'tipo_producto_codigo', 'unidad_medida_codigo', 'moneda_codigo',
                'linea_producto_codigo', 'marca_codigo',
                'clasificacion_producto_codigo', 'clave_sat_codigo',
                'unidad_sat_codigo', 'impuestos_codigos', 'codigos_barras',
            ], true)) {
                $value = strtoupper($value);
            }
            $data[$field] = $value === '' && !in_array($field, [
                'id_producto', 'descripcion', 'tipo_producto_codigo',
                'unidad_medida_codigo', 'controla_series', 'controla_lotes',
                'controla_pedimentos', 'impuestos_codigos', 'codigos_barras',
            ], true) ? null : $value;
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array<string, array{id: int, code: string}>> $catalogs
     * @param array<string, true> $existing
     * @param array<string, true> $skuConflicts
     * @param array<string, true> $barcodeConflicts
     * @param array<string, true> $duplicateIds
     * @param array<string, true> $duplicateSkus
     * @param array<string, true> $duplicateBarcodes
     * @return array{list<ProductImportValidationError>, array<string, array{id: int, code: string}|list<array{id: int, code: string}>>}
     */
    private function validateRow(
        int $row,
        array &$data,
        array $catalogs,
        array $existing,
        array $skuConflicts,
        array $barcodeConflicts,
        array $duplicateIds,
        array $duplicateSkus,
        array $duplicateBarcodes,
    ): array {
        $errors = [];
        $references = [];
        $id = (string) $data['id_producto'];
        if ($id === '') {
            $errors[] = $this->error($row, 'id_producto', 'product_id_required', 'El ID de producto es obligatorio.');
        } elseif (strlen($id) > 16) {
            $errors[] = $this->error($row, 'id_producto', 'product_id_too_long', 'El ID de producto admite hasta 16 caracteres.');
        } elseif (preg_match('/^[A-Z0-9]{1,16}$/', $id) !== 1) {
            $errors[] = $this->error($row, 'id_producto', 'product_id_invalid', 'El ID de producto solo admite letras ASCII y números.');
        }
        if ($id !== '' && isset($duplicateIds[$id])) {
            $errors[] = $this->error($row, 'id_producto', 'duplicate_product_id_in_file', 'El ID de producto está repetido en el archivo.', ['normalized_value' => $id]);
        }
        if ($id !== '' && isset($existing[$id])) {
            $errors[] = $this->error($row, 'id_producto', 'product_already_exists', 'El producto ya existe y el modo es create-only.');
        }

        $description = (string) $data['descripcion'];
        if ($description === '') {
            $errors[] = $this->error($row, 'descripcion', 'required_field', 'La descripción es obligatoria.');
        } elseif ($this->length($description) > 40) {
            $errors[] = $this->error($row, 'descripcion', 'value_too_long', 'La descripción admite hasta 40 caracteres.');
        }
        if (is_string($data['descripcion_larga']) && $this->length($data['descripcion_larga']) > 255) {
            $errors[] = $this->error($row, 'descripcion_larga', 'value_too_long', 'La descripción larga admite hasta 255 caracteres.');
        }

        foreach (['sku' => 40, 'sku_alterno' => 40, 'codigo_fabricante' => 60] as $field => $max) {
            $value = $data[$field];
            if (is_string($value) && preg_match('/^[A-Z0-9._\/-]{1,' . $max . '}$/', $value) !== 1) {
                $errors[] = $this->error($row, $field, 'invalid_format', 'El identificador no cumple el formato permitido.');
            }
        }
        foreach (['upc' => '/^[0-9]{12}$/', 'ean' => '/^(?:[0-9]{8}|[0-9]{13})$/', 'gtin' => '/^(?:[0-9]{8}|[0-9]{12}|[0-9]{13}|[0-9]{14})$/'] as $field => $pattern) {
            $value = $data[$field];
            if (is_string($value) && preg_match($pattern, $value) !== 1) {
                $errors[] = $this->error($row, $field, 'invalid_format', 'El identificador comercial no cumple el formato permitido.');
            }
        }
        if (is_string($data['modelo']) && $this->length($data['modelo']) > 80) {
            $errors[] = $this->error($row, 'modelo', 'value_too_long', 'El modelo admite hasta 80 caracteres.');
        }
        $sku = $data['sku'];
        if (is_string($sku) && (isset($skuConflicts[$sku]) || isset($duplicateSkus[$sku]))) {
            $errors[] = $this->error($row, 'sku', isset($skuConflicts[$sku]) ? 'sku_already_exists' : 'duplicate_sku_in_file', 'El SKU debe ser único.');
        }

        foreach (array_slice(self::CATALOG_FIELDS, 0, 8) as $field) {
            $code = (string) ($data[$field] ?? '');
            $required = in_array($field, ['tipo_producto_codigo', 'unidad_medida_codigo'], true);
            if ($code === '') {
                if ($required) {
                    $errors[] = $this->error($row, $field, 'required_field', 'El código de catálogo es obligatorio.');
                }
                continue;
            }
            $resolved = $catalogs[$field][$code] ?? null;
            if ($resolved === null) {
                $errors[] = $this->error($row, $field, 'catalog_value_not_found', 'El valor de catálogo no existe, está inactivo o eliminado.', ['code' => $code]);
            } else {
                $references[$field] = $resolved;
            }
        }

        foreach (['peso_kg' => [8, 4], 'largo_cm' => [9, 3], 'ancho_cm' => [9, 3], 'alto_cm' => [9, 3]] as $field => [$integers, $scale]) {
            $value = $data[$field];
            if ($value === null) {
                continue;
            }
            if (!$this->positiveDecimal((string) $value, $integers, $scale)) {
                $errors[] = $this->error($row, $field, 'invalid_decimal', 'El valor debe ser un decimal positivo con la precisión permitida.');
                $data[$field] = null;
            }
        }

        foreach (['controla_series', 'controla_lotes', 'controla_pedimentos'] as $field) {
            $raw = (string) $data[$field];
            if ($raw === '') {
                $data[$field] = 0;
            } elseif ($raw === '0' || $raw === '1') {
                $data[$field] = (int) $raw;
            } else {
                $data[$field] = 0;
                $errors[] = $this->error($row, $field, 'invalid_boolean', 'El control solo acepta 0, 1 o vacío.');
            }
        }

        if ((string) $data['tipo_producto_codigo'] === 'SERVICIO') {
            foreach (['peso_kg', 'largo_cm', 'ancho_cm', 'alto_cm'] as $field) {
                if ($data[$field] !== null) {
                    $errors[] = $this->error($row, $field, 'service_physical_value_not_allowed', 'Un servicio no puede registrar datos físicos.');
                }
            }
            foreach (['controla_series', 'controla_lotes', 'controla_pedimentos'] as $field) {
                if ($data[$field] === 1) {
                    $errors[] = $this->error($row, $field, 'service_control_not_allowed', 'Un servicio no puede activar controles físicos.');
                }
            }
        }

        $taxes = $this->splitList((string) $data['impuestos_codigos']);
        if ($taxes['has_empty']) {
            $errors[] = $this->error($row, 'impuestos_codigos', 'empty_list_item', 'La lista de impuestos contiene un elemento vacío.');
        }
        if ($taxes['has_duplicates']) {
            $errors[] = $this->error($row, 'impuestos_codigos', 'duplicate_list_item', 'No repitas impuestos en una fila.');
        }
        $taxRefs = [];
        foreach ($taxes['values'] as $code) {
            $resolved = $catalogs['impuestos_codigos'][$code] ?? null;
            if ($resolved === null) {
                $errors[] = $this->error($row, 'impuestos_codigos', 'catalog_value_not_found', 'Un impuesto no existe, está inactivo o eliminado.', ['code' => $code]);
            } else {
                $taxRefs[] = $resolved;
            }
        }
        $data['impuestos_codigos'] = $taxes['values'];
        $references['impuestos_codigos'] = $taxRefs;

        $barcodeList = $this->splitList((string) $data['codigos_barras']);
        if ($barcodeList['has_empty']) {
            $errors[] = $this->error($row, 'codigos_barras', 'empty_list_item', 'La lista de códigos de barras contiene un elemento vacío.');
        }
        if ($barcodeList['has_duplicates']) {
            $errors[] = $this->error($row, 'codigos_barras', 'duplicate_list_item', 'No repitas códigos de barras en una fila.');
        }
        foreach ($barcodeList['values'] as $barcode) {
            if (preg_match('/^[A-Z0-9]{1,64}$/', $barcode) !== 1) {
                $errors[] = $this->error($row, 'codigos_barras', 'invalid_barcode', 'El código de barras solo admite letras ASCII y números.');
            }
            if (isset($barcodeConflicts[$barcode])) {
                $errors[] = $this->error($row, 'codigos_barras', 'barcode_already_exists', 'El código de barras ya pertenece a otro producto.');
            } elseif (isset($duplicateBarcodes[$barcode]) && !$barcodeList['has_duplicates']) {
                $errors[] = $this->error($row, 'codigos_barras', 'duplicate_barcode_in_file', 'El código de barras se repite en otra fila.');
            }
        }
        $data['codigos_barras'] = $barcodeList['values'];

        return [$errors, $references];
    }

    /** @return array{values: list<string>, has_empty: bool, has_duplicates: bool} */
    private function splitList(string $raw): array
    {
        if ($raw === '') {
            return ['values' => [], 'has_empty' => false, 'has_duplicates' => false];
        }
        $parts = explode('|', $raw);
        $values = [];
        $hasEmpty = false;
        foreach ($parts as $part) {
            $value = strtoupper(trim($part));
            if ($value === '') {
                $hasEmpty = true;
                continue;
            }
            $values[] = $value;
        }
        return [
            'values' => $values,
            'has_empty' => $hasEmpty,
            'has_duplicates' => count($values) !== count(array_unique($values)),
        ];
    }

    private function positiveDecimal(string $value, int $integerDigits, int $scale): bool
    {
        $pattern = '/^(?:0|[1-9]\d{0,' . ($integerDigits - 1) . '})(?:\.\d{1,' . $scale . '})?$/';
        return preg_match($pattern, $value) === 1 && preg_match('/[1-9]/', $value) === 1;
    }

    /** @param list<string> $values @return list<string> */
    private function uniqueStrings(array $values): array
    {
        return array_values(array_unique($values));
    }

    /** @param list<string> $values @return array<string, true> */
    private function duplicates(array $values): array
    {
        $counts = array_count_values(array_filter($values, static fn (string $value): bool => $value !== ''));
        return array_fill_keys(array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)), true);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /** @param array<string, bool|float|int|string|null> $context */
    private function error(?int $row, ?string $field, string $code, string $message, array $context = []): ProductImportValidationError
    {
        return new ProductImportValidationError($row, $field, $code, $message, $context);
    }
}
