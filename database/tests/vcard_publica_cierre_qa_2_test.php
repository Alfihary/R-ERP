<?php

declare(strict_types=1);

use App\Core\Config;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        $connection = $GLOBALS['vcard_publica_cierre_qa_2_connection'];
        $config = $GLOBALS['vcard_publica_cierre_qa_2_config'];

        if (!$connection instanceof ConnectionProvider || !$config instanceof Config) {
            throw new RuntimeException('VCARD-PUBLICA-CIERRE-QA-2 globals are not configured.');
        }

        $results = [
            'static_contract' => $this->staticContract(),
            'documentation' => $this->documentationContract(),
            'aggregated_regressions' => $this->runAggregatedRegressions($connection, $config, $pdo, $expectedDatabase),
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'VCARD-PUBLICA-CIERRE-QA-2 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'public_routes' => [
                'GET /v/{slug}',
                'GET /v/{slug}/foto',
                'GET /v/{slug}/vcf',
                'GET /v/{slug}/qr',
                'GET /v/{slug}/productos',
                'GET /v/{slug}/productos/{id_producto}/imagen',
                'GET /credencial/verificar/{token}',
            ],
            'private_routes' => [
                'GET /perfil',
                'POST /perfil/vcard/configuracion',
                'POST /perfil/vcard/privacidad',
                'POST /perfil/vcard/publicar',
                'POST /perfil/vcard/despublicar',
                'POST /perfil/vcard/productos/agregar',
                'POST /perfil/vcard/productos/actualizar',
                'POST /perfil/vcard/productos/quitar',
                'GET /perfil/credencial',
                'GET /perfil/credencial/qr',
                'GET /perfil/credencial/qr/descargar',
            ],
            'security_contract' => [
                'no_password_hash',
                'no_token',
                'no_token_hash',
                'no_storage_uploads',
                'no_physical_paths',
                'no_price_stock_cost_supplier_warehouse',
                'no_relation_internal_ids',
            ],
            'cleanup' => 'delegated_tests_rolled_back_transient_data',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function staticContract(): array
    {
        $routes = $this->read('routes/web.php');
        $view = $this->read('app/Views/vcards/public.php');
        $css = $this->read('public/css/modules/vcard-public.css');

        return [
            'public_vcard_route_declared' => $this->routeDeclared($routes, '/v/{slug}'),
            'public_photo_route_declared' => $this->routeDeclared($routes, '/v/{slug}/foto'),
            'public_vcf_route_declared' => $this->routeDeclared($routes, '/v/{slug}/vcf'),
            'public_qr_route_declared' => $this->routeDeclared($routes, '/v/{slug}/qr'),
            'public_products_route_declared' => $this->routeDeclared($routes, '/v/{slug}/productos'),
            'public_product_image_route_declared' => $this->routeDeclared(
                $routes,
                '/v/{slug}/productos/{id_producto}/imagen'
            ),
            'credential_verification_route_declared' => $this->routeDeclared(
                $routes,
                '/credencial/verificar/{token}'
            ),
            'private_profile_routes_declared' =>
                $this->routeDeclared($routes, '/perfil')
                && $this->routeDeclared($routes, '/perfil/vcard/configuracion')
                && $this->routeDeclared($routes, '/perfil/vcard/privacidad')
                && $this->routeDeclared($routes, '/perfil/vcard/publicar')
                && $this->routeDeclared($routes, '/perfil/vcard/despublicar')
                && $this->routeDeclared($routes, '/perfil/vcard/productos/agregar')
                && $this->routeDeclared($routes, '/perfil/vcard/productos/actualizar')
                && $this->routeDeclared($routes, '/perfil/vcard/productos/quitar')
                && $this->routeDeclared($routes, '/perfil/credencial')
                && $this->routeDeclared($routes, '/perfil/credencial/qr')
                && $this->routeDeclared($routes, '/perfil/credencial/qr/descargar'),
            'public_profile_section_removed' =>
                !str_contains($view, '<h2>Perfil público</h2>')
                && !str_contains($view, 'No hay datos adicionales publicados.'),
            'mobile_products_action_exists' =>
                str_contains($view, "'label' => 'Productos'")
                && str_contains($view, "'type' => 'products-mobile'")
                && str_contains($view, '/productos'),
            'products_preview_section_marked' => str_contains($view, 'vcard-public__section--products-preview'),
            'desktop_products_link_copy_present' => str_contains($view, 'Ver todos los productos'),
            'products_action_hidden_by_default' =>
                str_contains($css, '.vcard-public__action--products-mobile')
                && str_contains($css, 'display: none;'),
            'products_action_visible_on_mobile' =>
                str_contains($css, '@media (max-width: 46rem)')
                && str_contains($css, 'display: inline-flex;'),
            'products_preview_hidden_on_mobile' =>
                str_contains($css, '.vcard-public__section--products-preview')
                && str_contains($css, 'display: none;'),
            'public_vcard_assets_declared' =>
                str_contains($css, '/img/vcard/fondo-hvac-vectorial.webp')
                && str_contains($view, '/img/vcard/gr-logo-vcard.png')
                && str_contains($view, '/img/vcard/grupo-refrigerantes-logo-vcard.png'),
            'view_does_not_reference_storage_uploads' => !str_contains($view, 'storage/uploads'),
            'view_does_not_reference_credential_verification' => !str_contains($view, '/credencial/verificar/'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function documentationContract(): array
    {
        $doc = $this->read('docs/vcard-publica-cierre-qa-2.md');

        $requiredCommits = [
            'd4d9e40 fix(vcard): add private product linking for public vcard',
            '8412e0f feat(vcard): add dedicated vcard products permission',
            '55757a7 test(vcard): verify jesus product permission access',
            'bea9371 feat(vcard): add public products listing page',
            '26055ab feat(vcard): add secure public product images',
            'c2e6003 feat(vcard): add whatsapp product inquiry cta',
            'c8b9862 style(vcard): compact product whatsapp cta',
            'bf14397 style(vcard): remove public profile section',
            '6f7ff0b style(vcard): redesign public vcard layout',
        ];

        $publicRoutes = [
            'GET /v/{slug}',
            'GET /v/{slug}/foto',
            'GET /v/{slug}/vcf',
            'GET /v/{slug}/qr',
            'GET /v/{slug}/productos',
            'GET /v/{slug}/productos/{id_producto}/imagen',
            'GET /credencial/verificar/{token}',
        ];

        $privateRoutes = [
            'GET /perfil',
            'POST /perfil/vcard/configuracion',
            'POST /perfil/vcard/privacidad',
            'POST /perfil/vcard/publicar',
            'POST /perfil/vcard/despublicar',
            'POST /perfil/vcard/productos/agregar',
            'POST /perfil/vcard/productos/actualizar',
            'POST /perfil/vcard/productos/quitar',
            'GET /perfil/credencial',
            'GET /perfil/credencial/qr',
            'GET /perfil/credencial/qr/descargar',
        ];

        $docSearch = mb_strtolower($doc, 'UTF-8');

        return [
            'documents_objective' => str_contains($doc, 'cierre QA general del bloque vCard pública'),
            'documents_recent_commits' => $this->containsAll($doc, $requiredCommits),
            'documents_desktop_contract' =>
                str_contains($docSearch, 'desktop / tablet amplia')
                && str_contains($docSearch, 'preview máximo de 4 productos')
                && str_contains($docSearch, 'ver todos los productos'),
            'documents_mobile_contract' =>
                str_contains($docSearch, 'móvil / celular')
                && str_contains($docSearch, 'acción principal')
                && str_contains($docSearch, 'productos')
                && str_contains($docSearch, 'se oculta la sección'),
            'documents_public_products_contract' =>
                str_contains($docSearch, 'productos públicos')
                && str_contains($docSearch, 'precio mínimo')
                && str_contains($docSearch, 'stock')
                && str_contains($docSearch, 'ids internos')
                && str_contains($docSearch, 'pieza'),
            'documents_public_images_contract' =>
                str_contains($docSearch, 'imágenes públicas de producto')
                && str_contains($doc, '/v/{slug}/productos/{id_producto}/imagen'),
            'documents_whatsapp_contract' =>
                str_contains($doc, 'CTA WhatsApp')
                && str_contains($doc, 'https://wa.me/'),
            'documents_qr_vcf_photo_contract' =>
                str_contains($doc, 'QR / VCF / foto pública')
                && str_contains($doc, 'VCF no incluye productos'),
            'documents_forbidden_fields' =>
                str_contains($doc, 'password_hash')
                && str_contains($doc, 'token_hash')
                && str_contains($doc, 'storage/uploads')
                && str_contains($doc, 'precio')
                && str_contains($doc, 'stock')
                && str_contains($doc, 'costo'),
            'documents_public_routes' => $this->containsAll($doc, $publicRoutes),
            'documents_private_routes' => $this->containsAll($doc, $privateRoutes),
            'documents_residual_risks' => str_contains($doc, 'Riesgos residuales'),
            'documents_tests' => str_contains($doc, 'Pruebas ejecutadas'),
            'documents_pending' => str_contains($doc, 'Pendientes sugeridos'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function runAggregatedRegressions(
        ConnectionProvider $connection,
        Config $config,
        PDO $pdo,
        string $expectedDatabase
    ): array {
        $tests = [
            'vcard_publica_redistribucion_layout' => [
                'path' => 'database/tests/vcard_publica_redistribucion_layout_1_test.php',
                'globals' => ['vcard_publica_redistribucion_layout_connection', 'vcard_publica_redistribucion_layout_config'],
            ],
            'vcard_publica_quitar_perfil_publico' => [
                'path' => 'database/tests/vcard_publica_quitar_perfil_publico_1_test.php',
                'globals' => ['vcard_publica_quitar_perfil_publico_connection', 'vcard_publica_quitar_perfil_publico_config'],
            ],
            'vcard_productos_whatsapp_cta' => [
                'path' => 'database/tests/vcard_productos_whatsapp_cta_1_test.php',
                'globals' => ['vcard_productos_whatsapp_cta_connection', 'vcard_productos_whatsapp_cta_config'],
            ],
            'vcard_productos_imagen_publica_ui' => [
                'path' => 'database/tests/vcard_productos_imagen_publica_ui_1_test.php',
                'globals' => ['vcard_productos_imagen_publica_ui_connection', 'vcard_productos_imagen_publica_ui_config'],
            ],
            'vcard_publica_ui_productos' => [
                'path' => 'database/tests/vcard_publica_ui_productos_1_test.php',
                'globals' => ['vcard_publica_ui_productos_connection', 'vcard_publica_ui_productos_config'],
            ],
            'vcard_publico' => [
                'path' => 'database/tests/vcard_publico_seguridad_1_test.php',
                'globals' => ['vcard_publico_connection', 'vcard_publico_config'],
            ],
            'vcard_productos' => [
                'path' => 'database/tests/vcard_productos_1_test.php',
                'globals' => ['vcard_productos_connection', 'vcard_productos_config'],
            ],
            'vcard_qr' => [
                'path' => 'database/tests/vcard_qr_1_test.php',
                'globals' => ['vcard_qr_connection', 'vcard_qr_config'],
            ],
            'vcard_vcf' => [
                'path' => 'database/tests/vcard_vcf_1_test.php',
                'globals' => ['vcard_vcf_connection', 'vcard_vcf_config'],
            ],
            'vcard_foto_publica' => [
                'path' => 'database/tests/vcard_foto_publica_1_test.php',
                'globals' => ['vcard_foto_publica_connection', 'vcard_foto_publica_config'],
            ],
            'permisos_vcard_productos' => [
                'path' => 'database/tests/permisos_vcard_productos_1_test.php',
                'globals' => ['permisos_vcard_productos_connection', 'permisos_vcard_productos_config'],
            ],
            'jesus_vcard_productos_permission' => [
                'path' => 'database/tests/jesus_vcard_productos_permission_1_test.php',
                'globals' => ['jesus_vcard_productos_connection', 'jesus_vcard_productos_config'],
            ],
            'credencial_visual' => [
                'path' => 'database/tests/credencial_visual_1_test.php',
                'globals' => ['credencial_visual_connection', 'credencial_visual_config'],
            ],
            'credencial_token_qr' => [
                'path' => 'database/tests/credencial_token_qr_1_test.php',
                'globals' => ['credencial_token_qr_connection', 'credencial_token_qr_config'],
            ],
            'credencial_cierre_qa' => [
                'path' => 'database/tests/credencial_cierre_qa_1_test.php',
                'globals' => ['credencial_cierre_qa_connection', 'credencial_cierre_qa_config'],
            ],
        ];

        $results = [];

        foreach ($tests as $name => $definition) {
            foreach ($definition['globals'] as $globalName) {
                $GLOBALS[$globalName] = str_ends_with($globalName, '_config') ? $config : $connection;
            }

            $test = require BASE_PATH . '/' . $definition['path'];

            if (!$test instanceof DatabaseTest) {
                throw new RuntimeException($name . ' regression test has an invalid contract.');
            }

            $result = $test->run($pdo, $expectedDatabase);
            $results[$name] = is_array($result);
        }

        return $results;
    }

    /**
     * @param array<mixed> $needles
     */
    private function containsAll(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!is_string($needle) || !str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(BASE_PATH . '/' . $relativePath);

        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read ' . $relativePath);
        }

        return $contents;
    }

    private function routeDeclared(string $routes, string $route): bool
    {
        $normalize = static function (string $value): string {
            return str_replace(["'", '"', '.', ' ', "\r", "\n", "\t"], '', $value);
        };

        return str_contains($normalize($routes), $normalize($route));
    }

    /**
     * @param array<mixed> $value
     */
    private function allTrue(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                if (!$this->allTrue($item)) {
                    return false;
                }

                continue;
            }

            if ($item !== true && $item !== null) {
                return false;
            }
        }

        return true;
    }
};
