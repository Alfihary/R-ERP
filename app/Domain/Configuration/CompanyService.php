<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

use App\Infrastructure\Repositories\CompanyRepository;
use PDOException;

final class CompanyService
{
    public function __construct(private readonly CompanyRepository $companies)
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
            'rows' => $this->companies->paginate($filters),
            'total' => $this->companies->count($filters),
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
        $company = $this->companies->findById($id);

        if ($company === null) {
            throw new ConfigurationValidationException([
                'id' => 'La empresa solicitada no existe.',
            ]);
        }

        return $company;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, int $adminUserId): int
    {
        $this->assertActor($adminUserId);
        $data = $this->validate($input);

        if ($this->companies->existsCode((string) $data['codigo'])) {
            throw new ConfigurationValidationException([
                'codigo' => 'El código de empresa ya existe.',
            ]);
        }

        try {
            return $this->companies->create($data, $adminUserId);
        } catch (PDOException $exception) {
            $this->convertPersistenceError($exception, 'codigo');
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(int $id, array $input, int $actorId): void
    {
        $this->assertActor($actorId);
        $this->get($id);
        $data = $this->validate($input);

        if ($this->companies->existsCode((string) $data['codigo'], $id)) {
            throw new ConfigurationValidationException([
                'codigo' => 'El código de empresa ya existe.',
            ]);
        }

        try {
            $this->companies->update($id, $data, $actorId);
        } catch (PDOException $exception) {
            $this->convertPersistenceError($exception, 'codigo');
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed>|null $activeCompany
     */
    public function setActive(
        int $id,
        bool $active,
        int $actorId,
        ?array $activeCompany
    ): void {
        $this->assertActor($actorId);
        $this->get($id);

        if (!$active && (int) ($activeCompany['id'] ?? 0) === $id) {
            throw new ConfigurationValidationException([
                'estado' => 'No se puede desactivar la empresa del contexto activo.',
            ]);
        }

        if (!$active && $this->companies->activeWarehouseCount($id) > 0) {
            throw new ConfigurationValidationException([
                'estado' => 'Desactiva primero los almacenes activos de esta empresa.',
            ]);
        }

        if ($active) {
            $this->companies->activate($id, $actorId);
            return;
        }

        $this->companies->deactivate($id, $actorId);
    }

    /**
     * @return list<array{id: int, codigo: string, nombre: string}>
     */
    public function activeOptions(): array
    {
        return $this->companies->activeOptions();
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
     * @return array<string, string|null>
     */
    private function validate(array $input): array
    {
        $data = [
            'codigo' => $this->code($input, 'codigo'),
            'nombre' => $this->text($input, 'nombre'),
            'razon_social' => $this->nullableText($input, 'razon_social'),
            'nombre_comercial' => $this->nullableText($input, 'nombre_comercial'),
            'rfc' => $this->nullableUpper($input, 'rfc'),
            'regimen_fiscal' => $this->nullableUpper($input, 'regimen_fiscal'),
            'telefono' => $this->nullableText($input, 'telefono'),
            'email' => $this->nullableLower($input, 'email'),
            'sitio_web' => $this->nullableText($input, 'sitio_web'),
            'pais' => $this->nullableText($input, 'pais'),
            'estado' => $this->nullableText($input, 'estado'),
            'municipio' => $this->nullableText($input, 'municipio'),
            'colonia' => $this->nullableText($input, 'colonia'),
            'calle' => $this->nullableText($input, 'calle'),
            'numero_exterior' => $this->nullableText($input, 'numero_exterior'),
            'numero_interior' => $this->nullableText($input, 'numero_interior'),
            'codigo_postal' => $this->nullableText($input, 'codigo_postal'),
            'logo_path' => $this->nullableText($input, 'logo_path'),
            'color_primario' => $this->nullableText($input, 'color_primario'),
        ];
        $errors = [];

        if (preg_match('/^[A-Z0-9]+(?:[._-][A-Z0-9]+)*$/', $data['codigo']) !== 1
            || strlen($data['codigo']) < 2
            || strlen($data['codigo']) > 64
        ) {
            $errors['codigo'] =
                'El código de empresa debe tener de 2 a 64 caracteres: letras mayúsculas, números y separadores . _ -, sin espacios.';
        }

        if ($data['nombre'] === '' || strlen($data['nombre']) > 150) {
            $errors['nombre'] = 'El nombre es obligatorio y admite hasta 150 caracteres.';
        }

        $this->max($data, $errors, 'razon_social', 180);
        $this->max($data, $errors, 'nombre_comercial', 180);
        $this->max($data, $errors, 'regimen_fiscal', 10);
        $this->max($data, $errors, 'telefono', 30);
        $this->max($data, $errors, 'email', 120);
        $this->max($data, $errors, 'sitio_web', 160);
        $this->max($data, $errors, 'pais', 80);
        $this->max($data, $errors, 'estado', 80);
        $this->max($data, $errors, 'municipio', 80);
        $this->max($data, $errors, 'colonia', 120);
        $this->max($data, $errors, 'calle', 160);
        $this->max($data, $errors, 'numero_exterior', 30);
        $this->max($data, $errors, 'numero_interior', 30);
        $this->max($data, $errors, 'codigo_postal', 10);
        $this->max($data, $errors, 'logo_path', 255);
        $this->max($data, $errors, 'color_primario', 20);

        if ($data['rfc'] !== null
            && preg_match('/^[A-Z&Ñ0-9]{12,13}$/u', $data['rfc']) !== 1
        ) {
            $errors['rfc'] = 'El RFC debe tener formato básico válido.';
        }

        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'El email no tiene formato válido.';
        }

        if ($data['codigo_postal'] !== null
            && preg_match('/^[0-9]{5,10}$/', $data['codigo_postal']) !== 1
        ) {
            $errors['codigo_postal'] = 'El código postal debe tener entre 5 y 10 dígitos.';
        }

        if ($data['color_primario'] !== null
            && preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color_primario']) !== 1
        ) {
            $errors['color_primario'] = 'Usa un color hexadecimal como #1A2B3C.';
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
    private function nullableUpper(array $input, string $key): ?string
    {
        $value = $this->nullableText($input, $key);

        return $value === null ? null : strtoupper($value);
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
    private function code(array $input, string $key): string
    {
        $value = strtoupper($this->text($input, $key));

        return (string) preg_replace('/\s+/', '-', $value);
    }

    /**
     * @param array<string, string|null> $data
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

    private function convertPersistenceError(PDOException $exception, string $field): void
    {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new ConfigurationValidationException([
                $field => 'El registro ya existe.',
            ]);
        }

        if ((int) ($exception->errorInfo[1] ?? 0) === 3819) {
            throw new ConfigurationValidationException([
                $field => 'El valor no cumple las reglas de seguridad de la base de datos.',
            ]);
        }
    }
}
