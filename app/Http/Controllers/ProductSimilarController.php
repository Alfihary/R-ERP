<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Products\ProductSimilarException;
use App\Domain\Products\ProductSimilarService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class ProductSimilarController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly ProductSimilarService $similars
    ) {
    }

    public function search(Request $request): Response
    {
        $origin = (string) ($request->query()['id_producto'] ?? '');
        $term = (string) ($request->query()['q'] ?? '');
        $items = array_map(static fn (array $row): array => [
            'id_producto' => (string) ($row['id_producto'] ?? ''),
            'descripcion' => (string) ($row['descripcion'] ?? ''),
            'sku' => (string) ($row['sku'] ?? ''),
        ], $this->similars->search($origin, $term));

        return Response::json(['items' => $items]);
    }

    public function add(Request $request): Response
    {
        $user = $this->user();
        $origin = (string) $request->input('id_producto');
        $target = (string) $request->input('similar_id');
        try {
            $this->similars->add($origin, $target, (int) $user['user_id']);
        } catch (ProductSimilarException) {
            return Response::redirect('/productos/editar?id_producto=' . rawurlencode($origin) . '&result=similar_error');
        }
        return Response::redirect('/productos/editar?id_producto=' . rawurlencode($origin) . '&result=similar_added');
    }

    public function remove(Request $request): Response
    {
        $user = $this->user();
        $origin = (string) $request->input('id_producto');
        $target = (string) $request->input('similar_id');
        try {
            $this->similars->remove($origin, $target, (int) $user['user_id']);
        } catch (ProductSimilarException) {
            return Response::redirect('/productos/editar?id_producto=' . rawurlencode($origin) . '&result=similar_error');
        }
        return Response::redirect('/productos/editar?id_producto=' . rawurlencode($origin) . '&result=similar_removed');
    }

    /** @return array{user_id:int,username:string,email:string} */
    private function user(): array
    {
        $user = $this->auth->user();
        if ($user === null) {
            throw new \RuntimeException('Authenticated product similar controller requires a user.');
        }
        return $user;
    }
}
