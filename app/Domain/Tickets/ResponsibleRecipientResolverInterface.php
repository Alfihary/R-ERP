<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

interface ResponsibleRecipientResolverInterface
{
    /** @return list<string> */
    public function emailsForTicketContext(
        int $empresaId,
        int $almacenId,
        int $actorUserId
    ): array;
}
