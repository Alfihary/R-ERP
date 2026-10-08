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
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class CompanyController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly CompanyService $companies
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user();

        return $this->render('configuration/companies/index', [
            'abilities' => $this->abilities($user['user_id']),
            'notice' => $this->resultMessage($request),
            'result' => $this->companies->search($request->query()),
        ], 'Empresas');
    }

    public function createForm(Request $request): Response
    {
        return $this->renderForm([], [], false, 200);
    }

    public function create(Request $request): Response
    {
        $user = $this->user();

        try {
            $id = $this->companies->create($request->body(), $user['user_id']);
        } catch (ConfigurationValidationException $exception) {
            return $this->renderForm($request->body(), $exception->errors(), false, 422);
        }

        return Response::redirect('/configuracion/empresas/ver?id=' . $id . '&result=created');
    }

    public function show(Request $request): Response
    {
        try {
            $company = $this->companies->get($this->idFromQuery($request));
        } catch (ConfigurationValidationException) {
            return $this->notFound();
        }

        return $this->render('configuration/companies/show', [
            'abilities' => $this->abilities($this->user()['user_id']),
            'company' => $company,
            'notice' => $this->resultMessage($request),
        ], 'Detalle de empresa');
    }

    public function editForm(Request $request): Response
    {
        try {
            $company = $this->companies->get($this->idFromQuery($request));
        } catch (ConfigurationValidationException) {
            return $this->notFound();
        }

        return $this->renderForm($company, [], true, 200);
    }

    public function update(Request $request): Response
    {
        $user = $this->user();
        $id = $this->idFromBody($request);

        try {
            $this->companies->update($id, $request->body(), $user['user_id']);
        } catch (ConfigurationValidationException $exception) {
            return $this->renderForm(
                $request->body() + ['id' => $id],
                $exception->errors(),
                true,
                422
            );
        }

        return Response::redirect('/configuracion/empresas/ver?id=' . $id . '&result=updated');
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
            $this->companies->setActive(
                $id,
                $active,
                $user['user_id'],
                is_array($context['active_company'] ?? null)
                    ? $context['active_company']
                    : null
            );
        } catch (ConfigurationValidationException $exception) {
            return $this->render('configuration/companies/index', [
                'abilities' => $this->abilities($user['user_id']),
                'errors' => $exception->errors(),
                'notice' => null,
                'result' => $this->companies->search($request->query()),
            ], 'Empresas', 422);
        }

        return Response::redirect(
            '/configuracion/empresas?result=' . ($active ? 'activated' : 'deactivated')
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
        return $this->render('configuration/companies/form', [
            'editing' => $editing,
            'errors' => $errors,
            'values' => $values,
        ], $editing ? 'Editar empresa' : 'Crear empresa', $status);
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
            'activeNavigation' => 'configuration-companies',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'canAccessConfiguration' => true,
            'canAccessConfigCompanies' => true,
            'canAccessConfigWarehouses' => $this->permissions->allows(
                $user['user_id'],
                'configuracion.almacenes.acceder'
            ),
            'contentData' => $contentData,
            'contentView' => $contentView,
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => $pageTitle,
            'stylesheets' => ['/css/modules/config-companies.css'],
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
            throw new \RuntimeException('Authenticated company controller requires a user.');
        }

        return $user;
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(int $userId): array
    {
        return [
            'ver' => $this->permissions->allows($userId, 'configuracion.empresas.ver'),
            'crear' => $this->permissions->allows($userId, 'configuracion.empresas.crear'),
            'editar' => $this->permissions->allows($userId, 'configuracion.empresas.editar'),
            'desactivar' => $this->permissions->allows($userId, 'configuracion.empresas.desactivar'),
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
            'created' => 'Empresa creada correctamente.',
            'updated' => 'Empresa actualizada correctamente.',
            'activated' => 'Empresa activada correctamente.',
            'deactivated' => 'Empresa desactivada correctamente.',
            default => null,
        };
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404'), 404);
    }
}
