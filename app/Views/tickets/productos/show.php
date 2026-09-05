<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService || !is_array($ticket ?? null) || !is_array($errors ?? null)) {
    throw new RuntimeException('Product ticket detail data is incomplete.');
}

$ticketId = (string) ($ticket['id'] ?? '');
$partidas = is_array($ticket['partidas'] ?? null) ? $ticket['partidas'] : [];
$eventos = is_array($ticket['eventos'] ?? null) ? $ticket['eventos'] : [];
$comentarios = is_array($ticket['comentarios'] ?? null) ? $ticket['comentarios'] : [];
$estadoTicket = (string) ($ticket['estado'] ?? '');
$permissions = is_array($permissions ?? null) ? $permissions : [];
$canResolve = ($permissions['canResolve'] ?? false) === true;
$canCancel = ($permissions['canCancel'] ?? false) === true;
$canViewAttachments = ($permissions['canViewAttachments'] ?? false) === true;
$canCreateComments = ($permissions['canCreateComments'] ?? false) === true;
$canResendEmail = ($permissions['canResendEmail'] ?? false) === true;
$canViewEvents = ($permissions['canViewEvents'] ?? false) === true;
$isCancelled = $estadoTicket === 'CANCELADO';
$formatValue = static fn (mixed $value): string => trim((string) ($value ?? '')) !== '' ? (string) $value : '—';
$countByState = static function (array $items, string $state): int {
    $count = 0;

    foreach ($items as $item) {
        if (is_array($item) && (string) ($item['estado'] ?? '') === $state) {
            $count++;
        }
    }

    return $count;
};
$totalPartidas = (int) ($ticket['total_partidas'] ?? count($partidas));
$partidasEnRevision = (int) ($ticket['partidas_en_revision'] ?? $countByState($partidas, 'EN_REVISION'));
$partidasAprobadas = (int) ($ticket['partidas_aprobadas'] ?? $countByState($partidas, 'APROBADA'));
$partidasRechazadas = (int) ($ticket['partidas_rechazadas'] ?? $countByState($partidas, 'RECHAZADA'));
$comentariosPorPartida = [];
$comentariosGenerales = [];

foreach ($comentarios as $comentario) {
    if (!is_array($comentario)) {
        continue;
    }

    $comentarioPartidaId = (string) ($comentario['partida_id'] ?? '');

    if ($comentarioPartidaId !== '' && $comentarioPartidaId !== '0') {
        $comentariosPorPartida[$comentarioPartidaId][] = $comentario;
        continue;
    }

    $comentariosGenerales[] = $comentario;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ticket <?= e($ticket['folio'] ?? '') ?></title>
    <link rel="stylesheet" href="/css/core/app.css">
    <link rel="stylesheet" href="/css/modules/tickets-productos.css">
</head>
<body class="ticket-products">
    <main class="app-main ticket-products__page">
        <header class="page-heading ticket-products__hero">
            <div class="page-heading__eyebrow">
                <p class="page-heading__path">Solicitud documental de alta de productos</p>
                <a class="button button--secondary" href="/tickets/productos">Volver a tickets</a>
            </div>
            <div class="ticket-products__detail-head">
                <div>
                    <span class="ticket-products__overline">Folio</span>
                    <h1>Ticket <?= e($ticket['folio'] ?? '') ?></h1>
                </div>
                <span class="badge ticket-products__badge ticket-products__badge--<?= e(strtolower($estadoTicket)) ?>"><?= e($estadoTicket) ?></span>
            </div>
            <div class="ticket-products__warnings" role="note">
                <p class="alert alert--warning ticket-products__note">
                    Este ticket es documental y no crea productos reales.
                </p>
                <p class="alert alert--warning ticket-products__note">
                    Autorizar una partida no crea el producto en el catálogo.
                </p>
            </div>
        </header>

        <?php if ($errors !== []): ?>
            <section class="alert alert--danger ticket-products__errors" role="alert" aria-labelledby="ticket-producto-errores">
                <h2 id="ticket-producto-errores">Revisa la operación</h2>
                <ul>
                    <?php foreach ($errors as $field => $message): ?>
                        <li><?= e($field) ?>: <?= e($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <section class="home-section ticket-products__section" aria-labelledby="ticket-producto-resumen">
            <div class="home-section__heading">
                <div>
                    <p class="section-kicker">Contexto</p>
                    <h2 id="ticket-producto-resumen">Resumen</h2>
                </div>
            </div>
            <dl class="ticket-products__summary">
                <div><dt>Empresa</dt><dd><?= e($formatValue($ticket['empresa_nombre'] ?? $ticket['empresa_id'] ?? null)) ?></dd></div>
                <div><dt>Almacén</dt><dd><?= e($formatValue($ticket['almacen_nombre'] ?? $ticket['almacen_id'] ?? null)) ?></dd></div>
                <div><dt>Solicitante</dt><dd><?= e($formatValue($ticket['solicitante_nombre'] ?? $ticket['solicitante_usuario_id'] ?? null)) ?></dd></div>
                <div><dt>Fecha de creación</dt><dd><?= e($formatValue($ticket['created_at'] ?? null)) ?></dd></div>
                <div><dt>Última actualización</dt><dd><?= e($formatValue($ticket['updated_at'] ?? null)) ?></dd></div>
                <?php if (($ticket['motivo_cancelacion'] ?? null) !== null): ?>
                    <div><dt>Motivo de cancelación</dt><dd><?= e($formatValue($ticket['motivo_cancelacion'])) ?></dd></div>
                <?php endif; ?>
                <div class="ticket-products__summary-full"><dt>Observaciones generales</dt><dd><?= e($formatValue($ticket['observaciones_generales'] ?? null)) ?></dd></div>
            </dl>
        </section>

        <section class="home-section ticket-products__section" aria-labelledby="ticket-producto-resumen-partidas">
            <div class="home-section__heading">
                <div>
                    <p class="section-kicker">Resumen de partidas</p>
                    <h2 id="ticket-producto-resumen-partidas">Estado documental</h2>
                </div>
            </div>
            <dl class="ticket-products__metrics">
                <div><dt>Total</dt><dd><?= e((string) $totalPartidas) ?></dd></div>
                <div><dt>En revisión</dt><dd><?= e((string) $partidasEnRevision) ?></dd></div>
                <div><dt>Aprobadas</dt><dd><?= e((string) $partidasAprobadas) ?></dd></div>
                <div><dt>Rechazadas</dt><dd><?= e((string) $partidasRechazadas) ?></dd></div>
            </dl>
        </section>

        <section class="home-section ticket-products__section ticket-products__comments" aria-labelledby="ticket-producto-comentarios">
            <div class="home-section__heading">
                <div>
                    <p class="section-kicker">Bitácora documental</p>
                    <h2 id="ticket-producto-comentarios">Comentarios</h2>
                </div>
            </div>
            <p class="ticket-products__hint">
                Los comentarios son documentales y no modifican el estado del ticket.
            </p>
            <?php if ($comentariosGenerales === []): ?>
                <p class="ticket-products__placeholder">Sin comentarios generales registrados.</p>
            <?php else: ?>
                <ul class="ticket-products__comment-list">
                    <?php foreach ($comentariosGenerales as $comentario): ?>
                        <li>
                            <span class="ticket-products__comment-scope">Comentario general</span>
                            <p><?= e($formatValue($comentario['comentario'] ?? null)) ?></p>
                            <span class="ticket-products__event-meta">
                                Usuario <?= e($formatValue($comentario['usuario_id'] ?? null)) ?>
                                · <?= e($formatValue($comentario['created_at'] ?? null)) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canCreateComments): ?>
                <form class="ticket-products__action-form ticket-products__comment-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/comentarios">
                    <?= csrf_field($csrf) ?>
                    <label class="field">
                        <span>Agregar comentario general</span>
                        <textarea name="comentario" required></textarea>
                    </label>
                    <button class="button" type="submit">Agregar comentario</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="ticket-products__section" aria-labelledby="ticket-producto-partidas">
            <div class="ticket-products__section-header">
                <p class="section-kicker">Revisión documental</p>
                <h2 id="ticket-producto-partidas">Partidas</h2>
            </div>
            <?php if ($partidas === []): ?>
                <div class="empty-state ticket-products__empty">
                    <strong>Este ticket no tiene partidas visibles.</strong>
                </div>
            <?php else: ?>
                <div class="ticket-products__lines">
                <?php foreach ($partidas as $partida): ?>
                    <?php if (!is_array($partida)) {
                        continue;
                    } ?>
                    <?php $partidaId = (string) ($partida['id'] ?? ''); ?>
                    <?php $estadoPartida = (string) ($partida['estado'] ?? ''); ?>
                    <?php $comentariosDePartida = $comentariosPorPartida[$partidaId] ?? []; ?>
                    <article class="home-section ticket-products__line-card">
                        <div class="ticket-products__line-heading">
                            <h3>Partida <?= e($partida['numero_partida'] ?? '') ?></h3>
                            <span class="badge ticket-products__badge ticket-products__badge--<?= e(strtolower($estadoPartida)) ?>"><?= e($estadoPartida) ?></span>
                        </div>
                        <dl class="ticket-products__line-data">
                            <div class="ticket-products__line-full"><dt>Descripción</dt><dd><?= e($formatValue($partida['descripcion'] ?? null)) ?></dd></div>
                            <div><dt>Modelo</dt><dd><?= e($formatValue($partida['modelo'] ?? null)) ?></dd></div>
                            <div><dt>Marca documental</dt><dd><?= e($formatValue($partida['marca_texto'] ?? null)) ?></dd></div>
                            <div><dt>Proveedor documental</dt><dd><?= e($formatValue($partida['proveedor_texto'] ?? null)) ?></dd></div>
                            <div><dt>Unidad SAT</dt><dd><?= e($formatValue($partida['unidad_sat_id'] ?? null)) ?></dd></div>
                            <div><dt>Clave SAT</dt><dd><?= e($formatValue($partida['clave_sat_id'] ?? null)) ?></dd></div>
                            <div><dt>Moneda</dt><dd><?= e($formatValue($partida['moneda_id'] ?? null)) ?></dd></div>
                            <div><dt>Costo sugerido <span class="ticket-products__muted-inline">documental</span></dt><dd><?= e($formatValue($partida['costo_sugerido'] ?? null)) ?></dd></div>
                            <div><dt>Peso</dt><dd><?= e($formatValue($partida['peso'] ?? null)) ?></dd></div>
                            <div><dt>Lleva serie</dt><dd><?= ((int) ($partida['lleva_serie'] ?? 0)) === 1 ? 'Sí' : 'No' ?></dd></div>
                            <div><dt>Resuelto por</dt><dd><?= e($formatValue($partida['resuelto_por_usuario_id'] ?? null)) ?></dd></div>
                            <div><dt>Fecha de resolución</dt><dd><?= e($formatValue($partida['resuelto_at'] ?? null)) ?></dd></div>
                            <div class="ticket-products__line-full"><dt>Observaciones</dt><dd><?= e($formatValue($partida['observaciones'] ?? null)) ?></dd></div>
                            <div class="ticket-products__line-full"><dt>Motivo de rechazo</dt><dd><?= e($formatValue($partida['motivo_rechazo'] ?? null)) ?></dd></div>
                            <div class="ticket-products__line-full"><dt>Comentario de resolución</dt><dd><?= e($formatValue($partida['comentario_resolucion'] ?? null)) ?></dd></div>
                        </dl>

                        <div class="ticket-products__line-comments">
                            <h4>Comentarios de partida</h4>
                            <?php if ($comentariosDePartida === []): ?>
                                <p class="ticket-products__hint">Sin comentarios registrados para esta partida.</p>
                            <?php else: ?>
                                <ul class="ticket-products__comment-list">
                                    <?php foreach ($comentariosDePartida as $comentario): ?>
                                        <li>
                                            <span class="ticket-products__comment-scope">Partida <?= e($partida['numero_partida'] ?? '') ?></span>
                                            <p><?= e($formatValue($comentario['comentario'] ?? null)) ?></p>
                                            <span class="ticket-products__event-meta">
                                                Usuario <?= e($formatValue($comentario['usuario_id'] ?? null)) ?>
                                                · <?= e($formatValue($comentario['created_at'] ?? null)) ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($canCreateComments): ?>
                                <form class="ticket-products__action-form ticket-products__comment-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/comentarios">
                                    <?= csrf_field($csrf) ?>
                                    <input type="hidden" name="partida_id" value="<?= e($partidaId) ?>">
                                    <label class="field">
                                        <span>Agregar comentario a esta partida</span>
                                        <textarea name="comentario" required></textarea>
                                    </label>
                                    <button class="button button--secondary" type="submit">Comentar partida</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php if ($canResolve && $estadoPartida === 'EN_REVISION'): ?>
                            <div class="ticket-products__line-actions">
                                <form class="ticket-products__action-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/partidas/<?= e($partidaId) ?>/aprobar">
                                    <?= csrf_field($csrf) ?>
                                    <label class="field">
                                        <span>Comentario de resolución</span>
                                        <textarea name="comentario_resolucion"></textarea>
                                    </label>
                                    <button class="button" type="submit">Aprobar partida</button>
                                </form>

                                <form class="ticket-products__action-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/partidas/<?= e($partidaId) ?>/rechazar">
                                    <?= csrf_field($csrf) ?>
                                    <label class="field">
                                        <span>Motivo de rechazo</span>
                                        <textarea name="motivo_rechazo" required></textarea>
                                    </label>
                                    <label class="field">
                                        <span>Comentario de resolución</span>
                                        <textarea name="comentario_resolucion"></textarea>
                                    </label>
                                    <button class="button button--secondary" type="submit">Rechazar partida</button>
                                </form>
                            </div>
                        <?php elseif ($canResolve): ?>
                            <p class="ticket-products__permission-note">Esta partida ya fue resuelta; las acciones de aprobación y rechazo están ocultas.</p>
                        <?php else: ?>
                            <p class="ticket-products__permission-note">No tienes permiso para aprobar o rechazar partidas.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($canCancel && !$isCancelled): ?>
            <section class="home-section ticket-products__section" aria-labelledby="ticket-producto-cancelar">
                <h2 id="ticket-producto-cancelar">Cancelar ticket</h2>
                <form class="ticket-products__action-form ticket-products__cancel-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/cancelar">
                    <?= csrf_field($csrf) ?>
                    <label class="field">
                        <span>Motivo de cancelación</span>
                        <textarea name="motivo" required></textarea>
                    </label>
                    <button class="button button--secondary" type="submit">Cancelar ticket</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($canViewAttachments || $canResendEmail): ?>
            <section class="home-section ticket-products__section ticket-products__placeholders" aria-labelledby="ticket-producto-acciones-documentales">
                <h2 id="ticket-producto-acciones-documentales">Acciones documentales</h2>
                <?php if ($canViewAttachments): ?>
                    <p class="ticket-products__placeholder">Adjuntos documentales pendientes de fase posterior.</p>
                <?php endif; ?>
                <?php if ($canResendEmail): ?>
                    <button class="button button--secondary ticket-products__disabled-action" type="button" disabled>
                        Reenvío de correo pendiente de fase posterior.
                    </button>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($canViewEvents): ?>
            <section class="home-section ticket-products__section" aria-labelledby="ticket-producto-eventos">
                <h2 id="ticket-producto-eventos">Eventos</h2>
                <?php if ($eventos === []): ?>
                    <p class="ticket-products__hint">Sin eventos visibles.</p>
                <?php else: ?>
                    <ul class="ticket-products__events">
                        <?php foreach ($eventos as $evento): ?>
                            <?php if (!is_array($evento)) {
                                continue;
                            } ?>
                            <li>
                                <span class="ticket-products__event-type"><?= e($formatValue($evento['evento'] ?? null)) ?></span>
                                <span class="ticket-products__event-copy"><?= e($formatValue($evento['descripcion'] ?? null)) ?></span>
                                <span class="ticket-products__event-meta">
                                    Usuario <?= e($formatValue($evento['usuario_id'] ?? null)) ?>
                                    · <?= e($formatValue($evento['created_at'] ?? null)) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <p><a class="button button--secondary" href="/tickets/productos">Volver a tickets</a></p>
    </main>
</body>
</html>
