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
use App\Domain\Catalogs\SatCatalogService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class SatCatalogController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly CatalogService $catalogs,
        private readonly SatCatalogService $satCatalogs
    ) {
    }

    public function unitsIndex(Request $request): Response
    {
        return $this->renderSat('unidades_sat', $request);
    }

    public function unitsCreateForm(Request $request): Response
    {
        return $this->renderSat('unidades_sat', $request, 'create');
    }

    public function unitsEditForm(Request $request): Response
    {
        return $this->renderSat(
            'unidades_sat',
            $request,
            'update',
            $this->queryId($request)
        );
    }

    public function unitsCreate(Request $request): Response
    {
        $user = $this->user();

        try {
            $this->satCatalogs->createUnit($request->body(), $user['user_id']);
        } catch (CatalogValidationException $exception) {
            return $this->renderSat(
                'unidades_sat',
                $request,
                'create',
                null,
                $exception->errors(),
                $request->body(),
                422
            );
        }

        return Response::redirect('/catalogos/unidades-sat?result=created');
    }

    public function unitsUpdate(Request $request): Response
    {
        $user = $this->user();
        $id = $this->bodyId($request);

        try {
            $this->satCatalogs->updateUnit(
                $id,
                $request->body(),
                $user['user_id']
            );
        } catch (CatalogValidationException $exception) {
            return $this->renderSat(
                'unidades_sat',
                $request,
                'update',
                $id > 0 ? $id : null,
                $exception->errors(),
                $request->body(),
                422
            );
        }

        return Response::redirect('/catalogos/unidades-sat?result=updated');
    }

    public function unitsActivate(Request $request): Response
    {
        return $this->setUnitState($request, true);
    }

    public function unitsDeactivate(Request $request): Response
    {
        return $this->setUnitState($request, false);
    }

    public function keysIndex(Request $request): Response
    {
        return $this->renderSat('claves_sat', $request);
    }

    public function keysCreateForm(Request $request): Response
    {
        return $this->renderSat('claves_sat', $request, 'create');
    }

    public function keysEditForm(Request $request): Response
    {
        return $this->renderSat(
            'claves_sat',
            $request,
            'update',
            $this->queryId($request)
        );
    }

    public function keysCreate(Request $request): Response
    {
        $user = $this->user();

        try {
            $this->satCatalogs->createKey($request->body(), $user['user_id']);
        } catch (CatalogValidationException $exception) {
            return $this->renderSat(
                'claves_sat',
                $request,
                'create',
                null,
                $exception->errors(),
                $request->body(),
                422
            );
        }

        return Response::redirect('/catalogos/claves-sat?result=created');
    }

    public function keysUpdate(Request $request): Response
    {
        $user = $this->user();
        $id = $this->bodyId($request);

        try {
            $this->satCatalogs->updateKey(
                $id,
                $request->body(),
                $user['user_id']
            );
        } catch (CatalogValidationException $exception) {
            return $this->renderSat(
                'claves_sat',
                $request,
                'update',
                $id > 0 ? $id : null,
                $exception->errors(),
                $request->body(),
                422
            );
        }

        return Response::redirect('/catalogos/claves-sat?result=updated');
    }

    public function keysActivate(Request $request): Response
    {
        return $this->setKeyState($request, true);
    }

    public function keysDeactivate(Request $request): Response
    {
        return $this->setKeyState($request, false);
    }

    private function setUnitState(Request $request, bool $active): Response
    {
        $user = $this->user();
        $id = $this->bodyId($request);

        try {
            $this->satCatalogs->setUnitActive($id, $active, $user['user_id']);
        } catch (CatalogValidationException $exception) {
            return $this->renderSat(
                'unidades_sat',
                $request,
                null,
                null,
                $exception->errors(),
                [],
                422
            );
        }

        return Response::redirect(
            '/catalogos/unidades-sat?result='
            . ($active ? 'activated' : 'deactivated')
        );
    }

    private function setKeyState(Request $request, bool $active): Response
    {
        $user = $this->user();
        $id = $this->bodyId($request);

        try {
            $this->satCatalogs->setKeyActive($id, $active, $user['user_id']);
        } catch (CatalogValidationException $exception) {
            return $this->renderSat(
                'claves_sat',
                $request,
                null,
                null,
                $exception->errors(),
                [],
                422
            );
        }

        return Response::redirect(
            '/catalogos/claves-sat?result='
            . ($active ? 'activated' : 'deactivated')
        );
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $formData
     */
    private function renderSat(
        string $type,
        Request $request,
        ?string $mode = null,
        ?int $recordId = null,
        array $errors = [],
        array $formData = [],
        int $status = 200
    ): Response {
        $definition = $this->definition($type);
        $filters = $type === 'claves_sat'
            ? $this->satCatalogs->keyFilters($request->query())
            : $this->satCatalogs->unitFilters($request->query());
        $list = $type === 'claves_sat'
            ? $this->satCatalogs->listKeys($filters)
            : [
                'records' => $this->satCatalogs->listUnits($filters),
                'total' => null,
                'page' => 1,
                'per_page' => null,
                'total_pages' => 1,
            ];
        $record = null;

        if ($mode === 'update' && $recordId !== null) {
            try {
                $record = $type === 'claves_sat'
                    ? $this->satCatalogs->findKey($recordId)
                    : $this->satCatalogs->findUnit($recordId);
            } catch (CatalogValidationException $exception) {
                $errors = $exception->errors();
                $mode = null;
            }
        }

        $user = $this->user();
        $prefix = 'catalogos.' . $type . '.';
        $abilities = [];

        foreach (['crear', 'editar', 'estado'] as $action) {
            $abilities[$action] = $this->permissions->allows(
                $user['user_id'],
                $prefix . $action
            );
        }

        return $this->render(
            'catalogs/sat_manage',
            [
                'abilities' => $abilities,
                'definition' => $definition,
                'errors' => $errors,
                'filters' => $filters,
                'formData' => $formData,
                'mode' => $mode,
                'notice' => $this->resultMessage($request),
                'pagination' => [
                    'page' => $list['page'],
                    'per_page' => $list['per_page'],
                    'total' => $list['total'],
                    'total_pages' => $list['total_pages'],
                ],
                'record' => $record,
                'records' => $list['records'],
                'type' => $type,
                'viewableCatalogs' => $this->viewableDefinitions(
                    $user['user_id']
                ),
            ],
            $definition['title'],
            $status
        );
    }

    /**
     * @return array{slug: string, title: string, singular: string, permission: string}
     */
    private function definition(string $type): array
    {
        return match ($type) {
            'unidades_sat' => [
                'slug' => 'unidades-sat',
                'title' => 'Unidades SAT',
                'singular' => 'unidad SAT',
                'permission' => 'unidades_sat',
            ],
            'claves_sat' => [
                'slug' => 'claves-sat',
                'title' => 'Claves SAT',
                'singular' => 'clave SAT',
                'permission' => 'claves_sat',
            ],
            default => throw new \InvalidArgumentException('Invalid SAT catalog.'),
        };
    }

    /**
     * @param array<string, mixed> $contentData
     */
    private function render(
        string $contentView,
        array $contentData,
        string $pageTitle,
        int $status
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
                'Authenticated SAT catalog controller requires a user.'
            );
        }

        return $user;
    }

    private function bodyId(Request $request): int
    {
        return $this->id($request->input('id'));
    }

    private function queryId(Request $request): int
    {
        return $this->id($request->query()['id'] ?? null);
    }

    private function id(mixed $value): int
    {
        $id = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $id === false ? 0 : (int) $id;
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

        foreach ([
            'clasificaciones' => [
                'slug' => 'clasificaciones',
                'title' => 'Clasificaciones',
                'singular' => 'clasificación',
                'permission' => 'clasificaciones',
                'permission_code' => 'catalogos.clasificaciones.ver',
            ],
            'tipos_cambio' => [
                'slug' => 'tipos-cambio',
                'title' => 'Tipos de cambio',
                'singular' => 'tipo de cambio',
                'permission' => 'tipos_cambio',
                'permission_code' => 'catalogos.tipos_cambio.ver',
            ],
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
