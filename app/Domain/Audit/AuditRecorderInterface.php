<?php

declare(strict_types=1);

namespace App\Domain\Audit;

interface AuditRecorderInterface
{
    /** @param array<string,mixed> $metadata */
    public function recordRequired(string $action, ?int $actorUserId, array $metadata = []): void;
}
