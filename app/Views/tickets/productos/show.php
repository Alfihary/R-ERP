<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService || !is_array($ticket ?? null) || !is_array($errors ?? null)) {
    throw new RuntimeException('Product ticket detail data is incomplete.');
}

$ticketId = (string) ($ticket['id'] ?? '');
$partidas = is_array($ticket['partidas'] ?? null) ? $ticket['partidas'] : [];
$eventos = is_array($ticket['eventos'] ?? null) ? $ticket['eventos'] : [];
$estadoTicket = (string) ($ticket['estado'] ?? '');
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
            <h1>Ticket <?= e($ticket['folio'] ?? '') ?></h1>
            <p>Estado: <span class="badge ticket-products__badge ticket-products__badge--<?= e(strtolower($estadoTicket)) ?>"><?= e($estadoTicket) ?></span></p>
            <p class="alert alert--warning ticket-products__note" role="note">
                Autorizar una partida no crea el producto en el catálogo.
            </p>
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
                <div><dt>Empresa</dt><dd><?= e($ticket['empresa_id'] ?? '') ?></dd></div>
                <div><dt>Almacén</dt><dd><?= e($ticket['almacen_id'] ?? '') ?></dd></div>
                <div><dt>Solicitante</dt><dd><?= e($ticket['solicitante_usuario_id'] ?? '') ?></dd></div>
                <div><dt>Total partidas</dt><dd><?= e($ticket['total_partidas'] ?? '0') ?></dd></div>
                <div><dt>En revisión</dt><dd><?= e($ticket['partidas_en_revision'] ?? '0') ?></dd></div>
                <div><dt>Aprobadas</dt><dd><?= e($ticket['partidas_aprobadas'] ?? '0') ?></dd></div>
                <div><dt>Rechazadas</dt><dd><?= e($ticket['partidas_rechazadas'] ?? '0') ?></dd></div>
                <div class="ticket-products__summary-full"><dt>Observaciones generales</dt><dd><?= e($ticket['observaciones_generales'] ?? '—') ?></dd></div>
            </dl>
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
                    <article class="home-section ticket-products__line-card">
                        <div class="ticket-products__line-heading">
                            <h3>Partida <?= e($partida['numero_partida'] ?? '') ?></h3>
                            <span class="badge ticket-products__badge ticket-products__badge--<?= e(strtolower($estadoPartida)) ?>"><?= e($estadoPartida) ?></span>
                        </div>
                        <dl class="ticket-products__line-data">
                            <div class="ticket-products__line-full"><dt>Descripción</dt><dd><?= e($partida['descripcion'] ?? '') ?></dd></div>
                            <div><dt>Modelo</dt><dd><?= e($partida['modelo'] ?? '—') ?></dd></div>
                            <div><dt>Marca</dt><dd><?= e($partida['marca_texto'] ?? '—') ?></dd></div>
                            <div><dt>Proveedor documental</dt><dd><?= e($partida['proveedor_texto'] ?? '—') ?></dd></div>
                            <div><dt>Unidad SAT / clave SAT</dt><dd><?= e($partida['unidad_sat_id'] ?? '—') ?> / <?= e($partida['clave_sat_id'] ?? '—') ?></dd></div>
                            <div><dt>Costo sugerido documental</dt><dd><?= e($partida['costo_sugerido'] ?? '—') ?></dd></div>
                            <div><dt>Peso</dt><dd><?= e($partida['peso'] ?? '—') ?></dd></div>
                            <div><dt>Lleva serie</dt><dd><?= ((int) ($partida['lleva_serie'] ?? 0)) === 1 ? 'Sí' : 'No' ?></dd></div>
                            <div class="ticket-products__line-full"><dt>Observaciones</dt><dd><?= e($partida['observaciones'] ?? '—') ?></dd></div>
                            <div><dt>Motivo de rechazo</dt><dd><?= e($partida['motivo_rechazo'] ?? '—') ?></dd></div>
                            <div><dt>Comentario de resolución</dt><dd><?= e($partida['comentario_resolucion'] ?? '—') ?></dd></div>
                        </dl>

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
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

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
                            <?= e($evento['evento'] ?? '') ?>
                            <?php if (($evento['descripcion'] ?? null) !== null): ?>
                                — <?= e($evento['descripcion']) ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <p><a class="button button--secondary" href="/tickets/productos">Volver a tickets</a></p>
    </main>
</body>
</html>
