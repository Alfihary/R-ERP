<?php

declare(strict_types=1);

use App\Core\Session;
use App\Infrastructure\Database\DatabaseTest;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private PDO $pdo;
    private string $database;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $this->database = $expectedDatabase;

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-UI-PULIDO-1.');
        }

        $before = $this->operationalCounts();
        $html = $this->renderedViews();
        $this->closeSession();
        $during = $this->operationalCounts();
        $after = $this->operationalCounts();

        $results = [
            'views' => [
                'index_exists' => is_file(BASE_PATH . '/app/Views/tickets/productos/index.php'),
                'create_exists' => is_file(BASE_PATH . '/app/Views/tickets/productos/create.php'),
                'show_exists' => is_file(BASE_PATH . '/app/Views/tickets/productos/show.php'),
                'index_keeps_title' => str_contains($html['index'], 'Tickets de productos'),
                'index_keeps_new_ticket' => str_contains($html['index'], 'Nuevo ticket'),
                'create_keeps_post_action' => str_contains($html['create'], 'method="post" action="/tickets/productos"'),
                'create_keeps_csrf_field' => str_contains($html['create'], 'name="_token"'),
                'create_keeps_documentary_warning' => str_contains($html['create'], 'Este ticket es documental y no crea productos reales.'),
                'show_keeps_folio_state_lines' =>
                    str_contains($html['show'], 'GU-000010')
                    && str_contains($html['show'], 'EN_REVISION')
                    && str_contains($html['show'], 'Partidas'),
                'show_keeps_action_forms_csrf' =>
                    str_contains($html['show'], '/aprobar')
                    && str_contains($html['show'], '/rechazar')
                    && str_contains($html['show'], '/cancelar')
                    && substr_count($html['show'], 'name="_token"') >= 3,
                'show_keeps_documentary_warning' => str_contains($html['show'], 'Autorizar una partida no crea el producto en el catálogo.'),
            ],
            'visual_contract' => [
                'css_module_exists' => is_file(BASE_PATH . '/public/css/modules/tickets-productos.css'),
                'views_load_css_module' => $this->allViewsContain('/css/modules/tickets-productos.css'),
                'uses_existing_core_css' => $this->allViewsContain('/css/core/app.css'),
                'uses_ticket_products_prefix' =>
                    substr_count($this->read('public/css/modules/tickets-productos.css'), 'ticket-products__') >= 15,
                'uses_cards_tables_forms_alerts_badges' =>
                    $this->allViewsContain('ticket-products__')
                    && str_contains($html['index'], 'data-table')
                    && str_contains($html['create'], 'field')
                    && str_contains($html['show'], 'badge')
                    && str_contains($html['show'], 'home-section'),
                'status_badges_declared' =>
                    str_contains($this->read('public/css/modules/tickets-productos.css'), 'ticket-products__badge--en_revision')
                    && str_contains($this->read('public/css/modules/tickets-productos.css'), 'ticket-products__badge--resuelto_parcial')
                    && str_contains($this->read('public/css/modules/tickets-productos.css'), 'ticket-products__badge--aprobado')
                    && str_contains($this->read('public/css/modules/tickets-productos.css'), 'ticket-products__badge--rechazado')
                    && str_contains($this->read('public/css/modules/tickets-productos.css'), 'ticket-products__badge--cancelado'),
                'responsive_rules_declared' => str_contains($this->read('public/css/modules/tickets-productos.css'), '@media'),
            ],
            'security' => [
                'outputs_escape_malicious_values' =>
                    str_contains($html['show'], '&lt;script&gt;alert(1)&lt;/script&gt;')
                    && !str_contains($html['show'], '<script>alert(1)</script>'),
                'views_use_escape_helper' => $this->viewsUseEscapeHelper(),
                'no_storage_uploads_in_html' => !$this->containsAny($html, ['storage/uploads']),
                'no_physical_paths_in_html' => !$this->containsAny($html, ['C:\\', '/var/', 'BASE_PATH']),
                'no_sensitive_tokens_in_html' => !$this->containsAny($html, ['password_hash', 'token_hash', 'auth_user']),
            ],
            'scope_guardrails' => [
                'no_routes_modified_for_ui_polish' => $this->fileDoesNotContain('routes/web.php', 'tickets-productos-partidas-estados-ui-pulido'),
                'no_bootstrap_modified_for_ui_polish' => $this->fileDoesNotContain('bootstrap/app.php', 'tickets-productos-partidas-estados-ui-pulido'),
                'service_not_modified_for_ui_polish' => $this->fileDoesNotContain('app/Domain/Tickets/ProductRequestTicketService.php', 'tickets-productos-partidas-estados-ui-pulido'),
                'repository_not_modified_for_ui_polish' => $this->fileDoesNotContain('app/Infrastructure/Repositories/ProductRequestTicketRepository.php', 'tickets-productos-partidas-estados-ui-pulido'),
                'only_expected_ticket_css' => $this->onlyExpectedFiles(
                    'public/css/modules',
                    '/tickets.*productos|productos.*tickets/i',
                    ['public/css/modules/tickets-productos.css']
                ),
                'no_ticket_js_created' => !$this->hasFiles('public/js', '/tickets.*productos|productos.*tickets/i'),
                'no_mail_runtime_created' =>
                    !$this->hasFiles('app/Domain/Mail', '/ticket|solicitud|alta/i')
                    && !$this->hasFiles('app/Domain/Notifications', '/ticket|solicitud|alta/i'),
                'no_real_attachments_created' => !$this->hasFiles('storage', '/tickets-productos|ticket|solicitud/i'),
            ],
            'operational_guardrails' => [
                'counts_unchanged_after_render' => $before === $during && $before === $after,
                'no_product_created' => $before['productos'] === $after['productos'],
                'no_price_created' => $before['producto_precios'] === $after['producto_precios'],
                'no_stock_created' => $before['existencias_producto'] === $after['existencias_producto'],
                'no_inventory_created' => $before['inventario_existencias'] === $after['inventario_existencias'],
                'no_inventory_movement_created' => $before['movimientos_inventario'] === $after['movimientos_inventario'],
                'no_purchase_created' => $before['compras'] === $after['compras'],
                'no_supplier_created' => $before['proveedores'] === $after['proveedores'],
            ],
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-UI-PULIDO-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'views' => [
                'app/Views/tickets/productos/index.php',
                'app/Views/tickets/productos/create.php',
                'app/Views/tickets/productos/show.php',
            ],
            'css' => 'public/css/modules/tickets-productos.css',
            'cases' => $results,
            'operational_counts_before' => $before,
            'operational_counts_during' => $during,
            'operational_counts_after' => $after,
            'cleanup' => 'view_render_only_no_operational_data_written',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function renderedViews(): array
    {
        $this->startSession();
        $csrf = new CsrfTokenService(new Session([]));

        return [
            'index' => $this->render('app/Views/tickets/productos/index.php', [
                'tickets' => [[
                    'id' => 10,
                    'folio' => 'GU-000010',
                    'estado' => 'EN_REVISION',
                    'almacen_id' => 1,
                    'solicitante_usuario_id' => 1,
                    'created_at' => '2026-09-02 10:00:00',
                    'total_partidas' => 1,
                    'partidas_en_revision' => 1,
                    'partidas_aprobadas' => 0,
                    'partidas_rechazadas' => 0,
                ]],
                'permissions' => $this->allVisualPermissions(),
            ]),
            'create' => $this->render('app/Views/tickets/productos/create.php', [
                'csrf' => $csrf,
                'errors' => [],
                'values' => [],
                'permissions' => $this->allVisualPermissions(),
            ]),
            'show' => $this->render('app/Views/tickets/productos/show.php', [
                'csrf' => $csrf,
                'errors' => [],
                'permissions' => $this->allVisualPermissions(),
                'ticket' => [
                    'id' => 10,
                    'folio' => 'GU-000010',
                    'estado' => 'EN_REVISION',
                    'empresa_id' => 1,
                    'almacen_id' => 1,
                    'solicitante_usuario_id' => 1,
                    'observaciones_generales' => 'Solicitud documental.',
                    'total_partidas' => 1,
                    'partidas_en_revision' => 1,
                    'partidas_aprobadas' => 0,
                    'partidas_rechazadas' => 0,
                    'partidas' => [[
                        'id' => 20,
                        'numero_partida' => 1,
                        'estado' => 'EN_REVISION',
                        'descripcion' => 'Partida <script>alert(1)</script>',
                        'modelo' => 'M-01',
                        'marca_texto' => 'Marca documental',
                        'proveedor_texto' => 'Proveedor documental',
                        'unidad_sat_id' => null,
                        'clave_sat_id' => null,
                        'costo_sugerido' => '10.00',
                        'peso' => '1.00',
                        'lleva_serie' => 1,
                        'observaciones' => 'Observación.',
                        'motivo_rechazo' => null,
                        'comentario_resolucion' => null,
                    ]],
                    'eventos' => [[
                        'evento' => 'TICKET_CREADO',
                        'descripcion' => 'Ticket documental creado.',
                    ]],
                ],
            ]),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function allVisualPermissions(): array
    {
        return [
            'canView' => true,
            'canCreate' => true,
            'canResolve' => true,
            'canCancel' => true,
            'canViewAttachments' => true,
            'canCreateComments' => true,
            'canResendEmail' => true,
            'canViewEvents' => true,
        ];
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $relativePath, array $variables): string
    {
        extract($variables, EXTR_SKIP);

        ob_start();
        try {
            require BASE_PATH . '/' . $relativePath;

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            throw $exception;
        }
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_save_path(sys_get_temp_dir());
        session_name('TP_UI_PULIDO_' . bin2hex(random_bytes(4)));

        if (!session_start()) {
            throw new RuntimeException('Unable to start UI polish test session.');
        }
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function allViewsContain(string $needle): bool
    {
        foreach ([
            'app/Views/tickets/productos/index.php',
            'app/Views/tickets/productos/create.php',
            'app/Views/tickets/productos/show.php',
        ] as $view) {
            if (!str_contains($this->read($view), $needle)) {
                return false;
            }
        }

        return true;
    }

    private function viewsUseEscapeHelper(): bool
    {
        foreach ([
            'app/Views/tickets/productos/index.php',
            'app/Views/tickets/productos/create.php',
            'app/Views/tickets/productos/show.php',
        ] as $view) {
            if (!str_contains($this->read($view), '<?= e(')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $html
     * @param array<int, string> $needles
     */
    private function containsAny(array $html, array $needles): bool
    {
        foreach ($html as $document) {
            foreach ($needles as $needle) {
                if (str_contains($document, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function fileDoesNotContain(string $relativePath, string $needle): bool
    {
        return !str_contains($this->read($relativePath), $needle);
    }

    private function read(string $relativePath): string
    {
        $path = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function hasFiles(string $relativeDirectory, string $pattern): bool
    {
        $directory = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (!is_dir($directory)) {
            return false;
        }

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
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $expected
     */
    private function onlyExpectedFiles(string $relativeDirectory, string $pattern, array $expected): bool
    {
        $directory = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (!is_dir($directory)) {
            return $expected === [];
        }

        $actual = [];
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
                $actual[] = $relative;
            }
        }

        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    /**
     * @return array<string, int>
     */
    private function operationalCounts(): array
    {
        return [
            'productos' => $this->optionalCount('productos'),
            'producto_precios' => $this->optionalCount('producto_precios'),
            'existencias_producto' => $this->optionalCount('existencias_producto'),
            'inventario_existencias' => $this->optionalCount('inventario_existencias'),
            'movimientos_inventario' => $this->optionalCount('movimientos_inventario'),
            'compras' => $this->optionalCount('compras'),
            'proveedores' => $this->optionalCount('proveedores'),
        ];
    }

    private function optionalCount(string $table): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :schema_name
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['schema_name' => $this->database, 'table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
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
