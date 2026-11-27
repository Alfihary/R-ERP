<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

interface ResponsibleRecipientRepositoryInterface
{
    /** @return list<string> */
    public function findEmailsByScopeAndPermission(
        int $empresaId,
        int $almacenId,
        int $actorUserId
    ): array;
}
