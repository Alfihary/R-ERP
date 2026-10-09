<?php

declare(strict_types=1);

namespace App\Domain\Users;

final class UserAdminConflictException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
