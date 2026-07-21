<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Infrastructure\Repositories\FolioSeriesRepository;
use App\Support\Security\CsrfTokenService;
use PDOException;

final class FolioSeriesController
{
    public const DOCUMENT_TYPES = [
        'FACTURA_VENTA',
        'REMISION_VENTA',
        'PEDIDO_VENTA',
        'COTIZACION_VENTA',
        'NOTA_CREDITO',
        'COMPROBANTE_PAGO',
        'ORDEN_COMPRA',
        'RECEPCION_COMPRA',
        'DEVOLUCION_COMPRA',
        'TRANSFERENCIA_INVENTARIO',
        'AJUSTE_INVENTARIO',
        'ENTRADA_INVENTARIO',
        'SALIDA_INVENTARIO',
    ];

    public const PREFIX_SUGGESTIONS = [
        'FACTURA_VENTA' => 'F',
        'REMISION_VENTA' => 'R',
        'PEDIDO_VENTA' => 'P',
        'COTIZACION_VENTA' => 'C',
        'NOTA_CREDITO' => 'NC',
        'COMPROBANTE_PAGO' => 'CP',
        'ORDEN_COMPRA' => 'OC',
        'RECEPCION_COMPRA' => 'RC',
        'DEVOLUCION_COMPRA' => 'DV',
        'TRANSFERENCIA_INVENTARIO' => 'TR',
        'AJUSTE_INVENTARIO' => 'AJ',
        'ENTRADA_INVENTARIO' => 'EN',
        'SALIDA_INVENTARIO' => 'SA',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly FolioSeriesRepository $series
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user();

        return $this->render('configuration/folios/index', [
            'abilities' => $this->abilities($user['user_id']),
            'companies' => $this->series->companiesForSelect(),
            'documentTypes' => self::DOCUMENT_TYPES,
            'notice' => $this->resultMessage($request),
            'result' => $this->series->paginate($request->query()),
            'warehouses' => $this->series->warehousesForSelect(),
        ], 'Folios documentales');
    }

    public function createForm(Request $request): Response
    {
        return $this->renderForm([], [], false, false, 200);
    }

    public function create(Request $request): Response
    {
        $user = $this->user();

        try {
            $data = $this->validated($request->body(), null);
            $data['actor_id'] = $user['user_id'];
            $id = $this->series->create($data);
        } catch (ValidationFailure $exception) {
            return $this->renderForm($request->body(), $exception->errors(), false, false, 422);
        } catch (PDOException) {
            return $this->renderForm($request->body(), [
                'general' => 'No fue posible guardar la serie documental.',
            ], false, false, 422);
        }

        return Response::redirect('/configuracion/folios/ver?id=' . $id . '&result=created');
    }

    public function show(Request $request): Response
    {
        $serie = $this->findOr404($this->idFromQuery($request));

        if ($serie instanceof Response) {
            return $serie;
        }

        return $this->render('configuration/folios/show', [
            'abilities' => $this->abilities($this->user()['user_id']),
            'notice' => $this->resultMessage($request),
            'preview' => $this->preview($serie),
            'serie' => $serie,
        ], 'Detalle de serie documental');
    }

    public function editForm(Request $request): Response
    {
        $serie = $this->findOr404($this->idFromQuery($request));

        if ($serie instanceof Response) {
            return $serie;
        }

        return $this->renderForm(
            $serie,
            [],
            true,
            (int) ($serie['folios_emitidos'] ?? 0) > 0,
            200
        );
    }

    public function update(Request $request): Response
    {
        $user = $this->user();
        $id = $this->idFromBody($request);
        $serie = $this->series->findById($id);

        if ($serie === null) {
            return $this->notFound();
        }

        $locked = (int) ($serie['folios_emitidos'] ?? 0) > 0;

        try {
            if ($locked) {
                $data = $serie;
                $data['activo'] = ($request->input('activo') ?? null) === '1' ? 1 : 0;
                $data['actor_id'] = $user['user_id'];
            } else {
                $data = $this->validated($request->body(), $id);
                $data['actor_id'] = $user['user_id'];
            }

            $this->series->update($id, $data);
        } catch (ValidationFailure $exception) {
            return $this->renderForm(
                $request->body() + ['id' => $id],
                $exception->errors(),
                true,
                $locked,
                422
            );
        } catch (PDOException) {
            return $this->renderForm(
                $request->body() + ['id' => $id],
                ['general' => 'No fue posible actualizar la serie documental.'],
                true,
                $locked,
                422
            );
        }

        return Response::redirect('/configuracion/folios/ver?id=' . $id . '&result=updated');
    }

    public function activate(Request $request): Response
    {
        return $this->state($request, true);
    }

    public function deactivate(Request $request): Response
    {
        return $this->state($request, false);
    }

    private function state(Request $request, bool $active): Response
    {
        $id = $this->idFromBody($request);

        if ($this->series->findById($id) === null) {
            return $this->notFound();
        }

        if ($active) {
            $this->series->activate($id, $this->user()['user_id']);
        } else {
            $this->series->deactivate($id, $this->user()['user_id']);
        }

        return Response::redirect(
            '/configuracion/folios?result=' . ($active ? 'activated' : 'deactivated')
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validated(array $input, ?int $excludeId): array
    {
        $errors = [];
        $empresaId = filter_var(
            $input['empresa_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $almacenId = filter_var(
            $input['almacen_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $tipoDocumento = $this->upperSnake((string) ($input['tipo_documento'] ?? ''));
        $codigoSerie = $this->upperCode((string) ($input['codigo_serie'] ?? ''));
        $prefijo = $this->upperCode((string) ($input['prefijo'] ?? ''));
        $formato = trim((string) ($input['formato'] ?? FolioSeriesRepository::FORMAT));
        $separador = trim((string) ($input['separador'] ?? '-'));
        $siguienteNumero = filter_var(
            $input['siguiente_numero'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $longitud = filter_var(
            $input['longitud'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 12]]
        );
        $reinicioAnual = ($input['reinicio_anual'] ?? null) === '1' ? 1 : 0;
        $activo = ($input['activo'] ?? null) === '1' ? 1 : 0;
        $anioActual = null;

        if (($input['anio_actual'] ?? '') !== '') {
            $anio = filter_var(
                $input['anio_actual'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 2000, 'max_range' => 2100]]
            );
            if ($anio === false) {
                $errors['anio_actual'] = 'El año actual debe ser válido.';
            } else {
                $anioActual = $anio;
            }
        }

        if ($empresaId === false || !$this->series->companyExists($empresaId)) {
            $errors['empresa_id'] = 'Selecciona una empresa activa válida.';
            $empresaId = 0;
        }

        $warehouse = null;
        if ($almacenId === false) {
            $errors['almacen_id'] = 'Selecciona un almacén activo válido.';
            $almacenId = 0;
        } elseif ($empresaId > 0) {
            $warehouse = $this->series->warehouseById($almacenId, $empresaId);
            if ($warehouse === null) {
                $errors['almacen_id'] = 'El almacén debe pertenecer a la empresa seleccionada.';
            }
        }

        if (!in_array($tipoDocumento, self::DOCUMENT_TYPES, true)) {
            $errors['tipo_documento'] = 'Selecciona un tipo de documento válido.';
        }

        if (preg_match('/^[A-Z0-9_]{1,20}$/', $codigoSerie) !== 1) {
            $errors['codigo_serie'] = 'Usa código seguro en mayúsculas, números o guion bajo.';
        }

        if (preg_match('/^[A-Z0-9]{1,20}$/', $prefijo) !== 1) {
            $errors['prefijo'] = 'Usa prefijo seguro en mayúsculas y números, sin espacios.';
        }

        if ($formato !== FolioSeriesRepository::FORMAT) {
            $errors['formato'] = 'El formato MVP soportado es {PREFIJO}-{ALMACEN}{NUMERO}.';
        }

        if ($separador !== '-') {
            $errors['separador'] = 'El separador MVP soportado es guion medio.';
        }

        if ($siguienteNumero === false) {
            $errors['siguiente_numero'] = 'El siguiente número debe ser mayor o igual a 1.';
            $siguienteNumero = 1;
        }

        if ($longitud === false) {
            $errors['longitud'] = 'La longitud debe estar entre 1 y 12.';
            $longitud = 6;
        }

        if ($empresaId > 0
            && $almacenId > 0
            && $tipoDocumento !== ''
            && $codigoSerie !== ''
            && $this->series->existsScope($empresaId, $almacenId, $tipoDocumento, $codigoSerie, $excludeId)
        ) {
            $errors['codigo_serie'] = 'Ya existe una serie para empresa, almacén, tipo y código.';
        }

        if ($errors !== []) {
            throw new ValidationFailure($errors);
        }

        return [
            'empresa_id' => $empresaId,
            'almacen_id' => $almacenId,
            'tipo_documento' => $tipoDocumento,
            'codigo_serie' => $codigoSerie,
            'prefijo' => $prefijo,
            'codigo_almacen_snapshot' => (string) ($warehouse['codigo'] ?? ''),
            'formato' => FolioSeriesRepository::FORMAT,
            'separador' => '-',
            'siguiente_numero' => $siguienteNumero,
            'longitud' => $longitud,
            'reinicio_anual' => $reinicioAnual,
            'anio_actual' => $reinicioAnual === 1 ? $anioActual : null,
            'activo' => $activo,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderForm(
        array $values,
        array $errors,
        bool $editing,
        bool $locked,
        int $status
    ): Response {
        return $this->render('configuration/folios/form', [
            'companies' => $this->series->companiesForSelect(),
            'documentTypes' => self::DOCUMENT_TYPES,
            'editing' => $editing,
            'errors' => $errors,
            'locked' => $locked,
            'prefixSuggestions' => self::PREFIX_SUGGESTIONS,
            'preview' => $this->preview($values),
            'values' => $values,
            'warehouses' => $this->series->warehousesForSelect(),
        ], $editing ? 'Editar serie documental' : 'Crear serie documental', $status);
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
            'activeNavigation' => 'configuration-folios',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'canAccessConfiguration' => true,
            'canAccessConfigCompanies' => $this->permissions->allows(
                $user['user_id'],
                'configuracion.empresas.acceder'
            ),
            'canAccessConfigWarehouses' => $this->permissions->allows(
                $user['user_id'],
                'configuracion.almacenes.acceder'
            ),
            'canAccessConfigFolios' => true,
            'contentData' => $contentData,
            'contentView' => $contentView,
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => $pageTitle,
            'stylesheets' => ['/css/modules/config-folios.css'],
            'user' => $user,
        ]), $status);
    }

    /**
     * @return array{user_id: int, username: string, email: string}
     */
    private function user(): array
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new \RuntimeException('Authenticated folio controller requires a user.');
        }

        return $user;
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(int $userId): array
    {
        return [
            'ver' => $this->permissions->allows($userId, 'configuracion.folios.ver'),
            'crear' => $this->permissions->allows($userId, 'configuracion.folios.crear'),
            'editar' => $this->permissions->allows($userId, 'configuracion.folios.editar'),
            'desactivar' => $this->permissions->allows($userId, 'configuracion.folios.desactivar'),
        ];
    }

    private function idFromQuery(Request $request): int
    {
        $id = filter_var(
            $request->query()['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $id === false ? 0 : $id;
    }

    private function idFromBody(Request $request): int
    {
        $id = filter_var(
            $request->input('id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $id === false ? 0 : $id;
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function findOr404(int $id): array|Response
    {
        $serie = $this->series->findById($id);

        return $serie ?? $this->notFound();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function preview(array $values): string
    {
        $prefijo = (string) ($values['prefijo'] ?? 'F');
        $snapshot = (string) ($values['codigo_almacen_snapshot'] ?? 'BO');
        $number = max(1, (int) ($values['siguiente_numero'] ?? 1));
        $length = max(1, min(12, (int) ($values['longitud'] ?? 6)));

        return $this->series->preview($prefijo, $snapshot, $number, $length);
    }

    private function upperSnake(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function upperCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function resultMessage(Request $request): ?string
    {
        return match ($request->query()['result'] ?? null) {
            'created' => 'Serie documental creada correctamente.',
            'updated' => 'Serie documental actualizada correctamente.',
            'activated' => 'Serie documental activada correctamente.',
            'deactivated' => 'Serie documental desactivada correctamente.',
            default => null,
        };
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404'), 404);
    }
}

final class ValidationFailure extends \RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Validation failed.');
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
