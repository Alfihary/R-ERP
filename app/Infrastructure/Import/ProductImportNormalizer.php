<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Domain\Products\Import\ProductImportLimits;
use App\Domain\Products\Import\ProductImportReadException;
use App\Domain\Products\Import\ProductImportTechnicalError;

final class ProductImportNormalizer
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * @param list<string> $rawHeaders
     * @return list<string>
     */
    public function headers(array $rawHeaders, int $rowNumber): array
    {
        if (isset($rawHeaders[0]) && str_starts_with($rawHeaders[0], self::UTF8_BOM)) {
            $rawHeaders[0] = substr($rawHeaders[0], strlen(self::UTF8_BOM));
        }

        $headers = array_map(
            fn (string $header): string => mb_strtolower(trim($this->utf8($header, $rowNumber)), 'UTF-8'),
            $rawHeaders,
        );

        while ($headers !== [] && $headers[array_key_last($headers)] === '') {
            array_pop($headers);
        }

        if ($headers === []) {
            throw $this->failure('missing_header', 'El archivo no contiene encabezados válidos.');
        }

        $seen = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                throw $this->failure(
                    'empty_header',
                    'No se permiten encabezados vacíos entre columnas activas.',
                    $rowNumber,
                    'column_' . ($index + 1),
                );
            }

            if (isset($seen[$header])) {
                throw $this->failure(
                    'duplicate_header',
                    'El archivo contiene encabezados duplicados.',
                    $rowNumber,
                    $header,
                );
            }

            $seen[$header] = true;
        }

        return array_values($headers);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    public function values(array $values, int $rowNumber): array
    {
        foreach ($values as $index => $value) {
            $value = $this->utf8($value, $rowNumber, 'column_' . ($index + 1));

            if (strlen($value) > ProductImportLimits::MAX_CELL_BYTES) {
                throw $this->failure(
                    'cell_value_too_large',
                    'Una celda supera el límite técnico permitido.',
                    $rowNumber,
                    'column_' . ($index + 1),
                    ['max_bytes' => ProductImportLimits::MAX_CELL_BYTES],
                );
            }

            $values[$index] = $value;
        }

        return $values;
    }

    /**
     * @param list<string> $values
     */
    public function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $headers
     * @param list<string> $values
     * @return array<string, string>
     */
    public function associate(array $headers, array $values, int $rowNumber): array
    {
        while (count($values) > count($headers) && trim($values[array_key_last($values)]) === '') {
            array_pop($values);
        }

        if (count($values) > count($headers)) {
            throw $this->failure(
                'row_column_count_mismatch',
                'La fila contiene más columnas activas que los encabezados.',
                $rowNumber,
                null,
                ['expected_columns' => count($headers), 'actual_columns' => count($values)],
            );
        }

        $values = array_pad($values, count($headers), '');
        $associated = array_combine($headers, array_slice($values, 0, count($headers)));

        if (!is_array($associated)) {
            throw $this->failure('row_mapping_failed', 'No fue posible asociar la fila con sus encabezados.', $rowNumber);
        }

        return $associated;
    }

    private function utf8(string $value, int $row, ?string $field = null): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw $this->failure(
                'invalid_encoding',
                'El archivo contiene texto que no está codificado en UTF-8.',
                $row,
                $field,
            );
        }

        return $value;
    }

    /**
     * @param array<string, bool|float|int|string|null> $context
     */
    private function failure(
        string $code,
        string $message,
        ?int $row = null,
        ?string $field = null,
        array $context = [],
    ): ProductImportReadException {
        return new ProductImportReadException(
            new ProductImportTechnicalError($code, $message, $row, $field, $context),
        );
    }
}
