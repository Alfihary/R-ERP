<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$layout = file_get_contents($root . '/app/Views/layouts/app.php');
$routes = file_get_contents($root . '/routes/web.php');

if ($layout === false || $routes === false) {
    throw new RuntimeException('Sidebar RBAC fixture files could not be read.');
}

$navigationMarkup = preg_match(
    '/<nav\b[^>]*>(.*?)<\/nav>/is',
    $layout,
    $navigationMatch
) === 1
    ? $navigationMatch[1]
    : '';
preg_match_all(
    '/href="(\/[a-z0-9_\-\/{}]+)"/i',
    $navigationMarkup,
    $layoutMatches
);
$sidebarRoutes = $layoutMatches[1] ?? [];

if ($sidebarRoutes === []) {
    throw new RuntimeException('The sidebar must expose at least one route.');
}

if (count($sidebarRoutes) !== count(array_unique($sidebarRoutes))) {
    throw new RuntimeException('The sidebar contains duplicate route links.');
}

preg_match_all(
    '/\$router->(?:get|post|put|patch|delete)\(\s*[\'\"]([^\'\"]+)[\'\"]/i',
    $routes,
    $routeMatches
);
$registeredRoutes = array_unique($routeMatches[1] ?? []);

foreach ($sidebarRoutes as $sidebarRoute) {
    if (!in_array($sidebarRoute, $registeredRoutes, true)) {
        throw new RuntimeException('Sidebar route is not registered: ' . $sidebarRoute);
    }
}

$requiredGroups = [
    'Operación',
    'Inventario',
    'Catálogos',
    'Organización',
    'Administración',
    'Mi cuenta',
];

foreach ($requiredGroups as $group) {
    if (!str_contains($layout, '>' . $group . '<')) {
        throw new RuntimeException('Expected sidebar group is missing: ' . $group);
    }
}

$requiredPermissionCodes = [
    'tickets_productos.ver',
    'inventario.existencias.acceder',
    'inventario.existencias_series.acceder',
    'inventario.kardex.acceder',
    'inventario.kardex_series.acceder',
];

foreach ($requiredPermissionCodes as $permissionCode) {
    if (!str_contains($routes, "'" . $permissionCode . "'")) {
        throw new RuntimeException('Required route permission is missing: ' . $permissionCode);
    }
}

if (!str_contains($layout, '$canAccess')
    || !str_contains($routes, 'new PermissionMiddleware')
    || !str_contains($routes, 'new AuthMiddleware')
) {
    throw new RuntimeException('Sidebar visibility cannot replace route authorization.');
}

echo 'PASS sidebar routes=' . count($sidebarRoutes)
    . ' registered_routes=' . count($registeredRoutes)
    . ' groups=' . count($requiredGroups) . PHP_EOL;
