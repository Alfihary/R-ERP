<?php

declare(strict_types=1);

namespace App\Domain\Products\Import;

interface ProductImportPreviewStore
{
    /** @param array<string, mixed>|null $upload */
    public function storeUpload(?array $upload): ProductImportStoredUpload;

    /** @param array<string, mixed> $artifact */
    public function writeArtifact(array $artifact): void;

    /** @return array<string, mixed> */
    public function readArtifact(string $previewId): array;

    /** @return array<string, mixed> */
    public function readMetadata(string $previewId): array;

    /** @param array<string, mixed> $artifact */
    public function sourcePath(array $artifact): string;

    /** @param array<string, mixed> $artifact */
    public function replaceConfirmation(array $artifact): void;

    /** @return array<string, mixed> */
    public function readConfirmation(string $token): array;

    public function discardConfirmation(string $token): bool;

    public function discard(string $previewId): bool;

    public function discardStoredUpload(ProductImportStoredUpload $upload): void;

    public function cleanupExpired(): int;

    public function now(): int;
}
