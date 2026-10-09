<?php

declare(strict_types=1);

namespace App\Domain\Users;

final class UserAdminValidationException extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('User administration validation failed.');
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
