<?php

declare(strict_types=1);

$safeAppName = e($appName ?? 'SoporteGR ERP');
?>
<!doctype html>
<html lang="es">
<head>
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=gr1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $safeAppName ?></title>
</head>
<body>
    <main>
        <h1><?= $safeAppName ?></h1>
        <p>Arranque técnico CONFIG-0 activo.</p>
    </main>
</body>
</html>
