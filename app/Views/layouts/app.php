<?php

declare(strict_types=1);

use App\Core\View;
use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService
    || !is_array($user ?? null)
    || !is_array($context ?? null)
    || !is_string($contentView ?? null)
) {
    throw new RuntimeException('Authenticated layout context is incomplete.');
}

$contentData = is_array($contentData ?? null) ? $contentData : [];
$content = View::render($contentView, [
    'context' => $context,
    'csrf' => $csrf,
    'user' => $user,
] + $contentData);
$appName = is_string($appName ?? null) && $appName !== ''
    ? $appName
    : 'SoporteGR ERP';
$appName = trim($appName, " \t\n\r\0\x0B\"'");
$activeNavigation = is_string($activeNavigation ?? null)
    ? $activeNavigation
    : 'home';
$canAccessCatalogs = ($canAccessCatalogs ?? false) === true;
$stylesheets = is_array($stylesheets ?? null) ? $stylesheets : [];
$activeCompany = is_array($context['active_company'] ?? null)
    ? $context['active_company']
    : null;
$activeWarehouse = is_array($context['active_warehouse'] ?? null)
    ? $context['active_warehouse']
    : null;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($pageTitle ?? 'Inicio') ?> · <?= e($appName) ?></title>
    <link rel="stylesheet" href="/css/core/app.css">
    <?php foreach ($stylesheets as $stylesheet): ?>
        <?php if (
            is_string($stylesheet)
            && preg_match('#^/css/[a-z0-9/_-]+\.css$#', $stylesheet) === 1
        ): ?>
            <link rel="stylesheet" href="<?= e($stylesheet) ?>">
        <?php endif; ?>
    <?php endforeach; ?>
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
                <a
                    class="app-navigation__item<?= $activeNavigation === 'home' ? ' is-active' : '' ?>"
                    href="/app"
                    <?= $activeNavigation === 'home' ? 'aria-current="page"' : '' ?>
                >
                    <span aria-hidden="true">⌂</span>
                    Inicio
                </a>
                <?php if ($canAccessCatalogs): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'catalogs' ? ' is-active' : '' ?>"
                        href="/catalogos"
                        <?= $activeNavigation === 'catalogs' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">▦</span>
                        Catálogos
                    </a>
                <?php endif; ?>
            </nav>

            <p class="app-sidebar__note">
                La navegación crecerá únicamente con módulos aprobados.
            </p>
        </aside>

        <div class="app-workspace">
            <header class="app-topbar">
                <div class="app-topbar__context">
                    <span>Área privada</span>
                    <strong><?= e($pageTitle ?? 'Inicio') ?></strong>
                    <?php if ($activeCompany !== null && $activeWarehouse !== null): ?>
                        <small>
                            <?= e($activeCompany['name'] ?? '') ?>
                            ·
                            <?= e($activeWarehouse['name'] ?? '') ?>
                        </small>
                    <?php endif; ?>
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
