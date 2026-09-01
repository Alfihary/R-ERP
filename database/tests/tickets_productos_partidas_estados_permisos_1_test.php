<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Seed;

return new class implements DatabaseTest {
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

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException(
                'Unexpected active database for TP-PARTIDAS-ESTADOS-PERMISOS-1.'
            );
        }

        foreach (['roles', 'permisos', 'rol_permisos', 'usuarios'] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('Required RBAC table does not exist: ' . $table);
            }
        }

        $seed = require BASE_PATH
            . '/database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php';

        if (!$seed instanceof Seed) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-PERMISOS-1 seed has an invalid contract.'
            );
        }

        $before = $this->counts($pdo);
        $foreignPermissionFingerprintBefore = $this->foreignPermissionFingerprint($pdo);
        $adminRoleId = $this->activeAdminRoleId($pdo);
        $roleCodesBefore = $this->roleCodes($pdo);
        $userRowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
        $results = [];

        try {
            $pdo->beginTransaction();
            $seed->run($pdo);
            $afterFirstRun = $this->counts($pdo);
            $assignmentRowsAfterFirstRun = $this->ticketAdminAssignments($pdo, $adminRoleId);
            $seed->run($pdo);
            $during = $this->counts($pdo);
            $roleCodesDuring = $this->roleCodes($pdo);
            $userRowsDuring = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();

            $results['permissions'] = [
                'all_exist' => $this->ticketPermissionCount($pdo) === count(self::PERMISSIONS),
                'all_active' => $this->activeTicketPermissionCount($pdo) === count(self::PERMISSIONS),
                'exact_codes' => $this->ticketPermissionCodes($pdo) === array_keys(self::PERMISSIONS),
                'exact_names' => $this->ticketPermissionNamesMatch($pdo),
                'no_duplicate_codes' => $this->duplicatePermissionCodes($pdo) === 0,
                'module_is_tickets_productos' => $this->ticketPermissionsUseModule($pdo),
            ];
            $results['admin_assignment'] = [
                'admin_has_all_permissions' =>
                    $this->ticketAdminAssignments($pdo, $adminRoleId) === count(self::PERMISSIONS),
                'admin_assignments_active' =>
                    $this->ticketAdminActiveAssignments($pdo, $adminRoleId) === count(self::PERMISSIONS),
                'no_duplicate_role_permissions' => $this->duplicateRolePermissions($pdo) === 0,
            ];
            $results['idempotence'] = [
                'permission_counts_stable_second_run' =>
                    $afterFirstRun['ticket_permissions'] === $during['ticket_permissions'],
                'assignment_counts_stable_second_run' =>
                    $assignmentRowsAfterFirstRun === $this->ticketAdminAssignments($pdo, $adminRoleId),
                'seed_id' => $seed->id() === 'tickets_productos_partidas_estados_1_seed_permissions',
            ];
            $results['scope_guardrails'] = [
                'no_roles_created' => $roleCodesBefore === $roleCodesDuring,
                'no_new_solicitante_tickets_role' =>
                    !in_array('SOLICITANTE_TICKETS', array_diff($roleCodesDuring, $roleCodesBefore), true),
                'no_users_created_or_deleted' => $userRowsBefore === $userRowsDuring,
                'no_direct_user_permissions_table' => !$this->tableExists($pdo, 'usuario_permisos'),
                'foreign_permissions_unchanged' =>
                    $foreignPermissionFingerprintBefore === $this->foreignPermissionFingerprint($pdo),
            ];
            $results['static_guardrails'] = [
                'no_routes_created' => !$this->hasFiles('routes', '/ticket.*producto/i'),
                'no_controllers_created' => !$this->hasFiles('app/Http/Controllers', '/Ticket.*Product/i'),
                'no_views_created' => !$this->hasFiles('app/Views/tickets', '/\\.php$/i'),
                'no_mail_runtime_created' =>
                    !$this->hasFiles('app/Domain/Mail', '/Ticket.*Product|Product.*Ticket/i')
                    && !$this->hasFiles('app/Support/Mail', '/Ticket.*Product|Product.*Ticket/i'),
                'no_new_migration' =>
                    !$this->pathExists('database/migrations/tp_partidas_estados_permisos_1_'),
            ];
            $results['operational_guardrails'] = [
                'no_product_created' => $before['productos'] === $during['productos'],
                'no_price_created' => $before['producto_precios'] === $during['producto_precios'],
                'no_stock_created' => $before['existencias_producto'] === $during['existencias_producto'],
                'no_inventory_movement_created' =>
                    $before['movimientos_inventario'] === $during['movimientos_inventario'],
                'no_purchase_created' => $before['compras'] === $during['compras'],
                'no_supplier_created' => $before['proveedores'] === $during['proveedores'],
            ];

            $pdo->rollBack();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        $after = $this->counts($pdo);
        $results['cleanup'] = [
            'transaction_rolled_back' => $before === $after,
            'no_data_persisted_by_test' => $before === $after,
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-PERMISOS-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'seed' => $seed->id(),
            'permissions' => array_keys(self::PERMISSIONS),
            'assigned_role' => 'ADMIN',
            'cases' => $results,
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during ?? [],
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back_no_operational_data_written',
        ];
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
            throw new RuntimeException('Active ADMIN role not found.');
        }

        return (int) $id;
    }

    private function ticketPermissionCount(PDO $pdo): int
    {
        return $this->countWhere(
            $pdo,
            'permisos',
            $this->ticketPermissionWhere()
        );
    }

    private function activeTicketPermissionCount(PDO $pdo): int
    {
        return $this->countWhere(
            $pdo,
            'permisos',
            $this->ticketPermissionWhere() . ' AND activo = 1 AND eliminado_en IS NULL'
        );
    }

    /**
     * @return list<string>
     */
    private function ticketPermissionCodes(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT codigo
             FROM permisos
             WHERE ' . $this->ticketPermissionWhere() . '
             ORDER BY FIELD(codigo, ' . $this->quotedPermissionList() . ')'
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    }

    private function ticketPermissionNamesMatch(PDO $pdo): bool
    {
        $statement = $pdo->query(
            'SELECT codigo, nombre, descripcion
             FROM permisos
             WHERE ' . $this->ticketPermissionWhere()
        );

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (string) $row['codigo'];
            $description = self::PERMISSIONS[$code] ?? null;

            if (
                $description === null
                || (string) $row['descripcion'] !== $description
                || (string) $row['nombre'] !== rtrim($description, '.')
            ) {
                return false;
            }
        }

        return true;
    }

    private function ticketPermissionsUseModule(PDO $pdo): bool
    {
        return $this->countWhere(
            $pdo,
            'permisos',
            $this->ticketPermissionWhere() . " AND modulo = 'tickets_productos'"
        ) === count(self::PERMISSIONS);
    }

    private function ticketAdminAssignments(PDO $pdo, int $adminRoleId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE rp.rol_id = :rol_id
               AND ' . $this->ticketPermissionWhere('p')
        );
        $statement->execute(['rol_id' => $adminRoleId]);

        return (int) $statement->fetchColumn();
    }

    private function ticketAdminActiveAssignments(PDO $pdo, int $adminRoleId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE rp.rol_id = :rol_id
               AND rp.activo = 1
               AND rp.eliminado_en IS NULL
               AND p.activo = 1
               AND p.eliminado_en IS NULL
               AND ' . $this->ticketPermissionWhere('p')
        );
        $statement->execute(['rol_id' => $adminRoleId]);

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

    /**
     * @return list<string>
     */
    private function roleCodes(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT codigo FROM roles ORDER BY codigo'
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    }

    private function foreignPermissionFingerprint(PDO $pdo): string
    {
        $statement = $pdo->query(
            "SELECT COALESCE(GROUP_CONCAT(
                CONCAT_WS('|', codigo, modulo, nombre, COALESCE(descripcion, ''), activo, COALESCE(eliminado_en, 'NULL'))
                ORDER BY codigo SEPARATOR '\n'
             ), '')
             FROM permisos
             WHERE codigo REGEXP '^(vcard|precios|inventario|productos)\\.'"
        );

        return (string) $statement->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'ticket_permissions' => $this->countWhere(
                $pdo,
                'permisos',
                $this->ticketPermissionWhere()
            ),
            'roles' => $this->countTable($pdo, 'roles'),
            'usuarios' => $this->countTable($pdo, 'usuarios'),
            'rol_permisos' => $this->countTable($pdo, 'rol_permisos'),
            'productos' => $this->countTable($pdo, 'productos'),
            'producto_precios' => $this->countTable($pdo, 'producto_precios'),
            'existencias_producto' => $this->countTableIfExists($pdo, 'existencias_producto'),
            'movimientos_inventario' => $this->countTable($pdo, 'movimientos_inventario'),
            'compras' => $this->countTableIfExists($pdo, 'compras'),
            'proveedores' => $this->countTableIfExists($pdo, 'proveedores'),
        ];
    }

    private function countTable(PDO $pdo, string $table): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function countTableIfExists(PDO $pdo, string $table): int
    {
        if (!$this->tableExists($pdo, $table)) {
            return 0;
        }

        return $this->countTable($pdo, $table);
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    private function ticketPermissionWhere(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        return $prefix . 'codigo IN (' . $this->quotedPermissionList() . ')';
    }

    private function quotedPermissionList(): string
    {
        return implode(
            ', ',
            array_map(
                static fn (string $code): string => "'" . str_replace("'", "''", $code) . "'",
                array_keys(self::PERMISSIONS)
            )
        );
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function pathExists(string $relativePrefix): bool
    {
        foreach ($this->files(dirname($relativePrefix)) as $path) {
            if (str_starts_with($path, $relativePrefix)) {
                return true;
            }
        }

        return false;
    }

    private function hasFiles(string $relativeDir, string $pattern): bool
    {
        foreach ($this->files($relativeDir) as $path) {
            if (preg_match($pattern, basename($path)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function files(string $relativeDir): array
    {
        $base = BASE_PATH . '/' . trim($relativeDir, '/');

        if (!is_dir($base)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(BASE_PATH) + 1));
        }

        sort($files);

        return $files;
    }

    private function allTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $item) {
            if (!$this->allTrue($item)) {
                return false;
            }
        }

        return true;
    }
};
