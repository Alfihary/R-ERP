<?php

declare(strict_types=1);

if (!is_array($transfer ?? null)) {
    throw new RuntimeException('Inventory transfer detail context is incomplete.');
}

$notice = is_string($notice ?? null) ? $notice : null;
$canViewMovement = ($canViewMovement ?? false) === true;
$parts = is_array($transfer['partidas'] ?? null) ? $transfer['partidas'] : [];
?>
<section class="transfer-heading">
    <div>
        <p><a href="/inventario/transferencias">Transferencias</a> / Detalle</p>
        <h1><?= e((string) ($transfer['referencia'] ?? '')) ?></h1>
        <p>Transferencia aplicada. En esta fase no existe edición, cancelación ni reversa.</p>
    </div>
    <a class="button button--secondary transfer-heading-action" href="/inventario/transferencias">
        Volver
    </a>
</section>

<?php if ($notice !== null): ?>
    <div class="transfer-notice" role="status"><?= e($notice) ?></div>
<?php endif; ?>

<section class="transfer-detail" aria-labelledby="transfer-detail-title">
    <div class="transfer-section-heading">
        <div>
            <h2 id="transfer-detail-title">Encabezado</h2>
            <p>Información de consulta sin acciones de modificación.</p>
        </div>
        <span class="transfer-badge"><?= e((string) ($transfer['estado'] ?? 'APLICADA')) ?></span>
    </div>
    <dl class="transfer-detail-grid">
        <div><dt>Fecha/hora</dt><dd><?= e((string) ($transfer['fecha_movimiento'] ?? '')) ?></dd></div>
        <div><dt>Empresa</dt><dd><?= e((string) ($transfer['empresa_nombre'] ?? '')) ?></dd></div>
        <div><dt>Referencia</dt><dd><?= e((string) ($transfer['referencia'] ?? '')) ?></dd></div>
        <div><dt>Almacén origen</dt><dd><?= e((string) ($transfer['almacen_origen_nombre'] ?? '')) ?></dd></div>
        <div><dt>Almacén destino</dt><dd><?= e((string) ($transfer['almacen_destino_nombre'] ?? '')) ?></dd></div>
        <div><dt>Aplicado en</dt><dd><?= e((string) ($transfer['aplicado_en'] ?? '')) ?></dd></div>
        <div><dt>Movimiento salida</dt><dd>MOV-<?= e((string) ($transfer['movimiento_salida_id'] ?? '')) ?></dd></div>
        <div><dt>Movimiento entrada</dt><dd>MOV-<?= e((string) ($transfer['movimiento_entrada_id'] ?? '')) ?></dd></div>
        <div><dt>Creado por</dt><dd><?= e((string) ($transfer['creado_por_username'] ?? '')) ?></dd></div>
        <div><dt>Aplicado por</dt><dd><?= e((string) ($transfer['aplicado_por_username'] ?? '')) ?></dd></div>
        <div class="transfer-detail-grid__wide">
            <dt>Observaciones</dt>
            <dd><?= e((string) ($transfer['observaciones'] ?? 'Sin observaciones')) ?></dd>
        </div>
    </dl>
    <?php if ($canViewMovement): ?>
        <div class="transfer-detail-actions">
            <a
                class="button button--secondary"
                href="/inventario/movimientos/ver?id=<?= e((string) ($transfer['movimiento_salida_id'] ?? '')) ?>"
            >
                Ver movimiento salida
            </a>
            <a
                class="button button--secondary"
                href="/inventario/movimientos/ver?id=<?= e((string) ($transfer['movimiento_entrada_id'] ?? '')) ?>"
            >
                Ver movimiento entrada
            </a>
        </div>
    <?php endif; ?>
</section>

<section class="transfer-detail" aria-labelledby="transfer-detail-parts-title">
    <div class="transfer-section-heading">
        <div>
            <h2 id="transfer-detail-parts-title">Partidas transferidas</h2>
            <p><?= e((string) count($parts)) ?> partida(s).</p>
        </div>
    </div>
    <div class="transfer-table-wrap">
        <table class="transfer-table">
            <caption class="sr-only">Partidas de la transferencia</caption>
            <thead>
                <tr>
                    <th>ID producto</th>
                    <th>Descripción</th>
                    <th>Tipo</th>
                    <th>Cantidad</th>
                    <th>Observaciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($parts as $part): ?>
                    <tr>
                        <td><?= e((string) ($part['id_producto'] ?? '')) ?></td>
                        <td><?= e((string) ($part['descripcion'] ?? '')) ?></td>
                        <td><?= e((string) ($part['tipo_codigo'] ?? '')) ?></td>
                        <td><?= e((string) ($part['cantidad'] ?? '')) ?></td>
                        <td><?= e((string) ($part['observaciones'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
