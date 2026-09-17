<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

use App\Domain\Mail\MailConfigurationService;
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
            $runtime = $this->configuration->runtimeEventConfiguration($event);
            $account = $runtime['account'];
            $rule = $runtime['rule'];

            if (!is_array($account) || (int) ($account['activo'] ?? 0) !== 1) {
                return $this->skipped('no_active_mail_account');
            }
            if (!is_array($rule)) {
                return $this->skipped('event_rule_missing');
            }
            if ((int) ($rule['activo'] ?? 0) !== 1) {
                return $this->skipped('event_rule_inactive');
            }
            if ((int) ($rule['mail_account_id'] ?? 0) !== (int) ($account['id'] ?? 0)) {
                return $this->skipped('event_account_mismatch');
            }

            return $this->outbox->enqueueConfigured([
                'ticket_id' => $ticketId,
                'partida_id' => $partidaId,
                'evento' => $event,
                'creado_por_usuario_id' => $actorUserId,
                'responsable_email' => $actorEmail,
            ], $rule);
        } catch (Throwable) {
            return $this->skipped('notification_configuration_error');
        }
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
