<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Pricing\ProductPriceService;
use App\Domain\Pricing\PricingValidationException;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class ProductPriceController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly ProductPriceService $prices
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user();

        return $this->render('pricing/product-prices/index', [
            'abilities' => $this->abilities($user['user_id']),
            'errors' => [],
            'lists' => $this->prices->listarListasActivas(),
            'currencies' => $this->prices->listarMonedasActivas(),
            'notice' => $this->resultMessage($request),
            'result' => $this->prices->listPrices($request->query()),
        ], 'Precios por producto');
    }

    public function createForm(Request $request): Response
    {
        return $this->renderForm([], [], false, 200);
    }

    public function create(Request $request): Response
    {
        $user = $this->user();

        try {
            $price = $this->prices->crearPrecio($this->priceInput($request, $user['user_id']));
        } catch (PricingValidationException $exception) {
            return $this->renderForm($request->body(), $exception->errors(), false, 422);
        }

        return Response::redirect('/precios/productos/ver?id=' . $price['id'] . '&result=created');
    }

    public function show(Request $request): Response
    {
        $price = $this->prices->getPriceDetail($this->idFromQuery($request));

        if ($price === null) {
            return $this->notFound();
        }

        return $this->render('pricing/product-prices/show', [
            'abilities' => $this->abilities($this->user()['user_id']),
            'notice' => $this->resultMessage($request),
            'price' => $price,
        ], 'Detalle de precio');
    }

    public function editForm(Request $request): Response
    {
        $price = $this->prices->getPriceDetail($this->idFromQuery($request));

        if ($price === null) {
            return $this->notFound();
        }

        return $this->renderForm($price, [], true, 200);
    }

    public function update(Request $request): Response
    {
        $user = $this->user();
        $id = $this->idFromBody($request);
        $current = $this->prices->getPriceDetail($id);

        if ($current === null) {
            return $this->notFound();
        }

        try {
            $price = $this->prices->actualizarPrecio($this->updateInput($request, $id, $user['user_id']));
        } catch (PricingValidationException $exception) {
            return $this->renderForm(
                $request->body() + $current + ['id' => $id],
                $exception->errors(),
                true,
                422
            );
        }

        return Response::redirect('/precios/productos/ver?id=' . $price['id'] . '&result=updated');
    }

    public function deactivate(Request $request): Response
    {
        return $this->state($request, false);
    }

    public function reactivate(Request $request): Response
    {
        return $this->state($request, true);
    }

    public function history(Request $request): Response
    {
        $id = $this->idFromQuery($request);
        $price = $this->prices->getPriceDetail($id);

        if ($price === null) {
            return $this->notFound();
        }

        return $this->render('pricing/product-prices/history', [
            'history' => $this->prices->listarHistorialProductoPrecio($id),
            'price' => $price,
        ], 'Historial de precio');
    }

    private function state(Request $request, bool $active): Response
    {
        $user = $this->user();
        $id = $this->idFromBody($request);
        $motivo = trim((string) $request->input('motivo_cambio', ''));

        try {
            $price = $active
                ? $this->prices->reactivarPrecio($id, $user['user_id'], $motivo)
                : $this->prices->desactivarPrecio($id, $user['user_id'], $motivo);
        } catch (PricingValidationException $exception) {
            return $this->render('pricing/product-prices/index', [
                'abilities' => $this->abilities($user['user_id']),
                'errors' => $exception->errors(),
                'lists' => $this->prices->listarListasActivas(),
                'currencies' => $this->prices->listarMonedasActivas(),
                'notice' => null,
                'result' => $this->prices->listPrices($request->query()),
            ], 'Precios por producto', 422);
        }

        return Response::redirect(
            '/precios/productos/ver?id='
            . $price['id']
            . '&result='
            . ($active ? 'reactivated' : 'deactivated')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function priceInput(Request $request, int $userId): array
    {
        return [
            'id_producto' => (string) $request->input('id_producto', ''),
            'lista_precio_id' => $request->input('lista_precio_id'),
            'precio_lista' => $request->input('precio_lista'),
            'precio_minimo' => $request->input('precio_minimo'),
            'motivo_cambio' => (string) $request->input('motivo_cambio', ''),
            'usuario_id' => $userId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function updateInput(Request $request, int $id, int $userId): array
    {
        return [
            'producto_precio_id' => $id,
            'precio_lista' => $request->input('precio_lista'),
            'precio_minimo' => $request->input('precio_minimo'),
            'motivo_cambio' => (string) $request->input('motivo_cambio', ''),
            'usuario_id' => $userId,
        ];
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
        return $this->render('pricing/product-prices/form', [
            'editing' => $editing,
            'errors' => $errors,
            'lists' => $this->prices->listarListasActivas(),
            'values' => $values,
        ], $editing ? 'Editar precio de producto' : 'Crear precio de producto', $status);
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
            'activeNavigation' => 'product-prices',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'canAccessProductPrices' => true,
            'canAccessConfiguration' => $this->permissions->allows(
                $user['user_id'],
                'configuracion.empresas.acceder'
            ) || $this->permissions->allows(
                $user['user_id'],
                'configuracion.almacenes.acceder'
            ) || $this->permissions->allows(
                $user['user_id'],
                'configuracion.folios.acceder'
            ) || $this->permissions->allows(
                $user['user_id'],
                'precios.listas.acceder'
            ),
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
            'canAccessPriceLists' => $this->permissions->allows(
                $user['user_id'],
                'precios.listas.acceder'
            ),
            'contentData' => $contentData,
            'contentView' => $contentView,
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => $pageTitle,
            'stylesheets' => ['/css/modules/product-prices.css'],
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
            throw new \RuntimeException('Authenticated product price controller requires a user.');
        }

        return $user;
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(int $userId): array
    {
        return [
            'ver' => $this->permissions->allows($userId, 'precios.productos.ver'),
            'crear' => $this->permissions->allows($userId, 'precios.productos.crear'),
            'editar' => $this->permissions->allows($userId, 'precios.productos.editar'),
            'desactivar' => $this->permissions->allows($userId, 'precios.productos.desactivar'),
            'reactivar' => $this->permissions->allows($userId, 'precios.productos.reactivar'),
            'historial' => $this->permissions->allows($userId, 'precios.productos.historial'),
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
            'created' => 'Precio creado correctamente.',
            'updated' => 'Precio actualizado correctamente.',
            'deactivated' => 'Precio desactivado correctamente.',
            'reactivated' => 'Precio reactivado correctamente.',
            default => null,
        };
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404'), 404);
    }
}
