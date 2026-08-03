<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Credentials\CredentialQrService;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\CredentialTokenService;
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
        private readonly CredentialService $credentials,
        private readonly CredentialTokenService $tokens,
        private readonly CredentialQrService $qr,
        private readonly Session $session
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
                'tokenState' => $this->tokenStateForView($user['user_id']),
                'canViewCredentialQr' => $this->permissions->allows(
                    $user['user_id'],
                    'credencial.qr.ver'
                ),
                'canDownloadCredentialQr' => $this->permissions->allows(
                    $user['user_id'],
                    'credencial.qr.descargar'
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

    public function showQr(Request $request): Response
    {
        if (!$this->allowed('credencial.ver') || !$this->allowed('credencial.qr.ver')) {
            return Response::html(View::render('errors/403'), 403);
        }

        $token = $this->activeSessionToken($this->user()['user_id']);

        if ($token === null) {
            return Response::html('', 404);
        }

        $qr = $this->qr->generate($this->tokens->verificationPath($token));

        return Response::binary($qr['png'], 'image/png', [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function downloadQr(Request $request): Response
    {
        if (
            !$this->allowed('credencial.ver')
            || !$this->allowed('credencial.qr.ver')
            || !$this->allowed('credencial.qr.descargar')
        ) {
            return Response::html(View::render('errors/403'), 403);
        }

        $token = $this->activeSessionToken($this->user()['user_id']);

        if ($token === null) {
            return Response::html('', 404);
        }

        $qr = $this->qr->generate($this->tokens->verificationPath($token));

        return Response::binary($qr['png'], 'image/png', [
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'attachment; filename="credencial-qr.png"',
        ]);
    }

    public function renewToken(Request $request): Response
    {
        if (!$this->allowed('credencial.ver') || !$this->allowed('credencial.qr.ver')) {
            return Response::html(View::render('errors/403'), 403);
        }

        $user = $this->user();
        $token = $this->tokens->generarToken($user['user_id']);
        $this->session->put($this->tokenSessionKey($user['user_id']), [
            'token' => $token['token'],
            'token_prefix' => $token['token_prefix'],
        ]);

        return Response::redirect('/perfil/credencial');
    }

    public function revokeToken(Request $request): Response
    {
        if (!$this->allowed('credencial.ver') || !$this->allowed('credencial.qr.ver')) {
            return Response::html(View::render('errors/403'), 403);
        }

        $user = $this->user();
        $this->tokens->revocarTokenActivo($user['user_id']);
        $this->session->remove($this->tokenSessionKey($user['user_id']));

        return Response::redirect('/perfil/credencial');
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

    /**
     * @return array<string, mixed>
     */
    private function tokenStateForView(int $userId): array
    {
        $state = $this->tokens->obtenerEstadoToken($userId);

        return [
            ...$state,
            'session_token_available' => $this->activeSessionToken($userId) !== null,
        ];
    }

    private function activeSessionToken(int $userId): ?string
    {
        $state = $this->tokens->obtenerEstadoToken($userId);
        $sessionToken = $this->session->get($this->tokenSessionKey($userId));

        if (
            ($state['activo'] ?? false) !== true
            || !is_array($sessionToken)
            || !is_string($sessionToken['token'] ?? null)
            || !is_string($sessionToken['token_prefix'] ?? null)
            || ($state['token_prefix'] ?? null) !== $sessionToken['token_prefix']
        ) {
            return null;
        }

        return $sessionToken['token'];
    }

    private function tokenSessionKey(int $userId): string
    {
        return 'credential_plain_token_' . $userId;
    }
}
