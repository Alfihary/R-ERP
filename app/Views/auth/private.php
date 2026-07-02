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
$canAccessCatalogs = ($canAccessCatalogs ?? false) === true;
?>
<header class="page-heading">
    <div class="page-heading__eyebrow">
        <p class="page-heading__path">Inicio</p>
        <span class="status-label">
            <span aria-hidden="true"></span>
            Base operativa activa
        </span>
    </div>
    <h1>Inicio del ERP</h1>
    <p>
        Consulta tu contexto de trabajo y el estado de los controles base antes
        de iniciar una operación. Los módulos empresariales aún no están
        disponibles.
    </p>
</header>

<div class="home-grid">
    <section class="home-section home-section--context" aria-labelledby="scope-title">
        <div class="home-section__heading">
            <div>
                <p class="section-kicker">Contexto de trabajo</p>
                <h2 id="scope-title">Empresa y almacén activos</h2>
            </div>
            <?php if ($hasActiveContext): ?>
                <span class="status-label">
                    <span aria-hidden="true"></span>
                    Activo
                </span>
            <?php else: ?>
                <span class="status-label status-label--neutral">
                    Sin contexto
                </span>
            <?php endif; ?>
        </div>

        <?php if ($hasActiveContext): ?>
            <dl class="context-summary">
                <div>
                    <dt>Empresa</dt>
                    <dd><?= e($activeCompany['name'] ?? '') ?></dd>
                </div>
                <div>
                    <dt>Almacén</dt>
                    <dd><?= e($activeWarehouse['name'] ?? '') ?></dd>
                </div>
            </dl>
        <?php elseif (!$hasScope): ?>
            <div class="empty-state" role="status">
                <strong>No hay un contexto disponible</strong>
                <p>
                    Tu cuenta no tiene una empresa y un almacén activos.
                    Solicita una asignación al administrador del sistema.
                </p>
            </div>
        <?php else: ?>
            <div class="empty-state" role="status">
                <strong>Selecciona un contexto</strong>
                <p>
                    Elige una empresa y un almacén permitidos para establecer
                    el contexto de trabajo.
                </p>
            </div>
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

    <section class="home-section" aria-labelledby="session-title">
        <div class="home-section__heading">
            <div>
                <p class="section-kicker">Identidad</p>
                <h2 id="session-title">Sesión autenticada</h2>
            </div>
            <span class="status-label">
                <span aria-hidden="true"></span>
                Activa
            </span>
        </div>

        <dl class="session-summary">
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
</div>

<section class="home-section security-section" aria-labelledby="security-title">
    <div class="home-section__heading">
        <div>
            <p class="section-kicker">Controles base</p>
            <h2 id="security-title">Seguridad de la sesión</h2>
        </div>
        <span class="status-label">
            <span aria-hidden="true"></span>
            Operativa
        </span>
    </div>

    <ul class="control-list">
        <li>
            <div>
                <strong>Autenticación</strong>
                <span>La identidad de la sesión fue verificada.</span>
            </div>
            <span class="control-list__state">Activa</span>
        </li>
        <li>
            <div>
                <strong>Protección de formularios</strong>
                <span>Las acciones de cambio requieren una solicitud válida.</span>
            </div>
            <span class="control-list__state">Activa</span>
        </li>
        <li>
            <div>
                <strong>Autorización</strong>
                <span>El acceso a esta pantalla está controlado.</span>
            </div>
            <span class="control-list__state">Activa</span>
        </li>
        <li>
            <div>
                <strong>Alcance operativo</strong>
                <span>Empresa y almacén se validan contra tus asignaciones.</span>
            </div>
            <span class="control-list__state">Activa</span>
        </li>
    </ul>
</section>

<div class="home-grid home-grid--secondary">
    <section class="home-section" aria-labelledby="access-title">
        <div class="home-section__heading">
            <div>
                <p class="section-kicker">Navegación</p>
                <h2 id="access-title">Accesos disponibles</h2>
            </div>
        </div>
        <ul class="plain-status-list">
            <li>
                <span>Inicio privado</span>
                <strong>Disponible</strong>
            </li>
            <li>
                <span>Cierre seguro de sesión</span>
                <strong>Disponible en la barra superior</strong>
            </li>
            <?php if ($canAccessCatalogs): ?>
                <li>
                    <span>Catálogos base</span>
                    <strong>Disponible en la navegación</strong>
                </li>
            <?php endif; ?>
        </ul>
    </section>

    <section class="home-section" aria-labelledby="pending-title">
        <div class="home-section__heading">
            <div>
                <p class="section-kicker">Siguientes fases</p>
                <h2 id="pending-title">Operaciones pendientes</h2>
            </div>
        </div>
        <ul class="plain-status-list plain-status-list--pending">
            <li>
                <span>Productos</span>
                <strong>No disponibles</strong>
            </li>
            <li>
                <span>Inventario y movimientos</span>
                <strong>No disponibles</strong>
            </li>
            <li>
                <span>Solicitudes y procesos comerciales</span>
                <strong>No disponibles</strong>
            </li>
        </ul>
    </section>
</div>

<p class="home-boundary">
    Esta página no contiene indicadores, gráficas ni operaciones
    empresariales. Cada capacidad se habilitará únicamente en una fase
    aprobada.
</p>
