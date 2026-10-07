<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Tickets\ProductTicketEmailNotificationService;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\SmtpQaFixtureIntentRepository;
use RuntimeException;

final class SmtpQaFixtureIntentService
{
    public const PHASE = 'CORREO-SMTP-QA-FIXTURE-INTENCION-1';
    public const MARKER = '[QA_FIXTURE:CORREO_SMTP]';

    public function __construct(
        private readonly ProductRequestTicketRepository $tickets,
        private readonly SmtpQaFixtureIntentRepository $qa,
        private readonly ProductTicketEmailNotificationService $notifications
    ) {
    }

    /** @return array<string, mixed> */
    public function audit(QaMailContext $context): array
    {
        $this->assertContext($context);
        $artifact = $this->qa->phaseArtifact(self::PHASE);
        $counts = $this->qa->counts(self::PHASE);

        return [
            'phase' => self::PHASE,
            'database' => $context->databaseName(),
            'audit_pass' => $artifact === null
                && $counts['eligible_count'] === 0
                && $counts['qa_fixture_count'] === 0,
            'artifact_exists' => $artifact !== null,
            'artifact' => $artifact === null ? null : $this->safeEvidence($artifact, $context),
            'tickets_count' => $counts['tickets_count'],
            'outbox_count' => $counts['outbox_count'],
            'eligible_count' => $counts['eligible_count'],
            'qa_fixture_count' => $counts['qa_fixture_count'],
            'protected' => $this->qa->protectedEvidence(),
            'processor_executed' => false,
            'smtp_connections' => 0,
            'emails_sent' => 0,
            'secret_resolutions' => 0,
        ];
    }

    /** @return array<string, mixed> */
    public function create(QaMailContext $context, string $confirmation): array
    {
        $this->assertContext($context);
        if ($confirmation !== 'YES') {
            throw new QaMailValidationException('SMTP QA fixture creation confirmation is required.');
        }

        return $this->qa->withCreationLock(function () use ($context): array {
            return $this->qa->transactional(function () use ($context): array {
                if ($this->qa->phaseArtifact(self::PHASE) !== null) {
                    throw new RuntimeException('SMTP QA phase artifact already exists; rerun refused.');
                }
                if ($this->qa->eligibleCount() !== 0) {
                    throw new RuntimeException('SMTP QA fixture requires eligible_count=0 before creation.');
                }

                $scope = $this->qa->activeScope();
                $folio = $this->qa->nextFolio();
                $ticketId = $this->tickets->createTicket([
                    'folio' => $folio,
                    'empresa_id' => $scope['company_id'],
                    'almacen_id' => $scope['warehouse_id'],
                    'solicitante_usuario_id' => $scope['user_id'],
                    'estado' => 'EN_REVISION',
                    'observaciones_generales' => self::MARKER,
                    'total_partidas' => 0,
                    'partidas_en_revision' => 0,
                    'partidas_aprobadas' => 0,
                    'partidas_rechazadas' => 0,
                ]);
                $this->tickets->insertEvent(
                    $ticketId,
                    null,
                    $scope['user_id'],
                    'TICKET_CREADO',
                    'Fixture QA persistente para una única intención SMTP controlada.',
                    ['qa_phase' => self::PHASE, 'qa_no_send' => true]
                );

                $relationsBefore = $this->qa->relationCounts($ticketId);
                if (
                    $relationsBefore['lines'] !== 0
                    || $relationsBefore['attachments'] !== 0
                    || $relationsBefore['comments'] !== 0
                    || $relationsBefore['events'] !== 1
                    || $relationsBefore['outbox'] !== 0
                ) {
                    throw new RuntimeException('SMTP QA ticket fixture relations are invalid.');
                }

                $notification = $this->notifications->handleQa(
                    'TICKET_CREADO',
                    $ticketId,
                    null,
                    $scope['user_id'],
                    $context
                );
                if (
                    ($notification['notification_enqueued'] ?? false) !== true
                    || ($notification['notification_reason'] ?? '') !== 'pending_created'
                    || !is_array($notification['outbox'] ?? null)
                ) {
                    throw new RuntimeException('SMTP QA outbox intention was not created.');
                }

                $artifact = $this->qa->phaseArtifact(self::PHASE);
                if (!is_array($artifact)) {
                    throw new RuntimeException('SMTP QA persisted artifact could not be verified.');
                }
                $evidence = $this->safeEvidence($artifact, $context);
                $relationsAfter = $this->qa->relationCounts($ticketId);
                if ($relationsAfter['outbox'] !== 1 || $this->qa->eligibleCount() !== 1) {
                    throw new RuntimeException('SMTP QA final eligibility invariant failed.');
                }

                return [
                    'phase' => self::PHASE,
                    'created' => true,
                    'artifact' => $evidence,
                    'relations' => $relationsAfter,
                    'eligible_count' => 1,
                    'processor_executed' => false,
                    'smtp_connections' => 0,
                    'emails_sent' => 0,
                    'secret_resolutions' => 0,
                ];
            });
        });
    }

    private function assertContext(QaMailContext $context): void
    {
        $context->assertEvent('TICKET_CREADO');
        if ($this->qa->currentDatabaseName() !== $context->databaseName()) {
            throw new QaMailValidationException('SMTP QA active database does not match the validated context.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function safeEvidence(array $row, QaMailContext $context): array
    {
        $envelope = json_decode((string) ($row['cc_json'] ?? ''), true);
        if (!is_array($envelope)) {
            throw new RuntimeException('SMTP QA persisted envelope is invalid.');
        }
        $to = is_array($envelope['to'] ?? null) ? array_values($envelope['to']) : [];
        $cc = is_array($envelope['cc'] ?? null) ? array_values($envelope['cc']) : [];
        $bcc = is_array($envelope['bcc'] ?? null) ? array_values($envelope['bcc']) : [];
        $ticketId = (int) ($row['ticket_id'] ?? 0);
        $html = (string) ($row['html'] ?? '');
        $text = (string) ($row['text'] ?? '');
        $subject = (string) ($row['subject'] ?? '');
        $expectedDedupe = 'ticket:' . $ticketId . ':partida:null:evento:TICKET_CREADO';

        if (
            $ticketId <= 0
            || $ticketId === 34
            || (string) ($row['folio'] ?? '') === 'QASMTP-000001'
            || (string) ($row['estado'] ?? '') !== 'EN_REVISION'
            || (string) ($row['observaciones_generales'] ?? '') !== self::MARKER
            || (int) ($row['total_partidas'] ?? -1) !== 0
            || ($row['cancelado_at'] ?? null) !== null
            || (string) ($row['status'] ?? '') !== 'PENDIENTE'
            || (int) ($row['intentos'] ?? -1) !== 0
            || ($row['ultimo_intento_at'] ?? null) !== null
            || ($row['enviado_at'] ?? null) !== null
            || (string) ($row['evento'] ?? '') !== 'TICKET_CREADO'
            || (string) ($row['plantilla'] ?? '') !== 'ticket_created'
            || $to !== [$context->recipient()]
            || $cc !== []
            || $bcc !== []
            || (string) ($row['destinatario_email'] ?? '') !== $context->recipient()
            || (string) ($row['dedupe_key'] ?? '') !== $expectedDedupe
            || trim($subject) === ''
            || preg_match('/[\r\n]/', $subject) === 1
            || trim($html) === ''
            || trim($text) === ''
            || !str_contains($html, '/tickets/productos/' . $ticketId)
        ) {
            throw new RuntimeException('SMTP QA persisted artifact contract validation failed.');
        }

        [$local, $domain] = array_pad(explode('@', $context->recipient(), 2), 2, '');

        return [
            'ticket_id' => $ticketId,
            'folio' => (string) $row['folio'],
            'ticket_status' => (string) $row['estado'],
            'outbox_id' => (int) $row['outbox_id'],
            'outbox_status' => (string) $row['status'],
            'attempts' => (int) $row['intentos'],
            'template' => (string) $row['plantilla'],
            'dedupe_key' => (string) $row['dedupe_key'],
            'recipient_masked' => mb_substr($local, 0, 1, 'UTF-8') . '***@' . $domain,
            'recipient_domain' => $domain,
            'to_count' => count($to),
            'cc_count' => count($cc),
            'bcc_count' => count($bcc),
            'subject_bytes' => strlen($subject),
            'html_bytes' => strlen($html),
            'text_bytes' => strlen($text),
            'subject_sha256' => hash('sha256', $subject),
            'html_sha256' => hash('sha256', $html),
            'text_sha256' => hash('sha256', $text),
            'cta_present' => true,
        ];
    }
}
