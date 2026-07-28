<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Infrastructure\Repositories\PriceListRepository;
use PDOException;
use Throwable;

final class PriceListService
{
    public function __construct(private readonly PriceListRepository $lists)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>}
     */
    public function search(array $query): array
    {
        $filters = $this->filters($query);

        return [
            'rows' => $this->lists->paginate($filters),
            'total' => $this->lists->count($filters),
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'filters' => $filters,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $list = $this->lists->findNotDeletedById($id);

        if ($list === null) {
            throw new PricingValidationException([
                'id' => 'La lista de precios solicitada no existe.',
            ]);
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, int $actorId): int
    {
        $this->assertActor($actorId);
        $data = $this->validate($input);

        if ($this->lists->existsClave((string) $data['clave'])) {
            throw new PricingValidationException([
                'clave' => 'La clave ya existe.',
            ]);
        }

        return $this->transactional(function () use ($data, $actorId): int {
            if ((int) $data['es_predeterminada'] === 1) {
                $this->lists->clearDefault($actorId);
            }

            try {
                return $this->lists->insert($data + [
                    'creado_por' => $actorId,
                    'actualizado_por' => $actorId,
                ]);
            } catch (PDOException $exception) {
                $this->convertPersistenceError($exception);
                throw $exception;
            }
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(int $id, array $input, int $actorId): void
    {
        $this->assertActor($actorId);
        $this->get($id);
        $data = $this->validate($input);

        if ($this->lists->existsClave((string) $data['clave'], $id)) {
            throw new PricingValidationException([
                'clave' => 'La clave ya existe.',
            ]);
        }

        $this->transactional(function () use ($id, $data, $actorId): void {
            if ((int) $data['es_predeterminada'] === 1) {
                $this->lists->clearDefault($actorId);
            }

            try {
                $this->lists->update($id, $data + ['actualizado_por' => $actorId]);
            } catch (PDOException $exception) {
                $this->convertPersistenceError($exception);
                throw $exception;
            }
        });
    }

    public function activate(int $id, int $actorId): void
    {
        $this->assertActor($actorId);
        $this->get($id);
        $this->lists->activate($id, $actorId);
    }

    public function deactivate(int $id, int $actorId): void
    {
        $this->assertActor($actorId);
        $list = $this->get($id);

        if ((int) ($list['es_predeterminada'] ?? 0) === 1) {
            throw new PricingValidationException([
                'estado' =>
                    'No puedes desactivar la lista predeterminada. Primero marca otra lista como predeterminada.',
            ]);
        }

        $this->lists->deactivate($id, $actorId);
    }

    public function setDefault(int $id, int $actorId): void
    {
        $this->assertActor($actorId);

        $this->transactional(function () use ($id, $actorId): void {
            $list = $this->lists->findNotDeletedByIdForUpdate($id);

            if ($list === null) {
                throw new PricingValidationException([
                    'id' => 'La lista de precios solicitada no existe.',
                ]);
            }

            if ((int) ($list['activo'] ?? 0) !== 1) {
                throw new PricingValidationException([
                    'predeterminada' =>
                        'Solo una lista activa puede marcarse como predeterminada.',
                ]);
            }

            $this->lists->clearDefault($actorId);
            $this->lists->setDefault($id, $actorId);
        });
    }

    /**
     * @param array<string, mixed> $query
     * @return array{search: string, status: string, page: int, per_page: int}
     */
    public function filters(array $query): array
    {
        $status = (string) ($query['status'] ?? 'active');

        if (!in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'active';
        }

        return [
            'search' => trim((string) ($query['search'] ?? '')),
            'status' => $status,
            'page' => max(1, (int) ($query['page'] ?? 1)),
            'per_page' => 20,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{clave: string, nombre: string, observaciones: string|null, incluye_impuestos: int, es_predeterminada: int, activo: int}
     */
    private function validate(array $input): array
    {
        $data = [
            'clave' => $this->clave($input['clave'] ?? ''),
            'nombre' => trim((string) ($input['nombre'] ?? '')),
            'observaciones' => trim((string) ($input['observaciones'] ?? '')),
            'incluye_impuestos' =>
                ($input['incluye_impuestos'] ?? null) === '1' ? 1 : 0,
            'es_predeterminada' =>
                ($input['es_predeterminada'] ?? null) === '1' ? 1 : 0,
            'activo' => ($input['activo'] ?? null) === '1' ? 1 : 0,
        ];
        $errors = [];

        if (
            strlen($data['clave']) < 2
            || strlen($data['clave']) > 32
            || preg_match('/^[A-Z0-9]+([._-][A-Z0-9]+)*$/', $data['clave']) !== 1
        ) {
            $errors['clave'] =
                'La clave debe tener de 2 a 32 caracteres: letras mayúsculas, números y separadores . _ -, sin espacios.';
        }

        if ($data['nombre'] === '' || strlen($data['nombre']) > 100) {
            $errors['nombre'] =
                'El nombre es obligatorio y admite hasta 100 caracteres.';
        }

        if ($data['observaciones'] !== '' && strlen($data['observaciones']) > 500) {
            $errors['observaciones'] = 'Las observaciones admiten hasta 500 caracteres.';
        }

        if ($data['activo'] === 0 && $data['es_predeterminada'] === 1) {
            $errors['predeterminada'] =
                'Una lista inactiva no puede ser predeterminada.';
        }

        if ($errors !== []) {
            throw new PricingValidationException($errors);
        }

        return [
            'clave' => $data['clave'],
            'nombre' => $data['nombre'],
            'observaciones' =>
                $data['observaciones'] === '' ? null : $data['observaciones'],
            'incluye_impuestos' => $data['incluye_impuestos'],
            'es_predeterminada' => $data['es_predeterminada'],
            'activo' => $data['activo'],
        ];
    }

    private function clave(mixed $value): string
    {
        return strtoupper(trim((string) $value));
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        $ownsTransaction = $this->lists->beginTransaction();

        try {
            $result = $operation();
            $this->lists->commit($ownsTransaction);

            return $result;
        } catch (Throwable $exception) {
            $this->lists->rollBack($ownsTransaction);
            throw $exception;
        }
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId < 1) {
            throw new \InvalidArgumentException('A valid actor is required.');
        }
    }

    private function convertPersistenceError(PDOException $exception): void
    {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new PricingValidationException([
                'clave' => 'La clave ya existe.',
            ]);
        }

        if ((int) ($exception->errorInfo[1] ?? 0) === 3819) {
            throw new PricingValidationException([
                'clave' => 'El valor no cumple las reglas de seguridad de la base de datos.',
            ]);
        }
    }
}
