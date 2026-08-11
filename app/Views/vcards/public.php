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
$role = isset($vcard['puesto']) && is_string($vcard['puesto'])
    ? trim((string) $vcard['puesto'])
    : '';
$introTitle = $headline !== '' && $headline !== $displayName && $headline !== $role
    ? $headline
    : '';
$details = [
    'puesto' => 'Puesto',
    'empresa' => 'Empresa',
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
$linkIcons = [
    'sitio_web' => '🌐',
    'linkedin' => 'in',
    'facebook' => 'f',
    'instagram' => '◎',
    'google_maps' => '⌖',
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
if ($vcfUrl !== null) {
    $quickActions[] = ['label' => 'Agregar a contactos', 'href' => $vcfUrl, 'type' => 'secondary'];
}

$contactCards = [
    'telefono_movil' => ['label' => 'Móvil', 'icon' => '▯'],
    'telefono_fijo' => ['label' => 'Teléfono', 'icon' => '☎'],
    'correo' => ['label' => 'Email', 'icon' => '✉'],
];
$secondaryDetails = [
    'empresa' => 'Empresa',
    'ubicacion' => 'Ubicación',
];
$visibleContactCards = 0;
$visibleSecondaryDetails = 0;
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
                        <span>GR</span>
                        <strong>Grupo Refrigerantes</strong>
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

                <div class="vcard-public__service-strip" aria-label="Áreas de servicio">
                    <span>❄ Refrigeración</span>
                    <span>⚙ Soluciones técnicas</span>
                    <span>◇ Soporte</span>
                </div>

                <div class="vcard-public__brand-copy">
                    <p>Sistemas de Refrigeración y Climatización</p>
                    <p>Innovación • Eficiencia • Confianza</p>
                </div>
            </aside>

            <div class="vcard-public__content">
                <header class="vcard-public__header">
                    <h1 id="vcard-public-title"><?= e($displayName) ?></h1>
                    <?php if ($role !== ''): ?>
                        <p class="vcard-public__name"><?= e($role) ?></p>
                    <?php elseif ($headline !== '' && $headline !== $displayName): ?>
                        <p class="vcard-public__name"><?= e($headline) ?></p>
                    <?php endif; ?>
                    <?php if ($introTitle !== ''): ?>
                        <p class="vcard-public__intro"><?= e($introTitle) ?></p>
                    <?php endif; ?>
                    <?php if ($description !== ''): ?>
                        <p class="vcard-public__description"><?= e($description) ?></p>
                    <?php endif; ?>

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

                    <?php if ($quickActions !== []): ?>
                        <nav class="vcard-public__actions" aria-label="Acciones de contacto">
                            <?php foreach ($quickActions as $action): ?>
                                <?php
                                $href = (string) ($action['href'] ?? '');
                                $external = str_starts_with($href, 'http');
                                $isWhatsapp = str_starts_with($href, 'https://wa.me/');
                                $isVcf = str_ends_with($href, '/vcf');
                                $modifier = ($action['type'] ?? '') === 'primary'
                                    ? ''
                                    : ' vcard-public__action--secondary';
                                ?>
                                <a
                                    class="vcard-public__action<?= e($modifier) ?>"
                                    href="<?= e($href) ?>"
                                    <?php if ($isWhatsapp): ?>
                                        aria-label="Contactar por WhatsApp"
                                    <?php elseif ($isVcf): ?>
                                        aria-label="Descargar contacto"
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
                </header>

                <?php if ($visibleContactCards === 0): ?>
                    <section class="vcard-public__section" aria-labelledby="vcard-public-limited">
                        <h2 id="vcard-public-limited">Información de contacto</h2>
                        <p class="vcard-public__empty">
                            Esta vCard está publicada con información limitada por privacidad.
                        </p>
                    </section>
                <?php endif; ?>

                <section class="vcard-public__section vcard-public__section--compact" aria-labelledby="vcard-public-details">
                    <h2 id="vcard-public-details">Perfil público</h2>
                    <dl class="vcard-public__details">
                        <?php foreach ($secondaryDetails as $field => $label): ?>
                            <?php if (isset($vcard[$field]) && is_string($vcard[$field]) && trim($vcard[$field]) !== ''): ?>
                                <?php $visibleSecondaryDetails++; ?>
                                <div>
                                    <dt><?= e($label) ?></dt>
                                    <dd><?= e((string) $vcard[$field]) ?></dd>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </dl>

                    <?php if ($visibleSecondaryDetails === 0): ?>
                        <p class="vcard-public__empty">
                            No hay datos adicionales publicados.
                        </p>
                    <?php endif; ?>
                </section>

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
                                        <span aria-hidden="true"><?= e($linkIcons[$field] ?? '↗') ?></span>
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
                            <div>
                                <h2 id="vcard-public-products">Productos relacionados</h2>
                            </div>
                            <span><?= count($publicProducts) ?> visibles</span>
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
                                    $product['unidad_codigo'] ?? $product['unidad'] ?? null,
                                ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
                                $productInitial = $productName !== '' ? $productName : (string) ($product['id_producto'] ?? 'P');
                                $productInitial = function_exists('mb_substr')
                                    ? mb_substr($productInitial, 0, 1, 'UTF-8')
                                    : substr($productInitial, 0, 1);
                                ?>
                                <article class="vcard-public__product">
                                    <div class="vcard-public__product-media" aria-hidden="true">
                                        <?= e($productInitial) ?>
                                    </div>
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
                                    </div>
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
