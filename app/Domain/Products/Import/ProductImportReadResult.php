<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportReadResult
{
    /**
     * @param list<string> $headers
     * @param list<ProductImportRow> $rows
     * @param list<ProductImportTechnicalError> $warnings
     * @param array<string, bool|float|int|string|null> $metadata
     */
    public function __construct(
        public readonly string $format,
        public readonly array $headers,
        public readonly array $rows,
        public readonly array $warnings,
        public readonly array $metadata,
    ) {
    }

    public function totalRows(): int
    {
        return count($this->rows);
    }

    /**
     * @return array{
     *     format: string,
     *     headers: list<string>,
     *     rows: list<array{row_number: int, values: array<string, string>}>,
     *     total_rows: int,
     *     warnings: list<array{
     *         code: string,
     *         message: string,
     *         row: int|null,
     *         field: string|null,
     *         context: array<string, bool|float|int|string|null>
     *     }>,
     *     metadata: array<string, bool|float|int|string|null>
     * }
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'headers' => $this->headers,
            'rows' => array_map(
                static fn (ProductImportRow $row): array => $row->toArray(),
                $this->rows,
            ),
            'total_rows' => $this->totalRows(),
            'warnings' => array_map(
                static fn (ProductImportTechnicalError $warning): array => $warning->toArray(),
                $this->warnings,
            ),
            'metadata' => $this->metadata,
        ];
    }
}
