<?php

declare(strict_types=1);

namespace App\Domain\Scope;

final class EffectiveScope
{
    /**
     * @param list<array{id: int, code: string, name: string}> $companies
     * @param list<array{id: int, company_id: int, code: string, name: string}> $warehouses
     */
    private function __construct(
        private readonly array $companies,
        private readonly array $warehouses
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param list<array{id: int, code: string, name: string}> $companies
     * @param list<array{id: int, company_id: int, code: string, name: string}> $warehouses
     */
    public static function fromRecords(array $companies, array $warehouses): self
    {
        $allowedCompanyIds = [];

        foreach ($companies as $company) {
            $allowedCompanyIds[$company['id']] = true;
        }

        $warehouses = array_values(array_filter(
            $warehouses,
            static fn (array $warehouse): bool =>
                isset($allowedCompanyIds[$warehouse['company_id']])
        ));

        return new self($companies, $warehouses);
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    public function companies(): array
    {
        return $this->companies;
    }

    /**
     * @return list<array{id: int, company_id: int, code: string, name: string}>
     */
    public function warehouses(): array
    {
        return $this->warehouses;
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    public function defaultCompany(): ?array
    {
        return $this->companies[0] ?? null;
    }

    /**
     * @return array{id: int, company_id: int, code: string, name: string}|null
     */
    public function defaultWarehouse(): ?array
    {
        $company = $this->defaultCompany();

        if ($company === null) {
            return null;
        }

        foreach ($this->warehouses as $warehouse) {
            if ($warehouse['company_id'] === $company['id']) {
                return $warehouse;
            }
        }

        return null;
    }

    public function hasScope(): bool
    {
        return $this->defaultCompany() !== null
            && $this->defaultWarehouse() !== null;
    }

    /**
     * @return array{
     *     companies: list<array{id: int, code: string, name: string}>,
     *     warehouses: list<array{id: int, company_id: int, code: string, name: string}>,
     *     default_company: array{id: int, code: string, name: string}|null,
     *     default_warehouse: array{id: int, company_id: int, code: string, name: string}|null,
     *     has_companies: bool,
     *     has_warehouses: bool,
     *     has_scope: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'companies' => $this->companies(),
            'warehouses' => $this->warehouses(),
            'default_company' => $this->defaultCompany(),
            'default_warehouse' => $this->defaultWarehouse(),
            'has_companies' => $this->companies !== [],
            'has_warehouses' => $this->warehouses !== [],
            'has_scope' => $this->hasScope(),
        ];
    }
}
