<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Domain\Products\Import\ProductImportFileReader;
use App\Domain\Products\Import\ProductImportLimits;
use App\Domain\Products\Import\ProductImportReadException;
use App\Domain\Products\Import\ProductImportReadResult;
use App\Domain\Products\Import\ProductImportTechnicalError;

final class ProductImportReader implements ProductImportFileReader
{
    private const CSV_MIMES = [
        'application/octet-stream',
        'application/csv',
        'application/vnd.ms-excel',
        'text/csv',
        'text/plain',
        'text/x-csv',
    ];
    private const XLSX_MIMES = [
        'application/octet-stream',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/x-zip',
        'application/x-zip-compressed',
        'application/zip',
    ];

    public function __construct(
        private readonly CsvProductImportFileReader $csvReader = new CsvProductImportFileReader(),
        private readonly XlsxProductImportFileReader $xlsxReader = new XlsxProductImportFileReader(),
    ) {
    }

    public function read(string $path): ProductImportReadResult
    {
        try {
            $realPath = $this->safeRealPath($path);
            $extension = strtolower((string) pathinfo($realPath, PATHINFO_EXTENSION));

            if (!in_array($extension, ['csv', 'xlsx'], true)) {
                throw $this->failure(
                    'unsupported_format',
                    'El formato del archivo no está permitido. Usa CSV o XLSX.',
                    ['extension' => $extension],
                );
            }

            $size = filesize($realPath);

            if (!is_int($size) || $size <= 0) {
                throw $this->failure('empty_file', 'El archivo está vacío o no puede medirse.');
            }

            if ($size > ProductImportLimits::MAX_FILE_BYTES) {
                throw $this->failure(
                    'file_too_large',
                    'El archivo supera el límite técnico de 5 MiB.',
                    ['max_bytes' => ProductImportLimits::MAX_FILE_BYTES],
                );
            }

            $mime = $this->mime($realPath);
            $allowedMimes = $extension === 'csv' ? self::CSV_MIMES : self::XLSX_MIMES;

            if (!in_array($mime, $allowedMimes, true)) {
                throw $this->failure(
                    'invalid_file_type',
                    'El contenido del archivo no coincide con un formato permitido.',
                    ['detected_mime' => $mime],
                );
            }

            return $extension === 'csv'
                ? $this->csvReader->read($realPath)
                : $this->xlsxReader->read($realPath);
        } catch (ProductImportReadException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ProductImportReadException(
                new ProductImportTechnicalError(
                    'file_read_failed',
                    'No fue posible leer el archivo de importación.',
                ),
                $exception,
            );
        }
    }

    private function safeRealPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1) {
            throw $this->failure('unsafe_file_path', 'La ubicación del archivo no es segura.');
        }

        $realPath = realpath($path);

        if (!is_string($realPath) || !is_file($realPath) || !is_readable($realPath)) {
            throw $this->failure('file_not_readable', 'El archivo no existe o no puede leerse.');
        }

        $publicPath = realpath(BASE_PATH . '/public');

        if (is_string($publicPath)) {
            $prefix = rtrim(str_replace('\\', '/', $publicPath), '/') . '/';
            $normalizedFile = str_replace('\\', '/', $realPath);

            if (str_starts_with(strtolower($normalizedFile . '/'), strtolower($prefix))) {
                throw $this->failure(
                    'public_path_not_allowed',
                    'El archivo de importación debe permanecer en almacenamiento privado.',
                );
            }
        }

        return $realPath;
    }

    private function mime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? strtolower(trim($mime)) : '';
    }

    /**
     * @param array<string, bool|float|int|string|null> $context
     */
    private function failure(string $code, string $message, array $context = []): ProductImportReadException
    {
        return new ProductImportReadException(
            new ProductImportTechnicalError($code, $message, null, null, $context),
        );
    }
}
