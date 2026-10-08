<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use RuntimeException;

final class CredentialValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Credential validation failed.',
        private readonly int $statusCode = 422
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
