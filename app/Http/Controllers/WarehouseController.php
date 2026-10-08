<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Configuration\CompanyService;
use App\Domain\Configuration\ConfigurationValidationException;
use App\Domain\Configuration\WarehouseService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class WarehouseController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly WarehouseService $warehouses,
        private readonly CompanyService $companies
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user();

        return $this->render('configuration/warehouses/index', [
            'abilities' => $this->abilities($user['user_id']),
            'companies' => $this->companies->activeOptions(),
            'notice' => $this->resultMessage($request),
            'result' => $this->warehouses->search($request->query()),
        ], 'Almacenes');
    }

    public function createForm(Request $request): Response
    {
        return $this->renderForm([], [], false, 200);
    }

    public function create(Request $request): Response
    {
        $user = $this->user();

        try {
            $id = $this->warehouses->create($request->body(), $user['user_id']);
        } catch (ConfigurationValidationException $exception) {
            return $this->renderForm($request->body(), $exception->errors(), false, 422);
        }

        return Response::redirect('/configuracion/almacenes/ver?id=' . $id . '&result=created');
    }

    public function show(Request $request): Response
    {
        try {
            $warehouse = $this->warehouses->get($this->idFromQuery($request));
        } catch (ConfigurationValidationException) {
            return $this->notFound();
        }

        return $this->render('configuration/warehouses/show', [
            'abilities' => $this->abilities($this->user()['user_id']),
            'notice' => $this->resultMessage($request),
            'warehouse' => $warehouse,
        ], 'Detalle de almacén');
    }

    public function editForm(Request $request): Response
    {
        try {
            $warehouse = $this->warehouses->get($this->idFromQuery($request));
        } catch (ConfigurationValidationException) {
            return $this->notFound();
        }

        return $this->renderForm($warehouse, [], true, 200);
    }

    public function update(Request $request): Response
    {
        $user = $this->user();
        $id = $this->idFromBody($request);

        try {
            $this->warehouses->update($id, $request->body(), $user['user_id']);
        } catch (ConfigurationValidationException $exception) {
            return $this->renderForm(
                $request->body() + ['id' => $id],
                $exception->errors(),
                true,
                422
            );
        }

        return Response::redirect('/configuracion/almacenes/ver?id=' . $id . '&result=updated');
    }

    public function activate(Request $request): Response
    {
        return $this->state($request, true);
    }

    public function deactivate(Request $request): Response
    {
        return $this->state($request, false);
    }

    private function state(Request $request, bool $active): Response
    {
        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id'])->toArray();
        $id = $this->idFromBody($request);

        try {
            $this->warehouses->setActive(
                $id,
                $active,
                $user['user_id'],
                is_array($context['active_warehouse'] ?? null)
                    ? $context['active_warehouse']
                    : null
            );
        } catch (ConfigurationValidationException $exception) {
            return $this->render('configuration/warehouses/index', [
                'abilities' => $this->abilities($user['user_id']),
                'companies' => $this->companies->activeOptions(),
                'errors' => $exception->errors(),
                'notice' => null,
                'result' => $this->warehouses->search($request->query()),
            ], 'Almacenes', 422);
        }

        return Response::redirect(
            '/configuracion/almacenes?result=' . ($active ? 'activated' : 'deactivated')
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderForm(
        array $values,
        array $errors,
        bool $editing,
        int $status
    ): Response {
        return $this->render('configuration/warehouses/form', [
            'companies' => $this->companies->activeOptions(),
            'editing' => $editing,
            'errors' => $errors,
            'types' => WarehouseService::TYPES,
            'values' => $values,
        ], $editing ? 'Editar almacén' : 'Crear almacén', $status);
    }

    /**
     * @param array<string, mixed> $contentData
     */
    private function render(
        string $contentView,
        array $contentData,
        string $pageTitle,
        int $status = 200
    ): Response {
        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'configuration-warehouses',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'canAccessConfiguration' => true,
            'canAccessConfigCompanies' => $this->permissions->allows(
                $user['user_id'],
                'configuracion.empresas.acceder'
            ),
            'canAccessConfigWarehouses' => true,
            'contentData' => $contentData,
            'contentView' => $contentView,
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => $pageTitle,
            'stylesheets' => ['/css/modules/config-warehouses.css'],
            'user' => $user,
        ]), $status);
    }

    /**
     * @return array{user_id: int, username: string, email: string}
     */
    private function user(): array
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new \RuntimeException('Authenticated warehouse controller requires a user.');
        }

        return $user;
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(int $userId): array
    {
        return [
            'ver' => $this->permissions->allows($userId, 'configuracion.almacenes.ver'),
            'crear' => $this->permissions->allows($userId, 'configuracion.almacenes.crear'),
            'editar' => $this->permissions->allows($userId, 'configuracion.almacenes.editar'),
            'desactivar' => $this->permissions->allows($userId, 'configuracion.almacenes.desactivar'),
        ];
    }

    private function idFromQuery(Request $request): int
    {
        $id = filter_var(
            $request->query()['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $id === false ? 0 : $id;
    }

    private function idFromBody(Request $request): int
    {
        $id = filter_var(
            $request->input('id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $id === false ? 0 : $id;
    }

    private function resultMessage(Request $request): ?string
    {
        return match ($request->query()['result'] ?? null) {
            'created' => 'Almacén creado correctamente.',
            'updated' => 'Almacén actualizado correctamente.',
            'activated' => 'Almacén activado correctamente.',
            'deactivated' => 'Almacén desactivado correctamente.',
            default => null,
        };
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404'), 404);
    }
}
