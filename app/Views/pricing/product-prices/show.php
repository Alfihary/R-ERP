<?php

declare(strict_types=1);

$price = is_array($price ?? null) ? $price : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];
$active = (int) ($price['activo'] ?? 0) === 1;
$review = (int) ($price['requiere_revision'] ?? 0) === 1;
?>
<section class="product-price-page">
    <header class="product-price-page__header">
        <div>
            <p class="eyebrow">Precios</p>
            <h1><?= e($price['id_producto'] ?? 'Precio') ?> · <?= e($price['lista_clave'] ?? '') ?></h1>
            <p>Detalle básico del precio. La pantalla no emite ventas ni autorizaciones.</p>
        </div>
        <div class="product-price-header-actions">
            <a class="button button--secondary" href="/precios/productos">Volver</a>
            <?php if (($abilities['editar'] ?? false) === true): ?>
                <a class="button" href="/precios/productos/editar?id=<?= e($price['id'] ?? '') ?>">Editar</a>
            <?php endif; ?>
            <?php if (($abilities['historial'] ?? false) === true): ?>
                <a class="button button--secondary" href="/precios/productos/historial?id=<?= e($price['id'] ?? '') ?>">Historial</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>

    <article class="product-price-detail">
        <dl>
            <div>
                <dt>Producto</dt>
                <dd><strong><?= e($price['id_producto'] ?? '') ?></strong><small><?= e($price['producto_descripcion'] ?? '') ?></small></dd>
            </div>
            <div>
                <dt>Lista</dt>
                <dd><code><?= e($price['lista_clave'] ?? '') ?></code><small><?= e($price['lista_nombre'] ?? '') ?></small></dd>
            </div>
            <div>
                <dt>Moneda</dt>
                <dd><code><?= e($price['moneda_codigo'] ?? '') ?></code></dd>
            </div>
            <div>
                <dt>Precio lista</dt>
                <dd><?= e($price['precio_lista'] ?? '') ?></dd>
            </div>
            <div>
                <dt>Precio mínimo</dt>
                <dd><?= e($price['precio_minimo'] ?? '') ?></dd>
            </div>
            <div>
                <dt>Incluye impuestos</dt>
                <dd><?= (int) ($price['incluye_impuestos'] ?? 0) === 1 ? 'Sí' : 'No' ?></dd>
            </div>
            <div>
                <dt>Revisión</dt>
                <dd><span class="badge <?= $review ? 'badge--warning' : 'badge--success' ?>"><?= $review ? 'En revisión' : 'Sin revisión' ?></span></dd>
            </div>
            <div>
                <dt>Estado</dt>
                <dd><span class="badge <?= $active ? 'badge--success' : 'badge--warning' ?>"><?= $active ? 'Activo' : 'Inactivo' ?></span></dd>
            </div>
            <div>
                <dt>Creado</dt>
                <dd><?= e($price['creado_en'] ?? '') ?></dd>
            </div>
            <div>
                <dt>Actualizado</dt>
                <dd><?= e($price['actualizado_en'] ?? '—') ?></dd>
            </div>
        </dl>
    </article>
</section>
