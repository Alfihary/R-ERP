<?php

declare(strict_types=1);

namespace App\Domain\Products;

use App\Domain\Pricing\PricingValidationException;
use App\Domain\Pricing\ProductPriceService;
use App\Infrastructure\Repositories\ProductRepository;
use PDOException;

final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ?ProductPriceService $prices = null
    )
    {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{
     *     search: string,
     *     status: string,
     *     type_code: string,
     *     line_id: int|null,
     *     brand_id: int|null,
     *     classification_id: int|null
     * }
     */
    public function filters(array $query): array
    {
        $search = $this->text($query, 'search');

        return [
            'search' => strlen($search) <= 80 ? $search : '',
            'status' => in_array(
                $query['status'] ?? '',
                ['active', 'inactive'],
                true
            ) ? (string) $query['status'] : '',
            'type_code' => in_array(
                $query['type_code'] ?? '',
                ['PRODUCTO', 'SERVICIO', 'KIT'],
                true
            ) ? (string) $query['type_code'] : '',
            'line_id' => $this->optionalId($query, 'line_id'),
            'brand_id' => $this->optionalId($query, 'brand_id'),
            'classification_id' =>
                $this->optionalId($query, 'classification_id'),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function search(array $query): array
    {
        return $this->products->search($this->filters($query));
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function catalogs(): array
    {
        return $this->products->activeCatalogs();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activePriceLists(): array
    {
        return $this->priceService()->listarListasActivas();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function prices(string $productId): array
    {
        return $this->priceService()->listarPreciosProducto(
            $this->validatedStoredId($productId)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $productId): array
    {
        $productId = $this->validatedStoredId($productId);
        $product = $this->products->find($productId);

        if ($product === null) {
            throw new ProductValidationException([
                'id_producto' => 'El producto solicitado no existe.',
            ]);
        }

        return $product;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, int $actorId): string
    {
        return $this->createUsing($input, $actorId, null);
    }

    /**
     * @param array<string, mixed> $input
     * @param callable(string): void $afterProductCreated
     */
    public function createWithHook(
        array $input,
        int $actorId,
        callable $afterProductCreated
    ): string {
        return $this->createUsing($input, $actorId, $afterProductCreated);
    }

    /**
     * @param array<string, mixed> $input
     * @param callable(string): void|null $afterProductCreated
     */
    private function createUsing(
        array $input,
        int $actorId,
        ?callable $afterProductCreated
    ): string
    {
        $this->assertActor($actorId);
        $productId = strtoupper($this->text($input, 'id_producto'));
        $data = $this->validate($input, $productId, true);
        $initialPrices = $this->priceRows($input, 'precios_iniciales');

        if ($initialPrices !== [] && $data['product']['moneda_id'] === null) {
            throw new ProductValidationException([
                'moneda_id' =>
                    'Selecciona una moneda para capturar precios iniciales.',
            ]);
        }

        if ($this->products->exists($productId)) {
            throw new ProductValidationException([
                'id_producto' => 'El ID de producto ya existe.',
            ]);
        }

        try {
            $this->products->transactional(function () use (
                $data,
                $actorId,
                $productId,
                $afterProductCreated,
                $initialPrices
            ): void {
                $this->products->create($data['product'], $actorId);
                $this->products->replaceTaxes(
                    $productId,
                    $data['tax_ids'],
                    $actorId
                );
                $this->products->replaceBarcodes(
                    $productId,
                    $data['barcodes'],
                    $actorId
                );
                if ($afterProductCreated !== null) {
                    $afterProductCreated($productId);
                }
                if ($initialPrices !== []) {
                    $this->priceService()->crearPreciosInicialesProducto(
                        $productId,
                        $initialPrices,
                        $actorId
                    );
                }
            });
        } catch (PricingValidationException $exception) {
            throw new ProductValidationException($exception->errors());
        } catch (PDOException $exception) {
            $this->convertDatabaseError($exception);
            throw $exception;
        }

        return $productId;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(
        string $productId,
        array $input,
        int $actorId
    ): void {
        $this->assertActor($actorId);
        $productId = $this->validatedStoredId($productId);
        $current = $this->get($productId);
        $submittedId = $this->text($input, 'id_producto');

        if ($submittedId !== $productId) {
            throw new ProductValidationException([
                'id_producto' =>
                    'El ID del producto es inmutable y no puede modificarse.',
            ]);
        }

        $data = $this->validate($input, $productId, false);
        $newCurrencyId = $data['product']['moneda_id'];
        $currentCurrencyId = $current['moneda_id'] === null
            ? null
            : (int) $current['moneda_id'];
        $currencyChanged = $currentCurrencyId !== $newCurrencyId;
        $currencyPrices = $this->priceRows(
            $input,
            array_key_exists('precios_cambio_moneda', $input)
                ? 'precios_cambio_moneda'
                : 'precios_actualizados'
        );
        $newPrices = $this->priceRows($input, 'precios_iniciales');

        if ($newPrices !== [] && $newCurrencyId === null) {
            throw new ProductValidationException([
                'moneda_id' =>
                    'Selecciona una moneda para capturar precios del producto.',
            ]);
        }

        try {
            $this->products->transactional(function () use (
                $data,
                $actorId,
                $productId,
                $currencyChanged,
                $currencyPrices,
                $newCurrencyId,
                $newPrices
            ): void {
                if ($currencyChanged) {
                    $existingPrices = $this->prices === null
                        ? []
                        : $this->prices->listarPreciosProducto($productId);

                    if ($existingPrices !== [] || $currencyPrices !== []) {
                        if ($newCurrencyId === null) {
                            throw new ProductValidationException([
                                'moneda_id' =>
                                    'Selecciona la nueva moneda del producto.',
                            ]);
                        }

                        $this->priceService()->cambiarMonedaProductoConPrecios([
                            'id_producto' => $productId,
                            'moneda_id_nueva' => $newCurrencyId,
                            'precios_actualizados' => $currencyPrices,
                            'usuario_id' => $actorId,
                            'motivo_cambio' =>
                                'Cambio de moneda desde producto.',
                        ]);
                    }
                }

                $this->products->update(
                    $productId,
                    $data['product'],
                    $actorId
                );
                $this->products->replaceTaxes(
                    $productId,
                    $data['tax_ids'],
                    $actorId
                );
                $this->products->replaceBarcodes(
                    $productId,
                    $data['barcodes'],
                    $actorId
                );
                if ($newPrices !== []) {
                    $this->priceService()->crearPreciosInicialesProducto(
                        $productId,
                        $newPrices,
                        $actorId
                    );
                }
            });
        } catch (PricingValidationException $exception) {
            throw new ProductValidationException($exception->errors());
        } catch (PDOException $exception) {
            $this->convertDatabaseError($exception);
            throw $exception;
        }
    }

    public function setActive(
        string $productId,
        bool $active,
        int $actorId
    ): void {
        $this->assertActor($actorId);
        $product = $this->get($productId);

        if ((int) $product['activo'] === ($active ? 1 : 0)) {
            return;
        }

        $this->products->setActive($productId, $active, $actorId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     product: array<string, int|string|null>,
     *     tax_ids: list<int>,
     *     barcodes: list<string>
     * }
     */
    private function validate(
        array $input,
        string $productId,
        bool $creating
    ): array {
        $errors = [];

        if (preg_match('/^[A-Z0-9]{1,16}$/', $productId) !== 1) {
            $errors['id_producto'] =
                'Usa de 1 a 16 letras mayúsculas o números, sin espacios.';
        }

        $description = $this->text($input, 'descripcion');
        $longDescription = $this->nullableText(
            $input,
            'descripcion_larga'
        );

        if ($description === '' || $this->length($description) > 40) {
            $errors['descripcion'] =
                'La descripción es obligatoria y admite hasta 40 caracteres.';
        }
        if (
            $longDescription !== null
            && $this->length($longDescription) > 255
        ) {
            $errors['descripcion_larga'] =
                'La descripción larga admite hasta 255 caracteres.';
        }

        $identifiers = $this->identifiers($input);

        foreach ($identifiers['errors'] as $field => $message) {
            $errors[$field] = $message;
        }

        if (
            $identifiers['values']['sku'] !== null
            && $this->products->skuOwnedByOther(
                $identifiers['values']['sku'],
                $productId
            )
        ) {
            $errors['sku'] = 'El SKU ya pertenece a otro producto.';
        }

        $typeCode = strtoupper($this->text($input, 'tipo_producto'));
        $type = in_array(
            $typeCode,
            ['PRODUCTO', 'SERVICIO', 'KIT'],
            true
        ) ? $this->products->activeProductTypeByCode($typeCode) : null;

        if ($type === null) {
            $errors['tipo_producto'] =
                'Selecciona un tipo de producto activo y válido.';
        }

        $unitId = $this->optionalId($input, 'unidad_medida_id');
        $currencyId = $this->optionalId($input, 'moneda_id');
        $lineId = $this->optionalId($input, 'linea_producto_id');
        $brandId = $this->optionalId($input, 'marca_id');
        $classificationId = $this->optionalId(
            $input,
            'clasificacion_producto_id'
        );
        $satKeyId = $this->optionalId($input, 'clave_sat_id');
        $satUnitId = $this->optionalId($input, 'unidad_sat_id');

        if ($unitId === null) {
            $errors['unidad_medida_id'] = 'Selecciona una unidad de medida.';
        } elseif (!$this->products->activeUnitExists($unitId)) {
            $errors['unidad_medida_id'] =
                'La unidad no existe o no está activa.';
        }

        foreach ([
            'moneda_id' => [$currencyId, 'moneda', 'activeCurrencyExists'],
            'linea_producto_id' => [$lineId, 'línea', 'activeLineExists'],
            'marca_id' => [$brandId, 'marca', 'activeBrandExists'],
            'clasificacion_producto_id' => [
                $classificationId,
                'clasificación',
                'activeClassificationExists',
            ],
            'clave_sat_id' => [
                $satKeyId,
                'clave SAT',
                'activeSatKeyExists',
            ],
            'unidad_sat_id' => [
                $satUnitId,
                'unidad SAT',
                'activeSatUnitExists',
            ],
        ] as $field => [$value, $label, $method]) {
            if ($value !== null && !$this->products->{$method}($value)) {
                $errors[$field] = 'La ' . $label
                    . ' no existe o no está activa.';
            }
        }

        $physical = [
            'peso_kg' => $this->decimal(
                $input,
                'peso_kg',
                8,
                4,
                'El peso'
            ),
            'largo_cm' => $this->decimal(
                $input,
                'largo_cm',
                9,
                3,
                'El largo'
            ),
            'ancho_cm' => $this->decimal(
                $input,
                'ancho_cm',
                9,
                3,
                'El ancho'
            ),
            'alto_cm' => $this->decimal(
                $input,
                'alto_cm',
                9,
                3,
                'El alto'
            ),
        ];

        foreach ($physical as $field => $result) {
            if ($result['error'] !== null) {
                $errors[$field] = $result['error'];
            }
        }

        $controls = [];
        foreach ([
            'controla_series' => 'series',
            'controla_lotes' => 'lotes',
            'controla_pedimentos' => 'pedimentos',
        ] as $field => $label) {
            $result = $this->booleanFlag($input, $field);
            $controls[$field] = $result['value'];

            if ($result['error']) {
                $errors[$field] =
                    'El control de ' . $label . ' solo acepta 0 o 1.';
            }
        }

        if ($typeCode === 'SERVICIO') {
            foreach ([
                'peso_kg' => 'peso',
                'largo_cm' => 'largo',
                'ancho_cm' => 'ancho',
                'alto_cm' => 'alto',
            ] as $field => $label) {
                if ($physical[$field]['value'] !== null) {
                    $errors[$field] =
                        'Un servicio no puede registrar ' . $label . '.';
                }
            }
            foreach ([
                'controla_series' => 'series',
                'controla_lotes' => 'lotes',
                'controla_pedimentos' => 'pedimentos',
            ] as $field => $label) {
                if ($controls[$field] === 1) {
                    $errors[$field] =
                        'Un servicio no puede controlar ' . $label . '.';
                }
            }
        }

        $taxIds = $this->idList($input['impuestos'] ?? []);
        if ($taxIds === null) {
            $errors['impuestos'] =
                'La selección de impuestos contiene valores inválidos.';
            $taxIds = [];
        } elseif (count($taxIds) !== count(array_unique($taxIds))) {
            $errors['impuestos'] = 'No repitas impuestos.';
        } else {
            foreach ($taxIds as $taxId) {
                if (!$this->products->activeTaxExists($taxId)) {
                    $errors['impuestos'] =
                        'Un impuesto no existe o no está activo.';
                    break;
                }
            }
        }

        $barcodes = $this->barcodes($input['codigos_barras'] ?? '');

        if ($barcodes === null) {
            $errors['codigos_barras'] =
                'Usa un código por línea, solo con letras y números.';
            $barcodes = [];
        } elseif (count($barcodes) !== count(array_unique($barcodes))) {
            $errors['codigos_barras'] =
                'No repitas códigos de barras en el formulario.';
        } else {
            foreach ($barcodes as $barcode) {
                if ($this->products->barcodeOwnedByOther(
                    $barcode,
                    $productId
                )) {
                    $errors['codigos_barras'] =
                        'Un código de barras ya pertenece a otro producto.';
                    break;
                }
            }
        }

        if ($errors !== []) {
            throw new ProductValidationException($errors);
        }

        return [
            'product' => [
                ...($creating ? ['id_producto' => $productId] : []),
                'descripcion' => $description,
                'descripcion_larga' => $longDescription,
                'sku' => $identifiers['values']['sku'],
                'sku_alterno' => $identifiers['values']['sku_alterno'],
                'upc' => $identifiers['values']['upc'],
                'ean' => $identifiers['values']['ean'],
                'gtin' => $identifiers['values']['gtin'],
                'codigo_fabricante' =>
                    $identifiers['values']['codigo_fabricante'],
                'modelo' => $identifiers['values']['modelo'],
                'tipo_producto_id' => $type['id'],
                'unidad_medida_id' => $unitId,
                'moneda_id' => $currencyId,
                'linea_producto_id' => $lineId,
                'marca_id' => $brandId,
                'clasificacion_producto_id' => $classificationId,
                'clave_sat_id' => $satKeyId,
                'unidad_sat_id' => $satUnitId,
                'peso_kg' => $physical['peso_kg']['value'],
                'largo_cm' => $physical['largo_cm']['value'],
                'ancho_cm' => $physical['ancho_cm']['value'],
                'alto_cm' => $physical['alto_cm']['value'],
                'controla_series' => $controls['controla_series'],
                'controla_lotes' => $controls['controla_lotes'],
                'controla_pedimentos' => $controls['controla_pedimentos'],
            ],
            'tax_ids' => $taxIds,
            'barcodes' => $barcodes,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     values: array{
     *         sku: string|null,
     *         sku_alterno: string|null,
     *         upc: string|null,
     *         ean: string|null,
     *         gtin: string|null,
     *         codigo_fabricante: string|null,
     *         modelo: string|null
     *     },
     *     errors: array<string, string>
     * }
     */
    private function identifiers(array $input): array
    {
        $values = [
            'sku' => $this->nullableUpperText($input, 'sku'),
            'sku_alterno' => $this->nullableUpperText($input, 'sku_alterno'),
            'upc' => $this->nullableText($input, 'upc'),
            'ean' => $this->nullableText($input, 'ean'),
            'gtin' => $this->nullableText($input, 'gtin'),
            'codigo_fabricante' =>
                $this->nullableUpperText($input, 'codigo_fabricante'),
            'modelo' => $this->nullableText($input, 'modelo'),
        ];
        $errors = [];

        foreach (['sku' => 'SKU', 'sku_alterno' => 'SKU alterno'] as $field => $label) {
            if (
                $values[$field] !== null
                && preg_match('/^[A-Z0-9._\/-]{1,40}$/', $values[$field]) !== 1
            ) {
                $errors[$field] =
                    $label . ' inválido. Usa letras, números y . _ - /.';
            }
        }

        if (
            $values['upc'] !== null
            && preg_match('/^[0-9]{12}$/', $values['upc']) !== 1
        ) {
            $errors['upc'] = 'UPC inválido. Usa exactamente 12 dígitos.';
        }

        if (
            $values['ean'] !== null
            && preg_match('/^(?:[0-9]{8}|[0-9]{13})$/', $values['ean']) !== 1
        ) {
            $errors['ean'] = 'EAN inválido. Usa 8 o 13 dígitos.';
        }

        if (
            $values['gtin'] !== null
            && preg_match('/^(?:[0-9]{8}|[0-9]{12}|[0-9]{13}|[0-9]{14})$/', $values['gtin']) !== 1
        ) {
            $errors['gtin'] = 'GTIN inválido. Usa 8, 12, 13 o 14 dígitos.';
        }

        if (
            $values['codigo_fabricante'] !== null
            && preg_match('/^[A-Z0-9._\/-]{1,60}$/', $values['codigo_fabricante']) !== 1
        ) {
            $errors['codigo_fabricante'] =
                'Código fabricante inválido. Usa letras, números y . _ - /.';
        }

        if (
            $values['modelo'] !== null
            && $this->length($values['modelo']) > 80
        ) {
            $errors['modelo'] = 'Modelo demasiado largo. Máximo 80 caracteres.';
        }

        return ['values' => $values, 'errors' => $errors];
    }

    private function validatedStoredId(string $productId): string
    {
        if (preg_match('/^[A-Z0-9]{1,16}$/', $productId) !== 1) {
            throw new ProductValidationException([
                'id_producto' => 'El producto solicitado no es válido.',
            ]);
        }

        return $productId;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function nullableText(array $input, string $key): ?string
    {
        $value = $this->text($input, $key);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function nullableUpperText(array $input, string $key): ?string
    {
        $value = $this->nullableText($input, $key);

        return $value === null ? null : strtoupper($value);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function optionalId(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (
            (!is_string($value) && !is_int($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
        ) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return list<int>|null
     */
    private function idList(mixed $value): ?array
    {
        if ($value === '' || $value === null) {
            return [];
        }
        if (!is_array($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $item) {
            if (
                (!is_string($item) && !is_int($item))
                || preg_match('/^[1-9]\d*$/', (string) $item) !== 1
            ) {
                return null;
            }
            $result[] = (int) $item;
        }

        return $result;
    }

    /**
     * @return list<string>|null
     */
    private function barcodes(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        $lines = preg_split('/\R/', $value);

        if ($lines === false) {
            return null;
        }

        $barcodes = [];

        foreach ($lines as $line) {
            $barcode = strtoupper(trim($line));

            if ($barcode === '') {
                continue;
            }
            if (preg_match('/^[A-Z0-9]{1,64}$/', $barcode) !== 1) {
                return null;
            }
            $barcodes[] = $barcode;
        }

        return $barcodes;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{value: string|null, error: string|null}
     */
    private function decimal(
        array $input,
        string $key,
        int $integerDigits,
        int $scale,
        string $label
    ): array {
        $raw = $input[$key] ?? '';

        if ($raw === '' || $raw === null) {
            return ['value' => null, 'error' => null];
        }
        if (!is_string($raw) && !is_int($raw)) {
            return [
                'value' => null,
                'error' => $label . ' debe ser un decimal positivo válido.',
            ];
        }

        $value = trim((string) $raw);
        $pattern = '/^(?:0|[1-9]\d{0,'
            . ($integerDigits - 1)
            . '})(?:\.\d{1,'
            . $scale
            . '})?$/';

        if (
            preg_match($pattern, $value) !== 1
            || preg_match('/[1-9]/', $value) !== 1
        ) {
            return [
                'value' => null,
                'error' => $label . ' debe ser un decimal positivo válido.',
            ];
        }

        return ['value' => $value, 'error' => null];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{value: int, error: bool}
     */
    private function booleanFlag(array $input, string $key): array
    {
        if (!array_key_exists($key, $input)) {
            return ['value' => 0, 'error' => false];
        }

        $value = $input[$key];

        if ($value === 1 || $value === '1') {
            return ['value' => 1, 'error' => false];
        }
        if ($value === 0 || $value === '0') {
            return ['value' => 0, 'error' => false];
        }

        return ['value' => 0, 'error' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    private function priceRows(array $input, string $key): array
    {
        $raw = $input[$key] ?? [];

        if ($raw === '' || $raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            throw new ProductValidationException([
                $key => 'Los precios deben enviarse como una lista.',
            ]);
        }

        $rows = [];

        foreach (array_values($raw) as $index => $row) {
            if (!is_array($row)) {
                throw new ProductValidationException([
                    $key . '.' . $index =>
                        'Cada precio debe enviarse como un arreglo.',
                ]);
            }

            $listId = $row['lista_precio_id'] ?? null;
            $listPrice = $row['precio_lista'] ?? null;
            $minimumPrice = $row['precio_minimo'] ?? null;

            if (
                ($listId === null || $listId === '')
                && ($listPrice === null || $listPrice === '')
                && ($minimumPrice === null || $minimumPrice === '')
            ) {
                continue;
            }

            $rows[] = [
                'lista_precio_id' => $listId,
                'precio_lista' => $listPrice,
                'precio_minimo' => $minimumPrice,
            ];
        }

        return $rows;
    }

    private function priceService(): ProductPriceService
    {
        if ($this->prices === null) {
            throw new ProductValidationException([
                'precios' =>
                    'El servicio de precios no está disponible para productos.',
            ]);
        }

        return $this->prices;
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId < 1) {
            throw new \InvalidArgumentException('A valid actor is required.');
        }
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }

    private function convertDatabaseError(PDOException $exception): void
    {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new ProductValidationException([
                'id_producto' =>
                    'El ID, SKU o un código de barras ya existe.',
            ]);
        }
    }
}
