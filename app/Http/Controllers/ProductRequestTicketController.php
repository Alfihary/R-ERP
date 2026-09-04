<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Domain\Tickets\ProductRequestTicketValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Support\Security\CsrfTokenService;

final class ProductRequestTicketController
{
    private const VISUAL_PERMISSIONS = [
        'canView' => 'tickets_productos.ver',
        'canCreate' => 'tickets_productos.crear',
        'canResolve' => 'tickets_productos.resolver',
        'canCancel' => 'tickets_productos.cancelar',
        'canViewAttachments' => 'tickets_productos.adjuntos.ver',
        'canCreateComments' => 'tickets_productos.comentarios.crear',
        'canResendEmail' => 'tickets_productos.correo.reenviar',
        'canViewEvents' => 'tickets_productos.eventos.ver',
    ];

    public function __construct(
        private readonly AuthService $auth,
        private readonly ProductRequestTicketService $tickets,
        private readonly ?PermissionService $permissions = null,
        private readonly ?ProductRequestTicketRepository $ticketRepository = null
    ) {
    }

    public function index(Request $request): Response
    {
        $listing = $this->listing($request);

        return $this->render('tickets/productos/index', [
            'tickets' => $listing['items'],
            'listing' => $listing,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('tickets/productos/create', [
            'errors' => [],
            'values' => [],
        ]);
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

        return $this->render('tickets/productos/show', [
            'errors' => [],
            'ticket' => $ticket,
        ]);
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
            return $this->actionValidationResponse($exception, $params);
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
            return $this->actionValidationResponse($exception, $params);
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
            return $this->actionValidationResponse($exception, $params);
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
        return $this->render('tickets/productos/create', [
            'errors' => $exception->errors(),
            'values' => [],
        ], 422);
    }

    /**
     * @param array<string, string> $params
     */
    private function actionValidationResponse(
        ProductRequestTicketValidationException $exception,
        array $params
    ): Response {
        try {
            $ticket = $this->tickets->obtenerTicket($this->id($params['id'] ?? null));
        } catch (ProductRequestTicketValidationException) {
            $ticket = null;
        }

        if ($ticket === null) {
            return $this->validationResponse($exception);
        }

        return $this->render('tickets/productos/show', [
            'errors' => $exception->errors(),
            'ticket' => $ticket,
        ], 422);
    }

    private function notFound(): Response
    {
        return $this->html('<h1>Ticket no encontrado</h1>', 404);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $view, array $data, int $status = 200): Response
    {
        $this->ensureViewHelpers();

        return Response::html(View::render($view, [
            'csrf' => $this->csrf(),
            'permissions' => $this->visualPermissions(),
        ] + $data), $status);
    }

    /**
     * @return array<string, bool>
     */
    private function visualPermissions(): array
    {
        $user = $this->auth->user();
        $permissionService = $this->permissionService();
        $permissions = [];

        foreach (self::VISUAL_PERMISSIONS as $key => $code) {
            $permissions[$key] = $user !== null
                && $permissionService !== null
                && $permissionService->allows($user['user_id'], $code);
        }

        return $permissions;
    }

    private function permissionService(): ?PermissionService
    {
        if ($this->permissions instanceof PermissionService) {
            return $this->permissions;
        }

        return ($GLOBALS['permissions'] ?? null) instanceof PermissionService
            ? $GLOBALS['permissions']
            : null;
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array{page: int, perPage: int, total: int, totalPages: int},
     *     filters: array<string, mixed>
     * }
     */
    private function listing(Request $request): array
    {
        $repository = $this->repository();

        if ($repository === null) {
            return [
                'items' => [],
                'pagination' => [
                    'page' => 1,
                    'perPage' => 20,
                    'total' => 0,
                    'totalPages' => 1,
                ],
                'filters' => [
                    'folio' => '',
                    'estado' => '',
                    'empresa_id' => null,
                    'almacen_id' => null,
                    'fecha_desde' => '',
                    'fecha_hasta' => '',
                ],
            ];
        }

        $query = $request->query();

        return $repository->listar(
            [
                'folio' => $query['folio'] ?? '',
                'estado' => $query['estado'] ?? '',
                'empresa_id' => $query['empresa_id'] ?? null,
                'almacen_id' => $query['almacen_id'] ?? null,
                'fecha_desde' => $query['fecha_desde'] ?? '',
                'fecha_hasta' => $query['fecha_hasta'] ?? '',
            ],
            $this->positiveInteger($query['page'] ?? null, 1),
            $this->perPage($query['per_page'] ?? null)
        );
    }

    private function repository(): ?ProductRequestTicketRepository
    {
        if ($this->ticketRepository instanceof ProductRequestTicketRepository) {
            return $this->ticketRepository;
        }

        if (($GLOBALS['connection'] ?? null) instanceof ConnectionProvider) {
            return new ProductRequestTicketRepository($GLOBALS['connection']);
        }

        $config = require BASE_PATH . '/bootstrap/database.php';
        $databaseConfig = $config->get('database', []);

        return is_array($databaseConfig)
            ? new ProductRequestTicketRepository(new ConnectionProvider($databaseConfig))
            : null;
    }

    private function positiveInteger(mixed $value, int $default): int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9]\d*$/', (string) $value) !== 1) {
            return $default;
        }

        return (int) $value;
    }

    private function perPage(mixed $value): int
    {
        $perPage = $this->positiveInteger($value, 20);

        return in_array($perPage, [10, 20, 50], true) ? $perPage : 20;
    }

    private function ensureViewHelpers(): void
    {
        if (function_exists('csrf_field')) {
            return;
        }

        require_once BASE_PATH . '/app/Support/Security/helpers.php';
    }

    private function csrf(): CsrfTokenService
    {
        return new CsrfTokenService(new Session([]));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
