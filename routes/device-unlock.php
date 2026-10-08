<?php
declare(strict_types=1);

use App\Core\Request;
use App\Core\Router;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Http\Controllers\DeviceUnlockController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\PermissionMiddleware;

return static function (Router $router, AuthService $auth, PermissionService $permissions, DeviceUnlockController $controller): void {
    $normal = [new AuthMiddleware($auth), new PermissionMiddleware($auth, $permissions, 'sistema.app.ver')];
    $locked = [new AuthMiddleware($auth, true, false), new PermissionMiddleware($auth, $permissions, 'sistema.app.ver')];
    $router->get('/desbloquear', [$controller, 'page'], $locked);
    $router->get('/sesion/dispositivo/estado', [$controller, 'status'], $locked);
    $router->post('/sesion/dispositivo/actividad', [$controller, 'activity'], $locked);
    $router->post('/sesion/dispositivo/bloquear', [$controller, 'lock'], $locked);
    foreach (['register' => 'registrar', 'unlock' => 'desbloquear'] as $operation => $path) {
        $middleware = $operation === 'register' ? $normal : $locked;
        $router->post('/sesion/dispositivo/' . $path . '/opciones', static fn (Request $r) => $controller->options($r, $operation), $middleware);
        $router->post('/sesion/dispositivo/' . $path . '/verificar', static fn (Request $r) => $controller->complete($r, $operation), $middleware);
    }
};
