<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

$routes = file_get_contents(__DIR__ . '/../routes/web.php');
$controller = file_get_contents(__DIR__ . '/../app/Http/Controllers/ProductSimilarController.php');
$view = file_get_contents(__DIR__ . '/../app/Views/products/form.php');

$checks = [
    'HTTP_ROUTES_PRESENT' => $routes !== false
        && substr_count($routes, '/productos/similares/') === 3,
    'HTTP_PERMISSION_EDIT_PRESENT' => $routes !== false
        && substr_count($routes, "productos.similares.editar") >= 3,
    'HTTP_CONTROLLER_WIRED' => $controller !== false
        && str_contains($controller, 'ProductSimilarService'),
    'HTTP_CSRF_FORM_PRESENT' => $view !== false
        && str_contains($view, 'csrf_field($csrf)'),
    'HTTP_AUTOCOMPLETE_PRESENT' => $view !== false
        && str_contains($view, 'data-similar-search'),
];

foreach ($checks as $name => $pass) {
    echo $name . '=' . ($pass ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$pass) { exit(1); }
}
