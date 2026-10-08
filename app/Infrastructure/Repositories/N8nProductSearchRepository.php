<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class N8nProductSearchRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @param list<string> $codes
     * @return list<array<string, mixed>>
     */
    public function byNormalizedCode(array $codes): array
    {
        return $this->findByNormalizedColumn('p.id_producto', $codes);
    }

    /** @param list<string> $codes @return list<array<string, mixed>> */
    public function byModel(array $codes): array
    {
        return $this->findByNormalizedColumn('p.modelo', $codes);
    }

    /** @param list<string> $codes @return list<array<string, mixed>> */
    public function byPartNumber(array $codes): array
    {
        return $this->findByNormalizedColumn('p.numero_parte', $codes);
    }

    /** @param list<string> $codes @return list<array<string, mixed>> */
    public function byManufacturerSku(array $codes): array
    {
        return $this->findByNormalizedColumn('p.sku_fabricante', $codes);
    }

    /** @return list<array<string, mixed>> */
    public function byExactDescription(string $description, bool $long = false): array
    {
        $column = $long ? 'p.descripcion_larga' : 'p.descripcion';
        $statement = $this->connection->pdo()->prepare(
            $this->selectSql() . " AND LOWER(TRIM($column)) = LOWER(:description)"
                . ' ORDER BY p.id LIMIT 10'
        );
        $statement->execute(['description' => trim($description)]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<string> $terms @return list<array<string, mixed>> */
    public function partial(array $terms): array
    {
        $terms = array_values(array_unique(array_filter(
            array_map('trim', $terms),
            static fn (string $term): bool => strlen($term) >= 2
        )));
        if ($terms === []) {
            return [];
        }

        $fields = [
            'p.id_producto', 'p.modelo', 'p.numero_parte', 'p.sku_fabricante',
            'p.descripcion', 'p.descripcion_larga',
        ];
        $conditions = [];
        $parameters = [];
        foreach ($terms as $index => $term) {
            foreach ($fields as $fieldIndex => $field) {
                $key = 'term_' . $index . '_' . $fieldIndex;
                $conditions[] = $field . ' LIKE :' . $key;
                $parameters[$key] = '%' . $term . '%';
            }
        }
        $statement = $this->connection->pdo()->prepare(
            $this->selectSql() . ' AND (' . implode(' OR ', $conditions) . ')'
                . ' ORDER BY p.descripcion, p.id LIMIT 10'
        );
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<string> $codes @return list<array<string, mixed>> */
    private function findByNormalizedColumn(string $column, array $codes): array
    {
        // Callers pass only repository-owned column names.
        $codes = array_values(array_unique(array_filter($codes)));
        if ($codes === []) {
            return [];
        }

        $conditions = [];
        $parameters = [];
        foreach ($codes as $index => $code) {
            $key = 'code_' . $index;
            $conditions[] = "UPPER(REPLACE(REPLACE(TRIM(COALESCE($column, '')), '-', ''), ' ', '')) = :$key";
            $parameters[$key] = $code;
        }

        $statement = $this->connection->pdo()->prepare(
            $this->selectSql() . ' AND (' . implode(' OR ', $conditions) . ')'
                . ' ORDER BY p.id LIMIT 10'
        );
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function selectSql(): string
    {
        return <<<'SQL'
            SELECT
                p.id,
                p.id_producto,
                p.descripcion,
                p.descripcion_larga,
                p.modelo,
                p.numero_parte,
                p.sku_fabricante,
                m.nombre AS marca,
                u.nombre AS unidad,
                u.abreviatura AS unidad_abreviatura
            FROM productos p
            LEFT JOIN marcas m ON m.id = p.marca_id AND m.deleted_at IS NULL
            LEFT JOIN unidades_medida u
                ON u.id = p.unidad_medida_id AND u.deleted_at IS NULL
            WHERE p.activo = 1
              AND p.deleted_at IS NULL
            SQL;
    }
}
