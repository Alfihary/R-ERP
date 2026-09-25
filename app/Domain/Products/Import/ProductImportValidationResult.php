<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportValidationResult
{
    /**
     * @param list<ProductImportValidatedRow> $rows
     * @param list<ProductImportValidationError> $errors
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $errors,
        public readonly int $sourceRowCount,
    ) {
    }

    public function validRows(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (ProductImportValidatedRow $row): bool => $row->isValid(),
        ));
    }

    public function invalidRows(): int
    {
        return $this->sourceRowCount - $this->validRows();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total_rows' => $this->sourceRowCount,
            'valid_rows' => $this->validRows(),
            'invalid_rows' => $this->invalidRows(),
            'rows' => array_map(
                static fn (ProductImportValidatedRow $row): array => $row->toArray(),
                $this->rows,
            ),
            'errors' => array_map(
                static fn (ProductImportValidationError $error): array => $error->toArray(),
                $this->errors,
            ),
        ];
    }
}
