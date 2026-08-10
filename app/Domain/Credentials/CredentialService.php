<?php

declare(strict_types=1);

namespace App\Domain\Credentials;

use App\Infrastructure\Repositories\UserCredentialRepository;

final class CredentialService
{
    public function __construct(private readonly UserCredentialRepository $credentials)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function asegurarCredencial(int $usuarioId): array
    {
        if ($usuarioId < 1 || $this->credentials->activeUser($usuarioId) === null) {
            throw new CredentialValidationException([
                'usuario_id' => 'El usuario no existe o no está activo.',
            ]);
        }

        $credential = $this->credentials->credentialByUser($usuarioId);

        if ($credential === null) {
            $this->credentials->createBaseCredential($usuarioId);
            $credential = $this->credentials->credentialByUser($usuarioId);
        }

        if ($credential === null) {
            throw new CredentialValidationException([
                'credencial' => 'No fue posible asegurar la credencial.',
            ]);
        }

        return $this->safeCredential($credential);
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerCredencialVisual(int $usuarioId): array
    {
        $credential = $this->asegurarCredencial($usuarioId);
        $visual = $this->credentials->visualDataByUser($usuarioId);

        if ($visual === null) {
            throw new CredentialValidationException([
                'usuario_id' => 'El usuario no existe o no está activo.',
            ]);
        }

        $fullName = trim(implode(' ', array_filter([
            $visual['primer_nombre'] ?? null,
            $visual['segundo_nombre'] ?? null,
            $visual['apellido_paterno'] ?? null,
            $visual['apellido_materno'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));

        return [
            'nombre_completo' => $fullName !== ''
                ? $fullName
                : (string) ($visual['username'] ?? ''),
            'username' => (string) ($visual['username'] ?? ''),
            'email' => (string) ($visual['email'] ?? ''),
            'puesto' => $this->optionalString($visual['puesto'] ?? null),
            'telefono_movil' => $this->optionalString($visual['telefono_movil'] ?? null),
            'telefono_fijo' => $this->optionalString($visual['telefono_fijo'] ?? null),
            'ubicacion' => $this->optionalString($visual['ubicacion_publica'] ?? null),
            'estatus' => (string) ($credential['estatus'] ?? 'VIGENTE'),
            'emitida_en' => $credential['emitida_en'],
            'creado_en' => $credential['creado_en'],
            'foto' => $this->safePhoto($visual),
            'verificacion_publica' => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function obtenerFotoPrivada(int $usuarioId): ?array
    {
        if ($usuarioId < 1 || $this->credentials->activeUser($usuarioId) === null) {
            return null;
        }

        $photo = $this->credentials->activePhotoByUser($usuarioId);

        if ($photo === null) {
            return null;
        }

        return [
            'ruta_relativa' => (string) ($photo['ruta_relativa'] ?? ''),
            'nombre_archivo' => (string) ($photo['nombre_archivo'] ?? ''),
            'mime' => (string) ($photo['mime'] ?? ''),
            'extension' => (string) ($photo['extension'] ?? ''),
            'tamano_bytes' => (int) ($photo['tamano_bytes'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    private function safeCredential(array $credential): array
    {
        return [
            'estatus' => (string) ($credential['estatus'] ?? 'VIGENTE'),
            'emitida_en' => $credential['emitida_en'] ?? null,
            'creado_en' => $credential['creado_en'] ?? null,
        ];
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $visual
     * @return array<string, mixed>|null
     */
    private function safePhoto(array $visual): ?array
    {
        if (empty($visual['foto_id'])) {
            return null;
        }

        return [
            'registrada' => true,
            'nombre_archivo' => (string) ($visual['foto_nombre_archivo'] ?? ''),
            'mime' => (string) ($visual['foto_mime'] ?? ''),
            'endpoint' => '/perfil/credencial/foto',
            'tamano_bytes' => (int) ($visual['foto_tamano_bytes'] ?? 0),
            'creado_en' => $visual['foto_creado_en'] ?? null,
        ];
    }
}
