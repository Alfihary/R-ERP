<?php

declare(strict_types=1);

namespace App\Domain\Folios;

use App\Infrastructure\Repositories\FolioRepository;
use PDOException;
use Throwable;

final class FolioService
{
    private const SUPPORTED_FORMAT = '{PREFIJO}-{ALMACEN}{NUMERO}';

    public function __construct(private readonly FolioRepository $folios)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function emitir(array $input): array
    {
        $empresaId = $this->requiredId($input, 'empresa_id', 'Empresa requerida.');
        $almacenId = $this->requiredId($input, 'almacen_id', 'Almacén requerido.');
        $tipoDocumento = $this->requiredText(
            $input,
            'tipo_documento',
            'Tipo de documento requerido.'
        );
        $codigoSerie = $this->requiredText(
            $input,
            'codigo_serie',
            'Código de serie requerido.'
        );
        $creatorId = $this->optionalId($input, 'creado_por_usuario_id');

        try {
            $this->folios->beginTransaction();

            if (!$this->folios->warehouseBelongsToCompany($empresaId, $almacenId)) {
                throw new FolioValidationException(
                    'El almacén no pertenece a la empresa indicada.'
                );
            }

            if (
                $creatorId !== null
                && !$this->folios->userExists($creatorId)
            ) {
                throw new FolioValidationException('Usuario creador inválido.');
            }

            $series = $this->folios->findActiveSeriesForUpdate(
                $empresaId,
                $almacenId,
                $tipoDocumento,
                $codigoSerie
            );

            if ($series === null) {
                throw new FolioValidationException(
                    'Serie documental no encontrada.'
                );
            }

            if ((int) $series['activo'] !== 1) {
                throw new FolioValidationException('Serie documental inactiva.');
            }

            if ($series['eliminado_en'] !== null) {
                throw new FolioValidationException(
                    'Serie documental no encontrada.'
                );
            }

            if ((string) $series['formato'] !== self::SUPPORTED_FORMAT) {
                throw new FolioValidationException(
                    'Formato de folio no soportado.'
                );
            }

            if (!empty($input['__simulate_failure_after_lock'])) {
                throw new FolioValidationException(
                    'No fue posible emitir el folio.'
                );
            }

            $year = null;
            $currentYear = (int) date('Y');
            $number = (int) $series['siguiente_numero'];
            $nextNumber = $number + 1;
            $yearToPersist = $series['anio_actual'] === null
                ? null
                : (int) $series['anio_actual'];

            if ((int) $series['reinicio_anual'] === 1) {
                $year = $currentYear;
                $yearToPersist = $currentYear;

                if (
                    $series['anio_actual'] === null
                    || (int) $series['anio_actual'] !== $currentYear
                ) {
                    $number = 1;
                    $nextNumber = 2;
                }
            }

            $folio = $this->formatFolio(
                (string) $series['prefijo'],
                (string) $series['codigo_almacen_snapshot'],
                $number,
                (int) $series['longitud']
            );

            $folioId = $this->folios->insertDocumentoFolio([
                'serie_documental_id' => (int) $series['id'],
                'empresa_id' => $empresaId,
                'almacen_id' => $almacenId,
                'tipo_documento' => $tipoDocumento,
                'codigo_serie' => $codigoSerie,
                'prefijo_documento' => (string) $series['prefijo'],
                'codigo_almacen_snapshot' =>
                    (string) $series['codigo_almacen_snapshot'],
                'formato' => (string) $series['formato'],
                'folio' => $folio,
                'numero' => $number,
                'anio' => $year,
                'documento_tipo_origen' =>
                    $this->optionalText($input, 'documento_tipo_origen'),
                'documento_id_origen' =>
                    $this->optionalId($input, 'documento_id_origen'),
                'referencia_externa' =>
                    $this->optionalText($input, 'referencia_externa'),
                'creado_por_usuario_id' => $creatorId,
            ]);

            $this->folios->updateSerieNextNumber(
                (int) $series['id'],
                $nextNumber,
                $yearToPersist
            );

            $created = $this->folios->findFolioById($folioId);

            if ($created === null) {
                throw new FolioValidationException(
                    'No fue posible emitir el folio.'
                );
            }

            $this->folios->commit();

            return $created;
        } catch (FolioValidationException $exception) {
            $this->folios->rollBack();
            throw $exception;
        } catch (PDOException) {
            $this->folios->rollBack();
            throw new FolioValidationException(
                'No fue posible emitir el folio.'
            );
        } catch (Throwable) {
            $this->folios->rollBack();
            throw new FolioValidationException(
                'No fue posible emitir el folio.'
            );
        }
    }

    private function formatFolio(
        string $prefix,
        string $warehouseCode,
        int $number,
        int $length
    ): string {
        return $prefix
            . '-'
            . $warehouseCode
            . str_pad((string) $number, $length, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requiredId(
        array $input,
        string $key,
        string $message
    ): int {
        $value = $input[$key] ?? null;

        if (
            !is_int($value)
            && !(is_string($value) && ctype_digit($value))
        ) {
            throw new FolioValidationException($message);
        }

        $id = (int) $value;

        if ($id < 1) {
            throw new FolioValidationException($message);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function optionalId(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (
            !is_int($value)
            && !(is_string($value) && ctype_digit($value))
        ) {
            throw new FolioValidationException('Identificador inválido.');
        }

        $id = (int) $value;

        if ($id < 1) {
            throw new FolioValidationException('Identificador inválido.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requiredText(
        array $input,
        string $key,
        string $message
    ): string {
        $value = trim((string) ($input[$key] ?? ''));

        if ($value === '') {
            throw new FolioValidationException($message);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function optionalText(array $input, string $key): ?string
    {
        $value = trim((string) ($input[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
