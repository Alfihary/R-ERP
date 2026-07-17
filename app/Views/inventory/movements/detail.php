<?php

declare(strict_types=1);

if (!is_array($movement ?? null)) {
    throw new RuntimeException('Inventory movement detail context is incomplete.');
}

$notice = is_string($notice ?? null) ? $notice : null;
$parts = is_array($movement['partidas'] ?? null) ? $movement['partidas'] : [];
?>
<section class="inventory-heading">
    <div>
        <p><a href="/inventario/movimientos">Movimientos</a> / Detalle</p>
        <h1>MOV-<?= e((string) ($movement['id'] ?? '')) ?></h1>
        <p>Movimiento histórico aplicado. No puede editarse ni anularse en esta fase.</p>
    </div>
    <a class="button button--secondary inventory-heading-action" href="/inventario/movimientos">
        Volver
    </a>
</section>

<?php if ($notice !== null): ?>
    <div class="inventory-notice" role="status"><?= e($notice) ?></div>
<?php endif; ?>

<section class="inventory-detail" aria-labelledby="inventory-detail-title">
    <div class="inventory-section-heading">
        <div>
            <h2 id="inventory-detail-title">Encabezado</h2>
            <p>Información de consulta sin acciones de modificación.</p>
        </div>
        <span class="inventory-badge"><?= e((string) ($movement['estado'] ?? '')) ?></span>
    </div>
    <dl class="inventory-detail-grid">
        <div><dt>Fecha/hora</dt><dd><?= e((string) ($movement['fecha_movimiento'] ?? '')) ?></dd></div>
        <div><dt>Concepto</dt><dd><?= e((string) ($movement['concepto_nombre'] ?? '')) ?></dd></div>
        <div><dt>Naturaleza</dt><dd><?= e((string) ($movement['naturaleza'] ?? '')) ?></dd></div>
        <div><dt>Empresa</dt><dd><?= e((string) ($movement['empresa_nombre'] ?? '')) ?></dd></div>
        <div><dt>Almacén</dt><dd><?= e((string) ($movement['almacen_nombre'] ?? '')) ?></dd></div>
        <div><dt>Referencia</dt><dd><?= e((string) ($movement['referencia'] ?? 'Sin referencia')) ?></dd></div>
        <div><dt>Creado por</dt><dd><?= e((string) ($movement['creado_por_username'] ?? '')) ?></dd></div>
        <div><dt>Aplicado por</dt><dd><?= e((string) ($movement['aplicado_por_username'] ?? '')) ?></dd></div>
        <div><dt>Aplicado en</dt><dd><?= e((string) ($movement['aplicado_en'] ?? '')) ?></dd></div>
        <div class="inventory-detail-grid__wide">
            <dt>Observaciones</dt>
            <dd><?= e((string) ($movement['observaciones'] ?? 'Sin observaciones')) ?></dd>
        </div>
    </dl>
</section>

<section class="inventory-detail" aria-labelledby="inventory-detail-parts-title">
    <div class="inventory-section-heading">
        <div>
            <h2 id="inventory-detail-parts-title">Partidas</h2>
            <p><?= e((string) count($parts)) ?> partida(s).</p>
        </div>
    </div>
    <div class="inventory-table-wrap">
        <table class="inventory-table">
            <caption class="sr-only">Partidas del movimiento de inventario</caption>
            <thead>
                <tr>
                    <th>ID producto</th>
                    <th>Descripción</th>
                    <th>Cantidad</th>
                    <th>Series</th>
                    <th>Observaciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($parts as $part): ?>
                    <tr>
                        <td><?= e((string) ($part['id_producto'] ?? '')) ?></td>
                        <td><?= e((string) ($part['descripcion'] ?? '')) ?></td>
                        <td><?= e((string) ($part['cantidad'] ?? '')) ?></td>
                        <td>
                            <?php $series = is_array($part['series'] ?? null) ? $part['series'] : []; ?>
                            <?php if ($series !== []): ?>
                                <ul class="inventory-series-list">
                                    <?php foreach ($series as $number): ?>
                                        <li><?= e((string) $number) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <span class="inventory-muted">No aplica</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) ($part['observaciones'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
