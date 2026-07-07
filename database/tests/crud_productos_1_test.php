<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    private const CODES = [
        'productos.acceder',
        'productos.ver',
        'productos.crear',
        'productos.editar',
        'productos.estado',
    ];

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $currentDatabase = (string) $pdo->query(
            'SELECT DATABASE()'
        )->fetchColumn();

        if ($currentDatabase !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match CRUD-PRODUCTOS-1 DB-TEST.'
            );
        }

        [$placeholders, $parameters] = $this->placeholders(self::CODES);
        $in = implode(', ', $placeholders);
        $permissions = $pdo->prepare(
            'SELECT COUNT(*) FROM permisos
             WHERE codigo IN (' . $in . ')
               AND modulo = :modulo
               AND es_sistema = 1
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $permissions->execute($parameters + ['modulo' => 'productos']);
        $permissionRows = (int) $permissions->fetchColumn();

        $admin = $pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN roles r
                 ON r.id = rp.rol_id
                AND r.codigo = :role_code
                AND r.activo = 1
                AND r.eliminado_en IS NULL
             INNER JOIN permisos p
                 ON p.id = rp.permiso_id
                AND p.codigo IN (' . $in . ')
                AND p.activo = 1
                AND p.eliminado_en IS NULL
             WHERE rp.activo = 1
               AND rp.eliminado_en IS NULL'
        );
        $admin->execute($parameters + ['role_code' => 'ADMIN']);
        $adminRows = (int) $admin->fetchColumn();

        $duplicateCodes = (int) $pdo->query(
            'SELECT COUNT(*) FROM (
                SELECT codigo FROM permisos
                GROUP BY codigo HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
        $duplicateRelations = (int) $pdo->query(
            'SELECT COUNT(*) FROM (
                SELECT rol_id, permiso_id FROM rol_permisos
                GROUP BY rol_id, permiso_id HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
        $users = (int) $pdo->query(
            'SELECT COUNT(*) FROM usuarios'
        )->fetchColumn();
        $totalPermissions = (int) $pdo->query(
            'SELECT COUNT(*) FROM permisos
             WHERE activo = 1 AND eliminado_en IS NULL'
        )->fetchColumn();

        if (
            $permissionRows !== 5
            || $adminRows !== 5
            || $duplicateCodes !== 0
            || $duplicateRelations !== 0
            || $users !== 1
            || $totalPermissions !== 37
        ) {
            throw new RuntimeException(
                'CRUD-PRODUCTOS-1 permission seed evidence is invalid.'
            );
        }

        return [
            'database' => $currentDatabase,
            'permission_codes' => self::CODES,
            'permission_rows' => $permissionRows,
            'admin_active_permission_rows' => $adminRows,
            'total_active_permissions' => $totalPermissions,
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
