<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

$routes = file_get_contents(__DIR__ . '/../routes/web.php');
$familyController = file_get_contents(__DIR__ . '/../app/Http/Controllers/ProductFamilyController.php');
$attributeController = file_get_contents(__DIR__ . '/../app/Http/Controllers/ProductAttributeController.php');
$variantController = file_get_contents(__DIR__ . '/../app/Http/Controllers/ProductVariantController.php');
$familyView = file_get_contents(__DIR__ . '/../app/Views/products/variants/families.php');
$attributeView = file_get_contents(__DIR__ . '/../app/Views/products/variants/attributes.php');
$variantView = file_get_contents(__DIR__ . '/../app/Views/products/variants/index.php');
$seed = file_get_contents(__DIR__ . '/../database/seeds/productos_variantes_1_seed_permissions.php');

$checks = [
    'HTTP_FAMILY_ROUTES_PRESENT' => $routes !== false && substr_count($routes, '/productos/familias') >= 3,
    'HTTP_ATTRIBUTE_ROUTES_PRESENT' => $routes !== false && substr_count($routes, '/productos/atributos') >= 3,
    'HTTP_VARIANT_ROUTES_PRESENT' => $routes !== false && substr_count($routes, '/productos/variantes') >= 4,
    'HTTP_PERMISSION_GUARDS_PRESENT' => $routes !== false && substr_count($routes, 'productos.variantes.') >= 2,
    'HTTP_CONTROLLER_SERVICES_WIRED' => $familyController !== false && $attributeController !== false && $variantController !== false
        && str_contains($familyController, 'ProductFamilyService') && str_contains($attributeController, 'ProductAttributeService')
        && str_contains($variantController, 'ProductVariantService'),
    'HTTP_CSRF_FORMS_PRESENT' => $familyView !== false && $attributeView !== false && $variantView !== false
        && str_contains($familyView, 'csrf_field($csrf)') && str_contains($attributeView, 'csrf_field($csrf)')
        && str_contains($variantView, 'csrf_field($csrf)'),
    'HTTP_NO_MUTATION_GET' => $routes !== false && str_contains($routes, "->post('/productos/variantes")
        && !str_contains($routes, "->get('/productos/variantes/vincular"),
    'RBAC_PERMISSION_SEED_PRESENT' => $seed !== false && substr_count($seed, 'productos.variantes.') === 2,
];

foreach ($checks as $name => $pass) {
    echo $name . '=' . ($pass ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$pass) { exit(1); }
}
