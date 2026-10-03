<?php

declare(strict_types=1);

namespace App\Domain\Mail;

final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $htmlBody,
        public string $textBody
    ) {
        foreach ([
            'subject' => $subject,
            'html_body' => $htmlBody,
            'text_body' => $textBody,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new ProductTicketEmailTemplateValidationException($field . ' is required.');
            }
            if (preg_match('//u', $value) !== 1) {
                throw new ProductTicketEmailTemplateValidationException($field . ' must be valid UTF-8.');
            }
        }

        if (preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $subject) === 1) {
            throw new ProductTicketEmailTemplateValidationException('subject contains invalid control characters.');
        }
    }
}
