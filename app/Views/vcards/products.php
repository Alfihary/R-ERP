<?php

declare(strict_types=1);

$vcard = is_array($vcard ?? null) ? $vcard : [];
$publicProducts = is_array($publicProducts ?? null) ? $publicProducts : [];
$appName = isset($appName) && is_string($appName) ? $appName : 'SoporteGR ERP';
$backUrl = isset($backUrl) && is_string($backUrl) ? $backUrl : '/';
$canonicalUrl = isset($canonicalUrl) && is_string($canonicalUrl) ? $canonicalUrl : '';
$metaDescription = isset($metaDescription) && is_string($metaDescription) ? $metaDescription : '';
$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Productos públicos';
$name = trim((string) ($vcard['nombre'] ?? 'Contacto'));
$role = trim((string) ($vcard['puesto'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=gr1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?php if ($metaDescription !== ''): ?>
        <meta name="description" content="<?= e($metaDescription) ?>">
    <?php endif; ?>
    <?php if ($canonicalUrl !== ''): ?>
        <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/css/core/app.css">
    <link rel="stylesheet" href="/css/modules/vcard-public.css">
    <title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
</head>
<body class="vcard-public-body">
    <main class="vcard-public vcard-public--products-page" aria-labelledby="vcard-products-title">
        <article class="vcard-public__card vcard-public__card--products">
            <div class="vcard-public__content">
                <header class="vcard-public__header vcard-public__header--products">
                    <a class="vcard-public__back-link" href="<?= e($backUrl) ?>">← Volver a la vCard</a>
                    <div>
                        <h1 id="vcard-products-title">Productos públicos</h1>
                        <p class="vcard-public__name"><?= e($name !== '' ? $name : 'Contacto') ?></p>
                        <?php if ($role !== ''): ?>
                            <p class="vcard-public__description"><?= e($role) ?></p>
                        <?php endif; ?>
                    </div>
                </header>

                <section class="vcard-public__section" aria-labelledby="vcard-products-list">
                    <div class="vcard-public__section-heading">
                        <div>
                            <h2 id="vcard-products-list">Listado completo</h2>
                        </div>
                        <span><?= count($publicProducts) ?> visibles</span>
                    </div>

                    <?php if ($publicProducts === []): ?>
                        <p class="vcard-public__empty">No hay productos públicos disponibles.</p>
                    <?php else: ?>
                        <div class="vcard-public__products vcard-public__products--full">
                            <?php foreach ($publicProducts as $product): ?>
                                <?php
                                $productName = trim((string) ($product['descripcion'] ?? ''));
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
                                        <h3><?= e($productName !== '' ? $productName : 'Producto') ?></h3>
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
                    <?php endif; ?>
                </section>
            </div>
        </article>
    </main>
</body>
</html>
