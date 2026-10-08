<?php

declare(strict_types=1);
?>
<!doctype html>
<html lang="es">
<head>
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=gr1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error interno</title>
</head>
<body>
    <main>
        <h1>Error interno del servidor</h1>
        <p>No fue posible completar la solicitud.</p>
        <?php if (is_string($details ?? null) && $details !== ''): ?>
            <pre><?= e($details) ?></pre>
        <?php endif; ?>
    </main>
</body>
</html>
