<?php

declare(strict_types=1);

namespace App\Domain\Catalogs;

use App\Infrastructure\Repositories\SatCatalogRepository;

final class SatCatalogService
{
    private const KEY_PER_PAGE = 20;

    public function __construct(
        private readonly SatCatalogRepository $repository
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{search: string, status: string}
     */
    public function unitFilters(array $query): array
    {
        return [
            'search' => $this->text($query['search'] ?? '', 80),
            'status' => $this->status($query['status'] ?? 'all'),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{search: string, status: string, page: int}
     */
    public function keyFilters(array $query): array
    {
        $page = filter_var(
            $query['page'] ?? 1,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return [
            'search' => $this->text($query['search'] ?? '', 80),
            'status' => $this->status($query['status'] ?? 'all'),
            'page' => $page === false ? 1 : (int) $page,
        ];
    }

    /**
     * @param array{search: string, status: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listUnits(array $filters): array
    {
        return $this->repository->listUnits($filters);
    }

    /**
     * @param array{search: string, status: string, page: int} $filters
     * @return array{
     *     records: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     total_pages: int
     * }
     */
    public function listKeys(array $filters): array
    {
        return $this->repository->listKeys($filters, self::KEY_PER_PAGE);
    }

    /**
     * @return array<string, mixed>
     */
    public function findUnit(int $id): array
    {
        $record = $this->repository->findUnit($id);

        if ($record === null) {
            throw new CatalogValidationException([
                'record' => 'La unidad SAT no existe.',
            ]);
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function findKey(int $id): array
    {
        $record = $this->repository->findKey($id);

        if ($record === null) {
            throw new CatalogValidationException([
                'record' => 'La clave SAT no existe.',
            ]);
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createUnit(array $input, int $actorId): void
    {
        $data = $this->unitData($input);

        if ($this->repository->unitCodeExists($data['codigo'])) {
            throw new CatalogValidationException([
                'codigo' => 'El código de unidad SAT ya existe.',
            ]);
        }

        $this->repository->createUnit($data, $actorId);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateUnit(int $id, array $input, int $actorId): void
    {
        $this->findUnit($id);
        $data = $this->unitData($input);

        if ($this->repository->unitCodeExists($data['codigo'], $id)) {
            throw new CatalogValidationException([
                'codigo' => 'El código de unidad SAT ya existe.',
            ]);
        }

        $this->repository->updateUnit($id, $data, $actorId);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createKey(array $input, int $actorId): void
    {
        $data = $this->keyData($input);

        if ($this->repository->keyCodeExists($data['codigo'])) {
            throw new CatalogValidationException([
                'codigo' => 'El código de clave SAT ya existe.',
            ]);
        }

        $this->repository->createKey($data, $actorId);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateKey(int $id, array $input, int $actorId): void
    {
        $this->findKey($id);
        $data = $this->keyData($input);

        if ($this->repository->keyCodeExists($data['codigo'], $id)) {
            throw new CatalogValidationException([
                'codigo' => 'El código de clave SAT ya existe.',
            ]);
        }

        $this->repository->updateKey($id, $data, $actorId);
    }

    public function setUnitActive(int $id, bool $active, int $actorId): void
    {
        $this->findUnit($id);
        $this->repository->setUnitActive($id, $active, $actorId);
    }

    public function setKeyActive(int $id, bool $active, int $actorId): void
    {
        $this->findKey($id);
        $this->repository->setKeyActive($id, $active, $actorId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{codigo: string, nombre: string, descripcion: ?string}
     */
    private function unitData(array $input): array
    {
        $code = strtoupper(trim((string) ($input['codigo'] ?? '')));
        $name = $this->text($input['nombre'] ?? '', 120);
        $description = $this->nullableText($input['descripcion'] ?? '', 255);
        $errors = [];

        if (!preg_match('/^[A-Z0-9]{1,16}$/', $code)) {
            $errors['codigo'] = 'El código debe usar 1 a 16 letras mayúsculas o números.';
        }

        if ($name === '') {
            $errors['nombre'] = 'El nombre es obligatorio.';
        }

        if ($errors !== []) {
            throw new CatalogValidationException($errors);
        }

        return [
            'codigo' => $code,
            'nombre' => $name,
            'descripcion' => $description,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{codigo: string, descripcion: string}
     */
    private function keyData(array $input): array
    {
        $code = trim((string) ($input['codigo'] ?? ''));
        $description = $this->text($input['descripcion'] ?? '', 255);
        $errors = [];

        if (!preg_match('/^[0-9]{1,16}$/', $code)) {
            $errors['codigo'] = 'El código debe usar 1 a 16 dígitos.';
        }

        if ($description === '') {
            $errors['descripcion'] = 'La descripción es obligatoria.';
        }

        if ($errors !== []) {
            throw new CatalogValidationException($errors);
        }

        return [
            'codigo' => $code,
            'descripcion' => $description,
        ];
    }

    private function status(mixed $value): string
    {
        $status = (string) $value;

        return in_array($status, ['all', 'active', 'inactive'], true)
            ? $status
            : 'all';
    }

    private function text(mixed $value, int $maxLength): string
    {
        return mb_substr(trim((string) $value), 0, $maxLength);
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        $text = $this->text($value, $maxLength);

        return $text === '' ? null : $text;
    }
}
