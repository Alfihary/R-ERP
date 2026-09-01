<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Domain\Tickets\ProductRequestTicketValidationException;

final class ProductRequestTicketController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ProductRequestTicketService $tickets
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->html(
            '<h1>Tickets de productos</h1>'
            . '<p>Solicitud de alta de productos</p>'
            . '<p><a href="/tickets/productos/crear">Crear solicitud</a></p>'
        );
    }

    public function create(Request $request): Response
    {
        return $this->html(
            '<h1>Crear ticket de productos</h1>'
            . '<p>Formulario mínimo para solicitud documental de alta de productos.</p>'
            . '<form method="post" action="/tickets/productos">'
            . '<label>Empresa <input name="empresa_id"></label>'
            . '<label>Almacén <input name="almacen_id"></label>'
            . '<label>Descripción <textarea name="partidas[0][descripcion]"></textarea></label>'
            . '<button type="submit">Crear ticket documental</button>'
            . '</form>'
        );
    }

    public function store(Request $request): Response
    {
        try {
            $ticket = $this->tickets->crearTicket(
                $this->ticketInput($request),
                $this->userId()
            );
        } catch (ProductRequestTicketValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return Response::redirect('/tickets/productos/' . (int) $ticket['id']);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $ticket = $this->tickets->obtenerTicket($this->id($params['id'] ?? null));

        if ($ticket === null) {
            return $this->notFound();
        }

        $body = '<h1>Ticket ' . self::escape((string) $ticket['folio']) . '</h1>'
            . '<p>Estado: ' . self::escape((string) $ticket['estado']) . '</p>'
            . '<h2>Partidas</h2><ul>';

        foreach ($ticket['partidas'] ?? [] as $partida) {
            if (!is_array($partida)) {
                continue;
            }

            $body .= '<li>'
                . self::escape((string) ($partida['numero_partida'] ?? ''))
                . '. '
                . self::escape((string) ($partida['descripcion'] ?? ''))
                . ' — '
                . self::escape((string) ($partida['estado'] ?? ''));

            if (isset($partida['motivo_rechazo']) && $partida['motivo_rechazo'] !== null) {
                $body .= ' — Motivo: ' . self::escape((string) $partida['motivo_rechazo']);
            }

            $body .= '</li>';
        }

        $body .= '</ul><h2>Eventos</h2><ul>';

        foreach ($ticket['eventos'] ?? [] as $evento) {
            if (!is_array($evento)) {
                continue;
            }

            $body .= '<li>' . self::escape((string) ($evento['tipo_evento'] ?? '')) . '</li>';
        }

        return $this->html($body . '</ul>');
    }

    /**
     * @param array<string, string> $params
     */
    public function approveLine(Request $request, array $params): Response
    {
        try {
            $this->tickets->resolverPartida(
                $this->id($params['id'] ?? null),
                $this->id($params['partidaId'] ?? null),
                'APROBAR',
                ['comentario_resolucion' => $this->nullableText($request, 'comentario_resolucion')],
                $this->userId()
            );
        } catch (ProductRequestTicketValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return Response::redirect('/tickets/productos/' . $this->id($params['id'] ?? null));
    }

    /**
     * @param array<string, string> $params
     */
    public function rejectLine(Request $request, array $params): Response
    {
        try {
            $this->tickets->resolverPartida(
                $this->id($params['id'] ?? null),
                $this->id($params['partidaId'] ?? null),
                'RECHAZAR',
                [
                    'motivo_rechazo' => $this->nullableText($request, 'motivo_rechazo'),
                    'comentario_resolucion' => $this->nullableText($request, 'comentario_resolucion'),
                ],
                $this->userId()
            );
        } catch (ProductRequestTicketValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return Response::redirect('/tickets/productos/' . $this->id($params['id'] ?? null));
    }

    /**
     * @param array<string, string> $params
     */
    public function cancel(Request $request, array $params): Response
    {
        try {
            $this->tickets->cancelarTicket(
                $this->id($params['id'] ?? null),
                (string) $request->input('motivo', ''),
                $this->userId()
            );
        } catch (ProductRequestTicketValidationException $exception) {
            return $this->validationResponse($exception);
        }

        return Response::redirect('/tickets/productos/' . $this->id($params['id'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketInput(Request $request): array
    {
        $body = $request->body();
        $partidas = $body['partidas'] ?? [];

        if (!is_array($partidas) || $partidas === []) {
            $descripcion = trim((string) $request->input('descripcion', ''));
            $partidas = $descripcion === '' ? [] : [[
                'descripcion' => $descripcion,
                'modelo' => $this->nullableText($request, 'modelo'),
                'marca_texto' => $this->nullableText($request, 'marca_texto'),
                'proveedor_texto' => $this->nullableText($request, 'proveedor_texto'),
                'observaciones' => $this->nullableText($request, 'observaciones'),
            ]];
        }

        return [
            'empresa_id' => $request->input('empresa_id'),
            'almacen_id' => $request->input('almacen_id'),
            'observaciones_generales' => $this->nullableText($request, 'observaciones_generales'),
            'partidas' => array_values($partidas),
        ];
    }

    private function nullableText(Request $request, string $field): ?string
    {
        $value = trim((string) $request->input($field, ''));

        return $value === '' ? null : $value;
    }

    private function id(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9]\d*$/', (string) $value) !== 1) {
            throw new ProductRequestTicketValidationException([
                'id' => 'El identificador debe ser entero positivo.',
            ]);
        }

        return (int) $value;
    }

    private function userId(): int
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new \RuntimeException('Authenticated user is required.');
        }

        return $user['user_id'];
    }

    private function html(string $body, int $status = 200): Response
    {
        return Response::html(
            '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Tickets de productos</title></head>'
            . '<body>' . $body . '</body></html>',
            $status
        );
    }

    private function validationResponse(ProductRequestTicketValidationException $exception): Response
    {
        $body = '<h1>Solicitud inválida</h1><ul>';

        foreach ($exception->errors() as $field => $message) {
            $body .= '<li>' . self::escape((string) $field) . ': ' . self::escape((string) $message) . '</li>';
        }

        return $this->html($body . '</ul>', 422);
    }

    private function notFound(): Response
    {
        return $this->html('<h1>Ticket no encontrado</h1>', 404);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
