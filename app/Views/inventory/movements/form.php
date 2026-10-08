<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService
    || !is_array($values ?? null)
    || !is_array($errors ?? null)
    || !is_array($context ?? null)
) {
    throw new RuntimeException('Inventory movement form context is incomplete.');
}

$hasActiveContext = ($hasActiveContext ?? false) === true;
$activeCompany = is_array($context['active_company'] ?? null) ? $context['active_company'] : null;
$activeWarehouse = is_array($context['active_warehouse'] ?? null) ? $context['active_warehouse'] : null;
$parts = is_array($values['partidas'] ?? null) ? $values['partidas'] : [];
?>
<section class="inventory-heading">
    <div>
        <p><a href="/inventario/movimientos">Movimientos</a> / Nuevo ajuste</p>
        <h1>Nuevo ajuste de inventario</h1>
        <p>
            El ajuste se aplicará al contexto activo. La UI no modifica existencias
            directamente.
        </p>
    </div>
    <a class="button button--secondary inventory-heading-action" href="/inventario/movimientos">
        Volver
    </a>
</section>

<?php if ($errors !== []): ?>
    <div class="inventory-alert" role="alert">
        Revisa el formulario. No se aplicó ningún movimiento.
        <?php foreach ($errors as $message): ?>
            <p><?= e($message) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form
    class="inventory-form"
    method="post"
    action="/inventario/movimientos"
    data-inventory-movement-form
    data-product-search-endpoint="/inventario/productos/buscar"
>
    <?= csrf_field($csrf) ?>
    <input type="hidden" name="idempotency_key" value="<?= e((string) ($values['idempotency_key'] ?? '')) ?>">

    <section class="inventory-form-section" aria-labelledby="inventory-context-title">
        <div class="inventory-section-heading">
            <div>
                <h2 id="inventory-context-title">Contexto activo</h2>
                <p>No se envían IDs de empresa, almacén ni usuario desde el navegador.</p>
            </div>
        </div>
        <div class="inventory-context-grid">
            <div>
                <span>Empresa</span>
                <strong><?= e((string) ($activeCompany['name'] ?? 'Sin contexto')) ?></strong>
            </div>
            <div>
                <span>Almacén</span>
                <strong><?= e((string) ($activeWarehouse['name'] ?? 'Sin contexto')) ?></strong>
            </div>
        </div>
        <?php if (!$hasActiveContext): ?>
            <p class="inventory-field-error">Selecciona un contexto activo antes de crear ajustes.</p>
        <?php endif; ?>
    </section>

    <section class="inventory-form-section" aria-labelledby="inventory-header-title">
        <div class="inventory-section-heading">
            <div>
                <h2 id="inventory-header-title">Datos del movimiento</h2>
                <p>Solo se permiten entrada y salida por ajuste.</p>
            </div>
        </div>
        <div class="inventory-form-grid">
            <label class="inventory-field">
                <span>Tipo de ajuste</span>
                <select name="concepto_codigo" required>
                    <?php foreach ([
                        'ENTRADA_AJUSTE' => 'Entrada por ajuste',
                        'SALIDA_AJUSTE' => 'Salida por ajuste',
                    ] as $code => $label): ?>
                        <option
                            value="<?= e($code) ?>"
                            <?= ($values['concepto_codigo'] ?? '') === $code ? 'selected' : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="inventory-field">
                <span>Fecha y hora</span>
                <input
                    type="datetime-local"
                    name="fecha_movimiento"
                    value="<?= e((string) ($values['fecha_movimiento'] ?? '')) ?>"
                    required
                >
            </label>
            <label class="inventory-field">
                <span>Referencia opcional</span>
                <input name="referencia" maxlength="100" value="<?= e((string) ($values['referencia'] ?? '')) ?>">
            </label>
            <label class="inventory-field inventory-field--wide">
                <span>Observaciones opcionales</span>
                <textarea name="observaciones" maxlength="500"><?= e((string) ($values['observaciones'] ?? '')) ?></textarea>
            </label>
        </div>
    </section>

    <section class="inventory-form-section" aria-labelledby="inventory-parts-title">
        <div class="inventory-section-heading">
            <div>
                <h2 id="inventory-parts-title">Partidas</h2>
                <p>Busca productos activos. Los servicios se excluyen del buscador.</p>
            </div>
            <button class="button button--secondary" type="button" data-add-part>
                Agregar partida
            </button>
        </div>
        <div class="inventory-parts" data-parts>
            <?php foreach ($parts as $index => $part): ?>
                <?php $part = is_array($part) ? $part : []; ?>
                <div class="inventory-part" data-part>
                    <label class="inventory-field inventory-product-search">
                        <span>Producto</span>
                        <input
                            type="hidden"
                            name="partidas[<?= e((string) $index) ?>][id_producto]"
                            value="<?= e((string) ($part['id_producto'] ?? '')) ?>"
                            data-product-id
                        >
                        <input
                            type="hidden"
                            name="partidas[<?= e((string) $index) ?>][controla_series]"
                            value="<?= e((string) ($part['controla_series'] ?? '0')) ?>"
                            data-product-tracks-series
                        >
                        <input
                            type="search"
                            value="<?= e((string) ($part['producto_label'] ?? $part['id_producto'] ?? '')) ?>"
                            placeholder="Buscar por ID o descripción"
                            autocomplete="off"
                            data-product-search
                            aria-label="Buscar producto"
                        >
                        <div class="inventory-search-results" data-product-results hidden role="listbox"></div>
                        <small data-product-status>Escribe al menos 2 caracteres.</small>
                    </label>
                    <label class="inventory-field">
                        <span>Cantidad</span>
                        <input
                            name="partidas[<?= e((string) $index) ?>][cantidad]"
                            inputmode="decimal"
                            step="0.000001"
                            min="0.000001"
                            value="<?= e((string) ($part['cantidad'] ?? '')) ?>"
                            placeholder="1.000000"
                            required
                        >
                    </label>
                    <label class="inventory-field">
                        <span>Observaciones</span>
                        <input
                            name="partidas[<?= e((string) $index) ?>][observaciones]"
                            maxlength="500"
                            value="<?= e((string) ($part['observaciones'] ?? '')) ?>"
                        >
                    </label>
                    <label
                        class="inventory-field inventory-field--series"
                        data-series-panel
                        <?= (string) ($part['controla_series'] ?? '0') === '1' ? '' : 'hidden' ?>
                    >
                        <span>Números de serie</span>
                        <textarea
                            name="partidas[<?= e((string) $index) ?>][series_text]"
                            rows="4"
                            data-series-input
                            placeholder="SERIE-001&#10;SERIE-002"
                        ><?= e((string) ($part['series_text'] ?? '')) ?></textarea>
                        <small data-series-help>
                            Este producto controla números de serie. Captura una serie por cada unidad.
                        </small>
                    </label>
                    <button
                        class="button button--secondary inventory-remove-part"
                        type="button"
                        data-remove-part
                        aria-label="Quitar partida"
                    >
                        Quitar
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="inventory-form-actions">
        <button class="button" type="submit" <?= !$hasActiveContext ? 'disabled' : '' ?>>
            Aplicar movimiento
        </button>
        <a class="button button--secondary" href="/inventario/movimientos">Cancelar</a>
    </div>
</form>
