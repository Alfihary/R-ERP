<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

final class ProductImportConfirmationService
{
    public const TTL_SECONDS = 600;

    private const ID_PATTERN = '/^[a-f0-9]{64}$/';

    public function __construct(
        private readonly ProductImportFileReader $reader,
        private readonly ProductImportBusinessValidator $validator,
        private readonly ProductImportPreviewStore $store,
    ) {
    }

    /** @return array<string, mixed> */
    public function confirm(string $previewId, int $userId, string $sessionBinding): array
    {
        $this->assertIdentity($userId, $sessionBinding);
        $this->assertPreviewId($previewId);
        try {
            $metadata = $this->store->readMetadata($previewId);
        } catch (ProductImportPreviewException $exception) {
            throw new ProductImportConfirmationException(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }
        $this->assertOwnership($metadata, $userId);
        $this->assertSession($metadata, $sessionBinding);
        $this->assertPreviewState($metadata);

        try {
            $sourcePath = $this->store->sourcePath($metadata);
        } catch (ProductImportPreviewException $exception) {
            throw new ProductImportConfirmationException(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        $sourceDigest = hash_file('sha256', $sourcePath);
        if (!is_string($sourceDigest)
            || !is_string($metadata['sha256'] ?? null)
            || !hash_equals($metadata['sha256'], $sourceDigest)
        ) {
            throw new ProductImportConfirmationException(
                'preview_digest_mismatch',
                'El archivo cambió después del preview y debe validarse nuevamente.',
                409,
            );
        }

        try {
            $read = $this->reader->read($sourcePath);
        } catch (ProductImportReadException $exception) {
            $error = $exception->error();
            throw new ProductImportConfirmationException($error->code, $error->message, 422);
        }

        $validation = $this->validator->validate($read);
        $currentResult = $validation->toArray();
        $currentPreview = $this->publicPreview($metadata, $currentResult);
        $resultDigest = $this->resultDigest($currentResult);
        $storedResult = is_array($metadata['result'] ?? null) ? $metadata['result'] : [];
        $storedResultDigest = $this->resultDigest($storedResult);
        $totalsChanged = (int) ($metadata['total_rows'] ?? -1) !== $validation->sourceRowCount
            || (int) ($metadata['valid_rows'] ?? -1) !== $validation->validRows()
            || (int) ($metadata['invalid_rows'] ?? -1) !== $validation->invalidRows();

        if ($validation->invalidRows() !== 0 || $validation->errors !== []) {
            throw new ProductImportConfirmationException(
                'confirmation_revalidation_failed',
                'Los datos cambiaron desde la validación inicial. Revisa los errores actuales.',
                409,
                $currentPreview,
            );
        }

        $createdAt = $this->store->now();
        $token = bin2hex(random_bytes(32));
        $confirmation = [
            'schema_version' => 1,
            'status' => 'CONFIRMATION_READY',
            'confirmation_token' => $token,
            'preview_id' => $previewId,
            'user_id' => $userId,
            'session_binding' => $sessionBinding,
            'source_sha256' => $sourceDigest,
            'result_sha256' => $resultDigest,
            'created_at' => $createdAt,
            'expires_at' => $createdAt + self::TTL_SECONDS,
            'used_at' => null,
            'total_rows' => $validation->sourceRowCount,
            'valid_rows' => $validation->validRows(),
            'invalid_rows' => $validation->invalidRows(),
            'preview_totals_changed' => $totalsChanged,
            'preview_result_changed' => !hash_equals($storedResultDigest, $resultDigest),
        ];
        $confirmation['metadata_sha256'] = $this->metadataDigest($confirmation);
        try {
            $this->store->replaceConfirmation($confirmation);
        } catch (ProductImportPreviewException $exception) {
            throw new ProductImportConfirmationException(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        return [
            'status' => 'CONFIRMATION_READY',
            'confirmation_token' => $token,
            'preview_id' => $previewId,
            'source_sha256' => $sourceDigest,
            'result_sha256' => $resultDigest,
            'created_at' => $createdAt,
            'expires_at' => $confirmation['expires_at'],
            'total_rows' => $validation->sourceRowCount,
            'valid_rows' => $validation->validRows(),
            'invalid_rows' => $validation->invalidRows(),
            'preview_totals_changed' => $totalsChanged,
            'preview_result_changed' => $confirmation['preview_result_changed'],
            'preview' => $currentPreview,
        ];
    }

    /** @return array<string, mixed> */
    public function inspect(string $token, int $userId, string $sessionBinding): array
    {
        $this->assertIdentity($userId, $sessionBinding);
        $this->assertConfirmationToken($token);

        try {
            $confirmation = $this->store->readConfirmation($token);
        } catch (ProductImportPreviewException $exception) {
            throw new ProductImportConfirmationException(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }
        if ((int) ($confirmation['user_id'] ?? 0) !== $userId) {
            throw new ProductImportConfirmationException(
                'confirmation_not_found',
                'La confirmación solicitada no existe.',
                404,
            );
        }
        $storedBinding = $confirmation['session_binding'] ?? null;
        if (!is_string($storedBinding) || !hash_equals($storedBinding, $sessionBinding)) {
            throw new ProductImportConfirmationException(
                'confirmation_session_mismatch',
                'La confirmación pertenece a otra sesión.',
                409,
            );
        }
        if ((int) ($confirmation['expires_at'] ?? 0) <= $this->store->now()) {
            throw new ProductImportConfirmationException(
                'confirmation_expired',
                'La confirmación expiró. Revalida el archivo.',
                410,
            );
        }
        if (($confirmation['used_at'] ?? null) !== null) {
            throw new ProductImportConfirmationException(
                'confirmation_already_used',
                'La confirmación ya fue utilizada.',
                409,
            );
        }
        return $confirmation;
    }

    private function assertPreviewId(string $previewId): void
    {
        if (preg_match(self::ID_PATTERN, $previewId) !== 1) {
            throw new ProductImportConfirmationException(
                'invalid_preview_id',
                'La referencia del preview no es válida.',
                422,
            );
        }
    }

    private function assertConfirmationToken(string $token): void
    {
        if (preg_match(self::ID_PATTERN, $token) !== 1) {
            throw new ProductImportConfirmationException(
                'invalid_confirmation_token',
                'La referencia de confirmación no es válida.',
                422,
            );
        }
    }

    private function assertIdentity(int $userId, string $sessionBinding): void
    {
        if ($userId < 1 || preg_match(self::ID_PATTERN, $sessionBinding) !== 1) {
            throw new ProductImportConfirmationException(
                'confirmation_identity_invalid',
                'No fue posible identificar al propietario de la confirmación.',
                403,
            );
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertOwnership(array $metadata, int $userId): void
    {
        if ((int) ($metadata['user_id'] ?? 0) !== $userId) {
            throw new ProductImportConfirmationException(
                'preview_not_found',
                'El preview solicitado no existe.',
                404,
            );
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertSession(array $metadata, string $sessionBinding): void
    {
        $storedBinding = $metadata['session_binding'] ?? null;
        if (!is_string($storedBinding) || !hash_equals($storedBinding, $sessionBinding)) {
            throw new ProductImportConfirmationException(
                'preview_session_mismatch',
                'El preview pertenece a otra sesión.',
                409,
            );
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertPreviewState(array $metadata): void
    {
        if ((int) ($metadata['expires_at'] ?? 0) <= $this->store->now()) {
            throw new ProductImportConfirmationException(
                'preview_expired',
                'El preview expiró. Valida el archivo nuevamente.',
                410,
            );
        }
        if (($metadata['used_at'] ?? null) !== null) {
            throw new ProductImportConfirmationException(
                'preview_already_used',
                'El preview ya fue utilizado.',
                409,
            );
        }
    }

    /** @param array<string, mixed> $metadata @param array<string, mixed> $result @return array<string, mixed> */
    private function publicPreview(array $metadata, array $result): array
    {
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $globalErrors = [];
        $rowErrors = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $safe = [
                'row' => is_int($error['row'] ?? null) ? $error['row'] : null,
                'field' => is_string($error['field'] ?? null) ? $error['field'] : null,
                'code' => is_string($error['code'] ?? null) ? $error['code'] : 'validation_error',
                'message' => is_string($error['message'] ?? null)
                    ? $error['message']
                    : 'El archivo contiene un error de validación.',
            ];
            if ($safe['row'] === null) {
                $globalErrors[] = $safe;
            } else {
                $rowErrors[] = $safe;
            }
        }
        $visibleRows = array_slice($rows, 0, ProductImportPreviewService::MAX_VISIBLE_ROWS);
        return [
            'preview_id' => (string) ($metadata['preview_id'] ?? ''),
            'original_name' => (string) ($metadata['original_name'] ?? ''),
            'sha256' => (string) ($metadata['sha256'] ?? ''),
            'created_at' => (int) ($metadata['created_at'] ?? 0),
            'expires_at' => (int) ($metadata['expires_at'] ?? 0),
            'format' => (string) ($metadata['format'] ?? ''),
            'total_rows' => (int) ($result['total_rows'] ?? 0),
            'valid_rows' => (int) ($result['valid_rows'] ?? 0),
            'invalid_rows' => (int) ($result['invalid_rows'] ?? 0),
            'status' => (int) ($result['invalid_rows'] ?? 0) === 0 ? 'ready' : 'errors',
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

    /** @param array<string, mixed> $result */
    private function resultDigest(array $result): string
    {
        $canonical = $this->canonicalize($result);
        return hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string, mixed> $confirmation */
    private function metadataDigest(array $confirmation): string
    {
        unset($confirmation['metadata_sha256']);
        return hash('sha256', json_encode(
            $this->canonicalize($confirmation),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
