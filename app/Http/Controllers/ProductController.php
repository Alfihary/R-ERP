<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Products\ProductService;
use App\Domain\Products\ProductValidationException;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class ProductController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly ProductService $products
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user();

        return $this->render('products/index', [
            'abilities' => $this->abilities($user['user_id']),
            'catalogs' => $this->products->catalogs(),
            'filters' => $this->products->filters($request->query()),
            'notice' => $this->resultMessage($request),
            'products' => $this->products->search($request->query()),
        ], 'Productos');
    }

    public function createForm(Request $request): Response
    {
        return $this->renderForm([], [], false, 200);
    }

    public function create(Request $request): Response
    {
        $user = $this->user();

        try {
            $productId = $this->products->create(
                $request->body(),
                $user['user_id']
            );
        } catch (ProductValidationException $exception) {
            return $this->renderForm(
                $request->body(),
                $exception->errors(),
                false,
                422
            );
        }

        return Response::redirect(
            '/productos/ver?id_producto=' . rawurlencode($productId)
            . '&result=created'
        );
    }

    public function detail(Request $request): Response
    {
        $product = $this->requestedProduct($request);

        if ($product === null) {
            return $this->notFound();
        }

        return $this->render('products/detail', [
            'abilities' => $this->abilities($this->user()['user_id']),
            'notice' => $this->resultMessage($request),
            'product' => $product,
        ], 'Detalle de producto');
    }

    public function editForm(Request $request): Response
    {
        $product = $this->requestedProduct($request);

        if ($product === null) {
            return $this->notFound();
        }

        return $this->renderForm(
            $this->formValues($product),
            [],
            true,
            200
        );
    }

    public function update(Request $request): Response
    {
        $user = $this->user();
        $originalId = $request->input('original_id_producto');
        $originalId = is_string($originalId) ? $originalId : '';

        try {
            $this->products->update(
                $originalId,
                $request->body(),
                $user['user_id']
            );
        } catch (ProductValidationException $exception) {
            return $this->renderForm(
                $request->body(),
                $exception->errors(),
                true,
                422,
                $originalId
            );
        }

        return Response::redirect(
            '/productos/ver?id_producto=' . rawurlencode($originalId)
            . '&result=updated'
        );
    }

    public function state(Request $request, bool $active): Response
    {
        $user = $this->user();
        $productId = $request->input('id_producto');
        $productId = is_string($productId) ? $productId : '';

        try {
            $this->products->setActive(
                $productId,
                $active,
                $user['user_id']
            );
        } catch (ProductValidationException) {
            return $this->notFound();
        }

        return Response::redirect(
            '/productos?result=' . ($active ? 'activated' : 'deactivated')
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
        int $status,
        ?string $originalId = null
    ): Response {
        if ($editing && $originalId !== null) {
            $values['original_id_producto'] = $originalId;
        }

        return $this->render('products/form', [
            'catalogs' => $this->products->catalogs(),
            'editing' => $editing,
            'errors' => $errors,
            'values' => $values,
        ], $editing ? 'Editar producto' : 'Crear producto', $status);
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
            'activeNavigation' => 'products',
            'appName' => (string) $this->config->get(
                'app.name',
                'SoporteGR ERP'
            ),
            'canAccessCatalogs' => $this->permissions->allows(
                $user['user_id'],
                'catalogos.acceder'
            ),
            'canAccessProducts' => true,
            'contentData' => $contentData,
            'contentView' => $contentView,
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => $pageTitle,
            'stylesheets' => ['/css/modules/products.css'],
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
                'Authenticated product controller requires a user.'
            );
        }

        return $user;
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(int $userId): array
    {
        $abilities = [];

        foreach (['ver', 'crear', 'editar', 'estado'] as $action) {
            $abilities[$action] = $this->permissions->allows(
                $userId,
                'productos.' . $action
            );
        }

        return $abilities;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestedProduct(Request $request): ?array
    {
        $productId = $request->query()['id_producto'] ?? null;

        if (!is_string($productId)) {
            return null;
        }

        try {
            return $this->products->get($productId);
        } catch (ProductValidationException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function formValues(array $product): array
    {
        return [
            'original_id_producto' => $product['id_producto'] ?? '',
            'id_producto' => $product['id_producto'] ?? '',
            'descripcion' => $product['descripcion'] ?? '',
            'descripcion_larga' => $product['descripcion_larga'] ?? '',
            'tipo_producto' => $product['tipo_codigo'] ?? '',
            'unidad_medida_id' => $product['unidad_medida_id'] ?? '',
            'moneda_id' => $product['moneda_id'] ?? '',
            'linea_producto_id' => $product['linea_producto_id'] ?? '',
            'marca_id' => $product['marca_id'] ?? '',
            'clasificacion_producto_id' =>
                $product['clasificacion_producto_id'] ?? '',
            'peso_kg' => $product['peso_kg'] ?? '',
            'largo_cm' => $product['largo_cm'] ?? '',
            'ancho_cm' => $product['ancho_cm'] ?? '',
            'alto_cm' => $product['alto_cm'] ?? '',
            'controla_series' => $product['controla_series'] ?? 0,
            'controla_lotes' => $product['controla_lotes'] ?? 0,
            'controla_pedimentos' =>
                $product['controla_pedimentos'] ?? 0,
            'impuestos' => array_map(
                static fn (array $tax): string => (string) ($tax['id'] ?? ''),
                is_array($product['taxes'] ?? null)
                    ? $product['taxes']
                    : []
            ),
            'codigos_barras' => implode(
                PHP_EOL,
                array_map(
                    static fn (array $barcode): string =>
                        (string) ($barcode['codigo_barras'] ?? ''),
                    is_array($product['barcodes'] ?? null)
                        ? $product['barcodes']
                        : []
                )
            ),
        ];
    }

    private function resultMessage(Request $request): ?string
    {
        return match ($request->query()['result'] ?? null) {
            'created' => 'Producto creado correctamente.',
            'updated' => 'Producto actualizado correctamente.',
            'activated' => 'Producto activado correctamente.',
            'deactivated' => 'Producto desactivado correctamente.',
            default => null,
        };
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404'), 404);
    }
}
