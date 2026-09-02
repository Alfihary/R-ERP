<?php

declare(strict_types=1);

if (!is_array($tickets ?? null)) {
    throw new RuntimeException('Product ticket index data is incomplete.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tickets de productos</title>
    <link rel="stylesheet" href="/css/core/app.css">
    <link rel="stylesheet" href="/css/modules/tickets-productos.css">
</head>
<body class="ticket-products">
    <main class="app-main ticket-products__page">
        <header class="page-heading ticket-products__hero">
            <div class="page-heading__eyebrow">
                <p class="page-heading__path">Solicitudes de alta de productos</p>
                <a class="button" href="/tickets/productos/crear">Nuevo ticket</a>
            </div>
            <h1>Tickets de productos</h1>
            <p>
                Flujo documental para revisar partidas solicitadas sin crear productos,
                precios, inventario, compras ni proveedores reales.
            </p>
            <p class="alert alert--warning ticket-products__note">
                Aprobar partidas conserva el flujo documental: no crea catálogo, precios,
                inventario, compras ni proveedores.
            </p>
        </header>

        <section class="home-section ticket-products__section" aria-labelledby="tickets-productos-listado">
            <div class="home-section__heading">
                <div>
                    <p class="section-kicker">Bandeja documental</p>
                    <h2 id="tickets-productos-listado">Listado</h2>
                </div>
            </div>

            <?php if ($tickets === []): ?>
                <div class="empty-state ticket-products__empty">
                    <strong>No hay tickets de productos para mostrar.</strong>
                    <p>Cuando se registren solicitudes documentales aparecerán en esta bandeja.</p>
                </div>
            <?php else: ?>
                <div class="table-scroll ticket-products__table-wrap">
                    <table class="data-table ticket-products__table">
                        <caption>Tickets documentales de solicitud de alta de productos</caption>
                        <thead>
                            <tr>
                                <th scope="col">Folio</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Almacén</th>
                                <th scope="col">Solicitante</th>
                                <th scope="col">Fecha</th>
                                <th scope="col">Total</th>
                                <th scope="col">En revisión</th>
                                <th scope="col">Aprobadas</th>
                                <th scope="col">Rechazadas</th>
                                <th scope="col">Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <?php if (!is_array($ticket)) {
                                    continue;
                                } ?>
                                <?php $estado = (string) ($ticket['estado'] ?? ''); ?>
                                <tr>
                                    <td><strong><?= e($ticket['folio'] ?? '') ?></strong></td>
                                    <td><span class="badge ticket-products__badge ticket-products__badge--<?= e(strtolower($estado)) ?>"><?= e($estado) ?></span></td>
                                    <td><?= e($ticket['almacen_id'] ?? '') ?></td>
                                    <td><?= e($ticket['solicitante_usuario_id'] ?? '') ?></td>
                                    <td><?= e($ticket['created_at'] ?? '') ?></td>
                                    <td><?= e($ticket['total_partidas'] ?? '0') ?></td>
                                    <td><?= e($ticket['partidas_en_revision'] ?? '0') ?></td>
                                    <td><?= e($ticket['partidas_aprobadas'] ?? '0') ?></td>
                                    <td><?= e($ticket['partidas_rechazadas'] ?? '0') ?></td>
                                    <td>
                                        <a class="button button--sm button--secondary" href="/tickets/productos/<?= e((string) ($ticket['id'] ?? '')) ?>">
                                            Ver detalle
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
