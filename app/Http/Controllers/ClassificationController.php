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
use App\Domain\Catalogs\ClassificationService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class ClassificationController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly CatalogService $catalogs,
        private readonly ClassificationService $classifications
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render(
            200,
            [],
            [],
            null,
            null,
            $this->resultMessage($request)
        );
    }

    public function create(Request $request): Response
    {
        $user = $this->user();

        try {
            $this->classifications->create(
                $request->body(),
                $user['user_id']
            );
        } catch (CatalogValidationException $exception) {
            return $this->render(
                422,
                $exception->errors(),
                $request->body(),
                'create',
                null,
                null
            );
        }

        return Response::redirect(
            '/catalogos/clasificaciones?result=created'
        );
    }

    public function update(Request $request): Response
    {
        $user = $this->user();
        $id = $this->id($request);

        try {
            $this->classifications->update(
                $request->body(),
                $user['user_id']
            );
        } catch (CatalogValidationException $exception) {
            return $this->render(
                422,
                $exception->errors(),
                $request->body(),
                'update',
                $id,
                null
            );
        }

        return Response::redirect(
            '/catalogos/clasificaciones?result=updated'
        );
    }

    public function state(Request $request, bool $active): Response
    {
        $user = $this->user();
        $id = $this->id($request);

        try {
            $this->classifications->setActive(
                $request->body(),
                $active,
                $user['user_id']
            );
        } catch (CatalogValidationException $exception) {
            return $this->render(
                422,
                $exception->errors(),
                [],
                'state',
                $id,
                null
            );
        }

        return Response::redirect(
            '/catalogos/clasificaciones?result='
            . ($active ? 'activated' : 'deactivated')
        );
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $formData
     */
    private function render(
        int $status,
        array $errors,
        array $formData,
        ?string $failedAction,
        ?int $failedId,
        ?string $notice
    ): Response {
        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);
        $hierarchy = $this->classifications->viewData();
        $abilities = [];

        foreach (['crear', 'editar', 'estado'] as $action) {
            $abilities[$action] = $this->permissions->allows(
                $user['user_id'],
                'catalogos.clasificaciones.' . $action
            );
        }

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'catalogs',
            'appName' => (string) $this->config->get(
                'app.name',
                'SoporteGR ERP'
            ),
            'canAccessCatalogs' => true,
            'contentData' => [
                'abilities' => $abilities,
                'createParentOptions' =>
                    $hierarchy['create_parent_options'],
                'editParentOptions' =>
                    $hierarchy['edit_parent_options'],
                'errors' => $errors,
                'failedAction' => $failedAction,
                'failedId' => $failedId,
                'formData' => $formData,
                'notice' => $notice,
                'records' => $hierarchy['records'],
                'viewableCatalogs' =>
                    $this->viewableCatalogDefinitions($user['user_id']),
            ],
            'contentView' => 'catalogs/classifications',
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => 'Clasificaciones de producto',
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
                'Authenticated classification controller requires a user.'
            );
        }

        return $user;
    }

    private function id(Request $request): ?int
    {
        $id = filter_var(
            $request->input('id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $id === false ? null : $id;
    }

    private function resultMessage(Request $request): ?string
    {
        return match ($request->query()['result'] ?? null) {
            'created' => 'Clasificación creada correctamente.',
            'updated' => 'Clasificación actualizada correctamente.',
            'activated' => 'Clasificación activada correctamente.',
            'deactivated' => 'Clasificación desactivada correctamente.',
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
    private function viewableCatalogDefinitions(int $userId): array
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

        if ($this->permissions->allows(
            $userId,
            'catalogos.clasificaciones.ver'
        )) {
            $available['clasificaciones'] = [
                'slug' => 'clasificaciones',
                'title' => 'Clasificaciones',
                'singular' => 'clasificación',
                'permission' => 'clasificaciones',
            ];
        }

        if ($this->permissions->allows(
            $userId,
            'catalogos.tipos_cambio.ver'
        )) {
            $available['tipos_cambio'] = [
                'slug' => 'tipos-cambio',
                'title' => 'Tipos de cambio',
                'singular' => 'tipo de cambio',
                'permission' => 'tipos_cambio',
            ];
        }

        foreach ([
            'unidades_sat' => [
                'slug' => 'unidades-sat',
                'title' => 'Unidades SAT',
                'singular' => 'unidad SAT',
                'permission' => 'unidades_sat',
                'permission_code' => 'catalogos.unidades_sat.acceder',
            ],
            'claves_sat' => [
                'slug' => 'claves-sat',
                'title' => 'Claves SAT',
                'singular' => 'clave SAT',
                'permission' => 'claves_sat',
                'permission_code' => 'catalogos.claves_sat.acceder',
            ],
        ] as $key => $definition) {
            if ($this->permissions->allows(
                $userId,
                $definition['permission_code']
            )) {
                unset($definition['permission_code']);
                $available[$key] = $definition;
            }
        }

        return $available;
    }
}
