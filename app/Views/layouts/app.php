<?php

declare(strict_types=1);

use App\Core\View;
use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService
    || !is_array($user ?? null)
    || !is_array($scope ?? null)
    || !is_string($contentView ?? null)
) {
    throw new RuntimeException('Authenticated layout context is incomplete.');
}

$content = View::render($contentView, [
    'scope' => $scope,
    'user' => $user,
]);
$appName = is_string($appName ?? null) && $appName !== ''
    ? $appName
    : 'SoporteGR ERP';
$appName = trim($appName, " \t\n\r\0\x0B\"'");
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($pageTitle ?? 'Inicio') ?> · <?= e($appName) ?></title>
    <link rel="stylesheet" href="/css/core/app.css">
</head>
<body class="app-body">
    <a class="skip-link" href="#main-content">Saltar al contenido</a>

    <div class="app-shell">
        <aside class="app-sidebar" aria-label="Navegación principal">
            <a class="app-brand" href="/app" aria-label="<?= e($appName) ?>, inicio">
                <span class="app-brand__mark" aria-hidden="true">SG</span>
                <span>
                    <strong><?= e($appName) ?></strong>
                    <small>Entorno administrativo</small>
                </span>
            </a>

            <nav class="app-navigation" aria-label="Secciones">
                <a class="app-navigation__item is-active" href="/app" aria-current="page">
                    <span aria-hidden="true">⌂</span>
                    Inicio
                </a>
            </nav>

            <p class="app-sidebar__note">
                La navegación crecerá únicamente con módulos aprobados.
            </p>
        </aside>

        <div class="app-workspace">
            <header class="app-topbar">
                <div class="app-topbar__context">
                    <span>Área privada</span>
                    <strong>Inicio</strong>
                </div>

                <div class="app-account">
                    <div class="app-account__identity">
                        <strong><?= e($user['username'] ?? '') ?></strong>
                        <span><?= e($user['email'] ?? '') ?></span>
                    </div>
                    <form method="post" action="/logout">
                        <?= csrf_field($csrf) ?>
                        <button class="button button--secondary" type="submit">
                            Cerrar sesión
                        </button>
                    </form>
                </div>
            </header>

            <main class="app-main" id="main-content" tabindex="-1">
                <?= $content ?>
            </main>
        </div>
    </div>
</body>
</html>
