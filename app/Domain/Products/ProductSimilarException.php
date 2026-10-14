<?php

declare(strict_types=1);

namespace App\Domain\Products;

final class ProductSimilarException extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(
        private readonly array $errors,
        string $message = 'La relación de productos similares no es válida.'
    ) {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
