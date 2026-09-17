<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    public function id(): string
    {
        return 'tp_partidas_estados_correo_config_1_seed_permissions';
    }

    public function run(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO permisos (
                codigo,
                modulo,
                nombre,
                descripcion,
                es_sistema,
                activo
            ) VALUES (
                :codigo,
                :modulo,
                :nombre,
                :descripcion,
                1,
                1
            )
            ON DUPLICATE KEY UPDATE
                modulo = VALUES(modulo),
                nombre = VALUES(nombre),
                descripcion = VALUES(descripcion),
                es_sistema = 1,
                activo = 1,
                eliminado_en = NULL,
                eliminado_por = NULL
            SQL
        );
        $statement->execute([
            'codigo' => 'configuracion.correo.administrar',
            'modulo' => 'configuracion',
            'nombre' => 'Administrar configuración de correo',
            'descripcion' => 'Permite administrar configuración no sensible de correo para tickets de productos.',
        ]);
    }

    public function rollback(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            DELETE FROM permisos
            WHERE codigo = :codigo
              AND NOT EXISTS (
                  SELECT 1
                  FROM rol_permisos
                  WHERE rol_permisos.permiso_id = permisos.id
              )
            SQL
        );
        $statement->execute(['codigo' => 'configuracion.correo.administrar']);
    }
};
