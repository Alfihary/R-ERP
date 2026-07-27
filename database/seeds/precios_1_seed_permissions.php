<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const PERMISSIONS = [
        'precios.listas.acceder' => 'Acceder a listas de precios',
        'precios.listas.ver' => 'Ver listas de precios',
        'precios.listas.crear' => 'Crear listas de precios',
        'precios.listas.editar' => 'Editar listas de precios',
        'precios.listas.activar' => 'Activar listas de precios',
        'precios.listas.eliminar' => 'Eliminar listas de precios',
        'precios.listas.predeterminada' => 'Definir lista predeterminada',
        'precios.productos.acceder' => 'Acceder a precios de productos',
        'precios.productos.ver' => 'Ver precios de productos',
        'precios.productos.crear' => 'Crear precios de productos',
        'precios.productos.editar' => 'Editar precios de productos',
        'precios.productos.desactivar' => 'Desactivar precios de productos',
        'precios.productos.reactivar' => 'Reactivar precios de productos',
        'precios.productos.historial' => 'Ver historial de precios',
        'precios.autorizaciones.acceder' => 'Acceder a autorizaciones de precio',
        'precios.autorizaciones.ver' => 'Ver autorizaciones de precio',
        'precios.autorizaciones.solicitar' => 'Solicitar autorización de precio',
        'precios.autorizaciones.aprobar' => 'Aprobar autorización de precio',
        'precios.autorizaciones.rechazar' => 'Rechazar autorización de precio',
        'precios.autorizaciones.cancelar' => 'Cancelar autorización de precio',
        'precios.autorizaciones.utilizar' => 'Utilizar autorización de precio',
    ];

    public function id(): string
    {
        return 'precios_1_seed_permissions';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $roleId = $this->adminRoleId($pdo);

            foreach (self::PERMISSIONS as $code => $name) {
                $permissionId = $this->upsertPermission($pdo, $code, $name);
                $this->assignToAdmin($pdo, $roleId, $permissionId);
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
            $find = $pdo->prepare(
                'SELECT id FROM permisos WHERE codigo = :codigo LIMIT 1'
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

    private function adminRoleId(PDO $pdo): int
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
            throw new RuntimeException('PRECIOS-DB-1 requires active ADMIN role.');
        }

        return (int) $role['id'];
    }

    private function upsertPermission(PDO $pdo, string $code, string $name): int
    {
        $find = $pdo->prepare(
            'SELECT id, es_sistema FROM permisos
             WHERE codigo = :codigo LIMIT 1'
        );
        $find->execute(['codigo' => $code]);
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
                 ) VALUES (
                    :codigo,
                    :modulo,
                    :nombre,
                    :descripcion,
                    1,
                    1
                 )'
            );
            $insert->execute([
                'codigo' => $code,
                'modulo' => 'precios',
                'nombre' => $name,
                'descripcion' => $name . '.',
            ]);

            return (int) $pdo->lastInsertId();
        }

        if ((int) $existing['es_sistema'] !== 1) {
            throw new RuntimeException(
                'A PRECIOS-DB-1 permission exists but is not structural.'
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
            'modulo' => 'precios',
            'nombre' => $name,
            'descripcion' => $name . '.',
        ]);

        return (int) $existing['id'];
    }

    private function assignToAdmin(PDO $pdo, int $roleId, int $permissionId): void
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
