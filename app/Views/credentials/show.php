<?php

declare(strict_types=1);

$credential = is_array($credential ?? null) ? $credential : [];
$photo = is_array($credential['foto'] ?? null) ? $credential['foto'] : null;
$tokenState = is_array($tokenState ?? null) ? $tokenState : [];
$canViewCredentialQr = ($canViewCredentialQr ?? false) === true;
$canDownloadCredentialQr = ($canDownloadCredentialQr ?? false) === true;
$line = static fn (string $key): ?string =>
    is_string($credential[$key] ?? null) && trim((string) $credential[$key]) !== ''
        ? trim((string) $credential[$key])
        : null;
$issuedAt = $line('emitida_en') ?? $line('creado_en') ?? 'Sin fecha registrada';
$tokenActive = ($tokenState['activo'] ?? false) === true;
$sessionTokenAvailable = ($tokenState['session_token_available'] ?? false) === true;
?>
<section class="credential-page">
    <header class="credential-page__header">
        <div>
            <p class="eyebrow">Identificación interna</p>
            <h1>Mi credencial</h1>
            <p>
                Credencial visual para uso interno del ERP. La foto se mantiene privada.
            </p>
        </div>
        <button class="button button--secondary credential-page__print" type="button" onclick="window.print()">
            Imprimir
        </button>
    </header>

    <div class="credential-stage">
        <article class="credential-card" aria-labelledby="credential-title">
            <div class="credential-card__stripe" aria-hidden="true"></div>
            <header class="credential-card__top">
                <div>
                    <p>SoporteGR ERP</p>
                    <h2 id="credential-title">Credencial interna</h2>
                </div>
                <span class="credential-card__status"><?= e($credential['estatus'] ?? 'VIGENTE') ?></span>
            </header>

            <div class="credential-card__body">
                <div class="credential-card__photo" aria-label="Estado de foto">
                    <?php if ($photo !== null): ?>
                        <img
                            src="/perfil/credencial/foto"
                            alt="Foto privada de la credencial"
                            loading="lazy"
                            decoding="async"
                        >
                    <?php else: ?>
                        <div class="credential-card__photo-placeholder" aria-hidden="true">
                            <?= e(strtoupper(substr((string) ($credential['nombre_completo'] ?? 'U'), 0, 1))) ?>
                        </div>
                        <strong>Sin foto</strong>
                    <?php endif; ?>
                </div>

                <div class="credential-card__identity">
                    <p class="credential-card__label">Usuario autenticado</p>
                    <h3><?= e($credential['nombre_completo'] ?? '') ?></h3>
                    <p><?= e($credential['username'] ?? '') ?></p>
                </div>
            </div>

            <dl class="credential-card__details">
                <?php foreach ([
                    'email' => 'Email interno',
                    'puesto' => 'Puesto',
                    'telefono_movil' => 'Teléfono móvil',
                    'telefono_fijo' => 'Teléfono fijo',
                    'ubicacion' => 'Ubicación',
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

            <footer class="credential-card__footer">
                <span>La verificación pública no expone la foto</span>
                <span>QR privado autenticado</span>
            </footer>
        </article>

        <aside class="credential-note">
            <h2>Alcance de esta credencial</h2>
            <p>
                Esta pantalla no publica enlaces externos y no expone archivos privados. La foto
                activa se sirve únicamente mediante endpoint privado autenticado.
            </p>
            <p>
                La verificación pública confirma la vigencia de la credencial, pero no publica
                la foto privada del usuario.
                La verificación pública aún no está habilitada para mostrar foto.
            </p>
            <?php if ($photo !== null): ?>
                <dl>
                    <div>
                        <dt>Foto</dt>
                        <dd>Disponible en endpoint privado</dd>
                    </div>
                    <div>
                        <dt>Registrada</dt>
                        <dd><?= e($photo['creado_en'] ?? 'Sin fecha') ?></dd>
                    </div>
                </dl>
            <?php endif; ?>
        </aside>
    </div>

    <section class="credential-token-panel" aria-labelledby="credential-token-title">
        <div>
            <p class="eyebrow">Verificación futura</p>
            <h2 id="credential-token-title">Token y QR privado</h2>
            <p>
                El token plano no se guarda en base de datos. Solo se conserva su hash y el QR se
                muestra dentro del área autenticada.
            </p>
        </div>

        <dl class="credential-token-panel__meta">
            <div>
                <dt>Estado</dt>
                <dd>
                    <span class="credential-token-panel__badge <?= $tokenActive ? 'is-active' : 'is-inactive' ?>">
                        <?= $tokenActive ? 'Activo' : 'Inactivo' ?>
                    </span>
                </dd>
            </div>
            <div>
                <dt>Creado</dt>
                <dd><?= e((string) ($tokenState['creado_en'] ?? 'Sin token activo')) ?></dd>
            </div>
            <?php if (!$tokenActive && !empty($tokenState['revocado_en'])): ?>
                <div>
                    <dt>Revocado</dt>
                    <dd><?= e((string) $tokenState['revocado_en']) ?></dd>
                </div>
            <?php endif; ?>
        </dl>

        <div class="credential-token-panel__actions">
            <?php if ($canViewCredentialQr): ?>
                <form method="POST" action="/perfil/credencial/token/renovar">
                    <input type="hidden" name="_token" value="<?= e($csrf->token()) ?>">
                    <button class="button button--primary" type="submit">Renovar token</button>
                </form>
            <?php endif; ?>

            <?php if ($tokenActive && $canViewCredentialQr): ?>
                <form method="POST" action="/perfil/credencial/token/revocar">
                    <input type="hidden" name="_token" value="<?= e($csrf->token()) ?>">
                    <button class="button button--secondary" type="submit">Revocar token</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($tokenActive && $canViewCredentialQr && $sessionTokenAvailable): ?>
            <div class="credential-token-panel__qr">
                <img src="/perfil/credencial/qr" alt="QR privado de credencial">
                <div>
                    <strong>QR privado disponible</strong>
                    <p>El QR contiene la URL futura de verificación. No se persiste como archivo.</p>
                    <?php if ($canDownloadCredentialQr): ?>
                        <a class="button button--secondary" href="/perfil/credencial/qr/descargar">
                            Descargar QR
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($tokenActive && $canViewCredentialQr): ?>
            <p class="credential-token-panel__notice">
                Hay un token activo, pero el QR solo puede mostrarse tras renovar el token en esta
                sesión segura.
            </p>
        <?php else: ?>
            <p class="credential-token-panel__notice">
                No hay token activo. Renueva el token para habilitar el QR privado.
            </p>
        <?php endif; ?>
    </section>
</section>
