<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportValidationError
{
    /** @param array<string, bool|float|int|string|null> $context */
    public function __construct(
        public readonly ?int $row,
        public readonly ?string $field,
        public readonly string $code,
        public readonly string $message,
        public readonly array $context = [],
    ) {
    }

    /** @return array{row: int|null, field: string|null, code: string, message: string, context: array<string, bool|float|int|string|null>} */
    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'field' => $this->field,
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
