<?php

declare(strict_types=1);

namespace App\Domain\Products;

use App\Infrastructure\Repositories\ProductDocumentRepository;
use App\Infrastructure\Repositories\ProductRepository;

final class ProductImageService
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const STORAGE_ROOT = 'uploads/productos';
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductDocumentRepository $documents,
        private readonly string $storagePath
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(string $productId): ?array
    {
        $productId = $this->validatedProductId($productId);

        if (!$this->products->exists($productId)) {
            throw new ProductValidationException([
                'id_producto' => 'El producto solicitado no existe.',
            ]);
        }

        return $this->documents->activeMainPhoto($productId);
    }

    /**
     * @return array{body: string, mime_type: string, size: int, version: string}
     */
    public function content(string $productId): array
    {
        $photo = $this->current($productId);

        if ($photo === null) {
            throw new ProductValidationException([
                'imagen' => 'El producto no tiene imagen principal.',
            ]);
        }

        $path = $this->safePath((string) $photo['ruta_relativa']);

        if (!is_file($path) || !is_readable($path)) {
            throw new ProductValidationException([
                'imagen' => 'La imagen principal no está disponible.',
            ]);
        }

        $body = file_get_contents($path);

        if ($body === false) {
            throw new ProductValidationException([
                'imagen' => 'La imagen principal no está disponible.',
            ]);
        }

        return [
            'body' => $body,
            'mime_type' => (string) $photo['mime_type'],
            'size' => strlen($body),
            'version' => (string) (
                $photo['actualizado_en'] ?? $photo['creado_en'] ?? ''
            ),
        ];
    }

    /**
     * @param array<string, mixed>|null $upload
     */
    public function replace(string $productId, ?array $upload, int $actorId): void
    {
        $this->assertActor($actorId);
        $productId = $this->validatedProductId($productId);
        $validated = $this->prepareImageUpload($upload);
        $relativePath = $this->relativePath($productId, $validated['extension']);
        $targetPath = $this->absolutePathForNewFile($relativePath);
        $oldPhoto = null;

        $this->ensureDirectory(dirname($targetPath));

        if (!$this->movePreparedUpload($validated['tmp_name'], $targetPath)) {
            throw new ProductValidationException([
                'imagen' => 'No fue posible almacenar la imagen.',
            ]);
        }

        try {
            $oldPhoto = $this->documents->transactional(function () use (
                $actorId,
                $productId,
                $relativePath,
                $validated
            ): ?array {
                if (!$this->documents->lockProduct($productId)) {
                    throw new ProductValidationException([
                        'id_producto' => 'El producto solicitado no existe.',
                    ]);
                }

                $previous = $this->documents->lockActiveMainPhoto($productId);
                $documentId = $this->documents->insertMainPhoto([
                    'id_producto' => $productId,
                    'nombre_original' => $validated['original_name'],
                    'ruta_relativa' => $relativePath,
                    'mime_type' => $validated['mime_type'],
                    'tamano_bytes' => $validated['size'],
                ], $actorId);
                $this->documents->deactivateActiveMainPhotosExcept(
                    $productId,
                    $documentId,
                    $actorId
                );

                return $previous;
            });
        } catch (\Throwable $exception) {
            $this->deleteFileIfInsideRoot($targetPath);
            throw $exception;
        }

        if ($oldPhoto !== null) {
            $this->deleteRelativeFile((string) $oldPhoto['ruta_relativa']);
        }
    }

    /**
     * @param array<string, mixed>|null $upload
     * @return array{
     *     tmp_name: string,
     *     original_name: string,
     *     mime_type: string,
     *     extension: string,
     *     size: int
     * }
     */
    public function prepareImageUpload(?array $upload): array
    {
        return $this->validatedUpload($upload);
    }

    /**
     * @param array{
     *     tmp_name: string,
     *     original_name: string,
     *     mime_type: string,
     *     extension: string,
     *     size: int
     * } $validated
     */
    public function attachPreparedMainPhotoToNewProduct(
        string $productId,
        array $validated,
        int $actorId
    ): string {
        $this->assertActor($actorId);
        $productId = $this->validatedProductId($productId);
        $relativePath = $this->relativePath($productId, $validated['extension']);
        $targetPath = $this->absolutePathForNewFile($relativePath);

        $this->ensureDirectory(dirname($targetPath));

        if (!$this->movePreparedUpload($validated['tmp_name'], $targetPath)) {
            throw new ProductValidationException([
                'imagen' => 'No fue posible almacenar la imagen.',
            ]);
        }

        try {
            if (!$this->documents->lockProduct($productId)) {
                throw new ProductValidationException([
                    'id_producto' => 'El producto solicitado no existe.',
                ]);
            }

            $documentId = $this->documents->insertMainPhoto([
                'id_producto' => $productId,
                'nombre_original' => $validated['original_name'],
                'ruta_relativa' => $relativePath,
                'mime_type' => $validated['mime_type'],
                'tamano_bytes' => $validated['size'],
            ], $actorId);
            $this->documents->deactivateActiveMainPhotosExcept(
                $productId,
                $documentId,
                $actorId
            );
        } catch (\Throwable $exception) {
            $this->deleteFileIfInsideRoot($targetPath);
            throw $exception;
        }

        return $targetPath;
    }

    public function discardStoredFile(string $path): void
    {
        $this->deleteFileIfInsideRoot($path);
    }

    public function delete(string $productId, int $actorId): void
    {
        $this->assertActor($actorId);
        $productId = $this->validatedProductId($productId);
        $deleted = $this->documents->transactional(function () use (
            $actorId,
            $productId
        ): ?array {
            if (!$this->documents->lockProduct($productId)) {
                throw new ProductValidationException([
                    'id_producto' => 'El producto solicitado no existe.',
                ]);
            }

            $photo = $this->documents->lockActiveMainPhoto($productId);

            if ($photo === null) {
                return null;
            }

            $this->documents->deactivateMainPhoto((int) $photo['id'], $actorId);

            return $photo;
        });

        if ($deleted !== null) {
            $this->deleteRelativeFile((string) $deleted['ruta_relativa']);
        }
    }

    private function validatedProductId(string $productId): string
    {
        if (preg_match('/^[A-Z0-9]{1,16}$/', $productId) !== 1) {
            throw new ProductValidationException([
                'id_producto' => 'El producto solicitado no es válido.',
            ]);
        }

        return $productId;
    }

    /**
     * @param array<string, mixed>|null $upload
     * @return array{
     *     tmp_name: string,
     *     original_name: string,
     *     mime_type: string,
     *     extension: string,
     *     size: int
     * }
     */
    private function validatedUpload(?array $upload): array
    {
        if ($upload === null) {
            throw new ProductValidationException([
                'imagen' => 'Selecciona una imagen.',
            ]);
        }

        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error !== UPLOAD_ERR_OK) {
            throw new ProductValidationException([
                'imagen' => 'La carga de imagen no se completó correctamente.',
            ]);
        }

        $tmpName = $upload['tmp_name'] ?? '';

        if (!is_string($tmpName) || $tmpName === '' || !is_file($tmpName)) {
            throw new ProductValidationException([
                'imagen' => 'El archivo temporal de imagen no existe.',
            ]);
        }

        $size = filesize($tmpName);

        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new ProductValidationException([
                'imagen' => 'La imagen debe pesar entre 1 byte y 5 MiB.',
            ]);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new ProductValidationException([
                'imagen' => 'No fue posible validar el MIME real.',
            ]);
        }

        try {
            $mimeType = finfo_file($finfo, $tmpName);
        } finally {
            finfo_close($finfo);
        }

        if (!is_string($mimeType) || !isset(self::MIME_EXTENSIONS[$mimeType])) {
            throw new ProductValidationException([
                'imagen' => 'Solo se permiten imágenes JPEG, PNG o WebP.',
            ]);
        }

        if (@getimagesize($tmpName) === false) {
            throw new ProductValidationException([
                'imagen' => 'El archivo no es una imagen válida.',
            ]);
        }

        $originalName = $upload['name'] ?? 'imagen';

        if (!is_string($originalName) || trim($originalName) === '') {
            $originalName = 'imagen';
        }

        return [
            'tmp_name' => $tmpName,
            'original_name' => $this->limitOriginalName(trim($originalName)),
            'mime_type' => $mimeType,
            'extension' => self::MIME_EXTENSIONS[$mimeType],
            'size' => $size,
        ];
    }

    private function limitOriginalName(string $name): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, 255, 'UTF-8');
        }

        return substr($name, 0, 255);
    }

    private function relativePath(string $productId, string $extension): string
    {
        return $productId
            . '/foto-principal-'
            . bin2hex(random_bytes(16))
            . '.'
            . $extension;
    }

    private function absolutePathForNewFile(string $relativePath): string
    {
        if (
            str_contains($relativePath, '..')
            || str_contains($relativePath, '\\')
            || str_starts_with($relativePath, '/')
        ) {
            throw new ProductValidationException([
                'imagen' => 'La ruta de imagen no es segura.',
            ]);
        }

        return $this->uploadRoot() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function safePath(string $relativePath): string
    {
        $candidate = $this->absolutePathForNewFile($relativePath);
        $root = $this->normalized($this->uploadRoot()) . '/';
        $real = realpath($candidate);

        if ($real === false || !str_starts_with($this->normalized($real), $root)) {
            throw new ProductValidationException([
                'imagen' => 'La ruta de imagen no es segura.',
            ]);
        }

        return $real;
    }

    private function uploadRoot(): string
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
            throw new ProductValidationException([
                'imagen' => 'No fue posible preparar el almacenamiento.',
            ]);
        }
    }

    private function deleteRelativeFile(string $relativePath): void
    {
        try {
            $path = $this->safePath($relativePath);
        } catch (ProductValidationException) {
            return;
        }

        $this->deleteFileIfInsideRoot($path);
    }

    private function deleteFileIfInsideRoot(string $path): void
    {
        $real = realpath($path);

        if ($real === false) {
            return;
        }

        $root = $this->normalized($this->uploadRoot()) . '/';

        if (str_starts_with($this->normalized($real), $root) && is_file($real)) {
            @unlink($real);
            $this->removeEmptyProductDirectory(dirname($real));
        }
    }

    private function removeEmptyProductDirectory(string $directory): void
    {
        $root = $this->normalized($this->uploadRoot());
        $normalized = $this->normalized($directory);

        if ($normalized === $root || !str_starts_with($normalized, $root . '/')) {
            return;
        }

        $items = @scandir($directory);

        if ($items === false || array_diff($items, ['.', '..']) !== []) {
            return;
        }

        @rmdir($directory);
    }

    private function normalized(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function movePreparedUpload(string $tmpName, string $targetPath): bool
    {
        if (move_uploaded_file($tmpName, $targetPath)) {
            return true;
        }

        return rename($tmpName, $targetPath);
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId < 1) {
            throw new \InvalidArgumentException('A valid actor is required.');
        }
    }
}
