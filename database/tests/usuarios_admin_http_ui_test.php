<?php

declare(strict_types=1);

/**
 * Static HTTP/UI contract test. It deliberately performs no DB connection or
 * mutation; destructive HTTP QA belongs to an isolated fixture phase.
 */
$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$routes = $read('routes/web.php');
$controller = $read('app/Http/Controllers/AdminUserController.php');
$layout = $read('app/Views/layouts/app.php');
$sidebar = $read('app/Domain/Navigation/SidebarNavigationService.php');
$index = $read('app/Views/admin/users/index.php');
$form = $read('app/Views/admin/users/form.php');

$assertions = [
    'ROUTES_PRESENT' => str_contains($routes, "'/admin/usuarios'")
        && str_contains($routes, "'/admin/usuarios/{id}/editar'")
        && str_contains($routes, "'/admin/usuarios/{id}/estado'")
        && str_contains($routes, "'/admin/usuarios/{id}/eliminar'")
        && str_contains($routes, "'/admin/usuarios/{id}/roles'")
        && str_contains($routes, "'/admin/usuarios/{id}/password'"),
    'PERMISSION_MIDDLEWARE_PRESENT' => substr_count($routes, 'usuarios.') >= 6
        && str_contains($routes, 'new PermissionMiddleware'),
    'CONTROLLER_NO_SQL' => !str_contains($controller, 'SELECT ')
        && !str_contains($controller, 'INSERT ')
        && !str_contains($controller, 'password_hash('),
    'ACTOR_FROM_SESSION_ONLY' => str_contains($controller, 'actorId()')
        && !str_contains($controller, "input('actor_user_id'"),
    'CSRF_MUTATION_COVERAGE' => substr_count($form, 'csrf_field($csrf)') >= 3
        && substr_count($index, 'csrf_field($csrf)') >= 2,
    'PRG_MUTATION_COVERAGE' => substr_count($controller, 'Response::redirect(') >= 6,
    'OUTPUT_ESCAPING_REVIEW' => str_contains($index, 'e(') && str_contains($form, 'e('),
    'PASSWORD_SECRET_SAFE' => !str_contains($index, 'password_hash')
        && !str_contains($form, 'password_hash')
        && str_contains($controller, 'unset($body[\'password\']'),
    'SIDEBAR_PERMISSION_VISIBILITY' => str_contains($sidebar, "'usuarios.acceder'")
        && str_contains($layout, '$canAccessAdminUsers'),
];

$failed = array_keys(array_filter($assertions, static fn (bool $pass): bool => !$pass));
foreach ($assertions as $name => $pass) {
    echo $name . '=' . ($pass ? 'PASS' : 'FAIL') . PHP_EOL;
}
echo 'HTTP_EXECUTION=NOT_EXECUTED' . PHP_EOL;
echo 'DB_READS=0' . PHP_EOL;
echo 'DB_WRITES=0' . PHP_EOL;
echo 'QA_DATA_RESIDUALS=0' . PHP_EOL;
exit($failed === [] ? 0 : 1);
