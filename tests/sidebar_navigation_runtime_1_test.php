<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/bootstrap/autoload.php';
require BASE_PATH . '/app/Support/Security/helpers.php';

if (!defined('APP_PATH')) {
    define('APP_PATH', BASE_PATH . '/app');
}

session_name('sidebar_runtime_test');
session_save_path(sys_get_temp_dir());
session_id('sidebarruntime' . bin2hex(random_bytes(8)));
session_start();

try {
    $session = new App\Core\Session([
        'name' => 'sidebar_runtime_test',
        'same_site' => 'Lax',
        'secure' => false,
    ]);
    $csrf = new App\Support\Security\CsrfTokenService($session);

    $html = App\Core\View::render('layouts/app', [
        'activeNavigation' => 'inventory-stock',
        'appName' => 'SoporteGR ERP',
        'contentData' => [],
        'contentView' => 'errors/404',
        'context' => [],
        'csrf' => $csrf,
        'user' => ['username' => 'limited', 'email' => 'limited@example.invalid'],
        'canAccessInventoryStock' => true,
    ]);

    if (!str_contains($html, 'href="/inventario/existencias"')) {
        throw new RuntimeException('A permitted inventory link was not rendered.');
    }

    foreach ([
        '/admin/correo',
        '/admin/correo/cola',
        '/auditoria',
        '/configuracion/empresas',
        '/configuracion/almacenes',
        '/inventario/kardex',
    ] as $forbiddenPath) {
        if (str_contains($html, 'href="' . $forbiddenPath . '"')) {
            throw new RuntimeException('A forbidden limited-user link was rendered: ' . $forbiddenPath);
        }
    }

    if (substr_count($html, 'aria-current="page"') !== 1
        || !str_contains($html, 'href="/inventario/existencias"')
    ) {
        throw new RuntimeException('Limited-user active state is invalid.');
    }

    echo 'PASS limited_render permitted=1 forbidden=6 active=1' . PHP_EOL;
} finally {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
