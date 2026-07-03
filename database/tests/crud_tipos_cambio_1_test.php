<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    private const CODES = [
        'catalogos.tipos_cambio.ver',
        'catalogos.tipos_cambio.crear',
        'catalogos.tipos_cambio.editar',
        'catalogos.tipos_cambio.estado',
    ];

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $currentDatabase = (string) $pdo->query(
            'SELECT DATABASE()'
        )->fetchColumn();

        if ($currentDatabase !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match CRUD-TIPOS-CAMBIO-1 DB-TEST.'
            );
        }

        [$placeholders, $parameters] = $this->placeholders(self::CODES);
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
        $adminQuery->execute($parameters + ['role_code' => 'ADMIN']);
        $adminRows = (int) $adminQuery->fetchColumn();

        $previousPermissionRows = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM permisos
             WHERE modulo = 'catalogos'
               AND codigo NOT LIKE 'catalogos.tipos_cambio.%'
               AND activo = 1
               AND eliminado_en IS NULL"
        )->fetchColumn();
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
        $totalCatalogPermissions = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM permisos
             WHERE modulo = 'catalogos'
               AND activo = 1
               AND eliminado_en IS NULL"
        )->fetchColumn();

        if (
            $permissionRows !== count(self::CODES)
            || $adminRows !== count(self::CODES)
            || $previousPermissionRows !== 25
            || $totalCatalogPermissions !== 29
            || $duplicateCodes !== 0
            || $duplicateRelations !== 0
        ) {
            throw new RuntimeException(
                'CRUD-TIPOS-CAMBIO-1 permission seed evidence is invalid.'
            );
        }

        return [
            'database' => $currentDatabase,
            'permission_codes' => self::CODES,
            'permission_rows' => $permissionRows,
            'admin_active_permission_rows' => $adminRows,
            'previous_catalog_permission_rows' => $previousPermissionRows,
            'total_catalog_permission_rows' => $totalCatalogPermissions,
            'duplicate_permission_codes' => $duplicateCodes,
            'duplicate_role_permissions' => $duplicateRelations,
            'users_created' => 0,
        ];
    }

    /**
     * @param list<string> $codes
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function placeholders(array $codes): array
    {
        $placeholders = [];
        $parameters = [];

        foreach ($codes as $index => $code) {
            $key = 'permission_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $code;
        }

        return [$placeholders, $parameters];
    }
};
