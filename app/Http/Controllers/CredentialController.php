<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Domain\Audit\AuditService;
use App\Domain\Auth\AuthService;
use App\Domain\Credentials\CredentialQrService;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\CredentialTokenService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Vcards\VcardService;
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
        private readonly VcardService $vcards,
        private readonly Session $session,
        private readonly ?AuditService $audit = null
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
                'publicVcard' => $this->publicVcardForView($request, $user['user_id']),
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

        $user = $this->user();
        $payload = $this->publicVcardPayload($request, $user['user_id']);

        if ($payload === null) {
            return Response::html('', 404);
        }

        $qr = $this->qr->generate($payload, 8);
        $this->audit?->record('credencial.qr.ver', $user['user_id'], [
            'entidad' => 'credencial',
            'resultado' => 'ok',
            'destino' => 'vcard_publica',
        ]);

        return Response::binary($qr['png'], 'image/png', [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function photo(Request $request): Response
    {
        if (!$this->allowed('credencial.ver')) {
            return Response::html(View::render('errors/403'), 403);
        }

        $photo = $this->credentials->obtenerFotoPrivada($this->user()['user_id']);

        if ($photo === null) {
            return $this->photoNotFound();
        }

        $response = $this->servePhoto($photo);

        if ($response->status() === 200) {
            $this->audit?->record('credencial.foto.ver', $this->user()['user_id'], [
                'entidad' => 'credencial',
                'resultado' => 'ok',
                'mime' => $photo['mime'] ?? null,
            ]);
        }

        return $response;
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

        $user = $this->user();
        $payload = $this->publicVcardPayload($request, $user['user_id']);

        if ($payload === null) {
            return Response::html('', 404);
        }

        $qr = $this->qr->generate($payload, 8);
        $this->audit?->record('credencial.qr.descargar', $user['user_id'], [
            'entidad' => 'credencial',
            'resultado' => 'ok',
            'destino' => 'vcard_publica',
        ]);

        return Response::binary($qr['png'], 'image/png', [
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'attachment; filename="credencial-vcard-qr.png"',
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

    /**
     * @return array{
     *     available: bool,
     *     published: bool,
     *     path: string|null,
     *     url: string|null
     * }
     */
    private function publicVcardForView(Request $request, int $userId): array
    {
        $vcard = $this->vcards->obtenerConfiguracionPrivada($userId);
        $slug = trim((string) ($vcard['slug'] ?? ''));

        if ($slug === '') {
            return [
                'available' => false,
                'published' => false,
                'path' => null,
                'url' => null,
            ];
        }

        $path = '/v/' . rawurlencode($slug);

        return [
            'available' => true,
            'published' => ($vcard['publicada'] ?? false) === true,
            'path' => $path,
            'url' => $this->publicVcardUrl($request, $slug),
        ];
    }

    private function publicVcardPayload(Request $request, int $userId): ?string
    {
        $vcard = $this->publicVcardForView($request, $userId);

        if (($vcard['available'] ?? false) !== true || !is_string($vcard['path'] ?? null)) {
            return null;
        }

        $url = is_string($vcard['url'] ?? null) ? $vcard['url'] : '';

        return $url !== '' && strlen($url) <= 106
            ? $url
            : $vcard['path'];
    }

    private function publicVcardUrl(Request $request, string $slug): string
    {
        $path = '/v/' . rawurlencode($slug);
        $host = trim((string) ($request->header('host') ?? ''));

        if ($host !== '' && preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host) === 1) {
            $proto = strtolower(trim((string) ($request->header('x-forwarded-proto') ?? '')));
            $scheme = in_array($proto, ['http', 'https'], true) ? $proto : 'http';

            return $scheme . '://' . $host . $path;
        }

        $baseUrl = rtrim((string) $this->config->get('app.url', ''), '/');

        if ($baseUrl !== '' && preg_match('/^https?:\/\/[^\/\s]+$/', $baseUrl) === 1) {
            return $baseUrl . $path;
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $photo
     */
    private function servePhoto(array $photo): Response
    {
        $mime = strtolower((string) ($photo['mime'] ?? ''));
        $extension = strtolower((string) ($photo['extension'] ?? ''));
        $relativePath = (string) ($photo['ruta_relativa'] ?? '');
        $declaredSize = (int) ($photo['tamano_bytes'] ?? 0);

        if (!$this->allowedPhotoMetadata($mime, $extension, $declaredSize, $relativePath)) {
            return $this->photoNotFound();
        }

        $path = $this->safePhotoPath($relativePath);

        if ($path === null || !is_file($path) || !is_readable($path)) {
            return $this->photoNotFound();
        }

        $actualSize = filesize($path);

        if ($actualSize === false || $actualSize < 1) {
            return $this->photoNotFound();
        }

        $actualMime = $this->detectMime($path);

        if ($actualMime !== $mime) {
            return $this->photoNotFound();
        }

        $body = file_get_contents($path);

        if (!is_string($body) || $body === '') {
            return $this->photoNotFound();
        }

        return $this->withPhotoHeaders(Response::binary($body, $mime, [
            'Cache-Control' => 'private, max-age=300',
        ]));
    }

    private function allowedPhotoMetadata(
        string $mime,
        string $extension,
        int $declaredSize,
        string $relativePath
    ): bool {
        if ($declaredSize < 1 || $this->hasDangerousDoubleExtension($relativePath)) {
            return false;
        }

        return match ($mime) {
            'image/jpeg' => in_array($extension, ['jpg', 'jpeg'], true),
            'image/png' => $extension === 'png',
            'image/webp' => $extension === 'webp',
            default => false,
        };
    }

    private function safePhotoPath(string $relativePath): ?string
    {
        if (
            $relativePath === ''
            || !str_starts_with($relativePath, 'uploads/usuarios/')
            || str_contains($relativePath, '..')
            || str_contains($relativePath, '\\')
            || str_starts_with($relativePath, '/')
        ) {
            return null;
        }

        $storagePath = rtrim(
            (string) $this->config->get('paths.STORAGE_PATH', STORAGE_PATH),
            '/\\'
        );
        $root = $storagePath . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, 'uploads/usuarios');
        $rootReal = realpath($root);

        if ($rootReal === false) {
            return null;
        }

        $candidate = $storagePath . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $realPath = realpath($candidate);

        if ($realPath === false) {
            return null;
        }

        $normalizedRoot = $this->normalizedPath($rootReal) . '/';
        $normalizedPath = $this->normalizedPath($realPath);

        return str_starts_with($normalizedPath, $normalizedRoot)
            ? $realPath
            : null;
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return '';
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        return is_string($mime) ? strtolower($mime) : '';
    }

    private function hasDangerousDoubleExtension(string $relativePath): bool
    {
        $name = strtolower(basename(str_replace('\\', '/', $relativePath)));
        $parts = explode('.', $name);

        if (count($parts) < 3) {
            return false;
        }

        $dangerous = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'html', 'htm', 'js'];

        foreach (array_slice($parts, 0, -1) as $part) {
            if (in_array($part, $dangerous, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function photoNotFound(): Response
    {
        return $this->withPhotoHeaders(new Response('', 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]));
    }

    private function withPhotoHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-Frame-Options', 'DENY');
    }
}
