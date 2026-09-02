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
</head>
<body>
    <main>
        <header>
            <p>Solicitudes de alta de productos</p>
            <h1>Tickets de productos</h1>
            <p>
                Flujo documental para revisar partidas solicitadas sin crear productos,
                precios, inventario, compras ni proveedores reales.
            </p>
            <p><a class="button" href="/tickets/productos/crear">Nuevo ticket</a></p>
        </header>

        <section aria-labelledby="tickets-productos-listado">
            <h2 id="tickets-productos-listado">Listado</h2>

            <?php if ($tickets === []): ?>
                <p>No hay tickets de productos para mostrar en esta vista mínima.</p>
            <?php else: ?>
                <table>
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
                            <tr>
                                <td><?= e($ticket['folio'] ?? '') ?></td>
                                <td><?= e($ticket['estado'] ?? '') ?></td>
                                <td><?= e($ticket['almacen_id'] ?? '') ?></td>
                                <td><?= e($ticket['solicitante_usuario_id'] ?? '') ?></td>
                                <td><?= e($ticket['created_at'] ?? '') ?></td>
                                <td><?= e($ticket['total_partidas'] ?? '0') ?></td>
                                <td><?= e($ticket['partidas_en_revision'] ?? '0') ?></td>
                                <td><?= e($ticket['partidas_aprobadas'] ?? '0') ?></td>
                                <td><?= e($ticket['partidas_rechazadas'] ?? '0') ?></td>
                                <td>
                                    <a href="/tickets/productos/<?= e((string) ($ticket['id'] ?? '')) ?>">
                                        Ver detalle
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
