<?php

declare(strict_types=1);

$credential = is_array($credential ?? null) ? $credential : [];
$stylesheets = is_array($stylesheets ?? null) ? $stylesheets : [];
$appName = is_string($appName ?? null) ? $appName : 'SoporteGR ERP';
$pageTitle = is_string($pageTitle ?? null) ? $pageTitle : 'Verificación de credencial';
$optional = static fn (string $key): ?string =>
    is_string($credential[$key] ?? null) && trim((string) $credential[$key]) !== ''
        ? trim((string) $credential[$key])
        : null;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — <?= e($appName) ?></title>
    <link rel="stylesheet" href="/css/core/app.css">
    <?php foreach ($stylesheets as $stylesheet): ?>
        <?php if (is_string($stylesheet) && $stylesheet !== ''): ?>
            <link rel="stylesheet" href="<?= e($stylesheet) ?>">
        <?php endif; ?>
    <?php endforeach; ?>
</head>
<body class="credential-verify-body">
    <main class="credential-verify" aria-labelledby="credential-verify-title">
        <section class="credential-verify__card">
            <p class="credential-verify__eyebrow">Verificación pública</p>
            <h1 id="credential-verify-title"><?= e((string) ($credential['estado'] ?? 'Credencial válida')) ?></h1>
            <p class="credential-verify__message">
                <?= e((string) ($credential['mensaje'] ?? 'Esta página confirma que la credencial está activa al momento de consulta.')) ?>
            </p>

            <dl class="credential-verify__details">
                <div>
                    <dt>Nombre</dt>
                    <dd><?= e((string) ($credential['nombre_completo'] ?? '')) ?></dd>
                </div>
                <?php if ($optional('puesto') !== null): ?>
                    <div>
                        <dt>Puesto</dt>
                        <dd><?= e((string) $optional('puesto')) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($optional('ubicacion') !== null): ?>
                    <div>
                        <dt>Ubicación laboral</dt>
                        <dd><?= e((string) $optional('ubicacion')) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($credential['emitida_en'])): ?>
                    <div>
                        <dt>Emisión</dt>
                        <dd><?= e((string) $credential['emitida_en']) ?></dd>
                    </div>
                <?php endif; ?>
                <div>
                    <dt>Verificación</dt>
                    <dd><?= e((string) ($credential['verificada_en'] ?? '')) ?></dd>
                </div>
            </dl>
        </section>
    </main>
</body>
</html>
