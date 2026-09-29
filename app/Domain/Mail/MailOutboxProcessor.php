<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Infrastructure\Repositories\MailConfigurationRepository;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;
use Closure;
use RuntimeException;
use Throwable;

final class MailOutboxProcessor
{
    public const STALE_MINUTES = 15;
    public const MAX_BATCH_SIZE = 10;

    private ?Closure $secretResolver;

    /** @param callable(string):?string|null $secretResolver */
    public function __construct(
        private readonly ProductTicketEmailOutboxRepository $outbox,
        private readonly MailConfigurationRepository $configuration,
        private readonly MailTransport $transport,
        ?callable $secretResolver = null
    ) {
        $this->secretResolver = $secretResolver === null ? null : Closure::fromCallable($secretResolver);
    }

    /** @return list<array<string, mixed>> */
    public function dryRun(int $limit): array
    {
        $limit = $this->limit($limit);

        return array_map(static function (array $row): array {
            $email = strtolower(trim((string) ($row['destinatario_email'] ?? '')));
            $domain = str_contains($email, '@') ? substr($email, (int) strrpos($email, '@') + 1) : null;

            return [
                'id' => (int) $row['id'],
                'evento' => (string) $row['evento'],
                'status' => (string) $row['status'],
                'intentos' => (int) $row['intentos'],
                'max_intentos' => (int) $row['max_intentos'],
                'plantilla' => (string) $row['plantilla'],
                'recipient_domain' => $domain,
            ];
        }, $this->outbox->eligiblePreview($limit));
    }

    /** @return array{recovered_stale:int,processed:list<array<string,mixed>>} */
    public function process(int $limit): array
    {
        $limit = $this->limit($limit);
        $processed = [];
        $processedIds = [];

        for ($index = 0; $index < $limit; $index++) {
            $row = $this->outbox->claimNextEligible($processedIds);
            if ($row === null) {
                break;
            }

            $processedIds[] = (int) $row['id'];
            $processed[] = $this->deliverClaimed($row);
        }

        $recovered = $this->recoverStaleProcessing();

        return ['recovered_stale' => $recovered, 'processed' => $processed];
    }

    public function recoverStaleProcessing(): int
    {
        return $this->outbox->recoverStale(
            self::STALE_MINUTES,
            'Procesamiento anterior interrumpido.'
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function deliverClaimed(array $row): array
    {
        $id = (int) $row['id'];
        $claim = $this->claimIdentity($row);
        if ($claim === null) {
            return ['id' => $id, 'status' => 'STATE_CHANGED', 'transition' => 'state_changed'];
        }

        try {
            $account = $this->validatedAccount($this->configuration->primaryAccount());
            $message = $this->message($row);

            if ($this->transport->requiresSecret()) {
                $account['smtp_password'] = $this->resolveSecret((string) $account['smtp_secret_ref']);
            }

            $result = $this->transport->send($message, $account);
            unset($account['smtp_password']);

            if (($result['sent'] ?? false) === true) {
                $sent = $this->outbox->markClaimSent(
                    $id,
                    $claim['attempts'],
                    $claim['last_attempt_at']
                );

                return $this->transitionResponse($id, $sent);
            }

            return $this->fail(
                $id,
                $claim,
                $this->safeError((string) ($result['error_type'] ?? 'unexpected'))
            );
        } catch (Throwable $exception) {
            $safeMessage = $exception instanceof RuntimeException
                && $exception->getMessage() === 'Configuración SMTP incompleta.'
                ? $exception->getMessage()
                : 'No fue posible enviar el correo.';

            return $this->fail($id, $claim, $safeMessage);
        }
    }

    /** @param array{attempts:int,last_attempt_at:string} $claim @return array<string,mixed> */
    private function fail(int $id, array $claim, string $safeMessage): array
    {
        $failed = $this->outbox->markClaimError(
            $id,
            $safeMessage,
            $claim['attempts'],
            $claim['last_attempt_at']
        );
        $response = $this->transitionResponse($id, $failed);
        if (($failed['result'] ?? '') === 'success') {
            $response['error'] = $safeMessage;
        }

        return $response;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{attempts:int,last_attempt_at:string}|null
     */
    private function claimIdentity(array $row): ?array
    {
        $attempts = (int) ($row['intentos'] ?? 0);
        $lastAttemptAt = (string) ($row['ultimo_intento_at'] ?? '');
        if (
            $attempts < 1
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $lastAttemptAt) !== 1
        ) {
            return null;
        }

        return ['attempts' => $attempts, 'last_attempt_at' => $lastAttemptAt];
    }

    /**
     * @param array{result:string,row:array<string,mixed>|null} $transition
     * @return array<string,mixed>
     */
    private function transitionResponse(int $id, array $transition): array
    {
        $row = is_array($transition['row'] ?? null) ? $transition['row'] : null;
        $result = (string) ($transition['result'] ?? 'state_changed');

        return [
            'id' => $id,
            'status' => $row === null ? 'NO_ENCONTRADO' : (string) ($row['status'] ?? 'STATE_CHANGED'),
            'transition' => $result,
        ];
    }

    /** @param array<string, mixed>|null $account @return array<string, mixed> */
    private function validatedAccount(?array $account): array
    {
        if ($account === null || (int) ($account['activo'] ?? 0) !== 1) {
            throw new RuntimeException('Configuración SMTP incompleta.');
        }

        $from = $this->validEmail($account['from_email'] ?? null);
        $replyTo = trim((string) ($account['reply_to_email'] ?? ''));
        $host = trim((string) ($account['smtp_host'] ?? ''));
        $port = (int) ($account['smtp_port'] ?? 0);
        $encryption = strtolower(trim((string) ($account['smtp_encryption'] ?? '')));
        $username = trim((string) ($account['smtp_username'] ?? ''));
        $secretRef = trim((string) ($account['smtp_secret_ref'] ?? ''));

        if (
            $from === null
            || ($replyTo !== '' && $this->validEmail($replyTo) === null)
            || $host === ''
            || $port < 1
            || $port > 65535
            || !in_array($encryption, ['none', 'tls', 'ssl'], true)
            || $username === ''
            || preg_match('/^[A-Z][A-Z0-9_]*$/', $secretRef) !== 1
        ) {
            throw new RuntimeException('Configuración SMTP incompleta.');
        }

        $account['from_email'] = $from;
        $account['reply_to_email'] = $replyTo === '' ? null : strtolower($replyTo);
        $account['smtp_host'] = $host;
        $account['smtp_port'] = $port;
        $account['smtp_encryption'] = $encryption;
        $account['smtp_username'] = $username;
        $account['smtp_secret_ref'] = $secretRef;

        return $account;
    }

    /** @param array<string, mixed> $row @return array{to:list<string>,cc:list<string>,bcc:list<string>,subject:string,html:string,text:string} */
    private function message(array $row): array
    {
        $decoded = json_decode((string) ($row['cc_json'] ?? ''), true);
        $envelope = is_array($decoded) ? $decoded : [];
        $used = [];
        $to = $this->emails(array_merge(
            [(string) ($row['destinatario_email'] ?? '')],
            is_array($envelope['to'] ?? null) ? $envelope['to'] : []
        ), $used);
        $cc = $this->emails(is_array($envelope['cc'] ?? null) ? $envelope['cc'] : [], $used);
        $bcc = $this->emails(is_array($envelope['bcc'] ?? null) ? $envelope['bcc'] : [], $used);
        $subject = trim((string) ($row['subject'] ?? ''));
        $html = (string) ($row['html'] ?? '');
        $text = (string) ($row['text'] ?? '');

        if ($to === [] || $subject === '' || mb_strlen($subject, 'UTF-8') > 190 || ($html === '' && $text === '')) {
            throw new RuntimeException('Configuración SMTP incompleta.');
        }

        return compact('to', 'cc', 'bcc', 'subject', 'html', 'text');
    }

    /** @param array<mixed> $values @param array<string, true> $used @return list<string> */
    private function emails(array $values, array &$used): array
    {
        $emails = [];
        foreach ($values as $value) {
            $email = $this->validEmail($value);
            if ($email === null || isset($used[$email])) {
                continue;
            }
            $used[$email] = true;
            $emails[] = $email;
        }

        return $emails;
    }

    private function validEmail(mixed $value): ?string
    {
        $email = strtolower(trim((string) $value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    private function resolveSecret(string $reference): string
    {
        if ($this->secretResolver === null || preg_match('/^[A-Z][A-Z0-9_]*$/', $reference) !== 1) {
            throw new RuntimeException('Configuración SMTP incompleta.');
        }

        $secret = ($this->secretResolver)($reference);
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('Configuración SMTP incompleta.');
        }

        return $secret;
    }

    private function safeError(string $type): string
    {
        return match ($type) {
            'authentication' => 'El servidor de correo rechazó la autenticación.',
            'connection' => 'No fue posible conectar con el servidor de correo.',
            'recipient' => 'El servidor rechazó uno o más destinatarios.',
            'configuration' => 'Configuración SMTP incompleta.',
            'timeout' => 'El servidor de correo no respondió a tiempo.',
            default => 'No fue posible enviar el correo.',
        };
    }

    private function limit(int $limit): int
    {
        if ($limit < 1 || $limit > self::MAX_BATCH_SIZE) {
            throw new RuntimeException('Mail processing limit must be between 1 and 10.');
        }

        return $limit;
    }
}
