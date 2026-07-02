<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Catalogs\CatalogService;
use App\Domain\Catalogs\CatalogValidationException;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class CatalogController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly CatalogService $catalogs
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user();

        return $this->render(
            'catalogs/index',
            [
                'availableCatalogs' => $this->viewableDefinitions(
                    $user['user_id']
                ),
            ],
            'Catálogos'
        );
    }

    public function show(Request $request, string $catalog): Response
    {
        return $this->renderCatalog(
            $catalog,
            200,
            [],
            [],
            null,
            null,
            $this->resultMessage($request)
        );
    }

    public function create(Request $request, string $catalog): Response
    {
        $user = $this->user();

        try {
            $this->catalogs->create(
                $catalog,
                $request->body(),
                $user['user_id']
            );
        } catch (CatalogValidationException $exception) {
            return $this->renderCatalog(
                $catalog,
                422,
                $exception->errors(),
                $request->body(),
                'create',
                null,
                null
            );
        }

        return Response::redirect(
            '/catalogos/' . $this->catalogs->definition($catalog)['slug']
            . '?result=created'
        );
    }

    public function update(Request $request, string $catalog): Response
    {
        $user = $this->user();
        $id = $this->id($request);

        try {
            $this->catalogs->update(
                $catalog,
                $id,
                $request->body(),
                $user['user_id']
            );
        } catch (CatalogValidationException $exception) {
            return $this->renderCatalog(
                $catalog,
                422,
                $exception->errors(),
                $request->body(),
                'update',
                $id > 0 ? $id : null,
                null
            );
        }

        return Response::redirect(
            '/catalogos/' . $this->catalogs->definition($catalog)['slug']
            . '?result=updated'
        );
    }

    public function state(
        Request $request,
        string $catalog,
        bool $active
    ): Response {
        $user = $this->user();
        $id = $this->id($request);

        try {
            $this->catalogs->setActive(
                $catalog,
                $id,
                $active,
                $user['user_id']
            );
        } catch (CatalogValidationException $exception) {
            return $this->renderCatalog(
                $catalog,
                422,
                $exception->errors(),
                [],
                'state',
                $id > 0 ? $id : null,
                null
            );
        }

        return Response::redirect(
            '/catalogos/' . $this->catalogs->definition($catalog)['slug']
            . '?result=' . ($active ? 'activated' : 'deactivated')
        );
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $formData
     */
    private function renderCatalog(
        string $catalog,
        int $status,
        array $errors,
        array $formData,
        ?string $failedAction,
        ?int $failedId,
        ?string $notice
    ): Response {
        $definition = $this->catalogs->definition($catalog);
        $user = $this->user();
        $prefix = 'catalogos.' . $definition['permission'] . '.';
        $abilities = [];

        foreach (['crear', 'editar', 'estado'] as $action) {
            $abilities[$action] = $this->permissions->allows(
                $user['user_id'],
                $prefix . $action
            );
        }

        return $this->render(
            'catalogs/manage',
            [
                'abilities' => $abilities,
                'catalog' => $catalog,
                'catalogDefinition' => $definition,
                'errors' => $errors,
                'failedAction' => $failedAction,
                'failedId' => $failedId,
                'formData' => $formData,
                'notice' => $notice,
                'records' => $this->catalogs->list($catalog),
                'viewableCatalogs' => $this->viewableDefinitions(
                    $user['user_id']
                ),
            ],
            $definition['title'],
            $status
        );
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
            'activeNavigation' => 'catalogs',
            'appName' => (string) $this->config->get(
                'app.name',
                'SoporteGR ERP'
            ),
            'canAccessCatalogs' => true,
            'contentData' => $contentData,
            'contentView' => $contentView,
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => $pageTitle,
            'stylesheets' => ['/css/modules/catalogs.css'],
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
            throw new \RuntimeException(
                'Authenticated catalog controller requires a user.'
            );
        }

        return $user;
    }

    private function id(Request $request): int
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
        $result = $request->query()['result'] ?? null;

        return match ($result) {
            'created' => 'Registro creado correctamente.',
            'updated' => 'Registro actualizado correctamente.',
            'activated' => 'Registro activado correctamente.',
            'deactivated' => 'Registro desactivado correctamente.',
            default => null,
        };
    }

    /**
     * @return array<string, array{
     *     slug: string,
     *     title: string,
     *     singular: string,
     *     permission: string
     * }>
     */
    private function viewableDefinitions(int $userId): array
    {
        $available = [];

        foreach ($this->catalogs->definitions() as $key => $definition) {
            if ($this->permissions->allows(
                $userId,
                'catalogos.' . $definition['permission'] . '.ver'
            )) {
                $available[$key] = $definition;
            }
        }

        return $available;
    }
}
