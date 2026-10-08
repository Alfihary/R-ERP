<?php

declare(strict_types=1);

$warehouse = is_array($warehouse ?? null) ? $warehouse : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];

$field = static fn (string $key): string =>
    (string) (($warehouse[$key] ?? null) !== null && $warehouse[$key] !== ''
        ? $warehouse[$key]
        : '—');
?>
<section class="config-page">
    <header class="config-page__header">
        <div>
            <p class="eyebrow">Configuración operativa</p>
            <h1><?= e($warehouse['nombre'] ?? 'Almacén') ?></h1>
            <p>
                Código estable para folios:
                <code><?= e($warehouse['codigo'] ?? '') ?></code>
            </p>
        </div>
        <div class="actions">
            <a class="button button--secondary" href="/configuracion/almacenes">Volver</a>
            <?php if (($abilities['editar'] ?? false) === true): ?>
                <a class="button" href="/configuracion/almacenes/editar?id=<?= e($warehouse['id'] ?? '') ?>">Editar</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>

    <div class="detail-grid">
        <article class="detail-card">
            <h2>Operación</h2>
            <dl>
                <dt>Empresa</dt>
                <dd><?= e($warehouse['empresa_nombre'] ?? '') ?></dd>
                <dt>Estado</dt>
                <dd><?= (int) ($warehouse['activo'] ?? 0) === 1 ? 'Activo' : 'Inactivo' ?></dd>
                <dt>Tipo</dt>
                <dd><?= e($field('tipo_almacen')) ?></dd>
                <dt>Principal</dt>
                <dd><?= (int) ($warehouse['es_principal'] ?? 0) === 1 ? 'Sí' : 'No' ?></dd>
            </dl>
        </article>
        <article class="detail-card">
            <h2>Capacidades</h2>
            <dl>
                <dt>Ventas</dt>
                <dd><?= (int) ($warehouse['permite_ventas'] ?? 0) === 1 ? 'Sí' : 'No' ?></dd>
                <dt>Compras</dt>
                <dd><?= (int) ($warehouse['permite_compras'] ?? 0) === 1 ? 'Sí' : 'No' ?></dd>
                <dt>Inventario</dt>
                <dd><?= (int) ($warehouse['permite_inventario'] ?? 0) === 1 ? 'Sí' : 'No' ?></dd>
                <dt>Transferencias</dt>
                <dd><?= (int) ($warehouse['permite_transferencias'] ?? 0) === 1 ? 'Sí' : 'No' ?></dd>
            </dl>
        </article>
        <article class="detail-card">
            <h2>Contacto</h2>
            <dl>
                <dt>Responsable</dt>
                <dd><?= e($field('responsable')) ?></dd>
                <dt>Teléfono</dt>
                <dd><?= e($field('telefono')) ?></dd>
                <dt>Email</dt>
                <dd><?= e($field('email')) ?></dd>
                <dt>Dirección</dt>
                <dd>
                    <?= e(trim(implode(' ', array_filter([
                        $field('calle') !== '—' ? $field('calle') : '',
                        $field('numero_exterior') !== '—' ? $field('numero_exterior') : '',
                        $field('colonia') !== '—' ? $field('colonia') : '',
                        $field('municipio') !== '—' ? $field('municipio') : '',
                        $field('estado') !== '—' ? $field('estado') : '',
                        $field('codigo_postal') !== '—' ? $field('codigo_postal') : '',
                    ]))) ?: '—') ?>
                </dd>
            </dl>
        </article>
    </div>
</section>
