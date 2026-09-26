<?php

declare(strict_types=1);

namespace App\Infrastructure\Import;

use App\Domain\Products\Import\ProductImportLimits;
use App\Domain\Products\Import\ProductImportPreviewException;
use App\Domain\Products\Import\ProductImportPreviewStore;
use App\Domain\Products\Import\ProductImportStoredUpload;
use Closure;

final class PrivateProductImportPreviewStore implements ProductImportPreviewStore
{
    private const MAX_ARTIFACT_BYTES = 12 * 1024 * 1024;
    private const ID_PATTERN = '/^[a-f0-9]{64}$/';

    public function __construct(
        string $storagePath,
        private readonly bool $allowLocalFilesForTests = false,
        private readonly ?Closure $clock = null,
    ) {
        $this->root = rtrim($storagePath, '/\\')
            . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'product_import_previews';
    }

    private readonly string $root;

    /** @param array<string, mixed>|null $upload */
    public function storeUpload(?array $upload): ProductImportStoredUpload
    {
        $prepared = $this->validateUpload($upload);
        $this->ensureDirectories();
        $previewId = bin2hex(random_bytes(32));
        $relativePath = 'uploads/' . $previewId . '.' . $prepared['extension'];
        $absolutePath = $this->pathForRelative($relativePath, false);

        $moved = $this->allowLocalFilesForTests
            ? copy($prepared['tmp_name'], $absolutePath)
            : move_uploaded_file($prepared['tmp_name'], $absolutePath);
        if (!$moved) {
            throw new ProductImportPreviewException(
                'preview_upload_store_failed',
                'No fue posible guardar temporalmente el archivo.',
            );
        }
        @chmod($absolutePath, 0600);
        $digest = hash_file('sha256', $absolutePath);
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
            @unlink($absolutePath);
            throw new ProductImportPreviewException(
                'preview_digest_failed',
                'No fue posible verificar el archivo temporal.',
            );
        }

        return new ProductImportStoredUpload(
            $previewId,
            $absolutePath,
            $relativePath,
            $prepared['original_name'],
            $digest,
        );
    }

    /** @param array<string, mixed> $artifact */
    public function writeArtifact(array $artifact): void
    {
        $previewId = $this->validatedId($artifact['preview_id'] ?? null);
        $this->validateArtifactShape($artifact, $previewId);
        $this->ensureDirectories();
        $encoded = json_encode(
            $artifact,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        if (strlen($encoded) > self::MAX_ARTIFACT_BYTES) {
            throw new ProductImportPreviewException(
                'preview_metadata_too_large',
                'El resultado de validación es demasiado grande para conservarse.',
            );
        }
        $path = $this->metadataPath($previewId);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));

        try {
            if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
                throw new ProductImportPreviewException(
                    'preview_metadata_write_failed',
                    'No fue posible guardar el preview.',
                );
            }
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) {
                throw new ProductImportPreviewException(
                    'preview_metadata_write_failed',
                    'No fue posible guardar el preview.',
                );
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @return array<string, mixed> */
    public function readArtifact(string $previewId): array
    {
        $previewId = $this->validatedId($previewId);
        $path = $this->metadataPath($previewId);
        if (!is_file($path)) {
            throw new ProductImportPreviewException(
                'preview_not_found',
                'El preview solicitado no existe.',
                404,
            );
        }
        $size = filesize($path);
        if ($size === false || $size < 2 || $size > self::MAX_ARTIFACT_BYTES) {
            throw $this->corrupt();
        }
        try {
            $artifact = json_decode(
                (string) file_get_contents($path),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            throw $this->corrupt();
        }
        if (!is_array($artifact)) {
            throw $this->corrupt();
        }
        $this->validateArtifactShape($artifact, $previewId);
        $uploadPath = $this->pathForRelative((string) $artifact['stored_file'], true);
        if (!is_file($uploadPath)) {
            throw new ProductImportPreviewException(
                'preview_file_missing',
                'El archivo temporal del preview ya no está disponible.',
                410,
            );
        }
        $digest = hash_file('sha256', $uploadPath);
        if (!is_string($digest) || !hash_equals((string) $artifact['sha256'], $digest)) {
            throw new ProductImportPreviewException(
                'preview_file_changed',
                'El archivo temporal del preview cambió y ya no es válido.',
                409,
            );
        }
        return $artifact;
    }

    public function discard(string $previewId): bool
    {
        $previewId = $this->validatedId($previewId);
        $removed = false;
        $metadata = $this->metadataPath($previewId);
        if (is_file($metadata)) {
            $removed = @unlink($metadata) || $removed;
        }
        foreach (['csv', 'xlsx'] as $extension) {
            $upload = $this->pathForRelative('uploads/' . $previewId . '.' . $extension, false);
            if (is_file($upload)) {
                $removed = @unlink($upload) || $removed;
            }
        }
        return $removed;
    }

    public function discardStoredUpload(ProductImportStoredUpload $upload): void
    {
        $this->discard($upload->previewId);
    }

    public function cleanupExpired(): int
    {
        $metadataDirectory = $this->root . DIRECTORY_SEPARATOR . 'metadata';
        if (!is_dir($metadataDirectory)) {
            return 0;
        }
        $removed = 0;
        foreach (glob($metadataDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $previewId = pathinfo($path, PATHINFO_FILENAME);
            if (preg_match(self::ID_PATTERN, $previewId) !== 1) {
                continue;
            }
            $expired = true;
            try {
                $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw $this->corrupt();
                }
                $this->validateArtifactShape($decoded, $previewId);
                $expired = (int) $decoded['expires_at'] < $this->now();
            } catch (\JsonException | ProductImportPreviewException) {
                $expired = true;
            }
            if ($expired && $this->discard($previewId)) {
                ++$removed;
            }
        }
        return $removed;
    }

    public function now(): int
    {
        return $this->clock instanceof Closure
            ? (int) ($this->clock)()
            : time();
    }

    /** @param array<string, mixed>|null $upload @return array{tmp_name: string, original_name: string, extension: string} */
    private function validateUpload(?array $upload): array
    {
        if ($upload === null) {
            throw new ProductImportPreviewException('upload_required', 'Selecciona un archivo CSV o XLSX.');
        }
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            $code = $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? 'file_too_large'
                : 'upload_failed';
            throw new ProductImportPreviewException($code, 'La carga del archivo no se completó correctamente.');
        }
        $tmp = $upload['tmp_name'] ?? null;
        if (!is_string($tmp) || $tmp === '' || !is_file($tmp)) {
            throw new ProductImportPreviewException('upload_invalid', 'El archivo temporal no es válido.');
        }
        if (!$this->allowLocalFilesForTests && !is_uploaded_file($tmp)) {
            throw new ProductImportPreviewException('upload_invalid', 'El archivo no proviene de una carga HTTP válida.');
        }
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > ProductImportLimits::MAX_FILE_BYTES) {
            throw new ProductImportPreviewException('file_too_large', 'El archivo debe medir entre 1 byte y 5 MiB.');
        }
        $originalName = $this->sanitizeOriginalName($upload['name'] ?? null);
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            throw new ProductImportPreviewException('unsupported_format', 'Solo se permiten archivos CSV o XLSX.');
        }
        return ['tmp_name' => $tmp, 'original_name' => $originalName, 'extension' => $extension];
    }

    private function sanitizeOriginalName(mixed $name): string
    {
        if (!is_string($name)) {
            throw new ProductImportPreviewException('invalid_original_name', 'El nombre original no es válido.');
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            throw new ProductImportPreviewException('invalid_original_name', 'El nombre original no es seguro.');
        }
        return function_exists('mb_substr')
            ? mb_substr($name, 0, 255, 'UTF-8')
            : substr($name, 0, 255);
    }

    /** @param array<string, mixed> $artifact */
    private function validateArtifactShape(array $artifact, string $previewId): void
    {
        $requiredStrings = ['preview_id', 'session_binding', 'original_name', 'stored_file', 'sha256', 'format'];
        foreach ($requiredStrings as $field) {
            if (!is_string($artifact[$field] ?? null) || $artifact[$field] === '') {
                throw $this->corrupt();
            }
        }
        $createdAt = $artifact['created_at'] ?? null;
        $expiresAt = $artifact['expires_at'] ?? null;
        $usedAt = $artifact['used_at'] ?? null;
        $totalRows = $artifact['total_rows'] ?? null;
        $validRows = $artifact['valid_rows'] ?? null;
        $invalidRows = $artifact['invalid_rows'] ?? null;

        if (($artifact['schema_version'] ?? null) !== 1
            || $artifact['preview_id'] !== $previewId
            || preg_match('/^[a-f0-9]{64}$/', $artifact['session_binding']) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $artifact['sha256']) !== 1
            || preg_match('#^uploads/' . preg_quote($previewId, '#') . '\.(?:csv|xlsx)$#', $artifact['stored_file']) !== 1
            || !in_array($artifact['format'], ['csv', 'xlsx'], true)
            || (int) ($artifact['user_id'] ?? 0) < 1
            || !is_int($createdAt)
            || $createdAt < 1
            || !is_int($expiresAt)
            || $expiresAt <= $createdAt
            || ($usedAt !== null && (!is_int($usedAt) || $usedAt < $createdAt))
            || !is_int($totalRows)
            || !is_int($validRows)
            || !is_int($invalidRows)
            || $totalRows < 0
            || $validRows < 0
            || $invalidRows < 0
            || $validRows + $invalidRows !== $totalRows
            || !is_array($artifact['result'] ?? null)
            || !is_array($artifact['result']['rows'] ?? null)
            || !is_array($artifact['result']['errors'] ?? null)
        ) {
            throw $this->corrupt();
        }
    }

    private function validatedId(mixed $previewId): string
    {
        if (!is_string($previewId) || preg_match(self::ID_PATTERN, $previewId) !== 1) {
            throw new ProductImportPreviewException('preview_not_found', 'El preview solicitado no existe.', 404);
        }
        return $previewId;
    }

    private function metadataPath(string $previewId): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . $previewId . '.json';
    }

    private function pathForRelative(string $relative, bool $mustExist): string
    {
        if (preg_match('#^uploads/[a-f0-9]{64}\.(?:csv|xlsx)$#', $relative) !== 1) {
            throw $this->corrupt();
        }
        $candidate = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $normalizedRoot = $this->normalized($this->root) . '/';
        $normalizedCandidate = $this->normalized($candidate);
        if (!str_starts_with($normalizedCandidate, $normalizedRoot)) {
            throw $this->corrupt();
        }
        if ($mustExist) {
            $real = realpath($candidate);
            if ($real === false || !str_starts_with($this->normalized($real), $normalizedRoot)) {
                throw new ProductImportPreviewException('preview_file_missing', 'El archivo temporal del preview ya no está disponible.', 410);
            }
            return $real;
        }
        return $candidate;
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->root, $this->root . '/metadata', $this->root . '/uploads'] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new ProductImportPreviewException('preview_storage_unavailable', 'No fue posible preparar el almacenamiento privado.');
            }
        }
    }

    private function corrupt(): ProductImportPreviewException
    {
        return new ProductImportPreviewException('preview_metadata_corrupt', 'El preview almacenado no es válido.', 422);
    }

    private function normalized(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
