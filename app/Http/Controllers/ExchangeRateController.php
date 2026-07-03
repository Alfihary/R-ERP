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
use App\Domain\Catalogs\ExchangeRateService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class ExchangeRateController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly CatalogService $catalogs,
        private readonly ExchangeRateService $exchangeRates
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
            $this->exchangeRates->create(
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
            '/catalogos/tipos-cambio?result=created'
        );
    }

    public function update(Request $request): Response
    {
        $user = $this->user();
        $id = $this->id($request);

        try {
            $this->exchangeRates->update(
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
            '/catalogos/tipos-cambio?result=updated'
        );
    }

    public function state(Request $request, bool $active): Response
    {
        $user = $this->user();
        $id = $this->id($request);

        try {
            $this->exchangeRates->setActive(
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
            '/catalogos/tipos-cambio?result='
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
        $data = $this->exchangeRates->viewData();
        $abilities = [];

        foreach (['crear', 'editar', 'estado'] as $action) {
            $abilities[$action] = $this->permissions->allows(
                $user['user_id'],
                'catalogos.tipos_cambio.' . $action
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
                'currencies' => $data['currencies'],
                'errors' => $errors,
                'failedAction' => $failedAction,
                'failedId' => $failedId,
                'formData' => $formData,
                'notice' => $notice,
                'records' => $data['records'],
                'viewableCatalogs' =>
                    $this->viewableCatalogDefinitions($user['user_id']),
            ],
            'contentView' => 'catalogs/exchange_rates',
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => 'Tipos de cambio',
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
                'Authenticated exchange rate controller requires a user.'
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
            'created' => 'Tipo de cambio creado correctamente.',
            'updated' => 'Tipo de cambio actualizado correctamente.',
            'activated' => 'Tipo de cambio activado correctamente.',
            'deactivated' => 'Tipo de cambio desactivado correctamente.',
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

        return $available;
    }
}
