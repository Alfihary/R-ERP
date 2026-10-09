<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Folios\FolioService;
use App\Domain\Folios\FolioValidationException;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\InventoryIdempotencyRepository;
use App\Infrastructure\Repositories\InventoryRepository;

final class InventoryService
{
    private const MAX_INTEGER_DIGITS = 12;
    private const SCALE = 6;

    private const MISSING_SERIES_MESSAGE =
        'No existe una serie documental activa para este almacén y tipo de operación.';

    public function __construct(
        private readonly InventoryRepository $inventory,
        private readonly ?FolioService $folios = null,
        private readonly ?InventoryIdempotencyRepository $idempotency = null,
        private readonly ?AuditRepository $audit = null,
        private readonly ?InventoryMutationRepositoryInterface $mutations = null,
        private readonly ?TransactionBoundaryInterface $transactions = null
    )
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function aplicarMovimiento(array $input): array
    {
        $request = $this->validateRequestShape($input);

        return $this->transactionBoundary()->transactional(function () use ($request): array {
            $this->assertUser($request['usuario_id']);
            $this->assertScope($request['empresa_id'], $request['almacen_id']);
            $concept = $this->assertConcept($request['concepto_codigo']);
            $parts = $this->assertProducts($request['partidas']);
            $idempotency = $this->reserveIdempotency(
                'movimiento:' . $request['empresa_id'],
                $request['idempotency_key'],
                $this->fingerprint($request)
            );
            if ($idempotency['replay'] !== null) {
                return $idempotency['replay'];
            }
            $folio = $this->emitMovementFolio($request);

            if (!empty($request['simulate_failure_after_folio'])) {
                throw new InventoryValidationException([
                    'folio' => 'Falla simulada después de emitir folio.',
                ]);
            }

            $movementId = $this->mutationRepository()->createDraftMovement(
                $request['empresa_id'],
                $request['almacen_id'],
                $concept['id'],
                $request['fecha_movimiento'],
                $request['referencia'],
                $request['observaciones'],
                $request['usuario_id'],
                $folio === null ? null : (int) $folio['folio_id'],
                $folio === null ? null : (string) $folio['folio']
            );

            $detailIds = [];

            foreach ($parts as $part) {
                $detailIds[$part['id_producto']] = $this->mutationRepository()->insertMovementDetail(
                    $movementId,
                    $part['id_producto'],
                    $part['cantidad'],
                    $part['observaciones'],
                    $request['usuario_id']
                );
            }

            $appliedParts = [];

            foreach ($parts as $part) {
                if ($concept['naturaleza'] === 'ENTRADA') {
                    $this->mutationRepository()->ensureExistenceRow(
                        $request['almacen_id'],
                        $part['id_producto']
                    );
                }

                $locked = $this->inventory->lockExistence(
                    $request['almacen_id'],
                    $part['id_producto']
                );
                $previous = $locked['cantidad_actual'] ?? '0.000000';

                if (
                    $concept['naturaleza'] === 'SALIDA'
                    && $this->compareDecimals($previous, $part['cantidad']) < 0
                ) {
                    throw new InventoryValidationException([
                        'existencia' =>
                            'La salida no puede dejar existencia negativa.',
                    ]);
                }

                if ((int) $part['controla_series'] === 1) {
                    if ($concept['naturaleza'] === 'ENTRADA') {
                        $this->applySeriesEntry(
                            $part,
                            $detailIds[$part['id_producto']],
                            $request['almacen_id']
                        );
                    } else {
                        $this->applySeriesExit(
                            $part,
                            $detailIds[$part['id_producto']],
                            $request['almacen_id']
                        );
                    }
                }

                if ($concept['naturaleza'] === 'ENTRADA') {
                    $this->mutationRepository()->increaseExistence(
                        $request['almacen_id'],
                        $part['id_producto'],
                        $part['cantidad']
                    );
                } else {
                    $this->mutationRepository()->decreaseExistence(
                        $request['almacen_id'],
                        $part['id_producto'],
                        $part['cantidad']
                    );
                }

                $updated = $this->inventory->lockExistence(
                    $request['almacen_id'],
                    $part['id_producto']
                );

                $appliedParts[] = [
                    'id_producto' => $part['id_producto'],
                    'cantidad' => $part['cantidad'],
                    'saldo_anterior' => $previous,
                    'saldo_nuevo' => $updated['cantidad_actual'] ?? '0.000000',
                    'series_aplicadas' => count($part['series']),
                ];
            }

            $this->mutationRepository()->markMovementApplied(
                $movementId,
                $request['usuario_id']
            );
            $movement = $this->inventory->movementResult($movementId);
            $result = [
                'movimiento_id' => (int) $movement['id'],
                'estado' => (string) $movement['estado'],
                'empresa_id' => (int) $movement['empresa_id'],
                'almacen_id' => (int) $movement['almacen_id'],
                'concepto_codigo' => (string) $movement['concepto_codigo'],
                'naturaleza' => (string) $movement['naturaleza'],
                'fecha_movimiento' => (string) $movement['fecha_movimiento'],
                'folio_id' => $movement['folio_id'] === null
                    ? null
                    : (int) $movement['folio_id'],
                'folio' => $movement['folio'] === null
                    ? null
                    : (string) $movement['folio'],
                'referencia' => $movement['referencia'] === null
                    ? null
                    : (string) $movement['referencia'],
                'partidas_aplicadas' => $appliedParts,
            ];
            $this->recordAudit(
                $request,
                $movement,
                $appliedParts
            );
            $this->completeIdempotency($idempotency['id'], $result);

            return $result;
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     empresa_id: int,
     *     almacen_id: int,
     *     concepto_codigo: string,
     *     fecha_movimiento: string,
     *     referencia: string|null,
     *     folio: array{tipo_documento: string, codigo_serie: string}|null,
     *     simulate_failure_after_folio: bool,
     *     observaciones: string|null,
     *     usuario_id: int,
     *     idempotency_key: string,
     *     partidas: list<array{
     *         id_producto: string,
     *         cantidad: string,
     *         observaciones: string|null,
     *         series: list<string>
     *     }>
     * }
     */
    private function validateRequestShape(array $input): array
    {
        $errors = [];
        $companyId = $this->positiveId($input['empresa_id'] ?? null);
        $warehouseId = $this->positiveId($input['almacen_id'] ?? null);
        $actorId = $this->positiveId($input['usuario_id'] ?? null);
        $conceptCode = strtoupper($this->text($input, 'concepto_codigo'));
        $movementDate = $this->movementDate($input['fecha_movimiento'] ?? null);
        $reference = $this->nullableText($input, 'referencia', 100);
        $folio = $this->folioInput($input['folio'] ?? null);
        $notes = $this->nullableText($input, 'observaciones', 500);

        if ($companyId === null) {
            $errors['empresa_id'] = 'La empresa es obligatoria.';
        }
        if ($warehouseId === null) {
            $errors['almacen_id'] = 'El almacén es obligatorio.';
        }
        if ($actorId === null) {
            $errors['usuario_id'] = 'El usuario es obligatorio.';
        }
        if (preg_match('/^[A-Z0-9_]{1,32}$/', $conceptCode) !== 1) {
            $errors['concepto_codigo'] = 'El concepto no es válido.';
        }
        if ($movementDate === null) {
            $errors['fecha_movimiento'] =
                'La fecha debe tener formato YYYY-MM-DD HH:MM:SS.';
        }
        if ($reference === false) {
            $errors['referencia'] = 'La referencia admite hasta 100 caracteres.';
            $reference = null;
        }
        if ($folio === false) {
            $errors['folio'] = 'La serie documental del folio no es válida.';
            $folio = null;
        }
        if ($notes === false) {
            $errors['observaciones'] =
                'Las observaciones admiten hasta 500 caracteres.';
            $notes = null;
        }

        $rawParts = $input['partidas'] ?? null;
        $parts = [];

        if (!is_array($rawParts) || $rawParts === []) {
            $errors['partidas'] = 'Agrega al menos una partida.';
        } else {
            $seen = [];

            foreach (array_values($rawParts) as $index => $rawPart) {
                if (!is_array($rawPart)) {
                    $errors['partidas.' . $index] = 'La partida no es válida.';
                    continue;
                }

                $productId = strtoupper($this->text($rawPart, 'id_producto'));
                $quantity = $this->quantity($rawPart['cantidad'] ?? null);
                $partNotes = $this->nullableText($rawPart, 'observaciones', 500);
                $series = $this->seriesList($rawPart['series'] ?? null);

                if (preg_match('/^[A-Z0-9]{1,16}$/', $productId) !== 1) {
                    $errors['partidas.' . $index . '.id_producto'] =
                        'El producto no es válido.';
                } elseif (isset($seen[$productId])) {
                    $errors['partidas.' . $index . '.id_producto'] =
                        'No repitas productos en el movimiento.';
                }

                if ($quantity === null) {
                    $errors['partidas.' . $index . '.cantidad'] =
                        'La cantidad debe ser decimal positiva con hasta 6 decimales.';
                }
                if ($partNotes === false) {
                    $errors['partidas.' . $index . '.observaciones'] =
                        'Las observaciones de partida admiten hasta 500 caracteres.';
                    $partNotes = null;
                }
                if ($series === false) {
                    $errors['partidas.' . $index . '.series'] =
                        'Las series deben ser una lista de textos de 1 a 80 caracteres.';
                    $series = [];
                }

                if ($productId !== '') {
                    $seen[$productId] = true;
                }

                if (
                    preg_match('/^[A-Z0-9]{1,16}$/', $productId) === 1
                    && $quantity !== null
                ) {
                    $parts[] = [
                        'id_producto' => $productId,
                        'cantidad' => $quantity,
                        'observaciones' => $partNotes,
                        'series' => $series,
                    ];
                }
            }
        }

        if ($errors !== []) {
            throw new InventoryValidationException($errors);
        }

        usort(
            $parts,
            static fn (array $a, array $b): int =>
                strcmp($a['id_producto'], $b['id_producto'])
        );

        $idempotencyKey = $this->idempotencyKey($input['idempotency_key'] ?? null);
        if ($idempotencyKey === null) {
            $errors['idempotency_key'] = 'La clave de idempotencia no es válida.';
        }

        return [
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'concepto_codigo' => $conceptCode,
            'fecha_movimiento' => $movementDate,
            'referencia' => $reference,
            'folio' => $folio,
            'simulate_failure_after_folio' =>
                !empty($input['__simulate_failure_after_folio']),
            'observaciones' => $notes,
            'usuario_id' => $actorId,
            'idempotency_key' => $idempotencyKey ?? '',
            'partidas' => $parts,
        ];
    }

    /** @return array{id: int, replay: array<string, mixed>|null} */
    private function reserveIdempotency(string $scope, string $key, string $hash): array
    {
        if ($this->idempotency === null) {
            return ['id' => 0, 'replay' => null];
        }
        $row = $this->idempotency->reserve($scope, $key, $hash);
        if ($row['estado'] === 'COMPLETADA' && $row['resultado_json'] !== null) {
            $decoded = json_decode($row['resultado_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Resultado idempotente inválido.');
            }
            return ['id' => $row['id'], 'replay' => $decoded];
        }
        return ['id' => $row['id'], 'replay' => null];
    }

    /** @param array<string, mixed> $result */
    private function completeIdempotency(int $id, array $result): void
    {
        if ($this->idempotency !== null) {
            $this->idempotency->complete($id, $result);
        }
    }

    /** @param array<string, mixed> $request */
    private function fingerprint(array $request): string
    {
        unset($request['idempotency_key']);
        return hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function idempotencyKey(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return $this->idempotency === null ? bin2hex(random_bytes(16)) : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $request @param array<string, mixed> $movement @param array<int, mixed> $parts */
    private function recordAudit(array $request, array $movement, array $parts): void
    {
        if ($this->audit === null) {
            return;
        }
        $this->audit->insertRequired(
            (int) $request['usuario_id'],
            'inventario.movimiento.creado',
            'movimientos_inventario',
            (string) $movement['id'],
            'ok',
            null,
            null,
            [
                'empresa_id' => $request['empresa_id'],
                'almacen_id' => $request['almacen_id'],
                'concepto_codigo' => $movement['concepto_codigo'],
                'referencia' => $request['referencia'],
                'idempotency_key' => $request['idempotency_key'],
                'partidas' => $parts,
            ]
        );
    }

    private function assertUser(int $userId): void
    {
        if (!$this->inventory->activeUserExists($userId)) {
            throw new InventoryValidationException([
                'usuario_id' => 'El usuario no existe o no está activo.',
            ]);
        }
    }

    private function assertScope(int $companyId, int $warehouseId): void
    {
        if ($this->inventory->activeCompany($companyId) === null) {
            throw new InventoryValidationException([
                'empresa_id' => 'La empresa no existe o no está activa.',
            ]);
        }

        if (
            $this->inventory->activeWarehouseForCompany(
                $companyId,
                $warehouseId
            ) === null
        ) {
            throw new InventoryValidationException([
                'almacen_id' =>
                    'El almacén no existe, no está activo o no pertenece a la empresa.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function emitMovementFolio(array $request): ?array
    {
        if ($this->folios === null) {
            return null;
        }

        $folioInput = $request['folio'] ?? null;

        if ($folioInput === null) {
            if (!in_array(
                $request['concepto_codigo'],
                ['ENTRADA_AJUSTE', 'SALIDA_AJUSTE'],
                true
            )) {
                return null;
            }

            $folioInput = [
                'tipo_documento' => 'AJUSTE_INVENTARIO',
                'codigo_serie' => 'AJ',
            ];
        }

        try {
            return $this->folios->emitir([
                'empresa_id' => $request['empresa_id'],
                'almacen_id' => $request['almacen_id'],
                'tipo_documento' => $folioInput['tipo_documento'],
                'codigo_serie' => $folioInput['codigo_serie'],
                'documento_tipo_origen' => 'MOVIMIENTO_INVENTARIO',
                'documento_id_origen' => null,
                'referencia_externa' => $request['referencia'],
                'creado_por_usuario_id' => $request['usuario_id'],
            ]);
        } catch (FolioValidationException) {
            throw new InventoryValidationException([
                'folio' => self::MISSING_SERIES_MESSAGE,
            ]);
        }
    }

    /**
     * @return array{id: int, codigo: string, naturaleza: string}
     */
    private function assertConcept(string $code): array
    {
        $concept = $this->inventory->activeConceptByCode($code);

        if ($concept === null) {
            throw new InventoryValidationException([
                'concepto_codigo' => 'El concepto no existe o no está activo.',
            ]);
        }
        if (!in_array($concept['naturaleza'], ['ENTRADA', 'SALIDA'], true)) {
            throw new InventoryValidationException([
                'concepto_codigo' => 'La naturaleza del concepto no es válida.',
            ]);
        }

        return $concept;
    }

    /**
     * @param list<array{id_producto: string, cantidad: string, observaciones: string|null, series: list<string>}> $parts
     * @return list<array{id_producto: string, cantidad: string, observaciones: string|null, series: list<string>, controla_series: int}>
     */
    private function assertProducts(array $parts): array
    {
        $ids = array_column($parts, 'id_producto');
        $products = $this->inventory->activeProductsByIds($ids);
        $validated = [];

        foreach ($parts as $part) {
            $product = $products[$part['id_producto']] ?? null;

            if ($product === null) {
                throw new InventoryValidationException([
                    'id_producto' =>
                        'Un producto no existe o su tipo no está activo.',
                ]);
            }
            if ((int) $product['activo'] !== 1) {
                throw new InventoryValidationException([
                    'id_producto' => 'Un producto no está activo.',
                ]);
            }
            if ($product['tipo_codigo'] === 'SERVICIO') {
                throw new InventoryValidationException([
                    'id_producto' =>
                        'Un servicio no participa en movimientos de inventario.',
                ]);
            }
            if (!in_array($product['tipo_codigo'], ['PRODUCTO', 'KIT'], true)) {
                throw new InventoryValidationException([
                    'id_producto' => 'El tipo de producto no es inventariable.',
                ]);
            }

            $tracksSeries = (int) $product['controla_series'];

            if ($tracksSeries === 1) {
                if (!$this->isIntegerQuantity($part['cantidad'])) {
                    throw new InventoryValidationException([
                        'cantidad' =>
                            'Los productos con series requieren cantidad entera positiva.',
                    ]);
                }

                if ($part['series'] === []) {
                    throw new InventoryValidationException([
                        'series' =>
                            'Los productos con series requieren números de serie.',
                    ]);
                }

                if (count($part['series']) !== $this->quantityAsInteger($part['cantidad'])) {
                    throw new InventoryValidationException([
                        'series' =>
                            'La cantidad debe coincidir con el número de series.',
                    ]);
                }

                if (count($part['series']) !== count(array_unique($part['series']))) {
                    throw new InventoryValidationException([
                        'series' =>
                            'No repitas números de serie en la misma partida.',
                    ]);
                }
            } elseif ($part['series'] !== []) {
                throw new InventoryValidationException([
                    'series' =>
                        'Los productos sin control de series no aceptan números de serie.',
                ]);
            }

            $part['controla_series'] = $tracksSeries;
            $validated[] = $part;
        }

        return $validated;
    }

    /**
     * @param array{id_producto: string, series: list<string>} $part
     */
    private function applySeriesEntry(
        array $part,
        int $detailId,
        int $warehouseId
    ): void {
        $seriesByNumber = $this->inventory->activeSeriesByNumbers(
            $part['id_producto'],
            $part['series']
        );
        $seriesIds = [];

        foreach ($part['series'] as $number) {
            $series = $seriesByNumber[$number] ?? null;

            if ($series === null) {
                $seriesIds[$number] = $this->inventory->createSeries(
                    $part['id_producto'],
                    $number
                );
                continue;
            }

            if ((int) $series['activo'] !== 1) {
                throw new InventoryValidationException([
                    'series' => 'Una serie no está activa.',
                ]);
            }

            $seriesIds[$number] = (int) $series['id'];
        }

        $lockedStocks = $this->inventory->lockSeriesStocks(
            array_values($seriesIds)
        );

        foreach ($part['series'] as $number) {
            $seriesId = $seriesIds[$number];
            $stock = $lockedStocks[$seriesId] ?? null;

            if (
                $stock !== null
                && $stock['estado'] === 'EN_EXISTENCIA'
            ) {
                throw new InventoryValidationException([
                    'series' =>
                        'No se puede ingresar una serie que ya está en existencia.',
                ]);
            }

            $this->mutationRepository()->insertMovementDetailSeries($detailId, $seriesId);
            $this->mutationRepository()->saveSeriesStock(
                $seriesId,
                $warehouseId,
                'EN_EXISTENCIA'
            );
        }
    }

    /**
     * @param array{id_producto: string, series: list<string>} $part
     */
    private function applySeriesExit(
        array $part,
        int $detailId,
        int $warehouseId
    ): void {
        $seriesByNumber = $this->inventory->activeSeriesByNumbers(
            $part['id_producto'],
            $part['series']
        );
        $seriesIds = [];

        foreach ($part['series'] as $number) {
            $series = $seriesByNumber[$number] ?? null;

            if ($series === null || (int) $series['activo'] !== 1) {
                throw new InventoryValidationException([
                    'series' =>
                        'Una serie no existe, no pertenece al producto o no está activa.',
                ]);
            }

            $seriesIds[$number] = (int) $series['id'];
        }

        $lockedStocks = $this->inventory->lockSeriesStocks(
            array_values($seriesIds)
        );

        foreach ($part['series'] as $number) {
            $seriesId = $seriesIds[$number];
            $stock = $lockedStocks[$seriesId] ?? null;

            if (
                $stock === null
                || $stock['estado'] !== 'EN_EXISTENCIA'
                || $stock['almacen_id'] !== $warehouseId
            ) {
                throw new InventoryValidationException([
                    'series' =>
                        'La serie no está disponible en el almacén del movimiento.',
                ]);
            }

            $this->mutationRepository()->insertMovementDetailSeries($detailId, $seriesId);
            $this->mutationRepository()->saveSeriesStock(
                $seriesId,
                null,
                'FUERA_EXISTENCIA'
            );
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    private function positiveId(mixed $value): ?int
    {
        if (
            (!is_string($value) && !is_int($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
        ) {
            return null;
        }

        return (int) $value;
    }

    private function movementDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return date('Y-m-d H:i:s');
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        if (
            !$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d H:i:s') !== $value
        ) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @return string|null|false
     */
    private function nullableText(array $input, string $key, int $max): string|null|false
    {
        $value = $this->text($input, $key);

        if ($value === '') {
            return null;
        }

        return $this->length($value) <= $max ? $value : false;
    }

    /**
     * @return array{tipo_documento: string, codigo_serie: string}|null|false
     */
    private function folioInput(mixed $value): array|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_array($value)) {
            return false;
        }

        $type = strtoupper($this->text($value, 'tipo_documento'));
        $series = strtoupper($this->text($value, 'codigo_serie'));

        if (
            preg_match('/^[A-Z0-9_]{1,60}$/', $type) !== 1
            || preg_match('/^[A-Z0-9_]{1,20}$/', $series) !== 1
        ) {
            return false;
        }

        return ['tipo_documento' => $type, 'codigo_serie' => $series];
    }

    /**
     * @return list<string>|false
     */
    private function seriesList(mixed $value): array|false
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            return false;
        }

        $series = [];

        foreach (array_values($value) as $item) {
            if (!is_string($item) && !is_int($item)) {
                return false;
            }

            $number = trim((string) $item);

            if ($number === '' || strlen($number) > 80) {
                return false;
            }

            $series[] = $number;
        }

        return $series;
    }

    private function quantity(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = trim((string) $value);
        $pattern = '/^(?:0|[1-9]\d{0,'
            . (self::MAX_INTEGER_DIGITS - 1)
            . '})(?:\.\d{1,'
            . self::SCALE
            . '})?$/';

        if (
            preg_match($pattern, $value) !== 1
            || preg_match('/[1-9]/', $value) !== 1
        ) {
            return null;
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $integer . '.' . str_pad($fraction, self::SCALE, '0');
    }

    private function isIntegerQuantity(string $value): bool
    {
        return $this->decimalUnits($value) % 1_000_000 === 0;
    }

    private function quantityAsInteger(string $value): int
    {
        return intdiv($this->decimalUnits($value), 1_000_000);
    }

    private function compareDecimals(string $left, string $right): int
    {
        return $this->decimalUnits($left) <=> $this->decimalUnits($right);
    }

    private function decimalUnits(string $value): int
    {
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $integer * 1_000_000)
            + (int) str_pad(substr($fraction, 0, 6), 6, '0');
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }

    private function mutationRepository(): InventoryMutationRepositoryInterface
    {
        return $this->mutations ?? $this->inventory;
    }

    private function transactionBoundary(): TransactionBoundaryInterface
    {
        return $this->transactions ?? $this->inventory;
    }
}
