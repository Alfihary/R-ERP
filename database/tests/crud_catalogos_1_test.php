<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $currentDatabase = (string) $pdo->query(
            'SELECT DATABASE()'
        )->fetchColumn();

        if ($currentDatabase !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match CRUD-CATALOGOS-1 DB-TEST.'
            );
        }

        $codes = $this->permissionCodes();
        $placeholders = [];
        $parameters = [];

        foreach ($codes as $index => $code) {
            $key = 'permission_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $code;
        }

        $permissionQuery = $pdo->prepare(
            'SELECT COUNT(*)
             FROM permisos
             WHERE codigo IN (' . implode(', ', $placeholders) . ')
               AND modulo = :modulo
               AND es_sistema = 1
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $permissionQuery->execute($parameters + ['modulo' => 'catalogos']);
        $permissionRows = (int) $permissionQuery->fetchColumn();

        $adminQuery = $pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN roles r
                 ON r.id = rp.rol_id
                AND r.codigo = :role_code
                AND r.activo = 1
                AND r.eliminado_en IS NULL
             INNER JOIN permisos p
                 ON p.id = rp.permiso_id
                AND p.codigo IN (' . implode(', ', $placeholders) . ')
                AND p.activo = 1
                AND p.eliminado_en IS NULL
             WHERE rp.activo = 1
               AND rp.eliminado_en IS NULL'
        );
        $adminQuery->execute(
            $parameters + ['role_code' => 'ADMIN']
        );
        $adminRows = (int) $adminQuery->fetchColumn();

        $duplicateCodes = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM (
                 SELECT codigo
                 FROM permisos
                 WHERE modulo = 'catalogos'
                 GROUP BY codigo
                 HAVING COUNT(*) > 1
             ) duplicates"
        )->fetchColumn();
        $duplicateRelations = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM (
                 SELECT rp.rol_id, rp.permiso_id
                 FROM rol_permisos rp
                 INNER JOIN permisos p ON p.id = rp.permiso_id
                 WHERE p.modulo = 'catalogos'
                 GROUP BY rp.rol_id, rp.permiso_id
                 HAVING COUNT(*) > 1
             ) duplicates"
        )->fetchColumn();

        if ($permissionRows !== count($codes)
            || $adminRows !== count($codes)
            || $duplicateCodes !== 0
            || $duplicateRelations !== 0
        ) {
            throw new RuntimeException(
                'CRUD-CATALOGOS-1 permission seed evidence is invalid.'
            );
        }

        return [
            'database' => $currentDatabase,
            'permission_codes' => $codes,
            'permission_rows' => $permissionRows,
            'admin_active_permission_rows' => $adminRows,
            'duplicate_permission_codes' => $duplicateCodes,
            'duplicate_role_permissions' => $duplicateRelations,
            'users_created' => 0,
        ];
    }

    /**
     * @return list<string>
     */
    private function permissionCodes(): array
    {
        $codes = ['catalogos.acceder'];

        foreach (
            ['monedas', 'unidades', 'impuestos', 'lineas', 'marcas']
            as $catalog
        ) {
            foreach (['ver', 'crear', 'editar', 'estado'] as $action) {
                $codes[] = 'catalogos.' . $catalog . '.' . $action;
            }
        }

        return $codes;
    }
};
