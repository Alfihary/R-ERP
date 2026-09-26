<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService) {
    throw new RuntimeException('Product import preview data is incomplete.');
}
$preview = is_array($preview ?? null) ? $preview : null;
$confirmation = is_array($confirmation ?? null) ? $confirmation : null;
$error = is_array($error ?? null) ? $error : null;
$notice = is_string($notice ?? null) ? $notice : null;
?>
<header class="product-page-heading product-import-heading">
    <div>
        <p>Catálogo estructural global</p>
        <h1>Importar productos</h1>
        <p>
            Valida un archivo antes de cualquier importación. Esta etapa es
            solo de preview y no crea ni actualiza productos.
        </p>
    </div>
    <a class="button button--secondary" href="/productos">Volver a productos</a>
</header>

<?php if ($notice !== null): ?>
    <p class="product-notice" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?php if ($error !== null): ?>
    <section class="product-import-alert product-import-alert--error" role="alert">
        <strong>No fue posible completar la operación.</strong>
        <p><?= e((string) ($error['message'] ?? 'El archivo no es válido.')) ?></p>
        <small>Código: <?= e((string) ($error['code'] ?? 'preview_error')) ?></small>
    </section>
<?php endif; ?>

<section class="product-filter-panel product-import-upload" aria-labelledby="import-upload-title">
    <div class="product-section-heading">
        <div>
            <h2 id="import-upload-title">Seleccionar archivo</h2>
            <p>CSV o XLSX · máximo 5 MiB · máximo 1000 filas.</p>
        </div>
    </div>
    <div class="product-import-contract" aria-label="Alcance de la importación">
        <p><strong>CREATE-ONLY:</strong> los productos existentes no se actualizarán.</p>
        <ul>
            <li>Precios no incluidos.</li>
            <li>Inventario no incluido.</li>
            <li>Imágenes no incluidas.</li>
        </ul>
    </div>
    <form method="post" action="/productos/importar/validar" enctype="multipart/form-data">
        <?= csrf_field($csrf) ?>
        <div class="product-field">
            <label for="product-import-file">Archivo de productos</label>
            <input
                id="product-import-file"
                name="archivo"
                type="file"
                accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                required
            >
            <small>El archivo se conservará temporalmente en storage privado.</small>
        </div>
        <button class="button" type="submit">Validar archivo</button>
    </form>
</section>

<?php if ($preview !== null): ?>
    <?php
    $ready = ($preview['status'] ?? '') === 'ready';
    $total = (int) ($preview['total_rows'] ?? 0);
    $visible = is_array($preview['visible_rows'] ?? null) ? $preview['visible_rows'] : [];
    $globalErrors = is_array($preview['global_errors'] ?? null) ? $preview['global_errors'] : [];
    $rowErrors = is_array($preview['row_errors'] ?? null) ? $preview['row_errors'] : [];
    ?>
    <section class="product-import-preview" aria-labelledby="import-preview-title">
        <div class="product-section-heading product-import-preview__heading">
            <div>
                <p>Archivo: <strong><?= e((string) ($preview['original_name'] ?? '')) ?></strong></p>
                <h2 id="import-preview-title">Resultado del preview</h2>
            </div>
            <span class="product-import-status <?= $ready ? 'is-ready' : 'has-errors' ?>">
                <?= $ready ? 'LISTO PARA IMPORTAR' : 'CON ERRORES' ?>
            </span>
        </div>

        <?php if ($confirmation !== null): ?>
            <section class="product-import-confirmation" role="status" aria-labelledby="confirmation-ready-title">
                <div>
                    <span class="product-import-status is-ready">CONFIRMATION_READY</span>
                    <h3 id="confirmation-ready-title">Archivo revalidado correctamente</h3>
                    <p>La importación está lista para ejecutarse en una fase posterior.</p>
                </div>
                <dl class="product-import-confirmation__details">
                    <div>
                        <dt>Total filas</dt>
                        <dd><?= e((string) ($confirmation['total_rows'] ?? 0)) ?></dd>
                    </div>
                    <div>
                        <dt>Filas válidas</dt>
                        <dd><?= e((string) ($confirmation['valid_rows'] ?? 0)) ?></dd>
                    </div>
                    <div>
                        <dt>Fuente validada</dt>
                        <dd><code><?= e(substr((string) ($confirmation['source_sha256'] ?? ''), 0, 12)) ?>…</code></dd>
                    </div>
                    <div>
                        <dt>Expira</dt>
                        <dd><?= e(date('Y-m-d H:i:s', (int) ($confirmation['expires_at'] ?? 0))) ?></dd>
                    </div>
                </dl>
            </section>
        <?php endif; ?>

        <dl class="product-import-summary">
            <div><dt>Total filas</dt><dd><?= e((string) $total) ?></dd></div>
            <div><dt>Válidas</dt><dd><?= e((string) ($preview['valid_rows'] ?? 0)) ?></dd></div>
            <div><dt>Inválidas</dt><dd><?= e((string) ($preview['invalid_rows'] ?? 0)) ?></dd></div>
        </dl>

        <?php if ($globalErrors !== []): ?>
            <section class="product-import-errors" aria-labelledby="global-errors-title">
                <h3 id="global-errors-title">Errores generales del archivo</h3>
                <ul>
                    <?php foreach ($globalErrors as $item): ?>
                        <li>
                            <strong><?= e((string) ($item['field'] ?? 'Archivo')) ?>:</strong>
                            <?= e((string) ($item['message'] ?? 'Error de validación.')) ?>
                            <small><?= e((string) ($item['code'] ?? 'validation_error')) ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <div class="product-table-wrap product-import-table-wrap" tabindex="0">
            <table class="product-table product-import-table">
                <caption class="sr-only">Preview de productos</caption>
                <thead>
                    <tr>
                        <th scope="col">Fila</th>
                        <th scope="col">ID producto</th>
                        <th scope="col">Descripción</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Unidad</th>
                        <th scope="col">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visible as $row): ?>
                        <?php $valid = ($row['status'] ?? '') === 'valid'; ?>
                        <tr class="<?= $valid ? '' : 'product-import-row--invalid' ?>">
                            <td data-label="Fila"><?= e((string) ($row['row_number'] ?? '')) ?></td>
                            <td data-label="ID producto"><strong><?= e((string) ($row['id_producto'] ?? '')) ?></strong></td>
                            <td data-label="Descripción"><?= e((string) ($row['descripcion'] ?? '')) ?></td>
                            <td data-label="Tipo"><?= e((string) ($row['tipo_producto_codigo'] ?? '')) ?></td>
                            <td data-label="Unidad"><?= e((string) ($row['unidad_medida_codigo'] ?? '')) ?></td>
                            <td data-label="Estado">
                                <span class="product-import-row-status <?= $valid ? 'is-valid' : 'is-invalid' ?>">
                                    <?= $valid ? 'Válida' : 'Inválida' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total > count($visible)): ?>
            <p class="product-import-limit">
                Mostrando <?= e((string) count($visible)) ?> de <?= e((string) $total) ?> filas.
            </p>
        <?php endif; ?>

        <?php if ($rowErrors !== []): ?>
            <section class="product-import-errors" aria-labelledby="row-errors-title">
                <h3 id="row-errors-title">Errores por fila</h3>
                <div class="product-import-error-list">
                    <?php foreach ($rowErrors as $item): ?>
                        <article>
                            <strong>Fila <?= e((string) ($item['row'] ?? '')) ?> · <?= e((string) ($item['field'] ?? 'campo')) ?></strong>
                            <p><?= e((string) ($item['message'] ?? 'Error de validación.')) ?></p>
                            <small><?= e((string) ($item['code'] ?? 'validation_error')) ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($ready && $confirmation === null): ?>
            <form method="post" action="/productos/importar/confirmar" class="product-import-confirm">
                <?= csrf_field($csrf) ?>
                <input type="hidden" name="preview_id" value="<?= e((string) ($preview['preview_id'] ?? '')) ?>">
                <button class="button" type="submit">Confirmar importación</button>
                <small>Esta acción solo revalida el archivo; todavía no crea productos.</small>
            </form>
        <?php endif; ?>

        <form method="post" action="/productos/importar/descartar" class="product-import-discard">
            <?= csrf_field($csrf) ?>
            <input type="hidden" name="preview_id" value="<?= e((string) ($preview['preview_id'] ?? '')) ?>">
            <button class="button button--secondary" type="submit">Descartar preview</button>
        </form>
        <p class="product-import-no-confirm">
            La ejecución definitiva todavía no está habilitada.
        </p>
    </section>
<?php endif; ?>
