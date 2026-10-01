<?php

declare(strict_types=1);

use App\Domain\Tickets\ProductTicketEmailOutboxService;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;

return new class implements DatabaseTest {
    private PDO $pdo;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP mail outbox DB test.');
        }

        $runner = new MigrationRunner($pdo);
        $baseMigration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';
        $outboxMigration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_correo_outbox_db_1_001_create_ticket_product_email_outbox.php';

        if (!$baseMigration instanceof Migration || !$outboxMigration instanceof Migration) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1 migrations have invalid contracts.');
        }

        $baseState = $this->ensureTicketTables($runner, $baseMigration);
        $outboxState = $runner->migrate($outboxMigration);
        $before = $this->operationalCounts();
        $pdo->beginTransaction();

        try {
            $fixture = $this->fixture();
            $audit = [
                'schema' => $this->schemaCases($expectedDatabase),
                'constraints' => $this->constraintCases($fixture),
                'repository_service' => $this->repositoryServiceCases($fixture),
                'security_guardrails' => $this->securityGuardrails(),
                'scope_guardrails' => $this->scopeGuardrails(),
                'permission_guardrails' => $this->permissionGuardrails(),
                'documentation' => $this->documentationCases(),
            ];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->operationalCounts();
        $cleanup = [
            'no_product_created' => $before['productos'] === $after['productos'],
            'no_price_created' => $before['producto_precios'] === $after['producto_precios'],
            'no_stock_created' => $before['existencias_producto'] === $after['existencias_producto'],
            'no_inventory_created' => $before['movimientos_inventario'] === $after['movimientos_inventario'],
            'no_purchase_created' => $before['compras'] === $after['compras'],
            'no_supplier_created' => $before['proveedores'] === $after['proveedores'],
        ];
        $audit['cleanup'] = $cleanup;

        if ($outboxState === 'applied') {
            $runner->rollback($outboxMigration);
        }

        if ($baseState === 'applied_for_test') {
            $runner->rollback($baseMigration);
        }

        $audit['migration_cleanup'] = [
            'outbox_rolled_back_when_applied' => $outboxState !== 'applied'
                || !$this->tableExists($expectedDatabase, 'tickets_productos_correos'),
            'base_rolled_back_when_applied_for_test' => $baseState !== 'applied_for_test'
                || !$this->tableExists($expectedDatabase, 'tickets_productos'),
        ];

        if (!$this->allTrue($audit)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1 assertions failed: '
                . json_encode($audit, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'migration' => $outboxMigration->id(),
            'base_migration_state' => $baseState,
            'outbox_migration_state' => $outboxState,
            'table' => 'tickets_productos_correos',
            'statuses' => ['PENDIENTE', 'ENVIANDO', 'ENVIADO', 'ERROR', 'CANCELADO'],
            'events' => [
                'TICKET_CREADO',
                'PARTIDA_APROBADA',
                'PARTIDA_RECHAZADA',
                'TICKET_RESUELTO_TOTAL',
                'TICKET_RESUELTO_PARCIAL',
                'TICKET_CANCELADO',
            ],
            'templates' => [
                'ticket_created',
                'line_approved',
                'line_rejected',
                'ticket_resolved',
                'ticket_cancelled',
            ],
            'dedupe_key_rule' => 'ticket:{ticket_id}:partida:{partida_id|null}:evento:{evento}',
            'cases' => $audit,
            'operational_counts_before' => $before,
            'operational_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back_no_operational_data_written',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function schemaCases(string $database): array
    {
        $columns = $this->columns($database, 'tickets_productos_correos');
        $indexes = $this->indexes($database, 'tickets_productos_correos');
        $foreignKeys = $this->foreignKeys($database, 'tickets_productos_correos');
        $checks = implode("\n", $this->checks($database, 'tickets_productos_correos'));

        return [
            'table_exists' => $this->tableExists($database, 'tickets_productos_correos'),
            'has_ticket_id' => in_array('ticket_id', $columns, true),
            'has_nullable_partida_id' => in_array('partida_id', $columns, true)
                && $this->isNullable($database, 'tickets_productos_correos', 'partida_id'),
            'has_evento' => in_array('evento', $columns, true),
            'has_plantilla' => in_array('plantilla', $columns, true),
            'has_destinatario_email' => in_array('destinatario_email', $columns, true),
            'has_cc_json' => in_array('cc_json', $columns, true),
            'has_subject' => in_array('subject', $columns, true),
            'has_html' => in_array('html', $columns, true),
            'has_text' => in_array('text', $columns, true),
            'has_status' => in_array('status', $columns, true),
            'has_intentos' => in_array('intentos', $columns, true),
            'has_max_intentos' => in_array('max_intentos', $columns, true),
            'has_error_mensaje_seguro' => in_array('error_mensaje_seguro', $columns, true),
            'has_ultimo_intento_at' => in_array('ultimo_intento_at', $columns, true),
            'has_enviado_at' => in_array('enviado_at', $columns, true),
            'has_cancelado_at' => in_array('cancelado_at', $columns, true),
            'has_creado_por_usuario_id' => in_array('creado_por_usuario_id', $columns, true),
            'has_dedupe_key' => in_array('dedupe_key', $columns, true),
            'has_timestamps' => in_array('created_at', $columns, true) && in_array('updated_at', $columns, true),
            'engine_is_innodb' => $this->engine($database, 'tickets_productos_correos') === 'InnoDB',
            'dedupe_unique_index_exists' => in_array('uq_tickets_productos_correos_dedupe', $indexes, true),
            'operational_indexes_exist' => $this->containsAll($indexes, [
                'idx_tickets_productos_correos_ticket',
                'idx_tickets_productos_correos_partida',
                'idx_tickets_productos_correos_status',
                'idx_tickets_productos_correos_evento',
                'idx_tickets_productos_correos_created_at',
                'idx_tickets_productos_correos_destinatario',
                'idx_tickets_productos_correos_pendientes',
            ]),
            'foreign_keys_exist' => $this->containsAll($foreignKeys, [
                'ticket_id->tickets_productos.id',
                'partida_id->tickets_productos_partidas.id',
                'creado_por_usuario_id->usuarios.id',
            ]),
            'checks_include_status_event_template' =>
                str_contains($checks, 'PENDIENTE')
                && str_contains($checks, 'TICKET_CREADO')
                && str_contains($checks, 'ticket_created'),
            'checks_include_attempts_and_sensitive_guardrails' =>
                str_contains($checks, 'intentos')
                && str_contains($checks, 'max_intentos')
                && str_contains($checks, 'storage/private')
                && str_contains($checks, 'storage/uploads'),
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function constraintCases(array $fixture): array
    {
        $pendingId = $this->insertOutbox([
            'ticket_id' => $fixture['ticket_id'],
            'partida_id' => $fixture['partida_id'],
            'evento' => 'PARTIDA_APROBADA',
            'plantilla' => 'line_approved',
            'destinatario_email' => 'qa.tp.outbox@example.test',
            'cc_json' => null,
            'subject' => 'Partida aprobada en solicitud QA-000001',
            'html' => '<p>Detalle interno: /tickets/productos/' . $fixture['ticket_id'] . '</p>',
            'text' => 'Detalle interno: /tickets/productos/' . $fixture['ticket_id'],
            'status' => 'PENDIENTE',
            'dedupe_key' => 'ticket:' . $fixture['ticket_id'] . ':partida:' . $fixture['partida_id']
                . ':evento:PARTIDA_APROBADA:constraint',
            'creado_por_usuario_id' => $fixture['user_id'],
        ]);

        $this->markRaw($pendingId, 'ENVIADO', null);
        $sent = $this->findOutbox($pendingId);
        $errorId = $this->insertOutbox([
            'ticket_id' => $fixture['ticket_id'],
            'partida_id' => null,
            'evento' => 'TICKET_CREADO',
            'plantilla' => 'ticket_created',
            'destinatario_email' => 'qa.tp.outbox@example.test',
            'cc_json' => '{"cc":["responsable@example.test"]}',
            'subject' => 'Solicitud de alta de producto QA-000001 recibida',
            'html' => '<p>Solicitud recibida.</p>',
            'text' => 'Solicitud recibida.',
            'status' => 'PENDIENTE',
            'dedupe_key' => 'ticket:' . $fixture['ticket_id'] . ':partida:null:evento:TICKET_CREADO:direct-error',
            'creado_por_usuario_id' => $fixture['user_id'],
        ]);
        $this->markRaw($errorId, 'ERROR', 'Error temporal seguro.');
        $error = $this->findOutbox($errorId);

        return [
            'can_register_pending' => $pendingId > 0,
            'can_mark_sent_without_sending' => (string) $sent['status'] === 'ENVIADO'
                && $sent['enviado_at'] !== null
                && (int) $sent['intentos'] === 1,
            'can_mark_error_with_safe_message' => (string) $error['status'] === 'ERROR'
                && (string) $error['error_mensaje_seguro'] === 'Error temporal seguro.',
            'duplicate_dedupe_rejected' => $this->fails(fn () => $this->insertOutbox([
                'ticket_id' => $fixture['ticket_id'],
                'partida_id' => $fixture['partida_id'],
                'evento' => 'PARTIDA_APROBADA',
                'plantilla' => 'line_approved',
                'destinatario_email' => 'qa.tp.outbox@example.test',
                'cc_json' => null,
                'subject' => 'Duplicado',
                'html' => 'Duplicado',
                'text' => 'Duplicado',
                'status' => 'PENDIENTE',
                'dedupe_key' => 'ticket:' . $fixture['ticket_id'] . ':partida:' . $fixture['partida_id']
                    . ':evento:PARTIDA_APROBADA:constraint',
                'creado_por_usuario_id' => $fixture['user_id'],
            ])),
            'foreign_ticket_rejected' => $this->fails(fn () => $this->insertOutbox([
                'ticket_id' => 999999999,
                'partida_id' => null,
                'evento' => 'TICKET_CREADO',
                'plantilla' => 'ticket_created',
                'destinatario_email' => 'qa.tp.outbox@example.test',
                'cc_json' => null,
                'subject' => 'Ticket inexistente',
                'html' => 'Ticket inexistente',
                'text' => 'Ticket inexistente',
                'status' => 'PENDIENTE',
                'dedupe_key' => 'ticket:999999999:partida:null:evento:TICKET_CREADO',
                'creado_por_usuario_id' => $fixture['user_id'],
            ])),
            'foreign_line_rejected' => $this->fails(fn () => $this->insertOutbox([
                'ticket_id' => $fixture['ticket_id'],
                'partida_id' => 999999999,
                'evento' => 'PARTIDA_APROBADA',
                'plantilla' => 'line_approved',
                'destinatario_email' => 'qa.tp.outbox@example.test',
                'cc_json' => null,
                'subject' => 'Partida inexistente',
                'html' => 'Partida inexistente',
                'text' => 'Partida inexistente',
                'status' => 'PENDIENTE',
                'dedupe_key' => 'ticket:' . $fixture['ticket_id'] . ':partida:999999999:evento:PARTIDA_APROBADA',
                'creado_por_usuario_id' => $fixture['user_id'],
            ])),
            'foreign_created_by_rejected' => $this->fails(fn () => $this->insertOutbox([
                'ticket_id' => $fixture['ticket_id'],
                'partida_id' => null,
                'evento' => 'TICKET_CANCELADO',
                'plantilla' => 'ticket_cancelled',
                'destinatario_email' => 'qa.tp.outbox@example.test',
                'cc_json' => null,
                'subject' => 'Cancelado',
                'html' => 'Cancelado',
                'text' => 'Cancelado',
                'status' => 'PENDIENTE',
                'dedupe_key' => 'ticket:' . $fixture['ticket_id'] . ':partida:null:evento:TICKET_CANCELADO',
                'creado_por_usuario_id' => 999999999,
            ])),
            'invalid_status_rejected' => $this->invalidOutboxRejected($fixture, ['status' => 'INVALIDO']),
            'invalid_event_rejected' => $this->invalidOutboxRejected($fixture, ['evento' => 'COMENTARIO_AGREGADO']),
            'invalid_template_rejected' => $this->invalidOutboxRejected($fixture, ['plantilla' => 'comment_added']),
            'duplicate_template_event_mismatch_rejected' => $this->invalidOutboxRejected($fixture, [
                'evento' => 'TICKET_CREADO',
                'plantilla' => 'line_approved',
            ]) === false,
            'invalid_email_rejected' => $this->invalidOutboxRejected($fixture, ['destinatario_email' => 'sin correo']),
            'attempts_over_max_rejected' => $this->fails(function () use ($fixture): void {
                $statement = $this->pdo->prepare(
                    'INSERT INTO tickets_productos_correos (
                        ticket_id, evento, plantilla, destinatario_email, subject, status,
                        intentos, max_intentos, dedupe_key, created_at
                     ) VALUES (
                        :ticket_id, \'TICKET_CREADO\', \'ticket_created\', \'qa@example.test\', \'Intentos\',
                        \'PENDIENTE\', 4, 3, :dedupe_key, CURRENT_TIMESTAMP
                     )'
                );
                $statement->execute([
                    'ticket_id' => $fixture['ticket_id'],
                    'dedupe_key' => 'ticket:' . $fixture['ticket_id'] . ':partida:null:evento:TICKET_CREADO:intentos',
                ]);
            }),
            'storage_private_rejected' => $this->invalidOutboxRejected($fixture, ['text' => 'storage/private/file.pdf']),
            'storage_uploads_rejected' => $this->invalidOutboxRejected($fixture, ['html' => 'storage/uploads/file.pdf']),
            'windows_path_rejected' => $this->invalidOutboxRejected($fixture, ['text' => 'C:\\secret\\file.pdf']),
            'password_rejected' => $this->invalidOutboxRejected($fixture, ['error_mensaje_seguro' => 'password rejected']),
            'dsn_rejected' => $this->invalidOutboxRejected($fixture, ['error_mensaje_seguro' => 'DSN failed']),
            'token_rejected' => $this->invalidOutboxRejected($fixture, ['error_mensaje_seguro' => 'token expired']),
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function repositoryServiceCases(array $fixture): array
    {
        $service = new ProductTicketEmailOutboxService(
            new ProductTicketEmailOutboxRepository($this->pdo)
        );

        $ticketCreated = $service->enqueueTicketCreated($fixture['ticket_id'], $fixture['user_id']);
        $ticketCreatedAgain = $service->enqueueTicketCreated($fixture['ticket_id'], $fixture['user_id']);
        $lineApproved = $service->enqueueLineApproved($fixture['ticket_id'], $fixture['partida_id'], $fixture['user_id']);
        $lineRejected = $service->enqueueLineRejected($fixture['ticket_id'], $fixture['partida_id_2'], $fixture['user_id']);
        $resolved = $service->enqueueTicketResolved($fixture['ticket_id'], 'TICKET_RESUELTO_TOTAL', $fixture['user_id']);
        $cancelled = $service->enqueueTicketCancelled($fixture['ticket_id'], $fixture['user_id']);
        $sent = $service->markSent((int) $lineApproved['id']);
        $error = $service->markError((int) $lineRejected['id'], 'Error temporal seguro.');
        $withoutEmail = $service->enqueueTicketCreated($fixture['ticket_without_email_id'], $fixture['user_without_email_id']);

        return [
            'enqueue_creates_pending' => is_array($ticketCreated)
                && (string) $ticketCreated['status'] === 'PENDIENTE',
            'enqueue_does_not_duplicate_same_event_ticket_line' => is_array($ticketCreatedAgain)
                && (int) $ticketCreatedAgain['id'] === (int) $ticketCreated['id']
                && $this->countOutboxByDedupe((string) $ticketCreated['dedupe_key']) === 1,
            'enqueue_without_email_returns_null' => $withoutEmail === null,
            'enqueue_generates_expected_subjects' =>
                str_contains((string) $ticketCreated['subject'], 'recibida')
                && str_contains((string) $lineApproved['subject'], 'Partida aprobada')
                && str_contains((string) $lineRejected['subject'], 'Partida rechazada')
                && str_contains((string) $resolved['subject'], 'resuelta')
                && str_contains((string) $cancelled['subject'], 'cancelada'),
            'enqueue_generates_text_and_html' =>
                trim((string) $ticketCreated['text']) !== ''
                && trim((string) $ticketCreated['html']) !== ''
                && str_contains((string) $ticketCreated['html'], '/tickets/productos/' . $fixture['ticket_id']),
            'enqueue_has_no_direct_file_links_or_paths' =>
                !$this->containsSensitive((string) $ticketCreated['text'])
                && !$this->containsSensitive((string) $ticketCreated['html']),
            'legacy_mark_sent_requires_claim_identity' => ($sent['result'] ?? null) === 'state_changed'
                && (string) ($sent['row']['status'] ?? '') === (string) $lineApproved['status']
                && ($sent['row']['enviado_at'] ?? null) === ($lineApproved['enviado_at'] ?? null),
            'legacy_mark_error_requires_claim_identity' => ($error['result'] ?? null) === 'state_changed'
                && (string) ($error['row']['status'] ?? '') === (string) $lineRejected['status']
                && ($error['row']['error_mensaje_seguro'] ?? null)
                    === ($lineRejected['error_mensaje_seguro'] ?? null),
            'mark_error_rejects_sensitive_message' => $this->fails(
                fn () => $service->markError((int) $lineApproved['id'], 'DSN password token')
            ),
            'unsupported_event_rejected' => $this->fails(
                fn () => $service->enqueue([
                    'ticket_id' => $fixture['ticket_id'],
                    'evento' => 'ADJUNTO_CARGADO',
                    'creado_por_usuario_id' => $fixture['user_id'],
                ])
            ),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function securityGuardrails(): array
    {
        $migration = $this->read('database/migrations/tp_partidas_estados_correo_outbox_db_1_001_create_ticket_product_email_outbox.php');
        $service = $this->read('app/Domain/Tickets/ProductTicketEmailOutboxService.php');
        $repository = $this->read('app/Infrastructure/Repositories/ProductTicketEmailOutboxRepository.php');
        $runtime = $migration . "\n" . $service . "\n" . $repository;

        return [
            'no_mail_function_call' => !preg_match('/\bmail\s*\(/i', $runtime),
            'no_phpmailer_direct' => !preg_match('/PHPMailer|SwiftMailer|Symfony\\\\Component\\\\Mailer/i', $runtime),
            'no_smtp_configuration' => !preg_match('/smtp_host|smtp_user|smtp_pass|smtp_password/i', $runtime),
            'no_external_http_call' => !preg_match('/curl_exec|file_get_contents\s*\(\s*[\'"]https?:|stream_socket_client/i', $runtime),
            'prepared_statements_used' => str_contains($repository, '->prepare('),
            'no_credentials_or_hash_terms_in_schema_payload' =>
                str_contains($migration, 'credenciales') === false
                && str_contains($migration, 'password_hash') === false,
            'sensitive_guard_present_in_db_and_service' =>
                str_contains($migration, 'chk_tickets_productos_correos_no_sensitive')
                && str_contains($service, 'assertSafeText'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function scopeGuardrails(): array
    {
        return [
            'no_config_mail_created' => !is_file(BASE_PATH . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'mail.php'),
            'routes_not_modified_for_phase' => !str_contains(
                $this->read('routes/web.php'),
                'TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1'
            ),
            'bootstrap_not_modified_for_phase' => !str_contains(
                $this->read('bootstrap/app.php'),
                'TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1'
            ),
            'product_request_service_not_modified_for_phase' => !str_contains(
                $this->read('app/Domain/Tickets/ProductRequestTicketService.php'),
                'ProductTicketEmailOutboxService'
            ),
            'controller_not_modified_for_phase' => !str_contains(
                $this->read('app/Http/Controllers/ProductRequestTicketController.php'),
                'ProductTicketEmailOutboxService'
            ),
            'no_new_routes_for_email' => (static function (string $routes): bool {
                $allowed = preg_replace(
                    '#/admin/correo(?:/cuentas|/reglas)?#',
                    '',
                    $routes
                );

                return is_string($allowed)
                    && preg_match('#/(correo|correos|email|mail)#i', $allowed) !== 1;
            })($this->read('routes/web.php')),
            'no_new_seeds_for_email' => !$this->hasFiles(
                'database/seeds',
                '/correo|correos|email|mail|notification|notificacion/i'
            ) || is_file(
                BASE_PATH . DIRECTORY_SEPARATOR . 'database'
                . DIRECTORY_SEPARATOR . 'seeds'
                . DIRECTORY_SEPARATOR . 'tp_partidas_estados_correo_config_1_seed_permissions.php'
            ),
            'no_product_price_inventory_purchase_supplier_created' =>
                !$this->hasFiles('app/Domain/Products', '/Outbox|Email/i')
                && !$this->hasFiles('app/Domain/Inventory', '/Outbox|Email/i')
                && !$this->hasFiles('app/Domain/Purchases', '/Outbox|Email/i'),
            'no_download_or_preview' => !preg_match(
                '#/tickets/productos/\{id\}/(?:adjuntos|archivos|attachments)/\{[^}]+\}/(?:descargar|download|preview|ver)#i',
                $this->read('routes/web.php')
            ),
            'package_files_do_not_add_mail_dependencies' =>
                !preg_match('/phpmailer|symfony\/mailer|swiftmailer|smtp/i', $this->read('package.json'))
                && !preg_match('/phpmailer|symfony\/mailer|swiftmailer|smtp/i', $this->read('package-lock.json')),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissionGuardrails(): array
    {
        $seed = $this->read('database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php');

        return [
            'existing_resend_permission_still_exists' => str_contains($seed, 'tickets_productos.correo.reenviar')
                || $this->permissionExists('tickets_productos.correo.reenviar'),
            'resend_permission_not_duplicated_in_db' =>
                !$this->tableExists((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn(), 'permisos')
                || $this->permissionCount('tickets_productos.correo.reenviar') <= 1,
            'no_new_mail_permissions_in_seed' =>
                !str_contains($seed, 'tickets_productos.correo.enviar')
                && !str_contains($seed, 'tickets_productos.correo.configurar')
                && !str_contains($seed, 'correos.')
                && !str_contains($seed, 'notificaciones.'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function documentationCases(): array
    {
        $doc = $this->read('docs/tickets-productos-partidas-estados-correo-outbox-db-1.md');

        return [
            'doc_exists' => $doc !== '',
            'documents_table_and_fields' => $this->textContainsAll($doc, [
                'tickets_productos_correos',
                'ticket_id',
                'partida_id',
                'evento',
                'plantilla',
                'destinatario_email',
                'cc_json',
                'subject',
                'html',
                'text',
                'status',
                'intentos',
                'max_intentos',
                'error_mensaje_seguro',
                'dedupe_key',
            ]),
            'documents_statuses_events_templates' => $this->textContainsAll($doc, [
                'PENDIENTE',
                'ENVIANDO',
                'ENVIADO',
                'ERROR',
                'CANCELADO',
                'TICKET_CREADO',
                'PARTIDA_APROBADA',
                'PARTIDA_RECHAZADA',
                'TICKET_RESUELTO_TOTAL',
                'TICKET_RESUELTO_PARCIAL',
                'TICKET_CANCELADO',
                'ticket_created',
                'line_approved',
                'line_rejected',
                'ticket_resolved',
                'ticket_cancelled',
            ]),
            'documents_dedupe_and_no_sensitive' => $this->textContainsAll($doc, [
                'dedupe_key',
                'storage/private',
                'storage/uploads',
                'DSN',
                'password',
                'secret',
                'token',
            ]),
            'documents_no_runtime_send' => $this->textContainsAll($doc, [
                'no envía correos reales',
                'no configura SMTP',
                'no usa PHPMailer directo',
                'no crea worker',
                'no crea cron',
                'no crea rutas',
            ]),
            'documents_next_phase' => str_contains($doc, 'TP-PARTIDAS-ESTADOS-CORREO-ORQUESTACION-1'),
        ];
    }

    private function ensureTicketTables(MigrationRunner $runner, Migration $migration): string
    {
        if ($this->tableExists((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn(), 'tickets_productos')) {
            return 'already_available';
        }

        $result = $runner->migrate($migration);

        if ($result !== 'applied') {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1 could not prepare ticket tables.');
        }

        return 'applied_for_test';
    }

    /**
     * @return array<string, int>
     */
    private function fixture(): array
    {
        $userId = $this->createUser('qa.tp.mail.outbox', 'qa.tp.mail.outbox@example.test');
        $userWithoutEmailId = $this->createUser('qa.tp.mail.noemail', 'noemail@example.test');
        $this->pdo->prepare('UPDATE usuarios SET email = :email WHERE id = :id')->execute([
            'email' => '',
            'id' => $userWithoutEmailId,
        ]);
        $companyId = $this->createCompany($userId);
        $warehouseId = $this->createWarehouse($companyId, $userId);
        $ticketId = $this->createTicket($companyId, $warehouseId, $userId, 'QA-900001');
        $ticketWithoutEmailId = $this->createTicket($companyId, $warehouseId, $userWithoutEmailId, 'QA-900002');
        $partidaId = $this->createLine($ticketId, 1, 'APROBADA', $userId);
        $partidaId2 = $this->createLine($ticketId, 2, 'RECHAZADA', $userId);

        return [
            'user_id' => $userId,
            'user_without_email_id' => $userWithoutEmailId,
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'ticket_id' => $ticketId,
            'ticket_without_email_id' => $ticketWithoutEmailId,
            'partida_id' => $partidaId,
            'partida_id_2' => $partidaId2,
        ];
    }

    private function createUser(string $username, string $email): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo, creado_en)
             VALUES (:username, :email, :password_hash, 1, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($username, PASSWORD_DEFAULT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createCompany(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => 'QATPMO',
            'nombre' => 'Empresa QA Mail Outbox',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createWarehouse(int $companyId, int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => 'MO',
            'nombre' => 'Almacén QA Mail Outbox',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createTicket(int $companyId, int $warehouseId, int $userId, string $folio): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos (
                folio,
                empresa_id,
                almacen_id,
                solicitante_usuario_id,
                estado,
                observaciones_generales,
                total_partidas,
                partidas_en_revision,
                partidas_aprobadas,
                partidas_rechazadas,
                created_at
             ) VALUES (
                :folio,
                :empresa_id,
                :almacen_id,
                :solicitante_usuario_id,
                \'EN_REVISION\',
                \'Solicitud documental QA outbox.\',
                2,
                0,
                1,
                1,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'folio' => $folio,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'solicitante_usuario_id' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createLine(int $ticketId, int $number, string $state, int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos_partidas (
                ticket_producto_id,
                numero_partida,
                estado,
                descripcion,
                motivo_rechazo,
                comentario_resolucion,
                resuelto_por_usuario_id,
                resuelto_at,
                created_at
             ) VALUES (
                :ticket_producto_id,
                :numero_partida,
                :estado,
                :descripcion,
                :motivo_rechazo,
                :comentario_resolucion,
                :resuelto_por_usuario_id,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_producto_id' => $ticketId,
            'numero_partida' => $number,
            'estado' => $state,
            'descripcion' => 'Partida QA Mail Outbox ' . $number,
            'motivo_rechazo' => $state === 'RECHAZADA' ? 'Información insuficiente.' : null,
            'comentario_resolucion' => 'Respuesta documental QA.',
            'resuelto_por_usuario_id' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertOutbox(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos_correos (
                ticket_id,
                partida_id,
                evento,
                plantilla,
                destinatario_email,
                cc_json,
                subject,
                html,
                text,
                status,
                intentos,
                max_intentos,
                error_mensaje_seguro,
                creado_por_usuario_id,
                dedupe_key,
                created_at
             ) VALUES (
                :ticket_id,
                :partida_id,
                :evento,
                :plantilla,
                :destinatario_email,
                :cc_json,
                :subject,
                :html,
                :text,
                :status,
                0,
                3,
                :error_mensaje_seguro,
                :creado_por_usuario_id,
                :dedupe_key,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_id' => $data['ticket_id'],
            'partida_id' => $data['partida_id'] ?? null,
            'evento' => $data['evento'],
            'plantilla' => $data['plantilla'],
            'destinatario_email' => $data['destinatario_email'],
            'cc_json' => $data['cc_json'] ?? null,
            'subject' => $data['subject'],
            'html' => $data['html'],
            'text' => $data['text'],
            'status' => $data['status'],
            'error_mensaje_seguro' => $data['error_mensaje_seguro'] ?? null,
            'creado_por_usuario_id' => $data['creado_por_usuario_id'] ?? null,
            'dedupe_key' => $data['dedupe_key'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function markRaw(int $id, string $status, ?string $error): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tickets_productos_correos
             SET status = :status,
                 intentos = intentos + 1,
                 ultimo_intento_at = CURRENT_TIMESTAMP,
                 enviado_at = CASE WHEN :status_sent = "ENVIADO" THEN CURRENT_TIMESTAMP ELSE enviado_at END,
                 error_mensaje_seguro = :error
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'status' => $status,
            'status_sent' => $status,
            'error' => $error,
        ]);
    }

    /**
     * @param array<string, int> $fixture
     * @param array<string, mixed> $overrides
     */
    private function invalidOutboxRejected(array $fixture, array $overrides): bool
    {
        return $this->fails(function () use ($fixture, $overrides): void {
            $suffix = bin2hex(random_bytes(4));
            $base = [
                'ticket_id' => $fixture['ticket_id'],
                'partida_id' => null,
                'evento' => 'TICKET_CREADO',
                'plantilla' => 'ticket_created',
                'destinatario_email' => 'qa.tp.outbox.' . $suffix . '@example.test',
                'cc_json' => null,
                'subject' => 'Solicitud de alta de producto QA-000001 recibida',
                'html' => '<p>Solicitud recibida.</p>',
                'text' => 'Solicitud recibida.',
                'status' => 'PENDIENTE',
                'dedupe_key' => 'ticket:' . $fixture['ticket_id'] . ':partida:null:evento:TICKET_CREADO:' . $suffix,
                'creado_por_usuario_id' => $fixture['user_id'],
            ];

            $this->insertOutbox(array_merge($base, $overrides));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function findOutbox(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tickets_productos_correos WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Outbox row not found.');
        }

        return $row;
    }

    private function countOutboxByDedupe(string $dedupeKey): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tickets_productos_correos WHERE dedupe_key = :dedupe_key'
        );
        $statement->execute(['dedupe_key' => $dedupeKey]);

        return (int) $statement->fetchColumn();
    }

    private function permissionExists(string $permission): bool
    {
        if (!$this->tableExists((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn(), 'permisos')) {
            return false;
        }

        return $this->permissionCount($permission) >= 1;
    }

    private function permissionCount(string $permission): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM permisos WHERE codigo = :codigo');
        $statement->execute(['codigo' => $permission]);

        return (int) $statement->fetchColumn();
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
            'movimientos_inventario' => $this->optionalCount('movimientos_inventario'),
            'compras' => $this->optionalCount('compras'),
            'proveedores' => $this->optionalCount('proveedores'),
        ];
    }

    private function optionalCount(string $table): int
    {
        if (!$this->tableExists((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn(), $table)) {
            return 0;
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    private function tableExists(string $database, string $table): bool
    {
        $statement = $this->pdo->prepare(
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
    private function columns(string $database, string $table): array
    {
        $statement = $this->pdo->prepare(
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

    private function isNullable(string $database, string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
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

    private function engine(string $database, string $table): string
    {
        $statement = $this->pdo->prepare(
            'SELECT ENGINE
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :database_name
               AND TABLE_NAME = :table_name'
        );
        $statement->execute([
            'database_name' => $database,
            'table_name' => $table,
        ]);

        return (string) $statement->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function indexes(string $database, string $table): array
    {
        $statement = $this->pdo->prepare(
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
    private function foreignKeys(string $database, string $table): array
    {
        $statement = $this->pdo->prepare(
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
    private function checks(string $database, string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT cc.CHECK_CLAUSE
             FROM information_schema.CHECK_CONSTRAINTS cc
             INNER JOIN information_schema.TABLE_CONSTRAINTS tc
                ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
               AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
             WHERE tc.TABLE_SCHEMA = :database_name
               AND tc.TABLE_NAME = :table_name
               AND tc.CONSTRAINT_TYPE = "CHECK"
             ORDER BY cc.CONSTRAINT_NAME'
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

    private function containsSensitive(string $value): bool
    {
        return preg_match('#storage/private|storage/uploads|dsn|password|secret|token|\b[a-z]:[\\\\/]#i', $value) === 1;
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (Throwable) {
            return true;
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
            } elseif ($value !== true) {
                return false;
            }
        }

        return true;
    }
};
