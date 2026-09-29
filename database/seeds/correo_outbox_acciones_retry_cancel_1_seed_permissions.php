<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const PERMISSIONS = [
        'correos.cola.reintentar' => [
            'Reintentar correo en cola',
            'Permite solicitar el reprocesamiento de un correo en estado ERROR con intentos disponibles.',
        ],
        'correos.cola.cancelar' => [
            'Cancelar correo en cola',
            'Permite cancelar un correo pendiente o con error dentro del alcance operativo.',
        ],
    ];

    public function id(): string
    {
        return 'correo_outbox_acciones_retry_cancel_1_seed_permissions';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $roleId = $this->adminRoleId($pdo);
            foreach (self::PERMISSIONS as $code => [$name, $description]) {
                $permissionId = $this->upsertPermission($pdo, $code, $name, $description);
                $statement = $pdo->prepare(
                    <<<'SQL'
                    INSERT INTO rol_permisos (rol_id, permiso_id, activo)
                    VALUES (:rol_id, :permiso_id, 1)
                    ON DUPLICATE KEY UPDATE
                        activo = 1,
                        eliminado_en = NULL,
                        eliminado_por = NULL
                    SQL
                );
                $statement->execute(['rol_id' => $roleId, 'permiso_id' => $permissionId]);
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
        $roleId = $this->adminRoleId($pdo);
        foreach (array_keys(self::PERMISSIONS) as $code) {
            $permissionId = $this->permissionId($pdo, $code);
            if ($permissionId === null) {
                continue;
            }
            $statement = $pdo->prepare(
                'DELETE FROM rol_permisos WHERE rol_id = :rol_id AND permiso_id = :permiso_id'
            );
            $statement->execute(['rol_id' => $roleId, 'permiso_id' => $permissionId]);
            $statement = $pdo->prepare(
                'DELETE FROM permisos
                 WHERE id = :id AND es_sistema = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM rol_permisos WHERE rol_permisos.permiso_id = permisos.id
                   )'
            );
            $statement->execute(['id' => $permissionId]);
        }
    }

    private function upsertPermission(PDO $pdo, string $code, string $name, string $description): int
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO permisos (codigo, modulo, nombre, descripcion, es_sistema, activo)
            VALUES (:codigo, 'correos', :nombre, :descripcion, 1, 1)
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
        $statement->execute(['codigo' => $code, 'nombre' => $name, 'descripcion' => $description]);
        $id = $this->permissionId($pdo, $code);
        if ($id === null) {
            throw new RuntimeException('Mail outbox action permission could not be persisted.');
        }

        return $id;
    }

    private function permissionId(PDO $pdo, string $code): ?int
    {
        $statement = $pdo->prepare('SELECT id FROM permisos WHERE codigo = :codigo LIMIT 1');
        $statement->execute(['codigo' => $code]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function adminRoleId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM roles
             WHERE codigo = :codigo AND es_sistema = 1 AND activo = 1 AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['codigo' => 'ADMIN']);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('CORREO-OUTBOX-ACCIONES-RETRY-CANCEL-1 requires ADMIN.');
        }

        return (int) $id;
    }
};
