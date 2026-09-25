<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportReadException extends \RuntimeException
{
    public function __construct(
        private readonly ProductImportTechnicalError $technicalError,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($technicalError->message, 0, $previous);
    }

    public function error(): ProductImportTechnicalError
    {
        return $this->technicalError;
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
    public function errorArray(): array
    {
        return $this->technicalError->toArray();
    }
}
