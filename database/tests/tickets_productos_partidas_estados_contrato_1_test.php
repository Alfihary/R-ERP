<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP contract audit.');
        }

        $audit = [
            'git_scope' => $this->gitScope(),
            'current_surface' => $this->currentSurface(),
            'database_surface' => $this->databaseSurface($pdo, $expectedDatabase),
            'contract_documentation' => $this->contractDocumentation(),
            'critical_guardrails' => $this->criticalGuardrails(),
            'gap_analysis' => $this->gapAnalysis($pdo, $expectedDatabase),
        ];

        if (!$this->allTrue($audit['git_scope'])) {
            throw new RuntimeException('TP contract audit touched or requires forbidden functional surfaces.');
        }

        if (!$this->allTrue($audit['contract_documentation'])) {
            throw new RuntimeException('TP contract documentation is incomplete.');
        }

        if (!$this->allTrue($audit['critical_guardrails'])) {
            throw new RuntimeException('TP critical guardrails are not documented.');
        }

        return [
            'database' => $expectedDatabase,
            'phase' => 'TP-PARTIDAS-ESTADOS-CONTRATO-1',
            'audit_mode' => 'read_only_contract_and_gap_analysis',
            'cases' => $audit,
            'current_status' => [
                'ticket_product_routes' => $audit['current_surface']['routes_related'],
                'ticket_product_controllers' => $audit['current_surface']['controllers_related'],
                'ticket_product_domain_files' => $audit['current_surface']['domain_related'],
                'ticket_product_views' => $audit['current_surface']['views_related'],
                'ticket_tables_existing' => $audit['database_surface']['ticket_tables_existing'],
                'folio_service_available' => $audit['current_surface']['folio_service_available'],
                'mail_runtime_available' => $audit['current_surface']['mail_runtime_available'],
            ],
            'final_contract' => [
                'ticket_states' => [
                    'EN_REVISION',
                    'RESUELTO_PARCIAL',
                    'APROBADO',
                    'RECHAZADO',
                    'CANCELADO',
                ],
                'line_states' => [
                    'EN_REVISION',
                    'APROBADA',
                    'RECHAZADA',
                ],
                'folio_format' => 'CODIGOALMACEN-000000, example GU-000010',
                'approval_semantics' => 'documentary_only_never_creates_product_price_inventory_purchase_or_supplier',
            ],
            'detected_gaps' => $this->detectedGaps($audit),
            'risks' => [
                'Aprobar por ticket completo puede ocultar rechazos parciales si no se implementa estado por partida.',
                'Emitir folios fuera de la transaccion del ticket puede duplicar o saltar consecutivos.',
                'Crear producto real desde aprobacion documental mezclaria tickets con catalogos, precios e inventario.',
                'Adjuntos publicos o sin MIME real pueden exponer rutas fisicas o permitir archivos peligrosos.',
                'Correos directos desde controladores duplicarian logica y podrian filtrar datos sensibles.',
            ],
            'cleanup' => 'no_data_written_read_only_audit',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function gitScope(): array
    {
        return [
            'runner_is_database_only' => $this->fileExists('database/tickets-productos-partidas-estados-contrato.php'),
            'test_is_database_tests_only' => $this->fileExists('database/tests/tickets_productos_partidas_estados_contrato_1_test.php'),
            'contract_doc_exists' => $this->fileExists('docs/tickets-productos-partidas-estados-contrato-1.md'),
            'allowed_private_routes_controller_for_ticket_products' =>
                $this->allowedTicketProductRoutes()
                && $this->fileExists('app/Http/Controllers/ProductRequestTicketController.php')
                && str_contains($this->read('bootstrap/app.php'), 'ProductRequestTicketController'),
            'no_unexpected_ticket_routes_or_controllers' =>
                $this->onlyExpectedFiles('app/Http/Controllers', '/Ticket|Solicitud|AltaProducto/i', [
                    'app/Http/Controllers/ProductRequestTicketController.php',
                ])
                && $this->allowedTicketProductRoutes(),
            'ticket_service_files_are_authorized' => $this->onlyExpectedFiles('app/Domain/Tickets', '/\\.php$/i', [
                'app/Domain/Tickets/ProductRequestTicketService.php',
                'app/Domain/Tickets/ProductRequestTicketValidationException.php',
            ]),
            'ticket_repository_files_are_authorized' => $this->onlyExpectedFiles('app/Infrastructure/Repositories', '/Ticket|Solicitud|AltaProducto/i', [
                'app/Infrastructure/Repositories/ProductRequestTicketRepository.php',
            ]),
            'only_expected_ticket_ui_views_created' =>
                $this->onlyExpectedFiles('app/Views/tickets', '/\\.php$/i', [
                    'app/Views/tickets/productos/create.php',
                    'app/Views/tickets/productos/index.php',
                    'app/Views/tickets/productos/show.php',
                ]),
            'no_ticket_css_or_js_created' =>
                !$this->hasFiles('public/css', '/ticket|solicitud|alta/i')
                && !$this->hasFiles('public/js', '/ticket|solicitud|alta/i'),
            'permission_seed_is_authorized' => $this->fileExists(
                'database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php'
            ),
            'no_functional_ticket_seed_created' =>
                $this->onlyExpectedFiles('database/seeds', '/ticket|solicitud|alta/i', [
                    'database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php',
                ]),
            'no_ticket_mail_runtime_created' =>
                !$this->hasFiles('app/Domain/Mail', '/ticket|solicitud|alta/i')
                && !$this->hasFiles('app/Domain/Notifications', '/ticket|solicitud|alta/i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSurface(): array
    {
        $routes = $this->read('routes/web.php');
        $docsBase = $this->read('docs/base-datos.md');
        $docsFases = $this->read('docs/fases.md');
        $docsMail = $this->read('docs/mail-notifications.md');

        $routeLines = $this->matchingLines($routes, '/ticket|tickets|solicitud|alta/i');

        return [
            'routes_related' => $routeLines,
            'routes_count' => count($routeLines),
            'controllers_related' => $this->relativeFiles('app/Http/Controllers', '/Ticket|Solicitud|AltaProducto/i'),
            'domain_related' => $this->relativeFiles('app/Domain/Tickets', '/.*/'),
            'repositories_related' => $this->relativeFiles('app/Infrastructure/Repositories', '/Ticket|Solicitud|AltaProducto/i'),
            'views_related' => $this->relativeFiles('app/Views/tickets', '/.*/'),
            'db_tickets_phase_documented' =>
                str_contains($docsBase, 'DB-TICKETS-7')
                && str_contains($docsFases, 'ticket_partidas')
                && str_contains($docsFases, 'ticket_archivos'),
            'folio_service_available' =>
                $this->fileExists('app/Domain/Folios/FolioService.php')
                && $this->fileExists('app/Infrastructure/Repositories/FolioRepository.php'),
            'mail_runtime_available' =>
                $this->hasFiles('app/Domain/Mail', '/\\.php$/i')
                || $this->hasFiles('app/Domain/Notifications', '/\\.php$/i'),
            'mail_templates_conceptual' =>
                str_contains($docsMail, 'ticket_creado')
                && str_contains($docsMail, 'ticket_aprobado')
                && str_contains($docsMail, 'ticket_rechazado'),
            'current_approval_rejection_by_ticket' => false,
            'current_approval_rejection_by_line' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseSurface(PDO $pdo, string $database): array
    {
        $tables = [
            'tickets',
            'ticket_partidas',
            'ticket_comentarios',
            'ticket_archivos',
            'series_documentales',
            'documentos_folios',
            'productos',
            'producto_precios',
            'inventario_existencias',
            'movimientos_inventario',
            'compras',
            'proveedores',
            'mail_templates',
            'mail_queue',
            'mail_logs',
        ];

        $exists = [];
        $columns = [];

        foreach ($tables as $table) {
            $exists[$table] = $this->tableExists($pdo, $database, $table);
            $columns[$table] = $exists[$table] ? $this->columns($pdo, $database, $table) : [];
        }

        return [
            'tables' => $exists,
            'ticket_tables_existing' => array_filter(
                $exists,
                static fn (bool $exists, string $table): bool => $exists && str_starts_with($table, 'ticket'),
                ARRAY_FILTER_USE_BOTH
            ),
            'ticket_status_columns' => [
                'tickets' => $this->matchingValues($columns['tickets'], '/estado|estatus/i'),
                'ticket_partidas' => $this->matchingValues($columns['ticket_partidas'], '/estado|estatus|resuelto|rechazo/i'),
            ],
            'ticket_folio_columns' => $this->matchingValues($columns['tickets'], '/folio|serie|almacen/i'),
            'folio_tables_available' => ($exists['series_documentales'] ?? false) && ($exists['documentos_folios'] ?? false),
            'mail_tables_available' =>
                ($exists['mail_templates'] ?? false)
                || ($exists['mail_queue'] ?? false)
                || ($exists['mail_logs'] ?? false),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function contractDocumentation(): array
    {
        $doc = $this->read('docs/tickets-productos-partidas-estados-contrato-1.md');
        $lower = mb_strtolower($doc, 'UTF-8');

        $required = [
            'TP-PARTIDAS-ESTADOS-CONTRATO-1',
            'Este flujo nunca debe crear productos reales',
            'Este flujo nunca debe crear precios',
            'Este flujo nunca debe crear inventario',
            'Este flujo nunca debe generar claves definitivas',
            'Este flujo nunca debe crear compras',
            'CODIGOALMACEN-000000',
            'GU-000010',
            'EN_REVISION',
            'RESUELTO_PARCIAL',
            'APROBADO',
            'RECHAZADO',
            'CANCELADO',
            'APROBADA',
            'RECHAZADA',
            'tickets_productos.resolver',
            'tickets_productos.adjuntos.ver',
        ];

        return [
            'documents_phase' => str_contains($doc, 'TP-PARTIDAS-ESTADOS-CONTRATO-1'),
            'documents_critical_never_rules' => $this->containsAll($doc, array_slice($required, 1, 5)),
            'documents_ticket_creation_contract' =>
                str_contains($lower, 'empresa')
                && str_contains($lower, 'almacén')
                && str_contains($lower, 'observaciones generales')
                && str_contains($lower, 'una o varias partidas'),
            'documents_line_fields' =>
                str_contains($lower, 'proveedor existente')
                && str_contains($lower, 'proveedor libre')
                && str_contains($lower, 'unidad sat')
                && str_contains($lower, 'clave sat')
                && str_contains($lower, 'costo sugerido'),
            'documents_folio_contract' =>
                str_contains($doc, 'CODIGOALMACEN-000000')
                && str_contains($doc, 'GU-000010')
                && str_contains($lower, 'transaccional')
                && str_contains($lower, 'no debe depender del id global'),
            'documents_ticket_states' => $this->containsAll($doc, [
                'EN_REVISION',
                'RESUELTO_PARCIAL',
                'APROBADO',
                'RECHAZADO',
                'CANCELADO',
            ]),
            'documents_line_states' => $this->containsAll($doc, ['APROBADA', 'RECHAZADA']),
            'documents_line_resolution_data' =>
                str_contains($lower, 'usuario que resolvió')
                && str_contains($lower, 'fecha/hora de resolución')
                && str_contains($lower, 'motivo de rechazo'),
            'documents_mail_events' =>
                str_contains($lower, 'al crear ticket')
                && str_contains($lower, 'al resolver una partida')
                && str_contains($lower, 'al cerrar el ticket completo'),
            'documents_attachment_security' =>
                str_contains($lower, 'mime real')
                && str_contains($lower, 'doble extensión')
                && str_contains($lower, 'storage privado'),
            'documents_permissions' =>
                str_contains($doc, 'tickets_productos.ver')
                && str_contains($doc, 'tickets_productos.crear')
                && str_contains($doc, 'tickets_productos.resolver')
                && str_contains($doc, 'tickets_productos.correo.reenviar'),
            'documents_audit_events' =>
                str_contains($lower, 'aprobación de partida')
                && str_contains($lower, 'rechazo de partida')
                && str_contains($lower, 'envío de correo'),
            'documents_no_scope' =>
                str_contains($lower, 'creación real de producto')
                && str_contains($lower, 'compras')
                && str_contains($lower, 'inventario')
                && str_contains($lower, 'precios'),
            'documents_current_state' => str_contains($lower, 'estado actual detectado'),
            'documents_gaps' => str_contains($lower, 'brechas detectadas'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function criticalGuardrails(): array
    {
        $doc = $this->read('docs/tickets-productos-partidas-estados-contrato-1.md');
        $test = $this->read('database/tests/tickets_productos_partidas_estados_contrato_1_test.php');

        return [
            'approval_never_creates_product_documented' =>
                str_contains($doc, 'Aprobar una partida NO crea producto real')
                && str_contains($doc, 'insertar en productos'),
            'pricing_inventory_purchase_forbidden_documented' =>
                str_contains($doc, 'producto_precios')
                && str_contains($doc, 'existencias_producto')
                && str_contains($doc, 'crear compra'),
            'guardrails_are_asserted_by_audit' =>
                str_contains($test, 'approval_never_creates_product_documented')
                && str_contains($test, 'pricing_inventory_purchase_forbidden_documented'),
            'audit_is_read_only' =>
                $this->matchingLines($test, '/\\$pdo->(?:exec|beginTransaction|commit|rollBack)\\(/') === []
                && count($this->matchingLines($test, '/\\$pdo->prepare\\(/')) === 2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gapAnalysis(PDO $pdo, string $database): array
    {
        $surface = $this->currentSurface();
        $db = $this->databaseSurface($pdo, $database);

        return [
            'ticket_product_routes_missing' => !$this->allowedTicketProductRoutes(),
            'controller_missing' => $surface['controllers_related'] !== [
                'app/Http/Controllers/ProductRequestTicketController.php',
            ],
            'service_missing' => !$this->onlyExpectedFiles('app/Domain/Tickets', '/\\.php$/i', [
                'app/Domain/Tickets/ProductRequestTicketService.php',
                'app/Domain/Tickets/ProductRequestTicketValidationException.php',
            ]),
            'repository_missing' => !$this->onlyExpectedFiles('app/Infrastructure/Repositories', '/Ticket|Solicitud|AltaProducto/i', [
                'app/Infrastructure/Repositories/ProductRequestTicketRepository.php',
            ]),
            'views_missing' => $surface['views_related'] === ['app/Views/tickets/.gitkeep'],
            'ticket_tables_missing' => $db['ticket_tables_existing'] === [],
            'line_status_missing' => $db['ticket_status_columns']['ticket_partidas'] === [],
            'warehouse_folio_integration_missing' => $db['ticket_folio_columns'] === [],
            'mail_runtime_missing' => $surface['mail_runtime_available'] === false,
            'approval_by_line_missing' => $surface['current_approval_rejection_by_line'] === false,
            'no_product_creation_guardrail_test_missing' => !$this->repositoryHas(
                'database/tests',
                '/ticket.*no.*crea.*producto|no.*crea.*producto.*ticket|tickets_productos/i',
                'database/tests/tickets_productos_partidas_estados_contrato_1_test.php'
            ),
        ];
    }

    /**
     * @param array<string, mixed> $audit
     * @return list<string>
     */
    private function detectedGaps(array $audit): array
    {
        $gaps = [];
        $gapAnalysis = $audit['gap_analysis'];

        foreach ($gapAnalysis as $name => $isGap) {
            if ($isGap === true) {
                $gaps[] = $name;
            }
        }

        return $gaps;
    }

    private function tableExists(PDO $pdo, string $database, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :schema_name
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['schema_name' => $database, 'table_name' => $table]);

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
             WHERE TABLE_SCHEMA = :schema_name
               AND TABLE_NAME = :table_name
             ORDER BY ORDINAL_POSITION'
        );
        $statement->execute(['schema_name' => $database, 'table_name' => $table]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function matchingValues(array $values, string $pattern): array
    {
        return array_values(array_filter(
            $values,
            static fn (string $value): bool => preg_match($pattern, $value) === 1
        ));
    }

    /**
     * @return list<string>
     */
    private function matchingLines(string $content, string $pattern): array
    {
        $lines = preg_split('/\\R/', $content) ?: [];
        $matches = [];

        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $matches[] = ((int) $index + 1) . ': ' . trim($line);
            }
        }

        return $matches;
    }

    private function read(string $relativePath): string
    {
        $path = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (!is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    private function fileExists(string $relativePath): bool
    {
        return is_file(BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    }

    private function allowedTicketProductRoutes(): bool
    {
        $routes = $this->read('routes/web.php');

        return $this->containsAll($routes, [
            "'/tickets/productos'",
            "'/tickets/productos/crear'",
            "'/tickets/productos/{id}'",
            "'/tickets/productos/{id}/partidas/{partidaId}/aprobar'",
            "'/tickets/productos/{id}/partidas/{partidaId}/rechazar'",
            "'/tickets/productos/{id}/cancelar'",
            "tickets_productos.ver",
            "tickets_productos.crear",
            "tickets_productos.resolver",
            "tickets_productos.cancelar",
            "AuthMiddleware",
            "PermissionMiddleware",
        ])
            && substr_count($routes, "'/tickets/productos'") === 2
            && substr_count($routes, "'/tickets/productos/crear'") === 1
            && substr_count($routes, "'/tickets/productos/{id}'") === 1
            && substr_count($routes, "'/tickets/productos/{id}/partidas/{partidaId}/aprobar'") === 1
            && substr_count($routes, "'/tickets/productos/{id}/partidas/{partidaId}/rechazar'") === 1
            && substr_count($routes, "'/tickets/productos/{id}/cancelar'") === 1
            && !str_contains($routes, '/tickets-productos');
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

    /**
     * @param list<string> $expected
     */
    private function onlyExpectedFiles(
        string $relativeDirectory,
        string $pattern,
        array $expected
    ): bool {
        $files = $this->relativeFiles($relativeDirectory, $pattern);
        sort($files);
        sort($expected);

        return $files === $expected;
    }

    private function repositoryHas(string $relativeDirectory, string $pattern, string $excludedFile = ''): bool
    {
        foreach ($this->relativeFiles($relativeDirectory, '/.*/') as $file) {
            if ($file === $excludedFile) {
                continue;
            }

            if (preg_match('/\\.php$/', $file) !== 1) {
                continue;
            }

            if (preg_match($pattern, $this->read($file)) === 1) {
                return true;
            }
        }

        return false;
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
     * @param list<string> $needles
     */
    private function containsAll(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }
};
