<?php

declare(strict_types=1);

namespace App\Domain\Scope;

final class ScopeContext
{
    /**
     * @param array{id: int, code: string, name: string}|null $activeCompany
     * @param array{id: int, company_id: int, code: string, name: string}|null $activeWarehouse
     */
    public function __construct(
        private readonly EffectiveScope $effectiveScope,
        private readonly ?array $activeCompany,
        private readonly ?array $activeWarehouse
    ) {
    }

    public function effectiveScope(): EffectiveScope
    {
        return $this->effectiveScope;
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    public function activeCompany(): ?array
    {
        return $this->activeCompany;
    }

    /**
     * @return array{id: int, company_id: int, code: string, name: string}|null
     */
    public function activeWarehouse(): ?array
    {
        return $this->activeWarehouse;
    }

    public function hasScope(): bool
    {
        return $this->effectiveScope->hasScope();
    }

    public function hasActiveContext(): bool
    {
        return $this->activeCompany !== null && $this->activeWarehouse !== null;
    }

    public function requiresSelection(): bool
    {
        return count($this->effectiveScope->warehouses()) > 1;
    }

    /**
     * @return array{
     *     companies: list<array{id: int, code: string, name: string}>,
     *     warehouses: list<array{id: int, company_id: int, code: string, name: string}>,
     *     active_company: array{id: int, code: string, name: string}|null,
     *     active_warehouse: array{
     *         id: int,
     *         company_id: int,
     *         code: string,
     *         name: string
     *     }|null,
     *     has_scope: bool,
     *     has_active_context: bool,
     *     requires_selection: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'companies' => $this->effectiveScope->companies(),
            'warehouses' => $this->effectiveScope->warehouses(),
            'active_company' => $this->activeCompany(),
            'active_warehouse' => $this->activeWarehouse(),
            'has_scope' => $this->hasScope(),
            'has_active_context' => $this->hasActiveContext(),
            'requires_selection' => $this->requiresSelection(),
        ];
    }
}
