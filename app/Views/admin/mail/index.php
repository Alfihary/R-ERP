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
?>
<section class="config-page">
    <header class="config-page__header">
        <div>
            <p class="eyebrow">Configuración administrativa</p>
            <h1>Correo para tickets de productos</h1>
            <p>Define cuenta emisora, referencia segura de secreto y reglas por evento sin enviar correos reales todavía.</p>
        </div>
        <a class="button button--secondary" href="/tickets/productos">Volver a tickets</a>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if (($errors['general'] ?? '') !== ''): ?>
        <div class="alert alert--danger"><?= e($errors['general']) ?></div>
    <?php endif; ?>

    <section class="config-card">
        <h2>Estado</h2>
        <div class="summary-grid">
            <article>
                <strong>Cuenta activa</strong>
                <span><?= ($status['active_account'] ?? false) === true ? 'Sí' : 'No' ?></span>
            </article>
            <article>
                <strong>Configuración completa</strong>
                <span><?= ($status['complete_configuration'] ?? false) === true ? 'Sí' : 'No' ?></span>
            </article>
            <article>
                <strong>Secreto configurado</strong>
                <span><?= ($status['secret_configured'] ?? false) === true ? 'Sí' : 'No' ?></span>
            </article>
        </div>
        <p class="muted">
            El secreto real debe existir fuera de la base de datos, por ejemplo en una variable local como
            <code>MAIL_TICKETS_PRIMARY_PASSWORD</code>. Esta pantalla nunca muestra ni captura ese valor.
        </p>
    </section>

    <form class="config-form" method="post" action="/admin/correo/cuentas">
        <?= csrf_field($csrf) ?>
        <fieldset>
            <legend>Cuenta emisora</legend>
            <label class="field">
                <span>Nombre de configuración *</span>
                <input name="nombre" required maxlength="120" value="<?= e($value('nombre', 'Tickets de productos')) ?>">
            </label>
            <label class="field">
                <span>Correo remitente *</span>
                <input type="email" name="from_email" required maxlength="190" value="<?= e($value('from_email')) ?>">
            </label>
            <label class="field">
                <span>Nombre remitente *</span>
                <input name="from_name" required maxlength="120" value="<?= e($value('from_name', 'SoporteGR ERP')) ?>">
            </label>
            <label class="field">
                <span>Reply-To</span>
                <input type="email" name="reply_to_email" maxlength="190" value="<?= e($value('reply_to_email')) ?>">
            </label>
            <label class="field">
                <span>SMTP host *</span>
                <input name="smtp_host" required maxlength="190" value="<?= e($value('smtp_host')) ?>" placeholder="smtp.example.com">
            </label>
            <label class="field">
                <span>Puerto *</span>
                <input type="number" name="smtp_port" required min="1" max="65535" value="<?= e($value('smtp_port', '587')) ?>">
            </label>
            <label class="field">
                <span>Cifrado *</span>
                <select name="smtp_encryption" required>
                    <?php foreach (['none' => 'none', 'tls' => 'tls', 'ssl' => 'ssl'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $value('smtp_encryption', 'tls') === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Usuario SMTP *</span>
                <input name="smtp_username" required maxlength="190" value="<?= e($value('smtp_username')) ?>">
            </label>
            <label class="field">
                <span>Referencia de secreto *</span>
                <input name="smtp_secret_ref" required maxlength="120" value="<?= e($value('smtp_secret_ref', 'MAIL_TICKETS_PRIMARY_PASSWORD')) ?>">
                <small>Solo se guarda el nombre de la variable; nunca el secreto real.</small>
            </label>
            <label class="field field--inline">
                <input type="checkbox" name="activo" value="1" <?= $checked($account['activo'] ?? 1) ?>>
                <span>Cuenta activa</span>
            </label>
        </fieldset>
        <div class="form-actions">
            <button class="button" type="submit">Guardar cuenta emisora</button>
        </div>
    </form>

    <form class="config-form" method="post" action="/admin/correo/reglas">
        <?= csrf_field($csrf) ?>
        <fieldset>
            <legend>Reglas de Tickets de Productos</legend>
            <p class="muted">Las direcciones fijas se capturan como listas controladas. No provienen de requests públicos.</p>

            <?php foreach ($events as $event): ?>
                <?php $rule = is_array($rules[$event] ?? null) ? $rules[$event] : []; ?>
                <article class="config-card">
                    <h3><code><?= e((string) $event) ?></code></h3>
                    <label class="field field--inline">
                        <input type="checkbox" name="rules[<?= e((string) $event) ?>][enviar_solicitante]" value="1" <?= $checked($rule['enviar_solicitante'] ?? 1) ?>>
                        <span>Enviar al solicitante</span>
                    </label>
                    <label class="field field--inline">
                        <input type="checkbox" name="rules[<?= e((string) $event) ?>][enviar_responsables]" value="1" <?= $checked($rule['enviar_responsables'] ?? 0) ?>>
                        <span>Enviar a responsables configurados</span>
                    </label>
                    <label class="field">
                        <span>TO fijo controlado</span>
                        <textarea name="rules[<?= e((string) $event) ?>][to]" rows="2" placeholder="uno@empresa.test&#10;dos@empresa.test"><?= e($decodeList($rule['to_json'] ?? null)) ?></textarea>
                    </label>
                    <label class="field">
                        <span>CC</span>
                        <textarea name="rules[<?= e((string) $event) ?>][cc]" rows="2"><?= e($decodeList($rule['cc_json'] ?? null)) ?></textarea>
                    </label>
                    <label class="field">
                        <span>BCC</span>
                        <textarea name="rules[<?= e((string) $event) ?>][bcc]" rows="2"><?= e($decodeList($rule['bcc_json'] ?? null)) ?></textarea>
                    </label>
                    <label class="field field--inline">
                        <input type="checkbox" name="rules[<?= e((string) $event) ?>][activo]" value="1" <?= $checked($rule['activo'] ?? 0) ?>>
                        <span>Regla activa</span>
                    </label>
                </article>
            <?php endforeach; ?>
        </fieldset>
        <div class="form-actions">
            <button class="button" type="submit">Guardar reglas</button>
        </div>
    </form>
</section>
