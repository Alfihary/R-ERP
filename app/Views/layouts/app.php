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
$activeSidebarGroup = match ($activeNavigation) {
    'product-tickets' => 'operation',
    'products', 'product-prices', 'inventory', 'inventory-stock',
    'inventory-serial-stock', 'inventory-kardex', 'inventory-serial-kardex',
    'inventory-transfers', 'configuration-price-lists' => 'inventory',
    'catalogs' => 'catalogs',
    'configuration-companies', 'configuration-warehouses' => 'organization',
    'configuration-folios', 'configuration-mail', 'mail-outbox', 'audit', 'admin-users' => 'administration',
    'profile', 'credential' => 'account',
    default => null,
};
$centralSidebar = View::sidebarNavigationForUser((int) ($user['user_id'] ?? 0));
if (is_array($centralSidebar)) {
    $sidebarPermissions = is_array($centralSidebar['permissions'] ?? null)
        ? $centralSidebar['permissions']
        : [];
    $canAccessProfile = ($sidebarPermissions['perfil.ver'] ?? false) === true;
    $canAccessCredential = ($sidebarPermissions['credencial.ver'] ?? false) === true;
    $canAccessCatalogs = ($sidebarPermissions['catalogos.acceder'] ?? false) === true;
    $canAccessProducts = ($sidebarPermissions['productos.acceder'] ?? false) === true;
    $canAccessProductPrices = ($sidebarPermissions['precios.productos.acceder'] ?? false) === true;
    $canAccessProductTickets = ($sidebarPermissions['tickets_productos.ver'] ?? false) === true;
    $canAccessInventory = ($sidebarPermissions['inventario.movimientos.acceder'] ?? false) === true;
    $canAccessInventoryStock = ($sidebarPermissions['inventario.existencias.acceder'] ?? false) === true;
    $canAccessInventorySerialStock = ($sidebarPermissions['inventario.existencias_series.acceder'] ?? false) === true;
    $canAccessInventoryKardex = ($sidebarPermissions['inventario.kardex.acceder'] ?? false) === true;
    $canAccessInventorySerialKardex = ($sidebarPermissions['inventario.kardex_series.acceder'] ?? false) === true;
    $canAccessInventoryTransfers = ($sidebarPermissions['inventario.transferencias.acceder'] ?? false) === true;
    $canAccessConfigCompanies = ($sidebarPermissions['configuracion.empresas.acceder'] ?? false) === true;
    $canAccessConfigWarehouses = ($sidebarPermissions['configuracion.almacenes.acceder'] ?? false) === true;
    $canAccessConfigFolios = ($sidebarPermissions['configuracion.folios.acceder'] ?? false) === true;
    $canAccessPriceLists = ($sidebarPermissions['precios.listas.acceder'] ?? false) === true;
    $canAccessMailConfiguration = ($sidebarPermissions['configuracion.correo.administrar'] ?? false) === true;
    $canAccessMailOutbox = ($sidebarPermissions['correos.cola.ver'] ?? false) === true;
    $canAccessAudit = ($sidebarPermissions['auditoria.ver'] ?? false) === true;
    $canAccessAdminUsers = ($sidebarPermissions['usuarios.acceder'] ?? false) === true;
}
$canAccessProfile = ($canAccessProfile ?? false) === true;
$canAccessCredential = ($canAccessCredential ?? false) === true;
$canAccessCatalogs = ($canAccessCatalogs ?? false) === true;
$canAccessProducts = ($canAccessProducts ?? false) === true;
$canAccessProductPrices = ($canAccessProductPrices ?? false) === true;
$canAccessProductTickets = ($canAccessProductTickets ?? false) === true;
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
$canAccessMailConfiguration = ($canAccessMailConfiguration ?? false) === true;
$canAccessMailOutbox = ($canAccessMailOutbox ?? false) === true;
$canAccessAudit = ($canAccessAudit ?? false) === true;
$canAccessAdminUsers = ($canAccessAdminUsers ?? false) === true;
$stylesheets = is_array($stylesheets ?? null) ? $stylesheets : [];
$scripts = is_array($scripts ?? null) ? $scripts : [];
if (!in_array('/js/sidebar-collapse.js', $scripts, true)) {
    $scripts[] = '/js/sidebar-collapse.js';
}
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
                <?php $showAccount = $canAccessProfile || $canAccessCredential; ?>
                <?php $showOperation = $canAccessProductTickets; ?>
                <?php $showInventory = $canAccessProducts
                    || $canAccessProductPrices
                    || $canAccessInventory
                    || $canAccessInventoryStock
                    || $canAccessInventorySerialStock
                    || $canAccessInventoryKardex
                    || $canAccessInventorySerialKardex
                    || $canAccessInventoryTransfers
                    || $canAccessPriceLists; ?>
                <?php $showOrganization = $canAccessConfigCompanies || $canAccessConfigWarehouses; ?>
                <?php $showAdministration = $canAccessConfigFolios
                    || $canAccessMailConfiguration
                    || $canAccessMailOutbox
                    || $canAccessAudit
                    || $canAccessAdminUsers; ?>

                <?php if ($showOperation): ?>
                    <section class="app-navigation__group<?= $activeSidebarGroup === 'operation' ? ' has-active-item' : '' ?>" data-sidebar-group="operation">
                        <button class="app-navigation__toggle" type="button" aria-expanded="true" aria-controls="sidebar-group-operation">
                            <span class="app-navigation__section">Operación</span>
                            <span class="app-navigation__chevron" aria-hidden="true">⌄</span>
                        </button>
                        <div class="app-navigation__group-content" id="sidebar-group-operation">
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'product-tickets' ? ' is-active' : '' ?>"
                        href="/tickets/productos"
                        <?= $activeNavigation === 'product-tickets' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">✉</span>
                        Tickets de producto
                    </a>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($showInventory): ?>
                    <section class="app-navigation__group<?= $activeSidebarGroup === 'inventory' ? ' has-active-item' : '' ?>" data-sidebar-group="inventory">
                        <button class="app-navigation__toggle" type="button" aria-expanded="true" aria-controls="sidebar-group-inventory">
                            <span class="app-navigation__section">Inventario</span>
                            <span class="app-navigation__chevron" aria-hidden="true">⌄</span>
                        </button>
                        <div class="app-navigation__group-content" id="sidebar-group-inventory">
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
                    <?php if ($canAccessProductPrices): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'product-prices' ? ' is-active' : '' ?>"
                            href="/precios/productos"
                            <?= $activeNavigation === 'product-prices' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">$</span>
                            Precios por producto
                        </a>
                    <?php endif; ?>
                    <?php if ($canAccessInventory): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'inventory' ? ' is-active' : '' ?>"
                            href="/inventario/movimientos"
                            <?= $activeNavigation === 'inventory' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">⇄</span>
                            Movimientos
                        </a>
                    <?php endif; ?>
                    <?php if ($canAccessInventoryStock): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'inventory-stock' ? ' is-active' : '' ?>"
                            href="/inventario/existencias"
                            <?= $activeNavigation === 'inventory-stock' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">≡</span>
                            Existencias
                        </a>
                    <?php endif; ?>
                    <?php if ($canAccessInventorySerialStock): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'inventory-serial-stock' ? ' is-active' : '' ?>"
                            href="/inventario/existencias-series"
                            <?= $activeNavigation === 'inventory-serial-stock' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">#</span>
                            Existencias por serie
                        </a>
                    <?php endif; ?>
                    <?php if ($canAccessInventoryKardex): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'inventory-kardex' ? ' is-active' : '' ?>"
                            href="/inventario/kardex"
                            <?= $activeNavigation === 'inventory-kardex' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">↕</span>
                            Kardex
                        </a>
                    <?php endif; ?>
                    <?php if ($canAccessInventorySerialKardex): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'inventory-serial-kardex' ? ' is-active' : '' ?>"
                            href="/inventario/kardex-series"
                            <?= $activeNavigation === 'inventory-serial-kardex' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">⌁</span>
                            Kardex por serie
                        </a>
                    <?php endif; ?>
                    <?php if ($canAccessInventoryTransfers): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'inventory-transfers' ? ' is-active' : '' ?>"
                            href="/inventario/transferencias"
                            <?= $activeNavigation === 'inventory-transfers' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">⇆</span>
                            Transferencias
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
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($canAccessCatalogs): ?>
                    <section class="app-navigation__group<?= $activeSidebarGroup === 'catalogs' ? ' has-active-item' : '' ?>" data-sidebar-group="catalogs">
                        <button class="app-navigation__toggle" type="button" aria-expanded="true" aria-controls="sidebar-group-catalogs">
                            <span class="app-navigation__section">Catálogos</span>
                            <span class="app-navigation__chevron" aria-hidden="true">⌄</span>
                        </button>
                        <div class="app-navigation__group-content" id="sidebar-group-catalogs">
                    <a
                        class="app-navigation__item<?= $activeNavigation === 'catalogs' ? ' is-active' : '' ?>"
                        href="/catalogos"
                        <?= $activeNavigation === 'catalogs' ? 'aria-current="page"' : '' ?>
                    >
                        <span aria-hidden="true">▦</span>
                        Catálogos
                    </a>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($showOrganization): ?>
                    <section class="app-navigation__group<?= $activeSidebarGroup === 'organization' ? ' has-active-item' : '' ?>" data-sidebar-group="organization">
                        <button class="app-navigation__toggle" type="button" aria-expanded="true" aria-controls="sidebar-group-organization">
                            <span class="app-navigation__section">Organización</span>
                            <span class="app-navigation__chevron" aria-hidden="true">⌄</span>
                        </button>
                        <div class="app-navigation__group-content" id="sidebar-group-organization">
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
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($showAdministration): ?>
                    <section class="app-navigation__group<?= $activeSidebarGroup === 'administration' ? ' has-active-item' : '' ?>" data-sidebar-group="administration">
                        <button class="app-navigation__toggle" type="button" aria-expanded="true" aria-controls="sidebar-group-administration">
                            <span class="app-navigation__section">Administración</span>
                            <span class="app-navigation__chevron" aria-hidden="true">⌄</span>
                        </button>
                        <div class="app-navigation__group-content" id="sidebar-group-administration">
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
                    <?php if ($canAccessMailConfiguration): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'configuration-mail' ? ' is-active' : '' ?>"
                            href="/admin/correo"
                            <?= $activeNavigation === 'configuration-mail' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">@</span>
                            Correo
                        </a>
                    <?php endif; ?>
                    <?php if ($canAccessMailOutbox): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'mail-outbox' ? ' is-active' : '' ?>"
                            href="/admin/correo/cola"
                            <?= $activeNavigation === 'mail-outbox' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">✉</span>
                            Cola de correo
                        </a>
                    <?php endif; ?>
                    <?php if ($canAccessAudit): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'audit' ? ' is-active' : '' ?>"
                            href="/auditoria"
                            <?= $activeNavigation === 'audit' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">!</span>
                            Auditoría
                        </a>
                    <?php endif; ?>
                    <?php if ($canAccessAdminUsers): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'admin-users' ? ' is-active' : '' ?>"
                            href="/admin/usuarios"
                            <?= $activeNavigation === 'admin-users' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">◉</span>
                            Usuarios
                        </a>
                    <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($showAccount): ?>
                    <section class="app-navigation__group<?= $activeSidebarGroup === 'account' ? ' has-active-item' : '' ?>" data-sidebar-group="account">
                        <button class="app-navigation__toggle" type="button" aria-expanded="true" aria-controls="sidebar-group-account">
                            <span class="app-navigation__section">Mi cuenta</span>
                            <span class="app-navigation__chevron" aria-hidden="true">⌄</span>
                        </button>
                        <div class="app-navigation__group-content" id="sidebar-group-account">
                    <?php if ($canAccessProfile): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'profile' ? ' is-active' : '' ?>"
                            href="/perfil"
                            <?= $activeNavigation === 'profile' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">◌</span>
                            Mi perfil
                        </a>
                    <?php endif; ?>
                    <?php if ($canAccessCredential): ?>
                        <a
                            class="app-navigation__item<?= $activeNavigation === 'credential' ? ' is-active' : '' ?>"
                            href="/perfil/credencial"
                            <?= $activeNavigation === 'credential' ? 'aria-current="page"' : '' ?>
                        >
                            <span aria-hidden="true">▣</span>
                            Mi credencial
                        </a>
                    <?php endif; ?>
                        </div>
                    </section>
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
