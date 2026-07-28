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
$canAccessProducts = ($canAccessProducts ?? false) === true;
$canAccessInventory = ($canAccessInventory ?? false) === true;
$canAccessInventoryStock = ($canAccessInventoryStock ?? false) === true;
$canAccessInventorySerialStock = ($canAccessInventorySerialStock ?? false) === true;
$canAccessInventoryKardex = ($canAccessInventoryKardex ?? false) === true;
$canAccessInventorySerialKardex = ($canAccessInventorySerialKardex ?? false) === true;
$canAccessInventoryTransfers = ($canAccessInventoryTransfers ?? false) === true;
$canAccessConfiguration = ($canAccessConfiguration ?? false) === true;
$canAccessConfigCompanies = ($canAccessConfigCompanies ?? false) === true;
$canAccessConfigWarehouses = ($canAccessConfigWarehouses ?? false) === true;
$canAccessConfigFolios = ($canAccessConfigFolios ?? false) === true;
$canAccessPriceLists = ($canAccessPriceLists ?? false) === true;
$stylesheets = is_array($stylesheets ?? null) ? $stylesheets : [];
$scripts = is_array($scripts ?? null) ? $scripts : [];
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
                <?php if ($canAccessProducts): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'products' ? ' is-active' : '' ?>"
                        href="/productos"
                        <?= $activeNavigation === 'products' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">▤</span>
                        Productos
                    </a>
                <?php endif; ?>
                <?php if ($canAccessInventory): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'inventory' ? ' is-active' : '' ?>"
                        href="/inventario/movimientos"
                        <?= $activeNavigation === 'inventory' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">⇄</span>
                        Inventario · Movimientos
                    </a>
                <?php endif; ?>
                <?php if ($canAccessInventoryStock): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'inventory-stock' ? ' is-active' : '' ?>"
                        href="/inventario/existencias"
                        <?= $activeNavigation === 'inventory-stock' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">≡</span>
                        Inventario · Existencias
                    </a>
                <?php endif; ?>
                <?php if ($canAccessInventorySerialStock): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'inventory-serial-stock' ? ' is-active' : '' ?>"
                        href="/inventario/existencias-series"
                        <?= $activeNavigation === 'inventory-serial-stock' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">#</span>
                        Inventario · Existencias por serie
                    </a>
                <?php endif; ?>
                <?php if ($canAccessInventoryKardex): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'inventory-kardex' ? ' is-active' : '' ?>"
                        href="/inventario/kardex"
                        <?= $activeNavigation === 'inventory-kardex' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">↕</span>
                        Inventario · Kardex
                    </a>
                <?php endif; ?>
                <?php if ($canAccessInventorySerialKardex): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'inventory-serial-kardex' ? ' is-active' : '' ?>"
                        href="/inventario/kardex-series"
                        <?= $activeNavigation === 'inventory-serial-kardex' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">⌁</span>
                        Inventario · Kardex por serie
                    </a>
                <?php endif; ?>
                <?php if ($canAccessInventoryTransfers): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'inventory-transfers' ? ' is-active' : '' ?>"
                        href="/inventario/transferencias"
                        <?= $activeNavigation === 'inventory-transfers' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">⇆</span>
                        Inventario · Transferencias
                    </a>
                <?php endif; ?>
                <?php if ($canAccessConfiguration): ?>
                    <span class="app-navigation__section">Configuración</span>
                <?php endif; ?>
                <?php if ($canAccessConfigCompanies): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'configuration-companies' ? ' is-active' : '' ?>"
                        href="/configuracion/empresas"
                        <?= $activeNavigation === 'configuration-companies' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">▧</span>
                        Empresas
                    </a>
                <?php endif; ?>
                <?php if ($canAccessConfigWarehouses): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'configuration-warehouses' ? ' is-active' : '' ?>"
                        href="/configuracion/almacenes"
                        <?= $activeNavigation === 'configuration-warehouses' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">▣</span>
                        Almacenes
                    </a>
                <?php endif; ?>
                <?php if ($canAccessConfigFolios): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'configuration-folios' ? ' is-active' : '' ?>"
                        href="/configuracion/folios"
                        <?= $activeNavigation === 'configuration-folios' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">№</span>
                        Folios
                    </a>
                <?php endif; ?>
                <?php if ($canAccessPriceLists): ?>
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'configuration-price-lists' ? ' is-active' : '' ?>"
                        href="/configuracion/listas-precios"
                        <?= $activeNavigation === 'configuration-price-lists' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">$</span>
                        Listas de precios
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
    <?php foreach ($scripts as $script): ?>
        <?php if (
            is_string($script)
            && preg_match('#^/js/[a-z0-9/_-]+\.js$#', $script) === 1
        ): ?>
            <script src="<?= e($script) ?>" defer></script>
        <?php endif; ?>
    <?php endforeach; ?>
</body>
</html>
