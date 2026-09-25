<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportValidatedRow
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, array{id: int, code: string}|list<array{id: int, code: string}>> $resolvedReferences
     * @param list<ProductImportValidationError> $errors
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly array $data,
        public readonly array $resolvedReferences,
        public readonly array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'status' => $this->isValid() ? 'valid' : 'invalid',
            'data' => $this->data,
            'resolved_references' => $this->resolvedReferences,
            'errors' => array_map(
                static fn (ProductImportValidationError $error): array => $error->toArray(),
                $this->errors,
            ),
        ];
    }
}
