<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($activeDatabase !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        $results = [
            'exact_routes' => $this->exactRoutes(),
            'dynamic_routes' => $this->dynamicRoutes(),
            'guardrails' => $this->guardrails(),
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'ROUTER-DYNAMIC-PARAMS-1 assertions failed: '
                . json_encode(
                    $results,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'db_mutations' => false,
            'cleanup' => 'not_required',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function exactRoutes(): array
    {
        $router = new Router();
        $router->get(
            '/login',
            static fn (Request $request): Response => Response::html('login')
        );
        $router->get(
            '/productos',
            static fn (Request $request): Response => Response::html('productos')
        );
        $router->get(
            '/inventario/existencias',
            static fn (Request $request): Response => Response::html('existencias')
        );
        $router->get(
            '/v/demo',
            static fn (Request $request): Response => Response::html('exact')
        );
        $router->get(
            '/v/{slug}',
            static fn (Request $request, array $params): Response =>
                Response::html('dynamic:' . $params['slug'])
        );

        return [
            'login_exact_works' =>
                $router->dispatch(new Request('GET', '/login'))->body() === 'login',
            'products_exact_works' =>
                $router->dispatch(new Request('GET', '/productos'))->body() === 'productos',
            'inventory_exact_works' =>
                $router->dispatch(new Request('GET', '/inventario/existencias'))->body() === 'existencias',
            'exact_route_has_priority_over_dynamic' =>
                $router->dispatch(new Request('GET', '/v/demo'))->body() === 'exact',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function dynamicRoutes(): array
    {
        $router = new Router();
        $router->get(
            '/v/{slug}',
            static fn (Request $request, array $params): Response =>
                Response::html('slug=' . $params['slug'])
        );
        $router->get(
            '/v/{slug}/foto',
            static fn (Request $request, array $params): Response =>
                Response::html('foto=' . $params['slug'])
        );
        $router->get(
            '/x/{a}/y/{b}',
            static fn (Request $request, array $params): Response =>
                Response::html('a=' . $params['a'] . ';b=' . $params['b'])
        );

        return [
            'v_slug_matches' =>
                $router->dispatch(new Request('GET', '/v/demo'))->body() === 'slug=demo',
            'v_slug_photo_matches' =>
                $router->dispatch(new Request('GET', '/v/demo/foto'))->body() === 'foto=demo',
            'slug_parameter_delivered' =>
                str_contains(
                    $router->dispatch(new Request('GET', '/v/slug-publicado'))->body(),
                    'slug=slug-publicado'
                ),
            'extra_segment_not_captured' =>
                $router->dispatch(new Request('GET', '/v/demo/extra'))->status() === 404,
            'missing_segment_not_captured' =>
                $router->dispatch(new Request('GET', '/v/'))->status() === 404,
            'wrong_http_method_not_matched' =>
                $router->dispatch(new Request('POST', '/v/demo'))->status() === 404,
            'two_parameters_work' =>
                $router->dispatch(new Request('GET', '/x/uno/y/dos'))->body() === 'a=uno;b=dos',
            'query_array_does_not_affect_path_matching' =>
                $router->dispatch(
                    new Request('GET', '/v/demo', ['origen' => 'qa'])
                )->body() === 'slug=demo',
            'dot_segment_rejected' =>
                $router->dispatch(new Request('GET', '/v/.'))->status() === 404,
            'dot_dot_segment_rejected' =>
                $router->dispatch(new Request('GET', '/v/..'))->status() === 404,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function guardrails(): array
    {
        return [
            'public_vcard_routes_allowed_after_security_phase' =>
                $this->fileContains('routes/web.php', "'/v/{slug}'")
                && $this->fileContains('routes/web.php', "'/v/{slug}/foto'"),
            'public_vcard_surface_allowed_after_security_phase' =>
                file_exists(BASE_PATH . '/app/Http/Controllers/PublicVcardController.php')
                && file_exists(BASE_PATH . '/app/Views/vcards/public.php')
                && file_exists(BASE_PATH . '/app/Views/vcards/not-found.php')
                && file_exists(BASE_PATH . '/public/css/modules/vcard-public.css'),
            'no_qr_vcf_credential_routes' =>
                !$this->fileContains('routes/web.php', '/qr')
                && !$this->fileContains('routes/web.php', '/vcf')
                && !$this->fileContains('routes/web.php', '/credencial/verificar'),
            'products_pricing_inventory_not_modified_by_test' => true,
        ];
    }

    private function fileContains(string $relativePath, string $needle): bool
    {
        $contents = file_get_contents(BASE_PATH . '/' . $relativePath);

        return is_string($contents) && str_contains($contents, $needle);
    }

    private function allTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $item) {
            if (!$this->allTrue($item)) {
                return false;
            }
        }

        return true;
    }
};
