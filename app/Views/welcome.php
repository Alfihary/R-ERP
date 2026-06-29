<?php

declare(strict_types=1);

$safeAppName = htmlspecialchars(
    (string) ($appName ?? 'SoporteGR ERP'),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<!doctype html>
<html lang="es">
<head>
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
