<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Products\Import\ProductImportBusinessLookup;
use PDO;

final class PdoProductImportBusinessLookup implements ProductImportBusinessLookup
{
    private const CATALOG_TABLES = [
        'tipo_producto_codigo' => 'tipos_producto',
        'unidad_medida_codigo' => 'unidades_medida',
        'moneda_codigo' => 'monedas',
        'linea_producto_codigo' => 'lineas_producto',
        'marca_codigo' => 'marcas',
        'clasificacion_producto_codigo' => 'clasificaciones_producto',
        'clave_sat_codigo' => 'claves_sat',
        'unidad_sat_codigo' => 'unidades_sat',
        'impuestos_codigos' => 'impuestos',
    ];

    private int $queryCount = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, list<string>> $codesByField
     * @return array<string, array<string, array{id: int, code: string}>>
     */
    public function resolveCatalogs(array $codesByField): array
    {
        $result = [];
        foreach (self::CATALOG_TABLES as $field => $table) {
            $codes = array_values(array_unique($codesByField[$field] ?? []));
            $result[$field] = [];
            if ($codes === []) {
                continue;
            }
            [$placeholders, $params] = $this->parameters($codes, 'code');
            $statement = $this->pdo->prepare(
                'SELECT id, codigo FROM ' . $table
                . ' WHERE activo = 1 AND eliminado_en IS NULL'
                . ' AND codigo IN (' . implode(', ', $placeholders) . ')'
            );
            ++$this->queryCount;
            $statement->execute($params);
            foreach ($statement->fetchAll() as $row) {
                $code = (string) $row['codigo'];
                $result[$field][$code] = ['id' => (int) $row['id'], 'code' => $code];
            }
        }
        return $result;
    }

    /** @param list<string> $productIds @return array<string, true> */
    public function existingProductIds(array $productIds): array
    {
        return $this->existingValues(
            'SELECT id_producto AS value FROM productos WHERE id_producto IN (%s)',
            $productIds,
            'product',
        );
    }

    /** @param list<string> $skus @return array<string, true> */
    public function conflictingSkus(array $skus): array
    {
        return $this->existingValues(
            'SELECT sku AS value FROM productos WHERE sku IN (%s)',
            $skus,
            'sku',
        );
    }

    /** @param list<string> $barcodes @return array<string, true> */
    public function conflictingBarcodes(array $barcodes): array
    {
        return $this->existingValues(
            'SELECT codigo_barras AS value FROM producto_codigos_barras WHERE codigo_barras IN (%s)',
            $barcodes,
            'barcode',
        );
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    /** @param list<string> $values @return array<string, true> */
    private function existingValues(string $sql, array $values, string $prefix): array
    {
        $values = array_values(array_unique($values));
        if ($values === []) {
            return [];
        }
        [$placeholders, $params] = $this->parameters($values, $prefix);
        $statement = $this->pdo->prepare(sprintf($sql, implode(', ', $placeholders)));
        ++$this->queryCount;
        $statement->execute($params);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $result[(string) $value] = true;
        }
        return $result;
    }

    /** @param list<string> $values @return array{list<string>, array<string, string>} */
    private function parameters(array $values, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach ($values as $index => $value) {
            $name = $prefix . '_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $value;
        }
        return [$placeholders, $params];
    }
}
