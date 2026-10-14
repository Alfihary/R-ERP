<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

use Closure;
use App\Domain\Audit\AuditService;
use App\Domain\Products\ProductCreateCore;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\ProductImportRunRepository;
use PDOException;

final class ProductImportBatchService
{
    private const TOKEN_PATTERN = '/^[a-f0-9]{64}$/';

    public function __construct(
        private readonly ConnectionProvider $connection,
        private readonly ProductImportRunRepository $runs,
        private readonly ProductCreateCore $createCore,
        private readonly AuditService $audit,
        private readonly ProductImportConfirmationService $confirmations,
        private readonly ProductImportFileReader $reader,
        private readonly ProductImportBusinessValidator $validator,
        private readonly ProductImportPreviewStore $store,
        private readonly ?Closure $afterProductPersisted = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(string $token, int $userId, string $sessionBinding): array
    {
        $this->assertToken($token);
        $replay = $this->runs->findSucceededByTokenForUser($token, $userId);
        if ($replay !== null) {
            return $this->resultFromRun($replay, true);
        }

        $existing = $this->runs->findByConfirmationToken($token);
        if ($existing !== null && (int) $existing['usuario_id'] !== $userId) {
            throw new ProductImportBatchException(
                'confirmation_not_found',
                'La confirmación solicitada no existe.',
                404,
            );
        }

        try {
            $confirmation = $this->confirmations->inspect($token, $userId, $sessionBinding);
            $previewId = (string) $confirmation['preview_id'];
            $artifact = $this->store->readArtifact($previewId);
            $sourcePath = $this->store->sourcePath($artifact);
            $sourceDigest = hash_file('sha256', $sourcePath);
            if (!is_string($sourceDigest)
                || !hash_equals((string) $confirmation['source_sha256'], $sourceDigest)
            ) {
                throw new ProductImportBatchException(
                    'preview_digest_mismatch',
                    'El archivo cambió después de la confirmación.',
                    409,
                );
            }
            $read = $this->reader->read($sourcePath);
            $validation = $this->validator->validate($read);
            if ($validation->invalidRows() !== 0 || $validation->errors !== []) {
                throw new ProductImportBatchException(
                    'confirmation_revalidation_failed',
                    'Los datos cambiaron desde la confirmación.',
                    409,
                );
            }
        } catch (ProductImportBatchException $exception) {
            throw $exception;
        } catch (ProductImportConfirmationException|ProductImportPreviewException $exception) {
            throw new ProductImportBatchException(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        } catch (ProductImportReadException $exception) {
            $error = $exception->error();
            throw new ProductImportBatchException($error->code, $error->message, 422);
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            try {
                $runId = $this->runs->createProcessing([
                    'confirmation_token' => $token,
                    'preview_id' => $previewId,
                    'usuario_id' => $userId,
                    'source_sha256' => $sourceDigest,
                    'total_filas' => $validation->sourceRowCount,
                ]);
            } catch (PDOException $exception) {
                if (!$this->isDuplicate($exception)) {
                    throw $exception;
                }
                $pdo->rollBack();
                $race = $this->runs->findSucceededByTokenForUser($token, $userId);
                if ($race !== null) {
                    return $this->resultFromRun($race, true);
                }
                throw new ProductImportBatchException(
                    'import_conflict',
                    'La importación ya está siendo procesada.',
                    409,
                );
            }

            $created = 0;
            foreach ($validation->rows as $row) {
                $this->createCore->create(
                    $this->productData($row),
                    $this->taxIds($row),
                    $this->barcodes($row),
                    $userId,
                );
                ++$created;
                if ($this->afterProductPersisted !== null) {
                    ($this->afterProductPersisted)($created - 1, (string) $row->data['id_producto']);
                }
            }

            $this->audit->recordRequired(
                'productos.importacion.lote',
                $userId,
                [
                    'entidad' => 'producto_importacion',
                    'entidad_id' => (string) $runId,
                    'resultado' => 'EXITO',
                    'total_filas' => $validation->sourceRowCount,
                    'creados' => $created,
                ],
            );
            $this->runs->markSucceeded($runId, $created, [
                'total_rows' => $validation->sourceRowCount,
                'created_count' => $created,
                'failed_count' => 0,
                'status' => 'succeeded',
            ]);
            $pdo->commit();
        } catch (ProductImportBatchException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new ProductImportBatchException(
                $this->isDuplicate($exception) ? 'product_conflict_after_preview' : 'import_transaction_failed',
                $this->isDuplicate($exception)
                    ? 'Un producto o identificador entró en conflicto durante la importación.'
                    : 'No fue posible completar la importación.',
                $this->isDuplicate($exception) ? 409 : 422,
            );
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new ProductImportBatchException(
                'import_transaction_failed',
                'No fue posible completar la importación.',
                422,
            );
        }

        // Cleanup is best-effort after the durable commit. A storage failure
        // must never turn a completed import into an apparent failure.
        try {
            $this->store->discard($previewId);
        } catch (\Throwable) {
            // The durable run and audit record are the source of truth.
        }
        return [
            'status' => 'succeeded',
            'import_run_id' => $runId,
            'total_rows' => $validation->sourceRowCount,
            'created_count' => $created,
            'failed_count' => 0,
            'confirmation_reference' => 'import_run_' . $runId,
            'completed_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, int|string|null> */
    private function productData(ProductImportValidatedRow $row): array
    {
        $data = $row->data;
        $refs = $row->resolvedReferences;
        $id = static fn (string $field): ?int => isset($refs[$field]['id']) ? (int) $refs[$field]['id'] : null;
        return [
            'id_producto' => (string) $data['id_producto'],
            'descripcion' => (string) $data['descripcion'],
            'descripcion_larga' => $data['descripcion_larga'],
            'sku' => $data['sku'], 'sku_alterno' => $data['sku_alterno'],
            'upc' => $data['upc'], 'ean' => $data['ean'], 'gtin' => $data['gtin'],
            'codigo_fabricante' => $data['codigo_fabricante'], 'modelo' => $data['modelo'],
            'tipo_producto_id' => $id('tipo_producto_codigo'),
            'unidad_medida_id' => $id('unidad_medida_codigo'),
            'moneda_id' => $id('moneda_codigo'), 'linea_producto_id' => $id('linea_producto_codigo'),
            'marca_id' => $id('marca_codigo'), 'clasificacion_producto_id' => $id('clasificacion_producto_codigo'),
            'clave_sat_id' => $id('clave_sat_codigo'), 'unidad_sat_id' => $id('unidad_sat_codigo'),
            'peso_kg' => $data['peso_kg'], 'largo_cm' => $data['largo_cm'],
            'ancho_cm' => $data['ancho_cm'], 'alto_cm' => $data['alto_cm'],
            'controla_series' => (int) $data['controla_series'],
            'controla_lotes' => (int) $data['controla_lotes'],
            'controla_pedimentos' => (int) $data['controla_pedimentos'],
        ];
    }

    /** @return list<int> */
    private function taxIds(ProductImportValidatedRow $row): array
    {
        return array_values(array_map(
            static fn (array $item): int => (int) $item['id'],
            $row->resolvedReferences['impuestos_codigos'] ?? [],
        ));
    }

    /** @return list<string> */
    private function barcodes(ProductImportValidatedRow $row): array
    {
        return array_values(array_map('strval', (array) ($row->data['codigos_barras'] ?? [])));
    }

    /** @param array<string, mixed> $run @return array<string, mixed> */
    private function resultFromRun(array $run, bool $replay): array
    {
        $summary = json_decode((string) $run['resultado_resumen_json'], true);
        $summary = is_array($summary) ? $summary : [];
        return [
            'status' => $replay ? 'already_processed' : 'succeeded',
            'import_run_id' => (int) $run['id'],
            'total_rows' => (int) ($summary['total_rows'] ?? $run['total_filas']),
            'created_count' => (int) ($summary['created_count'] ?? $run['creados']),
            'failed_count' => 0,
            'confirmation_reference' => 'import_run_' . (int) $run['id'],
            'completed_at' => (string) ($run['completado_en'] ?? ''),
        ];
    }

    private function assertToken(string $token): void
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new ProductImportBatchException('invalid_confirmation_token', 'La confirmación solicitada no existe.', 404);
        }
    }

    private function isDuplicate(PDOException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
