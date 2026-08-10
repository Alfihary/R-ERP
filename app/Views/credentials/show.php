<?php

declare(strict_types=1);

$credential = is_array($credential ?? null) ? $credential : [];
$photo = is_array($credential['foto'] ?? null) ? $credential['foto'] : null;
$publicVcard = is_array($publicVcard ?? null) ? $publicVcard : [];
$canViewCredentialQr = ($canViewCredentialQr ?? false) === true;
$canDownloadCredentialQr = ($canDownloadCredentialQr ?? false) === true;
$line = static fn (string $key): ?string =>
    is_string($credential[$key] ?? null) && trim((string) $credential[$key]) !== ''
        ? trim((string) $credential[$key])
        : null;
$issuedAt = $line('emitida_en') ?? $line('creado_en') ?? 'Sin fecha registrada';
$displayName = $line('nombre_completo') ?? $line('username') ?? 'Usuario';
$position = $line('puesto') ?? 'Colaborador';
$publicPath = is_string($publicVcard['path'] ?? null) ? $publicVcard['path'] : null;
$publicUrl = is_string($publicVcard['url'] ?? null) ? $publicVcard['url'] : $publicPath;
$publicVcardAvailable = ($publicVcard['available'] ?? false) === true && $publicPath !== null;
$publicVcardPublished = ($publicVcard['published'] ?? false) === true;
?>
<section class="credential-page">
    <header class="credential-page__header">
        <div>
            <p class="eyebrow">Identificación interna</p>
            <h1>Mi credencial</h1>
            <p>
                Credencial privada del ERP con acceso directo a tu vCard pública mediante QR.
            </p>
        </div>
        <button class="button button--secondary credential-page__print" type="button" onclick="window.print()">
            Imprimir
        </button>
    </header>

    <div class="credential-stage">
        <article class="credential-card" aria-labelledby="credential-title">
            <header class="credential-card__hero">
                <p>SoporteGR ERP</p>
                <span class="credential-card__status"><?= e($credential['estatus'] ?? 'VIGENTE') ?></span>
            </header>

            <div class="credential-card__portrait" aria-label="Foto de la credencial">
                <?php if ($photo !== null): ?>
                    <img
                        src="/perfil/credencial/foto"
                        alt="Foto privada de <?= e($displayName) ?>"
                        loading="lazy"
                        decoding="async"
                    >
                <?php else: ?>
                    <div class="credential-card__photo-placeholder" aria-hidden="true">
                        <?= e(strtoupper(substr($displayName, 0, 1))) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="credential-card__identity">
                <p class="credential-card__label">Credencial interna</p>
                <h2 id="credential-title"><?= e($displayName) ?></h2>
                <p><?= e($position) ?></p>
                <?php if ($line('ubicacion') !== null): ?>
                    <span><?= e((string) $line('ubicacion')) ?></span>
                <?php endif; ?>
            </div>

            <dl class="credential-card__details">
                <?php foreach ([
                    'username' => 'Usuario',
                    'email' => 'Email interno',
                    'telefono_movil' => 'Móvil',
                    'telefono_fijo' => 'Teléfono',
                ] as $key => $label): ?>
                    <?php if ($line($key) !== null): ?>
                        <div>
                            <dt><?= e($label) ?></dt>
                            <dd><?= e((string) $line($key)) ?></dd>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <div>
                    <dt>Emisión</dt>
                    <dd><?= e($issuedAt) ?></dd>
                </div>
            </dl>

            <section class="credential-card__qr" aria-labelledby="credential-qr-title">
                <p class="credential-card__qr-label" id="credential-qr-title">CÓDIGO QR</p>
                <?php if ($canViewCredentialQr && $publicVcardAvailable): ?>
                    <img src="/perfil/credencial/qr" alt="QR hacia la vCard pública">
                    <p>
                        QR de vCard pública:
                        <a href="<?= e($publicPath) ?>" target="_blank" rel="noopener noreferrer">
                            <?= e((string) $publicPath) ?>
                        </a>
                    </p>
                    <?php if ($canDownloadCredentialQr): ?>
                        <a class="button button--secondary" href="/perfil/credencial/qr/descargar">
                            Descargar QR
                        </a>
                    <?php endif; ?>
                <?php elseif (!$canViewCredentialQr): ?>
                    <p>No tienes permiso para ver el QR de la credencial.</p>
                <?php else: ?>
                    <p>Configura primero tu vCard para habilitar el QR público.</p>
                <?php endif; ?>
            </section>
        </article>

        <aside class="credential-note">
            <div>
                <p class="eyebrow">Puente público controlado</p>
                <h2>QR hacia vCard pública</h2>
                <p>
                    El QR de esta credencial apunta a la vCard pública del usuario. No contiene
                    token de verificación, rutas privadas ni paths físicos de archivos.
                </p>
            </div>

            <dl>
                <div>
                    <dt>Destino</dt>
                    <dd><?= e($publicUrl !== null ? $publicUrl : 'vCard no disponible') ?></dd>
                </div>
                <div>
                    <dt>Publicación</dt>
                    <dd><?= $publicVcardPublished ? 'Publicada' : 'No publicada' ?></dd>
                </div>
                <div>
                    <dt>Privacidad</dt>
                    <dd>La vCard solo muestra campos habilitados públicamente.</dd>
                </div>
                <?php if ($photo !== null): ?>
                    <div>
                        <dt>Foto privada</dt>
                        <dd>Servida únicamente por endpoint autenticado.</dd>
                    </div>
                <?php endif; ?>
            </dl>
        </aside>
    </div>
</section>
