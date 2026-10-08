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
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $roleId = $this->activeAdminRoleId($pdo);
            $permissionId = $this->upsertPermission($pdo);
            $this->assignPermission($pdo, $roleId, $permissionId);

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
        $roleId = $this->activeAdminRoleId($pdo);
        $permissionId = $this->permissionId($pdo);

        if ($permissionId === null) {
            return;
        }

        $deleteRelation = $pdo->prepare(
            'DELETE FROM rol_permisos
             WHERE rol_id = :rol_id AND permiso_id = :permiso_id'
        );
        $deleteRelation->execute([
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);

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

    private function activeAdminRoleId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT id, es_sistema, activo, eliminado_en
             FROM roles
             WHERE codigo = :codigo
             LIMIT 1'
        );
        $statement->execute(['codigo' => 'ADMIN']);
        $role = $statement->fetch(PDO::FETCH_ASSOC);

        if (
            !is_array($role)
            || (int) $role['es_sistema'] !== 1
            || (int) $role['activo'] !== 1
            || $role['eliminado_en'] !== null
        ) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-CORREO-CONFIG-1 requires the active structural ADMIN role.'
            );
        }

        return (int) $role['id'];
    }

    private function upsertPermission(PDO $pdo): int
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

        $permissionId = $this->permissionId($pdo);

        if ($permissionId === null) {
            throw new RuntimeException('Mail configuration permission could not be persisted.');
        }

        return $permissionId;
    }

    private function permissionId(PDO $pdo): ?int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM permisos WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => 'configuracion.correo.administrar']);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function assignPermission(PDO $pdo, int $roleId, int $permissionId): void
    {
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
        $statement->execute([
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);
    }
};
