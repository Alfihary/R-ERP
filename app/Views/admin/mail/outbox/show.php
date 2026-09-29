<?php

declare(strict_types=1);

$message = is_array($message ?? null) ? $message : [];
$recipients = is_array($message['recipients'] ?? null) ? $message['recipients'] : [];
$status = (string) ($message['status'] ?? '');
$canRetry = ($canRetry ?? false) === true;
$canCancel = ($canCancel ?? false) === true;
$actionNotice = is_array($actionNotice ?? null) ? $actionNotice : null;
$statusMeta = [
    'PENDIENTE' => ['label' => 'Pendiente', 'class' => 'is-pending', 'symbol' => '○'],
    'ENVIANDO' => ['label' => 'Enviando', 'class' => 'is-sending', 'symbol' => '↻'],
    'ENVIADO' => ['label' => 'Enviado', 'class' => 'is-sent', 'symbol' => '✓'],
    'ERROR' => ['label' => 'Error', 'class' => 'is-error', 'symbol' => '!'],
    'CANCELADO' => ['label' => 'Cancelado', 'class' => 'is-cancelled', 'symbol' => '×'],
][$status] ?? ['label' => 'Desconocido', 'class' => 'is-unknown', 'symbol' => '?'];
$dateFields = [
    'created_at' => 'Creado',
    'updated_at' => 'Actualizado',
    'ultimo_intento_at' => 'Último intento',
    'enviado_at' => 'Enviado',
    'cancelado_at' => 'Cancelado',
];
?>
<section class="mail-outbox-page mail-outbox-detail">
    <header class="mail-outbox-header">
        <div>
            <p class="eyebrow">Configuración / Correo / Cola</p>
            <h1>Mensaje #<?= e($message['id'] ?? '') ?></h1>
            <p>Detalle técnico y acciones administrativas controladas. El HTML se muestra escapado y nunca se ejecuta.</p>
        </div>
        <a class="button button--secondary" href="/admin/correo/cola">Volver a la cola</a>
    </header>

    <?php if ($actionNotice !== null): ?>
        <div class="alert alert--<?= e($actionNotice['type'] ?? 'warning') ?>" role="status">
            <?= e($actionNotice['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <section class="mail-outbox-detail__hero">
        <div>
            <span class="mail-state <?= e($statusMeta['class']) ?>">
                <span aria-hidden="true"><?= e($statusMeta['symbol']) ?></span><?= e($statusMeta['label']) ?>
            </span>
            <h2><?= e($message['subject'] ?? 'Sin asunto') ?></h2>
            <p><code><?= e($message['evento'] ?? '') ?></code> · Plantilla <code><?= e($message['plantilla'] ?? '') ?></code></p>
        </div>
        <div class="mail-outbox-attempts">
            <small>Intentos</small>
            <strong><?= e($message['intentos'] ?? 0) ?> / <?= e($message['max_intentos'] ?? 0) ?></strong>
        </div>
    </section>

    <?php if (!empty($message['error_mensaje_seguro'])): ?>
        <div class="alert alert--danger" role="status">
            <strong>Error seguro:</strong> <?= e($message['error_mensaje_seguro']) ?>
        </div>
    <?php endif; ?>

    <?php if ($canRetry || $canCancel || $status === 'ENVIANDO' || ($status === 'ERROR' && (int) ($message['intentos'] ?? 0) >= (int) ($message['max_intentos'] ?? 0))): ?>
        <section class="mail-outbox-panel mail-outbox-actions" aria-labelledby="mail-outbox-actions-title">
            <header>
                <div>
                    <h2 id="mail-outbox-actions-title">Acciones administrativas</h2>
                    <p>Las acciones cambian únicamente la cola; nunca envían SMTP desde esta pantalla.</p>
                </div>
            </header>

            <?php if ($status === 'ENVIANDO'): ?>
                <div class="alert alert--warning">Procesamiento en curso. No hay acciones manuales disponibles.</div>
            <?php elseif ($status === 'ERROR' && (int) ($message['intentos'] ?? 0) >= (int) ($message['max_intentos'] ?? 0)): ?>
                <div class="alert alert--warning">Máximo de intentos alcanzado. El mensaje no puede reintentarse.</div>
            <?php endif; ?>

            <div class="mail-outbox-actions__grid">
                <?php if ($canRetry): ?>
                    <form method="post" action="/admin/correo/cola/reintentar" class="mail-outbox-action-card">
                        <?= csrf_field($csrf) ?>
                        <input type="hidden" name="id" value="<?= e($message['id'] ?? '') ?>">
                        <div>
                            <h3>Reintentar procesamiento</h3>
                            <p>Devuelve el mensaje a pendiente sin reiniciar intentos. Se procesará posteriormente.</p>
                        </div>
                        <button class="button button--secondary" type="submit">Reintentar</button>
                    </form>
                <?php endif; ?>

                <?php if ($canCancel): ?>
                    <form method="post" action="/admin/correo/cola/cancelar" class="mail-outbox-action-card mail-outbox-action-card--cancel">
                        <?= csrf_field($csrf) ?>
                        <input type="hidden" name="id" value="<?= e($message['id'] ?? '') ?>">
                        <div class="field">
                            <label for="mail-cancel-reason">Motivo de cancelación</label>
                            <textarea id="mail-cancel-reason" name="motivo" rows="3" maxlength="300" required></textarea>
                            <small>Obligatorio, máximo 300 caracteres. Quedará registrado en auditoría.</small>
                        </div>
                        <button class="button button--secondary mail-outbox-cancel-button" type="submit">Cancelar mensaje</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="mail-outbox-detail__grid">
        <section class="mail-outbox-panel">
            <header><h2>Ticket y contexto</h2></header>
            <dl class="mail-outbox-definition-list">
                <div><dt>Ticket</dt><dd>
                    <?php if (($message['ticket_link_allowed'] ?? false) === true): ?>
                        <a href="/tickets/productos/<?= e($message['ticket_id'] ?? '') ?>"><?= e($message['folio'] ?? ('#' . ($message['ticket_id'] ?? ''))) ?></a>
                    <?php else: ?>
                        <?= e($message['folio'] ?? ('#' . ($message['ticket_id'] ?? ''))) ?> <small>(sin enlace)</small>
                    <?php endif; ?>
                </dd></div>
                <div><dt>Estado ticket</dt><dd><?= e($message['ticket_estado'] ?? '—') ?></dd></div>
                <div><dt>Empresa</dt><dd><?= e($message['empresa_nombre'] ?? ('#' . ($message['empresa_id'] ?? ''))) ?></dd></div>
                <div><dt>Almacén</dt><dd><?= e($message['almacen_nombre'] ?? ('#' . ($message['almacen_id'] ?? ''))) ?></dd></div>
                <div><dt>Partida</dt><dd><?= e($message['numero_partida'] ?? 'No aplica') ?></dd></div>
            </dl>
        </section>

        <section class="mail-outbox-panel">
            <header><h2>Fechas</h2></header>
            <dl class="mail-outbox-definition-list">
                <?php foreach ($dateFields as $field => $label): ?>
                    <div><dt><?= e($label) ?></dt><dd><?= e($message[$field] ?? '—') ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </section>
    </div>

    <section class="mail-outbox-panel">
        <header>
            <div><h2>Destinatarios</h2><p>Sobre normalizado almacenado para este mensaje.</p></div>
        </header>
        <?php if (($recipients['available'] ?? false) !== true): ?>
            <div class="alert alert--warning">Datos de destinatarios no disponibles o con formato no reconocido.</div>
        <?php endif; ?>
        <div class="mail-recipient-columns">
            <?php foreach (['to' => 'TO', 'cc' => 'CC', 'bcc' => 'BCC'] as $group => $label): ?>
                <div>
                    <h3><?= e($label) ?></h3>
                    <?php $emails = is_array($recipients[$group] ?? null) ? $recipients[$group] : []; ?>
                    <?php if ($emails === []): ?><p class="mail-outbox-muted">Sin destinatarios.</p><?php endif; ?>
                    <ul>
                        <?php foreach ($emails as $email): ?><li><?= e($email) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="mail-outbox-panel">
        <header><div><h2>Cuerpo de texto</h2><p>Contenido plano enviado o pendiente de envío.</p></div></header>
        <pre class="mail-message-source"><?= e($message['text'] ?? '') ?></pre>
    </section>

    <section class="mail-outbox-panel">
        <header><div><h2>HTML escapado</h2><p>Fuente visible como texto; scripts, etiquetas y eventos no se interpretan.</p></div></header>
        <pre class="mail-message-source"><?= e($message['html'] ?? '') ?></pre>
    </section>

    <section class="mail-outbox-panel mail-outbox-technical">
        <header><h2>Identificadores técnicos</h2></header>
        <dl class="mail-outbox-definition-list">
            <div><dt>ID</dt><dd><?= e($message['id'] ?? '') ?></dd></div>
            <div><dt>Clave de deduplicación</dt><dd><code><?= e($message['dedupe_key'] ?? '') ?></code></dd></div>
            <div><dt>Usuario creador</dt><dd><?= e($message['creado_por_usuario_id'] ?? 'Sistema') ?></dd></div>
        </dl>
    </section>
</section>
