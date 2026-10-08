<?php

declare(strict_types=1);

$list = is_array($list ?? null) ? $list : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];
$active = (int) ($list['activo'] ?? 0) === 1;
$default = (int) ($list['es_predeterminada'] ?? 0) === 1;
?>
<section class="price-list-page">
    <header class="price-list-page__header">
        <div>
            <p class="eyebrow">Configuración de precios</p>
            <h1><?= e($list['nombre'] ?? 'Lista de precios') ?></h1>
            <p>Detalle básico de lista global. No muestra ni edita precios por producto.</p>
        </div>
        <div class="price-list-header-actions">
            <a class="button button--secondary" href="/configuracion/listas-precios">Volver</a>
            <?php if (($abilities['editar'] ?? false) === true): ?>
                <a class="button" href="/configuracion/listas-precios/editar?id=<?= e($list['id'] ?? '') ?>">Editar</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>

    <article class="price-list-detail">
        <dl>
            <div>
                <dt>Clave</dt>
                <dd><code><?= e($list['clave'] ?? '') ?></code></dd>
            </div>
            <div>
                <dt>Nombre</dt>
                <dd><?= e($list['nombre'] ?? '') ?></dd>
            </div>
            <div>
                <dt>Incluye impuestos</dt>
                <dd><?= (int) ($list['incluye_impuestos'] ?? 0) === 1 ? 'Sí' : 'No' ?></dd>
            </div>
            <div>
                <dt>Predeterminada</dt>
                <dd>
                    <span class="badge <?= $default ? 'badge--success' : 'badge--neutral' ?>">
                        <?= $default ? 'Sí' : 'No' ?>
                    </span>
                </dd>
            </div>
            <div>
                <dt>Estado</dt>
                <dd>
                    <span class="badge <?= $active ? 'badge--success' : 'badge--warning' ?>">
                        <?= $active ? 'Activa' : 'Inactiva' ?>
                    </span>
                </dd>
            </div>
            <div>
                <dt>Observaciones</dt>
                <dd><?= e($list['observaciones'] ?? '—') ?></dd>
            </div>
        </dl>
    </article>
</section>
