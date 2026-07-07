<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class ProductRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @param array{
     *     search?: string,
     *     status?: string,
     *     type_code?: string,
     *     line_id?: int|null,
     *     brand_id?: int|null,
     *     classification_id?: int|null
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters): array
    {
        $conditions = ['p.eliminado_en IS NULL'];
        $parameters = [];

        if (($filters['search'] ?? '') !== '') {
            $conditions[] =
                '(p.id_producto LIKE :search_id '
                . 'OR p.descripcion LIKE :search_description)';
            $term = '%' . $filters['search'] . '%';
            $parameters['search_id'] = $term;
            $parameters['search_description'] = $term;
        }

        if (($filters['status'] ?? '') === 'active') {
            $conditions[] = 'p.activo = 1';
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $conditions[] = 'p.activo = 0';
        }

        if (($filters['type_code'] ?? '') !== '') {
            $conditions[] = 'tp.codigo = :type_code';
            $parameters['type_code'] = $filters['type_code'];
        }

        foreach ([
            'line_id' => 'p.linea_producto_id',
            'brand_id' => 'p.marca_id',
            'classification_id' => 'p.clasificacion_producto_id',
        ] as $key => $column) {
            if (($filters[$key] ?? null) !== null) {
                $conditions[] = $column . ' = :' . $key;
                $parameters[$key] = $filters[$key];
            }
        }

        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                p.id_producto,
                p.descripcion,
                p.activo,
                tp.codigo AS tipo_codigo,
                tp.nombre AS tipo_nombre,
                u.codigo AS unidad_codigo,
                l.nombre AS linea_nombre,
                m.nombre AS marca_nombre,
                c.nombre AS clasificacion_nombre
            FROM productos p
            INNER JOIN tipos_producto tp ON tp.id = p.tipo_producto_id
            INNER JOIN unidades_medida u ON u.id = p.unidad_medida_id
            LEFT JOIN lineas_producto l ON l.id = p.linea_producto_id
            LEFT JOIN marcas m ON m.id = p.marca_id
            LEFT JOIN clasificaciones_producto c
                ON c.id = p.clasificacion_producto_id
            WHERE
            SQL
            . ' ' . implode(' AND ', $conditions)
            . ' ORDER BY p.descripcion, p.id_producto'
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $productId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                p.id_producto,
                p.descripcion,
                p.descripcion_larga,
                p.tipo_producto_id,
                tp.codigo AS tipo_codigo,
                tp.nombre AS tipo_nombre,
                p.unidad_medida_id,
                u.codigo AS unidad_codigo,
                u.nombre AS unidad_nombre,
                p.moneda_id,
                mo.codigo AS moneda_codigo,
                mo.nombre AS moneda_nombre,
                p.linea_producto_id,
                l.nombre AS linea_nombre,
                p.marca_id,
                m.nombre AS marca_nombre,
                p.clasificacion_producto_id,
                c.nombre AS clasificacion_nombre,
                p.peso_kg,
                p.largo_cm,
                p.ancho_cm,
                p.alto_cm,
                p.controla_series,
                p.controla_lotes,
                p.controla_pedimentos,
                p.activo
            FROM productos p
            INNER JOIN tipos_producto tp ON tp.id = p.tipo_producto_id
            INNER JOIN unidades_medida u ON u.id = p.unidad_medida_id
            LEFT JOIN monedas mo ON mo.id = p.moneda_id
            LEFT JOIN lineas_producto l ON l.id = p.linea_producto_id
            LEFT JOIN marcas m ON m.id = p.marca_id
            LEFT JOIN clasificaciones_producto c
                ON c.id = p.clasificacion_producto_id
            WHERE p.id_producto = :id_producto
              AND p.eliminado_en IS NULL
            LIMIT 1
            SQL
        );
        $statement->execute(['id_producto' => $productId]);
        $product = $statement->fetch();

        if ($product === false) {
            return null;
        }

        $product['taxes'] = $this->taxes($productId);
        $product['barcodes'] = $this->barcodes($productId);

        return $product;
    }

    public function exists(string $productId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM productos
             WHERE id_producto = :id_producto AND eliminado_en IS NULL'
        );
        $statement->execute(['id_producto' => $productId]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function activeCatalogs(): array
    {
        return [
            'types' => $this->activeOptions(
                'SELECT id, codigo, nombre FROM tipos_producto
                 WHERE activo = 1 AND eliminado_en IS NULL ORDER BY nombre'
            ),
            'units' => $this->activeOptions(
                'SELECT id, codigo, nombre FROM unidades_medida
                 WHERE activo = 1 AND eliminado_en IS NULL ORDER BY nombre'
            ),
            'currencies' => $this->activeOptions(
                'SELECT id, codigo, nombre FROM monedas
                 WHERE activo = 1 AND eliminado_en IS NULL ORDER BY codigo'
            ),
            'lines' => $this->activeOptions(
                'SELECT id, codigo, nombre FROM lineas_producto
                 WHERE activo = 1 AND eliminado_en IS NULL ORDER BY nombre'
            ),
            'brands' => $this->activeOptions(
                'SELECT id, codigo, nombre FROM marcas
                 WHERE activo = 1 AND eliminado_en IS NULL ORDER BY nombre'
            ),
            'classifications' => $this->activeOptions(
                'SELECT id, codigo, nombre FROM clasificaciones_producto
                 WHERE activo = 1 AND eliminado_en IS NULL ORDER BY nombre'
            ),
            'taxes' => $this->activeOptions(
                'SELECT id, codigo, nombre FROM impuestos
                 WHERE activo = 1 AND eliminado_en IS NULL ORDER BY nombre'
            ),
        ];
    }

    public function activeUnitExists(int $id): bool
    {
        return $this->activeIdExists('unidades_medida', $id);
    }

    public function activeCurrencyExists(int $id): bool
    {
        return $this->activeIdExists('monedas', $id);
    }

    public function activeLineExists(int $id): bool
    {
        return $this->activeIdExists('lineas_producto', $id);
    }

    public function activeBrandExists(int $id): bool
    {
        return $this->activeIdExists('marcas', $id);
    }

    public function activeClassificationExists(int $id): bool
    {
        return $this->activeIdExists('clasificaciones_producto', $id);
    }

    public function activeTaxExists(int $id): bool
    {
        return $this->activeIdExists('impuestos', $id);
    }

    /**
     * @return array{id: int, codigo: string, nombre: string}|null
     */
    public function activeProductTypeByCode(string $code): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, codigo, nombre
             FROM tipos_producto
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $type = $statement->fetch();

        if ($type === false) {
            return null;
        }

        return [
            'id' => (int) $type['id'],
            'codigo' => (string) $type['codigo'],
            'nombre' => (string) $type['nombre'],
        ];
    }

    public function barcodeOwnedByOther(
        string $barcode,
        string $productId
    ): bool {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM producto_codigos_barras
             WHERE codigo_barras = :codigo_barras
               AND id_producto <> :id_producto'
        );
        $statement->execute([
            'codigo_barras' => $barcode,
            'id_producto' => $productId,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function create(array $data, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO productos (
                id_producto,
                descripcion,
                descripcion_larga,
                tipo_producto_id,
                unidad_medida_id,
                moneda_id,
                linea_producto_id,
                marca_id,
                clasificacion_producto_id,
                peso_kg,
                largo_cm,
                ancho_cm,
                alto_cm,
                controla_series,
                controla_lotes,
                controla_pedimentos,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :id_producto,
                :descripcion,
                :descripcion_larga,
                :tipo_producto_id,
                :unidad_medida_id,
                :moneda_id,
                :linea_producto_id,
                :marca_id,
                :clasificacion_producto_id,
                :peso_kg,
                :largo_cm,
                :ancho_cm,
                :alto_cm,
                :controla_series,
                :controla_lotes,
                :controla_pedimentos,
                1,
                :creado_por,
                :actualizado_por
            )
            SQL
        );
        $statement->execute($data + [
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function update(
        string $productId,
        array $data,
        int $actorId
    ): void {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE productos
            SET descripcion = :descripcion,
                descripcion_larga = :descripcion_larga,
                tipo_producto_id = :tipo_producto_id,
                unidad_medida_id = :unidad_medida_id,
                moneda_id = :moneda_id,
                linea_producto_id = :linea_producto_id,
                marca_id = :marca_id,
                clasificacion_producto_id = :clasificacion_producto_id,
                peso_kg = :peso_kg,
                largo_cm = :largo_cm,
                ancho_cm = :ancho_cm,
                alto_cm = :alto_cm,
                controla_series = :controla_series,
                controla_lotes = :controla_lotes,
                controla_pedimentos = :controla_pedimentos,
                actualizado_por = :actualizado_por
            WHERE id_producto = :id_producto
              AND eliminado_en IS NULL
            SQL
        );
        $statement->execute($data + [
            'actualizado_por' => $actorId,
            'id_producto' => $productId,
        ]);
    }

    /**
     * @param list<int> $taxIds
     */
    public function replaceTaxes(
        string $productId,
        array $taxIds,
        int $actorId
    ): void {
        $deactivate = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE producto_impuestos
            SET activo = 0,
                eliminado_en = CURRENT_TIMESTAMP,
                eliminado_por = :actor_id,
                actualizado_por = :actor_id_update
            WHERE id_producto = :id_producto
              AND activo = 1
            SQL
        );
        $deactivate->execute([
            'actor_id' => $actorId,
            'actor_id_update' => $actorId,
            'id_producto' => $productId,
        ]);

        $upsert = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO producto_impuestos (
                id_producto,
                impuesto_id,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :id_producto,
                :impuesto_id,
                1,
                :actor_id,
                :actor_id_update
            )
            ON DUPLICATE KEY UPDATE
                activo = 1,
                eliminado_en = NULL,
                eliminado_por = NULL,
                actualizado_por = :actor_id_duplicate
            SQL
        );

        foreach ($taxIds as $taxId) {
            $upsert->execute([
                'id_producto' => $productId,
                'impuesto_id' => $taxId,
                'actor_id' => $actorId,
                'actor_id_update' => $actorId,
                'actor_id_duplicate' => $actorId,
            ]);
        }
    }

    /**
     * @param list<string> $barcodes
     */
    public function replaceBarcodes(
        string $productId,
        array $barcodes,
        int $actorId
    ): void {
        $deactivate = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE producto_codigos_barras
            SET activo = 0,
                es_principal = 0,
                eliminado_en = CURRENT_TIMESTAMP,
                eliminado_por = :actor_id,
                actualizado_por = :actor_id_update
            WHERE id_producto = :id_producto
              AND activo = 1
            SQL
        );
        $deactivate->execute([
            'actor_id' => $actorId,
            'actor_id_update' => $actorId,
            'id_producto' => $productId,
        ]);

        $upsert = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO producto_codigos_barras (
                id_producto,
                codigo_barras,
                tipo,
                es_principal,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :id_producto,
                :codigo_barras,
                NULL,
                :es_principal,
                1,
                :actor_id,
                :actor_id_update
            )
            ON DUPLICATE KEY UPDATE
                activo = 1,
                es_principal = :es_principal_duplicate,
                eliminado_en = NULL,
                eliminado_por = NULL,
                actualizado_por = :actor_id_duplicate
            SQL
        );

        foreach ($barcodes as $index => $barcode) {
            $principal = $index === 0 ? 1 : 0;
            $upsert->execute([
                'id_producto' => $productId,
                'codigo_barras' => $barcode,
                'es_principal' => $principal,
                'actor_id' => $actorId,
                'actor_id_update' => $actorId,
                'es_principal_duplicate' => $principal,
                'actor_id_duplicate' => $actorId,
            ]);
        }
    }

    public function setActive(
        string $productId,
        bool $active,
        int $actorId
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE productos
             SET activo = :activo, actualizado_por = :actor_id
             WHERE id_producto = :id_producto
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'activo' => $active ? 1 : 0,
            'actor_id' => $actorId,
            'id_producto' => $productId,
        ]);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        $pdo = $this->connection->pdo();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $operation();

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taxes(string $productId): array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT i.id, i.codigo, i.nombre, i.tasa
            FROM producto_impuestos pi
            INNER JOIN impuestos i ON i.id = pi.impuesto_id
            WHERE pi.id_producto = :id_producto
              AND pi.activo = 1
              AND pi.eliminado_en IS NULL
            ORDER BY i.nombre
            SQL
        );
        $statement->execute(['id_producto' => $productId]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function barcodes(string $productId): array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT codigo_barras, es_principal
            FROM producto_codigos_barras
            WHERE id_producto = :id_producto
              AND activo = 1
              AND eliminado_en IS NULL
            ORDER BY es_principal DESC, codigo_barras
            SQL
        );
        $statement->execute(['id_producto' => $productId]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeOptions(string $sql): array
    {
        return $this->connection->pdo()->query($sql)->fetchAll();
    }

    private function activeIdExists(string $catalog, int $id): bool
    {
        $sql = match ($catalog) {
            'unidades_medida' =>
                'SELECT COUNT(*) FROM unidades_medida
                 WHERE id = :id AND activo = 1 AND eliminado_en IS NULL',
            'monedas' =>
                'SELECT COUNT(*) FROM monedas
                 WHERE id = :id AND activo = 1 AND eliminado_en IS NULL',
            'lineas_producto' =>
                'SELECT COUNT(*) FROM lineas_producto
                 WHERE id = :id AND activo = 1 AND eliminado_en IS NULL',
            'marcas' =>
                'SELECT COUNT(*) FROM marcas
                 WHERE id = :id AND activo = 1 AND eliminado_en IS NULL',
            'clasificaciones_producto' =>
                'SELECT COUNT(*) FROM clasificaciones_producto
                 WHERE id = :id AND activo = 1 AND eliminado_en IS NULL',
            'impuestos' =>
                'SELECT COUNT(*) FROM impuestos
                 WHERE id = :id AND activo = 1 AND eliminado_en IS NULL',
            default => throw new \InvalidArgumentException(
                'Unsupported product catalog.'
            ),
        };
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute(['id' => $id]);

        return (int) $statement->fetchColumn() === 1;
    }
}
