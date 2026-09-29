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
            throw new RuntimeException('Unexpected active database for TP mail contract audit.');
        }

        $audit = [
            'documentation' => $this->documentationCases(),
            'runtime_absence' => $this->runtimeAbsenceCases(),
            'permission_contract' => $this->permissionCases($pdo),
            'scope_guardrails' => $this->scopeGuardrails(),
            'current_placeholder' => $this->currentPlaceholderCases(),
            'previous_guardrails_available' => $this->previousGuardrailsAvailable(),
        ];

        if (!$this->allTrue($audit)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1 assertions failed: '
                . json_encode($audit, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'phase' => 'TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1',
            'audit_mode' => 'read_only_mail_contract_no_runtime_send',
            'events_with_email' => [
                'TICKET_CREADO',
                'PARTIDA_APROBADA',
                'PARTIDA_RECHAZADA',
                'TICKET_RESUELTO_TOTAL',
                'TICKET_RESUELTO_PARCIAL',
                'TICKET_CANCELADO',
            ],
            'events_without_email_for_now' => [
                'COMENTARIO_AGREGADO',
                'ADJUNTO_CARGADO',
            ],
            'templates' => [
                'ticket_created',
                'line_approved',
                'line_rejected',
                'ticket_resolved',
                'ticket_cancelled',
            ],
            'manual_resend_permission' => 'tickets_productos.correo.reenviar',
            'cases' => $audit,
            'runtime_status' => 'not_implemented_by_this_phase',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function documentationCases(): array
    {
        $doc = $this->read('docs/tickets-productos-partidas-estados-correo-contrato-1.md');
        $lower = mb_strtolower($doc, 'UTF-8');

        return [
            'doc_exists' => $doc !== '',
            'documents_phase_and_objective' => $this->textContainsAll($doc, [
                'TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1',
                'contrato técnico',
                'funcional',
                'seguridad',
                'no envía correos reales',
                'no configura SMTP',
            ]),
            'documents_email_events' => $this->textContainsAll($doc, [
                'TICKET_CREADO',
                'PARTIDA_APROBADA',
                'PARTIDA_RECHAZADA',
                'TICKET_RESUELTO_TOTAL',
                'TICKET_RESUELTO_PARCIAL',
                'TICKET_CANCELADO',
                'COMENTARIO_AGREGADO',
                'ADJUNTO_CARGADO',
            ]),
            'documents_send_vs_register_only' => $this->textContainsAll($doc, [
                'COMENTARIO_AGREGADO',
                'ADJUNTO_CARGADO',
                'sin correo automático',
                'Solo se registra',
            ]),
            'documents_recipients' => $this->textContainsAll($doc, [
                'email del usuario creador',
                'responsables',
                'no se hardcodean',
                'empresa',
                'almacén',
            ]),
            'documents_controlled_cc' => $this->textContainsAll($doc, [
                'CC controlado',
                'no permitir CC arbitrario',
                'no permitir correos libres',
                'validación',
            ]),
            'documents_templates_and_subjects' => $this->textContainsAll($doc, [
                'ticket_created',
                'line_approved',
                'line_rejected',
                'ticket_resolved',
                'ticket_cancelled',
                'Solicitud de alta de producto {folio} recibida',
                'Partida aprobada en solicitud {folio}',
                'Partida rechazada en solicitud {folio}',
                'Solicitud de alta de producto {folio} resuelta',
                'Solicitud de alta de producto {folio} cancelada',
            ]),
            'documents_template_content' => $this->textContainsAll($doc, [
                'saludo',
                'folio',
                'empresa',
                'almacén',
                'solicitante',
                'resumen de partidas',
                'datos autorizados',
                'motivo de rechazo',
                'link interno',
                'versión HTML',
                'versión de texto plano',
            ]),
            'documents_visual_reference_without_runtime' => $this->textContainsAll($doc, [
                'referencia visual',
                'to',
                'subject',
                'html',
                'text',
                'cc',
                'no autoriza copiar credenciales',
            ]),
            'documents_security_privacy' => $this->textContainsAll($doc, [
                'rutas internas',
                'storage/private',
                'nombre interno de archivo',
                'DSN',
                'credenciales SMTP',
                'contraseñas',
                'password hash',
                'tokens',
                'información de sesión',
                'errores técnicos crudos',
            ]),
            'documents_no_attachments_by_mail' => $this->textContainsAll($doc, [
                'no enviar adjuntos por correo',
                'no adjuntar automáticamente',
                'no incluir links directos',
                'descarga/preview no está implementado',
            ]),
            'documents_internal_link_only' => $this->textContainsAll($doc, [
                'APP_URL',
                '/tickets/productos/{id}',
                'no incluir rutas físicas',
                'no incluir tokens de sesión',
            ]),
            'documents_requester_without_email' => $this->textContainsAll($doc, [
                'solicitante no tiene email',
                'no se debe intentar enviar',
                'no se debe bloquear la operación principal',
            ]),
            'documents_failure_behavior' => $this->textContainsAll($doc, [
                'Si el correo falla',
                'no debe revertirse',
                'error seguro',
                'aviso genérico',
            ]),
            'documents_outbox_history' => $this->textContainsAll($doc, [
                'tickets_productos_correos',
                'partida_id',
                'destinatario_email',
                'cc_json',
                'error_mensaje_seguro',
                'intentos',
                'ultimo_intento_at',
                'enviado_at',
            ]),
            'documents_mail_statuses' => $this->textContainsAll($doc, [
                'PENDIENTE',
                'ENVIANDO',
                'ENVIADO',
                'ERROR',
                'CANCELADO',
            ]),
            'documents_idempotency' => $this->textContainsAll($doc, [
                'Idempotencia',
                'ticket',
                'partida nullable',
                'evento',
                'plantilla',
                'destinatario',
                'payload hash',
            ]),
            'documents_retries' => $this->textContainsAll($doc, [
                'reintentos automáticos',
                'limitados',
                'registrar intentos',
                'reenvío manual',
            ]),
            'documents_existing_permission' => str_contains($doc, 'tickets_productos.correo.reenviar'),
            'documents_no_new_permissions_or_seeds' => $this->textContainsAll($doc, [
                'No se crea permiso nuevo',
                'No se modifican seeds',
                'No se crea',
                'permiso nuevo',
            ]),
            'documents_next_runtime_phase' => str_contains($lower, 'tp-partidas-estados-correo-runtime-1'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function runtimeAbsenceCases(): array
    {
        $ticketRuntime = $this->read('app/Http/Controllers/ProductRequestTicketController.php') . "\n"
            . $this->read('app/Domain/Tickets/ProductRequestTicketService.php') . "\n"
            . $this->read('app/Infrastructure/Repositories/ProductRequestTicketRepository.php') . "\n"
            . $this->read('routes/web.php') . "\n"
            . $this->read('bootstrap/app.php') . "\n"
            . $this->read('app/Views/tickets/productos/show.php') . "\n"
            . $this->read('public/js/modules/tickets-productos-create.js');

        return [
            'no_mail_domain_runtime_created' =>
                !$this->hasFiles('app/Domain/Mail', '/ticket|solicitud|alta|producto/i')
                && !$this->hasFiles('app/Domain/Notifications', '/ticket|solicitud|alta|producto/i'),
            'no_mail_support_runtime_created' => !$this->hasFiles('app/Support/Mail', '/ticket|solicitud|alta|producto/i'),
            'no_direct_phpmailer_in_tickets' => !preg_match('/PHPMailer|SMTP|SwiftMailer|Symfony\\\\Component\\\\Mailer/i', $ticketRuntime),
            'no_mail_send_call_in_tickets' => !preg_match('/->send\\(|mail\\s*\\(|sendmail|smtp/i', $ticketRuntime),
            'no_mail_routes_created' =>
                !preg_match('#/tickets/productos/\\{id\\}/(?:correo|email|mail)#i', $this->read('routes/web.php'))
                && !preg_match('#/(?:correo|email|mail)/tickets/productos#i', $this->read('routes/web.php')),
            'no_mail_config_created' => !is_file(BASE_PATH . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'mail.php'),
            'no_mail_tables_created_by_contract' =>
                $this->onlyAllowedTicketProductOutboxTableMention()
                && !$this->tableMentionExists('mail_templates')
                && !$this->tableMentionExists('mail_queue')
                && !$this->tableMentionExists('mail_logs'),
            'no_download_or_preview_implemented' => !preg_match(
                '#/tickets/productos/\\{id\\}/(?:adjuntos|archivos|attachments)/\\{[^}]+\\}/(?:descargar|download|preview|ver)#i',
                $this->read('routes/web.php')
            ),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissionCases(PDO $pdo): array
    {
        $seed = $this->read('database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php');
        $permissionExists = $this->permissionExists($pdo, 'tickets_productos.correo.reenviar');

        return [
            'existing_resend_permission_documented' => str_contains(
                $this->read('docs/tickets-productos-partidas-estados-correo-contrato-1.md'),
                'tickets_productos.correo.reenviar'
            ),
            'existing_resend_permission_available_in_seed_or_db' =>
                str_contains($seed, 'tickets_productos.correo.reenviar') || $permissionExists,
            'mail_permissions_remain_scoped' =>
                !str_contains($seed, 'correos.')
                && !str_contains($seed, 'notificaciones.')
                && !str_contains($seed, 'tickets_productos.correo.enviar')
                && !str_contains($seed, 'tickets_productos.correo.configurar')
                && str_contains(
                    $this->read('database/seeds/correo_outbox_ui_1_seed_permissions.php'),
                    "private const CODE = 'correos.cola.ver'"
                ),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function scopeGuardrails(): array
    {
        return [
            'no_migrations_created_for_mail_contract' =>
                $this->relativeFiles(
                    'database/migrations',
                    '/correo|correos|mail|notification|notifications|notificacion|notificaciones/i'
                ) === [
                    'database/migrations/tp_partidas_estados_correo_config_1_001_create_mail_configuration.php',
                    'database/migrations/tp_partidas_estados_correo_outbox_db_1_001_create_ticket_product_email_outbox.php',
                ],
            'only_authorized_mail_seeds_exist' =>
                $this->relativeFiles(
                    'database/seeds',
                    '/correo|correos|mail|notification|notifications|notificacion|notificaciones/i'
                ) === [
                    'database/seeds/correo_outbox_ui_1_seed_permissions.php',
                    'database/seeds/tp_partidas_estados_correo_config_1_seed_permissions.php',
                ],
            'no_product_price_inventory_purchase_supplier_phase_marker' =>
                !str_contains($this->read('docs/tickets-productos-partidas-estados-correo-contrato-1.md'), 'crear producto real')
                && $this->textContainsAll($this->read('docs/tickets-productos-partidas-estados-correo-contrato-1.md'), [
                    'no crea productos',
                    'precios',
                    'inventario',
                    'compras',
                    'proveedores',
                ]),
            'routes_not_modified_for_mail_contract_marker' => !str_contains(
                $this->read('routes/web.php'),
                'TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1'
            ),
            'bootstrap_not_modified_for_mail_contract_marker' => !str_contains(
                $this->read('bootstrap/app.php'),
                'TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1'
            ),
            'controller_not_modified_for_mail_contract_marker' => !str_contains(
                $this->read('app/Http/Controllers/ProductRequestTicketController.php'),
                'TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1'
            ),
            'service_not_modified_for_mail_contract_marker' => !str_contains(
                $this->read('app/Domain/Tickets/ProductRequestTicketService.php'),
                'TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1'
            ),
            'repository_not_modified_for_mail_contract_marker' => !str_contains(
                $this->read('app/Infrastructure/Repositories/ProductRequestTicketRepository.php'),
                'TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1'
            ),
            'package_files_do_not_define_mail_runtime' =>
                !str_contains($this->read('package.json'), 'phpmailer')
                && !str_contains($this->read('package-lock.json'), 'phpmailer')
                && !str_contains($this->read('package.json'), 'smtp')
                && !str_contains($this->read('package-lock.json'), 'smtp'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function currentPlaceholderCases(): array
    {
        $show = $this->read('app/Views/tickets/productos/show.php');

        return [
            'mail_placeholder_visible' => str_contains($show, 'Correo electrónico'),
            'mail_placeholder_reflects_current_queue' => str_contains(
                $show,
                'Las notificaciones de correo se gestionan mediante la cola administrativa de correo.'
            ),
            'future_events_still_listed' => $this->textContainsAll($show, [
                'ticket creado',
                'partida aprobada',
                'partida rechazada',
                'ticket resuelto',
                'ticket cancelado',
            ]),
            'resend_action_still_disabled' => str_contains($show, 'ticket-products__disabled-action')
                && str_contains($show, 'Reenvío manual no disponible.'),
            'placeholder_does_not_submit_mail' => !preg_match('/action="[^"]*(correo|email|mail)/i', $show),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function previousGuardrailsAvailable(): array
    {
        $required = [
            'database/tickets-productos-partidas-estados-multipartida-respuestas-ui.php',
            'database/tickets-productos-partidas-estados-ux-flujo-correcciones.php',
            'database/tickets-productos-partidas-estados-aprobacion-captura.php',
            'database/tickets-productos-partidas-estados-adjuntos-runtime.php',
            'database/tickets-productos-partidas-estados-create-catalogos.php',
            'database/tickets-productos-partidas-estados-comentarios.php',
            'database/tickets-productos-partidas-estados-detalle-pulido.php',
            'database/tickets-productos-partidas-estados-listado.php',
            'database/tickets-productos-partidas-estados-ui-permisos-visuales.php',
            'database/tickets-productos-partidas-estados-ui-pulido.php',
            'database/tickets-productos-partidas-estados-ui.php',
            'database/tickets-productos-partidas-estados-routes-controller.php',
            'database/tickets-productos-partidas-estados-service.php',
            'database/tickets-productos-partidas-estados-db.php',
            'database/inventario-service.php',
            'database/precios.php',
        ];

        $cases = [];

        foreach ($required as $relativePath) {
            $cases[str_replace(['/', '.php', '-'], ['_', '', '_'], $relativePath)] = $this->read($relativePath) !== '';
        }

        return $cases;
    }

    private function permissionExists(PDO $pdo, string $permission): bool
    {
        if (!$this->tableExists($pdo, (string) $pdo->query('SELECT DATABASE()')->fetchColumn(), 'permisos')) {
            return false;
        }

        $statement = $pdo->prepare(
             'SELECT COUNT(*)
             FROM permisos
             WHERE codigo = :codigo'
        );
        $statement->execute(['codigo' => $permission]);

        return (int) $statement->fetchColumn() >= 1;
    }

    private function tableMentionExists(string $table): bool
    {
        foreach ($this->relativeFiles('database/migrations', '/\\.php$/i') as $file) {
            if (str_contains($this->read($file), $table)) {
                return true;
            }
        }

        return false;
    }

    private function onlyAllowedTicketProductOutboxTableMention(): bool
    {
        $files = [];

        foreach ($this->relativeFiles('database/migrations', '/\\.php$/i') as $file) {
            if (str_contains($this->read($file), 'tickets_productos_correos')) {
                $files[] = $file;
            }
        }

        return $files === [
            'database/migrations/tp_partidas_estados_correo_outbox_db_1_001_create_ticket_product_email_outbox.php',
        ];
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
