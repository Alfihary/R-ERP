<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

final class ResponsibleRecipientResolver implements ResponsibleRecipientResolverInterface
{
    public function __construct(
        private readonly ResponsibleRecipientRepositoryInterface $repository
    ) {
    }

    public function emailsForTicketContext(
        int $empresaId,
        int $almacenId,
        int $actorUserId
    ): array {
        $emails = [];

        foreach ($this->repository->findEmailsByScopeAndPermission($empresaId, $almacenId, $actorUserId) as $email) {
            $normalized = strtolower(trim($email));
            if ($normalized !== '' && filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[$normalized] = $normalized;
            }
        }

        ksort($emails);

        return array_values($emails);
    }
}
