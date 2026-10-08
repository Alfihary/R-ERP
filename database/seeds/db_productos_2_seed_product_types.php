<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const TYPES = [
        [
            'codigo' => 'PRODUCTO',
            'nombre' => 'Producto',
            'descripcion' => 'Producto físico o mercancía.',
        ],
        [
            'codigo' => 'SERVICIO',
            'nombre' => 'Servicio',
            'descripcion' => 'Servicio sin control físico de inventario.',
        ],
        [
            'codigo' => 'KIT',
            'nombre' => 'Kit',
            'descripcion' => 'Agrupación futura de productos o servicios.',
        ],
    ];

    public function id(): string
    {
        return 'db_productos_2_seed_product_types';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $actorId = $this->initialAdminId($pdo);

            foreach (self::TYPES as $type) {
                $this->upsert($pdo, $type, $actorId);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function rollback(PDO $pdo): void
    {
        $actorId = $this->initialAdminId($pdo);
        $codes = array_column(self::TYPES, 'codigo');
        $placeholders = implode(', ', array_fill(0, count($codes), '?'));
        $statement = $pdo->prepare(
            'UPDATE tipos_producto tp
             SET activo = 0,
                 eliminado_en = CURRENT_TIMESTAMP,
                 eliminado_por = ?,
                 actualizado_por = ?
             WHERE codigo IN (' . $placeholders . ')
               AND NOT EXISTS (
                   SELECT 1 FROM productos p
                   WHERE p.tipo_producto_id = tp.id
               )'
        );
        $statement->execute([$actorId, $actorId, ...$codes]);
    }

    private function initialAdminId(PDO $pdo): int
    {
        $statement = $pdo->query(
            "SELECT u.id
             FROM usuarios u
             INNER JOIN usuario_roles ur
                 ON ur.usuario_id = u.id
                AND ur.activo = 1
                AND ur.eliminado_en IS NULL
             INNER JOIN roles r
                 ON r.id = ur.rol_id
                AND r.codigo = 'ADMIN'
                AND r.activo = 1
                AND r.eliminado_en IS NULL
             WHERE u.activo = 1
               AND u.eliminado_en IS NULL
             ORDER BY u.id
             LIMIT 1"
        );
        $actorId = (int) $statement->fetchColumn();

        if ($actorId < 1) {
            throw new RuntimeException(
                'DB-PRODUCTOS-2 requires the active initial ADMIN user.'
            );
        }

        return $actorId;
    }

    /**
     * @param array{codigo: string, nombre: string, descripcion: string} $type
     */
    private function upsert(PDO $pdo, array $type, int $actorId): void
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO tipos_producto (
                codigo,
                nombre,
                descripcion,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :codigo,
                :nombre,
                :descripcion,
                1,
                :creado_por,
                :actualizado_por
            )
            ON DUPLICATE KEY UPDATE
                nombre = :nombre_update,
                descripcion = :descripcion_update,
                activo = 1,
                eliminado_en = NULL,
                eliminado_por = NULL,
                creado_por = COALESCE(creado_por, :creado_por_update),
                actualizado_por = :actualizado_por_update
            SQL
        );
        $statement->execute([
            'codigo' => $type['codigo'],
            'nombre' => $type['nombre'],
            'descripcion' => $type['descripcion'],
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
            'nombre_update' => $type['nombre'],
            'descripcion_update' => $type['descripcion'],
            'creado_por_update' => $actorId,
            'actualizado_por_update' => $actorId,
        ]);
    }
};
