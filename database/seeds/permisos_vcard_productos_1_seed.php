<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const CODE = 'vcard.productos.administrar';

    public function id(): string
    {
        return 'permisos_vcard_productos_1_seed';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $permissionId = $this->upsertPermission($pdo);
            $this->assignToAdmin($pdo, $permissionId);

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
        $permissionId = $this->permissionId($pdo);

        if ($permissionId === null) {
            return;
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

    private function upsertPermission(PDO $pdo): int
    {
        $find = $pdo->prepare(
            'SELECT id, es_sistema
             FROM permisos
             WHERE codigo = :codigo
             LIMIT 1'
        );
        $find->execute(['codigo' => self::CODE]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);

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
                ) VALUES (
                    :codigo,
                    :modulo,
                    :nombre,
                    :descripcion,
                    1,
                    1
                )
                SQL
            );
            $insert->execute([
                'codigo' => self::CODE,
                'modulo' => 'vcard',
                'nombre' => 'Administrar productos vCard',
                'descripcion' => 'Administrar productos visibles en vCard publica.',
            ]);

            return (int) $pdo->lastInsertId();
        }

        if ((int) $existing['es_sistema'] !== 1) {
            throw new RuntimeException(
                'The vCard products permission exists but is not marked as structural.'
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
            'modulo' => 'vcard',
            'nombre' => 'Administrar productos vCard',
            'descripcion' => 'Administrar productos visibles en vCard publica.',
        ]);

        return (int) $existing['id'];
    }

    private function assignToAdmin(PDO $pdo, int $permissionId): void
    {
        $adminRoleId = $this->activeAdminRoleId($pdo);
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
            <<<'SQL'
            UPDATE rol_permisos
            SET activo = 1,
                eliminado_en = NULL,
                eliminado_por = NULL
            WHERE rol_id = :rol_id AND permiso_id = :permiso_id
            SQL
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
                'PERMISOS-VCARD-PRODUCTOS-1 requires the active structural ADMIN role.'
            );
        }

        return (int) $id;
    }

    private function permissionId(PDO $pdo): ?int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM permisos WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => self::CODE]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }
};
