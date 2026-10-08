<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const PERMISSIONS = [
        [
            'codigo' => 'catalogos.unidades_sat.acceder',
            'nombre' => 'Acceder a unidades SAT',
            'descripcion' => 'Permite abrir la administración de unidades SAT.',
        ],
        [
            'codigo' => 'catalogos.unidades_sat.crear',
            'nombre' => 'Crear unidades SAT',
            'descripcion' => 'Permite registrar unidades SAT estructurales.',
        ],
        [
            'codigo' => 'catalogos.unidades_sat.editar',
            'nombre' => 'Editar unidades SAT',
            'descripcion' => 'Permite modificar unidades SAT estructurales.',
        ],
        [
            'codigo' => 'catalogos.unidades_sat.estado',
            'nombre' => 'Cambiar estado de unidades SAT',
            'descripcion' => 'Permite activar o desactivar unidades SAT.',
        ],
        [
            'codigo' => 'catalogos.claves_sat.acceder',
            'nombre' => 'Acceder a claves SAT',
            'descripcion' => 'Permite abrir la administración de claves SAT.',
        ],
        [
            'codigo' => 'catalogos.claves_sat.crear',
            'nombre' => 'Crear claves SAT',
            'descripcion' => 'Permite registrar claves SAT estructurales.',
        ],
        [
            'codigo' => 'catalogos.claves_sat.editar',
            'nombre' => 'Editar claves SAT',
            'descripcion' => 'Permite modificar claves SAT estructurales.',
        ],
        [
            'codigo' => 'catalogos.claves_sat.estado',
            'nombre' => 'Cambiar estado de claves SAT',
            'descripcion' => 'Permite activar o desactivar claves SAT.',
        ],
    ];

    public function id(): string
    {
        return 'crud_sat_1_seed_permissions';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $roleId = $this->activeAdminRoleId($pdo);

            foreach (self::PERMISSIONS as $permission) {
                $permissionId = $this->upsert($pdo, $permission);
                $this->assign($pdo, $roleId, $permissionId);
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
        $roleId = $this->activeAdminRoleId($pdo);

        foreach (array_column(self::PERMISSIONS, 'codigo') as $code) {
            $find = $pdo->prepare(
                'SELECT id FROM permisos WHERE codigo = :codigo'
            );
            $find->execute(['codigo' => $code]);
            $permissionId = $find->fetchColumn();

            if ($permissionId === false) {
                continue;
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
                'CRUD-SAT-1 requires the active structural ADMIN role.'
            );
        }

        return (int) $role['id'];
    }

    /**
     * @param array{codigo: string, nombre: string, descripcion: string} $data
     */
    private function upsert(PDO $pdo, array $data): int
    {
        $find = $pdo->prepare(
            'SELECT id, es_sistema FROM permisos
             WHERE codigo = :codigo LIMIT 1'
        );
        $find->execute(['codigo' => $data['codigo']]);
        $existing = $find->fetch();

        if ($existing === false) {
            $insert = $pdo->prepare(
                'INSERT INTO permisos (
                    codigo, modulo, nombre, descripcion, es_sistema, activo
                 ) VALUES (
                    :codigo, :modulo, :nombre, :descripcion, 1, 1
                 )'
            );
            $insert->execute($data + ['modulo' => 'catalogos']);

            return (int) $pdo->lastInsertId();
        }

        if ((int) $existing['es_sistema'] !== 1) {
            throw new RuntimeException(
                'A SAT permission exists but is not structural.'
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
            'modulo' => 'catalogos',
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'],
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
