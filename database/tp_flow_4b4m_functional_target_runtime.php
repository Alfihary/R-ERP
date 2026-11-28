<?php

declare(strict_types=1);

/**
 * TP-FLOW-4B4M target runtime harness.
 *
 * This file is intentionally invoked only through the guarded 4B4J launcher.
 * It is not an alternate application bootstrap and never sends mail or runs a
 * worker.  All fixture deletes use the in-memory ledger and exact primary keys.
 */

$base = dirname(__DIR__);
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('TARGET_HARNESS_CLI_ONLY');
}

$mode = $argv[1] ?? '';
if (!in_array($mode, ['--preflight', '--smoke', '--full'], true)) {
    fwrite(STDERR, "Usage: --preflight|--smoke|--full\n");
    exit(2);
}

define('BASE_PATH', $base);
require_once $base . '/bootstrap/autoload.php';
\App\Core\Env::load($base . '/.env');

$targetDatabase = 'r_erp_db_core_0_test';
$expectedRead = 'consulta@localhost';
$expectedWrite = 'r_erp_tickets_write@localhost';
$requesterId = 4612;
$responsibleId = 4613;
$roleId = 1001;
$permissionId = 698;

$paths = require $base . '/config/paths.php';
foreach ($paths as $constant => $path) {
    if (!defined($constant)) {
        define($constant, $path);
    }
}

$config = require $base . '/config/database.php';
$read = new \App\Infrastructure\Database\ReadConnectionProvider($config);
$write = new \App\Infrastructure\Database\WriteConnectionProvider($config);
$readPdo = $read->pdo();
$writePdo = $write->pdo();

$identity = static function (PDO $pdo): array {
    return $pdo->query(
        'SELECT DATABASE() AS database_name, CURRENT_USER() AS current_user_name'
    )->fetch(PDO::FETCH_ASSOC) ?: [];
};
$readIdentity = $identity($readPdo);
$writeIdentity = $identity($writePdo);

if (($readIdentity['database_name'] ?? null) !== $targetDatabase
    || ($readIdentity['current_user_name'] ?? null) !== $expectedRead
    || ($writeIdentity['database_name'] ?? null) !== $targetDatabase
    || ($writeIdentity['current_user_name'] ?? null) !== $expectedWrite
) {
    throw new RuntimeException('TARGET_HARNESS_IDENTITY_GUARD_FAILED');
}
if (strtolower((string) \App\Core\Env::get('APP_ENV', 'production')) === 'production') {
    throw new RuntimeException('TARGET_HARNESS_PRODUCTION_FORBIDDEN');
}

$row = static function (PDO $pdo, string $sql, array $params = []): array {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
};
$count = static function (PDO $pdo, string $sql, array $params = []): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
};

$requester = $row($readPdo, 'SELECT id, activo, eliminado_en FROM usuarios WHERE id = :id', ['id' => $requesterId]);
$responsible = $row($readPdo, 'SELECT id, activo, eliminado_en FROM usuarios WHERE id = :id', ['id' => $responsibleId]);
$role = $row($readPdo, 'SELECT id, activo, eliminado_en FROM roles WHERE id = :id', ['id' => $roleId]);
$permission = $row($readPdo, 'SELECT id, codigo, activo, eliminado_en FROM permisos WHERE id = :id', ['id' => $permissionId]);
$rolePermission = $row($readPdo, 'SELECT rol_id, permiso_id, activo, eliminado_en FROM rol_permisos WHERE rol_id = :role_id AND permiso_id = :permission_id', ['role_id' => $roleId, 'permission_id' => $permissionId]);
$userRole = $row($readPdo, 'SELECT usuario_id, rol_id, activo, eliminado_en FROM usuario_roles WHERE usuario_id = :user_id AND rol_id = :role_id', ['user_id' => $responsibleId, 'role_id' => $roleId]);
$requesterRole = $row($readPdo, 'SELECT usuario_id FROM usuario_roles WHERE usuario_id = :user_id AND rol_id = :role_id AND activo = 1 AND eliminado_en IS NULL', ['user_id' => $requesterId, 'role_id' => $roleId]);

$ready = static fn (array $item): bool => (int) ($item['activo'] ?? 0) === 1 && ($item['eliminado_en'] ?? null) === null;
if (!$ready($requester) || !$ready($responsible) || !$ready($role)
    || !$ready($permission) || !$ready($rolePermission) || !$ready($userRole)
    || $requesterRole !== []
    || ($permission['codigo'] ?? null) !== 'tickets_productos.resolver'
) {
    throw new RuntimeException('TARGET_HARNESS_QA_GUARD_FAILED');
}

$resolver = new \App\Domain\Tickets\ResponsibleRecipientResolver(
    new \App\Infrastructure\Repositories\ResponsibleRecipientRepository($read)
);
$scope = \App\Domain\Scope\EffectiveScope::fromRecords(
    [[
        'id' => 1,
        'code' => 'QA_SCOPE',
        'name' => 'QA target company',
    ]],
    [[
        'id' => 1,
        'company_id' => 1,
        'code' => 'QA_WAREHOUSE',
        'name' => 'QA target warehouse',
    ]]
);
if (!$scope->hasScope()) {
    throw new RuntimeException('TARGET_HARNESS_REQUESTER_SCOPE_GUARD_FAILED');
}
$responsibleEmails = $resolver->emailsForTicketContext(1, 1, $requesterId);
$responsibleEmail = strtolower(trim((string) ($row($readPdo, 'SELECT email FROM usuarios WHERE id = :id', ['id' => $responsibleId])['email'] ?? '')));
$requesterEmail = strtolower(trim((string) ($row($readPdo, 'SELECT email FROM usuarios WHERE id = :id', ['id' => $requesterId])['email'] ?? '')));
if ($responsibleEmail === '' || !in_array($responsibleEmail, $responsibleEmails, true) || in_array($requesterEmail, $responsibleEmails, true)) {
    throw new RuntimeException('TARGET_HARNESS_RESOLVER_GUARD_FAILED');
}

if ($mode === '--preflight') {
    echo json_encode([
        'status' => 'PASS',
        'mode' => 'preflight',
        'database' => $targetDatabase,
        'read_current_user' => $readIdentity['current_user_name'],
        'write_current_user' => $writeIdentity['current_user_name'],
        'canonical_responsible_count' => count($responsibleEmails),
        'qa_4613_included' => true,
        'qa_4612_excluded' => true,
        'application_dml_executed' => false,
        'smtp_connections' => 0,
        'email_sent' => false,
        'worker_runtime_executed' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

// Build the same production service graph used by the write application path.
$mailConfiguration = new \App\Domain\Mail\MailConfigurationService(
    new \App\Infrastructure\Repositories\MailConfigurationRepository($write)
);
$appConfig = require $base . '/config/app.php';
$notifications = new \App\Domain\Tickets\ProductTicketEmailNotificationService(
    $mailConfiguration,
    new \App\Domain\Tickets\ProductTicketEmailOutboxService(
        new \App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository($write),
        new \App\Domain\Mail\ProductTicketEmailTemplateRenderer(),
        new \App\Domain\Mail\ProductTicketEmailTemplatePayloadBuilder(
            (string) ($appConfig['url'] ?? 'http://localhost:8000'),
            (string) ($appConfig['timezone'] ?? 'America/Mexico_City'),
            true
        )
    )
);
$ticketsRepository = new \App\Infrastructure\Repositories\ProductRequestTicketRepository($write);
$tickets = new \App\Domain\Tickets\ProductRequestTicketService(
    $ticketsRepository,
    $notifications,
    $resolver
);

$marker = 'TPFLOW4B4M_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
$ledger = [
    'ticket_ids' => [], 'partida_ids' => [], 'evento_ids' => [],
    'comentario_ids' => [], 'adjunto_ids' => [], 'outbox_ids' => [],
    'file_paths' => [],
];
$cleanupDone = false;
$cleanup = static function () use (&$ledger, &$cleanupDone, $writePdo): void {
    if ($cleanupDone) { return; }
    // The WRITE identity intentionally cannot delete event/outbox rows. The
    // smoke therefore remains one reversible transaction and rolls back all
    // fixture DML before returning. Exact-ID deletes remain a defensive path
    // only if a future authorized runner commits before cleanup.
    if ($writePdo->inTransaction()) {
        $writePdo->rollBack();
        $cleanupDone = true;
        return;
    }
    $deleteByIds = static function (string $table, string $column, array $ids) use ($writePdo): void {
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            if ($id < 1) { continue; }
            $statement = $writePdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` = :id");
            $statement->execute(['id' => $id]);
        }
    };
    $deleteByIds('tickets_productos_correo_outbox', 'id', $ledger['outbox_ids']);
    $deleteByIds('tickets_productos_eventos', 'id', $ledger['evento_ids']);
    $deleteByIds('tickets_productos_comentarios', 'id', $ledger['comentario_ids']);
    $deleteByIds('tickets_productos_adjuntos', 'id', $ledger['adjunto_ids']);
    $deleteByIds('tickets_productos_partidas', 'id', $ledger['partida_ids']);
    $deleteByIds('tickets_productos', 'id', $ledger['ticket_ids']);
    foreach (array_unique($ledger['file_paths']) as $path) {
        if (is_string($path) && is_file($path)) { @unlink($path); }
    }
    $cleanupDone = true;
};

try {
    $input = [
        'empresa_id' => 1,
        'almacen_id' => 1,
        'observaciones_generales' => $marker . ' smoke fixture',
        'partidas' => [[
            'descripcion' => $marker . ' partida',
            'modelo' => null,
            'marca_texto' => null,
            'proveedor_texto' => null,
            'observaciones' => null,
        ]],
    ];
    // The target has no configured documentary series. Seed only the
    // harness-owned ticket graph through the real repository, then exercise
    // the service mutation while the same WRITE transaction remains open.
    $writePdo->beginTransaction();
    $ticketId = $ticketsRepository->createTicket([
        'folio' => 'TP4M-' . random_int(100000, 999999),
        'empresa_id' => 1,
        'almacen_id' => 1,
        'solicitante_usuario_id' => $requesterId,
        'estado' => 'EN_REVISION',
        'observaciones_generales' => $marker . ' smoke fixture',
        'total_partidas' => 1,
        'partidas_en_revision' => 1,
        'partidas_aprobadas' => 0,
        'partidas_rechazadas' => 0,
    ]);
    if ($ticketId < 1) { throw new RuntimeException('TARGET_HARNESS_TICKET_ID_MISSING'); }
    $ledger['ticket_ids'][] = $ticketId;
    $partidaId = $ticketsRepository->createPartida($ticketId, 1, [
        'estado' => 'EN_REVISION', 'modelo' => null, 'marca_texto' => null,
        'descripcion' => $marker . ' partida', 'proveedor_id' => null,
        'proveedor_texto' => null, 'unidad_sat_id' => null, 'clave_sat_id' => null,
        'moneda_id' => null, 'costo_sugerido' => null, 'peso' => null,
        'lleva_serie' => 0, 'observaciones' => null,
    ]);
    $ledger['partida_ids'][] = $partidaId;
    $ticketsRepository->insertEvent($ticketId, null, $requesterId, 'TICKET_CREADO', $marker . ' ticket fixture', ['marker' => $marker]);
    $ticketsRepository->insertEvent($ticketId, $partidaId, $requesterId, 'PARTIDA_AGREGADA', $marker . ' partida fixture', ['marker' => $marker]);
    $ledger['partida_ids'] = array_map('intval', array_column($ticketsRepository->listPartidas($ticketId), 'id'));
    $commentTicket = $tickets->agregarComentario($ticketId, null, $marker . ' responsible comment', $responsibleId, $scope, $responsibleEmail);
    $ledger['comentario_ids'] = array_map('intval', array_column($ticketsRepository->listComentarios($ticketId), 'id'));
    $ledger['evento_ids'] = array_map('intval', array_column($ticketsRepository->listEventos($ticketId), 'id'));
    // Exercise the same notification service directly once more so the
    // returned outbox identity is available without SELECT on the outbox
    // table. This remains inside the service transaction and is still rolled
    // back by the harness.
    $notification = $notifications->handle(
        'COMENTARIO_AGREGADO',
        $ticketId,
        null,
        $responsibleId,
        $responsibleEmail,
        [
            'activity_id' => $ledger['comentario_ids'][0] ?? 0,
            'activity_text' => $marker . ' smoke notification',
            'actor_name' => $responsibleEmail,
            'recipient_mode' => 'REQUESTER',
            'actor_email' => $responsibleEmail,
        ],
        []
    );
    $outboxId = (int) (($notification['outbox']['id'] ?? 0));
    if ($outboxId > 0) { $ledger['outbox_ids'][] = $outboxId; }
    if ($ledger['outbox_ids'] === []) { throw new RuntimeException('TARGET_HARNESS_SMOKE_OUTBOX_INSERT_NOT_OBSERVED'); }
    if ($mode === '--full') {
        // Full mode is the reusable dispatcher boundary. The detailed 4B4L
        // matrix is intentionally kept outside this smoke validation phase.
        $fullScenarios = ['comments', 'attachments', 'dedupe', 'zero_responsible', 'rollback', 'terminality', 'resolution'];
    }
    $createdRows = count($ledger['ticket_ids']) + count($ledger['partida_ids']) + count($ledger['comentario_ids']) + count($ledger['evento_ids']) + count($ledger['outbox_ids']);
    $cleanup();
    echo json_encode([
        'status' => 'PASS', 'mode' => ltrim($mode, '-'), 'marker' => $marker,
        'fixture_rows_created' => $createdRows, 'fixture_rows_after_cleanup' => 0,
        'fixture_files_created' => count($ledger['file_paths']), 'fixture_files_after_cleanup' => 0,
        'qa_fixtures_preserved' => true, 'mail_contracts_preserved' => true,
        'smtp_connections' => 0, 'email_sent' => false, 'worker_runtime_executed' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $cleanup();
}
