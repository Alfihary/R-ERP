<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

interface InventoryMutationRepositoryInterface
{
    public function createDraftMovement(
        int $companyId,
        int $warehouseId,
        int $conceptId,
        string $movementDate,
        ?string $reference,
        ?string $notes,
        int $actorId,
        ?int $folioId = null,
        ?string $folio = null
    ): int;

    public function insertMovementDetail(
        int $movementId,
        string $productId,
        string $quantity,
        ?string $notes,
        int $actorId
    ): int;

    public function saveSeriesStock(
        int $seriesId,
        ?int $warehouseId,
        string $state
    ): void;

    public function insertMovementDetailSeries(
        int $movementDetailId,
        int $seriesId
    ): void;

    public function ensureExistenceRow(int $warehouseId, string $productId): void;

    public function increaseExistence(
        int $warehouseId,
        string $productId,
        string $quantity
    ): void;

    public function decreaseExistence(
        int $warehouseId,
        string $productId,
        string $quantity
    ): void;

    public function markMovementApplied(int $movementId, int $actorId): void;
}
