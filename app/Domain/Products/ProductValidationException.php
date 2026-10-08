<?php

declare(strict_types=1);

namespace App\Domain\Products;

final class ProductValidationException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Product data is invalid.');
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
