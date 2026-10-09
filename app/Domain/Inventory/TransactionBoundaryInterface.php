<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

interface TransactionBoundaryInterface
{
    public function transactional(callable $operation): mixed;
}
