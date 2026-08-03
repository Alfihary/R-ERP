<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Credentials\CredentialService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class CredentialController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly CredentialService $credentials
    ) {
    }

    public function show(Request $request): Response
    {
        if (!$this->allowed('credencial.ver')) {
            return Response::html(View::render('errors/403'), 403);
        }

        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'credential',
            'appName' => (string) $this->config->get(
                'app.name',
                'SoporteGR ERP'
            ),
            ...$this->navigationPermissions($user['user_id']),
            'contentData' => [
                'credential' => $this->credentials->obtenerCredencialVisual(
                    $user['user_id']
                ),
            ],
            'contentView' => 'credentials/show',
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => 'Mi credencial',
            'stylesheets' => ['/css/modules/credential.css'],
            'user' => $user,
        ]));
    }

    /**
     * @return array{user_id: int, username: string, email: string}
     */
    private function user(): array
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new \RuntimeException(
                'Authenticated credential controller requires a user.'
            );
        }

        return $user;
    }

    private function allowed(string $permission): bool
    {
        return $this->permissions->allows($this->user()['user_id'], $permission);
    }

    /**
     * @return array<string, bool>
     */
    private function navigationPermissions(int $userId): array
    {
        return [
            'canAccessProfile' => $this->permissions->allows($userId, 'perfil.ver'),
            'canAccessCredential' => $this->permissions->allows($userId, 'credencial.ver'),
            'canAccessCatalogs' => $this->permissions->allows($userId, 'catalogos.acceder'),
            'canAccessProducts' => $this->permissions->allows($userId, 'productos.acceder'),
            'canAccessProductPrices' => $this->permissions->allows($userId, 'precios.productos.acceder'),
            'canAccessInventory' => $this->permissions->allows($userId, 'inventario.movimientos.acceder'),
            'canAccessInventoryStock' => $this->permissions->allows($userId, 'inventario.existencias.acceder'),
            'canAccessInventorySerialStock' => $this->permissions->allows($userId, 'inventario.existencias_series.acceder'),
            'canAccessInventoryKardex' => $this->permissions->allows($userId, 'inventario.kardex.acceder'),
            'canAccessInventorySerialKardex' => $this->permissions->allows($userId, 'inventario.kardex_series.acceder'),
            'canAccessInventoryTransfers' => $this->permissions->allows($userId, 'inventario.transferencias.acceder'),
            'canAccessConfiguration' => $this->permissions->allows($userId, 'configuracion.empresas.acceder')
                || $this->permissions->allows($userId, 'configuracion.almacenes.acceder')
                || $this->permissions->allows($userId, 'configuracion.folios.acceder')
                || $this->permissions->allows($userId, 'precios.listas.acceder'),
            'canAccessConfigCompanies' => $this->permissions->allows($userId, 'configuracion.empresas.acceder'),
            'canAccessConfigWarehouses' => $this->permissions->allows($userId, 'configuracion.almacenes.acceder'),
            'canAccessConfigFolios' => $this->permissions->allows($userId, 'configuracion.folios.acceder'),
            'canAccessPriceLists' => $this->permissions->allows($userId, 'precios.listas.acceder'),
        ];
    }
}
