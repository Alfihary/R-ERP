<?php

declare(strict_types=1);

namespace App\Domain\Catalogs;

use App\Infrastructure\Repositories\CatalogRepository;
use PDOException;

final class CatalogService
{
    /**
     * @var array<string, array{
     *     slug: string,
     *     title: string,
     *     singular: string,
     *     permission: string
     * }>
     */
    private const CATALOGS = [
        'monedas' => [
            'slug' => 'monedas',
            'title' => 'Monedas',
            'singular' => 'moneda',
            'permission' => 'monedas',
        ],
        'unidades' => [
            'slug' => 'unidades',
            'title' => 'Unidades de medida',
            'singular' => 'unidad',
            'permission' => 'unidades',
        ],
        'impuestos' => [
            'slug' => 'impuestos',
            'title' => 'Impuestos',
            'singular' => 'impuesto',
            'permission' => 'impuestos',
        ],
        'lineas' => [
            'slug' => 'lineas',
            'title' => 'Líneas de producto',
            'singular' => 'línea',
            'permission' => 'lineas',
        ],
        'marcas' => [
            'slug' => 'marcas',
            'title' => 'Marcas',
            'singular' => 'marca',
            'permission' => 'marcas',
        ],
    ];

    public function __construct(private readonly CatalogRepository $catalogs)
    {
    }

    /**
     * @return array<string, array{
     *     slug: string,
     *     title: string,
     *     singular: string,
     *     permission: string
     * }>
     */
    public function definitions(): array
    {
        return self::CATALOGS;
    }

    /**
     * @return array{
     *     slug: string,
     *     title: string,
     *     singular: string,
     *     permission: string
     * }
     */
    public function definition(string $catalog): array
    {
        $definition = self::CATALOGS[$catalog] ?? null;

        if ($definition === null) {
            throw new \InvalidArgumentException('Unsupported catalog.');
        }

        return $definition;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $catalog): array
    {
        $this->definition($catalog);

        return $this->catalogs->all($catalog);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(
        string $catalog,
        array $input,
        int $actorId
    ): int {
        $this->assertActor($actorId);
        $data = $this->validate($catalog, $input);

        if ($this->catalogs->codeExists($catalog, (string) $data['codigo'])) {
            throw new CatalogValidationException([
                'codigo' => 'El código ya existe.',
            ]);
        }

        try {
            return $this->catalogs->create($catalog, $data, $actorId);
        } catch (PDOException $exception) {
            $this->convertDuplicate($exception);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(
        string $catalog,
        int $id,
        array $input,
        int $actorId
    ): void {
        $this->assertActor($actorId);
        $current = $this->requireRecord($catalog, $id);
        $data = $this->validate($catalog, $input);

        if ($this->catalogs->codeExists(
            $catalog,
            (string) $data['codigo'],
            $id
        )) {
            throw new CatalogValidationException([
                'codigo' => 'El código ya existe.',
            ]);
        }

        if ($catalog === 'monedas'
            && (int) $current['es_base'] === 1
            && (int) $data['es_base'] !== 1
        ) {
            throw new CatalogValidationException([
                'es_base' => 'Asigna otra moneda base antes de retirar esta marca.',
            ]);
        }

        try {
            $this->catalogs->update($catalog, $id, $data, $actorId);
        } catch (PDOException $exception) {
            $this->convertDuplicate($exception);
            throw $exception;
        }
    }

    public function setActive(
        string $catalog,
        int $id,
        bool $active,
        int $actorId
    ): void {
        $this->assertActor($actorId);
        $record = $this->requireRecord($catalog, $id);

        if ($catalog === 'monedas'
            && !$active
            && (int) $record['es_base'] === 1
        ) {
            throw new CatalogValidationException([
                'estado' => 'La moneda base no puede desactivarse.',
            ]);
        }

        $this->catalogs->setActive($catalog, $id, $active, $actorId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, int|string>
     */
    private function validate(string $catalog, array $input): array
    {
        $this->definition($catalog);
        $code = strtoupper($this->text($input, 'codigo'));
        $name = $this->text($input, 'nombre');
        $errors = [];

        if ($catalog === 'monedas') {
            if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                $errors['codigo'] = 'Usa exactamente tres letras.';
            }
        } elseif (preg_match(
            '/^[A-Z0-9]+(?:[_-][A-Z0-9]+)*$/',
            $code
        ) !== 1 || strlen($code) > 64) {
            $errors['codigo'] =
                'Usa letras, números, guion o guion bajo, sin espacios.';
        }

        if ($name === '' || strlen($name) > 150) {
            $errors['nombre'] = 'El nombre es obligatorio y admite hasta 150 caracteres.';
        }

        $data = ['codigo' => $code, 'nombre' => $name];

        if ($catalog === 'monedas') {
            $symbol = $this->text($input, 'simbolo');
            $decimals = $this->integer($input, 'decimales');

            if ($symbol === '' || strlen($symbol) > 10) {
                $errors['simbolo'] =
                    'El símbolo es obligatorio y admite hasta 10 caracteres.';
            }
            if ($decimals === null || $decimals < 0 || $decimals > 6) {
                $errors['decimales'] = 'Los decimales deben estar entre 0 y 6.';
            }

            $data += [
                'simbolo' => $symbol,
                'decimales' => $decimals ?? 0,
                'es_base' => ($input['es_base'] ?? null) === '1' ? 1 : 0,
            ];
        } elseif ($catalog === 'unidades') {
            $abbreviation = $this->text($input, 'abreviatura');

            if ($abbreviation === '' || strlen($abbreviation) > 20) {
                $errors['abreviatura'] =
                    'La abreviatura es obligatoria y admite hasta 20 caracteres.';
            }

            $data['abreviatura'] = $abbreviation;
        } elseif ($catalog === 'impuestos') {
            $rate = $this->decimal($input, 'tasa');
            $type = strtoupper($this->text($input, 'tipo'));

            if ($rate === null || $rate < 0 || $rate > 100) {
                $errors['tasa'] = 'La tasa debe estar entre 0 y 100.';
            }
            if (!in_array($type, ['IVA', 'IEPS', 'EXENTO'], true)) {
                $errors['tipo'] = 'Selecciona IVA, IEPS o EXENTO.';
            }
            if ($type === 'EXENTO' && $rate !== null && $rate !== 0.0) {
                $errors['tasa'] = 'Un impuesto EXENTO debe tener tasa cero.';
            }

            $data += [
                'tasa' => number_format($rate ?? 0, 4, '.', ''),
                'tipo' => $type,
            ];
        }

        if ($errors !== []) {
            throw new CatalogValidationException($errors);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRecord(string $catalog, int $id): array
    {
        if ($id < 1) {
            throw new CatalogValidationException([
                'id' => 'El registro solicitado no es válido.',
            ]);
        }

        $record = $this->catalogs->find($catalog, $id);

        if ($record === null) {
            throw new CatalogValidationException([
                'id' => 'El registro solicitado no existe.',
            ]);
        }

        return $record;
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
    private function integer(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;

        if (!is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function decimal(array $input, string $key): ?float
    {
        $value = $input[$key] ?? null;

        if (!is_string($value)
            || preg_match('/^-?\d+(?:\.\d{1,4})?$/', $value) !== 1
        ) {
            return null;
        }

        return (float) $value;
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
                'codigo' => 'El código ya existe.',
            ]);
        }
    }
}
