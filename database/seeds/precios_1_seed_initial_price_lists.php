<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    public function id(): string
    {
        return 'precios_1_seed_initial_price_lists';
    }

    public function run(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO listas_precios (
                clave,
                nombre,
                observaciones,
                incluye_impuestos,
                es_predeterminada,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                'PUBLICO',
                'Precio público',
                'Lista predeterminada para ventas sin convenio comercial específico.',
                0,
                1,
                1,
                NULL,
                NULL
            )
            ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                observaciones = VALUES(observaciones),
                incluye_impuestos = VALUES(incluye_impuestos),
                es_predeterminada = VALUES(es_predeterminada),
                activo = VALUES(activo),
                eliminado_en = NULL,
                eliminado_por = NULL,
                actualizado_en = CURRENT_TIMESTAMP,
                actualizado_por = NULL
            SQL
        );
        $statement->execute();
    }

    public function rollback(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            "DELETE FROM listas_precios
             WHERE clave = 'PUBLICO'
               AND NOT EXISTS (
                   SELECT 1 FROM producto_precios
                   WHERE producto_precios.lista_precio_id = listas_precios.id
               )
               AND NOT EXISTS (
                   SELECT 1 FROM producto_precios_historial
                   WHERE producto_precios_historial.lista_precio_id = listas_precios.id
               )"
        );
        $statement->execute();
    }
};
