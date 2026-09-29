<?php

declare(strict_types=1);

$message = is_array($message ?? null) ? $message : [];
$recipients = is_array($message['recipients'] ?? null) ? $message['recipients'] : [];
$status = (string) ($message['status'] ?? '');
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
            <p>Detalle técnico read-only. El HTML se muestra como texto escapado y nunca se ejecuta.</p>
        </div>
        <a class="button button--secondary" href="/admin/correo/cola">Volver a la cola</a>
    </header>

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
