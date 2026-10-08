<?php

declare(strict_types=1);

namespace App\Core;

final class ErrorHandler
{
    public function __construct(
        private readonly bool $debug
    ) {
    }

    public function render(\Throwable $exception): Response
    {
        $incidentId = $this->incidentId();

        /*
         * El detalle siempre se registra del lado servidor.
         * Nunca dependemos de mostrarlo al usuario para diagnosticar.
         */
        $this->logException($incidentId, $exception);

        /*
         * Solo desarrollo puede recibir detalle técnico.
         * En producción $debug debe ser false.
         */
        $details = $this->debug
            ? (string) $exception
            : null;

        try {
            return Response::html(
                View::render('errors/500', [
                    'details' => $details,
                    'incidentId' => $incidentId,
                ]),
                500
            );
        } catch (\Throwable $renderException) {
            $this->logException(
                $incidentId . '-VIEW',
                $renderException
            );

            return Response::html(
                $this->fallbackHtml($incidentId),
                500
            );
        }
    }

    private function incidentId(): string
    {
        try {
            $random = strtoupper(
                bin2hex(random_bytes(3))
            );
        } catch (\Throwable) {
            $random = strtoupper(
                substr(
                    hash(
                        'sha256',
                        uniqid('', true)
                    ),
                    0,
                    6
                )
            );
        }

        return sprintf(
            'ERR-%s-%s',
            date('Ymd-His'),
            $random
        );
    }

    private function logException(
        string $incidentId,
        \Throwable $exception
    ): void {
        error_log(
            sprintf(
                "[%s] %s\n%s",
                $incidentId,
                $exception->getMessage(),
                (string) $exception
            )
        );
    }

    private function fallbackHtml(string $incidentId): string
    {
        $safeId = htmlspecialchars(
            $incidentId,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        return <<<HTML
        <!doctype html>
        <html lang="es">
        <head>
            <meta charset="utf-8">
            <meta
                name="viewport"
                content="width=device-width, initial-scale=1"
            >
            <title>Error interno</title>
        </head>
        <body>
            <main>
                <h1>Error interno del servidor</h1>
                <p>No fue posible completar la solicitud.</p>
                <p>Folio del incidente: <strong>{$safeId}</strong></p>
            </main>
        </body>
        </html>
        HTML;
    }
}