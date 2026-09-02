<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService || !is_array($ticket ?? null) || !is_array($errors ?? null)) {
    throw new RuntimeException('Product ticket detail data is incomplete.');
}

$ticketId = (string) ($ticket['id'] ?? '');
$partidas = is_array($ticket['partidas'] ?? null) ? $ticket['partidas'] : [];
$eventos = is_array($ticket['eventos'] ?? null) ? $ticket['eventos'] : [];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ticket <?= e($ticket['folio'] ?? '') ?></title>
</head>
<body>
    <main>
        <header>
            <p>Solicitud documental de alta de productos</p>
            <h1>Ticket <?= e($ticket['folio'] ?? '') ?></h1>
            <p>Estado: <strong><?= e($ticket['estado'] ?? '') ?></strong></p>
            <p role="note">Autorizar una partida no crea el producto en el catálogo.</p>
        </header>

        <?php if ($errors !== []): ?>
            <section role="alert" aria-labelledby="ticket-producto-errores">
                <h2 id="ticket-producto-errores">Revisa la operación</h2>
                <ul>
                    <?php foreach ($errors as $field => $message): ?>
                        <li><?= e($field) ?>: <?= e($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <section aria-labelledby="ticket-producto-resumen">
            <h2 id="ticket-producto-resumen">Resumen</h2>
            <dl>
                <dt>Empresa</dt>
                <dd><?= e($ticket['empresa_id'] ?? '') ?></dd>
                <dt>Almacén</dt>
                <dd><?= e($ticket['almacen_id'] ?? '') ?></dd>
                <dt>Solicitante</dt>
                <dd><?= e($ticket['solicitante_usuario_id'] ?? '') ?></dd>
                <dt>Observaciones generales</dt>
                <dd><?= e($ticket['observaciones_generales'] ?? '—') ?></dd>
                <dt>Total partidas</dt>
                <dd><?= e($ticket['total_partidas'] ?? '0') ?></dd>
                <dt>En revisión</dt>
                <dd><?= e($ticket['partidas_en_revision'] ?? '0') ?></dd>
                <dt>Aprobadas</dt>
                <dd><?= e($ticket['partidas_aprobadas'] ?? '0') ?></dd>
                <dt>Rechazadas</dt>
                <dd><?= e($ticket['partidas_rechazadas'] ?? '0') ?></dd>
            </dl>
        </section>

        <section aria-labelledby="ticket-producto-partidas">
            <h2 id="ticket-producto-partidas">Partidas</h2>
            <?php if ($partidas === []): ?>
                <p>Este ticket no tiene partidas visibles.</p>
            <?php else: ?>
                <?php foreach ($partidas as $partida): ?>
                    <?php if (!is_array($partida)) {
                        continue;
                    } ?>
                    <?php $partidaId = (string) ($partida['id'] ?? ''); ?>
                    <article>
                        <h3>Partida <?= e($partida['numero_partida'] ?? '') ?></h3>
                        <dl>
                            <dt>Estado</dt>
                            <dd><?= e($partida['estado'] ?? '') ?></dd>
                            <dt>Descripción</dt>
                            <dd><?= e($partida['descripcion'] ?? '') ?></dd>
                            <dt>Modelo</dt>
                            <dd><?= e($partida['modelo'] ?? '—') ?></dd>
                            <dt>Marca</dt>
                            <dd><?= e($partida['marca_texto'] ?? '—') ?></dd>
                            <dt>Proveedor documental</dt>
                            <dd><?= e($partida['proveedor_texto'] ?? '—') ?></dd>
                            <dt>Unidad SAT / clave SAT</dt>
                            <dd><?= e($partida['unidad_sat_id'] ?? '—') ?> / <?= e($partida['clave_sat_id'] ?? '—') ?></dd>
                            <dt>Costo sugerido documental</dt>
                            <dd><?= e($partida['costo_sugerido'] ?? '—') ?></dd>
                            <dt>Peso</dt>
                            <dd><?= e($partida['peso'] ?? '—') ?></dd>
                            <dt>Lleva serie</dt>
                            <dd><?= ((int) ($partida['lleva_serie'] ?? 0)) === 1 ? 'Sí' : 'No' ?></dd>
                            <dt>Observaciones</dt>
                            <dd><?= e($partida['observaciones'] ?? '—') ?></dd>
                            <dt>Motivo de rechazo</dt>
                            <dd><?= e($partida['motivo_rechazo'] ?? '—') ?></dd>
                            <dt>Comentario de resolución</dt>
                            <dd><?= e($partida['comentario_resolucion'] ?? '—') ?></dd>
                        </dl>

                        <form method="post" action="/tickets/productos/<?= e($ticketId) ?>/partidas/<?= e($partidaId) ?>/aprobar">
                            <?= csrf_field($csrf) ?>
                            <label>
                                Comentario de resolución
                                <textarea name="comentario_resolucion"></textarea>
                            </label>
                            <button type="submit">Aprobar partida</button>
                        </form>

                        <form method="post" action="/tickets/productos/<?= e($ticketId) ?>/partidas/<?= e($partidaId) ?>/rechazar">
                            <?= csrf_field($csrf) ?>
                            <label>
                                Motivo de rechazo
                                <textarea name="motivo_rechazo" required></textarea>
                            </label>
                            <label>
                                Comentario de resolución
                                <textarea name="comentario_resolucion"></textarea>
                            </label>
                            <button type="submit">Rechazar partida</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section aria-labelledby="ticket-producto-cancelar">
            <h2 id="ticket-producto-cancelar">Cancelar ticket</h2>
            <form method="post" action="/tickets/productos/<?= e($ticketId) ?>/cancelar">
                <?= csrf_field($csrf) ?>
                <label>
                    Motivo de cancelación
                    <textarea name="motivo" required></textarea>
                </label>
                <button type="submit">Cancelar ticket</button>
            </form>
        </section>

        <section aria-labelledby="ticket-producto-eventos">
            <h2 id="ticket-producto-eventos">Eventos</h2>
            <?php if ($eventos === []): ?>
                <p>Sin eventos visibles.</p>
            <?php else: ?>
                <ul>
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

        <p><a href="/tickets/productos">Volver a tickets</a></p>
    </main>
</body>
</html>
