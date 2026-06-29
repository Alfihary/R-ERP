<?php

declare(strict_types=1);

use App\Domain\Security\PermissionService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PermissionRepository;

if (!isset($connection) || !$connection instanceof ConnectionProvider) {
    throw new RuntimeException('RBAC-0 DB-TEST requires the shared connection provider.');
}

return new class($connection) implements DatabaseTest {
    private PermissionService $permissions;

    public function __construct(private readonly ConnectionProvider $connection)
    {
        $this->permissions = new PermissionService(
            new PermissionRepository($this->connection)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $currentDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($currentDatabase !== $expectedDatabase) {
            throw new RuntimeException('The active database does not match RBAC-0 DB-TEST.');
        }

        $permissionCodes = [
            'sistema.acceder',
            'sistema.app.ver',
            'seguridad.rbac.ver',
        ];
        $permissionRows = $this->permissionRows($pdo, $permissionCodes);

        if (count($permissionRows) !== count($permissionCodes)) {
            throw new RuntimeException('RBAC-0 structural permissions are incomplete.');
        }

        foreach ($permissionRows as $permission) {
            if ((int) $permission['es_sistema'] !== 1
                || (int) $permission['activo'] !== 1
                || $permission['eliminado_en'] !== null
            ) {
                throw new RuntimeException('An RBAC-0 permission is inactive or not structural.');
            }
        }

        if ((int) $pdo->query('SELECT COUNT(*) FROM permisos')->fetchColumn() !== 3) {
            throw new RuntimeException('RBAC-0 must contain only the three approved permissions.');
        }

        $admin = $this->adminContext($pdo);
        $assignmentCount = $this->adminAssignmentCount($pdo, (int) $admin['rol_id']);

        if ($assignmentCount !== 3) {
            throw new RuntimeException('ADMIN must receive the three RBAC-0 permissions once.');
        }

        if ($this->duplicatePermissionCodes($pdo) !== 0
            || $this->duplicateRolePermissions($pdo) !== 0
        ) {
            throw new RuntimeException('RBAC-0 contains duplicate permissions or assignments.');
        }

        $userId = (int) $admin['usuario_id'];

        if (!$this->permissions->allows($userId, 'sistema.app.ver')) {
            throw new RuntimeException('ADMIN should be allowed to access the private app route.');
        }

        if ($this->permissions->allows($userId, 'sistema.permiso.inexistente')) {
            throw new RuntimeException('A missing permission must never grant access.');
        }

        $pdo->beginTransaction();

        try {
            $roleInactiveDenied = $this->testInactiveRole(
                $pdo,
                (int) $admin['rol_id'],
                $userId
            );
            $permissionInactiveDenied = $this->testInactivePermission(
                $pdo,
                (int) $permissionRows['sistema.app.ver']['id'],
                $userId
            );
            $duplicatePermissionRejected = $this->duplicatePermissionRejected($pdo);
            $duplicateAssignmentRejected = $this->duplicateAssignmentRejected(
                $pdo,
                (int) $admin['rol_id'],
                (int) $permissionRows['sistema.app.ver']['id']
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        if (!$roleInactiveDenied
            || !$permissionInactiveDenied
            || !$duplicatePermissionRejected
            || !$duplicateAssignmentRejected
        ) {
            throw new RuntimeException('RBAC-0 negative authorization checks failed.');
        }

        return [
            'permission_codes' => $permissionCodes,
            'permissions_rows' => count($permissionRows),
            'admin_permission_rows' => $assignmentCount,
            'duplicate_permission_codes' => 0,
            'duplicate_role_permissions' => 0,
            'admin_allowed' => true,
            'missing_permission_denied' => true,
            'inactive_role_denied' => true,
            'inactive_permission_denied' => true,
            'duplicate_permission_rejected' => true,
            'duplicate_assignment_rejected' => true,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    /**
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    private function permissionRows(PDO $pdo, array $codes): array
    {
        $placeholders = [];
        $parameters = [];

        foreach ($codes as $index => $code) {
            $key = 'code_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $code;
        }

        $statement = $pdo->prepare(
            'SELECT id, codigo, es_sistema, activo, eliminado_en
             FROM permisos
             WHERE codigo IN (' . implode(', ', $placeholders) . ')'
        );
        $statement->execute($parameters);
        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['codigo']] = $row;
        }

        return $rows;
    }

    /**
     * @return array{rol_id: int|string, usuario_id: int|string}
     */
    private function adminContext(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            SELECT r.id AS rol_id, u.id AS usuario_id
            FROM roles r
            INNER JOIN usuario_roles ur
                ON ur.rol_id = r.id
               AND ur.activo = 1
               AND ur.eliminado_en IS NULL
            INNER JOIN usuarios u
                ON u.id = ur.usuario_id
               AND u.activo = 1
               AND u.eliminado_en IS NULL
            WHERE r.codigo = :codigo
              AND r.es_sistema = 1
              AND r.activo = 1
              AND r.eliminado_en IS NULL
            LIMIT 1
            SQL
        );
        $statement->execute(['codigo' => 'ADMIN']);
        $context = $statement->fetch();

        if ($context === false) {
            throw new RuntimeException('RBAC-0 requires an active ADMIN user assignment.');
        }

        return $context;
    }

    private function adminAssignmentCount(PDO $pdo, int $roleId): int
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            SELECT COUNT(*)
            FROM rol_permisos rp
            INNER JOIN permisos p
                ON p.id = rp.permiso_id
               AND p.activo = 1
               AND p.eliminado_en IS NULL
            WHERE rp.rol_id = :rol_id
              AND rp.activo = 1
              AND rp.eliminado_en IS NULL
            SQL
        );
        $statement->execute(['rol_id' => $roleId]);

        return (int) $statement->fetchColumn();
    }

    private function duplicatePermissionCodes(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT codigo
                 FROM permisos
                 GROUP BY codigo
                 HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
    }

    private function duplicateRolePermissions(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT rol_id, permiso_id
                 FROM rol_permisos
                 GROUP BY rol_id, permiso_id
                 HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
    }

    private function testInactiveRole(PDO $pdo, int $roleId, int $userId): bool
    {
        $update = $pdo->prepare('UPDATE roles SET activo = :activo WHERE id = :id');
        $update->execute(['activo' => 0, 'id' => $roleId]);
        $denied = !$this->permissions->allows($userId, 'sistema.app.ver');
        $update->execute(['activo' => 1, 'id' => $roleId]);

        return $denied;
    }

    private function testInactivePermission(PDO $pdo, int $permissionId, int $userId): bool
    {
        $update = $pdo->prepare('UPDATE permisos SET activo = :activo WHERE id = :id');
        $update->execute(['activo' => 0, 'id' => $permissionId]);
        $denied = !$this->permissions->allows($userId, 'sistema.app.ver');
        $update->execute(['activo' => 1, 'id' => $permissionId]);

        return $denied;
    }

    private function duplicatePermissionRejected(PDO $pdo): bool
    {
        try {
            $statement = $pdo->prepare(
                'INSERT INTO permisos (codigo, modulo, nombre, es_sistema, activo)
                 VALUES (:codigo, :modulo, :nombre, 1, 1)'
            );
            $statement->execute([
                'codigo' => 'sistema.app.ver',
                'modulo' => 'sistema',
                'nombre' => 'Duplicado no permitido',
            ]);
        } catch (PDOException $exception) {
            return (int) ($exception->errorInfo[1] ?? 0) === 1062;
        }

        return false;
    }

    private function duplicateAssignmentRejected(
        PDO $pdo,
        int $roleId,
        int $permissionId
    ): bool {
        try {
            $statement = $pdo->prepare(
                'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
                 VALUES (:rol_id, :permiso_id, 1)'
            );
            $statement->execute([
                'rol_id' => $roleId,
                'permiso_id' => $permissionId,
            ]);
        } catch (PDOException $exception) {
            return (int) ($exception->errorInfo[1] ?? 0) === 1062;
        }

        return false;
    }
};
