<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!is_array($user ?? null)
    || !is_array($context ?? null)
    || !$csrf instanceof CsrfTokenService
) {
    throw new RuntimeException('Authenticated content context is incomplete.');
}

$companies = is_array($context['companies'] ?? null) ? $context['companies'] : [];
$warehouses = is_array($context['warehouses'] ?? null) ? $context['warehouses'] : [];
$activeCompany = is_array($context['active_company'] ?? null)
    ? $context['active_company']
    : null;
$activeWarehouse = is_array($context['active_warehouse'] ?? null)
    ? $context['active_warehouse']
    : null;
$hasScope = ($context['has_scope'] ?? false) === true;
$hasActiveContext = ($context['has_active_context'] ?? false) === true;
$requiresSelection = ($context['requires_selection'] ?? false) === true;
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
        <h2 id="scope-title">Contexto activo de operación</h2>
        <?php if ($hasActiveContext): ?>
            <span class="status-label">
                <span aria-hidden="true"></span>
                Activo
            </span>
        <?php else: ?>
            <span>Sin contexto</span>
        <?php endif; ?>
    </div>

    <?php if ($hasActiveContext): ?>
        <dl class="identity-list">
            <div>
                <dt>Empresa activa</dt>
                <dd><?= e($activeCompany['name'] ?? '') ?></dd>
            </div>
            <div>
                <dt>Almacén activo</dt>
                <dd><?= e($activeWarehouse['name'] ?? '') ?></dd>
            </div>
        </dl>
    <?php elseif (!$hasScope): ?>
        <p>
            Tu cuenta no tiene una empresa y un almacén activos disponibles.
            Solicita una asignación al administrador del sistema.
        </p>
    <?php else: ?>
        <p>
            Selecciona una empresa y un almacén permitidos para establecer el
            contexto de trabajo.
        </p>
    <?php endif; ?>

    <?php if ($hasScope && $requiresSelection): ?>
        <form class="scope-context-form" method="post" action="/app/contexto">
            <?= csrf_field($csrf) ?>

            <div class="scope-context-form__field">
                <label for="active-company">Empresa</label>
                <select
                    id="active-company"
                    name="active_company_id"
                    required
                >
                    <option value="">Selecciona una empresa</option>
                    <?php foreach ($companies as $company): ?>
                        <option
                            value="<?= e((string) ($company['id'] ?? '')) ?>"
                            <?php if (
                                (int) ($activeCompany['id'] ?? 0)
                                === (int) ($company['id'] ?? 0)
                            ): ?>
                                selected
                            <?php endif; ?>
                        >
                            <?= e($company['name'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="scope-context-form__field">
                <label for="active-warehouse">Almacén</label>
                <select
                    id="active-warehouse"
                    name="active_warehouse_id"
                    required
                >
                    <option value="">Selecciona un almacén</option>
                    <?php foreach ($companies as $company): ?>
                        <?php
                        $companyId = (int) ($company['id'] ?? 0);
                        $companyWarehouses = array_values(array_filter(
                            $warehouses,
                            static fn (array $warehouse): bool =>
                                (int) ($warehouse['company_id'] ?? 0) === $companyId
                        ));
                        ?>
                        <?php if ($companyWarehouses !== []): ?>
                            <optgroup label="<?= e($company['name'] ?? '') ?>">
                                <?php foreach ($companyWarehouses as $warehouse): ?>
                                    <option
                                        value="<?= e((string) ($warehouse['id'] ?? '')) ?>"
                                        <?php if (
                                            (int) ($activeWarehouse['id'] ?? 0)
                                            === (int) ($warehouse['id'] ?? 0)
                                        ): ?>
                                            selected
                                        <?php endif; ?>
                                    >
                                        <?= e($warehouse['name'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="button" type="submit">Cambiar contexto</button>
        </form>
    <?php endif; ?>
</section>

<section class="shell-notice" aria-labelledby="shell-notice-title">
    <h2 id="shell-notice-title">Cascarón administrativo disponible</h2>
    <p>
        La estructura de navegación está lista. Esta pantalla no contiene
        dashboard, métricas ni accesos a módulos aún no aprobados.
    </p>
</section>
