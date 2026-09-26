<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportPreviewService
{
    public const TTL_SECONDS = 1800;
    public const MAX_VISIBLE_ROWS = 20;

    public function __construct(
        private readonly ProductImportFileReader $reader,
        private readonly ProductImportBusinessValidator $validator,
        private readonly ProductImportPreviewStore $store,
    ) {
    }

    /**
     * @param array<string, mixed>|null $upload
     * @return array<string, mixed>
     */
    public function create(
        ?array $upload,
        int $userId,
        string $sessionBinding,
    ): array {
        $this->assertIdentity($userId, $sessionBinding);
        $this->store->cleanupExpired();
        $stored = $this->store->storeUpload($upload);

        try {
            $read = $this->reader->read($stored->absolutePath);
            $validation = $this->validator->validate($read);
            $createdAt = $this->store->now();
            $artifact = [
                'schema_version' => 1,
                'preview_id' => $stored->previewId,
                'user_id' => $userId,
                'session_binding' => $sessionBinding,
                'original_name' => $stored->originalName,
                'stored_file' => $stored->relativePath,
                'sha256' => $stored->sha256,
                'created_at' => $createdAt,
                'expires_at' => $createdAt + self::TTL_SECONDS,
                'used_at' => null,
                'format' => $read->format,
                'total_rows' => $validation->sourceRowCount,
                'valid_rows' => $validation->validRows(),
                'invalid_rows' => $validation->invalidRows(),
                'result' => $validation->toArray(),
            ];
            $this->store->writeArtifact($artifact);
            return $this->publicPreview($artifact);
        } catch (ProductImportReadException $exception) {
            $this->store->discardStoredUpload($stored);
            $error = $exception->error();
            throw new ProductImportPreviewException(
                $error->code,
                $error->message,
                422,
            );
        } catch (\Throwable $exception) {
            $this->store->discardStoredUpload($stored);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function get(
        string $previewId,
        int $userId,
        string $sessionBinding,
    ): array {
        $this->assertIdentity($userId, $sessionBinding);
        $artifact = $this->store->readArtifact($previewId);
        $this->assertOwnership($artifact, $userId, $sessionBinding);

        if ((int) ($artifact['expires_at'] ?? 0) < $this->store->now()) {
            $this->store->discard($previewId);
            throw new ProductImportPreviewException(
                'preview_expired',
                'El preview expiró. Valida el archivo nuevamente.',
                410,
            );
        }
        if (($artifact['used_at'] ?? null) !== null) {
            throw new ProductImportPreviewException(
                'preview_already_used',
                'El preview ya fue utilizado.',
                409,
            );
        }

        return $this->publicPreview($artifact);
    }

    public function discard(
        string $previewId,
        int $userId,
        string $sessionBinding,
    ): bool {
        $this->assertIdentity($userId, $sessionBinding);

        try {
            $artifact = $this->store->readArtifact($previewId);
        } catch (ProductImportPreviewException $exception) {
            if ($exception->errorCode === 'preview_not_found') {
                return false;
            }
            throw $exception;
        }

        $this->assertOwnership($artifact, $userId, $sessionBinding);
        return $this->store->discard($previewId);
    }

    public function cleanupExpired(): int
    {
        return $this->store->cleanupExpired();
    }

    /** @param array<string, mixed> $artifact @return array<string, mixed> */
    private function publicPreview(array $artifact): array
    {
        $result = $artifact['result'] ?? null;
        if (!is_array($result) || !is_array($result['rows'] ?? null) || !is_array($result['errors'] ?? null)) {
            throw new ProductImportPreviewException(
                'preview_metadata_corrupt',
                'El preview almacenado no es válido.',
                422,
            );
        }
        $visibleRows = array_slice($result['rows'], 0, self::MAX_VISIBLE_ROWS);
        $globalErrors = [];
        $rowErrors = [];
        foreach ($result['errors'] as $error) {
            if (!is_array($error)) {
                continue;
            }
            if (($error['row'] ?? null) === null) {
                $globalErrors[] = $this->safeError($error);
            } else {
                $rowErrors[] = $this->safeError($error);
            }
        }

        return [
            'preview_id' => (string) ($artifact['preview_id'] ?? ''),
            'original_name' => (string) ($artifact['original_name'] ?? ''),
            'sha256' => (string) ($artifact['sha256'] ?? ''),
            'created_at' => (int) ($artifact['created_at'] ?? 0),
            'expires_at' => (int) ($artifact['expires_at'] ?? 0),
            'format' => (string) ($artifact['format'] ?? ''),
            'total_rows' => (int) ($artifact['total_rows'] ?? 0),
            'valid_rows' => (int) ($artifact['valid_rows'] ?? 0),
            'invalid_rows' => (int) ($artifact['invalid_rows'] ?? 0),
            'status' => (int) ($artifact['invalid_rows'] ?? 0) === 0
                ? 'ready'
                : 'errors',
            'visible_rows' => array_map([$this, 'safeRow'], $visibleRows),
            'visible_rows_count' => count($visibleRows),
            'global_errors' => $globalErrors,
            'row_errors' => $rowErrors,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function safeRow(array $row): array
    {
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];
        return [
            'row_number' => (int) ($row['row_number'] ?? 0),
            'status' => ($row['status'] ?? '') === 'valid' ? 'valid' : 'invalid',
            'id_producto' => (string) ($data['id_producto'] ?? ''),
            'descripcion' => (string) ($data['descripcion'] ?? ''),
            'tipo_producto_codigo' => (string) ($data['tipo_producto_codigo'] ?? ''),
            'unidad_medida_codigo' => (string) ($data['unidad_medida_codigo'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $error @return array<string, mixed> */
    private function safeError(array $error): array
    {
        return [
            'row' => is_int($error['row'] ?? null) ? $error['row'] : null,
            'field' => is_string($error['field'] ?? null) ? $error['field'] : null,
            'code' => is_string($error['code'] ?? null) ? $error['code'] : 'validation_error',
            'message' => is_string($error['message'] ?? null)
                ? $error['message']
                : 'El archivo contiene un error de validación.',
        ];
    }

    private function assertIdentity(int $userId, string $sessionBinding): void
    {
        if ($userId < 1 || preg_match('/^[a-f0-9]{64}$/', $sessionBinding) !== 1) {
            throw new ProductImportPreviewException(
                'preview_identity_invalid',
                'No fue posible identificar al propietario del preview.',
                403,
            );
        }
    }

    /** @param array<string, mixed> $artifact */
    private function assertOwnership(array $artifact, int $userId, string $sessionBinding): void
    {
        if (
            (int) ($artifact['user_id'] ?? 0) !== $userId
            || !is_string($artifact['session_binding'] ?? null)
            || !hash_equals($artifact['session_binding'], $sessionBinding)
        ) {
            throw new ProductImportPreviewException(
                'preview_not_found',
                'El preview solicitado no existe.',
                404,
            );
        }
    }
}
