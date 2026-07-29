<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const PERMISSIONS = [
        [
            'codigo' => 'perfil.ver',
            'modulo' => 'perfil',
            'nombre' => 'Ver perfil propio',
            'descripcion' => 'Permite consultar el perfil autenticado.',
        ],
        [
            'codigo' => 'perfil.editar',
            'modulo' => 'perfil',
            'nombre' => 'Editar perfil propio',
            'descripcion' => 'Permite actualizar datos editables del perfil.',
        ],
        [
            'codigo' => 'perfil.password.cambiar',
            'modulo' => 'perfil',
            'nombre' => 'Cambiar contraseña propia',
            'descripcion' => 'Permite cambiar la contraseña del usuario autenticado.',
        ],
        [
            'codigo' => 'perfil.foto.actualizar',
            'modulo' => 'perfil',
            'nombre' => 'Actualizar foto de perfil',
            'descripcion' => 'Permite subir o reemplazar la foto de perfil.',
        ],
        [
            'codigo' => 'perfil.foto.eliminar',
            'modulo' => 'perfil',
            'nombre' => 'Eliminar foto de perfil',
            'descripcion' => 'Permite eliminar la foto de perfil.',
        ],
        [
            'codigo' => 'vcard.ver',
            'modulo' => 'vcard',
            'nombre' => 'Ver configuración vCard',
            'descripcion' => 'Permite consultar la configuración privada de vCard.',
        ],
        [
            'codigo' => 'vcard.editar',
            'modulo' => 'vcard',
            'nombre' => 'Editar vCard',
            'descripcion' => 'Permite actualizar datos públicos de vCard.',
        ],
        [
            'codigo' => 'vcard.publicar',
            'modulo' => 'vcard',
            'nombre' => 'Publicar o despublicar vCard',
            'descripcion' => 'Permite cambiar el estado público de la vCard.',
        ],
        [
            'codigo' => 'vcard.privacidad.editar',
            'modulo' => 'vcard',
            'nombre' => 'Editar privacidad de vCard',
            'descripcion' => 'Permite configurar visibilidad granular de vCard.',
        ],
        [
            'codigo' => 'vcard.productos.administrar',
            'modulo' => 'vcard',
            'nombre' => 'Administrar productos vCard',
            'descripcion' => 'Permite administrar productos publicados en vCard.',
        ],
        [
            'codigo' => 'vcard.qr.ver',
            'modulo' => 'vcard',
            'nombre' => 'Ver QR de vCard',
            'descripcion' => 'Permite consultar el QR asociado a la vCard.',
        ],
        [
            'codigo' => 'vcard.vcf.descargar',
            'modulo' => 'vcard',
            'nombre' => 'Descargar VCF de vCard',
            'descripcion' => 'Permite descargar el VCF generado de la vCard.',
        ],
        [
            'codigo' => 'credencial.ver',
            'modulo' => 'credencial',
            'nombre' => 'Ver credencial digital',
            'descripcion' => 'Permite consultar la credencial digital propia.',
        ],
        [
            'codigo' => 'credencial.qr.ver',
            'modulo' => 'credencial',
            'nombre' => 'Ver QR de credencial',
            'descripcion' => 'Permite consultar el QR de la credencial digital.',
        ],
        [
            'codigo' => 'credencial.qr.descargar',
            'modulo' => 'credencial',
            'nombre' => 'Descargar QR de credencial',
            'descripcion' => 'Permite descargar el QR de la credencial digital.',
        ],
    ];

    public function id(): string
    {
        return 'perfil_vcard_1_seed_permissions';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $roleId = $this->adminRoleId($pdo);

            foreach (self::PERMISSIONS as $permission) {
                $permissionId = $this->upsertPermission($pdo, $permission);
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

        foreach (array_column(self::PERMISSIONS, 'codigo') as $code) {
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
            throw new RuntimeException(
                'PERFIL-VCARD-DB-1 requires active ADMIN role.'
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
     * } $data
     */
    private function upsertPermission(PDO $pdo, array $data): int
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
            $insert->execute($data);

            return (int) $pdo->lastInsertId();
        }

        if ((int) $existing['es_sistema'] !== 1) {
            throw new RuntimeException(
                'A PERFIL-VCARD-DB-1 permission exists but is not structural.'
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
            'modulo' => $data['modulo'],
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'],
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
