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
$productsTotal = isset($productsTotal) ? max(0, (int) $productsTotal) : count($publicProducts);
$productsUrl = is_string($productsUrl ?? null) && $productsUrl !== '' ? $productsUrl : null;
$slug = (string) ($vcard['slug'] ?? '');
$publicSlugPath = $slug !== '' ? rawurlencode($slug) : '';
$productsActionUrl = $publicProducts !== [] && $publicSlugPath !== ''
    ? '/v/' . $publicSlugPath . '/productos'
    : null;
$name = trim((string) ($vcard['nombre'] ?? ''));
$title = trim((string) ($vcard['titulo_publico'] ?? $pageTitle));
$description = trim((string) ($vcard['descripcion_publica'] ?? ''));
$displayName = $name !== '' ? $name : $title;
$headline = $title !== '' ? $title : $displayName;
$role = isset($vcard['puesto']) && is_string($vcard['puesto'])
    ? trim((string) $vcard['puesto'])
    : '';
$introTitle = $headline !== '' && $headline !== $displayName && $headline !== $role
    ? $headline
    : '';
$links = [
    'sitio_web' => 'Sitio web',
    'linkedin' => 'LinkedIn',
    'facebook' => 'Facebook',
    'instagram' => 'Instagram',
    'google_maps' => 'Mapa',
];
$linkIcons = [
    'sitio_web' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm6.48 8h-3.05a13.95 13.95 0 0 0-1.03-4.02A7.04 7.04 0 0 1 18.48 11ZM12 5.1c.55.78 1.22 2.06 1.49 3.9h-2.98c.27-1.84.94-3.12 1.49-3.9ZM5.52 13h3.05c.14 1.48.5 2.85 1.03 4.02A7.04 7.04 0 0 1 5.52 13Zm3.05-2H5.52A7.04 7.04 0 0 1 9.6 6.98 13.95 13.95 0 0 0 8.57 11ZM12 18.9c-.55-.78-1.22-2.06-1.49-3.9h2.98c-.27 1.84-.94 3.12-1.49 3.9ZM13.91 13h-3.82a12.1 12.1 0 0 1 0-2h3.82a12.1 12.1 0 0 1 0 2Zm.49 4.02c.53-1.17.89-2.54 1.03-4.02h3.05a7.04 7.04 0 0 1-4.08 4.02Z"/></svg>',
    'linkedin' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M6.94 8.9H3.87V20h3.07V8.9ZM5.4 4a1.78 1.78 0 1 0 0 3.56A1.78 1.78 0 0 0 5.4 4Zm8.26 4.64c-1.67 0-2.57.92-3.01 1.56V8.9H7.72V20h3.06v-5.49c0-1.45.28-2.86 2.08-2.86 1.77 0 1.79 1.66 1.79 2.95V20h3.06v-6.1c0-2.99-.64-5.26-4.05-5.26Z"/></svg>',
    'facebook' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M14.2 8.1V6.5c0-.78.52-.96.89-.96h2.28V2.12L14.23 2.1c-3.49 0-4.28 2.61-4.28 4.28V8.1H7.2v3.52h2.75V21h4.25v-9.38h2.85l.38-3.52H14.2Z"/></svg>',
    'instagram' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M7.7 2h8.6A5.7 5.7 0 0 1 22 7.7v8.6a5.7 5.7 0 0 1-5.7 5.7H7.7A5.7 5.7 0 0 1 2 16.3V7.7A5.7 5.7 0 0 1 7.7 2Zm0 2A3.7 3.7 0 0 0 4 7.7v8.6A3.7 3.7 0 0 0 7.7 20h8.6a3.7 3.7 0 0 0 3.7-3.7V7.7A3.7 3.7 0 0 0 16.3 4H7.7Zm8.76 1.8a1.34 1.34 0 1 1 0 2.68 1.34 1.34 0 0 1 0-2.68ZM12 7.35a4.65 4.65 0 1 1 0 9.3 4.65 4.65 0 0 1 0-9.3Zm0 2a2.65 2.65 0 1 0 0 5.3 2.65 2.65 0 0 0 0-5.3Z"/></svg>',
    'google_maps' => '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 2.5a7.1 7.1 0 0 0-7.1 7.1c0 4.94 6.26 11.45 6.53 11.72a.8.8 0 0 0 1.14 0c.27-.27 6.53-6.78 6.53-11.72A7.1 7.1 0 0 0 12 2.5Zm0 9.65a2.55 2.55 0 1 1 0-5.1 2.55 2.55 0 0 1 0 5.1Z"/></svg>',
];
$safeTel = static fn (string $value): string => preg_replace('/[^0-9+]/', '', $value) ?? '';
$quickActions = [];

if (is_string($vcard['telefono_movil'] ?? null) && trim((string) $vcard['telefono_movil']) !== '') {
    $phone = $safeTel((string) $vcard['telefono_movil']);
    if ($phone !== '') {
        $quickActions[] = ['label' => 'Llamar ahora', 'href' => 'tel:' . $phone, 'type' => 'primary'];
    }
}
if (is_string($vcard['correo'] ?? null) && trim((string) $vcard['correo']) !== '') {
    $quickActions[] = [
        'label' => 'Enviar correo',
        'href' => 'mailto:' . rawurlencode(trim((string) $vcard['correo'])),
        'type' => 'secondary',
    ];
}
if (is_string($vcard['whatsapp'] ?? null) && trim((string) $vcard['whatsapp']) !== '') {
        $whatsapp = preg_replace('/\D/', '', (string) $vcard['whatsapp']) ?? '';
        if ($whatsapp !== '') {
            $quickActions[] = [
            'label' => 'Enviar WhatsApp',
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
if ($productsActionUrl !== null) {
    $quickActions[] = [
        'label' => 'Productos',
        'href' => $productsActionUrl,
        'type' => 'products-mobile',
    ];
}
$contactCards = [
    'telefono_movil' => ['label' => 'Móvil', 'icon' => '▯'],
    'telefono_fijo' => ['label' => 'Teléfono', 'icon' => '☎'],
    'correo' => ['label' => 'Email', 'icon' => '✉'],
];
$visibleContactCards = 0;
$visibleLinks = 0;

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
                <div class="vcard-public__brand-top">
                    <div class="vcard-public__logo" aria-label="Grupo Refrigerantes">
                        <img
                            src="/img/vcard/gr-logo-vcard.png"
                            alt="Grupo Refrigerantes"
                            width="128"
                            height="94"
                        >
                    </div>
                </div>

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

                <div class="vcard-public__brand-identity">
                    <p><?= e($displayName) ?></p>
                    <?php if ($role !== ''): ?>
                        <span><?= e($role) ?></span>
                    <?php elseif ($headline !== '' && $headline !== $displayName): ?>
                        <span><?= e($headline) ?></span>
                    <?php endif; ?>
                </div>

                <div class="vcard-public__service-strip" aria-label="Áreas públicas">
                    <a
                        href="https://www.institutoacr-gruporefrigerantes.com"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="Cursos"
                    >
                        <span class="vcard-public__service-word" aria-hidden="true">
                            <span style="--letter-index: 0">C</span>
                            <span style="--letter-index: 1">u</span>
                            <span style="--letter-index: 2">r</span>
                            <span style="--letter-index: 3">s</span>
                            <span style="--letter-index: 4">o</span>
                            <span style="--letter-index: 5">s</span>
                        </span>
                    </a>
                    <span aria-label="Proyectos, enlace pendiente">
                        <span class="vcard-public__service-word" aria-hidden="true">
                            <span style="--letter-index: 0">P</span>
                            <span style="--letter-index: 1">r</span>
                            <span style="--letter-index: 2">o</span>
                            <span style="--letter-index: 3">y</span>
                            <span style="--letter-index: 4">e</span>
                            <span style="--letter-index: 5">c</span>
                            <span style="--letter-index: 6">t</span>
                            <span style="--letter-index: 7">o</span>
                            <span style="--letter-index: 8">s</span>
                        </span>
                    </span>
                </div>

                <div class="vcard-public__brand-copy">
                    <p>Innovación • Eficiencia • Confianza</p>
                </div>
            </aside>

            <div class="vcard-public__content">
                <header class="vcard-public__header">
                    <div class="vcard-public__headline">
                        <h1 id="vcard-public-title"><?= e($displayName) ?></h1>
                        <?php if ($role !== ''): ?>
                            <p class="vcard-public__name"><?= e($role) ?></p>
                        <?php elseif ($headline !== '' && $headline !== $displayName): ?>
                            <p class="vcard-public__name"><?= e($headline) ?></p>
                        <?php endif; ?>
                        <?php if ($introTitle !== ''): ?>
                            <p class="vcard-public__intro"><?= e($introTitle) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($description !== ''): ?>
                        <p class="vcard-public__description"><?= e($description) ?></p>
                    <?php endif; ?>
                </header>

                <section class="vcard-public__top-panel" aria-label="Contacto y acciones">
                    <div class="vcard-public__contact-panel">
                        <div class="vcard-public__contact-grid" aria-label="Datos de contacto">
                            <?php foreach ($contactCards as $field => $config): ?>
                                <?php if (isset($vcard[$field]) && is_string($vcard[$field]) && trim($vcard[$field]) !== ''): ?>
                                    <?php $visibleContactCards++; ?>
                                    <div class="vcard-public__contact-card">
                                        <span aria-hidden="true"><?= e($config['icon']) ?></span>
                                        <div>
                                            <p><?= e($config['label']) ?></p>
                                            <strong><?= e((string) $vcard[$field]) ?></strong>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($visibleContactCards === 0): ?>
                            <div class="vcard-public__contact-card vcard-public__contact-card--empty">
                                <span aria-hidden="true">i</span>
                                <div>
                                    <p>Información de contacto</p>
                                    <strong>Esta vCard está publicada con información limitada por privacidad.</strong>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($quickActions !== []): ?>
                        <nav class="vcard-public__actions" aria-label="Acciones principales">
                            <?php foreach ($quickActions as $action): ?>
                                <?php
                                $href = (string) ($action['href'] ?? '');
                                $external = str_starts_with($href, 'http');
                                $isWhatsapp = str_starts_with($href, 'https://wa.me/');
                                $isProducts = str_ends_with($href, '/productos');
                                $modifier = ($action['type'] ?? '') === 'primary'
                                    ? ''
                                    : ' vcard-public__action--secondary';
                                if (($action['type'] ?? '') === 'products-mobile') {
                                    $modifier .= ' vcard-public__action--products-mobile';
                                }
                                ?>
                                <a
                                    class="vcard-public__action<?= e($modifier) ?>"
                                    href="<?= e($href) ?>"
                                    <?php if ($isWhatsapp): ?>
                                        aria-label="Contactar por WhatsApp"
                                    <?php elseif ($isProducts): ?>
                                        aria-label="Ver productos públicos"
                                    <?php endif; ?>
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
                </section>

                <?php if ($publicProducts !== []): ?>
                    <section class="vcard-public__section vcard-public__section--products-preview" aria-label="Productos">
                        <div class="vcard-public__section-heading">
                            <div>
                                <h2>Productos</h2>
                            </div>
                            <?php if ($productsUrl !== null): ?>
                                <a class="vcard-public__section-link" href="<?= e($productsUrl) ?>">
                                    Ver todos los productos
                                </a>
                            <?php else: ?>
                                <span><?= count($publicProducts) ?> visibles</span>
                            <?php endif; ?>
                        </div>
                        <div class="vcard-public__products">
                            <?php foreach ($publicProducts as $product): ?>
                                <?php
                                $productName = trim((string) ($product['descripcion'] ?? ''));
                                $publicText = trim((string) ($product['texto_publico'] ?? ''));
                                $meta = array_filter([
                                    $product['id_producto'] ?? null,
                                    $product['marca_nombre'] ?? $product['marca'] ?? null,
                                    $product['linea_nombre'] ?? $product['linea'] ?? null,
                                    $product['clasificacion_nombre'] ?? $product['clasificacion'] ?? null,
                                ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
                                $imageUrl = is_string($product['imagen_url'] ?? null)
                                    ? (string) $product['imagen_url']
                                    : '';
                                $whatsappUrl = is_string($product['whatsapp_url'] ?? null)
                                    ? (string) $product['whatsapp_url']
                                    : '';
                                $productInitial = $productName !== '' ? $productName : (string) ($product['id_producto'] ?? 'P');
                                $productInitial = function_exists('mb_substr')
                                    ? mb_substr($productInitial, 0, 1, 'UTF-8')
                                    : substr($productInitial, 0, 1);
                                ?>
                                <article class="vcard-public__product">
                                    <?php if ($imageUrl !== ''): ?>
                                        <img
                                            class="vcard-public__product-media vcard-public__product-image"
                                            src="<?= e($imageUrl) ?>"
                                            alt=""
                                            loading="lazy"
                                        >
                                    <?php else: ?>
                                        <div class="vcard-public__product-media" aria-hidden="true">
                                            <?= e($productInitial) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <?php if (($product['destacado'] ?? false) === true): ?>
                                            <span class="vcard-public__product-badge">Destacado</span>
                                        <?php endif; ?>
                                        <h3><?= e($productName !== '' ? $productName : 'Producto') ?></h3>
                                        <?php if ($publicText !== ''): ?>
                                            <p><?= e($publicText) ?></p>
                                        <?php endif; ?>
                                        <?php if ($meta !== []): ?>
                                            <p class="vcard-public__product-meta">
                                                <?= e(implode(' · ', array_map('strval', $meta))) ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($whatsappUrl !== ''): ?>
                                            <a
                                                class="vcard-public__product-cta"
                                                href="<?= e($whatsappUrl) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                Solicitar información
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <footer class="vcard-public__footer">
                    <section class="vcard-public__section" aria-labelledby="vcard-public-links">
                        <h2 id="vcard-public-links">Redes y enlaces</h2>
                        <ul class="vcard-public__links">
                            <?php foreach ($links as $field => $label): ?>
                                <?php if (isset($vcard[$field]) && is_string($vcard[$field]) && trim($vcard[$field]) !== ''): ?>
                                    <?php $visibleLinks++; ?>
                                    <li>
                                        <a
                                            href="<?= e((string) $vcard[$field]) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            aria-label="<?= e($label) ?>"
                                            title="<?= e($label) ?>"
                                        >
                                            <span class="vcard-public__link-icon" aria-hidden="true">
                                                <?= $linkIcons[$field] ?? $linkIcons['sitio_web'] ?>
                                            </span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>

                        <?php if ($visibleLinks === 0): ?>
                            <p class="vcard-public__empty">No hay enlaces públicos disponibles.</p>
                        <?php endif; ?>
                    </section>

                    <?php if ($vcfUrl !== null): ?>
                        <a
                            class="vcard-public__action vcard-public__action--download"
                            href="<?= e($vcfUrl) ?>"
                            aria-label="Descargar contacto"
                        >
                            Agregar a contactos
                        </a>
                    <?php endif; ?>

                    <div class="vcard-public__footer-brand" aria-label="Grupo Refrigerantes">
                        <img
                            src="/img/vcard/grupo-refrigerantes-logo-vcard.png"
                            alt="Grupo Refrigerantes"
                            width="3000"
                            height="165"
                        >
                    </div>
                </footer>
            </div>
        </article>
    </main>
</body>
</html>
