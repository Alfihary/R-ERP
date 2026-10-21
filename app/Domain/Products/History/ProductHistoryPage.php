<?php

declare(strict_types=1);

namespace App\Domain\Products\History;

/**
 * Final page read model consumed by the service and view in 2B/2D.
 */
final readonly class ProductHistoryPage
{
    /**
     * @param list<ProductHistoryItem> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pageSize,
        public int $pages,
        public ProductHistoryFilters $filters,
    ) {
    }
}
