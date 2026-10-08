<?php

declare(strict_types=1);

namespace App\Domain\Scope;

use App\Core\Session;

final class ScopeContextService
{
    public const COMPANY_SESSION_KEY = 'active_company_id';
    public const WAREHOUSE_SESSION_KEY = 'active_warehouse_id';

    public function __construct(
        private readonly UserScopeService $userScope,
        private readonly Session $session
    ) {
    }

    public function resolveForUser(int $userId): ScopeContext
    {
        $scope = $this->userScope->resolveForUser($userId);

        if (!$scope->hasScope()) {
            $this->clear();

            return new ScopeContext($scope, null, null);
        }

        $companyId = $this->session->get(self::COMPANY_SESSION_KEY);
        $warehouseId = $this->session->get(self::WAREHOUSE_SESSION_KEY);

        if (is_int($companyId) && is_int($warehouseId)) {
            $pair = $this->findPair($scope, $companyId, $warehouseId);

            if ($pair !== null) {
                return new ScopeContext($scope, $pair['company'], $pair['warehouse']);
            }
        }

        $this->clear();

        if (count($scope->warehouses()) === 1) {
            $warehouse = $scope->warehouses()[0];
            $pair = $this->findPair(
                $scope,
                $warehouse['company_id'],
                $warehouse['id']
            );

            if ($pair !== null) {
                $this->store($pair['company']['id'], $pair['warehouse']['id']);

                return new ScopeContext($scope, $pair['company'], $pair['warehouse']);
            }
        }

        return new ScopeContext($scope, null, null);
    }

    public function changeForUser(
        int $userId,
        int $companyId,
        int $warehouseId
    ): bool {
        $context = $this->resolveForUser($userId);
        $pair = $this->findPair(
            $context->effectiveScope(),
            $companyId,
            $warehouseId
        );

        if ($pair === null) {
            return false;
        }

        $this->store($pair['company']['id'], $pair['warehouse']['id']);

        return true;
    }

    public function clear(): void
    {
        $this->session->remove(self::COMPANY_SESSION_KEY);
        $this->session->remove(self::WAREHOUSE_SESSION_KEY);
    }

    /**
     * @return array{
     *     company: array{id: int, code: string, name: string},
     *     warehouse: array{
     *         id: int,
     *         company_id: int,
     *         code: string,
     *         name: string
     *     }
     * }|null
     */
    private function findPair(
        EffectiveScope $scope,
        int $companyId,
        int $warehouseId
    ): ?array {
        if ($companyId < 1 || $warehouseId < 1) {
            return null;
        }

        $company = null;

        foreach ($scope->companies() as $candidate) {
            if ($candidate['id'] === $companyId) {
                $company = $candidate;
                break;
            }
        }

        if ($company === null) {
            return null;
        }

        foreach ($scope->warehouses() as $warehouse) {
            if ($warehouse['id'] === $warehouseId
                && $warehouse['company_id'] === $companyId
            ) {
                return [
                    'company' => $company,
                    'warehouse' => $warehouse,
                ];
            }
        }

        return null;
    }

    private function store(int $companyId, int $warehouseId): void
    {
        $this->session->put(self::COMPANY_SESSION_KEY, $companyId);
        $this->session->put(self::WAREHOUSE_SESSION_KEY, $warehouseId);
    }
}
