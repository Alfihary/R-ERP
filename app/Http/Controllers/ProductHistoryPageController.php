<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Products\History\ProductHistoryPage;
use App\Domain\Products\ProductHistoryService;
use App\Domain\Products\ProductNotFoundException;
use App\Domain\Products\ProductService;
use App\Domain\Products\ProductValidationException;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;
use InvalidArgumentException;

final class ProductHistoryPageController
{
    private const FILTER_KEYS = [
        'action',
        'subresource',
        'actor_id',
        'date_from',
        'date_to',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly ProductService $products,
        private readonly ProductHistoryService $history
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();

        try {
            $productId = $this->requiredProductId($query);
            $page = $this->history->page(
                $productId,
                array_intersect_key($query, array_flip(self::FILTER_KEYS)),
                $query['page'] ?? 1,
                $query['page_size'] ?? 25
            );
            $product = $this->products->get($productId);
        } catch (ProductNotFoundException) {
            return $this->notFound();
        } catch (ProductValidationException $exception) {
            if ($this->isMissingProduct($exception)) {
                return $this->notFound();
            }

            throw $exception;
        } catch (InvalidArgumentException) {
            return Response::html(
                View::render('errors/400'),
                400
            );
        }

        return $this->render($product, $page, $query);
    }

    /** @param array<string, mixed> $query */
    private function requiredProductId(array $query): string
    {
        if (!array_key_exists('id_producto', $query)) {
            throw new InvalidArgumentException('A product ID is required.');
        }

        $value = $query['id_producto'];
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('id_producto must be scalar.');
        }

        $value = trim((string) $value);
        if ($value === '') {
            throw new InvalidArgumentException('A product ID is required.');
        }

        return $value;
    }

    private function isMissingProduct(ProductValidationException $exception): bool
    {
        return $exception->errors() === [
            'id_producto' => 'El producto solicitado no existe.',
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $query
     */
    private function render(
        array $product,
        ProductHistoryPage $page,
        array $query
    ): Response {
        $user = $this->auth->user();
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        $publicFilters = [];
        foreach (self::FILTER_KEYS as $key) {
            if (array_key_exists($key, $query)) {
                $publicFilters[$key] = $query[$key];
            }
        }

        $context = $this->scopeContext->resolveForUser((int) $user['user_id']);

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'products',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'contentData' => [
                'page' => $page,
                'product' => $product,
                'publicFilters' => $publicFilters,
            ],
            'contentView' => 'products/history',
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => 'Historial de producto',
            'scripts' => [],
            'stylesheets' => [
                '/css/modules/products.css',
                '/css/modules/product-history.css',
            ],
            'user' => $user,
        ]));
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404'), 404);
    }
}
