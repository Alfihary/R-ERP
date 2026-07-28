<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Pricing\PriceListService;
use App\Domain\Pricing\PricingValidationException;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class PriceListController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly PriceListService $priceLists
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user();

        return $this->render('pricing/lists/index', [
            'abilities' => $this->abilities($user['user_id']),
            'errors' => [],
            'notice' => $this->resultMessage($request),
            'result' => $this->priceLists->search($request->query()),
        ], 'Listas de precios');
    }

    public function createForm(Request $request): Response
    {
        return $this->renderForm([], [], false, 200);
    }

    public function create(Request $request): Response
    {
        $user = $this->user();

        try {
            $id = $this->priceLists->create($request->body(), $user['user_id']);
        } catch (PricingValidationException $exception) {
            return $this->renderForm($request->body(), $exception->errors(), false, 422);
        }

        return Response::redirect('/configuracion/listas-precios/ver?id=' . $id . '&result=created');
    }

    public function show(Request $request): Response
    {
        try {
            $list = $this->priceLists->get($this->idFromQuery($request));
        } catch (PricingValidationException) {
            return $this->notFound();
        }

        return $this->render('pricing/lists/show', [
            'abilities' => $this->abilities($this->user()['user_id']),
            'list' => $list,
            'notice' => $this->resultMessage($request),
        ], 'Detalle de lista de precios');
    }

    public function editForm(Request $request): Response
    {
        try {
            $list = $this->priceLists->get($this->idFromQuery($request));
        } catch (PricingValidationException) {
            return $this->notFound();
        }

        return $this->renderForm($list, [], true, 200);
    }

    public function update(Request $request): Response
    {
        $user = $this->user();
        $id = $this->idFromBody($request);

        try {
            $this->priceLists->update($id, $request->body(), $user['user_id']);
        } catch (PricingValidationException $exception) {
            return $this->renderForm(
                $request->body() + ['id' => $id],
                $exception->errors(),
                true,
                422
            );
        }

        return Response::redirect('/configuracion/listas-precios/ver?id=' . $id . '&result=updated');
    }

    public function activate(Request $request): Response
    {
        return $this->state($request, true);
    }

    public function deactivate(Request $request): Response
    {
        return $this->state($request, false);
    }

    public function setDefault(Request $request): Response
    {
        $user = $this->user();

        try {
            $this->priceLists->setDefault($this->idFromBody($request), $user['user_id']);
        } catch (PricingValidationException $exception) {
            return $this->render('pricing/lists/index', [
                'abilities' => $this->abilities($user['user_id']),
                'errors' => $exception->errors(),
                'notice' => null,
                'result' => $this->priceLists->search($request->query()),
            ], 'Listas de precios', 422);
        }

        return Response::redirect('/configuracion/listas-precios?result=default');
    }

    private function state(Request $request, bool $active): Response
    {
        $user = $this->user();

        try {
            if ($active) {
                $this->priceLists->activate($this->idFromBody($request), $user['user_id']);
            } else {
                $this->priceLists->deactivate($this->idFromBody($request), $user['user_id']);
            }
        } catch (PricingValidationException $exception) {
            return $this->render('pricing/lists/index', [
                'abilities' => $this->abilities($user['user_id']),
                'errors' => $exception->errors(),
                'notice' => null,
                'result' => $this->priceLists->search($request->query()),
            ], 'Listas de precios', 422);
        }

        return Response::redirect(
            '/configuracion/listas-precios?result=' . ($active ? 'activated' : 'deactivated')
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
        return $this->render('pricing/lists/form', [
            'editing' => $editing,
            'errors' => $errors,
            'values' => $values,
        ], $editing ? 'Editar lista de precios' : 'Crear lista de precios', $status);
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
            'activeNavigation' => 'configuration-price-lists',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'canAccessConfiguration' => true,
            'canAccessConfigCompanies' => $this->permissions->allows(
                $user['user_id'],
                'configuracion.empresas.acceder'
            ),
            'canAccessConfigWarehouses' => $this->permissions->allows(
                $user['user_id'],
                'configuracion.almacenes.acceder'
            ),
            'canAccessConfigFolios' => $this->permissions->allows(
                $user['user_id'],
                'configuracion.folios.acceder'
            ),
            'canAccessPriceLists' => true,
            'contentData' => $contentData,
            'contentView' => $contentView,
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => $pageTitle,
            'stylesheets' => ['/css/modules/pricing-lists.css'],
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
            throw new \RuntimeException('Authenticated price list controller requires a user.');
        }

        return $user;
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(int $userId): array
    {
        return [
            'ver' => $this->permissions->allows($userId, 'precios.listas.ver'),
            'crear' => $this->permissions->allows($userId, 'precios.listas.crear'),
            'editar' => $this->permissions->allows($userId, 'precios.listas.editar'),
            'activar' => $this->permissions->allows($userId, 'precios.listas.activar'),
            'predeterminada' => $this->permissions->allows($userId, 'precios.listas.predeterminada'),
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
            'created' => 'Lista creada correctamente.',
            'updated' => 'Lista actualizada correctamente.',
            'activated' => 'Lista activada correctamente.',
            'deactivated' => 'Lista desactivada correctamente.',
            'default' => 'Lista marcada como predeterminada.',
            default => null,
        };
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404'), 404);
    }
}
