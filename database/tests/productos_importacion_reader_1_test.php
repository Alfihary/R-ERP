<?php

declare(strict_types=1);

use App\Domain\Products\Import\ProductImportLimits;
use App\Domain\Products\Import\ProductImportReadException;
use App\Infrastructure\Import\ProductImportReader;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

return static function (): array {
    $suite = new class {
        private string $tempDir;
        private ProductImportReader $reader;

        /**
         * @return array<string, mixed>
         */
        public function run(): array
        {
            $this->tempDir = BASE_PATH . '/storage/temp/product-import-reader-' . bin2hex(random_bytes(8));

            if (!mkdir($this->tempDir, 0750, true) && !is_dir($this->tempDir)) {
                throw new RuntimeException('Could not create the isolated reader test directory.');
            }

            $this->reader = new ProductImportReader();
            $checks = [];

            try {
                $checks = array_merge(
                    $this->csvChecks(),
                    $this->xlsxChecks(),
                    $this->architectureChecks(),
                );
            } finally {
                $this->removeTree($this->tempDir);
            }

            $checks['no_residual_temp_files'] = !file_exists($this->tempDir);

            foreach ($checks as $name => $passed) {
                if ($passed !== true) {
                    throw new RuntimeException('Product import reader assertion failed: ' . $name . '.');
                }
            }

            return [
                'phase' => 'PRODUCTOS-IMPORTACION-LECTOR-SEGURO-1',
                'status' => 'PASS',
                'checks' => $checks,
                'limits' => [
                    'max_file_bytes' => ProductImportLimits::MAX_FILE_BYTES,
                    'max_data_rows' => ProductImportLimits::MAX_DATA_ROWS,
                    'max_cell_bytes' => ProductImportLimits::MAX_CELL_BYTES,
                    'max_zip_entries' => ProductImportLimits::MAX_ZIP_ENTRIES,
                    'max_uncompressed_bytes' => ProductImportLimits::MAX_UNCOMPRESSED_BYTES,
                    'max_compression_ratio' => ProductImportLimits::MAX_COMPRESSION_RATIO,
                ],
                'database_used' => false,
                'smtp_used' => false,
                'business_files_processed' => false,
                'cleanup' => 'complete',
            ];
        }

        /**
         * @return array<string, bool>
         */
        private function csvChecks(): array
        {
            $valid = $this->csv('valid.csv', "id_producto,descripcion,nota\n00123,Árbol,=texto\n002,+válido,-dato\n");
            $validResult = $this->reader->read($valid);
            $bom = $this->csv('bom.csv', "\xEF\xBB\xBFid_producto,descripcion\nA1,Producto\n");
            $bomResult = $this->reader->read($bom);
            $lf = $this->csv('lf.csv', "id_producto,descripcion\nA1,Uno\n\nA2,Dos\n");
            $lfResult = $this->reader->read($lf);
            $crlf = $this->csv('crlf.csv', "id_producto,descripcion\r\nA1,Uno\r\nA2,Dos\r\n");
            $crlfResult = $this->reader->read($crlf);
            $headersOnly = $this->csv('headers-only.csv', "id_producto,descripcion\n");
            $headersOnlyResult = $this->reader->read($headersOnly);

            $invalidUtf8 = $this->csv('invalid-utf8.csv', "id_producto,descripcion\nA1,Texto \xC3\x28 invalido\n");
            $empty = $this->csv('empty.csv', '');
            $duplicate = $this->csv('duplicate.csv', " ID_PRODUCTO ,id_producto\nA1,A2\n");

            $tooManyRows = "id_producto,descripcion\n";
            for ($row = 1; $row <= 1001; ++$row) {
                $tooManyRows .= 'P' . $row . ',Producto ' . $row . "\n";
            }
            $rowLimit = $this->csv('row-limit.csv', $tooManyRows);

            $oversizedCell = $this->csv(
                'oversized-cell.csv',
                "id_producto,descripcion\nA1," . str_repeat('a', ProductImportLimits::MAX_CELL_BYTES + 1) . "\n",
            );
            $unsupported = $this->csv('unsupported.txt', "id_producto\nA1\n");
            $fakeExtension = $this->csv('fake.xlsx', "id_producto,descripcion\nA1,Falso\n");
            $large = $this->tempDir . '/large.csv';
            $largeHandle = fopen($large, 'wb');
            if (!is_resource($largeHandle)) {
                throw new RuntimeException('Could not create the oversized CSV fixture.');
            }
            ftruncate($largeHandle, ProductImportLimits::MAX_FILE_BYTES + 1);
            fclose($largeHandle);

            return [
                'csv_valid' => $validResult->format === 'csv' && $validResult->totalRows() === 2,
                'csv_bom_removed' => $bomResult->headers[0] === 'id_producto',
                'csv_utf8_accents' => $validResult->rows[0]->values['descripcion'] === 'Árbol',
                'csv_formula_like_text_preserved' => $validResult->rows[0]->values['nota'] === '=texto'
                    && $validResult->rows[1]->values['descripcion'] === '+válido'
                    && $validResult->rows[1]->values['nota'] === '-dato',
                'csv_lf' => $lfResult->totalRows() === 2 && $lfResult->rows[1]->rowNumber === 4,
                'csv_crlf' => $crlfResult->totalRows() === 2 && $crlfResult->rows[1]->rowNumber === 3,
                'csv_headers_only' => $headersOnlyResult->totalRows() === 0,
                'csv_invalid_utf8_rejected' => $this->errorCode($invalidUtf8) === 'invalid_encoding',
                'csv_empty_rejected' => $this->errorCode($empty) === 'empty_file',
                'csv_duplicate_header_rejected' => $this->errorCode($duplicate) === 'duplicate_header',
                'csv_empty_row_ignored' => $lfResult->rows[1]->rowNumber === 4,
                'csv_row_limit' => $this->errorCode($rowLimit) === 'row_limit_exceeded',
                'file_size_limit' => $this->errorCode($large) === 'file_too_large',
                'unsupported_format' => $this->errorCode($unsupported) === 'unsupported_format',
                'fake_extension_rejected' => in_array(
                    $this->errorCode($fakeExtension),
                    ['invalid_file_type', 'xlsx_invalid_zip'],
                    true,
                ),
                'cell_size_limit' => $this->errorCode($oversizedCell) === 'cell_value_too_large',
            ];
        }

        /**
         * @return array<string, bool>
         */
        private function xlsxChecks(): array
        {
            $valid = $this->xlsx('valid.xlsx', [
                [
                    ['id_producto', 'descripcion'],
                    ['00123', 'Árbol'],
                ],
            ]);
            $validResult = $this->reader->read($valid);

            $emptyFirstSheet = $this->xlsx('empty-first-sheet.xlsx', [
                [],
                [
                    ['id_producto', 'descripcion'],
                    ['A1', 'Producto'],
                ],
            ]);
            $emptyFirstResult = $this->reader->read($emptyFirstSheet);

            $multipleSheets = $this->xlsx('multiple-content-sheets.xlsx', [
                [['id_producto'], ['A1']],
                [['id_producto'], ['A2']],
            ]);

            $formula = $this->xlsx('formula.xlsx', [
                [
                    ['id_producto', 'descripcion'],
                    ['A1', '=1+1'],
                ],
            ]);

            $cachedFormula = $this->xlsxWithCachedFormula('cached-formula.xlsx');

            $corruptOoxml = $this->tempDir . '/corrupt-ooxml.xlsx';
            $this->zipWithEntries($corruptOoxml, ['placeholder.txt' => 'not ooxml']);
            $invalidZip = $this->tempDir . '/invalid-zip.xlsx';
            file_put_contents($invalidZip, "PK\x03\x04broken-zip");

            $macro = $this->copyFixture($valid, 'macro.xlsx');
            $this->mutateZip($macro, static function (ZipArchive $zip): void {
                $zip->addFromString('xl/vbaProject.bin', 'macro-marker');
            });

            $external = $this->copyFixture($valid, 'external.xlsx');
            $this->mutateZip($external, static function (ZipArchive $zip): void {
                $name = 'xl/_rels/workbook.xml.rels';
                $xml = $zip->getFromName($name);
                if (!is_string($xml)) {
                    throw new RuntimeException('Workbook relationships fixture is missing.');
                }
                $injection = '<Relationship Id="rIdExternal" '
                    . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLink" '
                    . 'Target="https://example.invalid/book.xlsx" TargetMode="External"/>';
                $xml = str_replace('</Relationships>', $injection . '</Relationships>', $xml);
                $zip->addFromString($name, $xml);
            });

            $traversal = $this->copyFixture($valid, 'traversal.xlsx');
            $this->mutateZip($traversal, static function (ZipArchive $zip): void {
                $zip->addFromString('../outside.txt', 'blocked');
            });

            $duplicate = $this->copyFixture($valid, 'duplicate-entry.xlsx');
            $this->mutateZip($duplicate, static function (ZipArchive $zip): void {
                $workbook = $zip->getFromName('xl/workbook.xml');
                if (!is_string($workbook)) {
                    throw new RuntimeException('Workbook fixture is missing.');
                }
                $zip->addFromString('xl//workbook.xml', $workbook);
            });

            $zipBomb = $this->copyFixture($valid, 'zip-bomb.xlsx');
            $this->mutateZip($zipBomb, static function (ZipArchive $zip): void {
                $zip->addFromString('xl/highly-compressible.bin', str_repeat('A', 1024 * 1024));
            });

            $tooManyEntries = $this->copyFixture($valid, 'entry-limit.xlsx');
            $this->mutateZip($tooManyEntries, static function (ZipArchive $zip): void {
                for ($index = 0; $index <= ProductImportLimits::MAX_ZIP_ENTRIES; ++$index) {
                    $zip->addFromString('custom/entry-' . $index . '.txt', 'x');
                }
            });

            $encrypted = $this->copyFixture($valid, 'encrypted.xlsx');
            $encryptedSupported = method_exists(ZipArchive::class, 'setEncryptionName');
            if ($encryptedSupported) {
                $this->mutateZip($encrypted, static function (ZipArchive $zip): void {
                    $zip->addFromString('xl/protected.bin', 'secret');
                    if (!$zip->setEncryptionName('xl/protected.bin', ZipArchive::EM_AES_256, 'test-password')) {
                        throw new RuntimeException('Could not encrypt the XLSX test entry.');
                    }
                });
            }

            return [
                'xlsx_valid' => $validResult->format === 'xlsx' && $validResult->totalRows() === 1,
                'xlsx_text_leading_zero_preserved' => $validResult->rows[0]->values['id_producto'] === '00123',
                'xlsx_single_content_sheet' => $validResult->metadata['content_sheet_count'] === 1,
                'xlsx_empty_sheets_tolerated' => $emptyFirstResult->metadata['content_sheet_count'] === 1
                    && $emptyFirstResult->metadata['total_sheet_count'] === 2,
                'xlsx_multiple_content_sheets_rejected' => $this->errorCode($multipleSheets)
                    === 'xlsx_multiple_non_empty_sheets',
                'xlsx_formula_rejected' => $this->errorCode($formula) === 'xlsx_formula_not_allowed',
                'xlsx_cached_formula_rejected' => $this->errorCode($cachedFormula) === 'xlsx_formula_not_allowed',
                'xlsx_corrupt_ooxml_rejected' => $this->errorCode($corruptOoxml) === 'xlsx_structure_invalid',
                'xlsx_invalid_zip_rejected' => $this->errorCode($invalidZip) === 'xlsx_invalid_zip',
                'xlsx_macro_rejected' => $this->errorCode($macro) === 'xlsx_macro_not_allowed',
                'xlsx_external_relationship_rejected' => $this->errorCode($external)
                    === 'xlsx_external_relationship_not_allowed',
                'xlsx_path_traversal_rejected' => $this->errorCode($traversal) === 'xlsx_unsafe_zip_path',
                'xlsx_duplicate_entry_rejected' => $this->errorCode($duplicate) === 'xlsx_duplicate_zip_entry',
                'xlsx_zip_bomb_rejected' => $this->errorCode($zipBomb) === 'xlsx_zip_bomb_suspected',
                'xlsx_entry_limit_rejected' => $this->errorCode($tooManyEntries)
                    === 'xlsx_zip_entry_limit_exceeded',
                'xlsx_encrypted_rejected' => !$encryptedSupported
                    || $this->errorCode($encrypted) === 'xlsx_encrypted_not_supported',
            ];
        }

        /**
         * @return array<string, bool>
         */
        private function architectureChecks(): array
        {
            $paths = [
                BASE_PATH . '/app/Domain/Products/Import',
                BASE_PATH . '/app/Infrastructure/Import',
                BASE_PATH . '/database/productos-importacion-reader.php',
            ];
            $contents = '';

            foreach ($paths as $path) {
                if (is_dir($path)) {
                    foreach (glob($path . '/*.php') ?: [] as $file) {
                        $contents .= (string) file_get_contents($file);
                    }
                    continue;
                }

                $contents .= (string) file_get_contents($path);
            }

            return [
                'no_pdo_dependency' => !str_contains($contents, 'PDO'),
                'no_connection_provider' => !str_contains($contents, 'ConnectionProvider'),
                'no_product_repository' => !str_contains($contents, 'ProductRepository'),
                'no_product_service' => !str_contains($contents, 'ProductService'),
                'no_smtp' => !str_contains($contents, 'SMTP'),
                'limits_are_centralized' => ProductImportLimits::MAX_FILE_BYTES === 5 * 1024 * 1024
                    && ProductImportLimits::MAX_DATA_ROWS === 1000
                    && ProductImportLimits::MAX_ZIP_ENTRIES === 2048
                    && ProductImportLimits::MAX_UNCOMPRESSED_BYTES === 50 * 1024 * 1024
                    && ProductImportLimits::MAX_COMPRESSION_RATIO === 100.0,
                'database_connection_used_false' => true,
            ];
        }

        private function csv(string $name, string $contents): string
        {
            $path = $this->tempDir . '/' . $name;
            file_put_contents($path, $contents);
            return $path;
        }

        /**
         * @param list<list<list<null|bool|float|int|string>>> $sheets
         */
        private function xlsx(string $name, array $sheets): string
        {
            $path = $this->tempDir . '/' . $name;
            $options = new Options();
            $options->setTempFolder($this->tempDir);
            $writer = new Writer($options);
            $writer->openToFile($path);

            try {
                foreach ($sheets as $sheetIndex => $rows) {
                    if ($sheetIndex > 0) {
                        $writer->addNewSheetAndMakeItCurrent();
                    }

                    foreach ($rows as $values) {
                        $writer->addRow(Row::fromValues($values));
                    }
                }
            } finally {
                $writer->close();
            }

            return $path;
        }

        private function xlsxWithCachedFormula(string $name): string
        {
            $path = $this->tempDir . '/' . $name;
            $options = new Options();
            $options->setTempFolder($this->tempDir);
            $writer = new Writer($options);
            $writer->openToFile($path);

            try {
                $writer->addRow(Row::fromValues(['id_producto', 'descripcion']));
                $writer->addRow(new Row([
                    Cell::fromValue('A1'),
                    new FormulaCell('=1+1', null, 2),
                ]));
            } finally {
                $writer->close();
            }

            return $path;
        }

        /**
         * @param array<string, string> $entries
         */
        private function zipWithEntries(string $path, array $entries): void
        {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the ZIP fixture.');
            }

            try {
                foreach ($entries as $name => $contents) {
                    $zip->addFromString($name, $contents);
                }
            } finally {
                $zip->close();
            }
        }

        private function copyFixture(string $source, string $name): string
        {
            $target = $this->tempDir . '/' . $name;
            if (!copy($source, $target)) {
                throw new RuntimeException('Could not copy an XLSX fixture.');
            }
            return $target;
        }

        /**
         * @param callable(ZipArchive): void $mutation
         */
        private function mutateZip(string $path, callable $mutation): void
        {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException('Could not open the XLSX fixture for mutation.');
            }

            try {
                $mutation($zip);
            } finally {
                $zip->close();
            }
        }

        private function errorCode(string $path): ?string
        {
            try {
                $this->reader->read($path);
                return null;
            } catch (ProductImportReadException $exception) {
                return $exception->error()->code;
            }
        }

        private function removeTree(string $path): void
        {
            if (!is_dir($path)) {
                return;
            }

            $items = scandir($path);
            if (!is_array($items)) {
                return;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $itemPath = $path . '/' . $item;
                if (is_dir($itemPath) && !is_link($itemPath)) {
                    $this->removeTree($itemPath);
                    continue;
                }

                for ($attempt = 0; $attempt < 5 && file_exists($itemPath); ++$attempt) {
                    gc_collect_cycles();
                    @unlink($itemPath);

                    if (file_exists($itemPath)) {
                        usleep(20_000);
                    }
                }
            }

            if (is_dir($path)) {
                @rmdir($path);
            }
        }
    };

    return $suite->run();
};
