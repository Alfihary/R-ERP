<?php

declare(strict_types=1);

$company = is_array($company ?? null) ? $company : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];

$field = static fn (string $key): string =>
    (string) (($company[$key] ?? null) !== null && $company[$key] !== ''
        ? $company[$key]
        : '—');
?>
<section class="config-page">
    <header class="config-page__header">
        <div>
            <p class="eyebrow">Configuración operativa</p>
            <h1><?= e($company['nombre'] ?? 'Empresa') ?></h1>
            <p>Código estable: <code><?= e($company['codigo'] ?? '') ?></code></p>
        </div>
        <div class="actions">
            <a class="button button--secondary" href="/configuracion/empresas">Volver</a>
            <?php if (($abilities['editar'] ?? false) === true): ?>
                <a class="button" href="/configuracion/empresas/editar?id=<?= e($company['id'] ?? '') ?>">Editar</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>

    <div class="detail-grid">
        <article class="detail-card">
            <h2>Identidad</h2>
            <dl>
                <dt>Estado</dt>
                <dd><?= (int) ($company['activo'] ?? 0) === 1 ? 'Activa' : 'Inactiva' ?></dd>
                <dt>Razón social</dt>
                <dd><?= e($field('razon_social')) ?></dd>
                <dt>Nombre comercial</dt>
                <dd><?= e($field('nombre_comercial')) ?></dd>
                <dt>RFC</dt>
                <dd><?= e($field('rfc')) ?></dd>
                <dt>Régimen fiscal</dt>
                <dd><?= e($field('regimen_fiscal')) ?></dd>
            </dl>
        </article>
        <article class="detail-card">
            <h2>Contacto y dirección</h2>
            <dl>
                <dt>Teléfono</dt>
                <dd><?= e($field('telefono')) ?></dd>
                <dt>Email</dt>
                <dd><?= e($field('email')) ?></dd>
                <dt>Sitio web</dt>
                <dd><?= e($field('sitio_web')) ?></dd>
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
