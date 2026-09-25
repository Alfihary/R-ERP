<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Domain\Products\Import\ProductImportLimits;
use App\Domain\Products\Import\ProductImportReadException;
use App\Domain\Products\Import\ProductImportTechnicalError;

final class XlsxPrevalidator
{
    /**
     * @return array{
     *     zip_entries: int,
     *     uncompressed_bytes: int,
     *     max_compression_ratio: float
     * }
     */
    public function validate(string $path): array
    {
        $this->assertZipSignature($path);

        $zip = new \ZipArchive();
        $opened = $zip->open($path, \ZipArchive::RDONLY);

        if ($opened !== true) {
            throw $this->failure('xlsx_invalid_zip', 'El archivo XLSX no contiene un ZIP válido.');
        }

        try {
            if ($zip->numFiles <= 0) {
                throw $this->failure('xlsx_invalid_zip', 'El contenedor XLSX está vacío.');
            }

            if ($zip->numFiles > ProductImportLimits::MAX_ZIP_ENTRIES) {
                throw $this->failure(
                    'xlsx_zip_entry_limit_exceeded',
                    'El XLSX contiene demasiadas entradas internas.',
                    ['max_entries' => ProductImportLimits::MAX_ZIP_ENTRIES],
                );
            }

            $entries = [];
            $uncompressedBytes = 0;
            $maxRatio = 0.0;

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);

                if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
                    throw $this->failure('xlsx_invalid_zip', 'No fue posible inspeccionar el contenido del XLSX.');
                }

                $rawName = $stat['name'];
                $normalizedName = $this->normalizeEntryName($rawName);
                $entryKey = strtolower(rtrim($normalizedName, '/'));

                if ($entryKey === '' || isset($entries[$entryKey])) {
                    throw $this->failure(
                        'xlsx_duplicate_zip_entry',
                        'El XLSX contiene entradas ZIP duplicadas o ambiguas.',
                    );
                }

                $entries[$entryKey] = $rawName;
                $size = max(0, (int) ($stat['size'] ?? 0));
                $compressedSize = max(0, (int) ($stat['comp_size'] ?? 0));

                if ($size > ProductImportLimits::MAX_UNCOMPRESSED_BYTES - $uncompressedBytes) {
                    throw $this->failure(
                        'xlsx_zip_bomb_suspected',
                        'El tamaño descomprimido declarado del XLSX no es seguro.',
                        ['max_uncompressed_bytes' => ProductImportLimits::MAX_UNCOMPRESSED_BYTES],
                    );
                }

                $uncompressedBytes += $size;

                if ($size > 0) {
                    if ($compressedSize === 0) {
                        throw $this->failure(
                            'xlsx_zip_bomb_suspected',
                            'Una entrada del XLSX declara una compresión no segura.',
                        );
                    }

                    $ratio = $size / $compressedSize;
                    $maxRatio = max($maxRatio, $ratio);

                    if ($ratio > ProductImportLimits::MAX_COMPRESSION_RATIO) {
                        throw $this->failure(
                            'xlsx_zip_bomb_suspected',
                            'Una entrada del XLSX supera el ratio de compresión permitido.',
                            ['max_ratio' => ProductImportLimits::MAX_COMPRESSION_RATIO],
                        );
                    }
                }

                $this->assertNotEncrypted($zip, $index, $stat);
                $this->assertNoMacroPath($normalizedName);
            }

            $required = [
                '[content_types].xml',
                '_rels/.rels',
                'xl/workbook.xml',
                'xl/_rels/workbook.xml.rels',
            ];

            foreach ($required as $requiredEntry) {
                if (!isset($entries[$requiredEntry])) {
                    throw $this->failure(
                        'xlsx_structure_invalid',
                        'El XLSX no contiene la estructura OOXML mínima requerida.',
                        ['missing_entry' => $requiredEntry],
                    );
                }
            }

            $hasWorksheet = false;

            foreach (array_keys($entries) as $entryName) {
                if (str_starts_with($entryName, 'xl/worksheets/') && str_ends_with($entryName, '.xml')) {
                    $hasWorksheet = true;
                    break;
                }
            }

            if (!$hasWorksheet) {
                throw $this->failure('xlsx_structure_invalid', 'El XLSX no contiene hojas de cálculo válidas.');
            }

            $contentTypes = $this->xmlEntry($zip, $entries['[content_types].xml']);
            $rootRelationships = $this->xmlEntry($zip, $entries['_rels/.rels']);
            $workbook = $this->xmlEntry($zip, $entries['xl/workbook.xml']);
            $workbookRelationships = $this->xmlEntry($zip, $entries['xl/_rels/workbook.xml.rels']);

            $this->assertNoMacroContent($contentTypes);
            $this->assertWorkbookContentType($contentTypes);
            $this->assertRelationship($rootRelationships, 'officedocument', 'xl/workbook.xml');
            $this->assertRelationship($workbookRelationships, 'worksheet', null);
            $this->parseXml($workbook);

            foreach ($entries as $entryName => $rawName) {
                if (str_ends_with($entryName, '.rels')) {
                    $this->assertNoExternalRelationships($this->xmlEntry($zip, $rawName));
                }

                if (str_starts_with($entryName, 'xl/externallinks/')) {
                    throw $this->failure(
                        'xlsx_external_relationship_not_allowed',
                        'El XLSX contiene referencias externas no permitidas.',
                    );
                }
            }

            return [
                'zip_entries' => $zip->numFiles,
                'uncompressed_bytes' => $uncompressedBytes,
                'max_compression_ratio' => round($maxRatio, 4),
            ];
        } catch (ProductImportReadException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ProductImportReadException(
                new ProductImportTechnicalError(
                    'xlsx_prevalidation_failed',
                    'No fue posible validar de forma segura la estructura del XLSX.',
                ),
                $exception,
            );
        } finally {
            $zip->close();
        }
    }

    private function assertZipSignature(string $path): void
    {
        $handle = @fopen($path, 'rb');

        if (!is_resource($handle)) {
            throw $this->failure('xlsx_unreadable', 'No fue posible abrir el archivo XLSX.');
        }

        try {
            $signature = fread($handle, 4);
        } finally {
            fclose($handle);
        }

        if ($signature !== "PK\x03\x04") {
            throw $this->failure('xlsx_invalid_zip', 'El archivo XLSX no contiene una firma ZIP válida.');
        }
    }

    private function normalizeEntryName(string $name): string
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw $this->failure('xlsx_unsafe_zip_path', 'El XLSX contiene una ruta interna no segura.');
        }

        if (preg_match('/^[a-z]:[\\\\\/]/i', $name) === 1 || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            throw $this->failure('xlsx_unsafe_zip_path', 'El XLSX contiene una ruta interna absoluta.');
        }

        $directory = str_ends_with($name, '/') || str_ends_with($name, '\\');
        $segments = explode('/', str_replace('\\', '/', $name));
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw $this->failure('xlsx_unsafe_zip_path', 'El XLSX contiene una ruta interna no segura.');
            }

            $normalized[] = $segment;
        }

        if ($normalized === []) {
            throw $this->failure('xlsx_unsafe_zip_path', 'El XLSX contiene una ruta interna vacía.');
        }

        return implode('/', $normalized) . ($directory ? '/' : '');
    }

    /**
     * @param array<string, mixed> $stat
     */
    private function assertNotEncrypted(\ZipArchive $zip, int $index, array $stat): void
    {
        if (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== 0) {
            throw $this->failure(
                'xlsx_encrypted_not_supported',
                'Los archivos XLSX cifrados o protegidos no están soportados.',
            );
        }

        if (!method_exists($zip, 'getEncryptionName')) {
            return;
        }

        $encryption = $zip->getEncryptionName($index);

        if (!is_string($encryption)) {
            return;
        }

        $normalized = strtolower(trim($encryption));

        if ($normalized !== '' && !in_array($normalized, ['none', 'no encryption'], true)) {
            throw $this->failure(
                'xlsx_encrypted_not_supported',
                'Los archivos XLSX cifrados o protegidos no están soportados.',
            );
        }
    }

    private function assertNoMacroPath(string $name): void
    {
        $normalized = strtolower($name);

        if (str_ends_with($normalized, 'vbaproject.bin') || str_contains($normalized, '/vba/')) {
            throw $this->failure('xlsx_macro_not_allowed', 'El XLSX contiene macros o contenido VBA no permitido.');
        }
    }

    private function xmlEntry(\ZipArchive $zip, string $rawName): string
    {
        $stat = $zip->statName($rawName);

        if (!is_array($stat) || (int) ($stat['size'] ?? 0) > ProductImportLimits::MAX_XML_ENTRY_BYTES) {
            throw $this->failure('xlsx_xml_too_large', 'Un XML interno del XLSX supera el límite permitido.');
        }

        $contents = $zip->getFromName($rawName);

        if (!is_string($contents) || $contents === '') {
            throw $this->failure('xlsx_structure_invalid', 'No fue posible leer la estructura OOXML requerida.');
        }

        return $contents;
    }

    private function parseXml(string $xml): \DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw $this->failure('xlsx_xml_invalid', 'El XLSX contiene una declaración XML no permitida.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded !== true) {
            throw $this->failure('xlsx_xml_invalid', 'El XLSX contiene XML inválido.');
        }

        return $document;
    }

    private function assertNoMacroContent(string $contentTypes): void
    {
        $lower = strtolower($contentTypes);

        if (str_contains($lower, 'macroenabled') || str_contains($lower, 'vbaproject')) {
            throw $this->failure('xlsx_macro_not_allowed', 'El XLSX contiene macros o contenido VBA no permitido.');
        }
    }

    private function assertWorkbookContentType(string $contentTypes): void
    {
        $document = $this->parseXml($contentTypes);
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="Override"]');
        $valid = false;

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }

                $partName = strtolower(ltrim($node->getAttribute('PartName'), '/'));
                $contentType = strtolower($node->getAttribute('ContentType'));

                if ($partName === 'xl/workbook.xml' && str_contains($contentType, 'spreadsheetml.sheet.main+xml')) {
                    $valid = true;
                    break;
                }
            }
        }

        if (!$valid) {
            throw $this->failure('xlsx_structure_invalid', 'El tipo OOXML del workbook no es válido para XLSX.');
        }
    }

    private function assertRelationship(string $xml, string $typeNeedle, ?string $target): void
    {
        $document = $this->parseXml($xml);
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="Relationship"]');
        $valid = false;

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }

                $type = strtolower($node->getAttribute('Type'));
                $relationshipTarget = strtolower(ltrim(str_replace('\\', '/', $node->getAttribute('Target')), '/'));

                if (str_contains($type, strtolower($typeNeedle)) && ($target === null || $relationshipTarget === strtolower($target))) {
                    $valid = true;
                    break;
                }
            }
        }

        if (!$valid) {
            throw $this->failure('xlsx_structure_invalid', 'El XLSX no contiene las relaciones OOXML requeridas.');
        }
    }

    private function assertNoExternalRelationships(string $xml): void
    {
        $document = $this->parseXml($xml);
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="Relationship"]');

        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $targetMode = strtolower(trim($node->getAttribute('TargetMode')));
            $type = strtolower($node->getAttribute('Type'));
            $target = strtolower(str_replace('\\', '/', $node->getAttribute('Target')));

            if ($targetMode === 'external' || str_contains($type, 'externallink') || str_contains($target, 'externallinks/')) {
                throw $this->failure(
                    'xlsx_external_relationship_not_allowed',
                    'El XLSX contiene relaciones externas no permitidas.',
                );
            }
        }
    }

    /**
     * @param array<string, bool|float|int|string|null> $context
     */
    private function failure(string $code, string $message, array $context = []): ProductImportReadException
    {
        return new ProductImportReadException(new ProductImportTechnicalError($code, $message, null, null, $context));
    }
}
