<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportStoredUpload
{
    public function __construct(
        public readonly string $previewId,
        public readonly string $absolutePath,
        public readonly string $relativePath,
        public readonly string $originalName,
        public readonly string $sha256,
    ) {
    }
}
