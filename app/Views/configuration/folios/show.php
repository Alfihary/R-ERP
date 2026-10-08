<?php

declare(strict_types=1);

$serie = is_array($serie ?? null) ? $serie : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];
$field = static fn (string $key): string =>
    (string) (($serie[$key] ?? null) !== null && $serie[$key] !== '' ? $serie[$key] : '—');
?>
<section class="folio-page">
    <header class="folio-page__header">
        <div>
            <p class="eyebrow">Configuración operativa</p>
            <h1><?= e($field('tipo_documento')) ?> · <?= e($field('codigo_serie')) ?></h1>
            <p>Serie documental por almacén. La vista previa no emite folios.</p>
        </div>
        <div class="actions">
            <a class="button button--secondary" href="/configuracion/folios">Volver</a>
            <?php if (($abilities['editar'] ?? false) === true): ?>
                <a class="button" href="/configuracion/folios/editar?id=<?= e($serie['id'] ?? '') ?>">Editar</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>

    <div class="folio-preview folio-preview--detail">
        <span>Ejemplo de siguiente folio</span>
        <strong><?= e($preview ?? '') ?></strong>
        <p>Calculado con prefijo, snapshot de almacén, siguiente número y longitud.</p>
    </div>

    <div class="detail-grid">
        <article class="detail-card">
            <h2>Scope</h2>
            <dl>
                <dt>Empresa</dt><dd><?= e($field('empresa_nombre')) ?></dd>
                <dt>Almacén</dt><dd><?= e($field('almacen_nombre')) ?></dd>
                <dt>Código almacén actual</dt><dd><code><?= e($field('almacen_codigo')) ?></code></dd>
                <dt>Snapshot almacén</dt><dd><code><?= e($field('codigo_almacen_snapshot')) ?></code></dd>
            </dl>
        </article>
        <article class="detail-card">
            <h2>Serie</h2>
            <dl>
                <dt>Tipo</dt><dd><code><?= e($field('tipo_documento')) ?></code></dd>
                <dt>Código de serie</dt><dd><code><?= e($field('codigo_serie')) ?></code></dd>
                <dt>Prefijo</dt><dd><code><?= e($field('prefijo')) ?></code></dd>
                <dt>Formato</dt><dd><code><?= e($field('formato')) ?></code></dd>
            </dl>
        </article>
        <article class="detail-card">
            <h2>Consecutivo</h2>
            <dl>
                <dt>Siguiente número</dt><dd><?= e($field('siguiente_numero')) ?></dd>
                <dt>Longitud</dt><dd><?= e($field('longitud')) ?></dd>
                <dt>Reinicio anual</dt><dd><?= (int) ($serie['reinicio_anual'] ?? 0) === 1 ? 'Sí' : 'No' ?></dd>
                <dt>Año actual</dt><dd><?= e($field('anio_actual')) ?></dd>
            </dl>
        </article>
        <article class="detail-card">
            <h2>Control</h2>
            <dl>
                <dt>Estado</dt><dd><?= (int) ($serie['activo'] ?? 0) === 1 ? 'Activa' : 'Inactiva' ?></dd>
                <dt>Folios emitidos</dt><dd><?= e($field('folios_emitidos')) ?></dd>
                <dt>Creado en</dt><dd><?= e($field('creado_en')) ?></dd>
                <dt>Actualizado en</dt><dd><?= e($field('actualizado_en')) ?></dd>
            </dl>
        </article>
    </div>
</section>
