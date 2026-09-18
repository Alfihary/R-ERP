<?php

declare(strict_types=1);

namespace App\Domain\Mail;

interface MailTransport
{
    public function requiresSecret(): bool;

    /**
     * @param array{to:list<string>,cc:list<string>,bcc:list<string>,subject:string,html:string,text:string} $message
     * @param array<string, mixed> $account
     * @return array{sent:bool,error_type:string|null}
     */
    public function send(array $message, array $account): array;
}
