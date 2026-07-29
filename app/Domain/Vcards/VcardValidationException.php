<?php

declare(strict_types=1);

namespace App\Domain\Vcards;

final class VcardValidationException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'VCard data is invalid.',
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
