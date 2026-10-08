<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Profile\ProfileService;
use App\Domain\Profile\ProfileValidationException;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Vcards\VcardPrivacyService;
use App\Domain\Vcards\VcardProductService;
use App\Domain\Vcards\VcardService;
use App\Domain\Vcards\VcardValidationException;
use App\Infrastructure\Storage\UserPhotoStorage;
use App\Support\Security\CsrfTokenService;

final class ProfileController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly ProfileService $profiles,
        private readonly UserPhotoStorage $photoStorage,
        private readonly ?VcardService $vcards = null,
        private readonly ?VcardPrivacyService $vcardPrivacy = null,
        private readonly ?VcardProductService $vcardProducts = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->allowed('perfil.ver')) {
            return $this->forbidden();
        }

        $user = $this->user();

        return $this->render('profile/index', [
            'abilities' => $this->abilities($user['user_id']),
            'errors' => [],
            'notice' => $this->resultMessage($request),
            'photo' => $this->photoPayload($user['user_id']),
            'profile' => $this->profiles->asegurarPerfil($user['user_id']),
            'vcard' => $this->vcardConfiguration($user['user_id']),
            'vcardErrors' => [],
            ...$this->vcardProductsPayload($user['user_id'], $request),
        ], 'Mi perfil');
    }

    public function update(Request $request): Response
    {
        if (!$this->allowed('perfil.editar')) {
            return $this->forbidden();
        }

        $user = $this->user();

        try {
            $this->profiles->actualizarPerfil(
                $user['user_id'],
                $this->profileInput($request)
            );
        } catch (ProfileValidationException $exception) {
            return $this->render('profile/index', [
                'abilities' => $this->abilities($user['user_id']),
                'errors' => $exception->errors(),
                'notice' => null,
                'photo' => $this->photoPayload($user['user_id']),
                'profile' => $this->profileInput($request)
                    + $this->profiles->asegurarPerfil($user['user_id']),
                'vcard' => $this->vcardConfiguration($user['user_id']),
                'vcardErrors' => [],
                ...$this->vcardProductsPayload($user['user_id'], $request),
            ], 'Mi perfil', $exception->statusCode());
        }

        return Response::redirect('/perfil?result=updated');
    }

    public function updateVcard(Request $request): Response
    {
        if (!$this->allowed('perfil.vcard.editar') || $this->vcards === null) {
            return $this->forbidden();
        }

        $user = $this->user();

        try {
            $this->vcards->actualizarConfiguracion(
                $user['user_id'],
                $this->vcardInput($request)
            );
        } catch (VcardValidationException $exception) {
            return $this->render('profile/index', [
                'abilities' => $this->abilities($user['user_id']),
                'errors' => [],
                'notice' => null,
                'photo' => $this->photoPayload($user['user_id']),
                'profile' => $this->profiles->asegurarPerfil($user['user_id']),
                'vcard' => $this->vcardConfiguration($user['user_id']),
                'vcardErrors' => $exception->errors(),
                ...$this->vcardProductsPayload($user['user_id'], $request),
            ], 'Mi perfil', $exception->statusCode());
        }

        return Response::redirect('/perfil?result=vcard_updated');
    }

    public function updateVcardPrivacy(Request $request): Response
    {
        if (
            !$this->allowed('perfil.vcard.editar')
            || $this->vcards === null
            || $this->vcardPrivacy === null
        ) {
            return $this->forbidden();
        }

        $user = $this->user();

        try {
            $vcard = $this->vcards->obtenerConfiguracionPrivada($user['user_id']);
            $this->vcardPrivacy->actualizarPrivacidad(
                (int) $vcard['id'],
                $this->vcardPrivacyInput($request)
            );
        } catch (VcardValidationException $exception) {
            return $this->render('profile/index', [
                'abilities' => $this->abilities($user['user_id']),
                'errors' => [],
                'notice' => null,
                'photo' => $this->photoPayload($user['user_id']),
                'profile' => $this->profiles->asegurarPerfil($user['user_id']),
                'vcard' => $this->vcardConfiguration($user['user_id']),
                'vcardErrors' => $exception->errors(),
                ...$this->vcardProductsPayload($user['user_id'], $request),
            ], 'Mi perfil', $exception->statusCode());
        }

        return Response::redirect('/perfil?result=vcard_privacy_updated');
    }

    public function publishVcard(Request $request): Response
    {
        if (!$this->allowed('perfil.vcard.publicar') || $this->vcards === null) {
            return $this->forbidden();
        }

        $this->vcards->publicar($this->user()['user_id']);

        return Response::redirect('/perfil?result=vcard_published');
    }

    public function unpublishVcard(Request $request): Response
    {
        if (!$this->allowed('perfil.vcard.publicar') || $this->vcards === null) {
            return $this->forbidden();
        }

        $this->vcards->despublicar($this->user()['user_id']);

        return Response::redirect('/perfil?result=vcard_unpublished');
    }

    public function addVcardProduct(Request $request): Response
    {
        if (
            !$this->allowed('perfil.vcard.editar')
            || $this->vcardProducts === null
        ) {
            return $this->forbidden();
        }

        try {
            $this->vcardProducts->agregarProducto(
                $this->user()['user_id'],
                $request->body()
            );
        } catch (VcardValidationException $exception) {
            return $this->renderVcardProductError($request, $exception);
        }

        return Response::redirect('/perfil?result=vcard_product_added');
    }

    public function updateVcardProduct(Request $request): Response
    {
        if (
            !$this->allowed('perfil.vcard.editar')
            || $this->vcardProducts === null
        ) {
            return $this->forbidden();
        }

        try {
            $this->vcardProducts->actualizarProducto(
                $this->user()['user_id'],
                $request->body()
            );
        } catch (VcardValidationException $exception) {
            return $this->renderVcardProductError($request, $exception);
        }

        return Response::redirect('/perfil?result=vcard_product_updated');
    }

    public function removeVcardProduct(Request $request): Response
    {
        if (
            !$this->allowed('perfil.vcard.editar')
            || $this->vcardProducts === null
        ) {
            return $this->forbidden();
        }

        try {
            $this->vcardProducts->quitarProducto(
                $this->user()['user_id'],
                $request->body()
            );
        } catch (VcardValidationException $exception) {
            return $this->renderVcardProductError($request, $exception);
        }

        return Response::redirect('/perfil?result=vcard_product_removed');
    }

    public function passwordForm(Request $request): Response
    {
        if (!$this->allowed('perfil.password.cambiar')) {
            return $this->forbidden();
        }

        return $this->render('profile/password', [
            'errors' => [],
        ], 'Cambiar contraseña');
    }

    public function updatePassword(Request $request): Response
    {
        if (!$this->allowed('perfil.password.cambiar')) {
            return $this->forbidden();
        }

        try {
            $this->profiles->cambiarPassword(
                $this->user()['user_id'],
                $request->body()
            );
        } catch (ProfileValidationException $exception) {
            return $this->render('profile/password', [
                'errors' => $exception->errors(),
            ], 'Cambiar contraseña', $exception->statusCode());
        }

        return Response::redirect('/perfil?result=password_updated');
    }

   public function deletePhoto(Request $request): Response
    {
        if (!$this->allowed('perfil.editar')) {
            return $this->forbidden();
        }

        $user = $this->user();
        $previous = $this->profiles->obtenerFotoActiva($user['user_id']);
        $this->profiles->eliminarFoto($user['user_id'], $user['user_id']);
        if (is_array($previous)) {
            $this->photoStorage->deleteRelativeFile((string) ($previous['ruta_relativa'] ?? ''), $user['user_id']);
        }

        return Response::redirect('/perfil?result=photo_deleted');
    }

    public function uploadPhoto(Request $request): Response
    {
        if (!$this->allowed('perfil.editar')) {
            return $this->forbidden();
        }

        $user = $this->user();
        $stored = null;
        $previous = $this->profiles->obtenerFotoActiva($user['user_id']);

        try {
            $stored = $this->photoStorage->store(
                $user['user_id'],
                $request->file('foto')
            );
            $this->profiles->registrarFoto(
                $user['user_id'],
                $stored,
                $user['user_id']
            );
            if (is_array($previous) && ($previous['ruta_relativa'] ?? null) !== ($stored['ruta_relativa'] ?? null)) {
                $this->photoStorage->deleteRelativeFile((string) ($previous['ruta_relativa'] ?? ''), $user['user_id']);
            }
        } catch (ProfileValidationException $exception) {
            if (is_array($stored) && isset($stored['ruta_relativa'])) {
                $this->photoStorage->deleteRelativeFile((string) $stored['ruta_relativa'], $user['user_id']);
            }

            return $this->render('profile/index', [
                'abilities' => $this->abilities($user['user_id']),
                'errors' => $exception->errors(),
                'notice' => null,
                'photo' => $this->photoPayload($user['user_id']),
                'profile' => $this->profiles->asegurarPerfil($user['user_id']),
                'vcard' => $this->vcardConfiguration($user['user_id']),
                'vcardErrors' => [],
                ...$this->vcardProductsPayload($user['user_id'], $request),
            ], 'Mi perfil', $exception->statusCode());
        } catch (\Throwable $exception) {
            if (is_array($stored) && isset($stored['ruta_relativa'])) {
                $this->photoStorage->deleteRelativeFile((string) $stored['ruta_relativa'], $user['user_id']);
            }

            throw $exception;
        }

        return Response::redirect('/perfil?result=photo_uploaded');
    }

    /** @return array<string,mixed>|null */
    private function photoPayload(int $userId): ?array
    {
        $photo = $this->profiles->obtenerFotoActiva($userId);
        if ($photo === null) {
            return null;
        }

        try {
            $actual = $this->photoStorage->inspectRelativeFile((string) ($photo['ruta_relativa'] ?? ''), $userId);
        } catch (ProfileValidationException) {
            return null;
        }

        return $actual === null ? null : [...$photo, ...$actual, 'creado_en' => null];
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
            'activeNavigation' => 'profile',
            'appName' => (string) $this->config->get(
                'app.name',
                'SoporteGR ERP'
            ),
            ...$this->navigationPermissions($user['user_id']),
            'contentData' => $contentData,
            'contentView' => $contentView,
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => $pageTitle,
            'stylesheets' => ['/css/modules/profile.css'],
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
                'Authenticated profile controller requires a user.'
            );
        }

        return $user;
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(int $userId): array
    {
        return [
            'ver' => $this->permissions->allows($userId, 'perfil.ver'),
            'editar' => $this->permissions->allows($userId, 'perfil.editar'),
            'password' => $this->permissions->allows(
                $userId,
                'perfil.password.cambiar'
            ),
            'foto_eliminar' => $this->permissions->allows(
                $userId,
                'perfil.editar'
            ),
            'foto_actualizar' => $this->permissions->allows(
                $userId,
                'perfil.editar'
            ),
            'vcard_ver' => $this->permissions->allows($userId, 'perfil.vcard.ver'),
            'vcard_editar' => $this->permissions->allows($userId, 'perfil.vcard.editar'),
            'vcard_publicar' => $this->permissions->allows(
                $userId,
                'perfil.vcard.publicar'
            ),
            'vcard_privacidad' => $this->permissions->allows(
                $userId,
                'perfil.vcard.editar'
            ),
            'vcard_productos_administrar' => $this->permissions->allows(
                $userId,
                'perfil.vcard.editar'
            ),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function navigationPermissions(int $userId): array
    {
        return [
            'canAccessProfile' => $this->permissions->allows($userId, 'perfil.ver'),
            'canAccessCatalogs' => $this->permissions->allows(
                $userId,
                'catalogos.acceder'
            ),
            'canAccessProducts' => $this->permissions->allows(
                $userId,
                'productos.acceder'
            ),
            'canAccessProductPrices' => $this->permissions->allows(
                $userId,
                'precios.productos.acceder'
            ),
            'canAccessInventory' => $this->permissions->allows(
                $userId,
                'inventario.movimientos.acceder'
            ),
            'canAccessInventoryStock' => $this->permissions->allows(
                $userId,
                'inventario.existencias.acceder'
            ),
            'canAccessInventorySerialStock' => $this->permissions->allows(
                $userId,
                'inventario.existencias_series.acceder'
            ),
            'canAccessInventoryKardex' => $this->permissions->allows(
                $userId,
                'inventario.kardex.acceder'
            ),
            'canAccessInventorySerialKardex' => $this->permissions->allows(
                $userId,
                'inventario.kardex_series.acceder'
            ),
            'canAccessInventoryTransfers' => $this->permissions->allows(
                $userId,
                'inventario.transferencias.acceder'
            ),
            'canAccessConfiguration' => $this->permissions->allows(
                $userId,
                'configuracion.empresas.acceder'
            ) || $this->permissions->allows(
                $userId,
                'configuracion.almacenes.acceder'
            ) || $this->permissions->allows(
                $userId,
                'configuracion.folios.acceder'
            ) || $this->permissions->allows(
                $userId,
                'precios.listas.acceder'
            ),
            'canAccessConfigCompanies' => $this->permissions->allows(
                $userId,
                'configuracion.empresas.acceder'
            ),
            'canAccessConfigWarehouses' => $this->permissions->allows(
                $userId,
                'configuracion.almacenes.acceder'
            ),
            'canAccessConfigFolios' => $this->permissions->allows(
                $userId,
                'configuracion.folios.acceder'
            ),
            'canAccessPriceLists' => $this->permissions->allows(
                $userId,
                'precios.listas.acceder'
            ),
        ];
    }

    private function allowed(string $permission): bool
    {
        return $this->permissions->allows($this->user()['user_id'], $permission);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileInput(Request $request): array
    {
        $allowed = [
            'primer_nombre',
            'segundo_nombre',
            'apellido_paterno',
            'apellido_materno',
            'puesto',
            'telefono_fijo',
            'telefono_movil',
            'whatsapp',
            'sitio_web',
            'linkedin_url',
            'facebook_url',
            'instagram_url',
            'google_maps_url',
            'ubicacion_publica',
        ];
        $input = [];

        foreach ($allowed as $field) {
            $value = $request->input($field);
            $input[$field] = is_string($value) ? $value : null;
        }

        return $input;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function vcardConfiguration(int $userId): ?array
    {
        if ($this->vcards === null) {
            return null;
        }

        return $this->vcards->obtenerConfiguracionPrivada($userId);
    }

    /**
     * @return array{
     *     vcardProducts: list<array<string, mixed>>,
     *     vcardProductSearchResults: list<array<string, mixed>>,
     *     vcardProductQuery: string,
     *     vcardProductErrors: array<string, string>
     * }
     */
    private function vcardProductsPayload(
        int $userId,
        Request $request,
        array $errors = []
    ): array {
        $queryValue = $request->query()['producto'] ?? '';
        $query = is_string($queryValue) ? trim($queryValue) : '';

        if (
            $this->vcardProducts === null
            || !$this->permissions->allows($userId, 'perfil.vcard.editar')
        ) {
            return [
                'vcardProducts' => [],
                'vcardProductSearchResults' => [],
                'vcardProductQuery' => $query,
                'vcardProductErrors' => $errors,
            ];
        }

        return [
            'vcardProducts' => $this->vcardProducts->listarPrivados($userId),
            'vcardProductSearchResults' => $query !== ''
                ? $this->vcardProducts->buscarProductosActivos($query)
                : [],
            'vcardProductQuery' => $query,
            'vcardProductErrors' => $errors,
        ];
    }

    private function renderVcardProductError(
        Request $request,
        VcardValidationException $exception
    ): Response {
        $user = $this->user();

        return $this->render('profile/index', [
            'abilities' => $this->abilities($user['user_id']),
            'errors' => [],
            'notice' => null,
            'photo' => $this->photoPayload($user['user_id']),
            'profile' => $this->profiles->asegurarPerfil($user['user_id']),
            'vcard' => $this->vcardConfiguration($user['user_id']),
            'vcardErrors' => [],
            ...$this->vcardProductsPayload(
                $user['user_id'],
                $request,
                $exception->errors()
            ),
        ], 'Mi perfil', $exception->statusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function vcardInput(Request $request): array
    {
        $allowed = [
            'slug',
            'titulo_publico',
            'descripcion_publica',
            'canal_contacto_preferido',
        ];
        $input = [];

        foreach ($allowed as $field) {
            $value = $request->input($field);
            $input[$field] = is_string($value) ? $value : null;
        }

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    private function vcardPrivacyInput(Request $request): array
    {
        $input = [];

        foreach (VcardPrivacyService::FIELDS as $field) {
            $input[$field] = $request->input($field, '0');
        }

        return $input;
    }

    private function resultMessage(Request $request): ?string
    {
        return match ($request->query()['result'] ?? null) {
            'updated' => 'Perfil actualizado correctamente.',
            'password_updated' => 'Contraseña actualizada correctamente.',
            'photo_uploaded' => 'Foto actualizada correctamente.',
            'photo_deleted' => 'Foto activa eliminada correctamente.',
            'vcard_updated' => 'Configuración pública de vCard actualizada.',
            'vcard_privacy_updated' => 'Privacidad pública de vCard actualizada.',
            'vcard_published' => 'vCard publicada correctamente.',
            'vcard_unpublished' => 'vCard despublicada correctamente.',
            'vcard_product_added' => 'Producto agregado a tu vCard.',
            'vcard_product_updated' => 'Producto de vCard actualizado.',
            'vcard_product_removed' => 'Producto removido de tu vCard.',
            default => null,
        };
    }

    private function forbidden(): Response
    {
        return Response::html(View::render('errors/403'), 403);
    }
}
