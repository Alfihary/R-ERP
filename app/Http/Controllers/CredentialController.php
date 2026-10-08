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
        if (!$this->allowed('perfil.ver')) {
            return Response::html(View::render('errors/403'), 403);
        }

        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);
        $publicVcard = $this->publicVcardForView($request, $user['user_id']);
        $credential = $this->credentials->obtenerCredencialVisual($user['user_id']);
        $storedPhoto = $this->credentials->obtenerFotoPrivada($user['user_id']);
        if ($storedPhoto === null || !$this->photoFileIsValid($storedPhoto, $user['user_id'])) {
            $credential['foto'] = null;
        }

        $html = View::render('layouts/app', [
            'activeNavigation' => 'credential',
            'appName' => (string) $this->config->get(
                'app.name',
                'SoporteGR ERP'
            ),
            ...$this->navigationPermissions($user['user_id']),
            'contentData' => [
                'credential' => $credential,
                'publicVcard' => $publicVcard,
                'tokenState' => $this->tokenStateForView($request, $user['user_id']),
                'canViewCredentialQr' => $this->permissions->allows(
                    $user['user_id'],
                    'perfil.vcard.ver'
                ),
                'canDownloadCredentialQr' => $this->permissions->allows(
                    $user['user_id'],
                    'perfil.vcard.ver'
                ),
            ],
            'contentView' => 'credentials/show',
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => 'Mi credencial',
            'stylesheets' => ['/css/modules/credential.css'],
            'scripts' => [
                '/js/vendor/html2canvas.js',
                '/js/modules/credential.js',
            ],
            'user' => $user,
        ]);

        if (($publicVcard['published'] ?? false) !== true) {
            $html = str_replace(
                '<span>QR no disponible</span>',
                '<span>La vCard no está publicada</span>',
                $html
            );
        }

        return Response::html($html);
    }

    public function showQr(Request $request): Response
    {
        if (!$this->allowed('perfil.ver') || !$this->allowed('perfil.vcard.ver')) {
            return Response::html(View::render('errors/403'), 403);
        }

        $user = $this->user();
        $payload = $this->publicVcardPayload($request, $user['user_id']);

        if ($payload === null) {
            return Response::html('', 404);
        }

        $qr = $this->qr->generate($payload, 8, 2);
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
        if (!$this->allowed('perfil.ver')) {
            return Response::html(View::render('errors/403'), 403);
        }

        $photo = $this->credentials->obtenerFotoPrivada($this->user()['user_id']);

        if ($photo === null) {
            return $this->photoNotFound();
        }

        $response = $this->servePhoto($photo, $this->user()['user_id']);

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
            !$this->allowed('perfil.ver')
            || !$this->allowed('perfil.vcard.ver')
        ) {
            return Response::html(View::render('errors/403'), 403);
        }

        $user = $this->user();
        $payload = $this->publicVcardPayload($request, $user['user_id']);

        if ($payload === null) {
            return Response::html('', 404);
        }

        $qr = $this->qr->generate($payload, 8, 2);
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
        if (!$this->allowed('perfil.ver') || !$this->allowed('perfil.vcard.ver')) {
            return Response::html(View::render('errors/403'), 403);
        }

        $user = $this->user();
        try {
            $token = $this->tokens->generarToken($user['user_id']);
        } catch (\Throwable) {
            return Response::redirect('/perfil/credencial?result=verification_unavailable');
        }
        $this->session->put($this->tokenSessionKey($user['user_id']), [
            'token' => $token['token'],
            'token_prefix' => $token['token_prefix'],
        ]);

        return Response::redirect('/perfil/credencial');
    }

    public function revokeToken(Request $request): Response
    {
        if (!$this->allowed('perfil.ver') || !$this->allowed('perfil.vcard.ver')) {
            return Response::html(View::render('errors/403'), 403);
        }

        $user = $this->user();
        try {
            $this->tokens->revocarTokenActivo($user['user_id']);
        } catch (\Throwable) {
            return Response::redirect('/perfil/credencial?result=verification_unavailable');
        }
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
            'canAccessCredential' => $this->permissions->allows($userId, 'perfil.ver'),
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
    private function tokenStateForView(Request $request, int $userId): array
    {
        try {
            $state = $this->tokens->obtenerEstadoToken($userId);
            $sessionToken = $this->activeSessionToken($userId);
        } catch (\Throwable) {
            return [
                'activo' => false,
                'token_prefix' => null,
                'creado_en' => null,
                'expira_en' => null,
                'revocado_en' => null,
                'session_token_available' => false,
                'verification_url' => null,
                'estado_disponible' => false,
            ];
        }

        return [
            ...$state,
            'session_token_available' => $sessionToken !== null,
            'verification_url' => $sessionToken === null
                ? null
                : $this->absoluteUrlForPath(
                    $request,
                    $this->tokens->verificationPath($sessionToken)
                ),
            'estado_disponible' => true,
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
        $published = in_array($vcard['publicada'] ?? false, [true, 1, '1'], true);

        if ($slug === '') {
            return [
                'available' => false,
                'published' => false,
                'message' => 'La vCard no está publicada.',
                'path' => null,
                'url' => null,
            ];
        }

        $path = '/v/' . rawurlencode($slug);

        return [
            'available' => $published,
            'published' => $published,
            'message' => $published ? null : 'La vCard no está publicada.',
            'path' => $published ? $path : null,
            'url' => $published ? $this->publicVcardUrl($request, $slug) : null,
        ];
    }

    private function publicVcardPayload(Request $request, int $userId): ?string
    {
        $vcard = $this->publicVcardForView($request, $userId);

        if (
            ($vcard['available'] ?? false) !== true
            || ($vcard['published'] ?? false) !== true
            || !is_string($vcard['path'] ?? null)
        ) {
            return null;
        }

        $url = is_string($vcard['url'] ?? null) ? $vcard['url'] : '';
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            ? $url
            : null;
    }

    private function publicVcardUrl(Request $request, string $slug): string
    {
        $path = '/v/' . rawurlencode($slug);
        $baseUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $parts = parse_url($baseUrl);
        $parts = is_array($parts) ? $parts : [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (
            in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
        ) {
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

            return $scheme . '://' . $host . $port . $path;
        }

        return $this->absoluteUrlForPath($request, $path);
    }

    private function absoluteUrlForPath(Request $request, string $path): string
    {
        $host = trim((string) ($request->header('host') ?? ''));

        if ($host !== '' && preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host) === 1) {
            $proto = strtolower(trim((string) ($request->header('x-forwarded-proto') ?? '')));
            $configuredScheme = strtolower((string) parse_url((string) $this->config->get('app.url', ''), PHP_URL_SCHEME));
            $defaultScheme = in_array($configuredScheme, ['http', 'https'], true) ? $configuredScheme : 'https';
            $scheme = in_array($proto, ['http', 'https'], true) ? $proto : $defaultScheme;

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
    private function servePhoto(array $photo, int $userId): Response
    {
        $extension = strtolower((string) ($photo['extension'] ?? ''));
        $relativePath = (string) ($photo['ruta_relativa'] ?? '');
        if (!$this->allowedPhotoMetadata($extension, $relativePath)) {
            return $this->photoNotFound();
        }

        $path = $this->safePhotoPath($relativePath, $userId);

        if ($path === null || !is_file($path) || !is_readable($path)) {
            return $this->photoNotFound();
        }

        $actualSize = filesize($path);

        if ($actualSize === false || $actualSize < 1) {
            return $this->photoNotFound();
        }

        $actualMime = $this->detectMime($path);
        $validExtensions = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
        ];
        if (!in_array($extension, $validExtensions[$actualMime] ?? [], true) || @getimagesize($path) === false) {
            return $this->photoNotFound();
        }

        $body = file_get_contents($path);

        if (!is_string($body) || $body === '') {
            return $this->photoNotFound();
        }

        return $this->withPhotoHeaders(Response::binary($body, $actualMime, [
            'Cache-Control' => 'private, max-age=300',
        ]));
    }

    private function allowedPhotoMetadata(
        string $extension,
        string $relativePath
    ): bool {
        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)
            && !$this->hasDangerousDoubleExtension($relativePath);
    }

    /** @param array<string,mixed> $photo */
    private function photoFileIsValid(array $photo, int $userId): bool
    {
        $relativePath = (string) ($photo['ruta_relativa'] ?? '');
        $path = $this->safePhotoPath($relativePath, $userId);
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return false;
        }
        $size = filesize($path);
        $mime = $this->detectMime($path);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $extensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
        return $size !== false && $size > 0
            && in_array($extension, $extensions[$mime] ?? [], true)
            && @getimagesize($path) !== false;
    }

    private function safePhotoPath(string $relativePath, int $userId): ?string
    {
        $normalized = str_replace('\\', '/', trim($relativePath));

        if (
            $normalized === ''
            || str_starts_with($normalized, '/')
            || preg_match('~^[A-Za-z]:/~', $normalized) === 1
            || str_contains($normalized, '..')
            || str_contains($normalized, "\0")
        ) {
            return null;
        }

        $newFormat = sprintf(
            '~\\Auploads/usuarios/%d/fotos/([A-Za-z0-9][A-Za-z0-9._-]{0,254})\\z~D',
            $userId
        );
        $legacyFormat = '~\\Auploads/perfiles/([A-Za-z0-9][A-Za-z0-9._-]{0,254})\\z~D';

        if (preg_match($newFormat, $normalized) !== 1 && preg_match($legacyFormat, $normalized) !== 1) {
            return null;
        }

        $storagePath = rtrim(
            (string) $this->config->get('paths.STORAGE_PATH', STORAGE_PATH),
            '/\\'
        );
        $uploadsRoot = $storagePath . DIRECTORY_SEPARATOR . 'uploads';
        $uploadsRootReal = realpath($uploadsRoot);

        if ($uploadsRootReal === false) {
            return null;
        }

        $candidate = $storagePath . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $realPath = realpath($candidate);

        if ($realPath === false) {
            return null;
        }

        $normalizedRoot = rtrim($this->normalizedPath($uploadsRootReal), '/') . '/';
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
            if (PHP_VERSION_ID < 80500) {
                finfo_close($finfo);
            }
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
