<?php

declare(strict_types=1);

namespace App\Domain\Scope;

interface ScopeRepositoryInterface
{
    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    public function companiesForUser(int $userId): array;

    /**
     * @return list<array{id: int, company_id: int, code: string, name: string}>
     */
    public function warehousesForUser(int $userId): array;
}
