<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryValidationException;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContext;
use App\Domain\Scope\ScopeContextService;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Support\Security\CsrfTokenService;

final class InventoryController
{
    private const ALLOWED_CONCEPTS = ['ENTRADA_AJUSTE', 'SALIDA_AJUSTE'];

    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly InventoryService $inventory,
        private readonly InventoryQueryRepository $queries
    ) {
    }

    public function index(Request $request): Response
    {
        $context = $this->context();
        $filters = $this->filters($request->query());
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
            $warehouse = $context->activeWarehouse();
            $result = $this->queries->movements($filters + [
                'company_id' => (int) ($company['id'] ?? 0),
                'warehouse_id' => (int) ($warehouse['id'] ?? 0),
            ]);
        }

        return $this->render('inventory/movements/index', [
            'filters' => $filters,
            'movements' => $result['rows'],
            'pagination' => $result['pagination'],
            'notice' => $this->resultMessage($request),
            'hasActiveContext' => $context->hasActiveContext(),
        ], 'Movimientos de inventario');
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
                ['contexto' => 'Selecciona un contexto activo antes de crear movimientos.'],
                422
            );
        }

        $concept = strtoupper($this->text($request->body(), 'concepto_codigo'));

        if (!in_array($concept, self::ALLOWED_CONCEPTS, true)) {
            return $this->renderForm(
                $request->body(),
                ['concepto_codigo' => 'Selecciona un tipo de ajuste permitido.'],
                422
            );
        }

        $company = $context->activeCompany();
        $warehouse = $context->activeWarehouse();
        $user = $this->user();

        try {
            $result = $this->inventory->aplicarMovimiento([
                'empresa_id' => (int) ($company['id'] ?? 0),
                'almacen_id' => (int) ($warehouse['id'] ?? 0),
                'concepto_codigo' => $concept,
                'fecha_movimiento' => $this->datetime($request->body()),
                'referencia' => $this->text($request->body(), 'referencia'),
                'observaciones' => $this->text($request->body(), 'observaciones'),
                'partidas' => $this->parts($request->body()),
                'usuario_id' => $user['user_id'],
            ]);
        } catch (InventoryValidationException $exception) {
            return $this->renderForm(
                $request->body(),
                $this->domainErrors($exception->errors()),
                422
            );
        }

        return Response::redirect(
            '/inventario/movimientos/ver?id='
            . rawurlencode((string) $result['movimiento_id'])
            . '&result=created'
        );
    }

    public function detail(Request $request): Response
    {
        $context = $this->context();
        $id = filter_var(
            $request->query()['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($id === false || !$context->hasActiveContext()) {
            return Response::html(View::render('errors/404'), 404);
        }

        $company = $context->activeCompany();
        $warehouse = $context->activeWarehouse();
        $movement = $this->queries->movement(
            $id,
            (int) ($company['id'] ?? 0),
            (int) ($warehouse['id'] ?? 0)
        );

        if ($movement === null) {
            return Response::html(View::render('errors/404'), 404);
        }

        return $this->render('inventory/movements/detail', [
            'movement' => $movement,
            'notice' => $this->resultMessage($request),
        ], 'Detalle de movimiento');
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
        return $this->render('inventory/movements/form', [
            'errors' => $errors,
            'values' => $this->formValues($values),
            'hasActiveContext' => $this->context()->hasActiveContext(),
        ], 'Nuevo ajuste de inventario', $status);
    }

    /**
     * @param array<string, mixed> $contentData
     */
    private function render(
        string $contentView,
        array $contentData,
        string $pageTitle,
        int $status = 200
    ): Response {
        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'inventory',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'canAccessCatalogs' => $this->permissions->allows($user['user_id'], 'catalogos.acceder'),
            'canAccessProducts' => $this->permissions->allows($user['user_id'], 'productos.acceder'),
            'canAccessInventory' => true,
            'contentData' => $contentData,
            'contentView' => $contentView,
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => $pageTitle,
            'scripts' => ['/js/modules/inventory-movements.js'],
            'stylesheets' => ['/css/modules/inventory-movements.css'],
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
            throw new \RuntimeException('Authenticated inventory controller requires a user.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{search: string, concept: string, status: string, date_from: string, date_to: string, page: int, per_page: int}
     */
    private function filters(array $query): array
    {
        $concept = in_array($query['concept'] ?? '', self::ALLOWED_CONCEPTS, true)
            ? (string) $query['concept']
            : '';
        $status = in_array($query['status'] ?? '', ['BORRADOR', 'APLICADO', 'ANULADO'], true)
            ? (string) $query['status']
            : '';

        return [
            'search' => $this->limitedText($query, 'search', 80),
            'concept' => $concept,
            'status' => $status,
            'date_from' => $this->date($query, 'date_from'),
            'date_to' => $this->date($query, 'date_to'),
            'page' => $this->positiveInt($query['page'] ?? null, 1),
            'per_page' => 15,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{id_producto: string, cantidad: string, observaciones: string}>
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
            $parts = [['id_producto' => '', 'producto_label' => '', 'cantidad' => '', 'observaciones' => '']];
        }

        return [
            'concepto_codigo' => $this->text($input, 'concepto_codigo'),
            'fecha_movimiento' => $this->text($input, 'fecha_movimiento'),
            'referencia' => $this->text($input, 'referencia'),
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
            'concepto_codigo' => 'ENTRADA_AJUSTE',
            'fecha_movimiento' => date('Y-m-d\TH:i'),
            'referencia' => '',
            'observaciones' => '',
            'partidas' => [
                ['id_producto' => '', 'producto_label' => '', 'cantidad' => '', 'observaciones' => ''],
            ],
        ];
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
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    private function domainErrors(array $errors): array
    {
        $safe = [];

        foreach ($errors as $field => $message) {
            $safe[$field] = match ($field) {
                'stock' => 'Saldo insuficiente para aplicar la salida.',
                'producto', 'partidas' => $message,
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
            'created' => 'Movimiento aplicado correctamente.',
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
