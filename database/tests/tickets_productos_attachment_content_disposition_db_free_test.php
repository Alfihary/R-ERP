<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Http/Controllers/ProductRequestTicketController.php';

$method = new ReflectionMethod(App\Http\Controllers\ProductRequestTicketController::class, 'contentDisposition');
$method->setAccessible(true);

$cases = [
    'archivo.pdf',
    'reporte mensual.pdf',
    'cotización_área.pdf',
    'nombre "con comillas".pdf',
    "nombre\rcon-cr.pdf",
    "nombre\ncon-lf.pdf",
    "nombre\x01control.pdf",
    'archivo.pdf; filename="evil.txt"',
    '',
    '报告_данные_日本語.png',
    str_repeat('largo-', 80) . '.pdf',
    'archivo.pdf',
    'imagen.jpg',
    'imagen.png',
    'imagen.webp',
];

foreach ($cases as $input) {
    $header = $method->invoke(null, $input);

    if (!str_starts_with($header, 'attachment; filename="')
        || !str_contains($header, '"; filename*=UTF-8\'\'')) {
        throw new RuntimeException('Content-Disposition syntax is invalid.');
    }

    if (str_contains($header, "\r") || str_contains($header, "\n")) {
        throw new RuntimeException('CR/LF was not removed from Content-Disposition.');
    }

    $segments = explode('; ', $header);
    if (count($segments) !== 3
        || preg_match('/^filename="[^"]*"$/', $segments[1]) !== 1
        || preg_match('/^filename\*=UTF-8\'\'[A-Za-z0-9%._~\-]++$/', $segments[2]) !== 1) {
        throw new RuntimeException('Content-Disposition contains an unsafe filename parameter.');
    }
}

$empty = $method->invoke(null, '');
if (!str_contains($empty, 'filename="adjunto"')) {
    throw new RuntimeException('Empty names must use the deterministic fallback.');
}

echo "PASS attachment_content_disposition_db_free\n";
