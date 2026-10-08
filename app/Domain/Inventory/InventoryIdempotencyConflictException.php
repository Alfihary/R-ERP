<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

final class InventoryIdempotencyConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('La clave de idempotencia ya fue usada con otra operación.');
    }
}
