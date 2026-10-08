<?php

declare(strict_types=1);

namespace App\Domain\Vcards;

final class VcardVcfService
{
    private const VERSION = '3.0';

    /**
     * @param array<string, mixed> $publicVcard
     */
    public function hasMinimumData(array $publicVcard): bool
    {
        return $this->text($publicVcard['titulo_publico'] ?? null) !== ''
            || $this->text($publicVcard['nombre'] ?? null) !== '';
    }

    /**
     * @param array<string, mixed> $publicVcard
     */
    public function generate(array $publicVcard): ?string
    {
        if (!$this->hasMinimumData($publicVcard)) {
            return null;
        }

        $fullName = $this->fullName($publicVcard);
        $lines = [
            'BEGIN:VCARD',
            'VERSION:' . self::VERSION,
            'FN:' . $this->escape($fullName),
        ];
        $structuredName = $this->structuredName($this->text($publicVcard['nombre'] ?? null));

        if ($structuredName !== null) {
            $lines[] = 'N:' . implode(';', array_map($this->escape(...), $structuredName));
        }

        $this->appendTextLine($lines, 'TITLE', $publicVcard['puesto'] ?? null);
        $this->appendTextLine($lines, 'ORG', $publicVcard['empresa'] ?? null);
        $this->appendTextLine($lines, 'TEL;TYPE=CELL', $publicVcard['telefono_movil'] ?? null);
        $this->appendTextLine($lines, 'TEL;TYPE=WORK,VOICE', $publicVcard['telefono_fijo'] ?? null);
        $this->appendTextLine($lines, 'TEL;TYPE=WHATSAPP', $publicVcard['whatsapp'] ?? null);
        $this->appendTextLine($lines, 'EMAIL;TYPE=INTERNET', $publicVcard['correo'] ?? null);
        $this->appendTextLine($lines, 'URL;TYPE=WORK', $publicVcard['sitio_web'] ?? null);
        $this->appendTextLine($lines, 'URL;TYPE=LINKEDIN', $publicVcard['linkedin'] ?? null);
        $this->appendTextLine($lines, 'URL;TYPE=FACEBOOK', $publicVcard['facebook'] ?? null);
        $this->appendTextLine($lines, 'URL;TYPE=INSTAGRAM', $publicVcard['instagram'] ?? null);
        $this->appendTextLine($lines, 'URL;TYPE=MAP', $publicVcard['google_maps'] ?? null);

        $notes = [];
        $description = $this->text($publicVcard['descripcion_publica'] ?? null);
        $location = $this->text($publicVcard['ubicacion'] ?? null);

        if ($description !== '') {
            $notes[] = $description;
        }
        if ($location !== '') {
            $notes[] = 'Ubicación: ' . $location;
        }

        if ($notes !== []) {
            $lines[] = 'NOTE:' . $this->escape(implode("\n", $notes));
        }

        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines) . "\r\n";
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @param list<string> $lines
     */
    private function appendTextLine(array &$lines, string $name, mixed $value): void
    {
        $text = $this->text($value);

        if ($text === '') {
            return;
        }

        $lines[] = $name . ':' . $this->escape($text);
    }

    /**
     * @param array<string, mixed> $publicVcard
     */
    private function fullName(array $publicVcard): string
    {
        $name = $this->text($publicVcard['nombre'] ?? null);

        if ($name !== '') {
            return $name;
        }

        return $this->text($publicVcard['titulo_publico'] ?? null);
    }

    /**
     * @return list<string>|null
     */
    private function structuredName(string $name): ?array
    {
        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $name);

        if ($parts === false || $parts === []) {
            return ['', $name, '', '', ''];
        }

        $family = count($parts) > 1 ? (string) array_pop($parts) : '';
        $given = trim(implode(' ', $parts));

        if ($given === '' && $family === '') {
            $given = $name;
        }

        return [$family, $given, '', '', ''];
    }

    private function escape(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? $value;

        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\;', '\,', '\n'],
            $value
        );
    }

    private function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
