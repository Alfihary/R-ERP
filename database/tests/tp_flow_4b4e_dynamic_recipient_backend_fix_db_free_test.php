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

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$repository = new class implements ProductTicketEmailOutboxRepositoryContract {
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

$service = new ProductTicketEmailOutboxService(
    $repository,
    new ProductTicketEmailTemplateRenderer(),
    new ProductTicketEmailTemplatePayloadBuilder('http://localhost', 'UTC', true)
);

$method = new ReflectionMethod($service, 'resolveRecipients');
$method->setAccessible(true);
$rule = [
    'enviar_solicitante' => 1,
    'enviar_responsables' => 1,
    'to_json' => null,
    'cc_json' => null,
    'bcc_json' => null,
];
$ticket = ['solicitante_email' => 'requester@example.invalid'];

$responsibles = $method->invoke($service, $ticket, $rule, 'responsible@example.invalid', 'RESPONSIBLES', 'requester@example.invalid');
$assert($responsibles['to'] === ['responsible@example.invalid'], 'RESPONSIBLES_MUST_SELECT_RESPONSIBLE');
$assert(!in_array('requester@example.invalid', array_merge($responsibles['to'], $responsibles['cc'], $responsibles['bcc']), true), 'REQUESTER_ACTOR_MUST_BE_EXCLUDED');

$requester = $method->invoke($service, $ticket, $rule, 'responsible@example.invalid', 'REQUESTER', 'responsible@example.invalid');
$assert($requester['to'] === ['requester@example.invalid'], 'REQUESTER_MODE_MUST_SELECT_REQUESTER');
$assert(!in_array('responsible@example.invalid', array_merge($requester['to'], $requester['cc'], $requester['bcc']), true), 'RESPONSIBLE_ACTOR_MUST_BE_EXCLUDED');

$deduped = $method->invoke($service, $ticket, [
    ...$rule,
    'cc_json' => json_encode(['requester@example.invalid', 'responsible@example.invalid'], JSON_THROW_ON_ERROR),
], 'responsible@example.invalid', 'REQUESTER', 'nobody@example.invalid');
$all = array_merge($deduped['to'], $deduped['cc'], $deduped['bcc']);
$assert(count($all) === count(array_unique($all)), 'RECIPIENT_DEDUPE_MUST_BE_PRESERVED');

$zero = $method->invoke($service, [
    'solicitante_email' => 'same@example.invalid',
], [
    'enviar_solicitante' => 1,
    'enviar_responsables' => 0,
    'to_json' => null,
    'cc_json' => null,
    'bcc_json' => null,
], null, 'REQUESTER', 'same@example.invalid');
$assert($zero === ['to' => [], 'cc' => [], 'bcc' => []], 'ZERO_RECIPIENT_MUST_REMAIN_EMPTY');

echo "TP_FLOW_4B4E_DYNAMIC_RECIPIENT_BACKEND_FIX_DB_FREE=PASS\n";
