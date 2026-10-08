<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Profile\ProfileValidationException;

final class UserPhotoStorage
{
    private const STORAGE_ROOT = 'uploads/usuarios';
    private const MAX_BYTES = 5242880;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    private const DANGEROUS_EXTENSIONS = [
        'asp',
        'aspx',
        'cgi',
        'exe',
        'htm',
        'html',
        'js',
        'jsp',
        'phtml',
        'phar',
        'php',
        'php3',
        'php4',
        'php5',
        'pl',
        'svg',
    ];

    public function __construct(
        private readonly string $storagePath,
        private readonly bool $allowLocalFilesForTests = false
    ) {
    }

    /**
     * @param array<string, mixed>|null $upload
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
    public function store(int $userId, ?array $upload): array
    {
        $prepared = $this->validatedUpload($upload);
        $relativePath = $this->relativePath($userId, $prepared['extension']);
        $targetPath = $this->absolutePathForNewFile($relativePath);

        $this->ensureDirectory(dirname($targetPath));

        if (!$this->movePreparedUpload($prepared['tmp_name'], $targetPath)) {
            throw new ProfileValidationException([
                'foto' => 'No fue posible almacenar la foto.',
            ]);
        }

        $size = filesize($targetPath);

        if ($size === false || $size < 1) {
            $this->deleteRelativeFile($relativePath, $userId);

            throw new ProfileValidationException([
                'foto' => 'No fue posible validar la foto almacenada.',
            ]);
        }

        return [
            'ruta_relativa' => $relativePath,
            'nombre_archivo' => basename($targetPath),
            'nombre_original' => $prepared['original_name'],
            'mime' => $prepared['mime'],
            'extension' => $prepared['extension'],
            'tamano_bytes' => $size,
            'sha256' => hash_file('sha256', $targetPath),
            'ancho' => $prepared['width'],
            'alto' => $prepared['height'],
        ];
    }

    public function deleteRelativeFile(string $relativePath, ?int $expectedUserId = null): void
    {
        try {
            $path = $this->safePath($relativePath, $expectedUserId);
        } catch (ProfileValidationException) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
            $this->removeEmptyDirectories(dirname($path));
        }
    }

    /** @return array{ruta_relativa:string,nombre_archivo:string,mime:string,extension:string,tamano_bytes:int,ancho:int|null,alto:int|null}|null */
    public function inspectRelativeFile(string $relativePath, ?int $expectedUserId = null): ?array
    {
        $path = $this->safePath($relativePath, $expectedUserId);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $size = filesize($path);
        $mime = $this->detectMime($path);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $dimensions = @getimagesize($path);
        $mimeExtensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];

        if ($size === false || $size < 1 || !in_array($extension, $mimeExtensions[$mime] ?? [], true) || !is_array($dimensions)) {
            return null;
        }

        return [
            'ruta_relativa' => str_replace('\\', '/', $relativePath),
            'nombre_archivo' => basename($path),
            'mime' => $mime,
            'extension' => $extension,
            'tamano_bytes' => $size,
            'ancho' => (int) $dimensions[0],
            'alto' => (int) $dimensions[1],
        ];
    }

    /**
     * @param array<string, mixed>|null $upload
     * @return array{
     *     tmp_name: string,
     *     original_name: string,
     *     mime: string,
     *     extension: string,
     *     width: int|null,
     *     height: int|null
     * }
     */
    private function validatedUpload(?array $upload): array
    {
        if ($upload === null) {
            throw new ProfileValidationException([
                'foto' => 'Selecciona una foto.',
            ]);
        }

        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error !== UPLOAD_ERR_OK) {
            throw new ProfileValidationException([
                'foto' => 'La carga de foto no se completó correctamente.',
            ]);
        }

        $tmpName = $upload['tmp_name'] ?? '';

        if (!is_string($tmpName) || $tmpName === '' || !is_file($tmpName)) {
            throw new ProfileValidationException([
                'foto' => 'El archivo temporal de foto no existe.',
            ]);
        }

        if (!$this->allowLocalFilesForTests && !is_uploaded_file($tmpName)) {
            throw new ProfileValidationException([
                'foto' => 'La foto no proviene de una carga HTTP válida.',
            ]);
        }

        $size = filesize($tmpName);

        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new ProfileValidationException([
                'foto' => 'La foto debe pesar entre 1 byte y 5 MiB.',
            ]);
        }

        $originalName = $this->validatedOriginalName($upload['name'] ?? null);
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new ProfileValidationException([
                'foto' => 'Solo se permiten fotos JPEG, PNG o WebP.',
            ]);
        }

        $mime = $this->detectMime($tmpName);
        $expectedExtension = self::MIME_EXTENSIONS[$mime] ?? null;

        if ($expectedExtension === null) {
            throw new ProfileValidationException([
                'foto' => 'Solo se permiten fotos JPEG, PNG o WebP.',
            ]);
        }

        if (
            ($expectedExtension === 'jpg' && !in_array($extension, ['jpg', 'jpeg'], true))
            || ($expectedExtension !== 'jpg' && $extension !== $expectedExtension)
        ) {
            throw new ProfileValidationException([
                'foto' => 'La extensión no coincide con el contenido real.',
            ]);
        }

        $dimensions = @getimagesize($tmpName);

        if (!is_array($dimensions)) {
            throw new ProfileValidationException([
                'foto' => 'El archivo no es una imagen válida.',
            ]);
        }

        return [
            'tmp_name' => $tmpName,
            'original_name' => $this->limitOriginalName($originalName),
            'mime' => $mime,
            'extension' => $expectedExtension,
            'width' => isset($dimensions[0]) ? (int) $dimensions[0] : null,
            'height' => isset($dimensions[1]) ? (int) $dimensions[1] : null,
        ];
    }

    private function validatedOriginalName(mixed $name): string
    {
        if (!is_string($name) || trim($name) === '') {
            throw new ProfileValidationException([
                'foto' => 'El nombre original de la foto no es válido.',
            ]);
        }

        $name = trim($name);

        if (
            str_contains($name, "\0")
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, '..')
        ) {
            throw new ProfileValidationException([
                'foto' => 'El nombre original de la foto no es seguro.',
            ]);
        }

        $parts = explode('.', strtolower($name));

        if (count($parts) < 2) {
            throw new ProfileValidationException([
                'foto' => 'La foto debe incluir una extensión permitida.',
            ]);
        }

        array_pop($parts);

        foreach ($parts as $part) {
            if (in_array($part, self::DANGEROUS_EXTENSIONS, true)) {
                throw new ProfileValidationException([
                    'foto' => 'No se permiten dobles extensiones peligrosas.',
                ]);
            }
        }

        return $name;
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new ProfileValidationException([
                'foto' => 'No fue posible validar el MIME real.',
            ]);
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            if (PHP_VERSION_ID < 80500) {
                finfo_close($finfo);
            }
        }

        return is_string($mime) ? $mime : '';
    }

    private function relativePath(int $userId, string $extension): string
    {
        if ($userId < 1) {
            throw new ProfileValidationException([
                'foto' => 'El usuario no es válido.',
            ]);
        }

        return self::STORAGE_ROOT
            . '/'
            . $userId
            . '/fotos/foto-perfil-'
            . date('YmdHis')
            . '-'
            . bin2hex(random_bytes(16))
            . '.'
            . $extension;
    }

    private function absolutePathForNewFile(string $relativePath): string
    {
        if (
            !str_starts_with($relativePath, self::STORAGE_ROOT . '/')
            || str_contains($relativePath, '..')
            || str_contains($relativePath, '\\')
            || str_starts_with($relativePath, '/')
        ) {
            throw new ProfileValidationException([
                'foto' => 'La ruta de foto no es segura.',
            ]);
        }

        return $this->storageRoot() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, substr($relativePath, strlen(self::STORAGE_ROOT) + 1));
    }

    private function safePath(string $relativePath, ?int $expectedUserId = null): string
    {
        $normalized = str_replace('\\', '/', trim($relativePath));
        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('~^[A-Za-z]:/~', $normalized) === 1 || str_contains($normalized, '..') || str_contains($normalized, "\0")) {
            throw new ProfileValidationException(['foto' => 'La ruta de foto no es segura.']);
        }

        $isNew = preg_match('~\\Auploads/usuarios/([1-9][0-9]*)/fotos/([A-Za-z0-9][A-Za-z0-9._-]{0,254})\\z~D', $normalized, $matches) === 1;
        $isLegacy = preg_match('~\\Auploads/perfiles/([A-Za-z0-9][A-Za-z0-9._-]{0,254})\\z~D', $normalized) === 1;
        if ((!$isNew && !$isLegacy) || ($isNew && $expectedUserId !== null && (int) $matches[1] !== $expectedUserId)) {
            throw new ProfileValidationException(['foto' => 'La ruta de foto no es segura.']);
        }

        $uploadsRoot = rtrim($this->storagePath, '/\\') . DIRECTORY_SEPARATOR . 'uploads';
        $uploadsReal = realpath($uploadsRoot);
        if ($uploadsReal === false) {
            throw new ProfileValidationException(['foto' => 'La ruta de foto no es segura.']);
        }
        $candidate = rtrim($this->storagePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $real = realpath($candidate);
        $root = rtrim($this->normalized($uploadsReal), '/') . '/';
        if ($real === false || !str_starts_with($this->normalized($real), $root)) {
            throw new ProfileValidationException([
                'foto' => 'La ruta de foto no es segura.',
            ]);
        }

        return $real;
    }

    private function storageRoot(): string
    {
        return rtrim($this->storagePath, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::STORAGE_ROOT);
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ProfileValidationException([
                'foto' => 'No fue posible preparar el almacenamiento de fotos.',
            ]);
        }
    }

    private function movePreparedUpload(string $tmpName, string $targetPath): bool
    {
        if (!$this->allowLocalFilesForTests) {
            return move_uploaded_file($tmpName, $targetPath);
        }

        return copy($tmpName, $targetPath);
    }

    private function removeEmptyDirectories(string $directory): void
    {
        $root = $this->normalized($this->storageRoot());
        $normalized = $this->normalized($directory);

        while ($normalized !== $root && str_starts_with($normalized, $root . '/')) {
            $items = @scandir($directory);

            if ($items === false || array_diff($items, ['.', '..']) !== []) {
                return;
            }

            @rmdir($directory);
            $directory = dirname($directory);
            $normalized = $this->normalized($directory);
        }
    }

    private function limitOriginalName(string $name): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, 255, 'UTF-8');
        }

        return substr($name, 0, 255);
    }

    private function normalized(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
