<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    /**
     * @var list<array{codigo: string, modulo: string, nombre: string, descripcion: string}>
     */
    private const PERMISSIONS = [
        [
            'codigo' => 'sistema.acceder',
            'modulo' => 'sistema',
            'nombre' => 'Acceder al sistema',
            'descripcion' => 'Permite el acceso autenticado a la base del sistema.',
        ],
        [
            'codigo' => 'sistema.app.ver',
            'modulo' => 'sistema',
            'nombre' => 'Ver área privada mínima',
            'descripcion' => 'Permite abrir la ruta privada mínima de RBAC-0.',
        ],
        [
            'codigo' => 'seguridad.rbac.ver',
            'modulo' => 'seguridad',
            'nombre' => 'Ver autorización base',
            'descripcion' => 'Permite consultar superficies futuras de autorización base.',
        ],
        [
            'codigo' => 'usuarios.acceder',
            'modulo' => 'usuarios',
            'nombre' => 'Administrar usuarios',
            'descripcion' => 'Permite consultar la consola administrativa de usuarios.',
        ],
        [
            'codigo' => 'usuarios.crear',
            'modulo' => 'usuarios',
            'nombre' => 'Crear usuarios',
            'descripcion' => 'Permite crear usuarios administrativos.',
        ],
        [
            'codigo' => 'usuarios.editar',
            'modulo' => 'usuarios',
            'nombre' => 'Editar usuarios',
            'descripcion' => 'Permite editar datos no sensibles de usuarios.',
        ],
        [
            'codigo' => 'usuarios.estado',
            'modulo' => 'usuarios',
            'nombre' => 'Cambiar estado de usuarios',
            'descripcion' => 'Permite activar, desactivar o dar de baja lógicamente usuarios.',
        ],
        [
            'codigo' => 'usuarios.roles',
            'modulo' => 'usuarios',
            'nombre' => 'Sincronizar roles de usuarios',
            'descripcion' => 'Permite reemplazar el conjunto de roles de un usuario.',
        ],
        [
            'codigo' => 'usuarios.password',
            'modulo' => 'usuarios',
            'nombre' => 'Restablecer contraseña de usuarios',
            'descripcion' => 'Permite restablecer una contraseña administrativa sin exponer secretos.',
        ],
    ];

    public function id(): string
    {
        return 'rbac_0_seed_base_permissions';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $adminRoleId = $this->activeAdminRoleId($pdo);

            foreach (self::PERMISSIONS as $permission) {
                $permissionId = $this->upsertPermission($pdo, $permission);
                $this->assignPermission($pdo, $adminRoleId, $permissionId);
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
        $adminRoleId = $this->activeAdminRoleId($pdo);

        foreach (array_column(self::PERMISSIONS, 'codigo') as $code) {
            $findPermission = $pdo->prepare(
                'SELECT id FROM permisos WHERE codigo = :codigo'
            );
            $findPermission->execute(['codigo' => $code]);
            $permissionId = $findPermission->fetchColumn();

            if ($permissionId === false) {
                continue;
            }

            $deleteRelation = $pdo->prepare(
                'DELETE FROM rol_permisos
                 WHERE rol_id = :rol_id AND permiso_id = :permiso_id'
            );
            $deleteRelation->execute([
                'rol_id' => $adminRoleId,
                'permiso_id' => $permissionId,
            ]);

            $deletePermission = $pdo->prepare(
                'DELETE FROM permisos
                 WHERE id = :id
                   AND es_sistema = 1
                   AND NOT EXISTS (
                       SELECT 1
                       FROM rol_permisos
                       WHERE rol_permisos.permiso_id = permisos.id
                   )'
            );
            $deletePermission->execute(['id' => $permissionId]);
        }
    }

    private function activeAdminRoleId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            SELECT id, es_sistema, activo, eliminado_en
            FROM roles
            WHERE codigo = :codigo
            LIMIT 1
            SQL
        );
        $statement->execute(['codigo' => 'ADMIN']);
        $role = $statement->fetch();

        if ($role === false
            || (int) $role['es_sistema'] !== 1
            || (int) $role['activo'] !== 1
            || $role['eliminado_en'] !== null
        ) {
            throw new RuntimeException('The active structural ADMIN role is required by RBAC-0.');
        }

        return (int) $role['id'];
    }

    /**
     * @param array{codigo: string, modulo: string, nombre: string, descripcion: string} $permission
     */
    private function upsertPermission(PDO $pdo, array $permission): int
    {
        $find = $pdo->prepare(
            'SELECT id, es_sistema FROM permisos WHERE codigo = :codigo LIMIT 1'
        );
        $find->execute(['codigo' => $permission['codigo']]);
        $existing = $find->fetch();

        if ($existing === false) {
            $insert = $pdo->prepare(
                <<<'SQL'
                INSERT INTO permisos (
                    codigo,
                    modulo,
                    nombre,
                    descripcion,
                    es_sistema,
                    activo
                )
                VALUES (
                    :codigo,
                    :modulo,
                    :nombre,
                    :descripcion,
                    1,
                    1
                )
                SQL
            );
            $insert->execute($permission);

            return (int) $pdo->lastInsertId();
        }

        if ((int) $existing['es_sistema'] !== 1) {
            throw new RuntimeException(
                'An RBAC-0 permission exists but is not marked as structural.'
            );
        }

        $update = $pdo->prepare(
            <<<'SQL'
            UPDATE permisos
            SET modulo = :modulo,
                nombre = :nombre,
                descripcion = :descripcion,
                activo = 1,
                eliminado_en = NULL,
                eliminado_por = NULL
            WHERE id = :id
            SQL
        );
        $update->execute([
            'id' => $existing['id'],
            'modulo' => $permission['modulo'],
            'nombre' => $permission['nombre'],
            'descripcion' => $permission['descripcion'],
        ]);

        return (int) $existing['id'];
    }

    private function assignPermission(PDO $pdo, int $roleId, int $permissionId): void
    {
        $find = $pdo->prepare(
            'SELECT activo
             FROM rol_permisos
             WHERE rol_id = :rol_id AND permiso_id = :permiso_id'
        );
        $find->execute([
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);
        $existing = $find->fetch();

        if ($existing === false) {
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
            <<<'SQL'
            UPDATE rol_permisos
            SET activo = 1,
                eliminado_en = NULL,
                eliminado_por = NULL
            WHERE rol_id = :rol_id AND permiso_id = :permiso_id
            SQL
        );
        $update->execute([
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);
    }
};
