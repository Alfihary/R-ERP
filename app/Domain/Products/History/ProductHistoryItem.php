<?php

declare(strict_types=1);

namespace App\Domain\Products\History;

/**
 * Final domain read model. Mapping from audit metadata is performed in 2B.
 */
final readonly class ProductHistoryItem
{
    /**
     * @param list<string>|null $changedFields
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public int $auditId,
        public string $occurredAt,
        public string $action,
        public string $category,
        public ?string $subresource,
        public string $result,
        public ?int $actorId,
        public string $actorDisplay,
        public string $productId,
        public string $entity,
        public ?string $entityId,
        public string $summary,
        public ?array $changedFields,
        public ?array $before,
        public ?array $after,
        public ?array $metadata,
        public string $completeness,
    ) {
    }
}
