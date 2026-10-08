<?php

declare(strict_types=1);

$price = is_array($price ?? null) ? $price : [];
$history = is_array($history ?? null) ? $history : [];
?>
<section class="product-price-page">
    <header class="product-price-page__header">
        <div>
            <p class="eyebrow">Precios</p>
            <h1>Historial de precio</h1>
            <p><?= e($price['id_producto'] ?? '') ?> · <?= e($price['lista_clave'] ?? '') ?></p>
        </div>
        <a class="button button--secondary" href="/precios/productos/ver?id=<?= e($price['id'] ?? '') ?>">Volver al detalle</a>
    </header>

    <div class="table-scroll product-price-table-scroll" role="region" aria-label="Historial de precio" tabindex="0">
        <table class="data-table product-price-history-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Moneda anterior</th>
                    <th>Moneda nueva</th>
                    <th>Lista anterior</th>
                    <th>Lista nueva</th>
                    <th>Mínimo anterior</th>
                    <th>Mínimo nuevo</th>
                    <th>Revisión</th>
                    <th>Estado</th>
                    <th>Usuario</th>
                    <th>Motivo</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($history === []): ?>
                    <tr><td colspan="12">No hay historial para este precio.</td></tr>
                <?php endif; ?>
                <?php foreach ($history as $row): ?>
                    <tr>
                        <td><?= e($row['cambiado_en'] ?? '') ?></td>
                        <td><code><?= e($row['tipo_cambio'] ?? '') ?></code></td>
                        <td><code><?= e($row['moneda_anterior_codigo'] ?? '—') ?></code></td>
                        <td><code><?= e($row['moneda_nueva_codigo'] ?? '') ?></code></td>
                        <td class="numeric"><?= e($row['precio_lista_anterior'] ?? '—') ?></td>
                        <td class="numeric"><?= e($row['precio_lista_nuevo'] ?? '') ?></td>
                        <td class="numeric"><?= e($row['precio_minimo_anterior'] ?? '—') ?></td>
                        <td class="numeric"><?= e($row['precio_minimo_nuevo'] ?? '') ?></td>
                        <td>
                            <?= e($row['requiere_revision_anterior'] ?? '—') ?>
                            →
                            <?= e($row['requiere_revision_nuevo'] ?? '') ?>
                        </td>
                        <td>
                            <?= e($row['activo_anterior'] ?? '—') ?>
                            →
                            <?= e($row['activo_nuevo'] ?? '') ?>
                        </td>
                        <td><?= e($row['cambiado_por_username'] ?? '') ?></td>
                        <td><?= e($row['motivo_cambio'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
