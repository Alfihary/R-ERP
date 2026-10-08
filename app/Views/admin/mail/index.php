<?php

declare(strict_types=1);

$account = is_array($account ?? null) ? $account : [];
$events = is_array($events ?? null) ? $events : [];
$rules = is_array($rules ?? null) ? $rules : [];
$status = is_array($status ?? null) ? $status : [];
$errors = is_array($errors ?? null) ? $errors : [];

$value = static fn (string $key, string $default = ''): string => (string) ($account[$key] ?? $default);
$checked = static fn (mixed $value): string => (int) $value === 1 ? 'checked' : '';
$decodeList = static function (mixed $json): string {
    if (!is_string($json) || trim($json) === '') {
        return '';
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? implode("\n", array_map('strval', $decoded)) : '';
};
$eventMeta = [
    'TICKET_CREADO' => [
        'title' => 'Ticket creado',
        'description' => 'Notifica cuando se registra una nueva solicitud.',
    ],
    'PARTIDA_APROBADA' => [
        'title' => 'Partida aprobada',
        'description' => 'Informa la aprobación de una partida solicitada.',
    ],
    'PARTIDA_RECHAZADA' => [
        'title' => 'Partida rechazada',
        'description' => 'Informa el rechazo y conserva el seguimiento del ticket.',
    ],
    'TICKET_RESUELTO_TOTAL' => [
        'title' => 'Ticket resuelto totalmente',
        'description' => 'Comunica que todas las partidas fueron atendidas.',
    ],
    'TICKET_RESUELTO_PARCIAL' => [
        'title' => 'Ticket resuelto parcialmente',
        'description' => 'Comunica el cierre con partidas atendidas parcialmente.',
    ],
    'TICKET_CANCELADO' => [
        'title' => 'Ticket cancelado',
        'description' => 'Notifica que la solicitud fue cancelada.',
    ],
];
?>
<section class="mail-config-page">
    <header class="mail-config-header">
        <div>
            <p class="mail-config-header__path">Configuración / Correo</p>
            <h1>Correo para tickets de productos</h1>
            <p>Administra la cuenta emisora y decide quién recibe cada evento del flujo de tickets.</p>
        </div>
        <a class="button button--secondary" href="/tickets/productos">Volver a tickets</a>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if (($errors['general'] ?? '') !== ''): ?>
        <div class="alert alert--danger"><?= e($errors['general']) ?></div>
    <?php endif; ?>

    <section class="mail-status-panel" aria-labelledby="mail-status-title">
        <div class="mail-status-panel__heading">
            <div>
                <h2 id="mail-status-title">Estado de la configuración</h2>
                <p>Comprobaciones necesarias antes de habilitar el envío.</p>
            </div>
            <span class="mail-status-panel__security">El secreto nunca se muestra</span>
        </div>
        <dl class="mail-status-list">
            <?php foreach ([
                'active_account' => 'Cuenta activa',
                'complete_configuration' => 'Configuración completa',
                'secret_configured' => 'Secreto configurado',
            ] as $statusKey => $statusLabel): ?>
                <?php $isReady = ($status[$statusKey] ?? false) === true; ?>
                <div class="mail-status-item">
                    <dt><?= e($statusLabel) ?></dt>
                    <dd class="mail-status-badge <?= $isReady ? 'is-ready' : 'is-pending' ?>">
                        <span aria-hidden="true"></span>
                        <?= $isReady ? 'Listo' : 'Pendiente' ?>
                    </dd>
                </div>
            <?php endforeach; ?>
        </dl>
        <p class="mail-security-note">
            La base de datos guarda únicamente la referencia de entorno
            <code>MAIL_TICKETS_PRIMARY_PASSWORD</code>, nunca la contraseña SMTP.
        </p>
    </section>

    <form class="mail-panel mail-account-form" method="post" action="/admin/correo/cuentas">
        <?= csrf_field($csrf) ?>
        <div class="mail-panel__heading">
            <div>
                <h2>Cuenta emisora</h2>
                <p>Datos visibles de conexión. La contraseña permanece fuera del ERP.</p>
            </div>
            <label class="mail-switch">
                <input type="checkbox" name="activo" value="1" <?= $checked($account['activo'] ?? 1) ?>>
                <span class="mail-switch__control" aria-hidden="true"></span>
                <span>Cuenta activa</span>
            </label>
        </div>
        <fieldset class="mail-form-grid">
            <legend class="mail-visually-hidden">Datos de la cuenta emisora</legend>
            <label class="mail-field">
                <span>Nombre de configuración *</span>
                <input name="nombre" required maxlength="120" value="<?= e($value('nombre', 'Tickets de productos')) ?>">
            </label>
            <label class="mail-field">
                <span>Correo remitente *</span>
                <input type="email" name="from_email" required maxlength="190" value="<?= e($value('from_email')) ?>">
            </label>
            <label class="mail-field">
                <span>Nombre remitente *</span>
                <input name="from_name" required maxlength="120" value="<?= e($value('from_name', 'SoporteGR ERP')) ?>">
            </label>
            <label class="mail-field">
                <span>Reply-To</span>
                <input type="email" name="reply_to_email" maxlength="190" value="<?= e($value('reply_to_email')) ?>">
            </label>
            <label class="mail-field mail-field--wide">
                <span>SMTP host *</span>
                <input name="smtp_host" required maxlength="190" value="<?= e($value('smtp_host')) ?>" placeholder="smtp.example.com">
            </label>
            <label class="mail-field">
                <span>Puerto *</span>
                <input type="number" name="smtp_port" required min="1" max="65535" value="<?= e($value('smtp_port', '587')) ?>">
            </label>
            <label class="mail-field">
                <span>Cifrado *</span>
                <select name="smtp_encryption" required>
                    <?php foreach (['none' => 'none', 'tls' => 'tls', 'ssl' => 'ssl'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $value('smtp_encryption', 'tls') === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="mail-field mail-field--wide">
                <span>Usuario SMTP *</span>
                <input name="smtp_username" required maxlength="190" value="<?= e($value('smtp_username')) ?>">
            </label>
            <label class="mail-field mail-field--full">
                <span>Referencia de secreto *</span>
                <input name="smtp_secret_ref" required maxlength="120" value="<?= e($value('smtp_secret_ref', 'MAIL_TICKETS_PRIMARY_PASSWORD')) ?>">
                <small>Solo se guarda el nombre de la variable; nunca el secreto real.</small>
            </label>
        </fieldset>
        <div class="mail-panel__actions">
            <button class="button" type="submit">Guardar cuenta emisora</button>
        </div>
    </form>

    <form class="mail-panel mail-rules-form" method="post" action="/admin/correo/reglas">
        <?= csrf_field($csrf) ?>
        <div class="mail-panel__heading">
            <div>
                <h2>Reglas por evento</h2>
                <p>Una dirección por línea. Los destinatarios fijos nunca provienen de solicitudes públicas.</p>
            </div>
            <span class="mail-rule-count"><?= count($events) ?> eventos</span>
        </div>
        <fieldset class="mail-rules-list">
            <legend class="mail-visually-hidden">Reglas de Tickets de Productos</legend>

            <?php foreach ($events as $event): ?>
                <?php $rule = is_array($rules[$event] ?? null) ? $rules[$event] : []; ?>
                <?php $meta = $eventMeta[(string) $event] ?? [
                    'title' => (string) $event,
                    'description' => 'Configura los destinatarios de este evento.',
                ]; ?>
                <article class="mail-rule">
                    <header class="mail-rule__header">
                        <div>
                            <div class="mail-rule__title-row">
                                <h3><?= e($meta['title']) ?></h3>
                                <code><?= e((string) $event) ?></code>
                            </div>
                            <p><?= e($meta['description']) ?></p>
                        </div>
                        <label class="mail-switch">
                            <input type="checkbox" name="rules[<?= e((string) $event) ?>][activo]" value="1" <?= $checked($rule['activo'] ?? 0) ?>>
                            <span class="mail-switch__control" aria-hidden="true"></span>
                            <span>Regla activa</span>
                        </label>
                    </header>

                    <div class="mail-rule__options" aria-label="Destinatarios automáticos">
                        <label class="mail-check">
                            <input type="checkbox" name="rules[<?= e((string) $event) ?>][enviar_solicitante]" value="1" <?= $checked($rule['enviar_solicitante'] ?? 1) ?>>
                            <span>
                                <strong>Solicitante</strong>
                                <small>Usa el correo registrado en el ticket.</small>
                            </span>
                        </label>
                        <label class="mail-check">
                            <input type="checkbox" name="rules[<?= e((string) $event) ?>][enviar_responsables]" value="1" <?= $checked($rule['enviar_responsables'] ?? 0) ?>>
                            <span>
                                <strong>Responsables configurados</strong>
                                <small>Incluye a quienes dan seguimiento al flujo.</small>
                            </span>
                        </label>
                    </div>

                    <div class="mail-recipient-grid">
                        <label class="mail-field mail-field--to">
                            <span>TO fijo controlado</span>
                            <textarea name="rules[<?= e((string) $event) ?>][to]" rows="3" placeholder="uno@empresa.test&#10;dos@empresa.test"><?= e($decodeList($rule['to_json'] ?? null)) ?></textarea>
                            <small>Destinatarios principales, uno por línea.</small>
                        </label>
                        <label class="mail-field">
                            <span>CC</span>
                            <textarea name="rules[<?= e((string) $event) ?>][cc]" rows="3" placeholder="copia@empresa.test"><?= e($decodeList($rule['cc_json'] ?? null)) ?></textarea>
                            <small>Copia visible opcional.</small>
                        </label>
                        <label class="mail-field">
                            <span>BCC</span>
                            <textarea name="rules[<?= e((string) $event) ?>][bcc]" rows="3" placeholder="auditoria@empresa.test"><?= e($decodeList($rule['bcc_json'] ?? null)) ?></textarea>
                            <small>Copia oculta opcional.</small>
                        </label>
                    </div>
                </article>
            <?php endforeach; ?>
        </fieldset>
        <div class="mail-panel__actions mail-panel__actions--sticky">
            <p>Los cambios aplican a los próximos eventos; no envían correos por sí solos.</p>
            <button class="button" type="submit">Guardar reglas</button>
        </div>
    </form>
</section>
