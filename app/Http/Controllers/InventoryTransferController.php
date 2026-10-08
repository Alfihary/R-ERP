<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Inventory\InventoryTransferService;
use App\Domain\Inventory\InventoryIdempotencyConflictException;
use App\Domain\Inventory\InventoryValidationException;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContext;
use App\Domain\Scope\ScopeContextService;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Support\Security\CsrfTokenService;

final class InventoryTransferController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly InventoryTransferService $transfers,
        private readonly InventoryQueryRepository $queries
    ) {
    }

    public function index(Request $request): Response
    {
        $context = $this->context();
        $filters = $this->filters($request->query());
        $warehouses = [];
        $errors = [];
        $result = [
            'rows' => [],
            'pagination' => [
                'page' => $filters['page'],
                'per_page' => $filters['per_page'],
                'total' => 0,
                'total_pages' => 1,
            ],
        ];

        if ($context->hasActiveContext()) {
            $company = $context->activeCompany();
            $companyId = (int) ($company['id'] ?? 0);
            $warehouses = $this->queries->warehousesForCompany($companyId);
            $allowedWarehouseIds = array_map(
                static fn (array $row): int => (int) $row['id'],
                $warehouses
            );

            if (
                $filters['warehouse_id'] !== null
                && !in_array($filters['warehouse_id'], $allowedWarehouseIds, true)
            ) {
                $errors['warehouse_id'] = 'Selecciona un almacén válido de la empresa activa.';
            } else {
                $result = $this->queries->transfers($filters + [
                    'company_id' => $companyId,
                ]);
            }
        }

        return $this->render('inventory/transfers/index', [
            'canCreateTransfer' => $this->permissions->allows(
                $this->user()['user_id'],
                'inventario.transferencias.crear'
            ),
            'errors' => $errors,
            'filters' => $filters,
            'hasActiveContext' => $context->hasActiveContext(),
            'notice' => $this->resultMessage($request),
            'pagination' => $result['pagination'],
            'transfers' => $result['rows'],
            'warehouses' => $warehouses,
        ], 'Transferencias de inventario', empty($errors) ? 200 : 422);
    }

    public function createForm(Request $request): Response
    {
        return $this->renderForm($this->defaultValues(), [], 200);
    }

    public function create(Request $request): Response
    {
        $context = $this->context();

        if (!$context->hasActiveContext()) {
            return $this->renderForm(
                $request->body(),
                ['contexto' => 'Selecciona un contexto activo antes de crear transferencias.'],
                422
            );
        }

        $company = $context->activeCompany();
        $user = $this->user();
        $idempotencyKey = $this->idempotencyKey($request->body()['idempotency_key'] ?? null);
        if ($idempotencyKey === null) {
            return $this->renderForm(
                $request->body(),
                ['idempotency_key' => 'La clave de operación no es válida.'],
                422
            );
        }

        try {
            $result = $this->transfers->transferir([
                'empresa_id' => (int) ($company['id'] ?? 0),
                'almacen_origen_id' => $this->positiveInt(
                    $request->body()['almacen_origen_id'] ?? null,
                    0
                ),
                'almacen_destino_id' => $this->positiveInt(
                    $request->body()['almacen_destino_id'] ?? null,
                    0
                ),
                'fecha_movimiento' => $this->datetime($request->body()),
                'referencia' => $this->reference($request->body()),
                'observaciones' => $this->text($request->body(), 'observaciones'),
                'usuario_id' => $user['user_id'],
                'partidas' => $this->parts($request->body()),
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (InventoryIdempotencyConflictException) {
            return $this->renderForm(
                $request->body(),
                ['idempotency_key' => 'La clave ya fue usada con datos distintos.'],
                409
            );
        } catch (InventoryValidationException $exception) {
            return $this->renderForm(
                $request->body(),
                $this->domainErrors($exception->errors()),
                422
            );
        }

        return Response::redirect(
            '/inventario/transferencias/ver?ref='
            . rawurlencode((string) $result['referencia_transferencia'])
            . '&result=created'
        );
    }

    public function detail(Request $request): Response
    {
        $context = $this->context();
        $reference = $this->reference($request->query());

        if ($reference === '' || !$context->hasActiveContext()) {
            return Response::html(View::render('errors/404'), 404);
        }

        $company = $context->activeCompany();
        $transfer = $this->queries->transfer(
            $reference,
            (int) ($company['id'] ?? 0)
        );

        if ($transfer === null) {
            return Response::html(View::render('errors/404'), 404);
        }

        return $this->render('inventory/transfers/detail', [
            'canViewMovement' => $this->permissions->allows(
                $this->user()['user_id'],
                'inventario.movimientos.ver'
            ),
            'notice' => $this->resultMessage($request),
            'transfer' => $transfer,
        ], 'Detalle de transferencia');
    }

    public function searchProducts(Request $request): Response
    {
        $query = trim((string) ($request->query()['q'] ?? ''));

        if ($this->length($query) < 2) {
            return Response::json(['items' => []]);
        }

        $query = $this->length($query) > 40 ? substr($query, 0, 40) : $query;

        return Response::json([
            'items' => array_map(
                static fn (array $row): array => [
                    'id_producto' => (string) $row['id_producto'],
                    'descripcion' => (string) $row['descripcion'],
                    'tipo_codigo' => (string) $row['tipo_codigo'],
                    'controla_series' => (int) ($row['controla_series'] ?? 0) === 1,
                ],
                $this->queries->searchProducts($query)
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderForm(array $values, array $errors, int $status): Response
    {
        $context = $this->context();
        $warehouses = [];

        if ($context->hasActiveContext()) {
            $company = $context->activeCompany();
            $warehouses = $this->queries->warehousesForCompany(
                (int) ($company['id'] ?? 0)
            );
        }

        return $this->render('inventory/transfers/form', [
            'errors' => $errors,
            'hasActiveContext' => $context->hasActiveContext(),
            'values' => $this->formValues($values),
            'warehouses' => $warehouses,
        ], 'Nueva transferencia', $status, [
            'scripts' => ['/js/modules/inventory-transfers.js'],
        ]);
    }

    /**
     * @param array<string, mixed> $contentData
     */
    private function render(
        string $contentView,
        array $contentData,
        string $pageTitle,
        int $status = 200,
        array $assets = []
    ): Response {
        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);
        $stylesheets = $assets['stylesheets'] ?? ['/css/modules/inventory-transfers.css'];
        $scripts = $assets['scripts'] ?? [];

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'inventory-transfers',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'canAccessCatalogs' => $this->permissions->allows($user['user_id'], 'catalogos.acceder'),
            'canAccessProducts' => $this->permissions->allows($user['user_id'], 'productos.acceder'),
            'canAccessInventory' => $this->permissions->allows($user['user_id'], 'inventario.movimientos.acceder'),
            'canAccessInventoryStock' => $this->permissions->allows($user['user_id'], 'inventario.existencias.acceder'),
            'canAccessInventoryKardex' => $this->permissions->allows($user['user_id'], 'inventario.kardex.acceder'),
            'canAccessInventoryTransfers' => $this->permissions->allows($user['user_id'], 'inventario.transferencias.acceder'),
            'contentData' => $contentData,
            'contentView' => $contentView,
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => $pageTitle,
            'scripts' => is_array($scripts) ? $scripts : [],
            'stylesheets' => is_array($stylesheets) ? $stylesheets : [],
            'user' => $user,
        ]), $status);
    }

    private function context(): ScopeContext
    {
        return $this->scopeContext->resolveForUser($this->user()['user_id']);
    }

    /**
     * @return array{user_id: int, username: string, email: string}
     */
    private function user(): array
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new \RuntimeException('Authenticated transfer controller requires a user.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{search: string, warehouse_id: int|null, date_from: string, date_to: string, page: int, per_page: int}
     */
    private function filters(array $query): array
    {
        $warehouse = filter_var(
            $query['warehouse_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return [
            'search' => $this->limitedText($query, 'search', 80),
            'warehouse_id' => $warehouse === false ? null : $warehouse,
            'date_from' => $this->date($query, 'date_from'),
            'date_to' => $this->date($query, 'date_to'),
            'page' => $this->positiveInt($query['page'] ?? null, 1),
            'per_page' => 15,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{id_producto: string, cantidad: string, observaciones: string, series: list<string>}>
     */
    private function parts(array $input): array
    {
        $rawParts = $input['partidas'] ?? [];

        if (!is_array($rawParts)) {
            return [];
        }

        $parts = [];

        foreach ($rawParts as $part) {
            if (!is_array($part)) {
                continue;
            }

            $parts[] = [
                'id_producto' => $this->text($part, 'id_producto'),
                'cantidad' => $this->text($part, 'cantidad'),
                'observaciones' => $this->text($part, 'observaciones'),
                'series' => $this->series($part),
            ];
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function formValues(array $input): array
    {
        $parts = $input['partidas'] ?? [];

        if (!is_array($parts) || $parts === []) {
            $parts = [$this->emptyPart()];
        }
        $parts = $this->formParts($parts);

        return [
            'almacen_origen_id' => $this->text($input, 'almacen_origen_id'),
            'almacen_destino_id' => $this->text($input, 'almacen_destino_id'),
            'fecha_movimiento' => $this->text($input, 'fecha_movimiento'),
            'referencia' => $this->reference($input),
            'observaciones' => $this->text($input, 'observaciones'),
            'partidas' => $parts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultValues(): array
    {
        return [
            'idempotency_key' => bin2hex(random_bytes(16)),
            'almacen_origen_id' => '',
            'almacen_destino_id' => '',
            'fecha_movimiento' => date('Y-m-d\TH:i'),
            'referencia' => 'TRF-' . date('Ymd-His'),
            'observaciones' => '',
            'partidas' => [
                $this->emptyPart(),
            ],
        ];
    }

    private function idempotencyKey(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $value) === 1 ? $value : null;
    }

    /**
     * @return array{id_producto: string, producto_label: string, cantidad: string, observaciones: string, series_text: string, controla_series: string}
     */
    private function emptyPart(): array
    {
        return [
            'id_producto' => '',
            'producto_label' => '',
            'cantidad' => '',
            'observaciones' => '',
            'series_text' => '',
            'controla_series' => '0',
        ];
    }

    /**
     * @param array<int|string, mixed> $parts
     * @return list<array<string, mixed>>
     */
    private function formParts(array $parts): array
    {
        $normalized = [];

        foreach ($parts as $part) {
            $part = is_array($part) ? $part : [];
            $seriesText = $this->text($part, 'series_text');

            if ($seriesText === '' && is_array($part['series'] ?? null)) {
                $seriesText = implode("\n", $this->series($part));
            }

            $normalized[] = [
                'id_producto' => $this->text($part, 'id_producto'),
                'producto_label' => $this->text($part, 'producto_label'),
                'cantidad' => $this->text($part, 'cantidad'),
                'observaciones' => $this->text($part, 'observaciones'),
                'series_text' => $seriesText,
                'controla_series' => $this->text($part, 'controla_series') === '1'
                    ? '1'
                    : '0',
            ];
        }

        return $normalized === [] ? [$this->emptyPart()] : $normalized;
    }

    /**
     * @param array<string, mixed> $part
     * @return list<string>
     */
    private function series(array $part): array
    {
        $raw = $part['series_text'] ?? $part['series'] ?? '';

        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw) || is_int($raw)) {
            $items = preg_split('/\R/', (string) $raw) ?: [];
        } else {
            return [];
        }

        $series = [];

        foreach ($items as $item) {
            if (!is_string($item) && !is_int($item)) {
                continue;
            }

            $number = trim((string) $item);

            if ($number !== '') {
                $series[] = $number;
            }
        }

        return $series;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function datetime(array $input): string
    {
        $raw = $this->text($input, 'fecha_movimiento');

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw) === 1) {
            return str_replace('T', ' ', $raw) . ':00';
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function reference(array $input): string
    {
        $reference = strtoupper($this->text($input, 'ref') ?: $this->text($input, 'referencia'));

        return preg_match('/^TRF-[A-Z0-9-]{1,96}$/', $reference) === 1
            ? $reference
            : '';
    }

    /**
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    private function domainErrors(array $errors): array
    {
        $safe = [];

        foreach ($errors as $field => $message) {
            $safe[$field] = match ($field) {
                'existencia' => 'Saldo insuficiente para aplicar la transferencia.',
                'id_producto', 'partidas' => $message,
                default => $message,
            };
        }

        return $safe;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) || is_int($value)
            ? trim((string) $value)
            : '';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function limitedText(array $input, string $key, int $limit): string
    {
        $value = $this->text($input, $key);

        return $this->length($value) <= $limit ? $value : '';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function date(array $input, string $key): string
    {
        $value = $this->text($input, $key);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if ((is_string($value) || is_int($value)) && preg_match('/^[1-9]\d*$/', (string) $value) === 1) {
            return (int) $value;
        }

        return $default;
    }

    private function resultMessage(Request $request): ?string
    {
        return match ($request->query()['result'] ?? null) {
            'created' => 'Transferencia aplicada correctamente.',
            default => null,
        };
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);
    }
}
