<?php

declare(strict_types=1);

namespace App\Domain\Products;

final class ProductVariantException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(private readonly array $errors, ?string $message = null)
    { parent::__construct($message ?? 'La variante no es válida.'); }
    /** @return array<string,string> */
    public function errors(): array { return $this->errors; }
}
