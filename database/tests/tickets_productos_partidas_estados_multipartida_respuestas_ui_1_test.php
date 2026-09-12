<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Domain\Tickets\ProductRequestTicketValidationException;
use App\Http\Controllers\ProductRequestTicketController;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

require_once BASE_PATH . '/app/Support/Security/helpers.php';

return new class implements DatabaseTest {
    private const PASSWORD = 'qa-tp-multipart';

    private PDO $pdo;
    private ProductRequestTicketRepository $repository;
    private ProductRequestTicketService $service;

    /** @var list<string> */
    private array $filesToDelete = [];

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $this->repository = new ProductRequestTicketRepository($GLOBALS['tp_product_ticket_multipart_connection']);
        $this->service = new ProductRequestTicketService($this->repository);

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-MULTIPARTIDA-RESPUESTAS-UI-1.');
        }

        $before = $this->operationalCounts();
        $beforeStorage = $this->storageFiles();
        $results = [];

        try {
            $pdo->beginTransaction();
            $fixture = $this->fixture();
            $createHtml = $this->renderCreate($fixture, [
                'empresa_id' => (string) $fixture['empresa_id'],
                'almacen_id' => (string) $fixture['almacen_id'],
                'partidas' => [
                    ['descripcion' => 'Partida conservada 1', 'modelo' => 'MP-1'],
                    ['descripcion' => 'Partida conservada 2', 'modelo' => 'MP-2'],
                ],
            ]);
            $js = $this->read('public/js/modules/tickets-productos-create.js');

            $ticket = $this->service->crearTicket([
                'empresa_id' => $fixture['empresa_id'],
                'almacen_id' => $fixture['almacen_id'],
                'observaciones_generales' => 'Ticket QA multipartida respuestas UI.',
                'partidas' => $this->validParts($fixture),
                'adjuntos' => [$this->upload('soporte-multipartida.pdf', "%PDF-1.4\n%%EOF\n")],
            ], $fixture['user_id']);
            $ticketId = (int) $ticket['id'];
            $partidas = $this->partidas($ticketId);

            $approved = $this->service->resolverPartida(
                $ticketId,
                (int) $partidas[0]['id'],
                'APROBAR',
                [
                    'clave_autorizada' => 'MP-OK',
                    'descripcion_autorizada' => 'Producto autorizado desde multipartida',
                    'unidad_sat_autorizada' => $fixture['unit_code'] . ' - ' . $fixture['unit_name'],
                    'clave_sat_autorizada' => $fixture['sat_key_code'] . ' - ' . $fixture['sat_key_description'],
                    'comentario_resolucion' => 'Respuesta aprobada para solicitante.',
                ],
                $fixture['user_id']
            );
            $rejected = $this->service->resolverPartida(
                $ticketId,
                (int) $partidas[1]['id'],
                'RECHAZAR',
                [
                    'motivo_rechazo' => 'Falta ficha técnica para validar alta.',
                    'comentario_resolucion' => 'Respuesta rechazada para solicitante.',
                ],
                $fixture['user_id']
            );
            $ticketAfterResponses = $this->service->obtenerTicket($ticketId) ?? $rejected;
            $showHtml = $this->renderShow($ticketAfterResponses, true);
            $showWithoutResolve = $this->renderShow($ticketAfterResponses, false);
            $cancelled = $this->service->cancelarTicket($ticketId, 'Cancelación QA multipartida.', $fixture['user_id']);
            $cancelledHtml = $this->renderShow($cancelled, true);
            $during = $this->operationalCounts();

            $results['create_ui'] = [
                'create_allows_multiple_partidas' => str_contains($createHtml, 'Partidas solicitadas')
                    && substr_count($createHtml, 'data-partida-card') >= 2,
                'add_button_exists' => str_contains($createHtml, '+ Agregar otra partida')
                    && str_contains($createHtml, 'data-add-partida'),
                'remove_button_exists' => str_contains($createHtml, 'Quitar partida')
                    && str_contains($createHtml, 'data-remove-partida'),
                'first_partida_is_not_removable_when_single' => str_contains($createHtml, 'La Partida 1 no puede eliminarse si es la única')
                    && str_contains($js, 'cards.length <= 1'),
                'weight_input_is_optional_decimal_number' => str_contains($createHtml, 'name="partidas[0][peso]"')
                    && str_contains($createHtml, 'type="number"')
                    && str_contains($createHtml, 'min="0"')
                    && str_contains($createHtml, 'step="0.001"')
                    && !str_contains($createHtml, 'name="partidas[0][peso]" required'),
                'general_initial_attachments_kept' => str_contains($createHtml, 'name="adjuntos[]"')
                    && str_contains($createHtml, 'multiple'),
                'partida_attachments_creation_documented_pending' => str_contains($createHtml, 'Adjuntos por partida durante creación')
                    && str_contains($createHtml, 'Pendiente de fase específica'),
                'no_inline_js_blockable_by_csp' => !str_contains($createHtml, "document.addEventListener('DOMContentLoaded'")
                    && str_contains($createHtml, 'src="/js/modules/tickets-productos-create.js" defer'),
            ];

            $results['js_contract'] = [
                'external_local_script_exists' => is_file(BASE_PATH . '/public/js/modules/tickets-productos-create.js'),
                'handles_company_warehouse' => str_contains($js, 'refreshWarehouses')
                    && str_contains($js, "company.addEventListener('change'"),
                'handles_dynamic_partidas' => str_contains($js, 'initPartidas')
                    && str_contains($js, 'data-add-partida')
                    && str_contains($js, 'data-remove-partida')
                    && str_contains($js, 'refreshPartidaIndexes'),
                'cloned_weight_keeps_decimal_contract' => str_contains($js, "key === 'peso'")
                    && str_contains($js, "field.type = 'number'")
                    && str_contains($js, "field.min = '0'")
                    && str_contains($js, "field.step = '0.001'")
                    && str_contains($js, 'field.required = false'),
                'uses_safe_dom' => str_contains($js, 'document.createElement(\'option\')')
                    && str_contains($js, '.textContent')
                    && !str_contains($js, 'innerHTML'),
                'no_cdn_framework_eval_console' => !$this->containsAny($js, [
                    'https://',
                    'http://',
                    'jquery',
                    'React',
                    'Vue',
                    'Angular',
                    'eval(',
                    'new Function',
                    'console.log',
                ]),
                'no_sql_or_internal_paths_or_secrets' => !preg_match('/\b(insert|update|delete|drop|alter)\b/i', $js)
                    && !preg_match('/\bselect\s+.+\s+from\b/i', $js)
                    && !preg_match('/\bcreate\s+table\b/i', $js)
                    && !$this->containsAny($js, ['storage/private', 'storage/uploads', 'token_hash', 'auth_user', 'password', 'secret', 'dsn']),
            ];

            $results['backend_multipart'] = [
                'accepts_multiple_valid_partidas' => count($partidas) === 3,
                'numbers_partidas_1_to_n' => array_column($partidas, 'numero_partida') === [1, 2, 3],
                'rejects_ticket_without_partidas' => $this->fails(fn () => $this->service->crearTicket([
                    'empresa_id' => $fixture['empresa_id'],
                    'almacen_id' => $fixture['almacen_id'],
                    'partidas' => [],
                ], $fixture['user_id'])),
                'rejects_completely_empty_partida' => $this->fails(fn () => $this->service->crearTicket([
                    'empresa_id' => $fixture['empresa_id'],
                    'almacen_id' => $fixture['almacen_id'],
                    'partidas' => [[]],
                ], $fixture['user_id'])),
                'failed_partida_leaves_no_partial_ticket' => $this->failedPartidaLeavesNoTicket($fixture),
                'controller_accepts_nested_partidas' => $this->controllerStoreAcceptsMultiple($fixture),
                'general_attachment_stored' => count($this->generalAttachments($ticketId)) === 1,
            ];

            $weights = $this->acceptedWeightCases($fixture);
            $invalidWeight = $this->invalidWeightLeavesNoTicketOrFile($fixture);
            $results['weight_validation'] = [
                'weight_is_optional' => $weights['empty'] === null,
                'accepts_zero' => $weights['0'] === '0.000000',
                'accepts_0_1' => $weights['0.1'] === '0.100000',
                'accepts_0_25' => $weights['0.25'] === '0.250000',
                'accepts_leading_dot_decimal' => $weights['.3'] === '0.300000',
                'accepts_1_5' => $weights['1.5'] === '1.500000',
                'accepts_10_75' => $weights['10.75'] === '10.750000',
                'accepts_leading_comma_decimal_and_normalizes' => $weights[',5'] === '0.500000',
                'accepts_comma_decimal_and_normalizes' => $weights['1,25'] === '1.250000',
                'rejects_negative_integer' => $this->weightFails($fixture, '-1'),
                'rejects_negative_decimal' => $this->weightFails($fixture, '-0.5'),
                'rejects_text' => $this->weightFails($fixture, 'abc'),
                'rejects_ambiguous_thousands_comma' => $this->weightFails($fixture, '1,000'),
                'rejects_symbols' => $this->weightFails($fixture, '10 kg'),
                'rejects_invalid_spaces' => $this->weightFails($fixture, ' 1'),
                'rejects_nan' => $this->weightFails($fixture, 'NaN'),
                'rejects_infinity' => $this->weightFails($fixture, 'Infinity'),
                'does_not_lose_decimals' => $weights['0.25'] === '0.250000'
                    && $weights['1.5'] === '1.500000',
                'multiple_decimal_weights_are_saved' => $weights['multiple'] === ['0.100000', '0.250000', '10.750000'],
                'multiple_empty_weights_are_saved_as_null' => $weights['multiple_empty'] === [null, null],
                'invalid_weight_leaves_no_partial_ticket' => $invalidWeight['no_partial_ticket'],
                'invalid_weight_leaves_no_orphan_file' => $invalidWeight['no_orphan_file'],
            ];

            $results['detail_responses'] = [
                'shows_requested_data_section' => str_contains($showHtml, 'Datos solicitados')
                    && str_contains($showHtml, 'Descripción solicitada')
                    && str_contains($showHtml, 'Proveedor documental'),
                'shows_product_area_response_section' => str_contains($showHtml, 'Respuesta del área de productos'),
                'approved_line_shows_authorized_data' => str_contains($showHtml, 'MP-OK')
                    && str_contains($showHtml, 'Producto autorizado desde multipartida')
                    && str_contains($showHtml, 'Unidad SAT autorizada')
                    && str_contains($showHtml, 'Clave SAT autorizada'),
                'rejected_line_shows_reason_and_comment' => str_contains($showHtml, 'Falta ficha técnica para validar alta.')
                    && str_contains($showHtml, 'Respuesta rechazada para solicitante.'),
                'pending_line_shows_pending_review' => str_contains($showHtml, 'Pendiente de revisión.'),
                'resolved_lines_hide_approve_reject_forms' => substr_count($showHtml, 'Aprobar partida') === 2
                    && substr_count($showHtml, 'Rechazar partida') === 1,
                'user_without_resolve_permission_sees_no_actions' => !str_contains($showWithoutResolve, 'Aprobar partida')
                    && !str_contains($showWithoutResolve, 'Rechazar partida'),
                'cancelled_ticket_hides_line_actions' => str_contains($cancelledHtml, 'Ticket cancelado; las acciones de la partida están ocultas.')
                    && !str_contains($cancelledHtml, '<button class="button" type="submit">Aprobar partida</button>')
                    && !str_contains($cancelledHtml, '<button class="button button--secondary" type="submit">Rechazar partida</button>'),
                'no_download_or_preview_or_internal_paths' => !$this->containsAny($showHtml, [
                    '/descargar',
                    '/preview',
                    'download',
                    'storage/private',
                    'ruta_relativa',
                    'nombre_guardado',
                    'C:\\',
                ]),
            ];

            $results['mail_placeholder'] = [
                'mail_placeholder_visible' => str_contains($showHtml, 'Correo electrónico')
                    && str_contains($showHtml, 'El envío automático de correo se implementará en una fase posterior.'),
                'future_mail_events_listed' => str_contains($showHtml, 'ticket creado')
                    && str_contains($showHtml, 'partida aprobada')
                    && str_contains($showHtml, 'partida rechazada')
                    && str_contains($showHtml, 'ticket resuelto')
                    && str_contains($showHtml, 'ticket cancelado'),
                'no_real_mail_runtime_created' => !$this->hasFiles('app/Domain/Mail', '/ticket|solicitud|alta/i')
                    && !$this->hasFiles('app/Domain/Notifications', '/ticket|solicitud|alta/i')
                    && !str_contains($this->read('app/Http/Controllers/ProductRequestTicketController.php'), 'PHPMailer'),
            ];

            $results['guardrails'] = [
                'company_warehouse_still_filters' => str_contains($js, 'item.empresa_id === selectedCompanyId')
                    && str_contains($js, 'warehouse.dataset.selectedWarehouse = \'\';'),
                'approval_capture_still_works' => $approved !== []
                    && str_contains($showHtml, 'Descripción autorizada'),
                'runtime_attachments_still_available' => str_contains($showHtml, 'Adjuntos de partida')
                    && str_contains($this->read('app/Domain/Tickets/ProductRequestTicketService.php'), 'public function agregarAdjunto'),
                'counts_unchanged_inside_phase' => $before === $during,
                'no_product_created' => $before['productos'] === $during['productos'],
                'no_price_created' => $before['producto_precios'] === $during['producto_precios'],
                'no_stock_created' => $before['existencias_producto'] === $during['existencias_producto'],
                'no_inventory_created' => $before['inventario_existencias'] === $during['inventario_existencias'],
                'no_inventory_movement_created' => $before['movimientos_inventario'] === $during['movimientos_inventario'],
                'no_purchase_created' => $before['compras'] === $during['compras'],
                'no_supplier_created' => $before['proveedores'] === $during['proveedores'],
            ];

            $duringStorage = $this->storageFiles();
            $pdo->rollBack();
            $this->deleteCreatedStorageFiles($duringStorage, $beforeStorage);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->deleteCreatedStorageFiles($this->storageFiles(), $beforeStorage);
            $this->deleteTempFiles();

            throw $exception;
        } finally {
            $this->closeSession();
        }

        $after = $this->operationalCounts();
        $afterStorage = $this->storageFiles();
        $this->deleteTempFiles();

        $results['cleanup'] = [
            'transaction_rolled_back' => $before === $after,
            'no_operational_counts_changed' => $before === $after,
            'no_storage_files_left_by_test' => $beforeStorage === $afterStorage,
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-MULTIPARTIDA-RESPUESTAS-UI-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'partidas_creadas_en_prueba' => 3,
            'adjuntos_por_partida_en_creacion' => 'pendiente_documentado_para_fase_posterior',
            'cases' => $results,
            'operational_counts_before' => $before,
            'operational_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back_storage_files_deleted_no_operational_data_written',
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function fixture(): array
    {
        $userId = $this->createUser();
        $this->createProfile($userId);
        $companyId = $this->createCompany('MPQA001', 'Empresa Multipartida QA', $userId);
        $warehouseId = $this->createWarehouse($companyId, 'MP', 'Almacén Multipartida QA', $userId);
        $this->assignScope($userId, $companyId, $warehouseId);
        $unit = $this->firstSatUnit();
        $satKey = $this->firstSatKey();
        $currency = $this->firstCurrency();

        return [
            'user_id' => $userId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'unit_id' => (int) $unit['id'],
            'unit_code' => (string) $unit['codigo'],
            'unit_name' => (string) $unit['nombre'],
            'sat_key_id' => (int) $satKey['id'],
            'sat_key_code' => (string) $satKey['codigo'],
            'sat_key_description' => (string) $satKey['descripcion'],
            'currency_id' => (int) $currency['id'],
        ];
    }

    /**
     * @param array<string, int|string> $fixture
     * @return list<array<string, mixed>>
     */
    private function validParts(array $fixture): array
    {
        return [
            [
                'descripcion' => 'Compresor documental multipartida',
                'modelo' => 'MP-100',
                'marca_texto' => 'Marca QA',
                'proveedor_texto' => 'Proveedor documental QA',
                'unidad_sat_id' => $fixture['unit_id'],
                'clave_sat_id' => $fixture['sat_key_id'],
                'moneda_id' => $fixture['currency_id'],
                'costo_sugerido' => '100.50',
                'peso' => '1.25',
                'lleva_serie' => 1,
                'observaciones' => 'Primera partida QA.',
            ],
            [
                'descripcion' => 'Sensor documental multipartida',
                'modelo' => 'MP-200',
                'marca_texto' => 'Marca QA',
                'proveedor_texto' => 'Proveedor documental QA',
                'unidad_sat_id' => $fixture['unit_id'],
                'clave_sat_id' => $fixture['sat_key_id'],
                'moneda_id' => $fixture['currency_id'],
                'costo_sugerido' => '25.00',
            ],
            [
                'descripcion' => 'Filtro documental multipartida',
                'modelo' => 'MP-300',
                'unidad_sat_id' => $fixture['unit_id'],
                'clave_sat_id' => $fixture['sat_key_id'],
                'moneda_id' => $fixture['currency_id'],
            ],
        ];
    }

    /**
     * @param array<string, int|string> $fixture
     * @return array<string, mixed>
     */
    private function acceptedWeightCases(array $fixture): array
    {
        $cases = [
            'empty' => '',
            '0' => '0',
            '0.1' => '0.1',
            '0.25' => '0.25',
            '.3' => '.3',
            '1.5' => '1.5',
            '10.75' => '10.75',
            ',5' => ',5',
            '1,25' => '1,25',
        ];
        $result = [];

        foreach ($cases as $label => $weight) {
            $stored = $this->createTicketWithWeights($fixture, [$weight]);
            $result[$label] = $stored[0] ?? null;
        }

        $result['multiple'] = $this->createTicketWithWeights($fixture, ['0.1', '0.25', '10.75']);
        $result['multiple_empty'] = $this->createTicketWithWeights($fixture, ['', '']);

        return $result;
    }

    /**
     * @param array<string, int|string> $fixture
     * @param list<string> $weights
     * @return list<string|null>
     */
    private function createTicketWithWeights(array $fixture, array $weights): array
    {
        $parts = [];

        foreach ($weights as $index => $weight) {
            $parts[] = [
                'descripcion' => 'Partida peso QA ' . ($index + 1),
                'peso' => $weight,
            ];
        }

        $ticket = $this->service->crearTicket([
            'empresa_id' => $fixture['empresa_id'],
            'almacen_id' => $fixture['almacen_id'],
            'observaciones_generales' => 'Ticket QA peso ' . bin2hex(random_bytes(3)),
            'partidas' => $parts,
        ], $fixture['user_id']);

        return array_map(
            static fn (array $partida): ?string => $partida['peso'] === null ? null : (string) $partida['peso'],
            $this->partidas((int) $ticket['id'])
        );
    }

    /**
     * @param array<string, int|string> $fixture
     */
    private function weightFails(array $fixture, string $weight): bool
    {
        return $this->fails(fn () => $this->service->crearTicket([
            'empresa_id' => $fixture['empresa_id'],
            'almacen_id' => $fixture['almacen_id'],
            'partidas' => [[
                'descripcion' => 'Partida con peso inválido.',
                'peso' => $weight,
            ]],
        ], $fixture['user_id']));
    }

    /**
     * @param array<string, int|string> $fixture
     * @return array{no_partial_ticket: bool, no_orphan_file: bool}
     */
    private function invalidWeightLeavesNoTicketOrFile(array $fixture): array
    {
        $observation = 'Ticket QA peso inválido sin parcial.';
        $beforeTickets = $this->countTicketsByObservation($observation);
        $beforeStorage = $this->storageFiles();

        try {
            $this->service->crearTicket([
                'empresa_id' => $fixture['empresa_id'],
                'almacen_id' => $fixture['almacen_id'],
                'observaciones_generales' => $observation,
                'partidas' => [
                    ['descripcion' => 'Partida válida previa.', 'peso' => '0.25'],
                    ['descripcion' => 'Partida inválida.', 'peso' => '-0.5'],
                ],
                'adjuntos' => [$this->upload('peso-invalido.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n")],
            ], $fixture['user_id']);
        } catch (ProductRequestTicketValidationException) {
            return [
                'no_partial_ticket' => $beforeTickets === $this->countTicketsByObservation($observation),
                'no_orphan_file' => $beforeStorage === $this->storageFiles(),
            ];
        }

        return [
            'no_partial_ticket' => false,
            'no_orphan_file' => false,
        ];
    }

    /**
     * @param array<string, int|string> $fixture
     * @param array<string, mixed> $values
     */
    private function renderCreate(array $fixture, array $values): string
    {
        return View::render('tickets/productos/create', [
            'csrf' => $this->csrf(),
            'permissions' => ['canCreate' => true],
            'errors' => [],
            'values' => $values,
            'catalogs' => [
                'companies' => $this->repository->availableCompaniesForUser((int) $fixture['user_id']),
                'warehouses' => $this->repository->availableWarehousesForUser((int) $fixture['user_id'], (int) $fixture['empresa_id']),
                'all_warehouses' => $this->repository->availableWarehousesForUser((int) $fixture['user_id']),
                'brands' => $this->repository->activeBrands(),
                'currencies' => $this->repository->activeCurrencies(),
                'sat_units' => $this->repository->activeSatUnits(),
                'sat_keys' => $this->repository->activeSatKeys(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function renderShow(array $ticket, bool $canResolve): string
    {
        return View::render('tickets/productos/show', [
            'csrf' => $this->csrf(),
            'permissions' => [
                'canResolve' => $canResolve,
                'canCancel' => true,
                'canViewAttachments' => true,
                'canCreateComments' => true,
                'canResendEmail' => true,
                'canViewEvents' => true,
            ],
            'errors' => [],
            'ticket' => $ticket,
            'approvalCatalogs' => [
                'sat_units' => $this->repository->activeSatUnits(),
                'sat_keys' => $this->repository->activeSatKeys(),
            ],
        ]);
    }

    /**
     * @param array<string, int|string> $fixture
     */
    private function failedPartidaLeavesNoTicket(array $fixture): bool
    {
        $before = $this->countTicketsByObservation('Ticket QA multipartida inválido.');

        try {
            $this->service->crearTicket([
                'empresa_id' => $fixture['empresa_id'],
                'almacen_id' => $fixture['almacen_id'],
                'observaciones_generales' => 'Ticket QA multipartida inválido.',
                'partidas' => [
                    ['descripcion' => 'Partida válida previa al fallo.'],
                    ['descripcion' => ''],
                ],
            ], $fixture['user_id']);
        } catch (ProductRequestTicketValidationException) {
            return $before === $this->countTicketsByObservation('Ticket QA multipartida inválido.');
        }

        return false;
    }

    /**
     * @param array<string, int|string> $fixture
     */
    private function controllerStoreAcceptsMultiple(array $fixture): bool
    {
        $response = $this->controllerFor((int) $fixture['user_id'])->store(new Request(
            'POST',
            '/tickets/productos',
            [],
            [
                'empresa_id' => (string) $fixture['empresa_id'],
                'almacen_id' => (string) $fixture['almacen_id'],
                'observaciones_generales' => 'Ticket QA multipartida controller.',
                'partidas' => $this->validParts($fixture),
            ]
        ));

        return $response->status() === 302
            && $this->countTicketsByObservation('Ticket QA multipartida controller.') === 1;
    }

    private function fails(callable $callback): bool
    {
        try {
            $callback();
        } catch (ProductRequestTicketValidationException) {
            return true;
        }

        return false;
    }

    private function controllerFor(int $userId): ProductRequestTicketController
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('TPMP' . bin2hex(random_bytes(4)));
            session_start();
        }

        $_SESSION['auth_user'] = [
            'user_id' => $userId,
            'username' => 'qa.tp.multipart',
            'email' => 'qa.tp.multipart@example.test',
        ];

        return new ProductRequestTicketController(
            new AuthService(new UserRepository($GLOBALS['tp_product_ticket_multipart_connection']), new Session([])),
            $this->service,
            new PermissionService(new PermissionRepository($GLOBALS['tp_product_ticket_multipart_connection'])),
            $this->repository
        );
    }

    private function csrf(): CsrfTokenService
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('TPMP' . bin2hex(random_bytes(4)));
            session_start();
        }

        return new CsrfTokenService(new Session([]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function partidas(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM tickets_productos_partidas WHERE ticket_producto_id = :ticket_id ORDER BY numero_partida ASC'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generalAttachments(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM tickets_productos_adjuntos WHERE ticket_producto_id = :ticket_id AND partida_id IS NULL'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll();
    }

    private function countTicketsByObservation(string $observation): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tickets_productos WHERE observaciones_generales = :observation'
        );
        $statement->execute(['observation' => $observation]);

        return (int) $statement->fetchColumn();
    }

    private function upload(string $name, string $contents): array
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $path = tempnam(sys_get_temp_dir(), 'tpmp_');

        if ($path === false) {
            throw new RuntimeException('Unable to create temp upload.');
        }

        file_put_contents($path, $contents);
        $this->filesToDelete[] = $path;

        return [
            'name' => $name,
            'type' => $extension === 'pdf' ? 'application/pdf' : 'application/octet-stream',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path) ?: 0,
        ];
    }

    private function createUser(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo, creado_en)
             VALUES (:username, :email, :password_hash, 1, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'username' => 'qa.tp.multipart',
            'email' => 'qa.tp.multipart@example.test',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createProfile(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO perfiles_usuario (usuario_id, primer_nombre, segundo_nombre, apellido_paterno)
             VALUES (:usuario_id, :primer_nombre, :segundo_nombre, :apellido_paterno)'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'primer_nombre' => 'QA',
            'segundo_nombre' => 'Multipartida',
            'apellido_paterno' => 'Respuestas',
        ]);
    }

    private function createCompany(string $code, string $name, int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createWarehouse(int $companyId, string $code, string $name, int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function assignScope(int $userId, int $companyId, int $warehouseId): void
    {
        $company = $this->pdo->prepare(
            'INSERT INTO usuario_empresas (usuario_id, empresa_id, activo, creado_por)
             VALUES (:usuario_id, :empresa_id, 1, :creado_por)'
        );
        $company->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'creado_por' => $userId,
        ]);

        $warehouse = $this->pdo->prepare(
            'INSERT INTO usuario_almacenes (usuario_id, empresa_id, almacen_id, activo, creado_por)
             VALUES (:usuario_id, :empresa_id, :almacen_id, 1, :creado_por)'
        );
        $warehouse->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'creado_por' => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstSatUnit(): array
    {
        $row = $this->pdo->query(
            'SELECT id, codigo, nombre FROM unidades_sat WHERE activo = 1 AND eliminado_en IS NULL ORDER BY id ASC LIMIT 1'
        )->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('No SAT unit available for multipartida test.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstSatKey(): array
    {
        $row = $this->pdo->query(
            'SELECT id, codigo, descripcion FROM claves_sat WHERE activo = 1 AND eliminado_en IS NULL ORDER BY id ASC LIMIT 1'
        )->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('No SAT key available for multipartida test.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstCurrency(): array
    {
        $row = $this->pdo->query(
            'SELECT id FROM monedas WHERE activo = 1 AND eliminado_en IS NULL ORDER BY es_base DESC, id ASC LIMIT 1'
        )->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('No currency available for multipartida test.');
        }

        return $row;
    }

    /**
     * @return array<string, int>
     */
    private function operationalCounts(): array
    {
        return [
            'productos' => $this->countRows('productos'),
            'producto_precios' => $this->countRows('producto_precios'),
            'existencias_producto' => $this->countRows('existencias_producto'),
            'inventario_existencias' => $this->countRows('inventario_existencias'),
            'movimientos_inventario' => $this->countRows('movimientos_inventario'),
            'compras' => $this->countRows('compras'),
            'proveedores' => $this->countRows('proveedores'),
        ];
    }

    private function countRows(string $table): int
    {
        $exists = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $exists->execute(['table' => $table]);

        if ((int) $exists->fetchColumn() !== 1) {
            return 0;
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function storageFiles(): array
    {
        $root = BASE_PATH . '/storage/private/tickets_productos';

        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param list<string> $current
     * @param list<string> $baseline
     */
    private function deleteCreatedStorageFiles(array $current, array $baseline): void
    {
        foreach (array_diff($current, $baseline) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function deleteTempFiles(): void
    {
        foreach (array_unique($this->filesToDelete) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->filesToDelete = [];
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, (string) $needle)) {
                return true;
            }
        }

        return false;
    }

    private function hasFiles(string $relativeDirectory, string $pattern): bool
    {
        $directory = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (!is_dir($directory)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = str_replace(
                [BASE_PATH . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
                ['', '/'],
                $file->getPathname()
            );

            if (preg_match($pattern, $relative) === 1) {
                return true;
            }
        }

        return false;
    }

    private function read(string $relativePath): string
    {
        $path = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function allTrue(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                if (!$this->allTrue($item)) {
                    return false;
                }

                continue;
            }

            if ($item !== true) {
                return false;
            }
        }

        return true;
    }
};
