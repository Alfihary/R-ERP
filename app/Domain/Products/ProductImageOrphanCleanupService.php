<?php

declare(strict_types=1);

namespace App\Domain\Products;

use App\Infrastructure\Repositories\ProductDocumentRepository;
use RuntimeException;

final class ProductImageOrphanCleanupService
{
    private const STORAGE_ROOT = 'uploads/productos';

    public function __construct(
        private readonly ProductDocumentRepository $documents,
        private readonly string $storagePath
    ) {
    }

    /** @return list<string> */
    public function candidates(): array
    {
        $root = $this->root();

        if (!is_dir($root)) {
            return [];
        }

        $referenced = [];
        foreach ($this->documents->activeReferencedRelativePaths() as $path) {
            $referenced[$this->normalizeRelative($path)] = true;
        }

        $candidates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = $this->relativeFromRoot($file->getPathname());
            if (!isset($referenced[$relative])) {
                $candidates[] = $relative;
            }
        }

        sort($candidates);
        return $candidates;
    }

    /** @return list<string> */
    public function cleanup(bool $dryRun = true): array
    {
        $candidates = $this->candidates();

        if ($dryRun) {
            return $candidates;
        }

        foreach ($candidates as $relative) {
            $path = $this->safeFilePath($relative);
            if (is_file($path) && !@unlink($path)) {
                throw new RuntimeException('Unable to remove orphan image file.');
            }
        }

        return $candidates;
    }

    private function root(): string
    {
        return rtrim($this->storagePath, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::STORAGE_ROOT);
    }

    private function relativeFromRoot(string $path): string
    {
        $root = $this->normalized(realpath($this->root()) ?: $this->root());
        $real = realpath($path);

        if ($real === false) {
            throw new RuntimeException('Unable to resolve orphan image path.');
        }

        $normalized = $this->normalized($real);
        if (!str_starts_with($normalized, $root . '/')) {
            throw new RuntimeException('Orphan image path escaped storage root.');
        }

        return substr($normalized, strlen($root) + 1);
    }

    private function safeFilePath(string $relative): string
    {
        $relative = $this->normalizeRelative($relative);
        if ($relative === '' || str_contains($relative, '..')) {
            throw new RuntimeException('Unsafe orphan image path.');
        }

        $candidate = $this->root() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($candidate);
        $root = $this->normalized(realpath($this->root()) ?: $this->root());

        if ($real === false || !is_file($real)) {
            throw new RuntimeException('Orphan image file is not valid.');
        }

        $normalized = $this->normalized($real);
        if (!str_starts_with($normalized, $root . '/')) {
            throw new RuntimeException('Orphan image path escaped storage root.');
        }

        return $real;
    }

    private function normalizeRelative(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return '';
        }

        $parts = explode('/', $path);
        if (in_array('..', $parts, true) || in_array('', $parts, true)) {
            return '';
        }

        return implode('/', $parts);
    }

    private function normalized(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
