<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="es">
<head>
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=gr1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1c4e74">
    <meta name="csrf-token" content="<?= e($csrf->token()) ?>">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="stylesheet" href="/css/modules/login.css">
    <link rel="stylesheet" href="/css/modules/pwa.css">
    <script src="/js/pwa.js" defer></script>
    <script src="/js/webauthn-support.js" defer></script>
    <script src="/js/device-unlock.js" defer></script>
    <title>Desbloquear · Grupo Refrigerantes</title>
</head>
<body data-device-page="unlock">
    <main class="login-page">
        <section class="login-card" aria-labelledby="unlock-title">
            <div class="brand-mark"><img src="/img/grb.png?v=original-alpha-2" alt="Grupo Refrigerantes" width="64" height="64"></div>
            <h1 id="unlock-title">Sesión bloqueada</h1>
            <p class="welcome"><?= $expired ? 'La protección de esta sesión venció. Ingresa de nuevo con tu contraseña.' : 'Usa la huella, el rostro o el PIN de tu dispositivo para continuar.' ?></p>
            <?php if (!$expired): ?>
                <button type="button" class="submit-button device-wide" data-device-unlock hidden>Desbloquear con huella / dispositivo</button>
            <?php endif; ?>
            <p data-device-message role="status" aria-live="polite"></p>
            <form action="/logout" method="post">
                <?= csrf_field($csrf) ?>
                <button type="submit" class="device-secondary">Cerrar sesión e ingresar con contraseña</button>
            </form>
        </section>
    </main>
</body>
</html>
