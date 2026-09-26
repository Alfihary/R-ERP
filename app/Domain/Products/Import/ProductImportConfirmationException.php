<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportConfirmationException extends \RuntimeException
{
    /** @param array<string, mixed>|null $currentPreview */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly ?array $currentPreview = null,
    ) {
        parent::__construct($message);
    }
}
