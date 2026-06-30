<?php

declare(strict_types=1);

if (!is_array($user ?? null) || !is_array($scope ?? null)) {
    throw new RuntimeException('Authenticated content context is incomplete.');
}

$companies = is_array($scope['companies'] ?? null) ? $scope['companies'] : [];
$warehouses = is_array($scope['warehouses'] ?? null) ? $scope['warehouses'] : [];
$defaultCompany = is_array($scope['default_company'] ?? null)
    ? $scope['default_company']
    : null;
$defaultWarehouse = is_array($scope['default_warehouse'] ?? null)
    ? $scope['default_warehouse']
    : null;
$hasScope = ($scope['has_scope'] ?? false) === true;
?>
<header class="page-heading">
    <p class="page-heading__path">Inicio</p>
    <h1>Área de trabajo</h1>
    <p>
        Este espacio confirma que tu sesión y permiso de acceso están activos.
        Los módulos operativos se incorporarán en fases posteriores.
    </p>
</header>

<section class="identity-section" aria-labelledby="identity-title">
    <div class="identity-section__heading">
        <h2 id="identity-title">Sesión autenticada</h2>
        <span class="status-label">
            <span aria-hidden="true"></span>
            Activa
        </span>
    </div>

    <dl class="identity-list">
        <div>
            <dt>Usuario</dt>
            <dd><?= e($user['username'] ?? '') ?></dd>
        </div>
        <div>
            <dt>Correo</dt>
            <dd><?= e($user['email'] ?? '') ?></dd>
        </div>
    </dl>
</section>

<section class="identity-section" aria-labelledby="scope-title">
    <div class="identity-section__heading">
        <h2 id="scope-title">Alcance operativo disponible</h2>
        <?php if ($hasScope): ?>
            <span class="status-label">
                <span aria-hidden="true"></span>
                Asignado
            </span>
        <?php else: ?>
            <span>Sin asignar</span>
        <?php endif; ?>
    </div>

    <?php if ($hasScope): ?>
        <dl class="identity-list">
            <div>
                <dt>Empresa predeterminada</dt>
                <dd><?= e($defaultCompany['name'] ?? '') ?></dd>
            </div>
            <div>
                <dt>Almacén predeterminado</dt>
                <dd><?= e($defaultWarehouse['name'] ?? '') ?></dd>
            </div>
            <div>
                <dt>Empresas permitidas</dt>
                <dd><?= e((string) count($companies)) ?></dd>
            </div>
            <div>
                <dt>Almacenes permitidos</dt>
                <dd><?= e((string) count($warehouses)) ?></dd>
            </div>
        </dl>
    <?php else: ?>
        <p>
            Tu cuenta no tiene una empresa y un almacén activos disponibles.
            Solicita una asignación al administrador del sistema.
        </p>
    <?php endif; ?>
</section>

<section class="shell-notice" aria-labelledby="shell-notice-title">
    <h2 id="shell-notice-title">Cascarón administrativo disponible</h2>
    <p>
        La estructura de navegación está lista. Esta pantalla no contiene
        dashboard, métricas ni accesos a módulos aún no aprobados.
    </p>
</section>
