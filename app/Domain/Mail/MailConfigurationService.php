<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Infrastructure\Repositories\MailConfigurationRepository;
use InvalidArgumentException;

final class MailConfigurationService
{
    public const PERMISSION = 'configuracion.correo.administrar';

    public const EVENTS = [
        'TICKET_CREADO',
        'PARTIDA_APROBADA',
        'PARTIDA_RECHAZADA',
        'TICKET_RESUELTO_TOTAL',
        'TICKET_RESUELTO_PARCIAL',
        'TICKET_CANCELADO',
    ];

    private const ENCRYPTIONS = ['none', 'tls', 'ssl'];

    public function __construct(private readonly MailConfigurationRepository $repository)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $account = $this->repository->primaryAccount();
        $rules = $this->rulesByEvent();

        return [
            'account' => $account,
            'accounts' => $this->repository->accounts(),
            'events' => self::EVENTS,
            'rules' => $rules,
            'status' => [
                'active_account' => is_array($account) && (int) ($account['activo'] ?? 0) === 1,
                'complete_configuration' => is_array($account)
                    && (string) ($account['from_email'] ?? '') !== ''
                    && (string) ($account['smtp_host'] ?? '') !== ''
                    && (int) ($account['smtp_port'] ?? 0) > 0
                    && (string) ($account['smtp_secret_ref'] ?? '') !== '',
                'secret_configured' => is_array($account)
                    && (string) ($account['smtp_secret_ref'] ?? '') !== '',
            ],
        ];
    }

    /**
     * Runtime-safe configuration for an event. This method never resolves
     * smtp_secret_ref and never reads environment secrets.
     *
     * @return array{account:array<string,mixed>|null,rule:array<string,mixed>|null}
     */
    public function runtimeEventConfiguration(string $event): array
    {
        $event = strtoupper(trim($event));

        if (!in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException('Evento de correo no soportado.');
        }

        $overview = $this->overview();
        $rule = $overview['rules'][$event] ?? null;

        return [
            'account' => is_array($overview['account']) ? $overview['account'] : null,
            'rule' => is_array($rule) ? $rule : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveAccount(array $input): int
    {
        $this->rejectPlainSecrets($input);

        return $this->repository->savePrimaryAccount([
            'nombre' => $this->text($input['nombre'] ?? '', 3, 120, 'nombre'),
            'from_email' => $this->email($input['from_email'] ?? '', 'from_email'),
            'from_name' => $this->text($input['from_name'] ?? '', 2, 120, 'from_name'),
            'reply_to_email' => $this->nullableEmail($input['reply_to_email'] ?? '', 'reply_to_email'),
            'smtp_host' => $this->host($input['smtp_host'] ?? ''),
            'smtp_port' => $this->port($input['smtp_port'] ?? ''),
            'smtp_encryption' => $this->encryption($input['smtp_encryption'] ?? ''),
            'smtp_username' => $this->text($input['smtp_username'] ?? '', 1, 190, 'smtp_username'),
            'smtp_secret_ref' => $this->secretRef($input['smtp_secret_ref'] ?? ''),
            'activo' => ($input['activo'] ?? null) === '1' ? 1 : 0,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveRules(array $input): void
    {
        $account = $this->repository->primaryAccount();

        if ($account === null) {
            throw new InvalidArgumentException('Configura primero una cuenta emisora.');
        }

        $rulesInput = is_array($input['rules'] ?? null) ? $input['rules'] : [];
        $this->repository->transaction(function () use ($rulesInput, $account): void {
            foreach (self::EVENTS as $event) {
                $ruleInput = is_array($rulesInput[$event] ?? null) ? $rulesInput[$event] : [];
                $recipientLists = $this->recipientLists($ruleInput);

                $this->repository->saveRule($event, [
                    'mail_account_id' => (int) $account['id'],
                    'enviar_solicitante' => ($ruleInput['enviar_solicitante'] ?? null) === '1' ? 1 : 0,
                    'enviar_responsables' => ($ruleInput['enviar_responsables'] ?? null) === '1' ? 1 : 0,
                    'to_json' => $this->jsonList($recipientLists['to']),
                    'cc_json' => $this->jsonList($recipientLists['cc']),
                    'bcc_json' => $this->jsonList($recipientLists['bcc']),
                    'activo' => ($ruleInput['activo'] ?? null) === '1' ? 1 : 0,
                ]);
            }
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rulesByEvent(): array
    {
        $rules = [];

        foreach ($this->repository->rules() as $rule) {
            $rules[(string) $rule['evento']] = $rule;
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>}
     */
    private function recipientLists(array $input): array
    {
        $to = $this->emailList($input['to'] ?? '');
        $cc = $this->emailList($input['cc'] ?? '');
        $bcc = $this->emailList($input['bcc'] ?? '');
        $seen = [];

        foreach (['to' => $to, 'cc' => $cc, 'bcc' => $bcc] as $scope => $emails) {
            foreach ($emails as $email) {
                if (isset($seen[$email])) {
                    throw new InvalidArgumentException(
                        'El correo ' . $email . ' está duplicado entre TO, CC o BCC.'
                    );
                }

                $seen[$email] = $scope;
            }
        }

        return ['to' => $to, 'cc' => $cc, 'bcc' => $bcc];
    }

    /**
     * @return list<string>
     */
    private function emailList(mixed $value): array
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', strtolower($raw)) ?: [];
        $emails = [];

        foreach ($parts as $part) {
            $email = $this->email($part, 'destinatarios');
            $emails[$email] = $email;
        }

        return array_values($emails);
    }

    /**
     * @param list<string> $emails
     */
    private function jsonList(array $emails): ?string
    {
        if ($emails === []) {
            return null;
        }

        return json_encode($emails, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function email(mixed $value, string $field): string
    {
        $email = strtolower(trim((string) $value));

        if (
            $email === ''
            || strlen($email) > 190
            || str_contains($email, ' ')
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException('El campo ' . $field . ' debe ser un email válido.');
        }

        return $email;
    }

    private function nullableEmail(mixed $value, string $field): ?string
    {
        $email = trim((string) $value);

        return $email === '' ? null : $this->email($email, $field);
    }

    private function port(mixed $value): int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if ($port === false) {
            throw new InvalidArgumentException('El puerto SMTP debe estar entre 1 y 65535.');
        }

        return $port;
    }

    private function encryption(mixed $value): string
    {
        $encryption = strtolower(trim((string) $value));

        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new InvalidArgumentException('Selecciona un cifrado SMTP permitido.');
        }

        return $encryption;
    }

    private function host(mixed $value): string
    {
        $host = strtolower(trim((string) $value));

        if (
            strlen($host) < 3
            || strlen($host) > 190
            || preg_match('/^[a-z0-9.-]+$/', $host) !== 1
            || str_contains($host, '..')
        ) {
            throw new InvalidArgumentException('El host SMTP debe ser un host válido.');
        }

        return $host;
    }

    private function secretRef(mixed $value): string
    {
        $ref = strtoupper(trim((string) $value));

        if (preg_match('/^[A-Z0-9_]{3,120}$/', $ref) !== 1) {
            throw new InvalidArgumentException('La referencia de secreto debe ser una variable segura.');
        }

        return $ref;
    }

    private function text(mixed $value, int $min, int $max, string $field): string
    {
        $text = trim((string) $value);

        if ($text === '' || mb_strlen($text) < $min || mb_strlen($text) > $max) {
            throw new InvalidArgumentException('El campo ' . $field . ' no tiene longitud válida.');
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function rejectPlainSecrets(array $input): void
    {
        foreach (['smtp_password', 'password', 'smtp_secret', 'token', 'dsn'] as $key) {
            if (array_key_exists($key, $input) && trim((string) $input[$key]) !== '') {
                throw new InvalidArgumentException('Los secretos SMTP no se capturan ni se guardan en la aplicación.');
            }
        }
    }
}
