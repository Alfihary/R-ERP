<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Validated capability for CLI-only QA outbox creation.
 *
 * This context is not a web request DTO and must never carry SMTP secrets,
 * transport configuration, session state, rendered bodies or manual dedupe.
 */
final readonly class QaMailContext
{
    public const DATABASE = 'r_erp_db_core_0_test';
    public const EVENT = 'TICKET_CREADO';

    private const ENVIRONMENTS = ['local', 'development', 'test'];

    private function __construct(
        private string $recipient,
        private string $environment,
        private string $databaseName,
        private string $event
    ) {
    }

    /**
     * Builds a fail-closed context from values validated by CLI tooling.
     *
     * @throws QaMailValidationException
     */
    public static function fromCli(
        string $recipient,
        string $environment,
        string $requestedDatabase,
        string $confirmedDatabase,
        string $configuredDatabase,
        string $activeDatabase,
        string $event,
        string $confirmNoSend
    ): self {
        if (PHP_SAPI !== 'cli') {
            throw new QaMailValidationException('SMTP QA recipient override is CLI-only.');
        }

        $environment = strtolower(trim($environment));
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new QaMailValidationException('SMTP QA recipient override is disabled in this environment.');
        }

        $databases = [
            trim($requestedDatabase),
            trim($confirmedDatabase),
            trim($configuredDatabase),
            trim($activeDatabase),
        ];
        if (count(array_unique($databases)) !== 1 || $databases[0] !== self::DATABASE) {
            throw new QaMailValidationException('SMTP QA database confirmation failed.');
        }

        $event = strtoupper(trim($event));
        if ($event !== self::EVENT) {
            throw new QaMailValidationException('SMTP QA event is not allowed.');
        }

        if ($confirmNoSend !== 'YES') {
            throw new QaMailValidationException('SMTP QA no-send confirmation is required.');
        }

        return new self(
            self::normalizeRecipient($recipient),
            $environment,
            self::DATABASE,
            $event
        );
    }

    public function recipient(): string
    {
        return $this->recipient;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function event(): string
    {
        return $this->event;
    }

    /** @throws QaMailValidationException */
    public function assertEvent(string $event): void
    {
        if (strtoupper(trim($event)) !== $this->event) {
            throw new QaMailValidationException('SMTP QA event does not match the validated context.');
        }
    }

    /** @throws QaMailValidationException */
    private static function normalizeRecipient(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new QaMailValidationException('SMTP QA recipient is invalid.');
        }

        $recipient = strtolower(trim($value));
        if (
            $recipient === ''
            || strlen($recipient) > 190
            || str_contains($recipient, ',')
            || str_contains($recipient, ';')
            || preg_match('/\s/u', $recipient) === 1
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new QaMailValidationException('SMTP QA recipient is invalid.');
        }

        return $recipient;
    }
}
