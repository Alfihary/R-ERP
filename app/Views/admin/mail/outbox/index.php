<?php

declare(strict_types=1);

$result = is_array($result ?? null) ? $result : [];
$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
$filters = is_array($result['filters'] ?? null) ? $result['filters'] : [];
$errors = is_array($result['validation_errors'] ?? null) ? $result['validation_errors'] : [];
$counts = is_array($counts ?? null) ? $counts : [];
$statuses = is_array($statuses ?? null) ? $statuses : [];
$events = is_array($events ?? null) ? $events : [];
$page = (int) ($result['page'] ?? 1);
$pages = (int) ($result['pages'] ?? 1);
$total = (int) ($result['total'] ?? 0);
$perPage = (int) ($result['per_page'] ?? 25);
$permissionUsed = is_string($permissionUsed ?? null) ? $permissionUsed : '';

$statusMeta = [
    'PENDIENTE' => ['label' => 'Pendiente', 'class' => 'is-pending', 'symbol' => '○'],
    'ENVIANDO' => ['label' => 'Enviando', 'class' => 'is-sending', 'symbol' => '↻'],
    'ENVIADO' => ['label' => 'Enviado', 'class' => 'is-sent', 'symbol' => '✓'],
    'ERROR' => ['label' => 'Error', 'class' => 'is-error', 'symbol' => '!'],
    'CANCELADO' => ['label' => 'Cancelado', 'class' => 'is-cancelled', 'symbol' => '×'],
];
$queryString = static function (int $targetPage) use ($filters): string {
    $query = [];
    foreach ($filters as $key => $value) {
        if ($key === 'page' || $value === '' || $value === null) {
            continue;
        }
        $query[$key] = $value;
    }
    $query['page'] = $targetPage;

    return http_build_query($query);
};
?>
<section class="mail-outbox-page">
    <header class="mail-outbox-header">
        <div>
            <p class="eyebrow">Configuración / Correo</p>
            <h1>Cola de correo</h1>
            <p>Consulta read-only de notificaciones. Esta pantalla no envía, reintenta, cancela ni modifica mensajes.</p>
        </div>
        <div class="mail-outbox-header__actions">
            <span class="mail-outbox-permission">Permiso: <?= e($permissionUsed) ?></span>
            <a class="button button--secondary" href="/admin/correo">Configuración</a>
        </div>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert--warning" role="status">
            <strong>Algunos filtros fueron ignorados.</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="mail-outbox-metrics" aria-label="Resumen por estado">
        <?php foreach ($statusMeta as $status => $meta): ?>
            <a class="mail-outbox-metric <?= e($meta['class']) ?>" href="/admin/correo/cola?estado=<?= e($status) ?>">
                <span class="mail-outbox-metric__symbol" aria-hidden="true"><?= e($meta['symbol']) ?></span>
                <span>
                    <small><?= e($meta['label']) ?></small>
                    <strong><?= e((int) ($counts[$status] ?? 0)) ?></strong>
                </span>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="mail-outbox-filters" method="get" action="/admin/correo/cola">
        <label class="field">
            <span>Estado</span>
            <select name="estado">
                <option value="">Todos</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= ($filters['estado'] ?? '') === $status ? 'selected' : '' ?>>
                        <?= e($statusMeta[$status]['label'] ?? $status) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field field--event">
            <span>Evento</span>
            <select name="evento">
                <option value="">Todos</option>
                <?php foreach ($events as $event): ?>
                    <option value="<?= e($event) ?>" <?= ($filters['evento'] ?? '') === $event ? 'selected' : '' ?>>
                        <?= e($event) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Folio</span>
            <input name="folio" maxlength="80" value="<?= e($filters['folio'] ?? '') ?>" placeholder="QASMTP-000001">
        </label>
        <label class="field">
            <span>ID ticket</span>
            <input inputmode="numeric" name="ticket_id" value="<?= e($filters['ticket_id'] ?? '') ?>" placeholder="34">
        </label>
        <label class="field">
            <span>Por página</span>
            <select name="per_page">
                <?php foreach ([25, 50, 100] as $option): ?>
                    <option value="<?= e($option) ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="mail-outbox-filters__actions">
            <button class="button" type="submit">Aplicar filtros</button>
            <a class="button button--ghost" href="/admin/correo/cola">Limpiar</a>
        </div>
    </form>

    <div class="mail-outbox-summary" aria-live="polite">
        <span><strong><?= e($total) ?></strong> mensajes</span>
        <span>Página <?= e($page) ?> de <?= e($pages) ?></span>
        <span>Más recientes primero</span>
    </div>

    <div class="table-scroll mail-outbox-table-scroll" role="region" aria-label="Mensajes de la cola" tabindex="0">
        <table class="data-table mail-outbox-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Ticket</th>
                    <th>Evento</th>
                    <th>Estado</th>
                    <th>Destinatario</th>
                    <th>Intentos</th>
                    <th>Último intento</th>
                    <th>Enviado</th>
                    <th>Creado</th>
                    <th><span class="mail-visually-hidden">Acciones</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr class="mail-outbox-empty">
                        <td colspan="10">No hay mensajes para los filtros seleccionados.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $meta = $statusMeta[(string) ($row['status'] ?? '')] ?? ['label' => 'Desconocido', 'class' => 'is-unknown', 'symbol' => '?']; ?>
                    <tr>
                        <td data-label="ID"><strong>#<?= e($row['id'] ?? '') ?></strong></td>
                        <td data-label="Ticket">
                            <strong><?= e($row['folio'] ?? ('#' . ($row['ticket_id'] ?? ''))) ?></strong>
                            <small>Ticket #<?= e($row['ticket_id'] ?? '') ?><?= !empty($row['numero_partida']) ? ' · Partida ' . e($row['numero_partida']) : '' ?></small>
                        </td>
                        <td data-label="Evento"><code><?= e($row['evento'] ?? '') ?></code></td>
                        <td data-label="Estado">
                            <span class="mail-state <?= e($meta['class']) ?>">
                                <span aria-hidden="true"><?= e($meta['symbol']) ?></span><?= e($meta['label']) ?>
                            </span>
                        </td>
                        <td data-label="Destinatario" class="mail-outbox-email"><?= e($row['destinatario_email'] ?? '') ?></td>
                        <td data-label="Intentos"><?= e($row['intentos'] ?? 0) ?> / <?= e($row['max_intentos'] ?? 0) ?></td>
                        <td data-label="Último intento"><?= e($row['ultimo_intento_at'] ?? '—') ?></td>
                        <td data-label="Enviado"><?= e($row['enviado_at'] ?? '—') ?></td>
                        <td data-label="Creado"><?= e($row['created_at'] ?? '') ?></td>
                        <td data-label="Detalle">
                            <a class="button button--secondary button--sm" href="/admin/correo/cola/detalle?id=<?= e($row['id'] ?? '') ?>">Ver detalle</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="mail-outbox-pagination" aria-label="Paginación de la cola">
            <?php if ($page > 1): ?>
                <a class="button button--secondary button--sm" href="?<?= e($queryString($page - 1)) ?>">Anterior</a>
            <?php endif; ?>
            <span>Página <?= e($page) ?> de <?= e($pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="button button--secondary button--sm" href="?<?= e($queryString($page + 1)) ?>">Siguiente</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
