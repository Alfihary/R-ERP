<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class ProductPriceHistoryRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function insert(array $data): int
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO producto_precios_historial (
                producto_precio_id,
                id_producto,
                lista_precio_id,
                moneda_id_anterior,
                moneda_id_nueva,
                precio_lista_anterior,
                precio_minimo_anterior,
                incluye_impuestos_anterior,
                requiere_revision_anterior,
                activo_anterior,
                precio_lista_nuevo,
                precio_minimo_nuevo,
                incluye_impuestos_nuevo,
                requiere_revision_nuevo,
                activo_nuevo,
                tipo_cambio,
                motivo_cambio,
                cambiado_por
             ) VALUES (
                :producto_precio_id,
                :id_producto,
                :lista_precio_id,
                :moneda_id_anterior,
                :moneda_id_nueva,
                :precio_lista_anterior,
                :precio_minimo_anterior,
                :incluye_impuestos_anterior,
                :requiere_revision_anterior,
                :activo_anterior,
                :precio_lista_nuevo,
                :precio_minimo_nuevo,
                :incluye_impuestos_nuevo,
                :requiere_revision_nuevo,
                :activo_nuevo,
                :tipo_cambio,
                :motivo_cambio,
                :cambiado_por
             )'
        );
        $statement->execute([
            'producto_precio_id' => $data['producto_precio_id'],
            'id_producto' => $data['id_producto'],
            'lista_precio_id' => $data['lista_precio_id'],
            'moneda_id_anterior' => $data['moneda_id_anterior'],
            'moneda_id_nueva' => $data['moneda_id_nueva'],
            'precio_lista_anterior' => $data['precio_lista_anterior'],
            'precio_minimo_anterior' => $data['precio_minimo_anterior'],
            'incluye_impuestos_anterior' =>
                $data['incluye_impuestos_anterior'],
            'requiere_revision_anterior' =>
                $data['requiere_revision_anterior'],
            'activo_anterior' => $data['activo_anterior'],
            'precio_lista_nuevo' => $data['precio_lista_nuevo'],
            'precio_minimo_nuevo' => $data['precio_minimo_nuevo'],
            'incluye_impuestos_nuevo' => $data['incluye_impuestos_nuevo'],
            'requiere_revision_nuevo' => $data['requiere_revision_nuevo'],
            'activo_nuevo' => $data['activo_nuevo'],
            'tipo_cambio' => $data['tipo_cambio'],
            'motivo_cambio' => $data['motivo_cambio'],
            'cambiado_por' => $data['cambiado_por'],
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByProductPrice(int $productoPrecioId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM producto_precios_historial
             WHERE producto_precio_id = :producto_precio_id
             ORDER BY cambiado_en DESC, id DESC'
        );
        $statement->execute(['producto_precio_id' => $productoPrecioId]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByProduct(string $idProducto): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM producto_precios_historial
             WHERE id_producto = :id_producto
             ORDER BY cambiado_en DESC, id DESC'
        );
        $statement->execute(['id_producto' => $idProducto]);

        return $statement->fetchAll();
    }
}
