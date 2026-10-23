<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Products\Import\ProductImportBusinessLookup;
use App\Infrastructure\Database\ConnectionProvider;

final class LazyProductImportBusinessLookup implements ProductImportBusinessLookup
{
    private ?PdoProductImportBusinessLookup $delegate = null;

    public function __construct(
        private readonly ConnectionProvider $connection
    ) {
    }

    public function resolveCatalogs(array $codesByField): array
    {
        return $this->delegate()->resolveCatalogs($codesByField);
    }

    public function existingProductIds(array $productIds): array
    {
        return $this->delegate()->existingProductIds($productIds);
    }

    public function conflictingSkus(array $skus): array
    {
        return $this->delegate()->conflictingSkus($skus);
    }

    public function conflictingBarcodes(array $barcodes): array
    {
        return $this->delegate()->conflictingBarcodes($barcodes);
    }

    private function delegate(): PdoProductImportBusinessLookup
    {
        if ($this->delegate === null) {
            $this->delegate = new PdoProductImportBusinessLookup(
                $this->connection->pdo()
            );
        }

        return $this->delegate;
    }
}
