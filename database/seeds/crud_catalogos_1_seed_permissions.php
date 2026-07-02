<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const CATALOGS = [
        'monedas' => 'monedas',
        'unidades' => 'unidades de medida',
        'impuestos' => 'impuestos',
        'lineas' => 'líneas de producto',
        'marcas' => 'marcas',
    ];

    private const ACTIONS = [
        'ver' => 'Ver',
        'crear' => 'Crear',
        'editar' => 'Editar',
        'estado' => 'Cambiar estado de',
    ];

    public function id(): string
    {
        return 'crud_catalogos_1_seed_permissions';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $adminRoleId = $this->activeAdminRoleId($pdo);

            foreach ($this->permissions() as $permission) {
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

        foreach (array_column($this->permissions(), 'codigo') as $code) {
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

    /**
     * @return list<array{
     *     codigo: string,
     *     modulo: string,
     *     nombre: string,
     *     descripcion: string
     * }>
     */
    private function permissions(): array
    {
        $permissions = [[
            'codigo' => 'catalogos.acceder',
            'modulo' => 'catalogos',
            'nombre' => 'Acceder a catálogos',
            'descripcion' => 'Permite abrir la sección de catálogos base.',
        ]];

        foreach (self::CATALOGS as $catalog => $label) {
            foreach (self::ACTIONS as $action => $verb) {
                $permissions[] = [
                    'codigo' => 'catalogos.' . $catalog . '.' . $action,
                    'modulo' => 'catalogos',
                    'nombre' => $verb . ' ' . $label,
                    'descripcion' => $verb . ' registros del catálogo de '
                        . $label . '.',
                ];
            }
        }

        return $permissions;
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
        $role = $statement->fetch();

        if ($role === false
            || (int) $role['es_sistema'] !== 1
            || (int) $role['activo'] !== 1
            || $role['eliminado_en'] !== null
        ) {
            throw new RuntimeException(
                'CRUD-CATALOGOS-1 requires the active structural ADMIN role.'
            );
        }

        return (int) $role['id'];
    }

    /**
     * @param array{
     *     codigo: string,
     *     modulo: string,
     *     nombre: string,
     *     descripcion: string
     * } $permission
     */
    private function upsertPermission(PDO $pdo, array $permission): int
    {
        $find = $pdo->prepare(
            'SELECT id, es_sistema
             FROM permisos
             WHERE codigo = :codigo
             LIMIT 1'
        );
        $find->execute(['codigo' => $permission['codigo']]);
        $existing = $find->fetch();

        if ($existing === false) {
            $insert = $pdo->prepare(
                'INSERT INTO permisos (
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
                 )'
            );
            $insert->execute($permission);

            return (int) $pdo->lastInsertId();
        }

        if ((int) $existing['es_sistema'] !== 1) {
            throw new RuntimeException(
                'A catalog permission exists but is not structural.'
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
            'modulo' => $permission['modulo'],
            'nombre' => $permission['nombre'],
            'descripcion' => $permission['descripcion'],
        ]);

        return (int) $existing['id'];
    }

    private function assignPermission(
        PDO $pdo,
        int $roleId,
        int $permissionId
    ): void {
        $find = $pdo->prepare(
            'SELECT activo
             FROM rol_permisos
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
