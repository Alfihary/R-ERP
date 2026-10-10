<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Users\UserAdminConflictException;
use App\Domain\Users\UserAdminService;
use App\Domain\Users\UserAdminValidationException;
use App\Infrastructure\Repositories\RoleRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

final class AdminUserController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly UserAdminService $users,
        private readonly UserRepository $userRepository,
        private readonly RoleRepository $roleRepository,
        private readonly ScopeRepository $scopeRepository
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render('admin/users/index', [
            'result' => $this->userRepository->paginateAdmin($request->query()),
            'companies' => $this->scopeRepository->activeCompanyOptions(),
            'warehouses' => $this->scopeRepository->activeWarehouseOptions(),
            'roles' => $this->roleRepository->activeOptions(),
            'notice' => $this->resultMessage($request),
            'abilities' => $this->abilities(),
        ], 'Usuarios');
    }

    public function createForm(Request $request): Response
    {
        return $this->renderForm([], [], false, 200);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->users->create($this->serviceInput($request->body(), true), $this->actorId());
        } catch (UserAdminValidationException $exception) {
            return $this->renderForm($this->safeOldInput($request->body()), $exception->errors(), false, 422);
        } catch (UserAdminConflictException $exception) {
            return $this->renderForm($this->safeOldInput($request->body()), ['general' => $exception->getMessage()], false, 409);
        }

        return Response::redirect('/admin/usuarios?result=created&id=' . $id);
    }

    /** @param array<string,string> $params */
    public function editForm(Request $request, array $params): Response
    {
        $id = $this->idFromParams($params);
        $user = $this->userRepository->findAdminById($id);
        if ($user === null || $user['eliminado_en'] !== null) {
            return $this->notFound();
        }

        $user['roles'] = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->roleRepository->activeRolesForUser($id)
        );
        return $this->renderForm($user, [], true, 200, $id);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $id = $this->idFromParams($params);
        if ($this->userRepository->findAdminById($id) === null) {
            return $this->notFound();
        }
        try {
            $this->users->update($id, $this->serviceInput($request->body(), false), $this->actorId());
        } catch (UserAdminValidationException $exception) {
            return $this->renderForm($this->safeOldInput($request->body()), $exception->errors(), true, 422, $id);
        } catch (UserAdminConflictException $exception) {
            return $this->renderForm($this->safeOldInput($request->body()), ['general' => $exception->getMessage()], true, 409, $id);
        }
        return Response::redirect('/admin/usuarios?result=updated');
    }

    /** @param array<string,string> $params */
    public function changeStatus(Request $request, array $params): Response
    {
        $id = $this->idFromParams($params);
        if ($this->userRepository->findAdminById($id) === null) {
            return $this->notFound();
        }
        $active = (string) $request->input('active') === '1';
        try {
            $this->users->changeStatus($id, $active, $this->actorId());
        } catch (UserAdminConflictException|UserAdminValidationException $exception) {
            return Response::redirect('/admin/usuarios?result=conflict');
        }
        return Response::redirect('/admin/usuarios?result=' . ($active ? 'activated' : 'deactivated'));
    }

    /** @param array<string,string> $params */
    public function softDelete(Request $request, array $params): Response
    {
        $id = $this->idFromParams($params);
        if ($this->userRepository->findAdminById($id) === null) {
            return $this->notFound();
        }
        try {
            $this->users->softDelete($id, $this->actorId());
        } catch (UserAdminConflictException|UserAdminValidationException $exception) {
            return Response::redirect('/admin/usuarios?result=conflict');
        }
        return Response::redirect('/admin/usuarios?result=deleted');
    }

    /** @param array<string,string> $params */
    public function roles(Request $request, array $params): Response
    {
        $id = $this->idFromParams($params);
        if ($this->userRepository->findAdminById($id) === null) {
            return $this->notFound();
        }
        $roleIds = is_array($request->input('roles')) ? $request->input('roles') : [];
        try {
            $this->users->syncRoles($id, $roleIds, $this->actorId());
        } catch (UserAdminConflictException|UserAdminValidationException $exception) {
            return Response::redirect('/admin/usuarios?result=conflict');
        }
        return Response::redirect('/admin/usuarios?result=roles');
    }

    /** @param array<string,string> $params */
    public function password(Request $request, array $params): Response
    {
        $id = $this->idFromParams($params);
        if ($this->userRepository->findAdminById($id) === null) {
            return $this->notFound();
        }
        try {
            $this->users->resetPassword(
                $id,
                (string) $request->input('password', ''),
                (string) $request->input('password_confirmation', ''),
                $this->actorId()
            );
        } catch (UserAdminConflictException|UserAdminValidationException $exception) {
            return Response::redirect('/admin/usuarios?result=validation');
        }
        return Response::redirect('/admin/usuarios?result=password');
    }

    /** @return array<string,bool> */
    private function abilities(): array
    {
        $id = $this->actorId();
        return [
            'acceder' => $this->permissions->allows($id, 'usuarios.acceder'),
            'crear' => $this->permissions->allows($id, 'usuarios.crear'),
            'editar' => $this->permissions->allows($id, 'usuarios.editar'),
            'estado' => $this->permissions->allows($id, 'usuarios.estado'),
            'roles' => $this->permissions->allows($id, 'usuarios.roles'),
            'password' => $this->permissions->allows($id, 'usuarios.password'),
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function serviceInput(array $body, bool $withPassword): array
    {
        $input = [
            'username' => $body['username'] ?? '',
            'email' => $body['email'] ?? '',
            'company_id' => $body['company_id'] ?? null,
            'warehouse_id' => $body['warehouse_id'] ?? null,
            'roles' => is_array($body['roles'] ?? null) ? $body['roles'] : [],
            'activo' => ($body['activo'] ?? null) === '1',
        ];
        if ($withPassword) {
            $input['password'] = $body['password'] ?? '';
        }
        return $input;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function safeOldInput(array $body): array
    {
        unset($body['password'], $body['password_confirmation'], $body['_token']);
        return $body;
    }

    /** @param array<string,mixed> $values @param array<string,string> $errors */
    private function renderForm(array $values, array $errors, bool $editing, int $status, ?int $id = null): Response
    {
        return $this->render('admin/users/form', [
            'values' => $values,
            'errors' => $errors,
            'editing' => $editing,
            'userId' => $id,
            'companies' => $this->scopeRepository->activeCompanyOptions(),
            'warehouses' => $this->scopeRepository->activeWarehouseOptions(),
            'roles' => $this->roleRepository->activeOptions(),
            'abilities' => $this->abilities(),
        ], $editing ? 'Editar usuario' : 'Crear usuario', $status);
    }

    /** @param array<string,mixed> $data */
    private function render(string $view, array $data, string $title, int $status = 200): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $context = $this->scopeContext->resolveForUser((int) $user['user_id']);
        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'admin-users',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'contentData' => $data,
            'contentView' => $view,
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => $title,
            'stylesheets' => ['/css/modules/admin-users.css'],
            'scripts' => ['/js/modules/admin-users.js'],
            'user' => $user,
        ]), $status);
    }

    private function actorId(): int
    {
        return (int) (($this->auth->user() ?? [])['user_id'] ?? 0);
    }

    /** @param array<string,string> $params */
    private function idFromParams(array $params): int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? 0 : (int) $id;
    }

    private function resultMessage(Request $request): ?string
    {
        return match ($request->query()['result'] ?? null) {
            'created' => 'Usuario creado correctamente.',
            'updated' => 'Usuario actualizado correctamente.',
            'activated' => 'Usuario activado correctamente.',
            'deactivated' => 'Usuario desactivado correctamente.',
            'deleted' => 'Usuario desactivado y marcado como eliminado.',
            'roles' => 'Roles actualizados correctamente.',
            'password' => 'Contraseña restablecida correctamente.',
            'conflict' => 'No es posible realizar la operación solicitada.',
            'validation' => 'No fue posible restablecer la contraseña.',
            default => null,
        };
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404'), 404);
    }
}
