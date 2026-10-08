<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'usuarios',
        'roles',
        'permisos',
        'usuario_roles',
        'rol_permisos',
        'auditoria_eventos',
    ];

    /** @var array<string, string> */
    private const INVENTORY_TABLES = [
        'empresas' => 'Empresas',
        'almacenes' => 'Almacenes',
        'monedas' => 'Monedas',
        'tipos_cambio' => 'Tipos de cambio',
        'unidades_medida' => 'Unidades de medida',
        'marcas' => 'Marcas',
        'lineas_producto' => 'Lineas',
        'clasificaciones_producto' => 'Clasificaciones',
        'tipos_producto' => 'Tipos de producto',
        'conceptos_movimiento_inventario' => 'Tipos/conceptos de inventario',
        'impuestos' => 'Impuestos',
        'usuarios' => 'Usuarios',
        'roles' => 'Roles',
        'permisos' => 'Permisos',
        'usuario_roles' => 'Usuario roles',
        'rol_permisos' => 'Rol permisos',
        'auditoria_eventos' => 'Auditoria base',
        'ui_temas' => 'Tema/UI base',
        'theme_assignments' => 'Asignaciones de tema',
    ];

    /** @var list<string> */
    private const KEY_PERMISSIONS = [
        'auditoria.ver',
        'perfil.ver',
        'perfil.editar',
        'vcard.ver',
        'vcard.editar',
        'credencial.ver',
        'credencial.qr.ver',
        'credencial.qr.descargar',
        'seguridad.rbac.ver',
    ];

    /** @var array<string, string> */
    private const CRITICAL_PRIVATE_ROUTES = [
        '/app' => "'/app'",
        '/perfil' => "'/perfil'",
        '/perfil/credencial' => "'/perfil/credencial'",
        '/auditoria' => "'/auditoria'",
    ];

    /** @var array<string, string> */
    private const CRITICAL_PUBLIC_ROUTES = [
        '/health' => "'/health'",
        '/v/{slug}' => "'/v/{slug}'",
        '/credencial/verificar/{token}' => "'/credencial/' . 'verificar/{token}'",
    ];

    /** @var list<string> */
    private const FORBIDDEN_PUBLIC_ROUTE_NEEDLES = [
        '/storage/uploads',
        "'/api/credencial",
        "'/api/vcard",
    ];

    /** @var list<string> */
    private const RUNNERS = [
        'database/permisos-auditoria.php',
        'database/auditoria-consulta.php',
        'database/auditoria-service.php',
        'database/rbac.php',
        'database/perfil-vcard.php',
        'database/perfil-service.php',
        'database/perfil-ui.php',
        'database/perfil-foto-upload.php',
        'database/credencial-cierre-qa.php',
        'database/credencial-hardening.php',
        'database/credencial-verificacion-publica.php',
        'database/credencial-token-qr.php',
        'database/credencial-visual.php',
        'database/perfil-credencial-foto.php',
        'database/vcard-service.php',
        'database/vcard-publico.php',
        'database/vcard-foto-publica.php',
        'database/vcard-vcf.php',
        'database/vcard-qr.php',
        'database/vcard-productos.php',
        'database/router-dynamic-params.php',
        'database/productos-imagen.php',
        'database/productos-2.php',
        'database/existencias.php',
        'database/inventory-service.php',
        'database/inventario-service.php',
        'database/series.php',
        'database/folios-inventario.php',
        'database/precios-service.php',
        'database/precios-producto-integracion.php',
        'database/precios-producto-ui.php',
        'database/precios-listas-ui.php',
        'database/precios-productos-global-ui.php',
        'database/precios-autorizaciones-service.php',
    ];

    /** @var list<string> */
    private const DOCS = [
        'docs/db-core-0.md',
        'docs/db-scope-1.md',
        'docs/db-catalogos-1.md',
        'docs/crud-catalogos-1.md',
        'docs/crud-catalogos-2.md',
        'docs/db-productos-1.md',
        'docs/db-productos-2.md',
        'docs/db-inventario-1.md',
        'docs/permisos-auditoria-1.md',
        'docs/auditoria-service-1.md',
        'docs/auditoria-consulta-1.md',
        'docs/perfil-vcard-db-1.md',
        'docs/credencial-cierre-qa-1.md',
    ];

    /** @var list<string> */
    private const ALLOWED_UNCOMMITTED = [
        'database/catalogos-base-cierre-qa.php',
        'database/tests/catalogos_base_cierre_qa_1_test.php',
        'docs/catalogos-base-cierre-qa-1.md',
    ];

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        $before = $this->counts($pdo);
        $tableInventory = $this->tableInventory($pdo);
        $results = [
            'database' => [
                'connection_ok' => true,
                'active_database_confirmed' => true,
                'no_persistent_mutation_before_checks' => true,
            ],
            'required_tables' => $this->requiredTables($pdo),
            'permissions' => $this->permissionEvidence($pdo),
            'duplicates' => [
                'permission_codes' => $this->duplicateCount($pdo, 'permisos', 'codigo'),
                'role_permissions' => $this->duplicateRolePermissions($pdo),
                'usernames' => $this->duplicateCount($pdo, 'usuarios', 'username'),
                'emails' => $this->duplicateCount($pdo, 'usuarios', 'email'),
            ],
            'integrity' => $this->integrityChecks($pdo),
            'audit_metadata' => $this->auditMetadataChecks($pdo),
            'routes' => $this->routeChecks(),
            'files' => [
                'runners' => $this->fileInventory(self::RUNNERS),
                'docs' => $this->fileInventory(self::DOCS),
            ],
            'guardrails' => [
                'allowed_uncommitted_only' => $this->allowedUncommittedOnly(),
                'no_migrations_modified' => !$this->hasUncommittedPath('database/migrations'),
                'no_seeds_modified' => !$this->hasUncommittedPath('database/seeds'),
                'no_app_modified' => !$this->hasUncommittedPath('app'),
                'no_public_modified' => !$this->hasUncommittedPath('public'),
                'no_routes_modified' => !$this->hasUncommittedPath('routes'),
                'no_bootstrap_modified' => !$this->hasUncommittedPath('bootstrap'),
            ],
        ];

        $after = $this->counts($pdo);
        $results['database']['persistent_counts_unchanged'] = $before === $after;

        $fatal = [
            'required_tables_all_present' => $this->allTrue($results['required_tables']),
            'admin_role_present' => $results['permissions']['admin_role_present'],
            'audit_permission_present' => $results['permissions']['auditoria_ver_active'],
            'admin_has_audit_permission' => $results['permissions']['admin_has_auditoria_ver'],
            'no_permission_duplicates' => $results['duplicates']['permission_codes'] === 0,
            'no_role_permission_duplicates' => $results['duplicates']['role_permissions'] === 0,
            'no_username_duplicates' => $results['duplicates']['usernames'] === 0,
            'no_email_duplicates' => $results['duplicates']['emails'] === 0,
            'integrity_ok' => $this->allZero($results['integrity']),
            'audit_metadata_ok' => $this->allZero($results['audit_metadata']),
            'routes_ok' => $this->allTrue($results['routes']['private'])
                && $this->allTrue($results['routes']['public'])
                && $this->allTrue($results['routes']['forbidden_absent']),
            'guardrails_ok' => $this->allTrue($results['guardrails']),
            'no_mutation' => $results['database']['persistent_counts_unchanged'],
        ];

        if (!$this->allTrue($fatal)) {
            throw new RuntimeException(
                'CATALOGOS-BASE-CIERRE-QA-1 assertions failed: '
                . json_encode($fatal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'result' => 'PASS',
            'table_inventory' => $tableInventory,
            'confirmed_tables' => $this->filterInventory($tableInventory, 'PASS'),
            'pending_controlled_tables' => $this->filterInventory($tableInventory, 'PENDIENTE_CONTROLADO'),
            'cases' => $results,
            'fatal_checks' => $fatal,
            'persistent_counts_before' => $before,
            'persistent_counts_after' => $after,
            'known_exception' => [
                'runner' => 'database/productos.php db:test',
                'reason' => 'not a blocking regression because the test database contains a persistent real/non-QA product',
                'product' => '102016169 | REFRIGERANTE R-410A 5KG IGAS',
                'action' => 'not_deleted_or_modified',
            ],
            'cleanup' => 'read_only_no_transient_data_created',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function requiredTables(PDO $pdo): array
    {
        $result = [];

        foreach (self::REQUIRED_TABLES as $table) {
            $result[$table] = $this->tableExists($pdo, $table);
        }

        return $result;
    }

    /**
     * @return array<string, array{label: string, status: string}>
     */
    private function tableInventory(PDO $pdo): array
    {
        $result = [];

        foreach (self::INVENTORY_TABLES as $table => $label) {
            $result[$table] = [
                'label' => $label,
                'status' => $this->tableExists($pdo, $table) ? 'PASS' : 'PENDIENTE_CONTROLADO',
            ];
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function filterInventory(array $inventory, string $status): array
    {
        $result = [];

        foreach ($inventory as $table => $evidence) {
            if (($evidence['status'] ?? '') === $status) {
                $result[$table] = (string) ($evidence['label'] ?? $table);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionEvidence(PDO $pdo): array
    {
        $permissions = [];

        foreach (self::KEY_PERMISSIONS as $permission) {
            $permissions[$permission] = $this->activePermissionExists($pdo, $permission);
        }

        return [
            'admin_role_present' => $this->activeRoleExists($pdo, 'ADMIN'),
            'auditoria_ver_active' => $permissions['auditoria.ver'] ?? false,
            'admin_has_auditoria_ver' => $this->adminHasPermission($pdo, 'auditoria.ver'),
            'key_permissions' => $permissions,
            'missing_key_permissions' => array_keys(array_filter(
                $permissions,
                static fn (bool $exists): bool => !$exists
            )),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function integrityChecks(PDO $pdo): array
    {
        return [
            'orphan_almacenes' => $this->orphanAlmacenes($pdo),
            'orphan_tipos_cambio' => $this->orphanTiposCambio($pdo),
            'invalid_product_ids' => $this->invalidProductIds($pdo),
            'productos_orphan_unidad' => $this->productOrphans($pdo, 'unidad_medida_id', 'unidades_medida'),
            'productos_orphan_moneda' => $this->productOrphans($pdo, 'moneda_id', 'monedas'),
            'productos_orphan_linea' => $this->productOrphans($pdo, 'linea_producto_id', 'lineas_producto'),
            'productos_orphan_marca' => $this->productOrphans($pdo, 'marca_id', 'marcas'),
            'productos_orphan_clasificacion' => $this->productOrphans(
                $pdo,
                'clasificacion_producto_id',
                'clasificaciones_producto'
            ),
            'productos_orphan_tipo' => $this->productOrphans($pdo, 'tipo_producto_id', 'tipos_producto'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function auditMetadataChecks(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, 'auditoria_eventos')) {
            return [
                'plain_token_keys' => 0,
                'password_hash_visible' => 0,
                'token_hash_visible' => 0,
                'storage_uploads_visible' => 0,
            ];
        }

        return [
            'plain_token_keys' => $this->countAuditMetadataLike($pdo, '%"token"%'),
            'password_hash_visible' => $this->countAuditMetadataLike($pdo, '%password_hash%'),
            'token_hash_visible' => $this->countAuditMetadataLike($pdo, '%token_hash%'),
            'storage_uploads_visible' => $this->countAuditMetadataLike($pdo, '%storage/uploads%'),
        ];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function routeChecks(): array
    {
        $routes = $this->contents('routes/web.php');
        $private = [];
        $public = [];
        $forbidden = [];

        foreach (self::CRITICAL_PRIVATE_ROUTES as $label => $needle) {
            $private[$label] = str_contains($routes, $needle);
        }

        foreach (self::CRITICAL_PUBLIC_ROUTES as $label => $needle) {
            $public[$label] = str_contains($routes, $needle);
        }

        foreach (self::FORBIDDEN_PUBLIC_ROUTE_NEEDLES as $needle) {
            $forbidden[$needle] = !str_contains($routes, $needle);
        }

        return [
            'private' => $private,
            'public' => $public,
            'forbidden_absent' => $forbidden,
        ];
    }

    /**
     * @param list<string> $paths
     * @return array<string, string>
     */
    private function fileInventory(array $paths): array
    {
        $result = [];

        foreach ($paths as $path) {
            $result[$path] = file_exists(BASE_PATH . '/' . $path)
                ? 'PASS'
                : 'PENDIENTE_CONTROLADO';
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        $counts = [];

        foreach (self::REQUIRED_TABLES as $table) {
            $counts[$table] = $this->tableExists($pdo, $table)
                ? (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn()
                : -1;
        }

        return $counts;
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

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function activeRoleExists(PDO $pdo, string $code): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM roles
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['codigo' => $code]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function activePermissionExists(PDO $pdo, string $code): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM permisos
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['codigo' => $code]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function adminHasPermission(PDO $pdo, string $code): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN roles r ON r.id = rp.rol_id
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE r.codigo = :role_code
               AND r.activo = 1
               AND r.eliminado_en IS NULL
               AND p.codigo = :permission_code
               AND p.activo = 1
               AND p.eliminado_en IS NULL
               AND rp.activo = 1
               AND rp.eliminado_en IS NULL'
        );
        $statement->execute([
            'role_code' => 'ADMIN',
            'permission_code' => $code,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function duplicateCount(PDO $pdo, string $table, string $column): int
    {
        if (!$this->tableExists($pdo, $table) || !$this->columnExists($pdo, $table, $column)) {
            return 0;
        }

        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT ' . $column . '
                 FROM ' . $table . '
                 WHERE ' . $column . ' IS NOT NULL
                 GROUP BY ' . $column . '
                 HAVING COUNT(*) > 1
             ) duplicated'
        )->fetchColumn();
    }

    private function duplicateRolePermissions(PDO $pdo): int
    {
        if (!$this->tableExists($pdo, 'rol_permisos')) {
            return 0;
        }

        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT rol_id, permiso_id
                 FROM rol_permisos
                 GROUP BY rol_id, permiso_id
                 HAVING COUNT(*) > 1
             ) duplicated'
        )->fetchColumn();
    }

    private function orphanAlmacenes(PDO $pdo): int
    {
        if (!$this->tableExists($pdo, 'almacenes') || !$this->tableExists($pdo, 'empresas')) {
            return 0;
        }

        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM almacenes a
             LEFT JOIN empresas e ON e.id = a.empresa_id
             WHERE e.id IS NULL'
        )->fetchColumn();
    }

    private function orphanTiposCambio(PDO $pdo): int
    {
        if (!$this->tableExists($pdo, 'tipos_cambio') || !$this->tableExists($pdo, 'monedas')) {
            return 0;
        }

        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM tipos_cambio tc
             LEFT JOIN monedas origen ON origen.id = tc.moneda_origen_id
             LEFT JOIN monedas destino ON destino.id = tc.moneda_destino_id
             WHERE origen.id IS NULL OR destino.id IS NULL'
        )->fetchColumn();
    }

    private function invalidProductIds(PDO $pdo): int
    {
        if (!$this->tableExists($pdo, 'productos')) {
            return 0;
        }

        return (int) $pdo->query(
            "SELECT COUNT(*)
             FROM productos
             WHERE NOT REGEXP_LIKE(id_producto, '^[A-Z0-9]{1,16}$', 'c')"
        )->fetchColumn();
    }

    private function productOrphans(PDO $pdo, string $column, string $catalogTable): int
    {
        if (
            !$this->tableExists($pdo, 'productos')
            || !$this->tableExists($pdo, $catalogTable)
            || !$this->columnExists($pdo, 'productos', $column)
        ) {
            return 0;
        }

        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM productos p
             LEFT JOIN ' . $catalogTable . ' c ON c.id = p.' . $column . '
             WHERE p.' . $column . ' IS NOT NULL
               AND c.id IS NULL'
        )->fetchColumn();
    }

    private function countAuditMetadataLike(PDO $pdo, string $needle): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM auditoria_eventos
             WHERE metadata_json IS NOT NULL
               AND LOWER(metadata_json) LIKE LOWER(:needle)'
        );
        $statement->execute(['needle' => $needle]);

        return (int) $statement->fetchColumn();
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents(BASE_PATH . '/' . $path);

        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read ' . $path);
        }

        return $contents;
    }

    private function hasUncommittedPath(string $path): bool
    {
        exec('git status --short -- ' . escapeshellarg($path), $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Unable to inspect git status for ' . $path);
        }

        return $output !== [];
    }

    private function allowedUncommittedOnly(): bool
    {
        exec('git status --short --untracked-files=all', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Unable to inspect git status.');
        }

        foreach ($output as $line) {
            if (str_starts_with($line, '!! ')) {
                continue;
            }

            $path = trim(substr((string) $line, 3));

            if (!in_array($path, self::ALLOWED_UNCOMMITTED, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function allTrue(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                if (!$this->allTrue($value)) {
                    return false;
                }

                continue;
            }

            if ($value !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, int> $values
     */
    private function allZero(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== 0) {
                return false;
            }
        }

        return true;
    }
};
