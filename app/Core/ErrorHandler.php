<?php

declare(strict_types=1);

namespace App\Core;

final class ErrorHandler
{
    public function __construct(private readonly bool $debug)
    {
    }

    public function render(\Throwable $exception): Response
    {
        $details = $this->debug ? (string) $exception : null;

        try {
            return Response::html(View::render('errors/500', [
                'details' => $details,
            ]), 500);
        } catch (\Throwable) {
            return Response::html('<h1>Error interno del servidor</h1>', 500);
        }
    }
}
