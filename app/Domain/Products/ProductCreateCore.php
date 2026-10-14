<?php

declare(strict_types=1);

namespace App\Domain\Products;

use App\Infrastructure\Repositories\ProductRepository;

/** Shared CREATE-ONLY persistence core; the caller owns the transaction. */
final class ProductCreateCore
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    /**
     * @param array<string, int|string|null> $product
     * @param list<int> $taxIds
     * @param list<string> $barcodes
     */
    public function create(
        array $product,
        array $taxIds,
        array $barcodes,
        int $actorId
    ): string {
        $productId = strtoupper((string) ($product['id_producto'] ?? ''));
        if ($productId === '') {
            throw new ProductValidationException(['id_producto' => 'El ID de producto es obligatorio.']);
        }

        $this->products->create($product, $actorId);
        $this->products->replaceTaxes($productId, $taxIds, $actorId);
        $this->products->replaceBarcodes($productId, $barcodes, $actorId);

        return $productId;
    }
}
