<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

if (!isset($initialAdminUsername, $initialAdminEmail)
    || !is_string($initialAdminUsername)
    || !is_string($initialAdminEmail)
) {
    throw new RuntimeException('DB-SCOPE-1 seed requires the configured initial administrator.');
}

return new class($initialAdminUsername, $initialAdminEmail) implements Seed {
    private const COMPANY_CODE = 'grupo-refrigerantes';
    private const WAREHOUSE_CODE = 'principal';

    public function __construct(
        private readonly string $adminUsername,
        private readonly string $adminEmail
    ) {
    }

    public function id(): string
    {
        return 'db_scope_1_seed_initial_scope';
    }

    public function run(PDO $pdo): void
    {
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $adminId = $this->initialAdminId($pdo);
            $companyId = $this->upsertCompany($pdo, $adminId);
            $warehouseId = $this->upsertWarehouse($pdo, $companyId, $adminId);
            $this->assignCompany($pdo, $adminId, $companyId);
            $this->assignWarehouse($pdo, $adminId, $companyId, $warehouseId);

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
        $adminId = $this->initialAdminId($pdo);
        $companyId = $this->companyId($pdo);

        if ($companyId === null) {
            return;
        }

        $warehouseId = $this->warehouseId($pdo, $companyId);

        if ($warehouseId !== null) {
            $deleteUserWarehouse = $pdo->prepare(
                'DELETE FROM usuario_almacenes
                 WHERE usuario_id = :usuario_id
                   AND empresa_id = :empresa_id
                   AND almacen_id = :almacen_id'
            );
            $deleteUserWarehouse->execute([
                'usuario_id' => $adminId,
                'empresa_id' => $companyId,
                'almacen_id' => $warehouseId,
            ]);
        }

        $deleteUserCompany = $pdo->prepare(
            'DELETE FROM usuario_empresas
             WHERE usuario_id = :usuario_id AND empresa_id = :empresa_id'
        );
        $deleteUserCompany->execute([
            'usuario_id' => $adminId,
            'empresa_id' => $companyId,
        ]);

        if ($warehouseId !== null) {
            $deleteWarehouse = $pdo->prepare(
                'DELETE FROM almacenes
                 WHERE id = :id
                   AND codigo = :codigo
                   AND NOT EXISTS (
                       SELECT 1
                       FROM usuario_almacenes
                       WHERE usuario_almacenes.almacen_id = almacenes.id
                   )'
            );
            $deleteWarehouse->execute([
                'id' => $warehouseId,
                'codigo' => self::WAREHOUSE_CODE,
            ]);
        }

        $deleteCompany = $pdo->prepare(
            'DELETE FROM empresas
             WHERE id = :id
               AND codigo = :codigo
               AND NOT EXISTS (
                   SELECT 1
                   FROM almacenes
                   WHERE almacenes.empresa_id = empresas.id
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM usuario_empresas
                   WHERE usuario_empresas.empresa_id = empresas.id
               )'
        );
        $deleteCompany->execute([
            'id' => $companyId,
            'codigo' => self::COMPANY_CODE,
        ]);
    }

    private function initialAdminId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            SELECT u.id
            FROM usuarios u
            INNER JOIN usuario_roles ur
                ON ur.usuario_id = u.id
               AND ur.activo = 1
               AND ur.eliminado_en IS NULL
            INNER JOIN roles r
                ON r.id = ur.rol_id
               AND r.codigo = :rol_codigo
               AND r.es_sistema = 1
               AND r.activo = 1
               AND r.eliminado_en IS NULL
            WHERE u.username = :username
              AND u.email = :email
              AND u.activo = 1
              AND u.eliminado_en IS NULL
            LIMIT 1
            SQL
        );
        $statement->execute([
            'rol_codigo' => 'ADMIN',
            'username' => strtolower(trim($this->adminUsername)),
            'email' => strtolower(trim($this->adminEmail)),
        ]);
        $userId = $statement->fetchColumn();

        if ($userId === false) {
            throw new RuntimeException(
                'DB-SCOPE-1 requires the active configured initial ADMIN user.'
            );
        }

        return (int) $userId;
    }

    private function upsertCompany(PDO $pdo, int $adminId): int
    {
        $companyId = $this->companyId($pdo);

        if ($companyId === null) {
            $insert = $pdo->prepare(
                'INSERT INTO empresas (
                    codigo,
                    nombre,
                    activo,
                    creado_por,
                    actualizado_por
                 )
                 VALUES (:codigo, :nombre, 1, :creado_por, :actualizado_por)'
            );
            $insert->execute([
                'codigo' => self::COMPANY_CODE,
                'nombre' => 'Grupo Refrigerantes',
                'creado_por' => $adminId,
                'actualizado_por' => $adminId,
            ]);

            return (int) $pdo->lastInsertId();
        }

        $update = $pdo->prepare(
            <<<'SQL'
            UPDATE empresas
            SET nombre = :nombre,
                activo = 1,
                actualizado_por = :actualizado_por,
                eliminado_en = NULL,
                eliminado_por = NULL
            WHERE id = :id
            SQL
        );
        $update->execute([
            'id' => $companyId,
            'nombre' => 'Grupo Refrigerantes',
            'actualizado_por' => $adminId,
        ]);

        return $companyId;
    }

    private function upsertWarehouse(PDO $pdo, int $companyId, int $adminId): int
    {
        $warehouseId = $this->warehouseId($pdo, $companyId);

        if ($warehouseId === null) {
            $insert = $pdo->prepare(
                'INSERT INTO almacenes (
                    empresa_id,
                    codigo,
                    nombre,
                    activo,
                    creado_por,
                    actualizado_por
                 )
                 VALUES (
                    :empresa_id,
                    :codigo,
                    :nombre,
                    1,
                    :creado_por,
                    :actualizado_por
                 )'
            );
            $insert->execute([
                'empresa_id' => $companyId,
                'codigo' => self::WAREHOUSE_CODE,
                'nombre' => 'Almacén Principal',
                'creado_por' => $adminId,
                'actualizado_por' => $adminId,
            ]);

            return (int) $pdo->lastInsertId();
        }

        $update = $pdo->prepare(
            <<<'SQL'
            UPDATE almacenes
            SET nombre = :nombre,
                activo = 1,
                actualizado_por = :actualizado_por,
                eliminado_en = NULL,
                eliminado_por = NULL
            WHERE id = :id
            SQL
        );
        $update->execute([
            'id' => $warehouseId,
            'nombre' => 'Almacén Principal',
            'actualizado_por' => $adminId,
        ]);

        return $warehouseId;
    }

    private function assignCompany(PDO $pdo, int $userId, int $companyId): void
    {
        $find = $pdo->prepare(
            'SELECT activo
             FROM usuario_empresas
             WHERE usuario_id = :usuario_id AND empresa_id = :empresa_id'
        );
        $find->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
        ]);

        if ($find->fetch() === false) {
            $insert = $pdo->prepare(
                'INSERT INTO usuario_empresas (
                    usuario_id,
                    empresa_id,
                    activo,
                    creado_por,
                    actualizado_por
                 )
                 VALUES (
                    :usuario_id,
                    :empresa_id,
                    1,
                    :creado_por,
                    :actualizado_por
                 )'
            );
            $insert->execute([
                'usuario_id' => $userId,
                'empresa_id' => $companyId,
                'creado_por' => $userId,
                'actualizado_por' => $userId,
            ]);
            return;
        }

        $update = $pdo->prepare(
            <<<'SQL'
            UPDATE usuario_empresas
            SET activo = 1,
                actualizado_por = :actualizado_por,
                eliminado_en = NULL,
                eliminado_por = NULL
            WHERE usuario_id = :usuario_id AND empresa_id = :empresa_id
            SQL
        );
        $update->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'actualizado_por' => $userId,
        ]);
    }

    private function assignWarehouse(
        PDO $pdo,
        int $userId,
        int $companyId,
        int $warehouseId
    ): void {
        $find = $pdo->prepare(
            'SELECT activo
             FROM usuario_almacenes
             WHERE usuario_id = :usuario_id AND almacen_id = :almacen_id'
        );
        $find->execute([
            'usuario_id' => $userId,
            'almacen_id' => $warehouseId,
        ]);

        if ($find->fetch() === false) {
            $insert = $pdo->prepare(
                'INSERT INTO usuario_almacenes (
                    usuario_id,
                    empresa_id,
                    almacen_id,
                    activo,
                    creado_por,
                    actualizado_por
                 )
                 VALUES (
                    :usuario_id,
                    :empresa_id,
                    :almacen_id,
                    1,
                    :creado_por,
                    :actualizado_por
                 )'
            );
            $insert->execute([
                'usuario_id' => $userId,
                'empresa_id' => $companyId,
                'almacen_id' => $warehouseId,
                'creado_por' => $userId,
                'actualizado_por' => $userId,
            ]);
            return;
        }

        $update = $pdo->prepare(
            <<<'SQL'
            UPDATE usuario_almacenes
            SET empresa_id = :empresa_id,
                activo = 1,
                actualizado_por = :actualizado_por,
                eliminado_en = NULL,
                eliminado_por = NULL
            WHERE usuario_id = :usuario_id AND almacen_id = :almacen_id
            SQL
        );
        $update->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'actualizado_por' => $userId,
        ]);
    }

    private function companyId(PDO $pdo): ?int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM empresas WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => self::COMPANY_CODE]);
        $companyId = $statement->fetchColumn();

        return $companyId === false ? null : (int) $companyId;
    }

    private function warehouseId(PDO $pdo, int $companyId): ?int
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM almacenes
             WHERE empresa_id = :empresa_id AND codigo = :codigo
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => self::WAREHOUSE_CODE,
        ]);
        $warehouseId = $statement->fetchColumn();

        return $warehouseId === false ? null : (int) $warehouseId;
    }
};
