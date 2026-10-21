<?php

declare(strict_types=1);

namespace App\Domain\Products\History;

use InvalidArgumentException;

/**
 * Normalized, read-only filters for a product history query.
 */
final readonly class ProductHistoryFilters
{
    public function __construct(
        public ?string $subresource = null,
        public ?string $action = null,
        public ?int $actorId = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
    ) {
        if ($this->actorId !== null && $this->actorId < 1) {
            throw new InvalidArgumentException('Actor ID must be positive.');
        }

        foreach (['dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo] as $name => $date) {
            if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException($name . ' must use YYYY-MM-DD.');
            }
        }
    }
}
