<?php

declare(strict_types=1);

$credential = is_array($credential ?? null) ? $credential : [];
$photo = is_array($credential['foto'] ?? null) ? $credential['foto'] : null;
$line = static fn (string $key): ?string =>
    is_string($credential[$key] ?? null) && trim((string) $credential[$key]) !== ''
        ? trim((string) $credential[$key])
        : null;
$issuedAt = $line('emitida_en') ?? $line('creado_en') ?? 'Sin fecha registrada';
?>
<section class="credential-page">
    <header class="credential-page__header">
        <div>
            <p class="eyebrow">Identificación interna</p>
            <h1>Mi credencial</h1>
            <p>
                Credencial visual para uso interno del ERP. No verificable públicamente todavía.
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
                        <strong>Foto registrada</strong>
                        <span><?= e($photo['mime'] ?? 'Imagen privada') ?></span>
                    <?php else: ?>
                        <strong>Sin foto</strong>
                        <span>Archivo privado no expuesto</span>
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
                <span>No verificable públicamente todavía</span>
                <span>Sin QR público</span>
            </footer>
        </article>

        <aside class="credential-note">
            <h2>Alcance de esta credencial</h2>
            <p>
                Esta pantalla no genera códigos de verificación, no publica enlaces externos
                y no expone archivos privados. La foto activa se indica como metadata segura.
            </p>
            <?php if ($photo !== null): ?>
                <dl>
                    <div>
                        <dt>Foto</dt>
                        <dd><?= e($photo['nombre_archivo'] ?? 'Registrada') ?></dd>
                    </div>
                    <div>
                        <dt>Registrada</dt>
                        <dd><?= e($photo['creado_en'] ?? 'Sin fecha') ?></dd>
                    </div>
                </dl>
            <?php endif; ?>
        </aside>
    </div>
</section>
