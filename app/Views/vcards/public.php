<?php

declare(strict_types=1);

if (
    !is_array($vcard ?? null)
    || !is_string($appName ?? null)
    || !is_string($pageTitle ?? null)
    || !is_string($metaDescription ?? null)
    || !is_string($canonicalUrl ?? null)
) {
    throw new RuntimeException('Public vCard view context is incomplete.');
}

$contactAction = is_array($contactAction ?? null) ? $contactAction : null;
$qrUrl = is_string($qrUrl ?? null) && $qrUrl !== '' ? $qrUrl : null;
$vcfUrl = is_string($vcfUrl ?? null) && $vcfUrl !== '' ? $vcfUrl : null;
$publicProducts = is_array($publicProducts ?? null) ? $publicProducts : [];
$slug = (string) ($vcard['slug'] ?? '');
$name = trim((string) ($vcard['nombre'] ?? ''));
$title = trim((string) ($vcard['titulo_publico'] ?? $pageTitle));
$description = trim((string) ($vcard['descripcion_publica'] ?? ''));
$displayName = $name !== '' ? $name : $title;
$details = [
    'puesto' => 'Puesto',
    'empresa' => 'Empresa',
    'almacen' => 'Almacén',
    'ubicacion' => 'Ubicación',
    'telefono_movil' => 'Teléfono móvil',
    'telefono_fijo' => 'Teléfono fijo',
    'correo' => 'Correo',
];
$links = [
    'sitio_web' => 'Sitio web',
    'linkedin' => 'LinkedIn',
    'facebook' => 'Facebook',
    'instagram' => 'Instagram',
    'google_maps' => 'Mapa',
];
$initial = $displayName !== '' ? $displayName : 'C';
$initial = function_exists('mb_substr')
    ? mb_substr($initial, 0, 1, 'UTF-8')
    : substr($initial, 0, 1);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="description" content="<?= e($metaDescription) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($metaDescription) ?>">
    <meta property="og:type" content="profile">
    <meta property="og:url" content="<?= e($canonicalUrl) ?>">
    <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
    <link rel="stylesheet" href="/css/core/app.css">
    <link rel="stylesheet" href="/css/modules/vcard-public.css">
</head>
<body class="vcard-public-body">
    <a class="skip-link" href="#main-content">Saltar al contenido</a>

    <main class="vcard-public" id="main-content" tabindex="-1">
        <article class="vcard-public__card" aria-labelledby="vcard-public-title">
            <header class="vcard-public__header">
                <div class="vcard-public__identity">
                    <?php if (($vcard['foto_publica_disponible'] ?? false) === true && $slug !== ''): ?>
                        <img
                            class="vcard-public__photo"
                            src="/v/<?= e(rawurlencode($slug)) ?>/foto"
                            alt=""
                            width="96"
                            height="96"
                        >
                    <?php else: ?>
                        <div class="vcard-public__avatar" aria-hidden="true">
                            <?= e($initial) ?>
                        </div>
                    <?php endif; ?>

                    <div>
                        <p class="vcard-public__label">vCard pública</p>
                        <h1 id="vcard-public-title"><?= e($title) ?></h1>
                        <?php if ($displayName !== '' && $displayName !== $title): ?>
                            <p class="vcard-public__name"><?= e($displayName) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($contactAction !== null || $vcfUrl !== null): ?>
                    <div class="vcard-public__actions">
                        <?php if ($contactAction !== null): ?>
                            <a
                                class="vcard-public__action"
                                href="<?= e((string) $contactAction['href']) ?>"
                                <?php if (str_starts_with((string) $contactAction['href'], 'http')): ?>
                                    target="_blank"
                                    rel="noopener noreferrer"
                                <?php endif; ?>
                            >
                                <?= e((string) $contactAction['label']) ?>
                            </a>
                        <?php endif; ?>

                        <?php if ($vcfUrl !== null): ?>
                            <a class="vcard-public__action vcard-public__action--secondary" href="<?= e($vcfUrl) ?>">
                                Descargar contacto
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </header>

            <?php if ($description !== ''): ?>
                <p class="vcard-public__description"><?= e($description) ?></p>
            <?php endif; ?>

            <section class="vcard-public__section" aria-labelledby="vcard-public-details">
                <h2 id="vcard-public-details">Información pública</h2>
                <dl class="vcard-public__details">
                    <?php $visibleDetails = 0; ?>
                    <?php foreach ($details as $field => $label): ?>
                        <?php if (isset($vcard[$field]) && is_string($vcard[$field]) && trim($vcard[$field]) !== ''): ?>
                            <?php $visibleDetails++; ?>
                            <div>
                                <dt><?= e($label) ?></dt>
                                <dd><?= e((string) $vcard[$field]) ?></dd>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </dl>

                <?php if ($visibleDetails === 0): ?>
                    <p class="vcard-public__empty">
                        Esta vCard está publicada con información limitada por privacidad.
                    </p>
                <?php endif; ?>
            </section>

            <section class="vcard-public__section" aria-labelledby="vcard-public-links">
                <h2 id="vcard-public-links">Enlaces públicos</h2>
                <ul class="vcard-public__links">
                    <?php $visibleLinks = 0; ?>
                    <?php foreach ($links as $field => $label): ?>
                        <?php if (isset($vcard[$field]) && is_string($vcard[$field]) && trim($vcard[$field]) !== ''): ?>
                            <?php $visibleLinks++; ?>
                            <li>
                                <a href="<?= e((string) $vcard[$field]) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= e($label) ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>

                <?php if ($visibleLinks === 0): ?>
                    <p class="vcard-public__empty">No hay enlaces públicos disponibles.</p>
                <?php endif; ?>
            </section>

            <?php if ($qrUrl !== null): ?>
                <section class="vcard-public__section" aria-labelledby="vcard-public-qr">
                    <h2 id="vcard-public-qr">QR público</h2>
                    <img
                        class="vcard-public__qr"
                        src="<?= e($qrUrl) ?>"
                        alt="Código QR para abrir esta vCard pública"
                        width="270"
                        height="270"
                    >
                    <p class="vcard-public__empty">
                        El QR apunta únicamente a esta página pública.
                    </p>
                </section>
            <?php endif; ?>

            <?php if ($publicProducts !== []): ?>
                <section class="vcard-public__section" aria-labelledby="vcard-public-products">
                    <h2 id="vcard-public-products">Productos</h2>
                    <div class="vcard-public__products">
                        <?php foreach ($publicProducts as $product): ?>
                            <?php
                            $productName = trim((string) ($product['descripcion'] ?? ''));
                            $publicText = trim((string) ($product['texto_publico'] ?? ''));
                            $meta = array_filter([
                                $product['marca'] ?? null,
                                $product['linea'] ?? null,
                                $product['clasificacion'] ?? null,
                                $product['unidad'] ?? null,
                            ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
                            ?>
                            <article class="vcard-public__product">
                                <div>
                                    <h3><?= e($productName !== '' ? $productName : 'Producto') ?></h3>
                                    <?php if ($publicText !== ''): ?>
                                        <p><?= e($publicText) ?></p>
                                    <?php endif; ?>
                                    <?php if ($meta !== []): ?>
                                        <p class="vcard-public__product-meta">
                                            <?= e(implode(' · ', array_map('strval', $meta))) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <?php if (($product['destacado'] ?? false) === true): ?>
                                    <span class="vcard-public__product-badge">Destacado</span>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </article>
    </main>
</body>
</html>
