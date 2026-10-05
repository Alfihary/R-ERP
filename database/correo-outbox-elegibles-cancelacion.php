<?php

declare(strict_types=1);

use App\Domain\Audit\AuditService;
use App\Domain\Mail\MailOutboxActionService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\UserScopeService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\MailOutboxActionRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;

final class CorreoOutboxElegiblesCancellation
{
    public const DATABASE = 'r_erp_db_core_0_test';
    public const REASON = 'Cancelación administrativa de notificación antigua no enviada; preservada por trazabilidad.';
    private const SAVEPOINT = 'correo_outbox_elegibles_cancelacion';

    /** @var array<int, array<string, int|string|null>> */
    public const EXPECTED = [
        698 => [
            'ticket_id' => 3,
            'ticket_folio' => 'BO-000013',
            'ticket_status' => 'APROBADO',
            'partida_id' => 3,
            'partida_status' => 'APROBADA',
            'evento' => 'PARTIDA_APROBADA',
            'plantilla' => 'line_approved',
            'dedupe_key' => 'ticket:3:partida:3:evento:PARTIDA_APROBADA',
        ],
        699 => [
            'ticket_id' => 3,
            'ticket_folio' => 'BO-000013',
            'ticket_status' => 'APROBADO',
            'partida_id' => 4,
            'partida_status' => 'APROBADA',
            'evento' => 'PARTIDA_APROBADA',
            'plantilla' => 'line_approved',
            'dedupe_key' => 'ticket:3:partida:4:evento:PARTIDA_APROBADA',
        ],
        700 => [
            'ticket_id' => 3,
            'ticket_folio' => 'BO-000013',
            'ticket_status' => 'APROBADO',
            'partida_id' => null,
            'partida_status' => null,
            'evento' => 'TICKET_RESUELTO_TOTAL',
            'plantilla' => 'ticket_resolved',
            'dedupe_key' => 'ticket:3:partida:null:evento:TICKET_RESUELTO_TOTAL',
        ],
    ];

    public function __construct(
        private readonly ConnectionProvider $connection,
        private readonly PermissionService $permissions,
        private readonly UserScopeService $scope
    ) {
    }

    /** @return array<string, mixed> */
    public function audit(): array
    {
        $rows = $this->fetchRows(array_keys(self::EXPECTED), false);
        $this->assertPreconditions($rows, self::EXPECTED);
        [$actorId, $warehouseIds] = $this->authorize($rows);

        return [
            'result' => 'PASS_AUDIT',
            'database' => self::DATABASE,
            'ids' => array_keys(self::EXPECTED),
            'row_count' => count($rows),
            'actor_user_id' => $actorId,
            'permission' => 'correos.cola.cancelar',
            'warehouse_scope_count' => count($warehouseIds),
            'rows' => array_map(fn (array $row): array => $this->safeRow($row), $rows),
            'eligible_count' => $this->eligibleCount(),
            'smtp_connections' => 0,
            'emails_sent' => 0,
            'secret_resolutions' => 0,
            'database_changed' => false,
        ];
    }

    /**
     * @param array<int, array<string, int|string|null>> $expected
     * @param null|callable(int, int, PDO):void $beforeCancel Test-only fault injector.
     * @return array<string, mixed>
     */
    public function executeBatch(array $expected, ?callable $beforeCancel = null): array
    {
        $pdo = $this->connection->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }

        try {
            $ids = array_keys($expected);
            $rows = $this->fetchRows($ids, true);
            $this->assertPreconditions($rows, $expected);
            [$actorId, $warehouseIds] = $this->authorize($rows);
            $before = $this->integritySnapshot($rows);
            $auditBefore = $this->auditCounts($ids);

            $auditRepository = new AuditRepository($this->connection);
            $service = new MailOutboxActionService(
                $this->connection,
                new MailOutboxActionRepository($this->connection),
                new AuditService($auditRepository),
                $auditRepository
            );

            foreach ($ids as $index => $id) {
                if ($beforeCancel !== null) {
                    $beforeCancel($index, $id, $pdo);
                }
                $result = $service->cancel(
                    $id,
                    $actorId,
                    $warehouseIds,
                    self::REASON,
                    ['user_agent' => 'CORREO-OUTBOX-ELEGIBLES-CANCELACION-1']
                );
                if ($result['result'] !== 'success' || $result['status'] !== 'CANCELADO') {
                    throw new RuntimeException('Atomic cancellation failed for outbox id ' . $id . '.');
                }
            }

            $afterRows = $this->fetchRows($ids, true);
            $after = $this->integritySnapshot($afterRows);
            foreach ($afterRows as $row) {
                if ($row['status'] !== 'CANCELADO'
                    || (int) $row['intentos'] !== 0
                    || $row['ultimo_intento_at'] !== null
                    || $row['enviado_at'] !== null
                    || $row['cancelado_at'] === null
                ) {
                    throw new RuntimeException('Post-cancellation state is invalid.');
                }
            }
            foreach ($ids as $id) {
                if ($before[$id] !== $after[$id]) {
                    throw new RuntimeException('Protected outbox content changed for id ' . $id . '.');
                }
            }
            $auditAfter = $this->auditCounts($ids);
            foreach ($ids as $id) {
                if (($auditAfter[$id] ?? 0) !== ($auditBefore[$id] ?? 0) + 1) {
                    throw new RuntimeException('Required cancellation audit was not recorded exactly once.');
                }
            }

            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            return [
                'result' => 'PASS_CANCELLED',
                'ids' => $ids,
                'cancelled_count' => count($ids),
                'actor_user_id' => $actorId,
                'warehouse_scope_count' => count($warehouseIds),
                'audit_rows_created' => count($ids),
                'protected_content_unchanged' => true,
                'smtp_connections' => 0,
                'emails_sent' => 0,
                'secret_resolutions' => 0,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function execute(): array
    {
        $before = $this->databaseState();
        $result = $this->executeBatch(self::EXPECTED);
        $afterRows = $this->fetchRows(array_keys(self::EXPECTED), false);
        $after = $this->databaseState();

        $result['rows'] = array_map(fn (array $row): array => $this->safeRow($row), $afterRows);
        $result['before'] = $before;
        $result['after'] = $after;
        $result['database_changed'] = true;

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, array<string, int|string|null>> $expected
     */
    public function assertPreconditions(array $rows, array $expected): void
    {
        if (count($rows) !== count($expected)) {
            throw new RuntimeException('Expected outbox rows were not found exactly.');
        }

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $contract = $expected[$id] ?? null;
            if ($contract === null) {
                throw new RuntimeException('Unexpected outbox id in cancellation set.');
            }
            $actual = [
                'ticket_id' => (int) $row['ticket_id'],
                'ticket_folio' => (string) $row['ticket_folio'],
                'ticket_status' => (string) $row['ticket_status'],
                'partida_id' => $row['partida_id'] === null ? null : (int) $row['partida_id'],
                'partida_status' => $row['partida_status'] === null ? null : (string) $row['partida_status'],
                'evento' => (string) $row['evento'],
                'plantilla' => (string) $row['plantilla'],
                'dedupe_key' => (string) $row['dedupe_key'],
            ];
            if ($actual !== $contract
                || $row['status'] !== 'PENDIENTE'
                || (int) $row['intentos'] !== 0
                || $row['ultimo_intento_at'] !== null
                || $row['enviado_at'] !== null
                || $row['cancelado_at'] !== null
            ) {
                throw new RuntimeException('Outbox precondition mismatch for id ' . $id . '.');
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{0:int,1:list<int>}
     */
    private function authorize(array $rows): array
    {
        $actorIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['creado_por_usuario_id'],
            $rows
        )));
        if (count($actorIds) !== 1 || $actorIds[0] < 1) {
            throw new RuntimeException('Cancellation actor cannot be derived unambiguously.');
        }
        $actorId = $actorIds[0];
        if (!$this->permissions->allows($actorId, 'correos.cola.cancelar')) {
            throw new RuntimeException('Derived actor lacks cancellation permission.');
        }

        $warehouseIds = array_values(array_map(
            static fn (array $warehouse): int => (int) $warehouse['id'],
            $this->scope->resolveForUser($actorId)->warehouses()
        ));
        foreach ($rows as $row) {
            if (!in_array((int) $row['almacen_id'], $warehouseIds, true)) {
                throw new RuntimeException('Derived actor is outside the required warehouse scope.');
            }
        }

        return [$actorId, $warehouseIds];
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    private function fetchRows(array $ids, bool $forUpdate): array
    {
        $ids = array_values(array_map('intval', $ids));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "SELECT o.*, t.folio AS ticket_folio, t.estado AS ticket_status,
                       t.almacen_id, p.estado AS partida_status
                FROM tickets_productos_correos o
                INNER JOIN tickets_productos t ON t.id = o.ticket_id
                LEFT JOIN tickets_productos_partidas p ON p.id = o.partida_id
                WHERE o.id IN ({$placeholders})
                ORDER BY o.id" . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($ids);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array<string, mixed>> $rows @return array<int, string> */
    private function integritySnapshot(array $rows): array
    {
        $snapshot = [];
        foreach ($rows as $row) {
            $protected = [
                'ticket_id' => (int) $row['ticket_id'],
                'partida_id' => $row['partida_id'] === null ? null : (int) $row['partida_id'],
                'evento' => $row['evento'],
                'plantilla' => $row['plantilla'],
                'destinatario_email' => $row['destinatario_email'],
                'cc_json' => $row['cc_json'],
                'subject' => $row['subject'],
                'html' => $row['html'],
                'text' => $row['text'],
                'intentos' => (int) $row['intentos'],
                'max_intentos' => (int) $row['max_intentos'],
                'error_mensaje_seguro' => $row['error_mensaje_seguro'],
                'ultimo_intento_at' => $row['ultimo_intento_at'],
                'enviado_at' => $row['enviado_at'],
                'creado_por_usuario_id' => (int) $row['creado_por_usuario_id'],
                'dedupe_key' => $row['dedupe_key'],
            ];
            $snapshot[(int) $row['id']] = hash(
                'sha256',
                json_encode($protected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
        }

        return $snapshot;
    }

    /** @param list<int> $ids @return array<int, int> */
    private function auditCounts(array $ids): array
    {
        $counts = [];
        $statement = $this->connection->pdo()->prepare(
            "SELECT COUNT(*) FROM auditoria_eventos
             WHERE accion = 'MAIL_OUTBOX_CANCELLED'
               AND entidad = 'tickets_productos_correos'
               AND entidad_id = :id"
        );
        foreach ($ids as $id) {
            $statement->execute(['id' => (string) $id]);
            $counts[$id] = (int) $statement->fetchColumn();
        }

        return $counts;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function safeRow(array $row): array
    {
        $cc = json_decode((string) ($row['cc_json'] ?? ''), true);
        $cc = is_array($cc) ? $cc : [];

        return [
            'id' => (int) $row['id'],
            'ticket_id' => (int) $row['ticket_id'],
            'partida_id' => $row['partida_id'] === null ? null : (int) $row['partida_id'],
            'evento' => $row['evento'],
            'plantilla' => $row['plantilla'],
            'status' => $row['status'],
            'intentos' => (int) $row['intentos'],
            'max_intentos' => (int) $row['max_intentos'],
            'ultimo_intento_at' => $row['ultimo_intento_at'],
            'enviado_at' => $row['enviado_at'],
            'cancelado_at_present' => $row['cancelado_at'] !== null,
            'dedupe_key' => $row['dedupe_key'],
            'to_count' => trim((string) $row['destinatario_email']) === '' ? 0 : 1,
            'cc_count' => count($cc['cc'] ?? []),
            'bcc_count' => count($cc['bcc'] ?? []),
            'recipient_envelope_sha256' => hash('sha256', (string) $row['destinatario_email'] . '|' . (string) $row['cc_json']),
            'subject_sha256' => hash('sha256', (string) $row['subject']),
            'html_sha256' => hash('sha256', (string) $row['html']),
            'text_sha256' => hash('sha256', (string) $row['text']),
        ];
    }

    private function eligibleCount(): int
    {
        return (int) $this->connection->pdo()->query(
            "SELECT COUNT(*) FROM tickets_productos_correos
             WHERE status IN ('PENDIENTE', 'ERROR')
               AND intentos < max_intentos
               AND enviado_at IS NULL
               AND cancelado_at IS NULL"
        )->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function databaseState(): array
    {
        $pdo = $this->connection->pdo();
        $ticket34 = $pdo->query(
            "SELECT id, folio, estado, total_partidas FROM tickets_productos WHERE id = 34"
        )->fetch(PDO::FETCH_ASSOC) ?: null;
        $special = [];
        foreach ([1, 36] as $id) {
            $statement = $pdo->prepare(
                'SELECT id, status, intentos, enviado_at, cancelado_at FROM tickets_productos_correos WHERE id = :id'
            );
            $statement->execute(['id' => $id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $special[(string) $id] = is_array($row) ? [
                'status' => $row['status'],
                'intentos' => (int) $row['intentos'],
                'enviado_at_present' => $row['enviado_at'] !== null,
                'cancelado_at_present' => $row['cancelado_at'] !== null,
            ] : null;
        }

        return [
            'tickets_count' => (int) $pdo->query('SELECT COUNT(*) FROM tickets_productos')->fetchColumn(),
            'outbox_count' => (int) $pdo->query('SELECT COUNT(*) FROM tickets_productos_correos')->fetchColumn(),
            'eligible_count' => $this->eligibleCount(),
            'ticket_34' => $ticket34,
            'protected_outbox' => $special,
        ];
    }
}

if (PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    define('BASE_PATH', dirname(__DIR__));

    try {
        $command = $argv[1] ?? '';
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (!str_starts_with($argument, '--')) {
                throw new RuntimeException('Unexpected CLI argument.');
            }
            [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
            $options[$name] = $value;
        }
        if (!in_array($command, ['audit', 'execute', 'db:test'], true)) {
            throw new RuntimeException('Unknown CORREO-OUTBOX-ELEGIBLES-CANCELACION-1 command.');
        }

        $database = trim($options['database'] ?? '');
        $confirmation = trim($options['confirm-database'] ?? '');
        if ($database !== CorreoOutboxElegiblesCancellation::DATABASE || $confirmation !== $database) {
            throw new RuntimeException('WRITE_REFUSED=true; reason=database_confirmation_mismatch');
        }

        $config = require BASE_PATH . '/bootstrap/database.php';
        $environment = strtolower((string) $config->get('app.env', 'production'));
        if ($environment === 'production') {
            throw new RuntimeException('WRITE_REFUSED=true; reason=production_environment');
        }
        $databaseConfig = $config->get('database', []);
        if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $database) {
            throw new RuntimeException('WRITE_REFUSED=true; reason=configured_database_mismatch');
        }

        $connection = new ConnectionProvider($databaseConfig);
        if ((string) $connection->pdo()->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('WRITE_REFUSED=true; reason=active_database_mismatch');
        }
        $runner = new CorreoOutboxElegiblesCancellation(
            $connection,
            new PermissionService(new PermissionRepository($connection)),
            new UserScopeService(new ScopeRepository($connection))
        );

        if ($command === 'execute') {
            if (($options['confirm-cancel-ids'] ?? '') !== '698,699,700'
                || ($options['confirm-no-email'] ?? '') !== 'YES'
            ) {
                throw new RuntimeException('WRITE_REFUSED=true; reason=execute_confirmation_mismatch');
            }
            $result = $runner->execute();
        } elseif ($command === 'db:test') {
            $GLOBALS['correo_outbox_elegibles_cancelacion_connection'] = $connection;
            $test = require BASE_PATH . '/database/tests/correo_outbox_elegibles_cancelacion_1_test.php';
            if (!$test instanceof App\Infrastructure\Database\DatabaseTest) {
                throw new RuntimeException('Cancellation DB-TEST contract is invalid.');
            }
            $result = $test->run($connection->pdo(), $database);
        } else {
            $result = $runner->audit();
        }

        echo json_encode(
            ['command' => $command, 'result' => $result],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } catch (PDOException $exception) {
        fwrite(STDERR, 'CORREO-OUTBOX-ELEGIBLES-CANCELACION-1 failed with SQLSTATE['
            . $exception->getCode() . '].' . PHP_EOL);
        exit(1);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
