<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportRow
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly array $values,
    ) {
    }

    /**
     * @return array{row_number: int, values: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'values' => $this->values,
        ];
    }
}
