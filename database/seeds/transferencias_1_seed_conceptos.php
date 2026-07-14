<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const CONCEPTS = [
        [
            'codigo' => 'TRANSFERENCIA_SALIDA',
            'nombre' => 'Salida por transferencia',
            'descripcion' => 'Salida estructural de inventario hacia otro almacén.',
            'naturaleza' => 'SALIDA',
        ],
        [
            'codigo' => 'TRANSFERENCIA_ENTRADA',
            'nombre' => 'Entrada por transferencia',
            'descripcion' => 'Entrada estructural de inventario desde otro almacén.',
            'naturaleza' => 'ENTRADA',
        ],
    ];

    public function id(): string
    {
        return 'transferencias_1_seed_conceptos';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $actorId = $this->initialAdminId($pdo);

            foreach (self::CONCEPTS as $concept) {
                $this->upsert($pdo, $concept, $actorId);
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
        $codes = array_column(self::CONCEPTS, 'codigo');
        $placeholders = implode(', ', array_fill(0, count($codes), '?'));

        $statement = $pdo->prepare(
            'UPDATE conceptos_movimiento_inventario c
             SET activo = 0,
                 eliminado_en = CURRENT_TIMESTAMP,
                 eliminado_por = ?,
                 actualizado_por = ?
             WHERE codigo IN (' . $placeholders . ')
               AND NOT EXISTS (
                   SELECT 1 FROM movimientos_inventario m
                   WHERE m.concepto_movimiento_id = c.id
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
                'TRANSFERENCIAS-1 requires the active initial ADMIN user.'
            );
        }

        return $actorId;
    }

    /**
     * @param array{
     *     codigo: string,
     *     nombre: string,
     *     descripcion: string,
     *     naturaleza: string
     * } $concept
     */
    private function upsert(PDO $pdo, array $concept, int $actorId): void
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO conceptos_movimiento_inventario (
                codigo,
                nombre,
                descripcion,
                naturaleza,
                activo,
                creado_por,
                actualizado_por
            )
            VALUES (
                :codigo,
                :nombre,
                :descripcion,
                :naturaleza,
                1,
                :creado_por,
                :actualizado_por
            )
            ON DUPLICATE KEY UPDATE
                nombre = :nombre_update,
                descripcion = :descripcion_update,
                naturaleza = :naturaleza_update,
                activo = 1,
                eliminado_en = NULL,
                eliminado_por = NULL,
                creado_por = COALESCE(creado_por, :creado_por_update),
                actualizado_por = :actualizado_por_update
            SQL
        );
        $statement->execute([
            'codigo' => $concept['codigo'],
            'nombre' => $concept['nombre'],
            'descripcion' => $concept['descripcion'],
            'naturaleza' => $concept['naturaleza'],
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
            'nombre_update' => $concept['nombre'],
            'descripcion_update' => $concept['descripcion'],
            'naturaleza_update' => $concept['naturaleza'],
            'creado_por_update' => $actorId,
            'actualizado_por_update' => $actorId,
        ]);
    }
};
