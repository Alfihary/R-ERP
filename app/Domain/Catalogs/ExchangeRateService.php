<?php

declare(strict_types=1);

namespace App\Domain\Catalogs;

use App\Infrastructure\Repositories\ExchangeRateRepository;
use DateTimeImmutable;
use PDOException;

final class ExchangeRateService
{
    public function __construct(
        private readonly ExchangeRateRepository $exchangeRates
    ) {
    }

    /**
     * @return array{
     *     currencies: list<array<string, mixed>>,
     *     records: list<array<string, mixed>>
     * }
     */
    public function viewData(): array
    {
        return [
            'currencies' => $this->exchangeRates->activeCurrencies(),
            'records' => $this->exchangeRates->all(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, int $actorId): int
    {
        $this->assertActor($actorId);
        $data = $this->validate($input);
        $this->assertUnique($data);

        try {
            return $this->exchangeRates->create($data, $actorId);
        } catch (PDOException $exception) {
            $this->convertDuplicate($exception);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(array $input, int $actorId): void
    {
        $this->assertActor($actorId);
        $id = $this->positiveInteger($input, 'id');
        $this->requireRecord($id);
        $data = $this->validate($input);
        $this->assertUnique($data, $id);

        try {
            $this->exchangeRates->update($id, $data, $actorId);
        } catch (PDOException $exception) {
            $this->convertDuplicate($exception);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function setActive(
        array $input,
        bool $active,
        int $actorId
    ): void {
        $this->assertActor($actorId);
        $id = $this->positiveInteger($input, 'id');
        $this->requireRecord($id);
        $this->exchangeRates->setActive($id, $active, $actorId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     moneda_origen_id: int,
     *     moneda_destino_id: int,
     *     fecha: string,
     *     valor: string
     * }
     */
    private function validate(array $input): array
    {
        $originId = $this->optionalPositiveInteger(
            $input,
            'moneda_origen_id'
        );
        $destinationId = $this->optionalPositiveInteger(
            $input,
            'moneda_destino_id'
        );
        $date = $this->text($input, 'fecha');
        $value = $this->text($input, 'valor');
        $errors = [];

        if ($originId === null) {
            $errors['moneda_origen_id'] = 'Selecciona la moneda origen.';
        } elseif (!$this->exchangeRates->activeCurrencyExists($originId)) {
            $errors['moneda_origen_id'] =
                'La moneda origen no existe o no está activa.';
        }

        if ($destinationId === null) {
            $errors['moneda_destino_id'] = 'Selecciona la moneda destino.';
        } elseif (!$this->exchangeRates->activeCurrencyExists($destinationId)) {
            $errors['moneda_destino_id'] =
                'La moneda destino no existe o no está activa.';
        }

        if (
            $originId !== null
            && $destinationId !== null
            && $originId === $destinationId
        ) {
            $errors['moneda_destino_id'] =
                'La moneda destino debe ser diferente de la moneda origen.';
        }

        if (!$this->validDate($date)) {
            $errors['fecha'] = 'Ingresa una fecha válida.';
        }

        if ($value === '') {
            $errors['valor'] = 'El valor es obligatorio.';
        } elseif (
            preg_match(
                '/^(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/',
                $value
            ) !== 1
        ) {
            $errors['valor'] =
                'Usa hasta 12 enteros y 8 decimales, sin separadores.';
        } elseif (trim(str_replace(['0', '.'], '', $value)) === '') {
            $errors['valor'] = 'El valor debe ser mayor que cero.';
        }

        if ($errors !== []) {
            throw new CatalogValidationException($errors);
        }

        return [
            'moneda_origen_id' => $originId,
            'moneda_destino_id' => $destinationId,
            'fecha' => $date,
            'valor' => $value,
        ];
    }

    /**
     * @param array{
     *     moneda_origen_id: int,
     *     moneda_destino_id: int,
     *     fecha: string,
     *     valor: string
     * } $data
     */
    private function assertUnique(array $data, ?int $exceptId = null): void
    {
        if ($this->exchangeRates->combinationExists(
            $data['moneda_origen_id'],
            $data['moneda_destino_id'],
            $data['fecha'],
            $exceptId
        )) {
            throw new CatalogValidationException([
                'fecha' =>
                    'Ya existe un tipo de cambio para ese par y fecha.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRecord(int $id): array
    {
        $record = $this->exchangeRates->find($id);

        if ($record === null) {
            throw new CatalogValidationException([
                'id' => 'El tipo de cambio solicitado no existe.',
            ]);
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function positiveInteger(array $input, string $key): int
    {
        $value = $this->optionalPositiveInteger($input, $key);

        if ($value === null) {
            throw new CatalogValidationException([
                $key => 'El registro solicitado no es válido.',
            ]);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function optionalPositiveInteger(
        array $input,
        string $key
    ): ?int {
        $value = $input[$key] ?? null;

        if (
            !is_string($value)
            || preg_match('/^[1-9]\d*$/', $value) !== 1
        ) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function validDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false
                || ($errors['warning_count'] === 0
                    && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId < 1) {
            throw new \InvalidArgumentException('A valid actor is required.');
        }
    }

    private function convertDuplicate(PDOException $exception): void
    {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new CatalogValidationException([
                'fecha' =>
                    'Ya existe un tipo de cambio para ese par y fecha.',
            ]);
        }
    }
}
