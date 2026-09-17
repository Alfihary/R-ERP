<?php

declare(strict_types=1);

use App\Domain\Mail\MailConfigurationService;
use App\Domain\Tickets\ProductTicketEmailNotificationService;
use App\Domain\Tickets\ProductTicketEmailOutboxService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\MailConfigurationRepository;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;

return new class implements DatabaseTest {
    private PDO $pdo;

    /** @return array<string, mixed> */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected database for mail orchestration test.');
        }
        foreach (['tickets_productos_correos', 'mail_accounts', 'tickets_productos_correo_reglas'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Required existing table is missing: ' . $table);
            }
        }

        $countsBefore = $this->operationalCounts();
        $pdo->beginTransaction();
        try {
            $fixture = $this->fixture();
            $configuration = new MailConfigurationService(new MailConfigurationRepository($this->connection()));
            $configuration->saveAccount([
                'nombre' => 'Orquestación QA',
                'from_email' => 'tickets@example.test',
                'from_name' => 'SoporteGR Tickets',
                'reply_to_email' => 'reply@example.test',
                'smtp_host' => 'smtp.example.test',
                'smtp_port' => '587',
                'smtp_encryption' => 'tls',
                'smtp_username' => 'tickets@example.test',
                'smtp_secret_ref' => 'MAIL_TICKETS_PRIMARY_PASSWORD',
                'activo' => '1',
            ]);
            $rules = [];
            foreach (MailConfigurationService::EVENTS as $event) {
                $rules[$event] = [
                    'enviar_solicitante' => '1',
                    'enviar_responsables' => '1',
                    'to' => 'fixed@example.test',
                    'cc' => 'cc@example.test',
                    'bcc' => 'bcc@example.test',
                    'activo' => '1',
                ];
            }
            $configuration->saveRules(['rules' => $rules]);

            $orchestrator = new ProductTicketEmailNotificationService(
                $configuration,
                new ProductTicketEmailOutboxService(new ProductTicketEmailOutboxRepository($this->connection()))
            );
            $results = [];
            $events = [
                ['TICKET_CREADO', null],
                ['PARTIDA_APROBADA', $fixture['partida_id']],
                ['PARTIDA_RECHAZADA', $fixture['partida_id_2']],
                ['TICKET_RESUELTO_PARCIAL', null],
                ['TICKET_RESUELTO_TOTAL', null],
                ['TICKET_CANCELADO', null],
            ];
            foreach ($events as [$event, $partidaId]) {
                $results[$event] = $orchestrator->handle(
                    $event,
                    $fixture['ticket_id'],
                    $partidaId,
                    $fixture['user_id'],
                    'responsable@example.test'
                );
            }

            $duplicate = $orchestrator->handle(
                'TICKET_CREADO',
                $fixture['ticket_id'],
                null,
                $fixture['user_id'],
                'responsable@example.test'
            );
            $this->setRuleActive('TICKET_CREADO', false);
            $inactive = $orchestrator->handle(
                'TICKET_CREADO',
                $fixture['ticket_id_2'],
                null,
                $fixture['user_id'],
                'responsable@example.test'
            );
            $this->setRuleActive('TICKET_CREADO', true);
            $this->setAccountActive(false);
            $noAccount = $orchestrator->handle(
                'TICKET_CREADO',
                $fixture['ticket_id_2'],
                null,
                $fixture['user_id'],
                'responsable@example.test'
            );
            $this->setAccountActive(true);
            $this->setRuleRecipients('TICKET_CREADO', false);
            $noRecipients = $orchestrator->handle(
                'TICKET_CREADO',
                $fixture['ticket_without_email_id'],
                null,
                $fixture['user_id'],
                null
            );

            $created = $results['TICKET_CREADO']['outbox'];
            $envelope = is_array($created)
                ? json_decode((string) ($created['cc_json'] ?? ''), true)
                : null;
            $rows = $this->outboxRows($fixture['ticket_id']);
            $source = $this->sourceContract();
            $cases = [
                'events' => [
                    'ticket_created_enqueued' => $results['TICKET_CREADO']['notification_enqueued'] === true,
                    'line_approved_enqueued' => $results['PARTIDA_APROBADA']['notification_enqueued'] === true,
                    'line_rejected_enqueued' => $results['PARTIDA_RECHAZADA']['notification_enqueued'] === true,
                    'resolved_partial_enqueued' => $results['TICKET_RESUELTO_PARCIAL']['notification_enqueued'] === true,
                    'resolved_total_enqueued' => $results['TICKET_RESUELTO_TOTAL']['notification_enqueued'] === true,
                    'cancelled_enqueued' => $results['TICKET_CANCELADO']['notification_enqueued'] === true,
                    'all_rows_pending' => count($rows) === 6 && $this->allValue($rows, 'status', 'PENDIENTE'),
                ],
                'configuration' => [
                    'inactive_rule_skips' => $inactive['notification_reason'] === 'event_rule_inactive',
                    'missing_active_account_skips' => $noAccount['notification_reason'] === 'no_active_mail_account',
                    'no_valid_recipients_skips' => $noRecipients['notification_reason'] === 'no_valid_recipients',
                    'requester_from_real_user' => is_array($envelope)
                        && in_array('requester@example.test', $envelope['to'] ?? [], true),
                    'fixed_to_resolved' => is_array($envelope)
                        && in_array('fixed@example.test', $envelope['to'] ?? [], true),
                    'cc_resolved' => is_array($envelope)
                        && in_array('cc@example.test', $envelope['cc'] ?? [], true),
                    'bcc_resolved' => is_array($envelope)
                        && in_array('bcc@example.test', $envelope['bcc'] ?? [], true),
                    'responsible_only_from_trusted_actor' => is_array($envelope)
                        && in_array('responsable@example.test', $envelope['cc'] ?? [], true),
                    'recipients_are_deduplicated' => is_array($envelope) && $this->uniqueEnvelope($envelope),
                ],
                'payload' => [
                    'subjects_and_templates_match' => $this->payloadContracts($rows),
                    'html_and_text_present' => $this->allNonEmpty($rows, ['html', 'text']),
                    'internal_link_present' => $this->allContain($rows, '/tickets/productos/' . $fixture['ticket_id']),
                    'no_sensitive_content' => !$this->rowsContain($rows, [
                        'storage/private', 'storage/uploads', 'password', 'dsn', 'token', 'C:\\',
                    ]),
                    'no_file_links_or_attachments' => !$this->rowsContain($rows, ['download', 'preview', 'ruta_relativa']),
                ],
                'dedupe' => [
                    'duplicate_does_not_insert' => $duplicate['notification_reason'] === 'duplicate_dedupe_key',
                    'one_row_per_event_scope' => count($rows) === 6,
                    'current_model_is_irreversible' => $source['line_resolution_is_one_way'],
                ],
                'integration' => $source,
            ];

            if (!$this->allTrue($cases)) {
                throw new RuntimeException('Mail orchestration assertions failed: ' . json_encode($cases));
            }

            return [
                'database' => $expectedDatabase,
                'events' => array_column($events, 0),
                'cases' => $cases,
                'operational_counts_before' => $countsBefore,
                'operational_counts_during' => $this->operationalCounts(),
                'cleanup' => 'transaction_rolled_back_no_operational_data_written',
            ];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function connection(): ConnectionProvider
    {
        $connection = $GLOBALS['tp_mail_orchestration_connection'] ?? null;
        if (!$connection instanceof ConnectionProvider) {
            throw new RuntimeException('Shared orchestration connection is unavailable.');
        }
        return $connection;
    }

    /** @return array<string,int> */
    private function fixture(): array
    {
        $suffix = substr(bin2hex(random_bytes(5)), 0, 10);
        $userId = $this->insert('usuarios', [
            'username' => 'qa.orch.' . $suffix,
            'email' => 'requester@example.test',
            'password_hash' => password_hash($suffix, PASSWORD_DEFAULT),
            'activo' => 1,
            'creado_en' => date('Y-m-d H:i:s'),
        ]);
        $noEmailUser = $this->insert('usuarios', [
            'username' => 'qa.noemail.' . $suffix,
            'email' => '',
            'password_hash' => password_hash($suffix . 'x', PASSWORD_DEFAULT),
            'activo' => 1,
            'creado_en' => date('Y-m-d H:i:s'),
        ]);
        $companyId = $this->insert('empresas', [
            'codigo' => 'QO' . strtoupper(substr($suffix, 0, 6)),
            'nombre' => 'Empresa QA Orquestación',
            'activo' => 1,
            'creado_por' => $userId,
        ]);
        $warehouseId = $this->insert('almacenes', [
            'empresa_id' => $companyId,
            'codigo' => 'QO' . strtoupper(substr($suffix, 0, 4)),
            'nombre' => 'Almacén QA Orquestación',
            'activo' => 1,
            'creado_por' => $userId,
        ]);
        $prefix = strtoupper(substr($suffix, 0, 6));
        $ticketId = $this->ticket($companyId, $warehouseId, $userId, $prefix . '-900001');
        $ticketId2 = $this->ticket($companyId, $warehouseId, $userId, $prefix . '-900002');
        $ticketWithoutEmail = $this->ticket($companyId, $warehouseId, $noEmailUser, $prefix . '-900003');
        $partidaId = $this->line($ticketId, 1, 'APROBADA', $userId);
        $partidaId2 = $this->line($ticketId, 2, 'RECHAZADA', $userId);
        return compact('userId', 'companyId', 'warehouseId') + [
            'user_id' => $userId,
            'ticket_id' => $ticketId,
            'ticket_id_2' => $ticketId2,
            'ticket_without_email_id' => $ticketWithoutEmail,
            'partida_id' => $partidaId,
            'partida_id_2' => $partidaId2,
        ];
    }

    private function ticket(int $companyId, int $warehouseId, int $userId, string $folio): int
    {
        return $this->insert('tickets_productos', [
            'folio' => $folio, 'empresa_id' => $companyId, 'almacen_id' => $warehouseId,
            'solicitante_usuario_id' => $userId, 'estado' => 'RESUELTO_PARCIAL',
            'observaciones_generales' => 'Solicitud QA.', 'total_partidas' => 2,
            'partidas_en_revision' => 0, 'partidas_aprobadas' => 1,
            'partidas_rechazadas' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function line(int $ticketId, int $number, string $state, int $userId): int
    {
        return $this->insert('tickets_productos_partidas', [
            'ticket_producto_id' => $ticketId, 'numero_partida' => $number,
            'estado' => $state, 'descripcion' => 'Partida QA ' . $number,
            'motivo_rechazo' => $state === 'RECHAZADA' ? 'Motivo QA.' : null,
            'comentario_resolucion' => 'Comentario QA.', 'resuelto_por_usuario_id' => $userId,
            'resuelto_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $values */
    private function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    private function setRuleActive(string $event, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE tickets_productos_correo_reglas SET activo = :active WHERE evento = :event');
        $statement->execute(['active' => $active ? 1 : 0, 'event' => $event]);
    }

    private function setAccountActive(bool $active): void
    {
        $this->pdo->prepare("UPDATE mail_accounts SET activo = :active WHERE codigo = 'TICKETS_PRODUCTOS'")
            ->execute(['active' => $active ? 1 : 0]);
    }

    private function setRuleRecipients(string $event, bool $enabled): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tickets_productos_correo_reglas SET enviar_solicitante = :requester_flag,
             enviar_responsables = :responsible_flag, to_json = NULL, cc_json = NULL, bcc_json = NULL
             WHERE evento = :event'
        );
        $statement->execute([
            'requester_flag' => $enabled ? 1 : 0,
            'responsible_flag' => $enabled ? 1 : 0,
            'event' => $event,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function outboxRows(int $ticketId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tickets_productos_correos WHERE ticket_id = :id ORDER BY id');
        $statement->execute(['id' => $ticketId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,bool> */
    private function sourceContract(): array
    {
        $ticket = file_get_contents(BASE_PATH . '/app/Domain/Tickets/ProductRequestTicketService.php') ?: '';
        $orchestration = file_get_contents(BASE_PATH . '/app/Domain/Tickets/ProductTicketEmailNotificationService.php') ?: '';
        $outbox = file_get_contents(BASE_PATH . '/app/Domain/Tickets/ProductTicketEmailOutboxService.php') ?: '';
        $controller = file_get_contents(BASE_PATH . '/app/Http/Controllers/ProductRequestTicketController.php') ?: '';
        $routes = file_get_contents(BASE_PATH . '/routes/web.php') ?: '';
        return [
            'ticket_created_hooked_after_transaction' => preg_match(
                '/\$ticket\s*=\s*\$this->transactional\(.*?\$ticket\[\'notification\'\]\s*=\s*\$this->notify\(\s*\'TICKET_CREADO\'/s',
                $ticket
            ) === 1,
            'approval_and_rejection_hooked' => str_contains($ticket, "'PARTIDA_APROBADA'") && str_contains($ticket, "'PARTIDA_RECHAZADA'"),
            'partial_and_total_hooked' => str_contains($ticket, "'TICKET_RESUELTO_PARCIAL'") && str_contains($ticket, "'TICKET_RESUELTO_TOTAL'"),
            'cancel_hooked' => str_contains($ticket, "'TICKET_CANCELADO'"),
            'comment_and_attachment_not_notified' => $this->methodHasNoNotification(
                $ticket,
                'public function agregarComentario',
                'public function agregarAdjunto'
            ) && $this->methodHasNoNotification(
                $ticket,
                'public function agregarAdjunto',
                'public function obtenerTicket'
            ),
            'line_resolution_is_one_way' => str_contains($ticket, 'La partida ya fue resuelta.'),
            'safe_notification_result' => str_contains($orchestration, 'notification_enqueued') && str_contains($orchestration, 'notification_reason'),
            'configuration_errors_do_not_break_ticket' => str_contains($orchestration, 'catch (Throwable)'),
            'actor_email_is_not_read_from_public_request' => !str_contains($controller, "input('responsable_email'") && !str_contains($controller, "input('cc'") && !str_contains($controller, "input('bcc'"),
            'no_smtp_secret_resolution' => !preg_match('/getenv|smtp_secret_ref\s*\]/i', $orchestration . $outbox),
            'no_smtp_mail_phpmailer_worker_cron_http' => !preg_match('/\\bmail\s*\(|PHPMailer|curl_|file_get_contents\s*\(\s*[\'\"]https?:|worker|cron/i', $orchestration . $outbox),
            'no_new_mail_routes' => !str_contains($routes, '/correo/orquestacion'),
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function payloadContracts(array $rows): bool
    {
        $expected = [
            'TICKET_CREADO' => ['ticket_created', 'recibida'],
            'PARTIDA_APROBADA' => ['line_approved', 'Partida aprobada'],
            'PARTIDA_RECHAZADA' => ['line_rejected', 'Partida rechazada'],
            'TICKET_RESUELTO_PARCIAL' => ['ticket_resolved', 'parcialmente'],
            'TICKET_RESUELTO_TOTAL' => ['ticket_resolved', 'resuelta'],
            'TICKET_CANCELADO' => ['ticket_cancelled', 'cancelada'],
        ];
        foreach ($rows as $row) {
            $contract = $expected[(string) $row['evento']] ?? null;
            if ($contract === null || $row['plantilla'] !== $contract[0] || !str_contains((string) $row['subject'], $contract[1])) {
                return false;
            }
        }
        return true;
    }

    private function methodHasNoNotification(string $source, string $start, string $end): bool
    {
        $startAt = strpos($source, $start);
        $endAt = strpos($source, $end);
        if ($startAt === false || $endAt === false || $endAt <= $startAt) {
            return false;
        }
        return !str_contains(substr($source, $startAt, $endAt - $startAt), '$this->notify(');
    }

    /** @param array<string,mixed> $envelope */
    private function uniqueEnvelope(array $envelope): bool
    {
        $all = array_merge($envelope['to'] ?? [], $envelope['cc'] ?? [], $envelope['bcc'] ?? []);
        return count($all) === count(array_unique($all));
    }

    /** @param list<array<string,mixed>> $rows */
    private function allValue(array $rows, string $key, string $value): bool
    {
        foreach ($rows as $row) if (($row[$key] ?? null) !== $value) return false;
        return true;
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $keys */
    private function allNonEmpty(array $rows, array $keys): bool
    {
        foreach ($rows as $row) foreach ($keys as $key) if (trim((string) ($row[$key] ?? '')) === '') return false;
        return true;
    }

    /** @param list<array<string,mixed>> $rows */
    private function allContain(array $rows, string $needle): bool
    {
        foreach ($rows as $row) if (!str_contains((string) ($row['html'] ?? '') . ($row['text'] ?? ''), $needle)) return false;
        return true;
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $needles */
    private function rowsContain(array $rows, array $needles): bool
    {
        foreach ($rows as $row) {
            $text = strtolower((string) ($row['subject'] ?? '') . ($row['html'] ?? '') . ($row['text'] ?? ''));
            foreach ($needles as $needle) if (str_contains($text, strtolower($needle))) return true;
        }
        return false;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() === 1;
    }

    /** @return array<string,int> */
    private function operationalCounts(): array
    {
        $counts = [];
        foreach (['productos', 'producto_precios', 'existencias_producto', 'movimientos_inventario', 'compras', 'proveedores'] as $table) {
            $counts[$table] = $this->tableExists($table) ? (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() : 0;
        }
        return $counts;
    }

    private function allTrue(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value) ? !$this->allTrue($value) : $value !== true) return false;
        }
        return true;
    }
};
