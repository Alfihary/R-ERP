<?php

declare(strict_types=1);

namespace App\Domain\Catalogs;

use App\Infrastructure\Repositories\ClassificationRepository;
use PDOException;

final class ClassificationService
{
    public function __construct(
        private readonly ClassificationRepository $classifications
    ) {
    }

    /**
     * @return array{
     *     records: list<array<string, mixed>>,
     *     create_parent_options: list<array<string, mixed>>,
     *     edit_parent_options: array<int, list<array<string, mixed>>>
     * }
     */
    public function viewData(): array
    {
        $rows = $this->classifications->all();
        $records = $this->decorate($rows);
        $editOptions = [];

        foreach ($records as $record) {
            $id = (int) $record['id'];
            $editOptions[$id] = $this->parentOptions($records, $id);
        }

        return [
            'records' => $records,
            'create_parent_options' => $this->parentOptions($records, null),
            'edit_parent_options' => $editOptions,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, int $actorId): int
    {
        $this->assertActor($actorId);

        return $this->classifications->transactional(
            function () use ($input, $actorId): int {
                $rows = $this->classifications->all(true);
                $data = $this->validateInput($input);
                $parent = $this->validateParent(
                    $rows,
                    $data['parent_id'],
                    null,
                    true
                );

                if ($this->classifications->codeExists($data['codigo'])) {
                    throw new CatalogValidationException([
                        'codigo' => 'El código ya existe.',
                    ]);
                }

                try {
                    return $this->classifications->create(
                        $data['codigo'],
                        $data['nombre'],
                        $parent,
                        $actorId
                    );
                } catch (PDOException $exception) {
                    $this->convertDatabaseError($exception);
                    throw $exception;
                }
            }
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(array $input, int $actorId): void
    {
        $this->assertActor($actorId);
        $id = $this->id($input['id'] ?? null);

        $this->classifications->transactional(
            function () use ($id, $input, $actorId): void {
                $rows = $this->classifications->all(true);
                $current = $this->requireRecord($rows, $id);
                $data = $this->validateInput($input);
                $parentId = $this->validateParent(
                    $rows,
                    $data['parent_id'],
                    $id,
                    (int) $current['activo'] === 1
                );

                if ($this->classifications->codeExists(
                    $data['codigo'],
                    $id
                )) {
                    throw new CatalogValidationException([
                        'codigo' => 'El código ya existe.',
                    ]);
                }

                try {
                    $this->classifications->update(
                        $id,
                        $data['codigo'],
                        $data['nombre'],
                        $parentId,
                        $actorId
                    );
                } catch (PDOException $exception) {
                    $this->convertDatabaseError($exception);
                    throw $exception;
                }
            }
        );
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
        $id = $this->id($input['id'] ?? null);

        $this->classifications->transactional(
            function () use ($id, $active, $actorId): void {
                $rows = $this->classifications->all(true);
                $record = $this->requireRecord($rows, $id);

                if ($active) {
                    $parentId = $this->nullableId($record['parent_id']);

                    if ($parentId !== null) {
                        $parent = $this->requireRecord($rows, $parentId);

                        if ((int) $parent['activo'] !== 1) {
                            throw new CatalogValidationException([
                                'estado' =>
                                    'Activa primero la clasificación padre.',
                            ]);
                        }
                    }
                } elseif ($this->hasActiveDescendants($rows, $id)) {
                    throw new CatalogValidationException([
                        'estado' =>
                            'Desactiva primero las clasificaciones hijas activas.',
                    ]);
                }

                $this->classifications->setActive(
                    $id,
                    $active,
                    $actorId
                );
            }
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{codigo: string, nombre: string, parent_id: int|null}
     */
    private function validateInput(array $input): array
    {
        $code = strtoupper($this->text($input['codigo'] ?? null));
        $name = $this->text($input['nombre'] ?? null);
        $parentId = $this->optionalId($input['parent_id'] ?? null);
        $errors = [];

        if (
            preg_match('/^[A-Z0-9]+(?:[_-][A-Z0-9]+)*$/', $code) !== 1
            || strlen($code) > 64
        ) {
            $errors['codigo'] =
                'Usa letras, números, guion o guion bajo, sin espacios.';
        }

        if ($name === '' || strlen($name) > 150) {
            $errors['nombre'] =
                'El nombre es obligatorio y admite hasta 150 caracteres.';
        }

        if ($parentId === false) {
            $errors['parent_id'] = 'Selecciona una clasificación padre válida.';
        }

        if ($errors !== []) {
            throw new CatalogValidationException($errors);
        }

        return [
            'codigo' => $code,
            'nombre' => $name,
            'parent_id' => $parentId,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function validateParent(
        array $rows,
        ?int $parentId,
        ?int $editingId,
        bool $recordWillBeActive
    ): ?int {
        if ($parentId === null) {
            return null;
        }

        if ($editingId !== null && $parentId === $editingId) {
            throw new CatalogValidationException([
                'parent_id' => 'Una clasificación no puede ser su propio padre.',
            ]);
        }

        $parent = $this->findRecord($rows, $parentId);

        if ($parent === null) {
            throw new CatalogValidationException([
                'parent_id' => 'La clasificación padre no existe.',
            ]);
        }

        if ($recordWillBeActive && (int) $parent['activo'] !== 1) {
            throw new CatalogValidationException([
                'parent_id' => 'La clasificación padre debe estar activa.',
            ]);
        }

        if (
            $editingId !== null
            && in_array(
                $parentId,
                $this->descendantIds($rows, $editingId),
                true
            )
        ) {
            throw new CatalogValidationException([
                'parent_id' =>
                    'No puedes usar un descendiente como clasificación padre.',
            ]);
        }

        return $parentId;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function requireRecord(array $rows, int $id): array
    {
        $record = $this->findRecord($rows, $id);

        if ($record !== null) {
            return $record;
        }

        throw new CatalogValidationException([
            'id' => 'La clasificación solicitada no existe.',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findRecord(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decorate(array $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $memo = [];
        $visiting = [];
        $records = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $hierarchy = $this->resolveHierarchy(
                $id,
                $byId,
                $memo,
                $visiting
            );
            $parentId = $this->nullableId($row['parent_id']);
            $row['parent_name'] = $parentId === null
                ? null
                : (string) ($byId[$parentId]['nombre'] ?? '');
            $row['path'] = $hierarchy['path'];
            $row['level'] = $hierarchy['level'];
            $row['parent_active'] = $parentId === null
                || (int) ($byId[$parentId]['activo'] ?? 0) === 1;
            $row['has_active_descendants'] =
                $this->hasActiveDescendants($rows, $id);
            $records[] = $row;
        }

        usort(
            $records,
            static fn (array $left, array $right): int =>
                strnatcasecmp((string) $left['path'], (string) $right['path'])
        );

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $byId
     * @param array<int, array{path: string, level: int}> $memo
     * @param array<int, bool> $visiting
     * @return array{path: string, level: int}
     */
    private function resolveHierarchy(
        int $id,
        array $byId,
        array &$memo,
        array &$visiting
    ): array {
        if (isset($memo[$id])) {
            return $memo[$id];
        }

        if (isset($visiting[$id])) {
            throw new \RuntimeException(
                'Classification hierarchy contains an existing cycle.'
            );
        }

        $record = $byId[$id] ?? null;

        if ($record === null) {
            throw new \RuntimeException(
                'Classification hierarchy contains an orphan.'
            );
        }

        $visiting[$id] = true;
        $parentId = $this->nullableId($record['parent_id']);

        if ($parentId === null) {
            $result = [
                'path' => (string) $record['nombre'],
                'level' => 0,
            ];
        } else {
            $parent = $this->resolveHierarchy(
                $parentId,
                $byId,
                $memo,
                $visiting
            );
            $result = [
                'path' => $parent['path'] . ' / ' . $record['nombre'],
                'level' => $parent['level'] + 1,
            ];
        }

        unset($visiting[$id]);
        $memo[$id] = $result;

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private function parentOptions(array $records, ?int $editingId): array
    {
        $excluded = $editingId === null
            ? []
            : [$editingId, ...$this->descendantIds($records, $editingId)];

        return array_values(array_filter(
            $records,
            static fn (array $record): bool =>
                !in_array((int) $record['id'], $excluded, true)
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function descendantIds(array $rows, int $id): array
    {
        $children = [];

        foreach ($rows as $row) {
            $parentId = $this->nullableId($row['parent_id']);

            if ($parentId !== null) {
                $children[$parentId][] = (int) $row['id'];
            }
        }

        $descendants = [];
        $pending = $children[$id] ?? [];

        while ($pending !== []) {
            $childId = array_shift($pending);

            if (in_array($childId, $descendants, true)) {
                continue;
            }

            $descendants[] = $childId;

            foreach ($children[$childId] ?? [] as $nestedId) {
                $pending[] = $nestedId;
            }
        }

        return $descendants;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function hasActiveDescendants(array $rows, int $id): bool
    {
        $descendants = $this->descendantIds($rows, $id);

        foreach ($rows as $row) {
            if (
                in_array((int) $row['id'], $descendants, true)
                && (int) $row['activo'] === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function id(mixed $value): int
    {
        $id = $this->optionalId($value);

        if (!is_int($id)) {
            throw new CatalogValidationException([
                'id' => 'La clasificación solicitada no es válida.',
            ]);
        }

        return $id;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    private function optionalId(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (
            (!is_string($value) && !is_int($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
        ) {
            return false;
        }

        return (int) $value;
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId < 1) {
            throw new \InvalidArgumentException('A valid actor is required.');
        }
    }

    private function convertDatabaseError(PDOException $exception): void
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($driverCode === 1062) {
            throw new CatalogValidationException([
                'codigo' => 'El código ya existe.',
            ]);
        }

        if ($driverCode === 1452) {
            throw new CatalogValidationException([
                'parent_id' => 'La clasificación padre ya no existe.',
            ]);
        }
    }
}
