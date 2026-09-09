<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;

return new class implements DatabaseTest {
    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP attachments contract audit.');
        }

        $migration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';

        if (!$migration instanceof Migration) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1 dependencies are invalid.');
        }

        $runner = new MigrationRunner($pdo);
        $beforeCounts = $this->operationalCounts($pdo);
        $beforeStorage = $this->storageSnapshot();
        $migrationState = $runner->migrate($migration);

        try {
            $audit = [
                'schema' => $this->schemaCases($pdo, $expectedDatabase),
                'runtime_contract' => $this->runtimeContractCases(),
                'documentation' => $this->documentationCases(),
                'scope_guardrails' => $this->scopeGuardrails(),
                'operational_guardrails' => $this->operationalGuardrails($pdo, $beforeCounts),
                'storage_guardrails' => $this->storageGuardrails($beforeStorage, $this->storageSnapshot()),
            ];
        } finally {
            if ($migrationState === 'applied') {
                $runner->rollback($migration);
            }
        }

        $afterCounts = $this->operationalCounts($pdo);
        $afterStorage = $this->storageSnapshot();

        $audit['cleanup'] = [
            'migration_rolled_back_when_applied' => $migrationState !== 'applied'
                || !$this->tableExists($pdo, $expectedDatabase, 'tickets_productos_adjuntos'),
            'no_operational_counts_changed' => $beforeCounts === $afterCounts,
            'no_storage_files_created' => $beforeStorage === $afterStorage,
        ];

        if (!$this->allTrue($audit)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1 assertions failed: '
                . json_encode($audit, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'phase' => 'TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1',
            'audit_mode' => 'read_only_attachment_contract_with_authorized_runtime_upload',
            'migration_state' => $migrationState,
            'table' => 'tickets_productos_adjuntos',
            'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
            'allowed_mime_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
            'max_file_size' => '5 MB',
            'view_permission' => 'tickets_productos.adjuntos.ver',
            'cases' => $audit,
            'operational_counts_before' => $beforeCounts,
            'operational_counts_after' => $afterCounts,
            'storage_files_before' => $beforeStorage['file_count'],
            'storage_files_after' => $afterStorage['file_count'],
            'cleanup' => 'migration_rolled_back_if_applied_no_files_written_no_operational_data_written',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function schemaCases(PDO $pdo, string $database): array
    {
        $columns = $this->columns($pdo, $database, 'tickets_productos_adjuntos');
        $foreignKeys = $this->foreignKeys($pdo, $database, 'tickets_productos_adjuntos');
        $indexes = $this->indexes($pdo, $database, 'tickets_productos_adjuntos');
        $migration = $this->read('database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php');

        return [
            'attachments_table_exists' => $this->tableExists($pdo, $database, 'tickets_productos_adjuntos'),
            'has_ticket_relation_column' => in_array('ticket_producto_id', $columns, true),
            'has_optional_partida_relation_column' => in_array('partida_id', $columns, true)
                && $this->nullableColumn($pdo, $database, 'tickets_productos_adjuntos', 'partida_id'),
            'has_metadata_columns' => $this->containsAll($columns, [
                'nombre_original',
                'nombre_guardado',
                'ruta_relativa',
                'mime',
                'extension',
                'tamano_bytes',
                'hash_sha256',
            ]),
            'has_ticket_foreign_key' => in_array(
                'ticket_producto_id->tickets_productos.id',
                $foreignKeys,
                true
            ),
            'has_partida_foreign_key' => in_array(
                'partida_id->tickets_productos_partidas.id',
                $foreignKeys,
                true
            ),
            'has_user_foreign_key' => in_array('subido_por_usuario_id->usuarios.id', $foreignKeys, true),
            'has_attachment_indexes' => $this->containsAll($indexes, [
                'idx_tickets_productos_adjuntos_ticket',
                'idx_tickets_productos_adjuntos_partida',
                'idx_tickets_productos_adjuntos_subido_por',
                'idx_tickets_productos_adjuntos_mime',
                'idx_tickets_productos_adjuntos_created_at',
            ]),
            'has_safe_relative_route_check' => $this->textContainsAll($migration, [
                'ruta_relativa',
                "NOT LIKE '/%'",
                'NOT REGEXP',
                "NOT LIKE '%..%'",
            ]),
            'has_extension_size_hash_checks' => $this->textContainsAll($migration, [
                'extension',
                'tamano_bytes > 0',
                'hash_sha256',
            ]),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function runtimeContractCases(): array
    {
        $routes = $this->read('routes/web.php');
        $controller = $this->read('app/Http/Controllers/ProductRequestTicketController.php');
        $service = $this->read('app/Domain/Tickets/ProductRequestTicketService.php');
        $repository = $this->read('app/Infrastructure/Repositories/ProductRequestTicketRepository.php');
        $safeUpload = $this->read('app/Support/SafeUpload.php');
        $show = $this->read('app/Views/tickets/productos/show.php');
        $index = $this->read('app/Views/tickets/productos/index.php');
        $create = $this->read('app/Views/tickets/productos/create.php');
        $ticketRuntime = $routes . "\n" . $controller . "\n" . $service . "\n" . $repository . "\n"
            . $safeUpload . "\n" . $show . "\n" . $index . "\n" . $create;

        return [
            'authorized_attachment_upload_route_exists' => str_contains($routes, "'/tickets/productos/{id}/adjuntos'")
                && str_contains($routes, '->attachment($request, $params)')
                && str_contains($routes, "\$productTicketMiddleware('tickets_productos.adjuntos.ver')"),
            'authorized_attachment_upload_uses_private_stack' => str_contains(
                $routes,
                'new PermissionMiddleware($auth, $permissions, $permission)'
            ) && (
                str_contains($routes, 'CsrfMiddleware')
                || str_contains($this->read('bootstrap/app.php'), 'CsrfMiddleware')
            ),
            'authorized_attachment_controller_service_repository_exists' =>
                str_contains($controller, 'function attachment(Request $request, array $params): Response')
                && str_contains($controller, '->agregarAdjunto(')
                && str_contains($service, 'function agregarAdjunto(')
                && str_contains($repository, 'function agregarAdjunto('),
            'safe_upload_runtime_validates_contract' => $this->textContainsAll($safeUpload, [
                'finfo',
                'MAX_BYTES = 5 * 1024 * 1024',
                'hasDangerousDoubleExtension',
                'move_uploaded_file',
                'storage/private/tickets_productos',
                'random_bytes',
            ]),
            'no_attachment_download_or_preview_route' => !preg_match(
                '#/tickets/productos/\{id\}/(?:adjuntos|archivos|attachments)/\{[^}]+\}/(?:descargar|download|preview|ver)#i',
                $routes
            ),
            'no_public_attachment_route' => !preg_match('#/v/\{slug\}.*(?:adjuntos|archivos|attachments)#i', $routes),
            'no_attachment_controller_created' => $this->relativeFiles(
                'app/Http/Controllers',
                '/Adjunto|Adjuntos|Attachment|Attachments|Archivo|Archivos/i'
            ) === [],
            'no_upload_runtime_in_index_or_create' => !preg_match(
                '/type=["\']file["\']|enctype=["\']multipart\/form-data/i',
                $index . $create
            ),
            'show_has_authorized_upload_form' => str_contains($show, 'action="/tickets/productos/<?= e($ticketId) ?>/adjuntos"')
                && str_contains($show, 'enctype="multipart/form-data"')
                && str_contains($show, 'name="adjunto"')
                && str_contains($show, 'name="partida_id"'),
            'no_direct_download_headers' => !preg_match('/Content-Disposition|application\/octet-stream|X-Accel-Redirect/i', $ticketRuntime),
            'attachments_runtime_replaces_placeholder' => str_contains($show, 'Los adjuntos sirven como soporte para revisar la solicitud.')
                && !str_contains($show, 'Adjuntos documentales pendientes de fase posterior.'),
            'comments_route_is_not_attachment_route' => str_contains($routes, '/tickets/productos/{id}/comentarios')
                && str_contains($routes, '/tickets/productos/{id}/adjuntos'),
            'internal_names_and_paths_not_displayed' =>
                !str_contains($show, "ruta_relativa")
                && !str_contains($show, "nombre_guardado")
                && !str_contains($show, "storage/private")
                && !str_contains($show, "storage/uploads"),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function documentationCases(): array
    {
        $doc = $this->read('docs/tickets-productos-partidas-estados-adjuntos-contrato-1.md');

        return [
            'documents_phase_and_objective' => $this->textContainsAll($doc, [
                'TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1',
                'adjuntos documentales',
                'runtime autorizado',
                'no crea endpoints de descarga',
            ]),
            'documents_base_table_and_scope' => $this->textContainsAll($doc, [
                'tickets_productos_adjuntos',
                'ticket completo',
                'partida específica',
                'pertenecer al ticket',
            ]),
            'documents_allowed_extensions' => $this->textContainsAll($doc, [
                'pdf',
                'jpg',
                'jpeg',
                'png',
                'webp',
            ]),
            'documents_real_mime_types' => $this->textContainsAll($doc, [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
                'finfo',
            ]),
            'documents_rejections' => $this->textContainsAll($doc, [
                'archivo.pdf.php',
                'imagen.jpg.php',
                'comprobante.png.exe',
                'svg',
                'zip',
                'ejecutables',
            ]),
            'documents_max_size' => str_contains($doc, '5 MB'),
            'documents_safe_names_and_paths' => $this->textContainsAll($doc, [
                'nombre original',
                'nombre almacenado',
                'aleatorio',
                'ruta relativa segura',
                '..',
                'file://',
                'URLs externas',
            ]),
            'documents_private_storage' => $this->textContainsAll($doc, [
                'storage/private/tickets_productos/{ticket_id}',
                'fuera de public',
                'public/uploads',
                'storage/uploads/productos',
                'storage/uploads/usuarios',
            ]),
            'documents_view_permission' => str_contains($doc, 'tickets_productos.adjuntos.ver'),
            'documents_no_new_permission' => $this->textContainsAll($doc, [
                'No se crea permiso nuevo',
                'futura fase deberá decidir',
            ]),
            'documents_event_check_constraint' => str_contains($doc, 'ADJUNTO_CARGADO')
                && str_contains($doc, 'ADJUNTO_AGREGADO'),
            'documents_operational_guardrails' => $this->textContainsAll($doc, [
                'nunca debe crear productos reales',
                'nunca debe crear precios',
                'nunca debe crear inventario',
                'nunca debe crear compras',
                'nunca debe crear proveedores',
            ]),
            'documents_next_phase' => str_contains($doc, 'TP-PARTIDAS-ESTADOS-ADJUNTOS-IMPLEMENTACION-1'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function scopeGuardrails(): array
    {
        $routes = $this->read('routes/web.php');
        $bootstrap = $this->read('bootstrap/app.php');
        $controller = $this->read('app/Http/Controllers/ProductRequestTicketController.php');
        $service = $this->read('app/Domain/Tickets/ProductRequestTicketService.php');
        $repository = $this->read('app/Infrastructure/Repositories/ProductRequestTicketRepository.php');

        return [
            'routes_not_modified_for_attachment_contract_marker' => !str_contains(
                $routes,
                'TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1'
            ),
            'bootstrap_not_modified_for_attachment_contract_marker' => !str_contains(
                $bootstrap,
                'TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1'
            ),
            'controller_not_modified_for_attachment_contract_marker' => !str_contains(
                $controller,
                'TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1'
            ),
            'service_not_modified_for_attachment_contract_marker' => !str_contains(
                $service,
                'TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1'
            ),
            'repository_not_modified_for_attachment_contract_marker' => !str_contains(
                $repository,
                'TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1'
            ),
            'views_not_modified_for_attachment_contract_marker' =>
                !str_contains($this->read('app/Views/tickets/productos/show.php'), 'TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1')
                && !str_contains($this->read('app/Views/tickets/productos/index.php'), 'TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1')
                && !str_contains($this->read('app/Views/tickets/productos/create.php'), 'TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1'),
            'no_js_created' => !$this->hasFiles('public/js', '/ticket|solicitud|alta|adjunto|archivo/i'),
            'no_ticket_mail_runtime_created' =>
                !$this->hasFiles('app/Domain/Mail', '/ticket|solicitud|alta/i')
                && !$this->hasFiles('app/Domain/Notifications', '/ticket|solicitud|alta/i'),
            'no_new_attachment_permission_declared' =>
                !str_contains($this->read('database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php'), 'tickets_productos.adjuntos.crear')
                && !str_contains($this->read('database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php'), 'tickets_productos.adjuntos.descargar'),
            'no_migration_for_attachment_contract_created' => !$this->hasFiles(
                'database/migrations',
                '/adjuntos.*contrato|attachments.*contract|ADJUNTOS-CONTRATO/i'
            ),
            'no_functional_seed_for_attachment_contract_created' => !$this->hasFiles(
                'database/seeds',
                '/adjuntos.*contrato|attachments.*contract|ADJUNTOS-CONTRATO/i'
            ),
        ];
    }

    /**
     * @param array<string, int> $before
     * @return array<string, bool>
     */
    private function operationalGuardrails(PDO $pdo, array $before): array
    {
        $during = $this->operationalCounts($pdo);

        return [
            'no_product_created' => $before['productos'] === $during['productos'],
            'no_price_created' => $before['producto_precios'] === $during['producto_precios'],
            'no_stock_created' => $before['existencias_producto'] === $during['existencias_producto'],
            'no_inventory_created' => $before['inventario_existencias'] === $during['inventario_existencias'],
            'no_inventory_movement_created' => $before['movimientos_inventario'] === $during['movimientos_inventario'],
            'no_purchase_created' => $before['compras'] === $during['compras'],
            'no_supplier_created' => $before['proveedores'] === $during['proveedores'],
        ];
    }

    /**
     * @param array{file_count:int, hash:string} $before
     * @param array{file_count:int, hash:string} $during
     * @return array<string, bool>
     */
    private function storageGuardrails(array $before, array $during): array
    {
        return [
            'no_storage_files_created_by_audit' => $before === $during,
            'private_ticket_storage_has_no_audit_files_created' => $before === $during,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function operationalCounts(PDO $pdo): array
    {
        return [
            'productos' => $this->optionalCount($pdo, 'productos'),
            'producto_precios' => $this->optionalCount($pdo, 'producto_precios'),
            'existencias_producto' => $this->optionalCount($pdo, 'existencias_producto'),
            'inventario_existencias' => $this->optionalCount($pdo, 'inventario_existencias'),
            'movimientos_inventario' => $this->optionalCount($pdo, 'movimientos_inventario'),
            'compras' => $this->optionalCount($pdo, 'compras'),
            'proveedores' => $this->optionalCount($pdo, 'proveedores'),
        ];
    }

    private function optionalCount(PDO $pdo, string $table): int
    {
        if (!$this->tableExists($pdo, (string) $pdo->query('SELECT DATABASE()')->fetchColumn(), $table)) {
            return 0;
        }

        return (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    private function tableExists(PDO $pdo, string $database, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :database_name
               AND TABLE_NAME = :table_name'
        );
        $statement->execute([
            'database_name' => $database,
            'table_name' => $table,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @return list<string>
     */
    private function columns(PDO $pdo, string $database, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :database_name
               AND TABLE_NAME = :table_name
             ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([
            'database_name' => $database,
            'table_name' => $table,
        ]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function nullableColumn(PDO $pdo, string $database, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :database_name
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $statement->execute([
            'database_name' => $database,
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (string) $statement->fetchColumn() === 'YES';
    }

    /**
     * @return list<string>
     */
    private function foreignKeys(PDO $pdo, string $database, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT CONCAT(COLUMN_NAME, "->", REFERENCED_TABLE_NAME, ".", REFERENCED_COLUMN_NAME)
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = :database_name
               AND TABLE_NAME = :table_name
               AND REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION'
        );
        $statement->execute([
            'database_name' => $database,
            'table_name' => $table,
        ]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<string>
     */
    private function indexes(PDO $pdo, string $database, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT DISTINCT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = :database_name
               AND TABLE_NAME = :table_name
             ORDER BY INDEX_NAME'
        );
        $statement->execute([
            'database_name' => $database,
            'table_name' => $table,
        ]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<string>
     */
    private function checks(PDO $pdo, string $database, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT CHECK_CLAUSE
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = :database_name
               AND CONSTRAINT_NAME IN (
                   SELECT CONSTRAINT_NAME
                   FROM information_schema.TABLE_CONSTRAINTS
                   WHERE TABLE_SCHEMA = :table_schema_name
                     AND TABLE_NAME = :table_name
                     AND CONSTRAINT_TYPE = "CHECK"
               )
             ORDER BY CONSTRAINT_NAME'
        );
        $statement->execute([
            'database_name' => $database,
            'table_schema_name' => $database,
            'table_name' => $table,
        ]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array{file_count:int, hash:string}
     */
    private function storageSnapshot(): array
    {
        $directory = BASE_PATH . DIRECTORY_SEPARATOR . 'storage';

        if (!is_dir($directory)) {
            return ['file_count' => 0, 'hash' => hash('sha256', '')];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $files[] = str_replace(
                [BASE_PATH . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
                ['', '/'],
                $file->getPathname()
            ) . ':' . $file->getSize();
        }

        sort($files);

        return [
            'file_count' => count($files),
            'hash' => hash('sha256', implode("\n", $files)),
        ];
    }

    /**
     * @return list<string>
     */
    private function relativeFiles(string $relativeDirectory, string $pattern): array
    {
        $directory = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = str_replace(
                [BASE_PATH . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
                ['', '/'],
                $file->getPathname()
            );

            if (preg_match($pattern, $relative) === 1) {
                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    private function hasFiles(string $relativeDirectory, string $pattern): bool
    {
        return $this->relativeFiles($relativeDirectory, $pattern) !== [];
    }

    private function read(string $relativePath): string
    {
        $path = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @param list<string> $haystack
     * @param list<string> $needles
     */
    private function containsAll(array $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!in_array($needle, $haystack, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $needles
     */
    private function textContainsAll(string $haystack, array $needles): bool
    {
        $haystack = mb_strtolower($haystack, 'UTF-8');

        foreach ($needles as $needle) {
            if (!str_contains($haystack, mb_strtolower($needle, 'UTF-8'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function allTrue(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                if (!$this->allTrue($item)) {
                    return false;
                }
            } elseif ($item !== true) {
                return false;
            }
        }

        return true;
    }
};
