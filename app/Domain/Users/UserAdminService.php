<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Domain\Audit\AuditRecorderInterface;
use App\Infrastructure\Repositories\RoleRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;

final class UserAdminService
{
    private const USERNAME_PATTERN = '/^[a-z0-9._-]{3,50}$/';
    private const PASSWORD_MIN_LENGTH = 12;
    private const PASSWORD_MAX_LENGTH = 255;

    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly ScopeRepository $scope,
        private readonly AuditRecorderInterface $audit
    ) {
    }

    /** @param array<string,mixed> $input */
    public function create(array $input, int $actorId): int
    {
        $data = $this->validatedInput($input, null, true);
        $this->assertActor($actorId);

        return $this->users->transactional(function () use ($data, $actorId): int {
            $this->assertUnique($data['username'], $data['email'], null);
            $this->assertScope($data['company_id'], $data['warehouse_id']);
            $roleRows = $this->validateRoles($data['roles']);
            $this->lockAdminSet();
            $userId = $this->users->insertAdmin([
                'username' => $data['username'],
                'email' => $data['email'],
                'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                'activo' => $data['activo'] ? 1 : 0,
                'creado_por' => $actorId,
                'actualizado_por' => $actorId,
            ]);
            $this->scope->replaceUserScope($userId, $data['company_id'], $data['warehouse_id'], $actorId);
            $this->roles->replaceUserRoles($userId, array_map(static fn (array $row): int => (int) $row['id'], $roleRows), $actorId);
            if ($data['activo'] && $roleRows === []) {
                throw new UserAdminValidationException(['roles' => 'Un usuario activo requiere al menos un rol.']);
            }
            $this->auditUser('usuario.creado', $actorId, $userId, [
                'target_user_id' => $userId,
                'changed_fields' => ['username', 'email', 'activo', 'empresa_id', 'almacen_id', 'roles'],
                'status_after' => $data['activo'] ? 1 : 0,
                'role_ids_after' => array_map(static fn (array $row): int => (int) $row['id'], $roleRows),
                'empresa_after' => $data['company_id'],
                'almacen_after' => $data['warehouse_id'],
            ]);
            return $userId;
        });
    }

    /** @param array<string,mixed> $input */
    public function update(int $targetUserId, array $input, int $actorId): void
    {
        if (array_intersect(array_keys($input), ['password', 'password_hash', 'password_confirmation'])) {
            throw new UserAdminValidationException(['password' => 'La contraseña usa una operación separada.']);
        }
        $data = $this->validatedInput($input, $targetUserId, false);
        $this->users->transactional(function () use ($targetUserId, $actorId, $data): void {
            [$target, $admins] = $this->lockAdminContextAndTarget($targetUserId);
            $this->assertUnique($data['username'], $data['email'], $targetUserId);
            $this->assertScope($data['company_id'], $data['warehouse_id']);
            $roleRows = $this->validateRoles($data['roles']);
            $beforeRoles = $this->roles->activeRolesForUser($targetUserId);
            $this->guardAdminMutation($target, $beforeRoles, $roleRows, (bool) $data['activo'], count($admins));
            $this->users->updateAdmin($targetUserId, [
                'username' => $data['username'],
                'email' => $data['email'],
                'actualizado_por' => $actorId,
            ]);
            $this->users->setActive($targetUserId, (bool) $data['activo'], $actorId);
            $this->scope->replaceUserScope($targetUserId, $data['company_id'], $data['warehouse_id'], $actorId);
            $this->roles->replaceUserRoles($targetUserId, array_map(static fn (array $row): int => (int) $row['id'], $roleRows), $actorId);
            $this->auditUser('usuario.actualizado', $actorId, $targetUserId, [
                'target_user_id' => $targetUserId,
                'changed_fields' => ['username', 'email', 'activo', 'empresa_id', 'almacen_id', 'roles'],
                'status_before' => (int) $target['activo'],
                'status_after' => $data['activo'] ? 1 : 0,
                'role_ids_before' => array_map(static fn (array $row): int => (int) $row['id'], $beforeRoles),
                'role_ids_after' => array_map(static fn (array $row): int => (int) $row['id'], $roleRows),
            ]);
        });
    }

    public function changeStatus(int $targetUserId, bool $active, int $actorId): void
    {
        $this->users->transactional(function () use ($targetUserId, $active, $actorId): void {
            [$target, $admins] = $this->lockAdminContextAndTarget($targetUserId);
            $roles = $this->roles->activeRolesForUser($targetUserId);
            $this->guardAdminMutation($target, $roles, $roles, $active, count($admins));
            $this->users->setActive($targetUserId, $active, $actorId);
            $this->auditUser('usuario.estado_actualizado', $actorId, $targetUserId, [
                'target_user_id' => $targetUserId,
                'status_before' => (int) $target['activo'],
                'status_after' => $active ? 1 : 0,
            ]);
        });
    }

    public function softDelete(int $targetUserId, int $actorId): void
    {
        $this->users->transactional(function () use ($targetUserId, $actorId): void {
            [$target, $admins] = $this->lockAdminContextAndTarget($targetUserId);
            $roles = $this->roles->activeRolesForUser($targetUserId);
            $this->guardAdminMutation($target, $roles, [], false, count($admins));
            $this->users->softDelete($targetUserId, $actorId);
            $this->roles->deactivateUserRoles($targetUserId, $actorId);
            $this->auditUser('usuario.eliminado', $actorId, $targetUserId, [
                'target_user_id' => $targetUserId,
                'status_before' => (int) $target['activo'],
                'status_after' => 0,
            ]);
        });
    }

    /** @param list<int|string> $roleIds */
    public function syncRoles(int $targetUserId, array $roleIds, int $actorId): void
    {
        $this->users->transactional(function () use ($targetUserId, $roleIds, $actorId): void {
            [$target, $admins] = $this->lockAdminContextAndTarget($targetUserId);
            $before = $this->roles->activeRolesForUser($targetUserId);
            $desired = $this->normalizeRoleIds($roleIds);
            $valid = $this->validateRoles($desired);
            if ((int) $target['activo'] === 1 && $valid === []) {
                throw new UserAdminValidationException(['roles' => 'Un usuario activo requiere al menos un rol.']);
            }
            $this->guardAdminMutation($target, $before, $valid, true, count($admins));
            $afterIds = array_map(static fn (array $row): int => (int) $row['id'], $valid);
            $this->roles->replaceUserRoles($targetUserId, $afterIds, $actorId);
            $this->auditUser('usuario.roles_actualizados', $actorId, $targetUserId, [
                'target_user_id' => $targetUserId,
                'role_ids_before' => array_map(static fn (array $row): int => (int) $row['id'], $before),
                'role_ids_after' => $afterIds,
            ]);
        });
    }

    public function resetPassword(int $targetUserId, string $password, string $confirmation, int $actorId): void
    {
        if ($password !== $confirmation || !$this->validPassword($password)) {
            throw new UserAdminValidationException(['password' => 'La contraseña no cumple la política o su confirmación no coincide.']);
        }
        $this->users->transactional(function () use ($targetUserId, $password, $actorId): void {
            $this->requireTargetForUpdate($targetUserId);
            $this->users->updateAdminPassword($targetUserId, password_hash($password, PASSWORD_DEFAULT), $actorId);
            $this->auditUser('usuario.password_reiniciada', $actorId, $targetUserId, [
                'target_user_id' => $targetUserId,
            ]);
        });
    }

    /** @param array<string,mixed> $input @return array{username:string,email:string,password:string,company_id:int,warehouse_id:int,roles:list<int>,activo:bool} */
    private function validatedInput(array $input, ?int $targetId, bool $withPassword): array
    {
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $companyId = filter_var($input['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $warehouseId = filter_var($input['warehouse_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $errors = [];
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            $errors['username'] = 'Username: 3-50 caracteres ASCII minúsculos, números, punto, guion o guion bajo.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            $errors['email'] = 'El email no es válido.';
        }
        if ($withPassword && !$this->validPassword($password)) {
            $errors['password'] = 'La contraseña debe tener entre 12 y 255 caracteres.';
        }
        if ($companyId === false) {
            $errors['company_id'] = 'La empresa es obligatoria.';
        }
        if ($warehouseId === false) {
            $errors['warehouse_id'] = 'El almacén es obligatorio.';
        }
        if ($errors !== []) {
            throw new UserAdminValidationException($errors);
        }
        $roles = $this->normalizeRoleIds(is_array($input['roles'] ?? null) ? $input['roles'] : []);
        return [
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'company_id' => (int) $companyId,
            'warehouse_id' => (int) $warehouseId,
            'roles' => $roles,
            'activo' => !array_key_exists('activo', $input) || (bool) $input['activo'],
        ];
    }

    private function validPassword(string $password): bool
    {
        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        return $length >= self::PASSWORD_MIN_LENGTH && $length <= self::PASSWORD_MAX_LENGTH;
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId < 1) {
            throw new UserAdminValidationException(['actor' => 'El actor administrativo no es válido.']);
        }
    }

    private function assertUnique(string $username, string $email, ?int $targetId): void
    {
        if ($this->users->existsUsername($username, $targetId)) {
            throw new UserAdminConflictException('El username ya existe.');
        }
        if ($this->users->existsEmail($email, $targetId)) {
            throw new UserAdminConflictException('El email ya existe.');
        }
    }

    private function assertScope(int $companyId, int $warehouseId): void
    {
        if ($this->scope->activeCompany($companyId) === null) {
            throw new UserAdminValidationException(['company_id' => 'La empresa no existe o no está activa.']);
        }
        if ($this->scope->activeWarehouseForCompany($warehouseId, $companyId) === null) {
            throw new UserAdminValidationException(['warehouse_id' => 'El almacén no pertenece a la empresa o no está activo.']);
        }
    }

    /** @param list<int> $roleIds @return list<array{id:int,codigo:string,nombre:string,activo:int,eliminado_en:string|null}> */
    private function validateRoles(array $roleIds): array
    {
        $normalized = $this->normalizeRoleIds($roleIds);
        if ($normalized === []) {
            return [];
        }
        $rows = $this->roles->activeRolesByIds($normalized);
        if (count($rows) !== count($normalized)) {
            throw new UserAdminValidationException(['roles' => 'Uno o más roles no existen o no están activos.']);
        }
        return $rows;
    }

    /** @param list<int|string> $roleIds @return list<int> */
    private function normalizeRoleIds(array $roleIds): array
    {
        $result = [];
        foreach ($roleIds as $roleId) {
            if (filter_var($roleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new UserAdminValidationException(['roles' => 'Cada rol debe ser un entero positivo.']);
            }
            $result[] = (int) $roleId;
        }
        return array_values(array_unique($result));
    }

    /** @return array<string,mixed> */
    private function requireTargetForUpdate(int $targetUserId): array
    {
        if ($targetUserId < 1) {
            throw new UserAdminValidationException(['user_id' => 'El usuario no es válido.']);
        }
        $target = $this->users->findByIdForUpdate($targetUserId);
        if ($target === null || $target['eliminado_en'] !== null) {
            throw new UserAdminValidationException(['user_id' => 'El usuario no existe o está eliminado.']);
        }
        return $target;
    }

    /** @return array{0:array<string,mixed>,1:list<array{id:int,username:string,email:string,activo:int,eliminado_en:string|null}>} */
    private function lockAdminContextAndTarget(int $targetUserId): array
    {
        $admins = $this->lockAdminSet();
        $target = $this->requireTargetForUpdate($targetUserId);
        $this->roles->lockUserRoleRows($targetUserId);
        return [$target, $admins];
    }

    /** @return list<array{id:int,username:string,email:string,activo:int,eliminado_en:string|null}> */
    private function lockAdminSet(): array
    {
        $role = $this->roles->lockStructural('ADMIN');
        if ($role === null) {
            throw new UserAdminConflictException('El rol estructural ADMIN no está disponible.');
        }
        return $this->users->usableAdminsForUpdate((int) $role['id']);
    }

    /** @param array<string,mixed> $target @param list<array> $before @param list<array> $after */
    private function guardAdminMutation(array $target, array $before, array $after, bool $activeAfter, int $usableAdminCount): void
    {
        $beforeAdmin = false;
        $afterAdmin = false;
        foreach ($before as $role) {
            if ((string) ($role['codigo'] ?? '') === 'ADMIN') { $beforeAdmin = true; break; }
        }
        foreach ($after as $role) {
            if ((string) ($role['codigo'] ?? '') === 'ADMIN') { $afterAdmin = true; break; }
        }
        if (!$beforeAdmin || ($activeAfter && $afterAdmin)) {
            return;
        }
        if ($usableAdminCount <= 1) {
            throw new UserAdminConflictException('No es posible dejar al sistema sin un ADMIN utilizable.');
        }
    }

    /** @param array<string,mixed> $metadata */
    private function auditUser(string $action, int $actorId, int $targetId, array $metadata): void
    {
        $this->audit->recordRequired($action, $actorId, $metadata + [
            'entidad' => 'usuarios',
            'entidad_id' => (string) $targetId,
            'resultado' => 'ok',
        ]);
    }
}
