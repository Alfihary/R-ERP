<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Http\Controllers\ProductRequestTicketController;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\UserRepository;

return new class implements DatabaseTest {
    private PDO $pdo;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException(
                'Unexpected active database for TP-PARTIDAS-ESTADOS-CREATE-CATALOGOS-1.'
            );
        }

        $migration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';
        $seed = require BASE_PATH
            . '/database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php';

        if (!$migration instanceof Migration || !$seed instanceof Seed) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-CREATE-CATALOGOS-1 dependencies are invalid.'
            );
        }

        $runner = new MigrationRunner($pdo);
        $migrateResult = $this->ensureTicketTables($runner, $migration);
        $seed->run($pdo);
        $countsBefore = $this->operationalCounts();
        $results = [];
        $countsDuring = [];

        $pdo->beginTransaction();

        try {
            $fixture = $this->fixture();
            $controller = $this->controllerFor($fixture['user_id']);
            $_SESSION['active_company_id'] = $fixture['company_a_id'];
            $_SESSION['active_warehouse_id'] = $fixture['warehouse_a_id'];
            $createDefault = $controller->create(new Request('GET', '/tickets/productos/crear'));
            $createCompanyB = $controller->create(
                new Request('GET', '/tickets/productos/crear', [
                    'empresa_id' => (string) $fixture['company_b_id'],
                ])
            );
            $created = $controller->store(new Request('POST', '/tickets/productos', [], [
                'empresa_id' => (string) $fixture['company_a_id'],
                'almacen_id' => (string) $fixture['warehouse_a_id'],
                'observaciones_generales' => 'Solicitud de revisión.',
                'partidas' => [[
                    'descripcion' => 'Equipo solicitado para revisión.',
                    'modelo' => 'CAT-UI-1',
                    'marca_id' => (string) $fixture['brand_id'],
                    'proveedor_texto' => 'Proveedor sugerido',
                    'unidad_sat_busqueda' => 'QATPCAT1 - Pieza QA Catálogos - Unidad de prueba para selector',
                    'clave_sat_busqueda' => '99123456 - Clave QA Catálogos',
                    'moneda_id' => (string) $fixture['currency_id'],
                    'costo_sugerido' => '10.50',
                    'peso' => '1.25',
                    'lleva_serie' => '1',
                    'observaciones' => 'Observación de captura.',
                ]],
            ]));
            $invalidUnit = $controller->store(new Request('POST', '/tickets/productos', [], [
                'empresa_id' => (string) $fixture['company_a_id'],
                'almacen_id' => (string) $fixture['warehouse_a_id'],
                'partidas' => [[
                    'descripcion' => 'Equipo con unidad SAT inexistente.',
                    'unidad_sat_busqueda' => 'Unidad SAT inexistente QA',
                    'clave_sat_busqueda' => '99123456 - Clave QA Catálogos',
                ]],
            ]));
            $invalidSatKey = $controller->store(new Request('POST', '/tickets/productos', [], [
                'empresa_id' => (string) $fixture['company_a_id'],
                'almacen_id' => (string) $fixture['warehouse_a_id'],
                'partidas' => [[
                    'descripcion' => 'Equipo con clave SAT inexistente.',
                    'unidad_sat_busqueda' => 'QATPCAT1 - Pieza QA Catálogos - Unidad de prueba para selector',
                    'clave_sat_busqueda' => 'Clave SAT inexistente QA',
                ]],
            ]));
            $ambiguousSatKey = $controller->store(new Request('POST', '/tickets/productos', [], [
                'empresa_id' => (string) $fixture['company_a_id'],
                'almacen_id' => (string) $fixture['warehouse_a_id'],
                'partidas' => [[
                    'descripcion' => 'Equipo con clave SAT ambigua.',
                    'unidad_sat_busqueda' => 'QATPCAT1 - Pieza QA Catálogos - Unidad de prueba para selector',
                    'clave_sat_busqueda' => 'Clave SAT Ambigua QA',
                ]],
            ]));
            $ticketCountAfterValid = $this->ticketCount();
            $invalidCompanyWarehouse = $controller->store(new Request('POST', '/tickets/productos', [], [
                'empresa_id' => (string) $fixture['company_a_id'],
                'almacen_id' => (string) $fixture['warehouse_b_id'],
                'partidas' => [[
                    'descripcion' => 'Equipo con almacén de otra empresa.',
                    'unidad_sat_busqueda' => 'QATPCAT1 - Pieza QA Catálogos - Unidad de prueba para selector',
                    'clave_sat_busqueda' => '99123456 - Clave QA Catálogos',
                ]],
            ]));
            $outOfScopeCompany = $controller->store(new Request('POST', '/tickets/productos', [], [
                'empresa_id' => (string) $fixture['company_out_id'],
                'almacen_id' => (string) $fixture['warehouse_out_id'],
                'partidas' => [[
                    'descripcion' => 'Equipo con empresa fuera de alcance.',
                    'unidad_sat_busqueda' => 'QATPCAT1 - Pieza QA Catálogos - Unidad de prueba para selector',
                    'clave_sat_busqueda' => '99123456 - Clave QA Catálogos',
                ]],
            ]));
            $outOfScopeWarehouse = $controller->store(new Request('POST', '/tickets/productos', [], [
                'empresa_id' => (string) $fixture['company_a_id'],
                'almacen_id' => (string) $fixture['warehouse_a_out_scope_id'],
                'partidas' => [[
                    'descripcion' => 'Equipo con almacén fuera de alcance.',
                    'unidad_sat_busqueda' => 'QATPCAT1 - Pieza QA Catálogos - Unidad de prueba para selector',
                    'clave_sat_busqueda' => '99123456 - Clave QA Catálogos',
                ]],
            ]));
            $partida = $this->latestPartida();

            $createHtml = $createDefault->body();
            $companyBHtml = $createCompanyB->body();
            $view = $this->read('app/Views/tickets/productos/create.php');
            $ticketCreateJs = $this->read('public/js/modules/tickets-productos-create.js');
            $controllerSource = $this->read('app/Http/Controllers/ProductRequestTicketController.php');
            $repositorySource = $this->read('app/Infrastructure/Repositories/ProductRequestTicketRepository.php');

            $results = [
                'catalog_ui' => [
                    'create_status_200' => $createDefault->status() === 200,
                    'company_is_select' => str_contains($createHtml, 'name="empresa_id"')
                        && str_contains($createHtml, '<select'),
                    'warehouse_is_select' => str_contains($createHtml, 'name="almacen_id"')
                        && str_contains($createHtml, 'data-warehouse-select'),
                    'manual_refresh_button_removed' =>
                        !str_contains($createHtml, 'Actualizar almacenes')
                        && !str_contains($createHtml, 'Actualizar catálogos')
                        && !str_contains($createHtml, 'formmethod="get"'),
                    'company_warehouse_auto_filter_js_exists' =>
                        str_contains($createHtml, 'id="ticket-products-warehouses-data"')
                        && str_contains($createHtml, 'data-company-select')
                        && str_contains($createHtml, 'src="/js/modules/tickets-productos-create.js" defer')
                        && str_contains($ticketCreateJs, 'addEventListener')
                        && str_contains($ticketCreateJs, "'change'")
                        && str_contains($ticketCreateJs, 'replaceChildren'),
                    'company_warehouse_js_is_external_local_and_safe' =>
                        is_file(BASE_PATH . '/public/js/modules/tickets-productos-create.js')
                        && !str_contains($view, "document.addEventListener('DOMContentLoaded'")
                        && str_contains($view, 'type="application/json" id="ticket-products-warehouses-data"')
                        && !str_contains($ticketCreateJs, 'https://')
                        && !str_contains($ticketCreateJs, 'http://')
                        && !str_contains($ticketCreateJs, 'jquery')
                        && !str_contains($ticketCreateJs, 'React')
                        && !str_contains($ticketCreateJs, 'Vue')
                        && !str_contains($ticketCreateJs, 'Angular')
                        && !str_contains($ticketCreateJs, 'console.log')
                        && str_contains($ticketCreateJs, 'document.createElement(\'option\')')
                        && str_contains($ticketCreateJs, 'option.textContent = text')
                        && !str_contains($ticketCreateJs, 'innerHTML')
                        && !str_contains($ticketCreateJs, 'eval('),
                    'warehouse_json_is_limited_to_safe_fields' =>
                        str_contains($createHtml, '"empresa_id"')
                        && str_contains($createHtml, '"almacen_id"')
                        && str_contains($createHtml, '"codigo"')
                        && str_contains($createHtml, '"nombre"')
                        && str_contains(
                            $view,
                            "'empresa_id' => (int) (\$warehouse['empresa_id'] ?? 0)"
                        )
                        && str_contains(
                            $view,
                            "'almacen_id' => (int) (\$warehouse['id'] ?? 0)"
                        )
                        && !str_contains($view, "'usuario_id'")
                        && !str_contains($view, "'token'")
                        && !str_contains($view, "'password'"),
                    'warehouse_json_keeps_real_company_relation' =>
                        str_contains(
                            $createHtml,
                            '"empresa_id":' . $fixture['company_a_id'] . ',"almacen_id":' . $fixture['warehouse_a_id']
                        )
                        && str_contains(
                            $createHtml,
                            '"empresa_id":' . $fixture['company_b_id'] . ',"almacen_id":' . $fixture['warehouse_b_id']
                        ),
                    'sat_has_single_search_input_per_catalog' =>
                        substr_count($createHtml, 'name="partidas[0][unidad_sat_busqueda]"') === 1
                        && substr_count($createHtml, 'name="partidas[0][clave_sat_busqueda]"') === 1,
                    'sat_has_no_separate_result_fields' =>
                        !str_contains($createHtml, 'name="partidas[0][unidad_sat_id]"')
                        && !str_contains($createHtml, 'name="partidas[0][clave_sat_id]"')
                        && !str_contains($view, 'type="hidden" name="partidas[0][unidad_sat_id]"')
                        && !str_contains($view, 'type="hidden" name="partidas[0][clave_sat_id]"'),
                    'brand_is_catalog_select' => str_contains($createHtml, 'name="partidas[0][marca_id]"')
                        && str_contains($createHtml, 'Marca QA Catálogos'),
                    'currency_is_catalog_select' => str_contains($createHtml, 'name="partidas[0][moneda_id]"')
                        && str_contains($createHtml, 'MXN'),
                    'sat_unit_is_single_visible_field' =>
                        substr_count($createHtml, 'list="unidades_sat_options"') === 1
                        && substr_count($createHtml, 'for="partida_unidad_sat"') === 1
                        && str_contains($createHtml, '<datalist id="unidades_sat_options">'),
                    'sat_unit_selection_has_code_and_description' =>
                        str_contains($createHtml, 'name="partidas[0][unidad_sat_busqueda]"')
                        && str_contains($createHtml, 'QATPCAT1')
                        && str_contains($createHtml, 'Pieza QA Catálogos'),
                    'sat_key_is_single_visible_field' =>
                        substr_count($createHtml, 'list="claves_sat_options"') === 1
                        && substr_count($createHtml, 'for="partida_clave_sat"') === 1
                        && str_contains($createHtml, '<datalist id="claves_sat_options">'),
                    'sat_key_selection_has_code_and_description' =>
                        str_contains($createHtml, 'name="partidas[0][clave_sat_busqueda]"')
                        && str_contains($createHtml, '99123456')
                        && str_contains($createHtml, 'Clave QA Catálogos'),
                    'unnecessary_documentary_labels_removed' =>
                        !str_contains($createHtml, 'Marca documental')
                        && !str_contains($createHtml, 'Proveedor documental')
                        && !str_contains($createHtml, 'Costo sugerido documental')
                        && !str_contains($createHtml, 'Crear ticket documental'),
                    'contract_warning_kept' => str_contains(
                        $createHtml,
                        'Este ticket es documental y no crea productos reales.'
                    ),
                ],
                'scope_and_defaults' => [
                    'single_default_company_selected' => str_contains(
                        $createHtml,
                        'value="' . $fixture['company_a_id'] . '" selected'
                    ),
                    'company_b_filters_warehouses' =>
                        str_contains($companyBHtml, '"almacen_id":' . $fixture['warehouse_b_id'])
                        && !str_contains($companyBHtml, '<option value="' . $fixture['warehouse_a_id'] . '"'),
                    'company_change_clears_foreign_warehouse_in_js' =>
                        str_contains($ticketCreateJs, "warehouse.dataset.selectedWarehouse = '';")
                        && str_contains($ticketCreateJs, 'item.empresa_id === selectedCompanyId')
                        && !str_contains($ticketCreateJs, 'item.empresa_id === companyId'),
                    'warehouse_empty_state_when_company_has_no_available_warehouse' =>
                        str_contains($ticketCreateJs, 'Sin almacenes asignados para esta empresa')
                        && str_contains($ticketCreateJs, 'No tienes almacenes asignados para esta empresa.'),
                    'companies_limited_to_user_scope' =>
                        str_contains($createHtml, 'Empresa QA Catálogos A')
                        && !str_contains($createHtml, 'Empresa QA Catálogos Sin Scope'),
                    'warehouses_limited_to_user_scope' =>
                        str_contains($createHtml, '"almacen_id":' . $fixture['warehouse_a_id'])
                        && !str_contains($createHtml, '"almacen_id":' . $fixture['warehouse_a_out_scope_id']),
                ],
                'controller_repository_contract' => [
                    'controller_preserves_post_action' =>
                        str_contains($createHtml, 'method="post" action="/tickets/productos"'),
                    'controller_passes_catalogs_to_view' =>
                        str_contains($controllerSource, 'createCatalogs')
                        && str_contains($controllerSource, 'availableCompaniesForUser'),
                    'repository_uses_scope_tables' =>
                        str_contains($repositorySource, 'usuario_empresas')
                        && str_contains($repositorySource, 'usuario_almacenes'),
                    'repository_uses_catalog_tables' =>
                        str_contains($repositorySource, 'FROM marcas')
                        && str_contains($repositorySource, 'FROM monedas')
                        && str_contains($repositorySource, 'FROM unidades_sat')
                        && str_contains($repositorySource, 'FROM claves_sat'),
                    'repository_uses_prepared_scope_queries' =>
                        str_contains($repositorySource, 'prepare(')
                        && str_contains($repositorySource, ':usuario_id'),
                ],
                'store_contract' => [
                    'store_redirects_to_detail' => $created->status() === 302,
                    'brand_id_converted_to_brand_text' =>
                        (string) ($partida['marca_texto'] ?? '') === 'Marca QA Catálogos',
                    'unit_id_is_persisted' => (int) ($partida['unidad_sat_id'] ?? 0) === $fixture['unit_id'],
                    'sat_key_id_is_persisted' => (int) ($partida['clave_sat_id'] ?? 0) === $fixture['sat_key_id'],
                    'currency_id_is_persisted' => (int) ($partida['moneda_id'] ?? 0) === $fixture['currency_id'],
                    'invalid_unit_sat_id_is_rejected' =>
                        $invalidUnit->status() === 422
                        && str_contains($invalidUnit->body(), 'Selecciona una unidad SAT válida del catálogo.'),
                    'invalid_sat_key_id_is_rejected' =>
                        $invalidSatKey->status() === 422
                        && str_contains($invalidSatKey->body(), 'Selecciona una clave SAT válida del catálogo.'),
                    'ambiguous_sat_key_is_rejected' =>
                        $ambiguousSatKey->status() === 422
                        && str_contains($ambiguousSatKey->body(), 'La clave SAT es ambigua'),
                    'warehouse_from_other_company_is_rejected' =>
                        $invalidCompanyWarehouse->status() === 422
                        && str_contains(
                            $invalidCompanyWarehouse->body(),
                            'El almacén no pertenece a la empresa seleccionada o no está disponible para tu usuario.'
                        ),
                    'out_of_scope_company_is_rejected' =>
                        $outOfScopeCompany->status() === 422
                        && str_contains($outOfScopeCompany->body(), 'La empresa no está disponible para tu usuario.'),
                    'out_of_scope_warehouse_is_rejected' =>
                        $outOfScopeWarehouse->status() === 422
                        && str_contains(
                            $outOfScopeWarehouse->body(),
                            'El almacén no pertenece a la empresa seleccionada o no está disponible para tu usuario.'
                        ),
                    'invalid_scope_does_not_create_ticket' =>
                        $this->ticketCount() === $ticketCountAfterValid,
                    'sat_text_is_resolved_before_persisting' =>
                        str_contains($controllerSource, 'resolveActiveSatUnit')
                        && str_contains($controllerSource, 'resolveActiveSatKey')
                        && str_contains($repositorySource, 'resolveActiveSatUnit')
                        && str_contains($repositorySource, 'resolveActiveSatKey'),
                ],
                'security_guardrails' => [
                    'view_uses_escape_helper' => substr_count($view, 'e(') >= 20,
                    'csrf_kept' => str_contains($createHtml, 'name="_token"'),
                    'no_routes_modified_by_phase' => !$this->fileContains(
                        'routes/web.php',
                        'TP-PARTIDAS-ESTADOS-CREATE-CATALOGOS-1'
                    ),
                    'no_private_paths_rendered' => !str_contains($createHtml, 'C:\\')
                        && !str_contains($createHtml, '/var/')
                        && !str_contains($createHtml, 'BASE_PATH'),
                    'no_sensitive_values_rendered' => !str_contains($createHtml, 'password_hash')
                        && !str_contains($createHtml, 'token_hash')
                        && !str_contains($createHtml, 'auth_user'),
                ],
                'operational_guardrails' => [
                    'no_product_created' => $countsBefore['productos'] === $this->operationalCounts()['productos'],
                    'no_price_created' => $countsBefore['producto_precios'] === $this->operationalCounts()['producto_precios'],
                    'no_stock_created' => $countsBefore['existencias_producto'] === $this->operationalCounts()['existencias_producto'],
                    'no_purchase_created' => $countsBefore['compras'] === $this->operationalCounts()['compras'],
                    'no_supplier_created' => $countsBefore['proveedores'] === $this->operationalCounts()['proveedores'],
                ],
            ];
            $countsDuring = $this->operationalCounts();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->closeSession();
        }

        $countsAfter = $this->operationalCounts();

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-CREATE-CATALOGOS-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        if ($countsBefore !== $countsAfter) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-CREATE-CATALOGOS-1 changed operational counts.'
            );
        }

        return [
            'database' => $expectedDatabase,
            'migration_state' => $migrateResult,
            'cases' => $results,
            'operational_counts_before' => $countsBefore,
            'operational_counts_during' => $countsDuring,
            'operational_counts_after' => $countsAfter,
            'cleanup' => 'transaction_rolled_back_no_operational_data_written',
        ];
    }

    private function ensureTicketTables(MigrationRunner $runner, Migration $migration): string
    {
        if ($this->tableExists('tickets_productos')) {
            return 'already_available';
        }

        $result = $runner->migrate($migration);

        if ($result !== 'applied') {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-CREATE-CATALOGOS-1 could not prepare ticket tables.'
            );
        }

        return 'applied_for_test';
    }

    /**
     * @return array<string, int>
     */
    private function fixture(): array
    {
        $userId = $this->createUser('qa.tp.create.catalogos');
        $this->assignAdminRole($userId);
        $companyAId = $this->createCompany('QATPCATA', 'Empresa QA Catálogos A', $userId);
        $companyBId = $this->createCompany('QATPCATB', 'Empresa QA Catálogos B', $userId);
        $companyOutId = $this->createCompany('QATPCATO', 'Empresa QA Catálogos Sin Scope', $userId);
        $warehouseAId = $this->createWarehouse($companyAId, 'QATPCATA', 'Almacén QA Catálogos A', $userId);
        $warehouseBId = $this->createWarehouse($companyBId, 'QATPCATB', 'Almacén QA Catálogos B', $userId);
        $warehouseOutId = $this->createWarehouse($companyOutId, 'QATPCATO', 'Almacén QA Catálogos Sin Scope', $userId);
        $warehouseAOutScopeId = $this->createWarehouse(
            $companyAId,
            'QATPCATX',
            'Almacén QA Catálogos A Sin Scope',
            $userId
        );
        $this->assignScope($userId, $companyAId, $warehouseAId);
        $this->assignScope($userId, $companyBId, $warehouseBId);
        $brandId = $this->createBrand($userId);
        $unitId = $this->createSatUnit($userId);
        $satKeyId = $this->createSatKey($userId);
        $this->createSatKey($userId, '99123457', 'Clave SAT Ambigua QA');
        $this->createSatKey($userId, '99123458', 'Clave SAT Ambigua QA');
        $currencyId = $this->currencyId();

        return [
            'user_id' => $userId,
            'company_a_id' => $companyAId,
            'company_b_id' => $companyBId,
            'company_out_id' => $companyOutId,
            'warehouse_a_id' => $warehouseAId,
            'warehouse_b_id' => $warehouseBId,
            'warehouse_out_id' => $warehouseOutId,
            'warehouse_a_out_scope_id' => $warehouseAOutScopeId,
            'brand_id' => $brandId,
            'unit_id' => $unitId,
            'sat_key_id' => $satKeyId,
            'currency_id' => $currencyId,
        ];
    }

    private function controllerFor(int $userId): ProductRequestTicketController
    {
        $this->closeSession();
        session_save_path(sys_get_temp_dir());
        session_name('TP_CAT_' . bin2hex(random_bytes(4)));

        if (!session_start()) {
            throw new RuntimeException('Unable to start create catalog test session.');
        }

        $_SESSION['auth_user'] = [
            'user_id' => $userId,
            'username' => 'qa.tp.create.catalogos',
            'email' => 'qa.tp.create.catalogos@example.test',
        ];

        $connection = $GLOBALS['tp_product_ticket_create_catalogos_connection'];
        $repository = new ProductRequestTicketRepository($connection);

        return new ProductRequestTicketController(
            new AuthService(new UserRepository($connection), new Session([])),
            new ProductRequestTicketService($repository),
            new PermissionService(new PermissionRepository($connection)),
            $repository
        );
    }

    private function createUser(string $username): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo, creado_en)
             VALUES (:username, :email, :password_hash, 1, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash($username, PASSWORD_DEFAULT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function assignAdminRole(int $userId): void
    {
        $roleId = $this->activeAdminRoleId();

        if ($roleId < 1) {
            throw new RuntimeException(
                'ADMIN role is required for TP-PARTIDAS-ESTADOS-CREATE-CATALOGOS-1.'
            );
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'rol_id' => $roleId,
        ]);
    }

    private function activeAdminRoleId(): int
    {
        if (!$this->tableExists('roles')) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            "SELECT id FROM roles WHERE codigo = 'ADMIN' AND activo = 1 AND eliminado_en IS NULL LIMIT 1"
        );
        $statement->execute();

        return (int) ($statement->fetchColumn() ?: 0);
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

    private function createBrand(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO marcas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => 'QATPCAT',
            'nombre' => 'Marca QA Catálogos',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSatUnit(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO unidades_sat (codigo, nombre, descripcion, creado_por)
             VALUES (:codigo, :nombre, :descripcion, :creado_por)'
        );
        $statement->execute([
            'codigo' => 'QATPCAT1',
            'nombre' => 'Pieza QA Catálogos',
            'descripcion' => 'Unidad de prueba para selector',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSatKey(
        int $userId,
        string $code = '99123456',
        string $description = 'Clave QA Catálogos'
    ): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO claves_sat (codigo, descripcion, creado_por)
             VALUES (:codigo, :descripcion, :creado_por)'
        );
        $statement->execute([
            'codigo' => $code,
            'descripcion' => $description,
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function currencyId(): int
    {
        $id = (int) $this->pdo->query(
            "SELECT id FROM monedas WHERE codigo = 'MXN' ORDER BY id LIMIT 1"
        )->fetchColumn();

        if ($id <= 0) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-CREATE-CATALOGOS-1 requires MXN currency.'
            );
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function latestPartida(): array
    {
        $row = $this->pdo->query(
            'SELECT *
             FROM tickets_productos_partidas
             ORDER BY id DESC
             LIMIT 1'
        )->fetch();

        if (!is_array($row)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-CREATE-CATALOGOS-1 did not create a test line.'
            );
        }

        return $row;
    }

    private function ticketCount(): int
    {
        if (!$this->tableExists('tickets_productos')) {
            return 0;
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM tickets_productos')->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function operationalCounts(): array
    {
        $tables = [
            'productos',
            'producto_precios',
            'existencias_producto',
            'compras',
            'proveedores',
        ];
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = $this->tableExists($table)
                ? (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn()
                : 0;
        }

        return $counts;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(BASE_PATH . '/' . $path);

        if (!is_string($contents)) {
            throw new RuntimeException('Could not read ' . $path);
        }

        return $contents;
    }

    private function fileContains(string $path, string $needle): bool
    {
        return str_contains($this->read($path), $needle);
    }

    /**
     * @param array<string, mixed> $results
     */
    private function allTrue(array $results): bool
    {
        foreach ($results as $value) {
            if (is_array($value)) {
                if (!$this->allTrue($value)) {
                    return false;
                }

                continue;
            }

            if ($value !== true) {
                return false;
            }
        }

        return true;
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }
    }
};
