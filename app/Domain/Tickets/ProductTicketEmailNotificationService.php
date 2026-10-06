<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

use App\Domain\Mail\MailConfigurationService;
use App\Domain\Mail\QaMailContext;
use App\Domain\Mail\QaMailValidationException;
use Throwable;

final class ProductTicketEmailNotificationService
{
    public function __construct(
        private readonly MailConfigurationService $configuration,
        private readonly ProductTicketEmailOutboxService $outbox
    ) {
    }

    /**
     * Runs only after the ticket transaction has committed. Notification
     * failures are reported as safe metadata and never roll back ticket data.
     *
     * @return array{notification_enqueued:bool,notification_reason:string,outbox:array<string,mixed>|null}
     */
    public function handle(
        string $event,
        int $ticketId,
        ?int $partidaId,
        int $actorUserId,
        ?string $actorEmail = null
    ): array {
        try {
            $configuration = $this->activeRule($event);
            if ($configuration['reason'] !== null) {
                return $this->skipped($configuration['reason']);
            }

            return $this->outbox->enqueueConfigured([
                'ticket_id' => $ticketId,
                'partida_id' => $partidaId,
                'evento' => $event,
                'creado_por_usuario_id' => $actorUserId,
                'responsable_email' => $actorEmail,
            ], $configuration['rule']);
        } catch (Throwable) {
            return $this->skipped('notification_configuration_error');
        }
    }

    /**
     * CLI-only QA entry point. The validated context replaces all operational
     * recipients while preserving the normal rule, renderer, dedupe and
     * repository pipeline. This method is not a web API.
     *
     * @return array{notification_enqueued:bool,notification_reason:string,outbox:array<string,mixed>|null}
     * @throws QaMailValidationException
     */
    public function handleQa(
        string $event,
        int $ticketId,
        ?int $partidaId,
        int $actorUserId,
        QaMailContext $context
    ): array {
        $context->assertEvent($event);

        try {
            $configuration = $this->activeRule($event);
        } catch (Throwable) {
            return $this->skipped('notification_configuration_error');
        }

        if ($configuration['reason'] !== null) {
            return $this->skipped($configuration['reason']);
        }

        return $this->outbox->enqueueConfiguredQa([
            'ticket_id' => $ticketId,
            'partida_id' => $partidaId,
            'evento' => $event,
            'creado_por_usuario_id' => $actorUserId,
        ], $configuration['rule'], $context);
    }

    /**
     * @return array{rule:array<string,mixed>,reason:string|null}
     */
    private function activeRule(string $event): array
    {
        $runtime = $this->configuration->runtimeEventConfiguration($event);
        $account = $runtime['account'];
        $rule = $runtime['rule'];

        if (!is_array($account) || (int) ($account['activo'] ?? 0) !== 1) {
            return ['rule' => [], 'reason' => 'no_active_mail_account'];
        }
        if (!is_array($rule)) {
            return ['rule' => [], 'reason' => 'event_rule_missing'];
        }
        if ((int) ($rule['activo'] ?? 0) !== 1) {
            return ['rule' => [], 'reason' => 'event_rule_inactive'];
        }
        if ((int) ($rule['mail_account_id'] ?? 0) !== (int) ($account['id'] ?? 0)) {
            return ['rule' => [], 'reason' => 'event_account_mismatch'];
        }

        return ['rule' => $rule, 'reason' => null];
    }

    /**
     * @return array{notification_enqueued:bool,notification_reason:string,outbox:null}
     */
    private function skipped(string $reason): array
    {
        return [
            'notification_enqueued' => false,
            'notification_reason' => $reason,
            'outbox' => null,
        ];
    }
}
