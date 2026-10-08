<?php

declare(strict_types=1);

namespace App\Domain\Vcards;

use App\Infrastructure\Repositories\VcardPrivacyRepository;

final class VcardPrivacyService
{
    public const FIELDS = [
        'foto',
        'correo',
        'telefono_fijo',
        'telefono_movil',
        'puesto',
        'empresa',
        'almacen',
        'ubicacion',
        'sitio_web',
        'linkedin',
        'facebook',
        'instagram',
        'whatsapp',
        'google_maps',
        'productos',
    ];

    public function __construct(
        private readonly VcardPrivacyRepository $privacy
    ) {
    }

    /**
     * @return list<string>
     */
    public function camposPermitidos(): array
    {
        return self::FIELDS;
    }

    /**
     * @return array<string, bool>
     */
    public function privacidadDefault(): array
    {
        return array_fill_keys(self::FIELDS, false);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, bool>
     */
    public function normalizarPrivacidad(array $input): array
    {
        $unknown = array_diff(array_keys($input), self::FIELDS);

        if ($unknown !== []) {
            throw new VcardValidationException([
                'privacidad' => 'La privacidad contiene campos no permitidos.',
            ]);
        }

        $normalized = $this->privacidadDefault();

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];
            $normalized[$field] = in_array($value, [true, 1, '1', 'on'], true);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, bool>
     */
    public function actualizarPrivacidad(int $vcardId, array $input): array
    {
        if ($vcardId < 1) {
            throw new VcardValidationException([
                'vcard_id' => 'La vCard no es válida.',
            ]);
        }

        $visibility = $this->normalizarPrivacidad($input);
        $this->privacy->ensureDefaults($vcardId, $this->privacidadDefault());
        $this->privacy->upsertVisibility($vcardId, $visibility);

        return $this->privacyForVcard($vcardId);
    }

    /**
     * @return array<string, bool>
     */
    public function privacyForVcard(int $vcardId): array
    {
        $stored = $this->privacy->listByVcard($vcardId);

        return $stored + $this->privacidadDefault();
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, bool> $privacidad
     * @return array<string, mixed>
     */
    public function aplicarPrivacidad(array $datos, array $privacidad): array
    {
        $public = [];

        foreach (self::FIELDS as $field) {
            if (!$this->esVisible($privacidad, $field)) {
                continue;
            }

            if (array_key_exists($field, $datos) && $datos[$field] !== null && $datos[$field] !== '') {
                $public[$field] = $datos[$field];
            }
        }

        return $public;
    }

    /**
     * @param array<string, bool> $privacidad
     */
    public function esVisible(array $privacidad, string $campo): bool
    {
        if (!in_array($campo, self::FIELDS, true)) {
            return false;
        }

        return ($privacidad[$campo] ?? false) === true;
    }
}
