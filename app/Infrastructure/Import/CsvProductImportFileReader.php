<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Domain\Products\Import\ProductImportFileReader;
use App\Domain\Products\Import\ProductImportLimits;
use App\Domain\Products\Import\ProductImportReadException;
use App\Domain\Products\Import\ProductImportReadResult;
use App\Domain\Products\Import\ProductImportRow;
use App\Domain\Products\Import\ProductImportTechnicalError;

final class CsvProductImportFileReader implements ProductImportFileReader
{
    public function __construct(private readonly ProductImportNormalizer $normalizer = new ProductImportNormalizer())
    {
    }

    public function read(string $path): ProductImportReadResult
    {
        $handle = @fopen($path, 'rb');

        if (!is_resource($handle)) {
            throw $this->failure('csv_unreadable', 'No fue posible abrir el archivo CSV.');
        }

        $headers = null;
        $headerRow = null;
        $rows = [];
        $physicalLine = 0;

        try {
            while (true) {
                $record = $this->nextRecord($handle, $physicalLine);

                if ($record === null) {
                    break;
                }

                [$rowNumber, $rawValues] = $record;
                $values = $this->normalizer->values($this->strings($rawValues), $rowNumber);

                if ($this->normalizer->isEmptyRow($values)) {
                    continue;
                }

                if ($headers === null) {
                    $headers = $this->normalizer->headers($values, $rowNumber);
                    $headerRow = $rowNumber;
                    continue;
                }

                if (count($rows) >= ProductImportLimits::MAX_DATA_ROWS) {
                    throw $this->failure(
                        'row_limit_exceeded',
                        'El archivo supera el máximo de 1000 filas de datos.',
                        $rowNumber,
                    );
                }

                $rows[] = new ProductImportRow(
                    $rowNumber,
                    $this->normalizer->associate($headers, $values, $rowNumber),
                );
            }
        } catch (ProductImportReadException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ProductImportReadException(
                new ProductImportTechnicalError('csv_read_failed', 'No fue posible leer el archivo CSV.'),
                $exception,
            );
        } finally {
            fclose($handle);
        }

        if ($headers === null || $headerRow === null) {
            throw $this->failure('missing_header', 'El archivo CSV no contiene encabezados.');
        }

        return new ProductImportReadResult(
            'csv',
            $headers,
            $rows,
            [],
            [
                'header_row' => $headerRow,
                'mime' => $this->mime($path),
                'size_bytes' => (int) filesize($path),
                'delimiter' => ',',
                'encoding' => 'UTF-8',
                'database_used' => false,
            ],
        );
    }

    /**
     * @param resource $handle
     * @return array{int, array<int, string|null>}|null
     */
    private function nextRecord($handle, int &$physicalLine): ?array
    {
        if (feof($handle)) {
            return null;
        }

        $start = ftell($handle);
        $rowNumber = $physicalLine + 1;
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });

        try {
            $record = fgetcsv($handle, null, ',', '"', '');
        } finally {
            restore_error_handler();
        }

        if ($warning !== null) {
            throw $this->failure('csv_read_failed', 'No fue posible interpretar una fila del archivo CSV.', $rowNumber);
        }

        if ($record === false) {
            if (feof($handle)) {
                return null;
            }

            throw $this->failure('csv_read_failed', 'No fue posible interpretar una fila del archivo CSV.', $rowNumber);
        }

        $end = ftell($handle);
        $physicalLine += $this->consumedPhysicalLines($handle, $start, $end);

        return [$rowNumber, $record];
    }

    /**
     * @param resource $handle
     */
    private function consumedPhysicalLines($handle, int|false $start, int|false $end): int
    {
        if (!is_int($start) || !is_int($end) || $end <= $start || fseek($handle, $start) !== 0) {
            return 1;
        }

        $remaining = $end - $start;
        $lineBreaks = 0;

        while ($remaining > 0) {
            $chunk = fread($handle, min(8192, $remaining));

            if (!is_string($chunk) || $chunk === '') {
                break;
            }

            $lineBreaks += substr_count($chunk, "\n");
            $remaining -= strlen($chunk);
        }

        fseek($handle, $end);

        return max(1, $lineBreaks);
    }

    /**
     * @param array<int, string|null> $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_map(static fn (?string $value): string => $value ?? '', $values));
    }

    private function mime(string $path): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        return is_string($mime) ? $mime : '';
    }

    private function failure(string $code, string $message, ?int $row = null): ProductImportReadException
    {
        return new ProductImportReadException(new ProductImportTechnicalError($code, $message, $row));
    }
}
