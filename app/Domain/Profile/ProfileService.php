<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Infrastructure\Repositories\ProfileRepository;
use App\Infrastructure\Repositories\UserPhotoRepository;

final class ProfileService
{
    private const MAX_PHOTO_BYTES = 5_242_880;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const DANGEROUS_EXTENSIONS = [
        'asp',
        'aspx',
        'bat',
        'cmd',
        'com',
        'exe',
        'htaccess',
        'html',
        'js',
        'jsp',
        'phtml',
        'php',
        'phar',
        'ps1',
        'sh',
        'svg',
    ];
    private const PROFILE_FIELDS = [
        'primer_nombre' => 80,
        'segundo_nombre' => 80,
        'apellido_paterno' => 80,
        'apellido_materno' => 80,
        'puesto' => 120,
        'telefono_fijo' => 40,
        'telefono_movil' => 40,
        'whatsapp' => 40,
        'sitio_web' => 255,
        'linkedin_url' => 255,
        'facebook_url' => 255,
        'instagram_url' => 255,
        'google_maps_url' => 500,
        'ubicacion_publica' => 255,
    ];
    private const URL_FIELDS = [
        'sitio_web',
        'linkedin_url',
        'facebook_url',
        'instagram_url',
        'google_maps_url',
    ];
    private const FORBIDDEN_PROFILE_FIELDS = [
        'roles',
        'permisos',
        'empresa',
        'empresa_id',
        'almacen',
        'almacen_id',
        'activo',
        'email',
        'username',
        'password',
        'password_hash',
        'hash',
    ];

    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly UserPhotoRepository $photos
    )
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerPerfil(int $usuarioId): array
    {
        $this->assertActiveUser($usuarioId);
        $profile = $this->profiles->findProfileByUser($usuarioId);

        if ($profile === null) {
            throw new ProfileValidationException([
                'usuario_id' => 'El perfil del usuario no existe.',
            ]);
        }

        return $this->safeProfile($profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function asegurarPerfil(int $usuarioId): array
    {
        return $this->profiles->transactional(function () use ($usuarioId): array {
            $this->assertActiveUser($usuarioId);
            $profile = $this->profiles->findProfileByUser($usuarioId);

            if ($profile === null) {
                $this->profiles->createBaseProfile($usuarioId);
                $profile = $this->profiles->findProfileByUser($usuarioId);
            }

            if ($profile === null) {
                throw new \RuntimeException('Base profile could not be created.');
            }

            return $this->safeProfile($profile);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function actualizarPerfil(int $usuarioId, array $input): array
    {
        return $this->profiles->transactional(function () use (
            $usuarioId,
            $input
        ): array {
            $this->asegurarPerfil($usuarioId);
            $data = $this->profileInput($input);
            $this->profiles->updateProfile($usuarioId, $data);

            return $this->obtenerPerfil($usuarioId);
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function cambiarPassword(int $usuarioId, array $input): void
    {
        $this->profiles->transactional(function () use (
            $usuarioId,
            $input
        ): void {
            $user = $this->assertActiveUser($usuarioId);
            $current = $this->requiredString(
                $input,
                'password_actual',
                'La contraseña actual es obligatoria.'
            );
            $new = $this->requiredString(
                $input,
                'password_nueva',
                'La nueva contraseña es obligatoria.'
            );
            $confirmation = $this->requiredString(
                $input,
                'password_confirmacion',
                'La confirmación de contraseña es obligatoria.'
            );
            $errors = [];

            if (!password_verify($current, (string) $user['password_hash'])) {
                $errors['password_actual'] =
                    'La contraseña actual no es correcta.';
            }
            if ($new !== $confirmation) {
                $errors['password_confirmacion'] =
                    'La confirmación no coincide.';
            }
            if (strlen($new) < 8) {
                $errors['password_nueva'] =
                    'La nueva contraseña debe tener al menos 8 caracteres.';
            }
            if (
                $errors === []
                && password_verify($new, (string) $user['password_hash'])
            ) {
                $errors['password_nueva'] =
                    'La nueva contraseña debe ser distinta a la actual.';
            }

            if ($errors !== []) {
                throw new ProfileValidationException($errors);
            }

            $this->profiles->updatePasswordHash(
                $usuarioId,
                password_hash($new, PASSWORD_DEFAULT)
            );
        });
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function registrarFoto(
        int $usuarioId,
        array $metadata,
        ?int $actorId = null
    ): array {
        return $this->profiles->transactional(function () use (
            $usuarioId,
            $metadata,
            $actorId
        ): array {
            $this->assertActiveUser($usuarioId);

            if ($actorId !== null) {
                $this->assertActiveUser($actorId);
            }

            $data = $this->photoInput($metadata);
            $this->photos->activePhoto($usuarioId, true);
            $this->photos->deactivateActivePhotos($usuarioId, $actorId);
            $this->photos->insertPhoto($usuarioId, $data, $actorId);
            $photo = $this->photos->activePhoto($usuarioId);

            if ($photo === null) {
                throw new \RuntimeException('Active user photo not found.');
            }

            return $this->safePhoto($photo);
        });
    }

    public function eliminarFoto(int $usuarioId, ?int $actorId = null): void
    {
        $this->profiles->transactional(function () use (
            $usuarioId,
            $actorId
        ): void {
            $this->assertActiveUser($usuarioId);

            if ($actorId !== null) {
                $this->assertActiveUser($actorId);
            }

            $this->photos->markActivePhotoDeleted($usuarioId, $actorId);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function obtenerFotoActiva(int $usuarioId): ?array
    {
        $this->assertActiveUser($usuarioId);
        $photo = $this->photos->activePhoto($usuarioId);

        return $photo === null ? null : $this->safePhoto($photo);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertActiveUser(int $usuarioId): array
    {
        if ($usuarioId < 1) {
            throw new ProfileValidationException([
                'usuario_id' => 'El usuario no es válido.',
            ]);
        }

        $user = $this->profiles->findActiveUser($usuarioId);

        if ($user === null) {
            throw new ProfileValidationException([
                'usuario_id' => 'El usuario no existe o no está activo.',
            ]);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private function profileInput(array $input): array
    {
        $errors = [];

        foreach (self::FORBIDDEN_PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $errors[$field] = 'Este campo no puede actualizarse desde perfil.';
            }
        }

        $data = [];

        foreach (self::PROFILE_FIELDS as $field => $maxLength) {
            $value = $this->nullableString($input[$field] ?? null);

            if ($value !== null && $this->length($value) > $maxLength) {
                $errors[$field] = 'Máximo ' . $maxLength . ' caracteres.';
            }

            if (
                $value !== null
                && in_array($field, self::URL_FIELDS, true)
                && !$this->validHttpUrl($value)
            ) {
                $errors[$field] = 'Usa una URL http o https válida.';
            }

            $data[$field] = $value;
        }

        if ($errors !== []) {
            throw new ProfileValidationException($errors);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{
     *     ruta_relativa: string,
     *     nombre_archivo: string,
     *     nombre_original: string|null,
     *     mime: string,
     *     extension: string,
     *     tamano_bytes: int,
     *     sha256: string,
     *     ancho: int|null,
     *     alto: int|null
     * }
     */
    private function photoInput(array $metadata): array
    {
        $errors = [];
        $path = $this->requiredString(
            $metadata,
            'ruta_relativa',
            'La ruta relativa es obligatoria.'
        );
        $filename = $this->requiredString(
            $metadata,
            'nombre_archivo',
            'El nombre de archivo es obligatorio.'
        );
        $originalName = $this->nullableString($metadata['nombre_original'] ?? null);
        $mime = strtolower($this->requiredString(
            $metadata,
            'mime',
            'El MIME es obligatorio.'
        ));
        $extension = strtolower($this->requiredString(
            $metadata,
            'extension',
            'La extensión es obligatoria.'
        ));
        $size = $this->positiveInt($metadata['tamano_bytes'] ?? null);
        $sha256 = strtolower($this->requiredString(
            $metadata,
            'sha256',
            'El hash SHA-256 es obligatorio.'
        ));
        $width = $this->optionalPositiveInt($metadata['ancho'] ?? null, 'ancho');
        $height = $this->optionalPositiveInt($metadata['alto'] ?? null, 'alto');

        if (!$this->safeRelativePath($path)) {
            $errors['ruta_relativa'] =
                'La ruta relativa no debe ser absoluta, pública, URL ni contener traversal.';
        }
        if (!$this->safeFileName($filename)) {
            $errors['nombre_archivo'] =
                'El nombre de archivo no es seguro.';
        }
        if (
            $originalName !== null
            && (!$this->safeFileName($originalName)
                || $this->hasDangerousDoubleExtension($originalName))
        ) {
            $errors['nombre_original'] =
                'El nombre original no es seguro.';
        }
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            $errors['mime'] = 'El tipo de imagen no está permitido.';
        }
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $errors['extension'] = 'La extensión no está permitida.';
        }
        if ($size === null || $size > self::MAX_PHOTO_BYTES) {
            $errors['tamano_bytes'] =
                'El tamaño debe ser mayor a 0 y máximo 5 MB.';
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            $errors['sha256'] = 'El hash SHA-256 no es válido.';
        }
        if ($width === false) {
            $errors['ancho'] = 'El ancho debe ser positivo o null.';
        }
        if ($height === false) {
            $errors['alto'] = 'El alto debe ser positivo o null.';
        }
        if (($width === null) !== ($height === null)) {
            $errors['dimensiones'] =
                'Ancho y alto deben enviarse juntos o ambos en null.';
        }
        if ($this->hasDangerousDoubleExtension($filename)) {
            $errors['nombre_archivo'] =
                'No se permiten dobles extensiones peligrosas.';
        }

        if ($errors !== []) {
            throw new ProfileValidationException($errors);
        }

        return [
            'ruta_relativa' => $path,
            'nombre_archivo' => $filename,
            'nombre_original' => $originalName,
            'mime' => $mime,
            'extension' => $extension,
            'tamano_bytes' => $size,
            'sha256' => $sha256,
            'ancho' => $width,
            'alto' => $height,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requiredString(
        array $input,
        string $field,
        string $message
    ): string {
        $value = $this->nullableString($input[$field] ?? null);

        if ($value === null) {
            throw new ProfileValidationException([$field => $message]);
        }

        return $value;
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

    private function positiveInt(mixed $value): ?int
    {
        if (
            (!is_string($value) && !is_int($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
        ) {
            return null;
        }

        return (int) $value;
    }

    private function optionalPositiveInt(mixed $value, string $field): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        $integer = $this->positiveInt($value);

        return $integer === null ? false : $integer;
    }

    private function safeRelativePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        if ($normalized !== $path) {
            return false;
        }
        if (
            str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:/', $normalized) === 1
            || preg_match('#^https?://#i', $normalized) === 1
            || str_contains($normalized, '..')
            || str_contains($normalized, "\0")
            || str_starts_with($normalized, 'public/')
        ) {
            return false;
        }

        return $this->length($normalized) <= 500;
    }

    private function safeFileName(string $filename): bool
    {
        if (
            $filename === ''
            || $this->length($filename) > 255
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, '..')
            || str_contains($filename, "\0")
        ) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._ -]+$/', $filename) === 1;
    }

    private function hasDangerousDoubleExtension(string $filename): bool
    {
        $parts = explode('.', strtolower($filename));

        if (count($parts) < 3) {
            return false;
        }

        array_pop($parts);

        foreach ($parts as $part) {
            if (in_array($part, self::DANGEROUS_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeProfile(array $profile): array
    {
        return [
            'id' => (int) $profile['id'],
            'usuario_id' => (int) $profile['usuario_id'],
            'primer_nombre' => $profile['primer_nombre'],
            'segundo_nombre' => $profile['segundo_nombre'],
            'apellido_paterno' => $profile['apellido_paterno'],
            'apellido_materno' => $profile['apellido_materno'],
            'puesto' => $profile['puesto'],
            'telefono_fijo' => $profile['telefono_fijo'],
            'telefono_movil' => $profile['telefono_movil'],
            'sitio_web' => $profile['sitio_web'],
            'linkedin_url' => $profile['linkedin_url'],
            'facebook_url' => $profile['facebook_url'],
            'instagram_url' => $profile['instagram_url'],
            'whatsapp' => $profile['whatsapp'],
            'google_maps_url' => $profile['google_maps_url'],
            'ubicacion_publica' => $profile['ubicacion_publica'],
            'creado_en' => $profile['creado_en'],
            'actualizado_en' => $profile['actualizado_en'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function safePhoto(array $photo): array
    {
        return [
            'id' => (int) $photo['id'],
            'usuario_id' => (int) $photo['usuario_id'],
            'disco' => (string) $photo['disco'],
            'ruta_relativa' => (string) $photo['ruta_relativa'],
            'nombre_original' => $photo['nombre_original'],
            'nombre_archivo' => (string) $photo['nombre_archivo'],
            'mime' => (string) $photo['mime'],
            'extension' => (string) $photo['extension'],
            'tamano_bytes' => (int) $photo['tamano_bytes'],
            'sha256' => (string) $photo['sha256'],
            'ancho' => $photo['ancho'] === null ? null : (int) $photo['ancho'],
            'alto' => $photo['alto'] === null ? null : (int) $photo['alto'],
            'activa' => (int) $photo['activa'],
            'creado_en' => $photo['creado_en'],
        ];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }
}
