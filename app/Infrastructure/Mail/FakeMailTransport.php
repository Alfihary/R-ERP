<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Domain\Mail\MailTransport;
use RuntimeException;

final class FakeMailTransport implements MailTransport
{
    /** @var list<array{message:array<string,mixed>,account:array<string,mixed>}> */
    private array $deliveries = [];

    public function __construct(private string $scenario = 'success')
    {
    }

    public function requiresSecret(): bool
    {
        return false;
    }

    public function scenario(string $scenario): void
    {
        $this->scenario = $scenario;
    }

    public function send(array $message, array $account): array
    {
        $this->deliveries[] = [
            'message' => $message,
            'account' => $this->withoutSecrets($account),
        ];

        return match ($this->scenario) {
            'success' => ['sent' => true, 'error_type' => null],
            'auth_error' => ['sent' => false, 'error_type' => 'authentication'],
            'connection_error' => ['sent' => false, 'error_type' => 'connection'],
            'timeout' => ['sent' => false, 'error_type' => 'timeout'],
            'recipient_rejection' => ['sent' => false, 'error_type' => 'recipient'],
            'unexpected_error' => throw new RuntimeException(
                'Internal transport failure with password and C:\\private\\mail details.'
            ),
            default => throw new RuntimeException('Unsupported fake mail scenario.'),
        };
    }

    /** @return list<array{message:array<string,mixed>,account:array<string,mixed>}> */
    public function deliveries(): array
    {
        return $this->deliveries;
    }

    /** @param array<string, mixed> $account @return array<string, mixed> */
    private function withoutSecrets(array $account): array
    {
        unset($account['smtp_password']);

        return $account;
    }
}
