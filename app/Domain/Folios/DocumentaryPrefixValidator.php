<?php

declare(strict_types=1);

namespace App\Domain\Folios;

final class DocumentaryPrefixValidator
{
    private const PREFIX_PATTERN = '/^[A-Z0-9]{2,5}$/D';

    private const MIN_CONSECUTIVE = 1;

    private const MAX_CONSECUTIVE = 999999;

    public function validatePrefix(mixed $prefix): string
    {
        if (!is_string($prefix) || preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new FolioValidationException(
                'El prefijo documental debe contener de 2 a 5 caracteres ASCII en mayúsculas o dígitos.'
            );
        }

        return $prefix;
    }

    public function validateConsecutive(mixed $consecutive): int
    {
        if (
            !is_int($consecutive)
            || $consecutive < self::MIN_CONSECUTIVE
            || $consecutive > self::MAX_CONSECUTIVE
        ) {
            throw new FolioValidationException(
                'El consecutivo documental debe ser un entero entre 1 y 999999.'
            );
        }

        return $consecutive;
    }

    public function formatFolio(mixed $prefix, mixed $consecutive): string
    {
        $validatedPrefix = $this->validatePrefix($prefix);
        $validatedConsecutive = $this->validateConsecutive($consecutive);

        return $validatedPrefix
            . '-'
            . str_pad((string) $validatedConsecutive, 6, '0', STR_PAD_LEFT);
    }
}
