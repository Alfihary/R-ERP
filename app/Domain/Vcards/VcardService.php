<?php

declare(strict_types=1);

namespace App\Domain\Vcards;

use App\Infrastructure\Repositories\UserVcardRepository;
use App\Infrastructure\Repositories\VcardPrivacyRepository;

final class VcardService
{
    private const RESERVED_SLUGS = [
        'admin',
        'login',
        'logout',
        'perfil',
        'usuarios',
        'productos',
        'precios',
        'inventario',
        'api',
        'assets',
        'public',
        'storage',
        'qr',
        'vcard',
        'credencial',
        'verificar',
        'soporte',
        'soportegr',
        'dashboard',
        'configuracion',
        'catalogos',
    ];
    private const CHANNELS = [
        'whatsapp',
        'telefono_movil',
        'telefono_fijo',
        'correo',
        'ninguno',
    ];
    private const URL_FIELDS = [
        'sitio_web',
        'linkedin_url',
        'facebook_url',
        'instagram_url',
        'google_maps_url',
    ];

    public function __construct(
        private readonly UserVcardRepository $vcards,
        private readonly VcardPrivacyRepository $privacyRepository,
        private readonly VcardPrivacyService $privacy
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function asegurarVcard(int $usuarioId): array
    {
        return $this->vcards->transactional(function () use ($usuarioId): array {
            $user = $this->assertActiveUser($usuarioId);
            $vcard = $this->vcards->findByUser($usuarioId, true);

            if ($vcard === null) {
                $slug = $this->baseSlug((string) $user['username']);
                $this->validarSlugDisponible($slug);
                $this->vcards->createBase($usuarioId, $slug);
                $vcard = $this->vcards->findByUser($usuarioId, true);
            }

            if ($vcard === null) {
                throw new \RuntimeException('Base vCard could not be created.');
            }

            $this->privacyRepository->ensureDefaults(
                (int) $vcard['id'],
                $this->privacy->privacidadDefault()
            );

            return $this->safePrivateVcard($vcard)
                + ['privacidad' => $this->privacy->privacyForVcard((int) $vcard['id'])];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerConfiguracionPrivada(int $usuarioId): array
    {
        return $this->asegurarVcard($usuarioId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function actualizarConfiguracion(int $usuarioId, array $input): array
    {
        return $this->vcards->transactional(function () use (
            $usuarioId,
            $input
        ): array {
            $this->assertActiveUser($usuarioId);
            $vcard = $this->asegurarVcard($usuarioId);
            $data = $this->configurationInput($input, (int) $vcard['id']);
            $this->vcards->updateConfiguration((int) $vcard['id'], $data);

            return $this->obtenerConfiguracionPrivada($usuarioId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function publicar(int $usuarioId): array
    {
        return $this->vcards->transactional(function () use ($usuarioId): array {
            $vcard = $this->asegurarVcard($usuarioId);
            $slug = (string) ($vcard['slug'] ?? '');

            if ($slug === '') {
                throw new VcardValidationException([
                    'slug' => 'El slug es obligatorio para publicar.',
                ]);
            }

            $this->validateSlug($slug);
            $this->validarSlugDisponible($slug, (int) $vcard['id']);
            $this->vcards->publish((int) $vcard['id']);

            return $this->obtenerConfiguracionPrivada($usuarioId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function despublicar(int $usuarioId): array
    {
        return $this->vcards->transactional(function () use ($usuarioId): array {
            $vcard = $this->asegurarVcard($usuarioId);
            $this->vcards->unpublish((int) $vcard['id']);

            return $this->obtenerConfiguracionPrivada($usuarioId);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolverPublicaPorSlug(string $slug): ?array
    {
        $normalized = $this->normalizeSlug($slug);

        if ($normalized === '') {
            return null;
        }

        $data = $this->vcards->publicDataForSlug($normalized);

        if ($data === null) {
            return null;
        }

        return $this->construirRepresentacionPublica($data);
    }

    public function propietarioUsuarioIdPublico(string $slug): ?int
    {
        $normalized = $this->normalizeSlug($slug);
        if ($normalized === '') {
            return null;
        }
        $data = $this->vcards->publicDataForSlug($normalized);
        $userId = is_array($data) ? (int) ($data['usuario_id'] ?? 0) : 0;
        return $userId > 0 ? $userId : null;
    }
    /** @return list<string> */
    public function rolesNotificacionPropietarioPublico(string $slug): array
    {
        $normalized = $this->normalizeSlug($slug);
        if ($normalized === '') {
            return [];
        }
        $data = $this->vcards->publicDataForSlug($normalized);
        $userId = is_array($data) ? (int) ($data['usuario_id'] ?? 0) : 0;
        return $userId > 0 ? $this->vcards->activeNotificationRoleCodes($userId) : [];
    }
    /**
     * @return array{
     *     ruta_relativa: string,
     *     mime: string,
     *     extension: string,
     *     tamano_bytes: int
     * }|null
     */
    public function obtenerFotoPublicaPorSlug(string $slug): ?array
    {
        $normalized = $this->normalizeSlug($slug);

        if ($normalized === '') {
            return null;
        }

        $publicData = $this->vcards->publicDataForSlug($normalized);

        if ($publicData === null) {
            return null;
        }

        $vcardId = (int) ($publicData['vcard_id'] ?? $publicData['id'] ?? 0);
        $privacy = $this->privacy->privacyForVcard($vcardId);
        $visible = $this->privacy->aplicarPrivacidad([
            'foto' => !empty($publicData['foto_id']),
        ], $privacy);

        if (($visible['foto'] ?? false) !== true) {
            return null;
        }

        $photo = $this->vcards->publicPhotoForSlug($normalized);

        if ($photo === null) {
            return null;
        }

        return [
            'ruta_relativa' => (string) ($photo['ruta_relativa'] ?? ''),
            'mime' => (string) ($photo['mime'] ?? ''),
            'extension' => (string) ($photo['extension'] ?? ''),
            'tamano_bytes' => (int) ($photo['tamano_bytes'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $vcard
     * @return array<string, mixed>
     */
    public function construirRepresentacionPublica(array $vcard): array
    {
        $vcardId = (int) ($vcard['vcard_id'] ?? $vcard['id'] ?? 0);
        $privacy = $this->privacy->privacyForVcard($vcardId);
        $name = $this->publicName($vcard);
        $datos = [
            'foto' => !empty($vcard['foto_id']),
            'correo' => $vcard['email'] ?? null,
            'telefono_fijo' => $vcard['telefono_fijo'] ?? null,
            'telefono_movil' => $vcard['telefono_movil'] ?? null,
            'puesto' => $vcard['puesto'] ?? null,
            'empresa' => $vcard['empresa_nombre'] ?? null,
            'almacen' => $vcard['almacen_nombre'] ?? null,
            'ubicacion' => $vcard['ubicacion_publica'] ?? null,
            'sitio_web' => $vcard['sitio_web'] ?? null,
            'linkedin' => $vcard['linkedin_url'] ?? null,
            'facebook' => $vcard['facebook_url'] ?? null,
            'instagram' => $vcard['instagram_url'] ?? null,
            'whatsapp' => $vcard['whatsapp'] ?? null,
            'google_maps' => $vcard['google_maps_url'] ?? null,
            'productos' => true,
        ];
        $public = [
            'slug' => (string) ($vcard['slug'] ?? ''),
            'nombre' => $name,
            'titulo_publico' => (string) ($vcard['titulo_publico'] ?? $name),
        ];

        if (!empty($vcard['descripcion_publica'])) {
            $public['descripcion_publica'] = (string) $vcard['descripcion_publica'];
        }

        $visible = $this->privacy->aplicarPrivacidad($datos, $privacy);

        if (array_key_exists('foto', $visible)) {
            $public['foto_publica_disponible'] = $visible['foto'] === true;
            unset($visible['foto']);
        }
        if (array_key_exists('productos', $visible)) {
            $public['productos_habilitados'] = $visible['productos'] === true;
            unset($visible['productos']);
        }

        $public += $visible;
        $channel = $this->resolverCanalContactoPublico([
            ...$public,
            'canal_contacto_preferido' => $vcard['canal_contacto_preferido'] ?? null,
        ]);

        if ($channel !== null) {
            $public['canal_contacto'] = $channel;
        }

        return $public;
    }

    public function validarSlugDisponible(string $slug, ?int $vcardId = null): void
    {
        $normalized = $this->validateSlug($slug);

        if ($this->vcards->slugExists($normalized, $vcardId)) {
            throw new VcardValidationException([
                'slug' => 'El slug ya está en uso.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $representacion
     * @return array{tipo: string, valor: string}|null
     */
    public function resolverCanalContactoPublico(array $representacion): ?array
    {
        $preferred = $representacion['canal_contacto_preferido'] ?? null;
        $priority = is_string($preferred) && $preferred !== 'ninguno'
            ? [$preferred, 'whatsapp', 'telefono_movil', 'telefono_fijo', 'correo']
            : ['whatsapp', 'telefono_movil', 'telefono_fijo', 'correo'];
        $priority = array_values(array_unique($priority));

        foreach ($priority as $field) {
            $value = $representacion[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return ['tipo' => $field, 'valor' => $value];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function assertActiveUser(int $usuarioId): array
    {
        if ($usuarioId < 1) {
            throw new VcardValidationException([
                'usuario_id' => 'El usuario no es válido.',
            ]);
        }

        $user = $this->vcards->findActiveUser($usuarioId);

        if ($user === null) {
            throw new VcardValidationException([
                'usuario_id' => 'El usuario no existe o no está activo.',
            ]);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private function configurationInput(array $input, int $vcardId): array
    {
        $errors = [];
        $slug = $this->normalizeSlug($this->nullableString($input['slug'] ?? null) ?? '');
        $title = $this->nullableString($input['titulo_publico'] ?? null);
        $description = $this->nullableString($input['descripcion_publica'] ?? null);
        $channel = $this->nullableString($input['canal_contacto_preferido'] ?? null);

        try {
            $this->validateSlug($slug);
            $this->validarSlugDisponible($slug, $vcardId);
        } catch (VcardValidationException $exception) {
            $errors += $exception->errors();
        }

        if ($title !== null && $this->length($title) > 160) {
            $errors['titulo_publico'] = 'Máximo 160 caracteres.';
        }
        if ($description !== null && $this->length($description) > 2000) {
            $errors['descripcion_publica'] = 'Máximo 2000 caracteres.';
        }
        if ($channel !== null && !in_array($channel, self::CHANNELS, true)) {
            $errors['canal_contacto_preferido'] = 'El canal de contacto no está permitido.';
        }

        foreach (self::URL_FIELDS as $field) {
            $url = $this->nullableString($input[$field] ?? null);

            if ($url !== null && !$this->validHttpUrl($url)) {
                $errors[$field] = 'Usa una URL http o https válida.';
            }
        }

        if ($errors !== []) {
            throw new VcardValidationException($errors);
        }

        return [
            'slug' => $slug,
            'titulo_publico' => $title,
            'descripcion_publica' => $description,
            'canal_contacto_preferido' => $channel === 'ninguno' ? null : $channel,
        ];
    }

    private function validateSlug(string $slug): string
    {
        $normalized = $this->normalizeSlug($slug);

        if (
            $normalized === ''
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $normalized) !== 1
            || $this->length($normalized) < 3
            || $this->length($normalized) > 80
        ) {
            throw new VcardValidationException([
                'slug' => 'El slug debe usar letras minúsculas, números y guion medio.',
            ]);
        }
        if (in_array($normalized, self::RESERVED_SLUGS, true)) {
            throw new VcardValidationException([
                'slug' => 'El slug está reservado.',
            ]);
        }
        if (preg_match('/^\d+$/', $normalized) === 1) {
            throw new VcardValidationException([
                'slug' => 'El slug no puede ser un identificador numérico puro.',
            ]);
        }

        return $normalized;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/\s+/', '-', $slug) ?? '';
        $slug = preg_replace('/[^a-z0-9-]+/', '', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    private function baseSlug(string $username): string
    {
        $slug = $this->normalizeSlug(str_replace(['.', '_'], '-', $username));

        if ($slug === '' || in_array($slug, self::RESERVED_SLUGS, true)) {
            return 'usuario-' . bin2hex(random_bytes(4));
        }

        return $slug;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function validHttpUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function safePrivateVcard(array $vcard): array
    {
        return [
            'id' => (int) $vcard['id'],
            'usuario_id' => (int) $vcard['usuario_id'],
            'slug' => (string) $vcard['slug'],
            'titulo_publico' => $vcard['titulo_publico'],
            'descripcion_publica' => $vcard['descripcion_publica'],
            'publicada' => (int) $vcard['publicada'] === 1,
            'canal_contacto_preferido' => $vcard['canal_contacto_preferido'],
            'publicado_en' => $vcard['publicado_en'],
            'despublicado_en' => $vcard['despublicado_en'],
        ];
    }

    /**
     * @param array<string, mixed> $vcard
     */
    private function publicName(array $vcard): string
    {
        $parts = array_filter([
            $vcard['primer_nombre'] ?? null,
            $vcard['segundo_nombre'] ?? null,
            $vcard['apellido_paterno'] ?? null,
            $vcard['apellido_materno'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
        $name = trim(implode(' ', $parts));

        return $name !== '' ? $name : (string) ($vcard['titulo_publico'] ?? 'Contacto');
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }
}


