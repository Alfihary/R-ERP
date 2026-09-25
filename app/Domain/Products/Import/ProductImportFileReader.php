<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

interface ProductImportFileReader
{
    public function read(string $path): ProductImportReadResult;
}
