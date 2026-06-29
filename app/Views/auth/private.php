<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService || !is_array($user ?? null)) {
    throw new RuntimeException('Authenticated view context is incomplete.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sesión autenticada</title>
</head>
<body>
    <main>
        <h1>Sesión autenticada</h1>
        <p>Usuario: <?= e($user['username'] ?? '') ?></p>
        <p>Email: <?= e($user['email'] ?? '') ?></p>
        <form method="post" action="/logout">
            <?= csrf_field($csrf) ?>
            <button type="submit">Cerrar sesión</button>
        </form>
    </main>
</body>
</html>
