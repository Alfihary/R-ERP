<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

final class ConfigurationValidationException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Configuration validation failed.');
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
