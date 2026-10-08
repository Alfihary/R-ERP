<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const PERMISSION = [
        'codigo' => 'inventario.kardex_series.acceder',
        'nombre' => 'Acceder a kardex por serie',
        'descripcion' => 'Permite consultar el historial read-only de movimientos por número de serie.',
    ];

    public function id(): string
    {
        return 'kardex_series_1_seed_permissions';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $roleId = $this->activeAdminRoleId($pdo);
            $permissionId = $this->upsert($pdo);
            $this->assign($pdo, $roleId, $permissionId);

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
        $find = $pdo->prepare('SELECT id FROM permisos WHERE codigo = :codigo');
        $find->execute(['codigo' => self::PERMISSION['codigo']]);
        $permissionId = $find->fetchColumn();

        if ($permissionId === false) {
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

        $deletePermission = $pdo->prepare(
            'DELETE FROM permisos
             WHERE id = :id
               AND es_sistema = 1
               AND NOT EXISTS (
                   SELECT 1 FROM rol_permisos
                   WHERE rol_permisos.permiso_id = permisos.id
               )'
        );
        $deletePermission->execute(['id' => $permissionId]);
    }

    private function activeAdminRoleId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT id, es_sistema, activo, eliminado_en
             FROM roles WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => 'ADMIN']);
        $role = $statement->fetch();

        if (
            $role === false
            || (int) $role['es_sistema'] !== 1
            || (int) $role['activo'] !== 1
            || $role['eliminado_en'] !== null
        ) {
            throw new RuntimeException(
                'KARDEX-SERIES-1 requires the active structural ADMIN role.'
            );
        }

        return (int) $role['id'];
    }

    private function upsert(PDO $pdo): int
    {
        $find = $pdo->prepare(
            'SELECT id, es_sistema FROM permisos
             WHERE codigo = :codigo LIMIT 1'
        );
        $find->execute(['codigo' => self::PERMISSION['codigo']]);
        $existing = $find->fetch();

        if ($existing === false) {
            $insert = $pdo->prepare(
                'INSERT INTO permisos (
                    codigo, modulo, nombre, descripcion, es_sistema, activo
                 ) VALUES (
                    :codigo, :modulo, :nombre, :descripcion, 1, 1
                 )'
            );
            $insert->execute(self::PERMISSION + ['modulo' => 'inventario']);

            return (int) $pdo->lastInsertId();
        }

        if ((int) $existing['es_sistema'] !== 1) {
            throw new RuntimeException(
                'The serial kardex permission exists but is not structural.'
            );
        }

        $update = $pdo->prepare(
            'UPDATE permisos
             SET modulo = :modulo,
                 nombre = :nombre,
                 descripcion = :descripcion,
                 activo = 1,
                 eliminado_en = NULL,
                 eliminado_por = NULL
             WHERE id = :id'
        );
        $update->execute([
            'id' => $existing['id'],
            'modulo' => 'inventario',
            'nombre' => self::PERMISSION['nombre'],
            'descripcion' => self::PERMISSION['descripcion'],
        ]);

        return (int) $existing['id'];
    }

    private function assign(PDO $pdo, int $roleId, int $permissionId): void
    {
        $find = $pdo->prepare(
            'SELECT activo FROM rol_permisos
             WHERE rol_id = :rol_id AND permiso_id = :permiso_id'
        );
        $find->execute([
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);

        if ($find->fetch() === false) {
            $insert = $pdo->prepare(
                'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
                 VALUES (:rol_id, :permiso_id, 1)'
            );
            $insert->execute([
                'rol_id' => $roleId,
                'permiso_id' => $permissionId,
            ]);
            return;
        }

        $update = $pdo->prepare(
            'UPDATE rol_permisos
             SET activo = 1,
                 eliminado_en = NULL,
                 eliminado_por = NULL
             WHERE rol_id = :rol_id AND permiso_id = :permiso_id'
        );
        $update->execute([
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);
    }
};
