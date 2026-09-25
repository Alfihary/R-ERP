<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportTechnicalError
{
    /**
     * @param array<string, bool|float|int|string|null> $context
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $row = null,
        public readonly ?string $field = null,
        public readonly array $context = [],
    ) {
    }

    /**
     * @return array{
     *     code: string,
     *     message: string,
     *     row: int|null,
     *     field: string|null,
     *     context: array<string, bool|float|int|string|null>
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'row' => $this->row,
            'field' => $this->field,
            'context' => $this->context,
        ];
    }
}
