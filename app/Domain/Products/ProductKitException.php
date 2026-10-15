<?php

declare(strict_types=1);

namespace App\Domain\Products;

final class ProductKitException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(
        private readonly array $errors,
        ?string $detail = null
    ) {
        parent::__construct($detail ?? 'La composición del KIT no es válida.');
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
