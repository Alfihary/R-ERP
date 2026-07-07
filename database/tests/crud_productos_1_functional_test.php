<?php

declare(strict_types=1);

namespace Database\Tests;

use App\Domain\Products\ProductService;
use App\Domain\Products\ProductValidationException;
use PDO;

final class CrudProductos1FunctionalTest
{
    public function run(PDO $pdo, ProductService $products): array
    {
        if ($pdo->inTransaction()) {
            throw new \RuntimeException(
                'CRUD-PRODUCTOS-1 functional test requires a clean transaction.'
            );
        }

        $before = $this->counts($pdo);
        $actorId = (int) $pdo->query(
            "SELECT id FROM usuarios
             WHERE activo = 1 AND eliminado_en IS NULL
             ORDER BY id LIMIT 1"
        )->fetchColumn();
        $unitId = (int) $pdo->query(
            "SELECT id FROM unidades_medida
             WHERE codigo = 'PIEZA' AND activo = 1 AND eliminado_en IS NULL"
        )->fetchColumn();
        $currencyId = (int) $pdo->query(
            "SELECT id FROM monedas
             WHERE codigo = 'MXN' AND activo = 1 AND eliminado_en IS NULL"
        )->fetchColumn();
        $taxIds = array_map(
            'intval',
            $pdo->query(
                "SELECT id FROM impuestos
                 WHERE activo = 1 AND eliminado_en IS NULL
                 ORDER BY id LIMIT 2"
            )->fetchAll(PDO::FETCH_COLUMN)
        );
        $typeCodes = $pdo->query(
            "SELECT codigo FROM tipos_producto
             WHERE activo = 1 AND eliminado_en IS NULL
             ORDER BY codigo"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (
            $actorId < 1
            || $unitId < 1
            || $currencyId < 1
            || count($taxIds) < 2
            || $typeCodes !== ['KIT', 'PRODUCTO', 'SERVICIO']
        ) {
            throw new \RuntimeException(
                'CRUD-PRODUCTOS-1 functional fixtures are unavailable.'
            );
        }

        $pdo->beginTransaction();

        try {
            $lineId = $this->insertCatalog(
                $pdo,
                'lineas_producto',
                'QAPRODLINE',
                'Línea QA productos',
                $actorId
            );
            $brandId = $this->insertCatalog(
                $pdo,
                'marcas',
                'QAPRODBRAND',
                'Marca QA productos',
                $actorId
            );
            $classificationId = $this->insertClassification($pdo, $actorId);
            $base = [
                'id_producto' => 'qaprod001',
                'descripcion' => 'Producto QA',
                'descripcion_larga' => 'Descripción funcional de prueba.',
                'tipo_producto' => 'PRODUCTO',
                'unidad_medida_id' => (string) $unitId,
                'moneda_id' => (string) $currencyId,
                'linea_producto_id' => (string) $lineId,
                'marca_id' => (string) $brandId,
                'clasificacion_producto_id' => (string) $classificationId,
                'peso_kg' => '12.3456',
                'largo_cm' => '100.125',
                'ancho_cm' => '50.500',
                'alto_cm' => '25.250',
                'controla_series' => '1',
                'controla_lotes' => '1',
                'controla_pedimentos' => '1',
                'impuestos' => [(string) $taxIds[0]],
                'codigos_barras' => "750000000001\n750000000002",
            ];

            try {
                $createdId = $products->create($base, $actorId);
            } catch (ProductValidationException $exception) {
                throw new \RuntimeException(
                    'Initial product fixture failed: '
                    . json_encode(
                        $exception->errors(),
                        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    )
                );
            }

            if ($createdId !== 'QAPROD001') {
                throw new \RuntimeException(
                    'Lowercase product identity was not normalized.'
                );
            }

            $created = $products->get($createdId);

            if (
                count($created['taxes'] ?? []) !== 1
                || count($created['barcodes'] ?? []) !== 2
                || ($created['tipo_codigo'] ?? '') !== 'PRODUCTO'
                || (string) ($created['peso_kg'] ?? '') !== '12.3456'
                || (string) ($created['largo_cm'] ?? '') !== '100.125'
                || (int) ($created['controla_series'] ?? 0) !== 1
                || (int) ($created['controla_lotes'] ?? 0) !== 1
                || (int) ($created['controla_pedimentos'] ?? 0) !== 1
            ) {
                throw new \RuntimeException(
                    'Product children were not created transactionally.'
                );
            }

            $expectedFailures = [];
            $cases = [
                'invalid_id' => array_replace(
                    $base,
                    ['id_producto' => 'QA-PROD']
                ),
                'duplicate_id' => array_replace(
                    $base,
                    ['id_producto' => 'QAPROD001']
                ),
                'unknown_type' => array_replace($base, [
                    'id_producto' => 'QATYPE',
                    'tipo_producto' => 'INEXISTENTE',
                ]),
                'empty_description' => array_replace($base, [
                    'id_producto' => 'QAEMPTY',
                    'descripcion' => '',
                ]),
                'long_description' => array_replace($base, [
                    'id_producto' => 'QALONG',
                    'descripcion' => str_repeat('X', 41),
                ]),
                'long_description_detail' => array_replace($base, [
                    'id_producto' => 'QALONGDETAIL',
                    'descripcion_larga' => str_repeat('X', 256),
                ]),
                'unknown_unit' => array_replace($base, [
                    'id_producto' => 'QAUNIT',
                    'unidad_medida_id' => '999999999',
                ]),
                'unknown_currency' => array_replace($base, [
                    'id_producto' => 'QACURRENCY',
                    'moneda_id' => '999999999',
                ]),
                'unknown_line' => array_replace($base, [
                    'id_producto' => 'QALINE',
                    'linea_producto_id' => '999999999',
                ]),
                'unknown_brand' => array_replace($base, [
                    'id_producto' => 'QABRAND',
                    'marca_id' => '999999999',
                ]),
                'unknown_classification' => array_replace($base, [
                    'id_producto' => 'QACLASS',
                    'clasificacion_producto_id' => '999999999',
                ]),
                'unknown_tax' => array_replace($base, [
                    'id_producto' => 'QATAX',
                    'impuestos' => ['999999999'],
                ]),
                'duplicate_tax' => array_replace($base, [
                    'id_producto' => 'QATAXDUP',
                    'impuestos' => [
                        (string) $taxIds[0],
                        (string) $taxIds[0],
                    ],
                ]),
                'duplicate_form_barcode' => array_replace($base, [
                    'id_producto' => 'QABARDUP',
                    'codigos_barras' => "750000000003\n750000000003",
                ]),
                'duplicate_global_barcode' => array_replace($base, [
                    'id_producto' => 'QABARGLOBAL',
                    'codigos_barras' => '750000000001',
                ]),
                'weight_zero' => array_replace($base, [
                    'id_producto' => 'QAWEIGHTZERO',
                    'peso_kg' => '0',
                ]),
                'weight_negative' => array_replace($base, [
                    'id_producto' => 'QAWEIGHTNEG',
                    'peso_kg' => '-1',
                ]),
                'weight_invalid' => array_replace($base, [
                    'id_producto' => 'QAWEIGHTBAD',
                    'peso_kg' => '1,25',
                ]),
                'length_zero' => array_replace($base, [
                    'id_producto' => 'QALENGTHZERO',
                    'largo_cm' => '0',
                ]),
                'length_negative' => array_replace($base, [
                    'id_producto' => 'QALENGTHNEG',
                    'largo_cm' => '-1',
                ]),
                'width_zero' => array_replace($base, [
                    'id_producto' => 'QAWIDTHZERO',
                    'ancho_cm' => '0.000',
                ]),
                'width_negative' => array_replace($base, [
                    'id_producto' => 'QAWIDTHNEG',
                    'ancho_cm' => '-0.001',
                ]),
                'height_zero' => array_replace($base, [
                    'id_producto' => 'QAHEIGHTZERO',
                    'alto_cm' => '0',
                ]),
                'height_negative' => array_replace($base, [
                    'id_producto' => 'QAHEIGHTNEG',
                    'alto_cm' => '-1',
                ]),
                'series_manipulated' => array_replace($base, [
                    'id_producto' => 'QASERIESBAD',
                    'controla_series' => '2',
                ]),
                'lots_manipulated' => array_replace($base, [
                    'id_producto' => 'QALOTSBAD',
                    'controla_lotes' => ['1'],
                ]),
                'customs_manipulated' => array_replace($base, [
                    'id_producto' => 'QACUSTOMSBAD',
                    'controla_pedimentos' => 'true',
                ]),
            ];

            foreach ($cases as $label => $input) {
                $this->expectValidation(
                    static fn () => $products->create($input, $actorId),
                    $label
                );
                $expectedFailures[] = $label;
            }

            $inactiveType = $pdo->prepare(
                "UPDATE tipos_producto SET activo = 0
                 WHERE codigo = 'KIT'"
            );
            $inactiveType->execute();
            $this->expectValidation(
                static fn () => $products->create(array_replace($base, [
                    'id_producto' => 'QAINACTTYPE',
                    'tipo_producto' => 'KIT',
                ]), $actorId),
                'inactive_type'
            );
            $expectedFailures[] = 'inactive_type';
            $pdo->exec(
                "UPDATE tipos_producto SET activo = 1 WHERE codigo = 'KIT'"
            );

            foreach ([
                'service_weight' => ['peso_kg' => '1'],
                'service_length' => ['largo_cm' => '1'],
                'service_width' => ['ancho_cm' => '1'],
                'service_height' => ['alto_cm' => '1'],
                'service_series' => ['controla_series' => '1'],
                'service_lots' => ['controla_lotes' => '1'],
                'service_customs' => ['controla_pedimentos' => '1'],
            ] as $label => $incompatible) {
                $serviceInput = array_replace($base, [
                    'id_producto' => 'QA' . strtoupper(substr($label, 8, 10)),
                    'tipo_producto' => 'SERVICIO',
                    'peso_kg' => '',
                    'largo_cm' => '',
                    'ancho_cm' => '',
                    'alto_cm' => '',
                    'controla_series' => '0',
                    'controla_lotes' => '0',
                    'controla_pedimentos' => '0',
                    'codigos_barras' => '',
                ], $incompatible);
                $this->expectValidation(
                    static fn () => $products->create(
                        $serviceInput,
                        $actorId
                    ),
                    $label
                );
                $expectedFailures[] = $label;
            }

            $serviceId = $products->create(array_replace($base, [
                'id_producto' => 'QASERVICE',
                'descripcion' => 'Servicio QA',
                'tipo_producto' => 'SERVICIO',
                'peso_kg' => '',
                'largo_cm' => '',
                'ancho_cm' => '',
                'alto_cm' => '',
                'controla_series' => '0',
                'controla_lotes' => '0',
                'controla_pedimentos' => '0',
                'codigos_barras' => '',
            ]), $actorId);
            $service = $products->get($serviceId);

            if (
                ($service['tipo_codigo'] ?? '') !== 'SERVICIO'
                || $service['peso_kg'] !== null
                || $service['largo_cm'] !== null
                || $service['ancho_cm'] !== null
                || $service['alto_cm'] !== null
                || (int) $service['controla_series'] !== 0
                || (int) $service['controla_lotes'] !== 0
                || (int) $service['controla_pedimentos'] !== 0
            ) {
                throw new \RuntimeException(
                    'A service persisted incompatible product data.'
                );
            }

            $kitId = $products->create(array_replace($base, [
                'id_producto' => 'QAKIT',
                'descripcion' => 'Kit QA',
                'tipo_producto' => 'KIT',
                'peso_kg' => '5.5000',
                'largo_cm' => '20.000',
                'ancho_cm' => '',
                'alto_cm' => '',
                'controla_series' => '0',
                'controla_lotes' => '1',
                'controla_pedimentos' => '0',
                'codigos_barras' => '',
            ]), $actorId);
            $kit = $products->get($kitId);

            if (
                ($kit['tipo_codigo'] ?? '') !== 'KIT'
                || (string) ($kit['peso_kg'] ?? '') !== '5.5000'
                || (int) ($kit['controla_lotes'] ?? 0) !== 1
            ) {
                throw new \RuntimeException(
                    'A kit did not persist its approved future policy.'
                );
            }

            $this->expectValidation(
                static fn () => $products->update(
                    $createdId,
                    array_replace($base, ['id_producto' => 'QACHANGED']),
                    $actorId
                ),
                'immutable_identity'
            );
            $expectedFailures[] = 'immutable_identity';

            $clearPhysical = [
                'peso_kg' => '',
                'largo_cm' => '',
                'ancho_cm' => '',
                'alto_cm' => '',
            ];
            $clearControls = [
                'controla_series' => '0',
                'controla_lotes' => '0',
                'controla_pedimentos' => '0',
            ];
            $this->expectValidation(
                static fn () => $products->update(
                    $createdId,
                    array_replace(
                        $base,
                        ['id_producto' => $createdId, 'tipo_producto' => 'SERVICIO'],
                        $clearControls
                    ),
                    $actorId
                ),
                'product_to_service_with_physical'
            );
            $expectedFailures[] = 'product_to_service_with_physical';
            $this->expectValidation(
                static fn () => $products->update(
                    $createdId,
                    array_replace(
                        $base,
                        ['id_producto' => $createdId, 'tipo_producto' => 'SERVICIO'],
                        $clearPhysical
                    ),
                    $actorId
                ),
                'product_to_service_with_controls'
            );
            $expectedFailures[] = 'product_to_service_with_controls';

            $products->update(
                $createdId,
                array_replace(
                    $base,
                    ['id_producto' => $createdId, 'tipo_producto' => 'SERVICIO'],
                    $clearPhysical,
                    $clearControls
                ),
                $actorId
            );
            $products->update($createdId, array_replace($base, [
                'id_producto' => $createdId,
                'tipo_producto' => 'PRODUCTO',
            ]), $actorId);
            $products->update($createdId, array_replace($base, [
                'id_producto' => $createdId,
                'tipo_producto' => 'KIT',
                'peso_kg' => '9.2500',
                'largo_cm' => '80.125',
                'ancho_cm' => '40.250',
                'alto_cm' => '10.500',
                'controla_series' => '0',
                'controla_lotes' => '1',
                'controla_pedimentos' => '0',
            ]), $actorId);

            $updatedInput = array_replace($base, [
                'id_producto' => $createdId,
                'descripcion' => 'Producto QA actualizado',
                'descripcion_larga' => 'Detalle actualizado.',
                'tipo_producto' => 'PRODUCTO',
                'peso_kg' => '20.1250',
                'largo_cm' => '110.500',
                'ancho_cm' => '60.250',
                'alto_cm' => '30.125',
                'controla_series' => '0',
                'controla_lotes' => '1',
                'controla_pedimentos' => '0',
                'impuestos' => [(string) $taxIds[1]],
                'codigos_barras' => '750000000099',
            ]);
            $products->update($createdId, $updatedInput, $actorId);
            $updated = $products->get($createdId);

            if (
                $updated['id_producto'] !== $createdId
                || $updated['descripcion'] !== 'Producto QA actualizado'
                || ($updated['tipo_codigo'] ?? '') !== 'PRODUCTO'
                || (string) ($updated['peso_kg'] ?? '') !== '20.1250'
                || (string) ($updated['largo_cm'] ?? '') !== '110.500'
                || (int) ($updated['controla_series'] ?? 1) !== 0
                || (int) ($updated['controla_lotes'] ?? 0) !== 1
                || (int) ($updated['controla_pedimentos'] ?? 1) !== 0
                || count($updated['taxes'] ?? []) !== 1
                || (int) ($updated['taxes'][0]['id'] ?? 0) !== $taxIds[1]
                || count($updated['barcodes'] ?? []) !== 1
                || ($updated['barcodes'][0]['codigo_barras'] ?? '')
                    !== '750000000099'
            ) {
                throw new \RuntimeException(
                    'Product update was not applied transactionally.'
                );
            }

            $products->setActive($createdId, false, $actorId);
            $inactive = $products->get($createdId);
            $products->setActive($createdId, true, $actorId);
            $active = $products->get($createdId);

            if (
                (int) $inactive['activo'] !== 0
                || (int) $active['activo'] !== 1
            ) {
                throw new \RuntimeException(
                    'Product state changes are invalid.'
                );
            }

            $filterResults = [
                'id' => count($products->search(['search' => 'QAPROD001'])),
                'description' => count($products->search([
                    'search' => 'actualizado',
                ])),
                'active' => count($products->search(['status' => 'active'])),
                'type_product' => count($products->search([
                    'type_code' => 'PRODUCTO',
                ])),
                'type_service' => count($products->search([
                    'type_code' => 'SERVICIO',
                ])),
                'type_kit' => count($products->search([
                    'type_code' => 'KIT',
                ])),
                'combined_type' => count($products->search([
                    'search' => 'Servicio QA',
                    'type_code' => 'SERVICIO',
                ])),
                'line' => count($products->search([
                    'line_id' => (string) $lineId,
                ])),
                'brand' => count($products->search([
                    'brand_id' => (string) $brandId,
                ])),
                'classification' => count($products->search([
                    'classification_id' => (string) $classificationId,
                ])),
            ];

            foreach ($filterResults as $count) {
                if ($count < 1) {
                    throw new \RuntimeException(
                        'A required product filter did not find the fixture.'
                    );
                }
            }

            $pdo->rollBack();

            if ($this->counts($pdo) !== $before) {
                throw new \RuntimeException(
                    'CRUD-PRODUCTOS-1 functional data was not rolled back.'
                );
            }

            return [
                'normalized_id' => $createdId,
                'creation' => 'pass',
                'validation_failures' => $expectedFailures,
                'transactional_tax_update' => true,
                'transactional_barcode_update' => true,
                'immutable_identity' => true,
                'product_type_rules' => [
                    'product' => true,
                    'service' => true,
                    'kit_future_policy' => true,
                ],
                'physical_attributes' => true,
                'tracking_flags' => true,
                'deactivate_activate' => true,
                'filters' => $filterResults,
                'persistent_counts_before' => $before,
                'persistent_counts_after' => $this->counts($pdo),
                'cleanup' => 'transaction_rolled_back',
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param callable(): mixed $operation
     */
    private function expectValidation(
        callable $operation,
        string $label
    ): void {
        try {
            $operation();
        } catch (ProductValidationException) {
            return;
        }

        throw new \RuntimeException(
            'Expected product validation failure was accepted: ' . $label
        );
    }

    private function insertCatalog(
        PDO $pdo,
        string $table,
        string $code,
        string $name,
        int $actorId
    ): int {
        $sql = match ($table) {
            'lineas_producto' =>
                'INSERT INTO lineas_producto (
                    codigo, nombre, activo, creado_por, actualizado_por
                 ) VALUES (
                    :codigo, :nombre, 1, :creado_por, :actualizado_por
                 )',
            'marcas' =>
                'INSERT INTO marcas (
                    codigo, nombre, activo, creado_por, actualizado_por
                 ) VALUES (
                    :codigo, :nombre, 1, :creado_por, :actualizado_por
                 )',
            default => throw new \InvalidArgumentException(
                'Unsupported functional catalog fixture.'
            ),
        };
        $statement = $pdo->prepare($sql);
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertClassification(PDO $pdo, int $actorId): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO clasificaciones_producto (
                parent_id,
                codigo,
                nombre,
                activo,
                creado_por,
                actualizado_por
             ) VALUES (
                NULL,
                :codigo,
                :nombre,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'codigo' => 'QAPRODCLASS',
            'nombre' => 'Clasificación QA productos',
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        $counts = [];

        foreach ([
            'productos',
            'producto_codigos_barras',
            'producto_impuestos',
            'lineas_producto',
            'marcas',
            'clasificaciones_producto',
        ] as $table) {
            $counts[$table] = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn();
        }

        return $counts;
    }
}
