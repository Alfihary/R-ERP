<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

interface ProductImportBusinessLookup
{
    /**
     * @param array<string, list<string>> $codesByField
     * @return array<string, array<string, array{id: int, code: string}>>
     */
    public function resolveCatalogs(array $codesByField): array;

    /** @param list<string> $productIds @return array<string, true> */
    public function existingProductIds(array $productIds): array;

    /** @param list<string> $skus @return array<string, true> */
    public function conflictingSkus(array $skus): array;

    /** @param list<string> $barcodes @return array<string, true> */
    public function conflictingBarcodes(array $barcodes): array;
}
