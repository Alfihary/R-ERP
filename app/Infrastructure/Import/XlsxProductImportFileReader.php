<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Domain\Products\Import\ProductImportFileReader;
use App\Domain\Products\Import\ProductImportLimits;
use App\Domain\Products\Import\ProductImportReadException;
use App\Domain\Products\Import\ProductImportReadResult;
use App\Domain\Products\Import\ProductImportRow;
use App\Domain\Products\Import\ProductImportTechnicalError;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

final class XlsxProductImportFileReader implements ProductImportFileReader
{
    public function __construct(
        private readonly XlsxPrevalidator $prevalidator = new XlsxPrevalidator(),
        private readonly ProductImportNormalizer $normalizer = new ProductImportNormalizer(),
    ) {
    }

    public function read(string $path): ProductImportReadResult
    {
        $zipMetadata = $this->prevalidator->validate($path);
        $options = new Options();
        $options->SHOULD_FORMAT_DATES = false;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        if (is_writable(dirname($path))) {
            $options->setTempFolder(dirname($path));
        }

        $reader = new Reader($options);
        $readerOpened = false;
        $contentSheets = 0;
        $totalSheets = 0;
        $selectedHeaders = null;
        $selectedRows = [];
        $selectedHeaderRow = null;
        $selectedSheetName = null;

        try {
            $reader->open($path);
            $readerOpened = true;

            foreach ($reader->getSheetIterator() as $sheet) {
                ++$totalSheets;
                $headers = null;
                $headerRow = null;
                $rows = [];
                $sheetHasContent = false;

                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    $physicalRow = (int) $rowNumber;
                    $values = $this->normalizer->values(
                        $this->cellStrings($row->getCells(), $physicalRow),
                        $physicalRow,
                    );

                    if ($this->normalizer->isEmptyRow($values)) {
                        continue;
                    }

                    $sheetHasContent = true;

                    if ($headers === null) {
                        $headers = $this->normalizer->headers($values, $physicalRow);
                        $headerRow = $physicalRow;
                        continue;
                    }

                    if (count($rows) >= ProductImportLimits::MAX_DATA_ROWS) {
                        throw $this->failure(
                            'row_limit_exceeded',
                            'El archivo supera el máximo de 1000 filas de datos.',
                            $physicalRow,
                        );
                    }

                    $rows[] = new ProductImportRow(
                        $physicalRow,
                        $this->normalizer->associate($headers, $values, $physicalRow),
                    );
                }

                if (!$sheetHasContent) {
                    continue;
                }

                ++$contentSheets;

                if ($contentSheets > 1) {
                    throw $this->failure(
                        'xlsx_multiple_non_empty_sheets',
                        'El XLSX contiene más de una hoja con contenido.',
                    );
                }

                $selectedHeaders = $headers;
                $selectedRows = $rows;
                $selectedHeaderRow = $headerRow;
                $selectedSheetName = $sheet->getName();
            }
        } catch (ProductImportReadException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ProductImportReadException(
                new ProductImportTechnicalError('xlsx_read_failed', 'No fue posible leer el archivo XLSX.'),
                $exception,
            );
        } finally {
            if ($readerOpened) {
                $reader->close();
            }

            unset($reader);
            gc_collect_cycles();
        }

        if ($contentSheets === 0 || !is_array($selectedHeaders) || !is_int($selectedHeaderRow)) {
            throw $this->failure('missing_header', 'El XLSX no contiene una hoja con encabezados.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return new ProductImportReadResult(
            'xlsx',
            $selectedHeaders,
            $selectedRows,
            [],
            array_merge(
                $zipMetadata,
                [
                    'header_row' => $selectedHeaderRow,
                    'sheet_name' => $selectedSheetName,
                    'content_sheet_count' => $contentSheets,
                    'total_sheet_count' => $totalSheets,
                    'mime' => is_string($mime) ? $mime : '',
                    'size_bytes' => (int) filesize($path),
                    'reader' => 'openspout/openspout 4.28.5',
                    'database_used' => false,
                ],
            ),
        );
    }

    /**
     * @param list<Cell> $cells
     * @return list<string>
     */
    private function cellStrings(array $cells, int $rowNumber): array
    {
        $values = [];

        foreach ($cells as $index => $cell) {
            if ($cell instanceof FormulaCell) {
                throw $this->failure(
                    'xlsx_formula_not_allowed',
                    'El XLSX contiene una fórmula no permitida.',
                    $rowNumber,
                    'column_' . ($index + 1),
                );
            }

            $value = $cell->getValue();

            if ($value === null) {
                $values[] = '';
                continue;
            }

            if (is_string($value)) {
                $values[] = $value;
                continue;
            }

            if (is_bool($value)) {
                $values[] = $value ? '1' : '0';
                continue;
            }

            if (is_int($value)) {
                $values[] = (string) $value;
                continue;
            }

            if (is_float($value) && is_finite($value)) {
                $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                $values[] = $encoded;
                continue;
            }

            throw $this->failure(
                'xlsx_unsupported_cell_type',
                'El XLSX contiene un tipo de celda no soportado de forma segura.',
                $rowNumber,
                'column_' . ($index + 1),
            );
        }

        return $values;
    }

    private function failure(
        string $code,
        string $message,
        ?int $row = null,
        ?string $field = null,
    ): ProductImportReadException {
        return new ProductImportReadException(new ProductImportTechnicalError($code, $message, $row, $field));
    }
}
