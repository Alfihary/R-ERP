<?php

declare(strict_types=1);

$credential = is_array($credential ?? null) ? $credential : [];
$photo = is_array($credential['foto'] ?? null) ? $credential['foto'] : null;
$publicVcard = is_array($publicVcard ?? null) ? $publicVcard : [];
$context = is_array($context ?? null) ? $context : [];
$company = is_array($context['active_company'] ?? null) ? $context['active_company'] : [];
$canViewCredentialQr = ($canViewCredentialQr ?? false) === true;

$line = static fn (string $key): ?string =>
    is_string($credential[$key] ?? null) && trim((string) $credential[$key]) !== ''
        ? trim((string) $credential[$key])
        : null;

$contextLine = static fn (array $source, string $key): ?string =>
    is_string($source[$key] ?? null) && trim((string) $source[$key]) !== ''
        ? trim((string) $source[$key])
        : null;

$date = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return 'Sin fecha registrada';
    }

    try {
        $parsed = new DateTimeImmutable($value);
        return $parsed->format('d/m/Y');
    } catch (Throwable) {
        return 'Sin fecha registrada';
    }
};

$displayName = $line('nombre_completo') ?? $line('username') ?? 'Usuario';
$position = $line('puesto') ?? 'Colaborador';
$companyName = $contextLine($company, 'name') ?? 'Grupo Refrigerantes';
$issuedAt = $line('emitida_en') ?? $line('creado_en');
$department = $line('departamento');
$area = $line('area');
$email = $line('email');
$phone = $line('telefono_fijo') ?? $line('telefono_movil');
$whatsapp = $line('whatsapp');

$rawStatus = strtoupper($line('estatus') ?? 'VIGENTE');
$status = $rawStatus === 'VIGENTE' ? 'ACTIVA' : $rawStatus;
$isActive = $rawStatus === 'VIGENTE';

$publicPath = is_string($publicVcard['path'] ?? null)
    ? $publicVcard['path']
    : null;

$publicVcardAvailable =
    ($publicVcard['available'] ?? false) === true
    && $publicPath !== null;

$publicQrPath = $publicVcardAvailable
    ? rtrim($publicPath, '/') . '/qr'
    : null;
?>

<section class="credential-page" data-credential-page>
    <header class="credential-page__header">
        <div>
            <p class="eyebrow">Identificación interna</p>
            <h1>Mi credencial</h1>
            <p>Credencial digital corporativa con acceso directo a tu vCard pública.</p>
        </div>

        <div class="credential-page__actions" data-export-exclude>
            <button
                class="button button--secondary"
                type="button"
                data-credential-flip
                aria-pressed="false"
            >
                Ver reverso
            </button>

            <button
                class="button"
                type="button"
                data-credential-download-front
            >
                Descargar frente PNG
            </button>
        </div>
    </header>

    <div class="credential-stage" data-credential-stage>
        <div
            class="credential-flip"
            data-credential-flip-card
            aria-live="polite"
        >
            <div class="credential-flip__inner">

                <article
                    class="credential-card credential-card--front"
                    data-credential-face="front"
                    aria-label="Frente de la credencial"
                >
                    <header class="credential-brand-row">
                        <div class="credential-brand">
                            <img
                                src="/img/grb.png"
                                alt=""
                                width="42"
                                height="42"
                            >
                        </div>
                    </header>

                    <div class="credential-card__title">
                        <span></span>
                        <strong>CREDENCIAL</strong>
                        <span></span>
                    </div>

                    <div class="credential-card__main">

                        <div
                            class="credential-card__portrait"
                            aria-label="Foto del colaborador"
                        >
                            <?php if ($photo !== null): ?>
                                <img
                                    src="/perfil/credencial/foto"
                                    alt="Foto privada de <?= e($displayName) ?>"
                                    crossorigin="use-credentials"
                                >
                            <?php else: ?>
                                <div
                                    class="credential-card__photo-placeholder"
                                    aria-hidden="true"
                                >
                                    <?= e(strtoupper(substr($displayName, 0, 1))) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="credential-card__identity">
                            <h2><?= e($displayName) ?></h2>
                            <p><?= e($position) ?></p>
                        </div>

                        <section
                            class="credential-vcard"
                            aria-labelledby="credential-vcard-title"
                        >
                            <h3
                                id="credential-vcard-title"
                                class="sr-only"
                            >
                                Código QR de contacto
                            </h3>

                            <?php if ($canViewCredentialQr && $publicVcardAvailable): ?>

                                <a
                                    class="credential-vcard__qr-link"
                                    href="<?= e($publicPath) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label="Abrir la vCard pública de <?= e($displayName) ?>"
                                >
                                    <span class="credential-vcard__qr">
                                        <img
                                            class="credential-vcard__matrix"
                                            src="<?= e($publicQrPath) ?>"
                                            alt="QR hacia la vCard pública"
                                            crossorigin="use-credentials"
                                        >
                                    </span>
                                </a>

                            <?php else: ?>

                                <div class="credential-vcard__qr">
                                    <span>QR no disponible</span>
                                </div>

                            <?php endif; ?>

                            <p>
                                Escanea para ver<br>
                                mi tarjeta virtual
                            </p>
                        </section>

                    </div>

                    <footer class="credential-card__footer">
                        <img
                            class="credential-wordmark"
                            src="/img/LETRAS_B.png"
                            alt="Grupo Refrigerantes"
                        >
                    </footer>
                </article>

                <article
                    class="credential-card credential-card--back"
                    data-credential-face="back"
                    aria-label="Reverso de la credencial"
                    aria-hidden="true"
                >
                    <header class="credential-brand-row credential-brand-row--back">
                        <div class="credential-brand">
                            <img
                                src="/img/grb.png"
                                alt=""
                                width="42"
                                height="42"
                            >
                        </div>

                        <span class="credential-status<?= $isActive ? ' is-active' : '' ?>">
                            <i aria-hidden="true"></i>
                            <?= e($status) ?>
                        </span>
                    </header>

                    <div class="credential-back-content">

                        <section class="credential-info-block">
                            <div class="credential-info-block__heading">
                                <span class="credential-info-block__icon">
                                    <svg
                                        aria-hidden="true"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                    >
                                        <path d="M4 21V7l10-4v18M4 21h16M14 10h6v11M7 10h2m-2 4h2m-2 4h2m8-4h2m-2 4h2"/>
                                    </svg>
                                </span>

                                <h2>Información corporativa</h2>
                            </div>

                            <dl>
                                <div>
                                    <dt>Empresa</dt>
                                    <dd><?= e($companyName) ?></dd>
                                </div>

                                <?php if ($department !== null): ?>
                                    <div>
                                        <dt>Departamento</dt>
                                        <dd><?= e($department) ?></dd>
                                    </div>
                                <?php endif; ?>

                                <?php if ($area !== null): ?>
                                    <div>
                                        <dt>Área</dt>
                                        <dd><?= e($area) ?></dd>
                                    </div>
                                <?php endif; ?>
                            </dl>
                        </section>

                        <?php if ($email !== null || $phone !== null || $whatsapp !== null): ?>

                            <section class="credential-info-block">
                                <div class="credential-info-block__heading">
                                    <span class="credential-info-block__icon">
                                        <svg
                                            aria-hidden="true"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                        >
                                            <rect
                                                x="2"
                                                y="5"
                                                width="20"
                                                height="14"
                                                rx="2"
                                            />
                                            <path d="m3 7 9 7 9-7"/>
                                        </svg>
                                    </span>

                                    <h2>Contacto corporativo</h2>
                                </div>

                                <dl>
                                    <?php if ($email !== null): ?>
                                        <div class="credential-info-block__email">
                                            <dt>Correo corporativo</dt>
                                            <dd><?= e($email) ?></dd>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($phone !== null): ?>
                                        <div>
                                            <dt>Teléfono / extensión</dt>
                                            <dd><?= e($phone) ?></dd>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($whatsapp !== null): ?>
                                        <div>
                                            <dt>WhatsApp empresarial</dt>
                                            <dd><?= e($whatsapp) ?></dd>
                                        </div>
                                    <?php endif; ?>
                                </dl>
                            </section>

                        <?php endif; ?>

                        <?php if ($issuedAt !== null): ?>

                            <section
                                class="credential-info-block credential-info-block--issued"
                                aria-label="Fecha de expedición"
                            >
                                <div class="credential-info-block__heading">
                                    <span class="credential-info-block__icon">
                                        <svg
                                            aria-hidden="true"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                        >
                                            <rect
                                                x="3"
                                                y="5"
                                                width="18"
                                                height="16"
                                                rx="2"
                                            />
                                            <path d="M7 3v4m10-4v4M3 10h18"/>
                                        </svg>
                                    </span>

                                    <h2>Expedición</h2>
                                </div>

                                <p><?= e($date($issuedAt)) ?></p>
                            </section>

                        <?php endif; ?>

                    </div>

                    <footer class="credential-card__legal">
                        <img
                            class="credential-wordmark"
                            src="/img/LETRAS_B.png"
                            alt="Grupo Refrigerantes"
                        >
                    </footer>
                </article>

            </div>
        </div>
    </div>

    <p
        class="credential-export-status"
        data-credential-export-status
        role="status"
        aria-live="polite"
    ></p>
</section>