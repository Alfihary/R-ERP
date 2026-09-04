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
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-DETALLE-PULIDO-1.');
        }

        $before = $this->operationalCounts();
        $html = $this->renderedShowViews();
        $during = $this->operationalCounts();
        $this->closeSession();
        $after = $this->operationalCounts();
        $show = $this->read('app/Views/tickets/productos/show.php');
        $css = $this->read('public/css/modules/tickets-productos.css');

        $results = [
            'show_contract' => [
                'show_exists' => is_file(BASE_PATH . '/app/Views/tickets/productos/show.php'),
                'show_conserves_folio' => str_contains($html['all'], 'GU-000010'),
                'show_conserves_ticket_state' => str_contains($html['all'], 'RESUELTO_PARCIAL'),
                'show_has_line_summary' => str_contains($html['all'], 'Resumen de partidas')
                    && str_contains($html['all'], 'Estado documental'),
                'show_has_line_totals' => str_contains($html['all'], 'Total')
                    && str_contains($html['all'], 'En revisión')
                    && str_contains($html['all'], 'Aprobadas')
                    && str_contains($html['all'], 'Rechazadas'),
                'show_has_lines_with_state' => str_contains($html['all'], 'Partida 1')
                    && str_contains($html['all'], 'Partida 2')
                    && str_contains($html['all'], 'APROBADA')
                    && str_contains($html['all'], 'RECHAZADA'),
                'show_has_documentary_fields' => str_contains($html['all'], 'Modelo')
                    && str_contains($html['all'], 'Marca documental')
                    && str_contains($html['all'], 'Proveedor documental'),
                'show_has_documentary_cost' => str_contains($html['all'], 'Costo sugerido')
                    && str_contains($html['all'], 'documental'),
                'show_has_rejection_reason' => str_contains($html['all'], 'Motivo de rechazo')
                    && str_contains($html['all'], 'No cumple especificación documental.'),
                'show_has_resolution_comment' => str_contains($html['all'], 'Comentario de resolución')
                    && str_contains($html['all'], 'Aprobación documental lista.'),
                'show_has_resolution_user_and_date' => str_contains($html['all'], 'Resuelto por')
                    && str_contains($html['all'], 'Fecha de resolución'),
            ],
            'actions_and_permissions' => [
                'resolved_lines_hide_approve_reject_actions' =>
                    !str_contains($html['all'], '/partidas/102/aprobar')
                    && !str_contains($html['all'], '/partidas/102/rechazar')
                    && !str_contains($html['all'], '/partidas/103/aprobar')
                    && !str_contains($html['all'], '/partidas/103/rechazar'),
                'pending_line_shows_approve_reject_with_resolver_permission' =>
                    str_contains($html['all'], '/partidas/101/aprobar')
                    && str_contains($html['all'], '/partidas/101/rechazar'),
                'without_resolver_permission_hides_approve_reject' =>
                    !str_contains($html['limited'], '/aprobar')
                    && !str_contains($html['limited'], '/rechazar')
                    && str_contains($html['limited'], 'No tienes permiso para aprobar o rechazar partidas.'),
                'cancel_shown_only_with_permission_and_open_ticket' =>
                    str_contains($html['all'], '/tickets/productos/10/cancelar')
                    && !str_contains($html['limited'], '/cancelar')
                    && !str_contains($html['cancelled'], '/cancelar'),
                'events_only_with_permission' => str_contains($html['all'], 'Eventos')
                    && str_contains($html['all'], 'PARTIDA_APROBADA')
                    && !str_contains($html['limited'], 'PARTIDA_APROBADA'),
                'attachments_placeholder_only_with_permission' =>
                    str_contains($html['all'], 'Adjuntos documentales pendientes de fase posterior.')
                    && !str_contains($html['limited'], 'Adjuntos documentales pendientes de fase posterior.'),
                'comments_placeholder_only_with_permission' =>
                    str_contains($html['all'], 'Comentarios documentales pendientes de fase posterior.')
                    && !str_contains($html['limited'], 'Comentarios documentales pendientes de fase posterior.'),
                'email_placeholder_disabled_only_with_permission' =>
                    str_contains($html['all'], 'Reenvío de correo pendiente de fase posterior.')
                    && str_contains($html['all'], 'disabled')
                    && !str_contains($html['limited'], 'Reenvío de correo pendiente de fase posterior.'),
                'csrf_preserved_in_allowed_forms' => substr_count($html['all'], 'name="_token"') >= 3,
            ],
            'visual_contract' => [
                'css_module_exists' => is_file(BASE_PATH . '/public/css/modules/tickets-productos.css'),
                'css_adds_detail_head' => str_contains($css, 'ticket-products__detail-head'),
                'css_adds_metrics' => str_contains($css, 'ticket-products__metrics'),
                'css_adds_events_timeline' => str_contains($css, 'ticket-products__event-type')
                    && str_contains($css, 'ticket-products__event-meta'),
                'css_keeps_responsive_rules' => str_contains($css, '@media'),
                'show_uses_detail_classes' => str_contains($show, 'ticket-products__detail-head')
                    && str_contains($show, 'ticket-products__metrics')
                    && str_contains($show, 'ticket-products__events'),
            ],
            'security' => [
                'outputs_escape_malicious_values' =>
                    str_contains($html['all'], '&lt;script&gt;alert(1)&lt;/script&gt;')
                    && !str_contains($html['all'], '<script>alert(1)</script>'),
                'show_uses_escape_helper' => str_contains($show, '<?= e('),
                'no_storage_uploads_in_html' => !$this->containsAny($html, ['storage/uploads']),
                'no_physical_paths_in_html' => !$this->containsAny($html, ['C:\\', '/var/', 'BASE_PATH']),
                'no_sensitive_data_in_html' => !$this->containsAny($html, [
                    'password_hash',
                    'token_hash',
                    '$_SESSION',
                    'auth_user',
                    'metadata_json',
                    '{"',
                ]),
            ],
            'scope_guardrails' => [
                'routes_unchanged_for_phase' => !str_contains(
                    $this->read('routes/web.php'),
                    'TP-PARTIDAS-ESTADOS-DETALLE-PULIDO-1'
                ),
                'bootstrap_unchanged_for_phase' => !str_contains(
                    $this->read('bootstrap/app.php'),
                    'TP-PARTIDAS-ESTADOS-DETALLE-PULIDO-1'
                ),
                'service_not_modified_for_phase' => !str_contains(
                    $this->read('app/Domain/Tickets/ProductRequestTicketService.php'),
                    'DETALLE-PULIDO'
                ),
                'repository_not_modified_for_phase' => !str_contains(
                    $this->read('app/Infrastructure/Repositories/ProductRequestTicketRepository.php'),
                    'DETALLE-PULIDO'
                ),
                'index_not_modified_for_phase_marker' => !str_contains(
                    $this->read('app/Views/tickets/productos/index.php'),
                    'DETALLE-PULIDO'
                ),
                'create_not_modified_for_phase_marker' => !str_contains(
                    $this->read('app/Views/tickets/productos/create.php'),
                    'DETALLE-PULIDO'
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
                'TP-PARTIDAS-ESTADOS-DETALLE-PULIDO-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'view' => 'app/Views/tickets/productos/show.php',
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
    private function renderedShowViews(): array
    {
        $this->startSession();
        $csrf = new CsrfTokenService(new Session([]));

        return [
            'all' => $this->render('app/Views/tickets/productos/show.php', [
                'csrf' => $csrf,
                'errors' => [],
                'permissions' => $this->permissions(true),
                'ticket' => $this->ticket('RESUELTO_PARCIAL'),
            ]),
            'limited' => $this->render('app/Views/tickets/productos/show.php', [
                'csrf' => $csrf,
                'errors' => [],
                'permissions' => $this->permissions(false),
                'ticket' => $this->ticket('RESUELTO_PARCIAL'),
            ]),
            'cancelled' => $this->render('app/Views/tickets/productos/show.php', [
                'csrf' => $csrf,
                'errors' => [],
                'permissions' => $this->permissions(true),
                'ticket' => $this->ticket('CANCELADO'),
            ]),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(bool $allowed): array
    {
        return [
            'canView' => true,
            'canCreate' => $allowed,
            'canResolve' => $allowed,
            'canCancel' => $allowed,
            'canViewAttachments' => $allowed,
            'canCreateComments' => $allowed,
            'canResendEmail' => $allowed,
            'canViewEvents' => $allowed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(string $state): array
    {
        return [
            'id' => 10,
            'folio' => 'GU-000010',
            'estado' => $state,
            'empresa_id' => 1,
            'empresa_nombre' => 'Empresa QA Detalle',
            'almacen_id' => 1,
            'almacen_nombre' => 'Almacén QA Detalle',
            'solicitante_usuario_id' => 1,
            'solicitante_nombre' => 'qa_detalle',
            'observaciones_generales' => 'Solicitud documental con &lt;script&gt;alert(1)&lt;/script&gt;.',
            'created_at' => '2026-09-04 09:00:00',
            'updated_at' => '2026-09-04 11:00:00',
            'motivo_cancelacion' => $state === 'CANCELADO' ? 'Cancelación documental.' : null,
            'total_partidas' => 3,
            'partidas_en_revision' => 1,
            'partidas_aprobadas' => 1,
            'partidas_rechazadas' => 1,
            'partidas' => [
                [
                    'id' => 101,
                    'numero_partida' => 1,
                    'estado' => 'EN_REVISION',
                    'descripcion' => 'Partida <script>alert(1)</script>',
                    'modelo' => 'M-01',
                    'marca_texto' => 'Marca documental',
                    'proveedor_texto' => 'Proveedor documental',
                    'unidad_sat_id' => 'H87',
                    'clave_sat_id' => '40101701',
                    'moneda_id' => 'MXN',
                    'costo_sugerido' => '120.00',
                    'peso' => '1.50',
                    'lleva_serie' => 1,
                    'observaciones' => 'Pendiente de revisión documental.',
                    'motivo_rechazo' => null,
                    'comentario_resolucion' => null,
                    'resuelto_por_usuario_id' => null,
                    'resuelto_at' => null,
                ],
                [
                    'id' => 102,
                    'numero_partida' => 2,
                    'estado' => 'APROBADA',
                    'descripcion' => 'Partida aprobada documental.',
                    'modelo' => 'M-02',
                    'marca_texto' => 'Marca aprobada',
                    'proveedor_texto' => 'Proveedor solo texto',
                    'unidad_sat_id' => 'H87',
                    'clave_sat_id' => '40101701',
                    'moneda_id' => 'MXN',
                    'costo_sugerido' => '150.00',
                    'peso' => '2.00',
                    'lleva_serie' => 0,
                    'observaciones' => 'Sin observaciones.',
                    'motivo_rechazo' => null,
                    'comentario_resolucion' => 'Aprobación documental lista.',
                    'resuelto_por_usuario_id' => 1,
                    'resuelto_at' => '2026-09-04 10:00:00',
                ],
                [
                    'id' => 103,
                    'numero_partida' => 3,
                    'estado' => 'RECHAZADA',
                    'descripcion' => 'Partida rechazada documental.',
                    'modelo' => 'M-03',
                    'marca_texto' => 'Marca rechazada',
                    'proveedor_texto' => 'Proveedor documental rechazado',
                    'unidad_sat_id' => 'H87',
                    'clave_sat_id' => '40101701',
                    'moneda_id' => 'MXN',
                    'costo_sugerido' => '180.00',
                    'peso' => '2.25',
                    'lleva_serie' => 0,
                    'observaciones' => 'Validar información.',
                    'motivo_rechazo' => 'No cumple especificación documental.',
                    'comentario_resolucion' => 'Rechazo documental registrado.',
                    'resuelto_por_usuario_id' => 1,
                    'resuelto_at' => '2026-09-04 10:30:00',
                ],
            ],
            'eventos' => [
                [
                    'evento' => 'TICKET_CREADO',
                    'descripcion' => 'Ticket documental creado.',
                    'usuario_id' => 1,
                    'created_at' => '2026-09-04 09:00:00',
                ],
                [
                    'evento' => 'PARTIDA_APROBADA',
                    'descripcion' => 'Partida aprobada como revisión documental.',
                    'usuario_id' => 1,
                    'created_at' => '2026-09-04 10:00:00',
                    'metadata_json' => '{"raw":"hidden"}',
                ],
            ],
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

        return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
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

    private function read(string $relativePath): string
    {
        $path = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_save_path(sys_get_temp_dir());
        session_name('TP_DETALLE_' . bin2hex(random_bytes(4)));

        if (!session_start()) {
            throw new RuntimeException('Unable to start detalle pulido test session.');
        }
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
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
