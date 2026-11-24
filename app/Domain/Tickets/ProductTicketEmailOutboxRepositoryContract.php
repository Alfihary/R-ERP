<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

interface ProductTicketEmailOutboxRepositoryContract
{
    public function findTicketContext(int $ticketId): ?array;
    public function currentDatabaseName(): string;
    /** @return array{lines:int,attachments:int} */
    public function qaFixtureRelationCounts(int $ticketId): array;
    public function findLineContext(int $ticketId, int $partidaId): ?array;
    /** @return list<array<string, mixed>> */
    public function findLinesContext(int $ticketId): array;
    public function countAttachments(int $ticketId): int;
    public function findByDedupeKey(string $dedupeKey): ?array;
    /** @param array<string, mixed> $data */
    public function insertPending(array $data): array;
    public function markSent(int $id, ?int $expectedAttempts = null, ?string $expectedLastAttemptAt = null): array;
    public function markError(int $id, string $safeMessage, ?int $expectedAttempts = null, ?string $expectedLastAttemptAt = null): array;
}
