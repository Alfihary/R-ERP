<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($base): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $path = $base . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Domain\Mail\ProductTicketEmailTemplatePayloadBuilder;
use App\Domain\Mail\ProductTicketEmailTemplateRenderer;
use App\Domain\Tickets\ProductTicketEmailOutboxRepositoryContract;
use App\Domain\Tickets\ProductTicketEmailOutboxService;
use App\Domain\Tickets\ResponsibleRecipientRepositoryInterface;
use App\Domain\Tickets\ResponsibleRecipientResolver;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$repository = new class implements ResponsibleRecipientRepositoryInterface {
    public function findEmailsByScopeAndPermission(int $empresaId, int $almacenId, int $actorUserId): array
    {
        return [' Z@example.invalid ', 'z@example.invalid', 'a@example.invalid', ''];
    }
};

$resolver = new ResponsibleRecipientResolver($repository);
$assert(
    $resolver->emailsForTicketContext(1, 1, 4612) === ['a@example.invalid', 'z@example.invalid'],
    'RESPONSIBLE_NORMALIZATION_DEDUPE_FAILED'
);

$source = (string) file_get_contents($base . '/app/Infrastructure/Repositories/ResponsibleRecipientRepository.php');
foreach ([
    'usuarios u',
    'usuario_empresas ue',
    'usuario_almacenes ua',
    'usuario_roles ur',
    'roles r',
    'rol_permisos rp',
    'permisos p',
    'u.activo = 1',
    'u.eliminado_en IS NULL',
    'ue.activo = 1',
    'ue.eliminado_en IS NULL',
    'ua.activo = 1',
    'ua.eliminado_en IS NULL',
    'ur.activo = 1',
    'ur.eliminado_en IS NULL',
    'r.activo = 1',
    'r.eliminado_en IS NULL',
    'rp.activo = 1',
    'rp.eliminado_en IS NULL',
    'p.activo = 1',
    'p.eliminado_en IS NULL',
    "p.codigo = 'tickets_productos.resolver'",
    'u.id <> :actor_user_id',
    'DISTINCT',
] as $needle) {
    $assert(str_contains($source, $needle), 'LOOKUP_SECURITY_CONTRACT_MISSING_' . $needle);
}
$assert(!str_contains($source, 'ADMIN'), 'ADMIN_BYPASS_FORBIDDEN');
$assert(!preg_match('/\b4612\b|\b4613\b|@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $source), 'HARDCODED_QA_RECIPIENT_FORBIDDEN');

$outboxRepository = new class implements ProductTicketEmailOutboxRepositoryContract {
    public function findTicketContext(int $ticketId): ?array { return null; }
    public function currentDatabaseName(): string { return 'db-free'; }
    public function qaFixtureRelationCounts(int $ticketId): array { return ['lines' => 0, 'attachments' => 0]; }
    public function findLineContext(int $ticketId, int $partidaId): ?array { return null; }
    public function findLinesContext(int $ticketId): array { return []; }
    public function countAttachments(int $ticketId): int { return 0; }
    public function findByDedupeKey(string $dedupeKey): ?array { return null; }
    public function insertPending(array $data): array { throw new LogicException('not used'); }
    public function markSent(int $id, ?int $expectedAttempts = null, ?string $expectedLastAttemptAt = null): array { throw new LogicException('not used'); }
    public function markError(int $id, string $safeMessage, ?int $expectedAttempts = null, ?string $expectedLastAttemptAt = null): array { throw new LogicException('not used'); }
};

$outbox = new ProductTicketEmailOutboxService(
    $outboxRepository,
    new ProductTicketEmailTemplateRenderer(),
    new ProductTicketEmailTemplatePayloadBuilder('http://localhost', 'UTC', true)
);
$resolve = new ReflectionMethod($outbox, 'resolveRecipients');
$resolve->setAccessible(true);
$rule = [
    'enviar_solicitante' => 1,
    'enviar_responsables' => 1,
    'to_json' => null,
    'cc_json' => null,
    'bcc_json' => null,
];
$ticket = ['solicitante_email' => 'requester@example.invalid'];

$responsibleDirection = $resolve->invoke(
    $outbox,
    $ticket,
    $rule,
    ['a@example.invalid', 'z@example.invalid', 'actor@example.invalid'],
    'RESPONSIBLES',
    'actor@example.invalid'
);
$assert($responsibleDirection['to'] === ['a@example.invalid'], 'RESPONSIBLES_DIRECTION_FAILED');
$assert($responsibleDirection['cc'] === ['z@example.invalid'], 'MULTIPLE_RESPONSIBLES_ENVELOPE_FAILED');

$requesterDirection = $resolve->invoke(
    $outbox,
    $ticket,
    $rule,
    ['responsible@example.invalid'],
    'REQUESTER',
    'responsible@example.invalid'
);
$assert($requesterDirection['to'] === ['requester@example.invalid'], 'REQUESTER_DIRECTION_FAILED');
$assert(!in_array('responsible@example.invalid', $requesterDirection['cc'], true), 'ACTOR_EXCLUSION_FAILED');

$zero = $resolve->invoke(
    $outbox,
    ['solicitante_email' => 'same@example.invalid'],
    $rule,
    ['same@example.invalid'],
    'RESPONSIBLES',
    'same@example.invalid'
);
$assert($zero === ['to' => [], 'cc' => [], 'bcc' => []], 'ZERO_RESPONSIBLE_FAILED');

$serviceSource = (string) file_get_contents($base . '/app/Domain/Tickets/ProductRequestTicketService.php');
$notificationSource = (string) file_get_contents($base . '/app/Domain/Tickets/ProductTicketEmailNotificationService.php');
$bootstrapSource = (string) file_get_contents($base . '/bootstrap/app.php');
$assert(str_contains($serviceSource, 'ResponsibleRecipientResolverInterface'), 'SERVICE_RESOLVER_CONTRACT_MISSING');
$assert(str_contains($serviceSource, '$responsibleEmails'), 'SERVICE_RESPONSIBLE_EMAILS_MISSING');
$assert(str_contains($notificationSource, 'array $responsibleEmails'), 'NOTIFICATION_RESPONSIBLE_ARRAY_MISSING');
$assert(str_contains($bootstrapSource, 'ResponsibleRecipientRepository($readConnectionProvider)'), 'READ_LOOKUP_WIRING_MISSING');
$assert(!preg_match('/\b4612\b|\b4613\b|@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $serviceSource . $notificationSource . $bootstrapSource), 'HARDCODED_RECIPIENT_FORBIDDEN');

echo "TP_FLOW_4B4G_CANONICAL_RESPONSIBLE_RECIPIENT_DB_FREE=PASS\n";
