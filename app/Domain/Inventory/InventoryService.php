<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Infrastructure\Repositories\InventoryRepository;

final class InventoryService
{
    private const MAX_INTEGER_DIGITS = 12;
    private const SCALE = 6;

    public function __construct(private readonly InventoryRepository $inventory)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function aplicarMovimiento(array $input): array
    {
        $request = $this->validateRequestShape($input);

        return $this->inventory->transactional(function () use ($request): array {
            $this->assertUser($request['usuario_id']);
            $this->assertScope($request['empresa_id'], $request['almacen_id']);
            $concept = $this->assertConcept($request['concepto_codigo']);
            $parts = $this->assertProducts($request['partidas']);

            $movementId = $this->inventory->createDraftMovement(
                $request['empresa_id'],
                $request['almacen_id'],
                $concept['id'],
                $request['fecha_movimiento'],
                $request['referencia'],
                $request['observaciones'],
                $request['usuario_id']
            );

            foreach ($parts as $part) {
                $this->inventory->insertMovementDetail(
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
                    $this->inventory->ensureExistenceRow(
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

                if ($concept['naturaleza'] === 'ENTRADA') {
                    $this->inventory->increaseExistence(
                        $request['almacen_id'],
                        $part['id_producto'],
                        $part['cantidad']
                    );
                } else {
                    $this->inventory->decreaseExistence(
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
                ];
            }

            $this->inventory->markMovementApplied(
                $movementId,
                $request['usuario_id']
            );
            $movement = $this->inventory->movementResult($movementId);

            return [
                'movimiento_id' => (int) $movement['id'],
                'estado' => (string) $movement['estado'],
                'empresa_id' => (int) $movement['empresa_id'],
                'almacen_id' => (int) $movement['almacen_id'],
                'concepto_codigo' => (string) $movement['concepto_codigo'],
                'naturaleza' => (string) $movement['naturaleza'],
                'fecha_movimiento' => (string) $movement['fecha_movimiento'],
                'partidas_aplicadas' => $appliedParts,
            ];
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
     *     observaciones: string|null,
     *     usuario_id: int,
     *     partidas: list<array{
     *         id_producto: string,
     *         cantidad: string,
     *         observaciones: string|null
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

        return [
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'concepto_codigo' => $conceptCode,
            'fecha_movimiento' => $movementDate,
            'referencia' => $reference,
            'observaciones' => $notes,
            'usuario_id' => $actorId,
            'partidas' => $parts,
        ];
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
     * @param list<array{id_producto: string, cantidad: string, observaciones: string|null}> $parts
     * @return list<array{id_producto: string, cantidad: string, observaciones: string|null}>
     */
    private function assertProducts(array $parts): array
    {
        $ids = array_column($parts, 'id_producto');
        $products = $this->inventory->activeProductsByIds($ids);

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
        }

        return $parts;
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
}

final class InventoryValidationException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Inventory movement data is invalid.');
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
