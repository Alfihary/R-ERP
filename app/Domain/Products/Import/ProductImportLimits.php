<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportLimits
{
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;
    public const MAX_DATA_ROWS = 1000;
    public const MAX_CELL_BYTES = 65_535;
    public const MAX_ZIP_ENTRIES = 2048;
    public const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;
    public const MAX_COMPRESSION_RATIO = 100.0;
    public const MAX_XML_ENTRY_BYTES = 2 * 1024 * 1024;

    private function __construct()
    {
    }
}
