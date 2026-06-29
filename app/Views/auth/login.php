<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService) {
    throw new RuntimeException('CSRF service is required by the login view.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión</title>
</head>
<body>
    <main>
        <h1>Iniciar sesión</h1>
        <?php if (is_string($error ?? null) && $error !== ''): ?>
            <p role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <form method="post" action="/login">
            <?= csrf_field($csrf) ?>
            <div>
                <label for="login">Email o nombre de usuario</label>
                <input id="login" name="login" type="text" autocomplete="username" required>
            </div>
            <div>
                <label for="password">Contraseña</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                >
            </div>
            <button type="submit">Entrar</button>
        </form>
    </main>
</body>
</html>
