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
        private readonly UserPhotoStorage $photoStorage
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
            'photo' => $this->profiles->obtenerFotoActiva($user['user_id']),
            'profile' => $this->profiles->asegurarPerfil($user['user_id']),
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
                'photo' => $this->profiles->obtenerFotoActiva($user['user_id']),
                'profile' => $this->profileInput($request)
                    + $this->profiles->asegurarPerfil($user['user_id']),
            ], 'Mi perfil', $exception->statusCode());
        }

        return Response::redirect('/perfil?result=updated');
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
        if (!$this->allowed('perfil.foto.eliminar')) {
            return $this->forbidden();
        }

        $user = $this->user();
        $this->profiles->eliminarFoto($user['user_id'], $user['user_id']);

        return Response::redirect('/perfil?result=photo_deleted');
    }

    public function uploadPhoto(Request $request): Response
    {
        if (!$this->allowed('perfil.foto.actualizar')) {
            return $this->forbidden();
        }

        $user = $this->user();
        $stored = null;

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
        } catch (ProfileValidationException $exception) {
            if (is_array($stored) && isset($stored['ruta_relativa'])) {
                $this->photoStorage->deleteRelativeFile(
                    (string) $stored['ruta_relativa']
                );
            }

            return $this->render('profile/index', [
                'abilities' => $this->abilities($user['user_id']),
                'errors' => $exception->errors(),
                'notice' => null,
                'photo' => $this->profiles->obtenerFotoActiva($user['user_id']),
                'profile' => $this->profiles->asegurarPerfil($user['user_id']),
            ], 'Mi perfil', $exception->statusCode());
        } catch (\Throwable $exception) {
            if (is_array($stored) && isset($stored['ruta_relativa'])) {
                $this->photoStorage->deleteRelativeFile(
                    (string) $stored['ruta_relativa']
                );
            }

            throw $exception;
        }

        return Response::redirect('/perfil?result=photo_uploaded');
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
                'perfil.foto.eliminar'
            ),
            'foto_actualizar' => $this->permissions->allows(
                $userId,
                'perfil.foto.actualizar'
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

    private function resultMessage(Request $request): ?string
    {
        return match ($request->query()['result'] ?? null) {
            'updated' => 'Perfil actualizado correctamente.',
            'password_updated' => 'Contraseña actualizada correctamente.',
            'photo_uploaded' => 'Foto actualizada correctamente.',
            'photo_deleted' => 'Foto activa eliminada correctamente.',
            default => null,
        };
    }

    private function forbidden(): Response
    {
        return Response::html(View::render('errors/403'), 403);
    }
}
