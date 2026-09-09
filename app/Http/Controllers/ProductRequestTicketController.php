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
        $values = $this->createValues($request->query());

        return $this->render('tickets/productos/create', [
            'errors' => [],
            'values' => $values,
            'catalogs' => $this->createCatalogs($values),
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
            return $this->validationResponse($exception, $request);
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
    public function comment(Request $request, array $params): Response
    {
        try {
            $ticketId = $this->id($params['id'] ?? null);
            $this->tickets->agregarComentario(
                $ticketId,
                $this->optionalId($request->input('partida_id')),
                (string) $request->input('comentario', ''),
                $this->userId()
            );
        } catch (ProductRequestTicketValidationException $exception) {
            return $this->actionValidationResponse($exception, $params);
        }

        return Response::redirect('/tickets/productos/' . $ticketId);
    }

    /**
     * @param array<string, string> $params
     */
    public function attachment(Request $request, array $params): Response
    {
        try {
            $ticketId = $this->id($params['id'] ?? null);
            $this->tickets->agregarAdjunto(
                $ticketId,
                $this->optionalId($request->input('partida_id')),
                $this->uploadedFile($request, 'adjunto'),
                $this->userId()
            );
        } catch (ProductRequestTicketValidationException $exception) {
            return $this->actionValidationResponse($exception, $params);
        }

        return Response::redirect('/tickets/productos/' . $ticketId);
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

        if (is_array($partidas)) {
            $partidas = $this->normalizeCatalogPartidas($partidas);
        }

        $this->assertCreateScope($request->input('empresa_id'), $request->input('almacen_id'));

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

    private function optionalId(mixed $value): ?int
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            return null;
        }

        return $this->id($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadedFile(Request $request, string $field): array
    {
        $file = $request->file($field);

        if (!is_array($file)) {
            throw new ProductRequestTicketValidationException([
                $field => 'Selecciona un archivo adjunto válido.',
            ]);
        }

        return $file;
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

    private function validationResponse(
        ProductRequestTicketValidationException $exception,
        ?Request $request = null
    ): Response
    {
        $values = $this->createValues($request?->body() ?? []);

        return $this->render('tickets/productos/create', [
            'errors' => $exception->errors(),
            'values' => $values,
            'catalogs' => $this->createCatalogs($values),
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
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function createValues(array $source): array
    {
        $values = $source;
        $repository = $this->repository();
        $userId = $this->currentUserIdOrNull();

        if ($repository === null || $userId === null) {
            return $values;
        }

        $companies = $repository->availableCompaniesForUser($userId);
        $companyId = $this->selectedCompanyId($source['empresa_id'] ?? null, $companies);
        $warehouses = $repository->availableWarehousesForUser($userId, $companyId);
        $warehouseId = $this->selectedWarehouseId($source['almacen_id'] ?? null, $warehouses);

        if ($companyId !== null) {
            $values['empresa_id'] = (string) $companyId;
        }

        if ($warehouseId !== null) {
            $values['almacen_id'] = (string) $warehouseId;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, list<array<string, mixed>>>
     */
    private function createCatalogs(array $values): array
    {
        $repository = $this->repository();
        $userId = $this->currentUserIdOrNull();

        if ($repository === null || $userId === null) {
            return [
                'companies' => [],
                'warehouses' => [],
                'brands' => [],
                'currencies' => [],
                'sat_units' => [],
                'sat_keys' => [],
            ];
        }

        $companyId = $this->positiveIntegerOrNull($values['empresa_id'] ?? null);

        return [
            'companies' => $repository->availableCompaniesForUser($userId),
            'warehouses' => $repository->availableWarehousesForUser($userId, $companyId),
            'all_warehouses' => $repository->availableWarehousesForUser($userId),
            'brands' => $repository->activeBrands(),
            'currencies' => $repository->activeCurrencies(),
            'sat_units' => $repository->activeSatUnits(),
            'sat_keys' => $repository->activeSatKeys(),
        ];
    }

    /**
     * @param list<mixed>|array<string, mixed> $partidas
     * @return list<mixed>|array<string, mixed>
     */
    private function normalizeCatalogPartidas(array $partidas): array
    {
        $repository = $this->repository();
        $errors = [];

        if ($repository === null) {
            return $partidas;
        }

        foreach ($partidas as $index => $partida) {
            if (!is_array($partida)) {
                continue;
            }

            $brandId = $this->positiveIntegerOrNull($partida['marca_id'] ?? null);

            if ($brandId !== null) {
                $brand = $repository->brandById($brandId);
                $partida['marca_texto'] = $brand['nombre'] ?? null;
            }

            $unitSatId = $this->positiveIntegerOrNull($partida['unidad_sat_id'] ?? null);
            $satKeyId = $this->positiveIntegerOrNull($partida['clave_sat_id'] ?? null);
            $unitSatText = $this->catalogText($partida['unidad_sat_busqueda'] ?? null);
            $satKeyText = $this->catalogText($partida['clave_sat_busqueda'] ?? null);

            if ($unitSatText !== '') {
                $resolved = $repository->resolveActiveSatUnit($unitSatText);

                if ($resolved['status'] === 'found' && $resolved['id'] !== null) {
                    $partida['unidad_sat_id'] = (string) $resolved['id'];
                } elseif ($resolved['status'] === 'ambiguous') {
                    $errors['partidas.' . $index . '.unidad_sat_busqueda'] =
                        'La unidad SAT es ambigua; escribe una clave más específica.';
                } else {
                    $errors['partidas.' . $index . '.unidad_sat_busqueda'] =
                        'Selecciona una unidad SAT válida del catálogo.';
                }
            } elseif ($unitSatId !== null && $repository->activeSatUnitById($unitSatId) === null) {
                $errors['partidas.' . $index . '.unidad_sat_busqueda'] =
                    'Selecciona una unidad SAT válida del catálogo.';
            }

            if ($satKeyText !== '') {
                $resolved = $repository->resolveActiveSatKey($satKeyText);

                if ($resolved['status'] === 'found' && $resolved['id'] !== null) {
                    $partida['clave_sat_id'] = (string) $resolved['id'];
                } elseif ($resolved['status'] === 'ambiguous') {
                    $errors['partidas.' . $index . '.clave_sat_busqueda'] =
                        'La clave SAT es ambigua; escribe una clave más específica.';
                } else {
                    $errors['partidas.' . $index . '.clave_sat_busqueda'] =
                        'Selecciona una clave SAT válida del catálogo.';
                }
            } elseif ($satKeyId !== null && $repository->activeSatKeyById($satKeyId) === null) {
                $errors['partidas.' . $index . '.clave_sat_busqueda'] =
                    'Selecciona una clave SAT válida del catálogo.';
            }

            $partidas[$index] = $partida;
        }

        if ($errors !== []) {
            throw new ProductRequestTicketValidationException($errors);
        }

        return $partidas;
    }

    private function assertCreateScope(mixed $companyValue, mixed $warehouseValue): void
    {
        $repository = $this->repository();
        $userId = $this->currentUserIdOrNull();
        $companyId = $this->positiveIntegerOrNull($companyValue);
        $warehouseId = $this->positiveIntegerOrNull($warehouseValue);

        if ($repository === null || $userId === null || $companyId === null || $warehouseId === null) {
            return;
        }

        $allowedCompanyIds = array_map(
            static fn (array $company): int => (int) $company['id'],
            $repository->availableCompaniesForUser($userId)
        );

        if (!in_array($companyId, $allowedCompanyIds, true)) {
            throw new ProductRequestTicketValidationException([
                'empresa_id' => 'La empresa no está disponible para tu usuario.',
            ]);
        }

        $allowedWarehouseIds = array_map(
            static fn (array $warehouse): int => (int) $warehouse['id'],
            $repository->availableWarehousesForUser($userId, $companyId)
        );

        if (!in_array($warehouseId, $allowedWarehouseIds, true)) {
            throw new ProductRequestTicketValidationException([
                'almacen_id' => 'El almacén no pertenece a la empresa seleccionada o no está disponible para tu usuario.',
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $companies
     */
    private function selectedCompanyId(mixed $value, array $companies): ?int
    {
        $selected = $this->positiveIntegerOrNull($value);
        $allowed = [];

        foreach ($companies as $company) {
            $allowed[(int) $company['id']] = true;
        }

        if ($selected !== null && isset($allowed[$selected])) {
            return $selected;
        }

        $sessionCompany = $this->positiveIntegerOrNull($_SESSION['active_company_id'] ?? null);

        if ($sessionCompany !== null && isset($allowed[$sessionCompany])) {
            return $sessionCompany;
        }

        return count($companies) === 1 ? (int) $companies[0]['id'] : null;
    }

    /**
     * @param list<array<string, mixed>> $warehouses
     */
    private function selectedWarehouseId(mixed $value, array $warehouses): ?int
    {
        $selected = $this->positiveIntegerOrNull($value);
        $allowed = [];

        foreach ($warehouses as $warehouse) {
            $allowed[(int) $warehouse['id']] = true;
        }

        if ($selected !== null && isset($allowed[$selected])) {
            return $selected;
        }

        $sessionWarehouse = $this->positiveIntegerOrNull($_SESSION['active_warehouse_id'] ?? null);

        if ($sessionWarehouse !== null && isset($allowed[$sessionWarehouse])) {
            return $sessionWarehouse;
        }

        return count($warehouses) === 1 ? (int) $warehouses[0]['id'] : null;
    }

    private function currentUserIdOrNull(): ?int
    {
        $user = $this->auth->user();

        return is_array($user ?? null) ? $this->positiveIntegerOrNull($user['user_id'] ?? null) : null;
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

        if (($GLOBALS['permissions'] ?? null) instanceof PermissionService) {
            return $GLOBALS['permissions'];
        }

        if (($GLOBALS['connection'] ?? null) instanceof ConnectionProvider) {
            return new PermissionService(
                new \App\Infrastructure\Repositories\PermissionRepository($GLOBALS['connection'])
            );
        }

        $config = require BASE_PATH . '/bootstrap/database.php';
        $databaseConfig = $config->get('database', []);

        return is_array($databaseConfig)
            ? new PermissionService(
                new \App\Infrastructure\Repositories\PermissionRepository(
                    new ConnectionProvider($databaseConfig)
                )
            )
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

    private function positiveIntegerOrNull(mixed $value): ?int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9]\d*$/', (string) $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function catalogText(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
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
