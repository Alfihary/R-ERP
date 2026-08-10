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
$headline = $title !== '' ? $title : $displayName;
$details = [
    'puesto' => 'Puesto',
    'empresa' => 'Empresa',
    'almacen' => 'Almacén',
    'ubicacion' => 'Ubicación',
    'correo' => 'Correo',
    'telefono_movil' => 'Móvil',
    'telefono_fijo' => 'Teléfono',
];
$links = [
    'sitio_web' => 'Sitio web',
    'linkedin' => 'LinkedIn',
    'facebook' => 'Facebook',
    'instagram' => 'Instagram',
    'google_maps' => 'Mapa',
];
$safeTel = static fn (string $value): string => preg_replace('/[^0-9+]/', '', $value) ?? '';
$quickActions = [];

if (is_string($vcard['telefono_movil'] ?? null) && trim((string) $vcard['telefono_movil']) !== '') {
    $phone = $safeTel((string) $vcard['telefono_movil']);
    if ($phone !== '') {
        $quickActions[] = ['label' => 'Llamar', 'href' => 'tel:' . $phone, 'type' => 'primary'];
    }
}
if (is_string($vcard['correo'] ?? null) && trim((string) $vcard['correo']) !== '') {
    $quickActions[] = [
        'label' => 'Correo',
        'href' => 'mailto:' . rawurlencode(trim((string) $vcard['correo'])),
        'type' => 'secondary',
    ];
}
if (is_string($vcard['whatsapp'] ?? null) && trim((string) $vcard['whatsapp']) !== '') {
        $whatsapp = preg_replace('/\D/', '', (string) $vcard['whatsapp']) ?? '';
        if ($whatsapp !== '') {
            $quickActions[] = [
            'label' => 'Contactar por WhatsApp',
            'href' => 'https://wa.me/' . $whatsapp,
            'type' => 'secondary',
        ];
    }
}
if ($quickActions === [] && $contactAction !== null) {
    $quickActions[] = [
        'label' => (string) $contactAction['label'],
        'href' => (string) $contactAction['href'],
        'type' => 'primary',
    ];
}
if ($vcfUrl !== null) {
    $quickActions[] = ['label' => 'Descargar contacto', 'href' => $vcfUrl, 'type' => 'secondary'];
}

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
    <meta name="color-scheme" content="dark light">
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
            <aside class="vcard-public__brand" aria-label="Identidad pública">
                <p class="vcard-public__brand-name"><?= e($appName) ?></p>

                <div class="vcard-public__portrait">
                    <?php if (($vcard['foto_publica_disponible'] ?? false) === true && $slug !== ''): ?>
                        <img
                            class="vcard-public__photo"
                            src="/v/<?= e(rawurlencode($slug)) ?>/foto"
                            alt=""
                            width="144"
                            height="144"
                        >
                    <?php else: ?>
                        <div class="vcard-public__avatar" aria-hidden="true">
                            <?= e($initial) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="vcard-public__brand-copy">
                    <p>Contacto público verificado por configuración de privacidad.</p>
                    <?php if ($qrUrl !== null): ?>
                        <p class="vcard-public__qr-label">QR público</p>
                        <img
                            class="vcard-public__qr"
                            src="<?= e($qrUrl) ?>"
                            alt="Código QR para abrir esta vCard pública"
                            width="132"
                            height="132"
                        >
                    <?php endif; ?>
                </div>
            </aside>

            <div class="vcard-public__content">
                <header class="vcard-public__header">
                    <p class="vcard-public__label">vCard pública</p>
                    <h1 id="vcard-public-title"><?= e($headline) ?></h1>
                    <?php if ($displayName !== '' && $displayName !== $headline): ?>
                        <p class="vcard-public__name"><?= e($displayName) ?></p>
                    <?php endif; ?>
                    <?php if ($description !== ''): ?>
                        <p class="vcard-public__description"><?= e($description) ?></p>
                    <?php endif; ?>

                    <?php if ($quickActions !== []): ?>
                        <nav class="vcard-public__actions" aria-label="Acciones de contacto">
                            <?php foreach ($quickActions as $action): ?>
                                <?php
                                $href = (string) ($action['href'] ?? '');
                                $external = str_starts_with($href, 'http');
                                $modifier = ($action['type'] ?? '') === 'primary'
                                    ? ''
                                    : ' vcard-public__action--secondary';
                                ?>
                                <a
                                    class="vcard-public__action<?= e($modifier) ?>"
                                    href="<?= e($href) ?>"
                                    <?php if ($external): ?>
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    <?php endif; ?>
                                >
                                    <?= e((string) ($action['label'] ?? 'Abrir')) ?>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>
                </header>

                <section class="vcard-public__section" aria-labelledby="vcard-public-details">
                    <h2 id="vcard-public-details">Información de contacto</h2>
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

                <?php if ($publicProducts !== []): ?>
                    <section class="vcard-public__section" aria-labelledby="vcard-public-products">
                        <div class="vcard-public__section-heading">
                            <h2 id="vcard-public-products">Productos relacionados</h2>
                            <span><?= count($publicProducts) ?> visibles</span>
                        </div>
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
            </div>
        </article>
    </main>
</body>
</html>
