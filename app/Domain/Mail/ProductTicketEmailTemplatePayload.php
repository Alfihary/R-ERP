<?php

declare(strict_types=1);

namespace App\Domain\Mail;

final readonly class ProductTicketEmailTemplatePayload
{
    /**
     * @param array<string, mixed> $ticket
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public string $event,
        public array $ticket,
        public string $displayName,
        public array $items,
        public int $attachmentsCount,
        public int $totalItems,
        public ?string $note,
        public string $ctaUrl,
        public string $timezone,
        public string $systemName = 'R-ERP',
        public string $brandLabel = 'ERP REFRIGERACIÓN'
    ) {
    }
}
