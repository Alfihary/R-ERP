<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // Use the production URL from an active n8n Webhook node. Keep the URL server-side.
    'webhook_url' => trim((string) Env::get('VCARD_N8N_WEBHOOK_URL', '')),
    'webhook_secret' => trim((string) Env::get('VCARD_N8N_WEBHOOK_SECRET', '')),
];
