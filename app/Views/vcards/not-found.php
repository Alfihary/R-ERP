<?php

declare(strict_types=1);

$appName = is_string($appName ?? null) && $appName !== ''
    ? $appName
    : 'SoporteGR ERP';
?>
<!doctype html>
<html lang="es">
<head>
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=gr1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">
    <title>vCard no disponible · <?= e($appName) ?></title>
    <link rel="stylesheet" href="/css/core/app.css">
    <link rel="stylesheet" href="/css/modules/vcard-public.css">
</head>
<body class="vcard-public-body">
    <main class="vcard-public vcard-public--not-found" id="main-content" tabindex="-1">
        <section class="vcard-public__card vcard-public__card--compact" aria-labelledby="vcard-not-found-title">
            <p class="vcard-public__label">vCard pública</p>
            <h1 id="vcard-not-found-title">Información no disponible</h1>
            <p class="vcard-public__description">
                No es posible mostrar esta vCard pública.
            </p>
        </section>
    </main>
</body>
</html>
