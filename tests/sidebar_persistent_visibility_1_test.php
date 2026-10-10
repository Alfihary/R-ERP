<?php

declare(strict_types=1);

use App\Domain\Navigation\SidebarNavigationService;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
require BASE_PATH . '/bootstrap/autoload.php';
require BASE_PATH . '/app/Support/Security/helpers.php';

$admin = SidebarNavigationService::forPermissions(static fn (string $permission): bool => true);
$routes = [
    '/app', '/productos', '/inventario/movimientos', '/inventario/existencias',
    '/inventario/kardex', '/catalogos', '/configuracion/empresas', '/admin/correo',
    '/perfil',
];
$adminSignature = static function (array $navigation): array {
    $items = [['label' => $navigation['root']['label'], 'href' => $navigation['root']['href'], 'permission' => null]];
    foreach ($navigation['groups'] as $group) {
        if ($group['items'] === []) {
            throw new RuntimeException('Empty sidebar group rendered.');
        }
        foreach ($group['items'] as $item) {
            $items[] = ['label' => $item['label'], 'href' => $item['href'], 'permission' => $item['permission']];
        }
    }
    return $items;
};

$expected = $adminSignature($admin);
if (count($expected) !== 21 || count($admin['groups']) !== 6) {
    throw new RuntimeException('ADMIN navigation must contain 20 items, 1 root and 6 groups.');
}
foreach ($routes as $route) {
    if ($adminSignature($admin) !== $expected) {
        throw new RuntimeException("ADMIN navigation drift on {$route}.");
    }
}

// Render the actual layout for each route while keeping the same central
// navigation projection. Only activeNavigation is allowed to vary.
$session = new App\Core\Session([
    'name' => 'sidebar_persistent_visibility_test',
    'same_site' => 'Lax',
    'secure' => false,
]);
session_save_path(sys_get_temp_dir());
$session->start();
$csrf = new App\Support\Security\CsrfTokenService($session);
$navigationProvider = new class($admin) {
    public function __construct(private readonly array $navigation)
    {
    }

    public function forUser(int $userId): array
    {
        return $this->navigation;
    }
};
App\Core\View::setSidebarNavigation($navigationProvider);
$renderedSignatures = [];
foreach (array_merge($routes, ['/inventario/existencias-series', '/productos']) as $route) {
    $active = match ($route) {
        '/app' => 'home',
        '/productos' => 'products',
        '/inventario/movimientos' => 'inventory',
        '/inventario/existencias' => 'inventory-stock',
        '/inventario/existencias-series' => 'inventory-serial-stock',
        '/inventario/kardex' => 'inventory-kardex',
        '/catalogos' => 'catalogs',
        '/configuracion/empresas' => 'configuration-companies',
        '/admin/correo' => 'configuration-mail',
        '/perfil' => 'profile',
        default => 'home',
    };
    $html = App\Core\View::render('layouts/app', [
        'activeNavigation' => $active,
        'appName' => 'SoporteGR ERP',
        'contentData' => [],
        'contentView' => 'errors/404',
        'context' => [],
        'csrf' => $csrf,
        'user' => ['user_id' => 1, 'username' => 'qa', 'email' => 'qa@example.invalid'],
    ]);
    preg_match('/<nav class="app-navigation".*?<\/nav>/s', $html, $navMatch);
    preg_match_all('/href="([^"]+)"/i', $navMatch[0] ?? '', $hrefMatch);
    $renderedSignatures[] = $hrefMatch[1] ?? [];
}
foreach ($renderedSignatures as $index => $signature) {
    if ($signature !== ($renderedSignatures[0] ?? [])) {
        throw new RuntimeException('Rendered sidebar href set drift at route index ' . $index . '.');
    }
}
if (count($renderedSignatures[0] ?? []) !== 21) {
    throw new RuntimeException('Rendered ADMIN layout must contain 20 group links plus root.');
}
$limited = SidebarNavigationService::forPermissions(
    static fn (string $permission): bool => $permission === 'inventario.existencias.acceder'
);
$limitedSignature = $adminSignature($limited);
if ($limitedSignature !== [['label' => 'Inicio', 'href' => '/app', 'permission' => null], ['label' => 'Existencias', 'href' => '/inventario/existencias', 'permission' => 'inventario.existencias.acceder']]) {
    throw new RuntimeException('Limited navigation does not match the authorized subset.');
}
if (count($limited['groups']) !== 1 || $limited['groups'][0]['id'] !== 'inventory') {
    throw new RuntimeException('Limited navigation rendered an unexpected group.');
}

echo 'PASS sidebar persistent visibility routes=' . count($routes)
    . ' admin_items=' . count($expected)
    . ' admin_groups=' . count($admin['groups'])
    . ' limited_items=' . count($limitedSignature) . PHP_EOL;
