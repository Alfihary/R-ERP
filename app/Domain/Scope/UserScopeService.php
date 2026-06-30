<?php

declare(strict_types=1);

namespace App\Domain\Scope;

final class UserScopeService
{
    public function __construct(private readonly ScopeRepositoryInterface $scope)
    {
    }

    public function resolveForUser(int $userId): EffectiveScope
    {
        if ($userId < 1) {
            return EffectiveScope::empty();
        }

        return EffectiveScope::fromRecords(
            $this->scope->companiesForUser($userId),
            $this->scope->warehousesForUser($userId)
        );
    }
}
