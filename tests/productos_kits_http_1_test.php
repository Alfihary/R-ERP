<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$routes = file_get_contents($base . '/routes/web.php') ?: '';
$controller = file_get_contents($base . '/app/Http/Controllers/ProductKitController.php') ?: '';
$seed = file_get_contents($base . '/database/seeds/productos_kits_1_seed_permissions.php') ?: '';
$form = file_get_contents($base . '/app/Views/products/form.php') ?: '';
$js = file_get_contents($base . '/public/js/modules/product-kit-search.js') ?: '';
$checks = [
    'ROUTES_PRESENT' => str_contains($routes, '/productos/kits/componentes'),
    'AUTH_AND_PERMISSION_PRESENT' => str_contains($routes, 'productos.kits.editar'),
    'POST_MUTATIONS_PRESENT' => str_contains($routes, "'/productos/kits/componentes/agregar'") && str_contains($routes, "'/productos/kits/componentes/quitar'"),
    'POST_UPDATE_PRESENT' => str_contains($routes, "'/productos/kits/componentes/actualizar'"),
    'NO_GET_MUTATION' => !preg_match("~\$router->get\([^;]+kits/componentes/(?:agregar|actualizar|quitar)~s", $routes),
    'CONTROLLER_WIRING' => str_contains($controller, 'ProductKitService'),
    'SEED_PERMISSIONS' => str_contains($seed, 'productos.kits.ver') && str_contains($seed, 'productos.kits.editar'),
    'KIT_SEARCH_ROOT' => str_contains($form, 'data-product-kit-search') && str_contains($form, 'data-search-url'),
    'KIT_ADD_FORM' => str_contains($form, '/productos/kits/componentes/agregar') && str_contains($form, 'name="componente_id_producto"'),
    'KIT_UPDATE_FORM' => str_contains($form, '/productos/kits/componentes/actualizar'),
    'KIT_REMOVE_FORM' => str_contains($form, '/productos/kits/componentes/quitar'),
    'KIT_CSRF' => substr_count($form, 'csrf_field($csrf)') >= 4,
    'KIT_JS_WIRED' => str_contains($js, "querySelector('[data-product-kit-search]')") && str_contains($js, 'data-kit-selected-id'),
];
$checks['_failed_checks'] = array_keys(array_filter($checks, static fn(bool $value): bool => !$value));
echo json_encode(['HTTP_QA_LEVEL'=>'STATIC_SOURCE_SMOKE_ONLY','result'=>$checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($checks['_failed_checks'] === [] ? 0 : 1);
