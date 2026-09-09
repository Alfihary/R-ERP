<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Tickets\ProductRequestTicketValidationException;

final class SafeUpload
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const ALLOWED_MIME_BY_EXTENSION = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
    private const DANGEROUS_EXTENSIONS = [
        'bat',
        'cmd',
        'com',
        'exe',
        'htm',
        'html',
        'js',
        'phtml',
        'phar',
        'php',
        'ps1',
        'sh',
        'svg',
    ];

    /**
     * @param array<string, mixed> $file
     * @return array{
     *     nombre_original: string,
     *     nombre_guardado: string,
     *     ruta_relativa: string,
     *     mime: string,
     *     extension: string,
     *     tamano_bytes: int,
     *     hash_sha256: string
     * }
     */
    public function storeTicketAttachment(array $file, int $ticketId): array
    {
        $this->assertFileShape($file);

        $originalName = $this->safeOriginalName((string) $file['name']);
        $tmpPath = (string) $file['tmp_name'];
        $size = (int) $file['size'];
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new ProductRequestTicketValidationException([
                'adjunto' => 'No fue posible recibir el archivo adjunto.',
            ]);
        }

        if ($size <= 0) {
            throw new ProductRequestTicketValidationException([
                'adjunto' => 'El archivo adjunto está vacío.',
            ]);
        }

        if ($size > self::MAX_BYTES) {
            throw new ProductRequestTicketValidationException([
                'adjunto' => 'El archivo adjunto no debe superar 5 MB.',
            ]);
        }

        if (!isset(self::ALLOWED_MIME_BY_EXTENSION[$extension])) {
            throw new ProductRequestTicketValidationException([
                'adjunto' => 'El tipo de archivo adjunto no está permitido.',
            ]);
        }

        if ($this->hasDangerousDoubleExtension($originalName)) {
            throw new ProductRequestTicketValidationException([
                'adjunto' => 'El nombre del archivo adjunto no es seguro.',
            ]);
        }

        if (!$this->isHttpUpload($tmpPath)) {
            throw new ProductRequestTicketValidationException([
                'adjunto' => 'El archivo adjunto no proviene de una subida válida.',
            ]);
        }

        $mime = $this->realMime($tmpPath);

        if ($mime !== self::ALLOWED_MIME_BY_EXTENSION[$extension]) {
            throw new ProductRequestTicketValidationException([
                'adjunto' => 'El contenido del archivo no coincide con su extensión.',
            ]);
        }

        $hash = hash_file('sha256', $tmpPath);

        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new ProductRequestTicketValidationException([
                'adjunto' => 'No fue posible validar la integridad del archivo.',
            ]);
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativePath = 'private/tickets_productos/' . $ticketId . '/' . $storedName;
        $targetDir = BASE_PATH . '/storage/private/tickets_productos/' . $ticketId;
        $targetPath = $targetDir . '/' . $storedName;

        if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
            throw new ProductRequestTicketValidationException([
                'adjunto' => 'No fue posible preparar el almacenamiento privado.',
            ]);
        }

        if (!$this->moveUpload($tmpPath, $targetPath)) {
            throw new ProductRequestTicketValidationException([
                'adjunto' => 'No fue posible guardar el archivo adjunto.',
            ]);
        }

        return [
            'nombre_original' => $originalName,
            'nombre_guardado' => $storedName,
            'ruta_relativa' => $relativePath,
            'mime' => $mime,
            'extension' => $extension,
            'tamano_bytes' => $size,
            'hash_sha256' => $hash,
        ];
    }

    /**
     * @param array<string, mixed> $file
     */
    private function assertFileShape(array $file): void
    {
        foreach (['name', 'tmp_name', 'size', 'error'] as $field) {
            if (!array_key_exists($field, $file)) {
                throw new ProductRequestTicketValidationException([
                    'adjunto' => 'Selecciona un archivo adjunto válido.',
                ]);
            }
        }
    }

    private function safeOriginalName(string $name): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', $name));

        if ($name === '' || mb_strlen($name) > 255) {
            throw new ProductRequestTicketValidationException([
                'adjunto' => 'El nombre del archivo adjunto no es válido.',
            ]);
        }

        return $name;
    }

    private function hasDangerousDoubleExtension(string $name): bool
    {
        $parts = array_map('strtolower', explode('.', $name));

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

    private function isHttpUpload(string $tmpPath): bool
    {
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return false;
        }

        return PHP_SAPI === 'cli' || is_uploaded_file($tmpPath);
    }

    private function realMime(string $tmpPath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);

        return is_string($mime) ? $mime : '';
    }

    private function moveUpload(string $tmpPath, string $targetPath): bool
    {
        if (PHP_SAPI === 'cli') {
            return rename($tmpPath, $targetPath);
        }

        return move_uploaded_file($tmpPath, $targetPath);
    }
}
