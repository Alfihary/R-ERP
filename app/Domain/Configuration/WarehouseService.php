<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

use App\Infrastructure\Repositories\WarehouseRepository;
use PDOException;

final class WarehouseService
{
    public const TYPES = [
        'GENERAL',
        'REFACCIONES',
        'SERVICIO',
        'CUARENTENA',
        'DEVOLUCIONES',
        'TRANSITO',
        'VIRTUAL',
    ];

    public function __construct(private readonly WarehouseRepository $warehouses)
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
            'rows' => $this->warehouses->paginate($filters),
            'total' => $this->warehouses->count($filters),
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
        $warehouse = $this->warehouses->findById($id);

        if ($warehouse === null) {
            throw new ConfigurationValidationException([
                'id' => 'El almacén solicitado no existe.',
            ]);
        }

        return $warehouse;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, int $adminUserId): int
    {
        $this->assertActor($adminUserId);
        $data = $this->validate($input);

        if (!$this->warehouses->activeCompanyExists((int) $data['empresa_id'])) {
            throw new ConfigurationValidationException([
                'empresa_id' => 'Selecciona una empresa activa.',
            ]);
        }

        if ($this->warehouses->existsCodeForCompany(
            (int) $data['empresa_id'],
            (string) $data['codigo']
        )) {
            throw new ConfigurationValidationException([
                'codigo' => 'El código ya existe dentro de la empresa.',
            ]);
        }

        try {
            return $this->warehouses->create($data, $adminUserId);
        } catch (PDOException $exception) {
            $this->convertDuplicate($exception);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(int $id, array $input, int $actorId): void
    {
        $this->assertActor($actorId);
        $current = $this->get($id);
        $data = $this->validate($input);

        if (!$this->warehouses->activeCompanyExists((int) $data['empresa_id'])) {
            throw new ConfigurationValidationException([
                'empresa_id' => 'Selecciona una empresa activa.',
            ]);
        }

        if ((int) $current['empresa_id'] !== (int) $data['empresa_id']) {
            throw new ConfigurationValidationException([
                'empresa_id' => 'No se permite mover almacenes entre empresas.',
            ]);
        }

        if ($this->warehouses->existsCodeForCompany(
            (int) $data['empresa_id'],
            (string) $data['codigo'],
            $id
        )) {
            throw new ConfigurationValidationException([
                'codigo' => 'El código ya existe dentro de la empresa.',
            ]);
        }

        try {
            $this->warehouses->update($id, $data, $actorId);
        } catch (PDOException $exception) {
            $this->convertDuplicate($exception);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed>|null $activeWarehouse
     */
    public function setActive(
        int $id,
        bool $active,
        int $actorId,
        ?array $activeWarehouse
    ): void {
        $this->assertActor($actorId);
        $warehouse = $this->get($id);

        if (!$active && (int) ($activeWarehouse['id'] ?? 0) === $id) {
            throw new ConfigurationValidationException([
                'estado' => 'No se puede desactivar el almacén del contexto activo.',
            ]);
        }

        if (!$active && $this->warehouses->activeWarehouseCount(
            (int) $warehouse['empresa_id'],
            $id
        ) < 1) {
            throw new ConfigurationValidationException([
                'estado' => 'La empresa debe conservar al menos un almacén activo.',
            ]);
        }

        if ($active) {
            $this->warehouses->activate($id, $actorId);
            return;
        }

        $this->warehouses->deactivate($id, $actorId);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{
     *     search: string,
     *     status: string,
     *     company_id: int|null,
     *     page: int,
     *     per_page: int
     * }
     */
    public function filters(array $query): array
    {
        $status = (string) ($query['status'] ?? 'active');

        if (!in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'active';
        }

        $companyId = filter_var(
            $query['empresa_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return [
            'search' => trim((string) ($query['search'] ?? '')),
            'status' => $status,
            'company_id' => $companyId === false ? null : $companyId,
            'page' => max(1, (int) ($query['page'] ?? 1)),
            'per_page' => 20,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, int|string|null>
     */
    private function validate(array $input): array
    {
        $companyId = filter_var(
            $input['empresa_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $data = [
            'empresa_id' => $companyId === false ? 0 : $companyId,
            'codigo' => strtolower($this->text($input, 'codigo')),
            'nombre' => $this->text($input, 'nombre'),
            'tipo_almacen' => strtoupper($this->text($input, 'tipo_almacen')),
            'responsable' => $this->nullableText($input, 'responsable'),
            'telefono' => $this->nullableText($input, 'telefono'),
            'email' => $this->nullableLower($input, 'email'),
            'pais' => $this->nullableText($input, 'pais'),
            'estado' => $this->nullableText($input, 'estado'),
            'municipio' => $this->nullableText($input, 'municipio'),
            'colonia' => $this->nullableText($input, 'colonia'),
            'calle' => $this->nullableText($input, 'calle'),
            'numero_exterior' => $this->nullableText($input, 'numero_exterior'),
            'numero_interior' => $this->nullableText($input, 'numero_interior'),
            'codigo_postal' => $this->nullableText($input, 'codigo_postal'),
            'permite_ventas' => $this->flag($input, 'permite_ventas'),
            'permite_compras' => $this->flag($input, 'permite_compras'),
            'permite_inventario' => $this->flag($input, 'permite_inventario'),
            'permite_transferencias' => $this->flag($input, 'permite_transferencias'),
            'es_principal' => $this->flag($input, 'es_principal'),
        ];
        $errors = [];

        if ($data['empresa_id'] < 1) {
            $errors['empresa_id'] = 'Selecciona una empresa.';
        }

        if (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $data['codigo']) !== 1
            || strlen($data['codigo']) > 64
        ) {
            $errors['codigo'] =
                'Usa letras minúsculas, números y separadores . _ -, sin espacios.';
        }

        if ($data['nombre'] === '' || strlen($data['nombre']) > 150) {
            $errors['nombre'] = 'El nombre es obligatorio y admite hasta 150 caracteres.';
        }

        if (!in_array($data['tipo_almacen'], self::TYPES, true)) {
            $errors['tipo_almacen'] = 'Selecciona un tipo de almacén válido.';
        }

        $this->max($data, $errors, 'responsable', 160);
        $this->max($data, $errors, 'telefono', 30);
        $this->max($data, $errors, 'email', 120);
        $this->max($data, $errors, 'pais', 80);
        $this->max($data, $errors, 'estado', 80);
        $this->max($data, $errors, 'municipio', 80);
        $this->max($data, $errors, 'colonia', 120);
        $this->max($data, $errors, 'calle', 160);
        $this->max($data, $errors, 'numero_exterior', 30);
        $this->max($data, $errors, 'numero_interior', 30);
        $this->max($data, $errors, 'codigo_postal', 10);

        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'El email no tiene formato válido.';
        }

        if ($data['codigo_postal'] !== null
            && preg_match('/^[0-9]{5,10}$/', $data['codigo_postal']) !== 1
        ) {
            $errors['codigo_postal'] = 'El código postal debe tener entre 5 y 10 dígitos.';
        }

        if ($errors !== []) {
            throw new ConfigurationValidationException($errors);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function nullableText(array $input, string $key): ?string
    {
        $value = $this->text($input, $key);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function nullableLower(array $input, string $key): ?string
    {
        $value = $this->nullableText($input, $key);

        return $value === null ? null : strtolower($value);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function flag(array $input, string $key): int
    {
        return ($input[$key] ?? null) === '1' ? 1 : 0;
    }

    /**
     * @param array<string, int|string|null> $data
     * @param array<string, string> $errors
     */
    private function max(
        array $data,
        array &$errors,
        string $key,
        int $length
    ): void {
        if ($data[$key] !== null && strlen((string) $data[$key]) > $length) {
            $errors[$key] = 'Admite hasta ' . $length . ' caracteres.';
        }
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
            throw new ConfigurationValidationException([
                'codigo' => 'El almacén ya existe o ya hay un principal para la empresa.',
            ]);
        }
    }
}
