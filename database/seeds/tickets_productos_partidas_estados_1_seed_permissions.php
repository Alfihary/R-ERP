<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const MODULE = 'tickets_productos';

    private const PERMISSIONS = [
        'tickets_productos.ver' => 'Ver tickets de solicitud de alta de productos.',
        'tickets_productos.crear' => 'Crear tickets de solicitud de alta de productos.',
        'tickets_productos.resolver' => 'Aprobar o rechazar partidas de tickets de productos.',
        'tickets_productos.cancelar' => 'Cancelar tickets de solicitud de alta de productos.',
        'tickets_productos.adjuntos.ver' => 'Ver adjuntos privados de tickets de productos.',
        'tickets_productos.comentarios.crear' => 'Agregar comentarios a tickets de productos.',
        'tickets_productos.correo.reenviar' => 'Reenviar correos documentales de tickets de productos.',
        'tickets_productos.eventos.ver' => 'Ver historial/eventos de tickets de productos.',
    ];

    public function id(): string
    {
        return 'tickets_productos_partidas_estados_1_seed_permissions';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $adminRoleId = $this->activeAdminRoleId($pdo);

            foreach (self::PERMISSIONS as $code => $description) {
                $permissionId = $this->upsertPermission($pdo, $code, $description);
                $this->assignToAdmin($pdo, $adminRoleId, $permissionId);
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

        foreach (array_keys(self::PERMISSIONS) as $code) {
            $permissionId = $this->permissionId($pdo, $code);

            if ($permissionId === null) {
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

    private function upsertPermission(PDO $pdo, string $code, string $description): int
    {
        $find = $pdo->prepare(
            'SELECT id, es_sistema
             FROM permisos
             WHERE codigo = :codigo
             LIMIT 1'
        );
        $find->execute(['codigo' => $code]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        $name = $this->permissionName($description);

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
                'modulo' => self::MODULE,
                'nombre' => $name,
                'descripcion' => $description,
            ]);

            return (int) $pdo->lastInsertId();
        }

        if ((int) $existing['es_sistema'] !== 1) {
            throw new RuntimeException(
                'A TP-PARTIDAS-ESTADOS-PERMISOS-1 permission exists but is not structural.'
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
            'modulo' => self::MODULE,
            'nombre' => $name,
            'descripcion' => $description,
        ]);

        return (int) $existing['id'];
    }

    private function assignToAdmin(PDO $pdo, int $adminRoleId, int $permissionId): void
    {
        $find = $pdo->prepare(
            'SELECT activo
             FROM rol_permisos
             WHERE rol_id = :rol_id AND permiso_id = :permiso_id'
        );
        $find->execute([
            'rol_id' => $adminRoleId,
            'permiso_id' => $permissionId,
        ]);

        if ($find->fetch(PDO::FETCH_ASSOC) === false) {
            $insert = $pdo->prepare(
                'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
                 VALUES (:rol_id, :permiso_id, 1)'
            );
            $insert->execute([
                'rol_id' => $adminRoleId,
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
            'rol_id' => $adminRoleId,
            'permiso_id' => $permissionId,
        ]);
    }

    private function activeAdminRoleId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM roles
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['codigo' => 'ADMIN']);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-PERMISOS-1 requires active ADMIN role.'
            );
        }

        return (int) $id;
    }

    private function permissionId(PDO $pdo, string $code): ?int
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM permisos
             WHERE codigo = :codigo
             LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function permissionName(string $description): string
    {
        return rtrim($description, '.');
    }
};
