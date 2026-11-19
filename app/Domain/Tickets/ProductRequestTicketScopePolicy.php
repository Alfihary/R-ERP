<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

use App\Domain\Scope\EffectiveScope;

final class ProductRequestTicketScopePolicy
{
    /**
     * @return list<array{empresa_id:int, almacen_id:int}>
     */
    public static function pairs(EffectiveScope $scope): array
    {
        $companyIds = [];

        foreach ($scope->companies() as $company) {
            $companyIds[(int) $company['id']] = true;
        }

        $pairs = [];
        foreach ($scope->warehouses() as $warehouse) {
            $companyId = (int) $warehouse['company_id'];
            $warehouseId = (int) $warehouse['id'];

            if ($companyId < 1 || $warehouseId < 1 || !isset($companyIds[$companyId])) {
                continue;
            }

            $pairs[] = [
                'empresa_id' => $companyId,
                'almacen_id' => $warehouseId,
            ];
        }

        return $pairs;
    }

    /**
     * @param list<array{empresa_id:int, almacen_id:int}> $pairs
     */
    public static function allowsPair(
        EffectiveScope $scope,
        int $companyId,
        int $warehouseId
    ): bool {
        foreach (self::pairs($scope) as $pair) {
            if ($pair['empresa_id'] === $companyId && $pair['almacen_id'] === $warehouseId) {
                return true;
            }
        }

        return false;
    }

}
