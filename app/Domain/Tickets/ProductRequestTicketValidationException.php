<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

final class ProductRequestTicketValidationException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        private readonly array $errors,
        ?\Throwable $previous = null
    )
    {
        parent::__construct(
            'La solicitud de alta de producto no es válida.',
            0,
            $previous
        );
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
