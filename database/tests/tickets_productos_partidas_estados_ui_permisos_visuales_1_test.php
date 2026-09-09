<?php

declare(strict_types=1);

use App\Core\Session;
use App\Infrastructure\Database\DatabaseTest;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private PDO $pdo;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException(
                'Unexpected active database for TP-PARTIDAS-ESTADOS-UI-PERMISOS-VISUALES-1.'
            );
        }

        $before = $this->operationalCounts();
        $html = $this->renderedViews();
        $after = $this->operationalCounts();
        $controller = $this->read('app/Http/Controllers/ProductRequestTicketController.php');

        $results = [
            'permissions' => [
                'create_user_sees_new_ticket' => str_contains($html['index_create'], 'href="/tickets/productos/crear"')
                    && str_contains($html['index_create'], 'Nuevo ticket'),
                'user_without_create_does_not_see_new_ticket_link' =>
                    !str_contains($html['index_no_create'], 'href="/tickets/productos/crear"')
                    && str_contains($html['index_no_create'], 'No tienes permiso para crear tickets.'),
                'user_without_view_does_not_see_detail_link' =>
                    !str_contains($html['index_no_view'], 'Ver detalle')
                    && str_contains($html['index_no_view'], 'Sin permiso de detalle'),
                'create_allowed_keeps_form_and_csrf' =>
                    str_contains($html['create_allowed'], 'method="post" action="/tickets/productos"')
                    && str_contains($html['create_allowed'], 'name="_token"'),
                'create_denied_hides_form' =>
                    !str_contains($html['create_denied'], 'method="post" action="/tickets/productos"')
                    && str_contains($html['create_denied'], 'No tienes permiso para crear tickets.'),
                'resolver_user_sees_approve_reject' =>
                    str_contains($html['show_all'], '/aprobar')
                    && str_contains($html['show_all'], '/rechazar')
                    && str_contains($html['show_all'], 'Aprobar partida')
                    && str_contains($html['show_all'], 'Rechazar partida'),
                'user_without_resolver_hides_approve_reject' =>
                    !str_contains($html['show_limited'], '/aprobar')
                    && !str_contains($html['show_limited'], '/rechazar')
                    && str_contains($html['show_limited'], 'No tienes permiso para aprobar o rechazar partidas.'),
                'cancel_user_sees_cancel_form' =>
                    str_contains($html['show_all'], '/cancelar')
                    && str_contains($html['show_all'], 'Cancelar ticket'),
                'user_without_cancel_hides_cancel_form' => !str_contains($html['show_limited'], '/cancelar'),
                'events_user_sees_events' =>
                    str_contains($html['show_all'], 'Eventos')
                    && str_contains($html['show_all'], 'TICKET_CREADO'),
                'user_without_events_hides_events' => !str_contains($html['show_limited'], 'TICKET_CREADO'),
                'attachments_user_sees_runtime_upload_ui' =>
                    str_contains($html['show_all'], 'Los adjuntos sirven como soporte para revisar la solicitud.')
                    && str_contains($html['show_all'], 'Subir adjunto')
                    && str_contains($html['show_all'], 'type="file"')
                    && str_contains($html['show_all'], 'enctype="multipart/form-data"'),
                'user_without_attachments_hides_runtime_upload_ui' =>
                    !str_contains($html['show_limited'], 'Los adjuntos sirven como soporte para revisar la solicitud.')
                    && !str_contains($html['show_limited'], 'Subir adjunto')
                    && !str_contains($html['show_limited'], 'type="file"')
                    && !str_contains($html['show_limited'], 'enctype="multipart/form-data"'),
                'email_user_sees_disabled_placeholder_only' =>
                    str_contains($html['show_all'], 'Reenvío de correo pendiente de fase posterior.')
                    && str_contains($html['show_all'], 'disabled'),
                'user_without_email_hides_email_placeholder' =>
                    !str_contains($html['show_limited'], 'Reenvío de correo pendiente de fase posterior.'),
            ],
            'controller_contract' => [
                'uses_permission_service' => str_contains($controller, 'PermissionService'),
                'uses_allows_for_visual_permissions' => str_contains($controller, '->allows($user[\'user_id\'], $code)'),
                'uses_existing_global_permission_service_without_bootstrap_change' =>
                    str_contains($controller, '$GLOBALS[\'permissions\']'),
                'fallback_is_safe_false' => str_contains($controller, '$permissionService !== null'),
                'no_direct_sql_in_controller' => preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $controller) !== 1,
                'delegates_to_service' =>
                    str_contains($controller, '$this->tickets->crearTicket')
                    && str_contains($controller, '$this->tickets->resolverPartida')
                    && str_contains($controller, '$this->tickets->cancelarTicket'),
            ],
            'security' => [
                'show_escapes_malicious_values' =>
                    str_contains($html['show_all'], '&lt;script&gt;alert(1)&lt;/script&gt;')
                    && !str_contains($html['show_all'], '<script>alert(1)</script>'),
                'views_use_escape_helper' => $this->viewsUseEscapeHelper(),
                'create_keeps_csrf' => str_contains($html['create_allowed'], 'name="_token"'),
                'show_keeps_csrf_in_allowed_actions' => substr_count($html['show_all'], 'name="_token"') >= 3,
                'no_storage_uploads_in_html' => !$this->containsAny($html, ['storage/uploads']),
                'no_physical_paths_in_html' => !$this->containsAny($html, ['C:\\', '/var/', 'BASE_PATH']),
                'no_attachment_download_or_preview_in_html' => !$this->containsAny($html, [
                    'Descargar adjunto',
                    'Vista previa',
                    '/descargar',
                    '/download',
                    '/preview',
                ]),
                'no_internal_attachment_paths_or_names_in_html' => !$this->containsAny($html, [
                    'ruta_relativa',
                    'nombre_guardado',
                    'storage/private',
                ]),
                'no_sensitive_data_in_html' => !$this->containsAny($html, [
                    'password_hash',
                    'token_hash',
                    'auth_user',
                    '$_SESSION',
                    'rol_permisos',
                    'usuario_roles',
                ]),
            ],
            'scope_guardrails' => [
                'routes_not_modified' => $this->fileDoesNotContain('routes/web.php', 'ui-permisos-visuales'),
                'bootstrap_not_modified' => $this->fileDoesNotContain('bootstrap/app.php', 'ui-permisos-visuales'),
                'service_not_modified' => $this->fileDoesNotContain(
                    'app/Domain/Tickets/ProductRequestTicketService.php',
                    'ui-permisos-visuales'
                ),
                'repository_not_modified' => $this->fileDoesNotContain(
                    'app/Infrastructure/Repositories/ProductRequestTicketRepository.php',
                    'ui-permisos-visuales'
                ),
                'no_ticket_js_created' => !$this->hasFiles('public/js', '/tickets.*productos|productos.*tickets/i'),
                'no_mail_runtime_created' =>
                    !$this->hasFiles('app/Domain/Mail', '/ticket|solicitud|alta/i')
                    && !$this->hasFiles('app/Domain/Notifications', '/ticket|solicitud|alta/i'),
                'no_real_attachments_created' => !$this->hasFiles('storage', '/tickets-productos|ticket|solicitud/i'),
            ],
            'operational_guardrails' => [
                'counts_unchanged_after_render' => $before === $after,
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
                'TP-PARTIDAS-ESTADOS-UI-PERMISOS-VISUALES-1 assertions failed: '
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
            'controller' => 'app/Http/Controllers/ProductRequestTicketController.php',
            'css' => 'public/css/modules/tickets-productos.css',
            'cases' => $results,
            'operational_counts_before' => $before,
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
        $ticket = $this->ticketFixture();

        return [
            'index_create' => $this->render('app/Views/tickets/productos/index.php', [
                'tickets' => [$this->ticketListFixture()],
                'permissions' => $this->permissions(['canView', 'canCreate']),
            ]),
            'index_no_create' => $this->render('app/Views/tickets/productos/index.php', [
                'tickets' => [$this->ticketListFixture()],
                'permissions' => $this->permissions(['canView']),
            ]),
            'index_no_view' => $this->render('app/Views/tickets/productos/index.php', [
                'tickets' => [$this->ticketListFixture()],
                'permissions' => $this->permissions([]),
            ]),
            'create_allowed' => $this->render('app/Views/tickets/productos/create.php', [
                'csrf' => $csrf,
                'errors' => [],
                'values' => [],
                'permissions' => $this->permissions(['canCreate']),
            ]),
            'create_denied' => $this->render('app/Views/tickets/productos/create.php', [
                'csrf' => $csrf,
                'errors' => [],
                'values' => [],
                'permissions' => $this->permissions([]),
            ]),
            'show_all' => $this->render('app/Views/tickets/productos/show.php', [
                'csrf' => $csrf,
                'errors' => [],
                'ticket' => $ticket,
                'permissions' => $this->permissions([
                    'canResolve',
                    'canCancel',
                    'canViewAttachments',
                    'canCreateComments',
                    'canResendEmail',
                    'canViewEvents',
                ]),
            ]),
            'show_limited' => $this->render('app/Views/tickets/productos/show.php', [
                'csrf' => $csrf,
                'errors' => [],
                'ticket' => $ticket,
                'permissions' => $this->permissions([]),
            ]),
        ];
    }

    /**
     * @param list<string> $enabled
     * @return array<string, bool>
     */
    private function permissions(array $enabled): array
    {
        $permissions = [
            'canView' => false,
            'canCreate' => false,
            'canResolve' => false,
            'canCancel' => false,
            'canViewAttachments' => false,
            'canCreateComments' => false,
            'canResendEmail' => false,
            'canViewEvents' => false,
        ];

        foreach ($enabled as $key) {
            $permissions[$key] = true;
        }

        return $permissions;
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketListFixture(): array
    {
        return [
            'id' => 10,
            'folio' => 'GU-000010',
            'estado' => 'EN_REVISION',
            'almacen_id' => 1,
            'solicitante_usuario_id' => 1,
            'created_at' => '2026-09-03 10:00:00',
            'total_partidas' => 1,
            'partidas_en_revision' => 1,
            'partidas_aprobadas' => 0,
            'partidas_rechazadas' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketFixture(): array
    {
        return $this->ticketListFixture() + [
            'empresa_id' => 1,
            'observaciones_generales' => 'Solicitud documental.',
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
        session_name('TPUIPERM' . bin2hex(random_bytes(4)));

        if (!session_start()) {
            throw new RuntimeException('Unable to start UI permissions test session.');
        }
    }

    /**
     * @return array<string, int>
     */
    private function operationalCounts(): array
    {
        $tables = [
            'productos',
            'producto_precios',
            'existencias_producto',
            'inventario_existencias',
            'movimientos_inventario',
            'compras',
            'proveedores',
        ];
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = $this->tableExists($table)
                ? (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn()
                : 0;
        }

        return $counts;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @param array<string, string> $html
     * @param list<string> $needles
     */
    private function containsAny(array $html, array $needles): bool
    {
        foreach ($html as $body) {
            foreach ($needles as $needle) {
                if (str_contains($body, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function viewsUseEscapeHelper(): bool
    {
        foreach ([
            'app/Views/tickets/productos/index.php',
            'app/Views/tickets/productos/create.php',
            'app/Views/tickets/productos/show.php',
        ] as $path) {
            if (!str_contains($this->read($path), '<?= e(')) {
                return false;
            }
        }

        return true;
    }

    private function fileDoesNotContain(string $path, string $needle): bool
    {
        return !str_contains($this->read($path), $needle);
    }

    private function hasFiles(string $directory, string $pattern): bool
    {
        $root = BASE_PATH . '/' . $directory;

        if (!is_dir($root)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo
                && $file->isFile()
                && preg_match($pattern, str_replace('\\', '/', $file->getPathname())) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(BASE_PATH . '/' . $path);

        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read ' . $path);
        }

        return $contents;
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
