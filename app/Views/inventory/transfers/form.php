<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService
    || !is_array($values ?? null)
    || !is_array($errors ?? null)
    || !is_array($context ?? null)
    || !is_array($warehouses ?? null)
) {
    throw new RuntimeException('Inventory transfer form context is incomplete.');
}

$hasActiveContext = ($hasActiveContext ?? false) === true;
$activeCompany = is_array($context['active_company'] ?? null) ? $context['active_company'] : null;
$activeWarehouse = is_array($context['active_warehouse'] ?? null) ? $context['active_warehouse'] : null;
$parts = is_array($values['partidas'] ?? null) ? $values['partidas'] : [];
?>
<section class="transfer-heading">
    <div>
        <p><a href="/inventario/transferencias">Transferencias</a> / Nueva</p>
        <h1>Nueva transferencia</h1>
        <p>
            Captura almacenes y partidas. La UI no modifica existencias ni crea
            movimientos directamente.
        </p>
    </div>
    <a class="button button--secondary transfer-heading-action" href="/inventario/transferencias">
        Volver
    </a>
</section>

<?php if ($errors !== []): ?>
    <div class="transfer-alert" role="alert">
        Revisa el formulario. No se aplicó ninguna transferencia.
        <?php foreach ($errors as $message): ?>
            <p><?= e($message) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form
    class="transfer-form"
    method="post"
    action="/inventario/transferencias"
    data-inventory-transfer-form
    data-product-search-endpoint="/inventario/transferencias/productos/buscar"
>
    <?= csrf_field($csrf) ?>

    <section class="transfer-form-section" aria-labelledby="transfer-context-title">
        <div class="transfer-section-heading">
            <div>
                <h2 id="transfer-context-title">Contexto activo</h2>
                <p>No se envían IDs de empresa ni usuario desde el navegador.</p>
            </div>
        </div>
        <div class="transfer-context-grid">
            <div>
                <span>Empresa</span>
                <strong><?= e((string) ($activeCompany['name'] ?? 'Sin contexto')) ?></strong>
            </div>
            <div>
                <span>Almacén activo</span>
                <strong><?= e((string) ($activeWarehouse['name'] ?? 'Sin contexto')) ?></strong>
            </div>
        </div>
        <?php if (!$hasActiveContext): ?>
            <p class="transfer-field-error">Selecciona un contexto activo antes de crear transferencias.</p>
        <?php endif; ?>
    </section>

    <section class="transfer-form-section" aria-labelledby="transfer-header-title">
        <div class="transfer-section-heading">
            <div>
                <h2 id="transfer-header-title">Encabezado</h2>
                <p>Origen y destino deben pertenecer a la empresa activa.</p>
            </div>
        </div>
        <div class="transfer-form-grid">
            <label class="transfer-field">
                <span>Almacén origen</span>
                <select name="almacen_origen_id" required>
                    <option value="">Selecciona origen</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <?php $warehouseId = (int) ($warehouse['id'] ?? 0); ?>
                        <option
                            value="<?= e((string) $warehouseId) ?>"
                            <?= (string) ($values['almacen_origen_id'] ?? '') === (string) $warehouseId ? 'selected' : '' ?>
                        >
                            <?= e((string) ($warehouse['nombre'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="transfer-field">
                <span>Almacén destino</span>
                <select name="almacen_destino_id" required>
                    <option value="">Selecciona destino</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <?php $warehouseId = (int) ($warehouse['id'] ?? 0); ?>
                        <option
                            value="<?= e((string) $warehouseId) ?>"
                            <?= (string) ($values['almacen_destino_id'] ?? '') === (string) $warehouseId ? 'selected' : '' ?>
                        >
                            <?= e((string) ($warehouse['nombre'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="transfer-field">
                <span>Fecha y hora</span>
                <input
                    type="datetime-local"
                    name="fecha_movimiento"
                    value="<?= e((string) ($values['fecha_movimiento'] ?? '')) ?>"
                    required
                >
            </label>
            <label class="transfer-field">
                <span>Referencia</span>
                <input
                    name="referencia"
                    maxlength="100"
                    pattern="TRF-[A-Za-z0-9-]{1,96}"
                    value="<?= e((string) ($values['referencia'] ?? '')) ?>"
                    required
                >
            </label>
            <label class="transfer-field transfer-field--wide">
                <span>Observaciones opcionales</span>
                <textarea name="observaciones" maxlength="500"><?= e((string) ($values['observaciones'] ?? '')) ?></textarea>
            </label>
        </div>
    </section>

    <section class="transfer-form-section" aria-labelledby="transfer-parts-title">
        <div class="transfer-section-heading">
            <div>
                <h2 id="transfer-parts-title">Partidas</h2>
                <p>Busca productos activos. Los servicios quedan excluidos.</p>
            </div>
            <button class="button button--secondary" type="button" data-add-part>
                Agregar partida
            </button>
        </div>
        <div class="transfer-parts" data-parts>
            <?php foreach ($parts as $index => $part): ?>
                <?php $part = is_array($part) ? $part : []; ?>
                <div class="transfer-part" data-part>
                    <label class="transfer-field transfer-product-search">
                        <span>Producto</span>
                        <input
                            type="hidden"
                            name="partidas[<?= e((string) $index) ?>][id_producto]"
                            value="<?= e((string) ($part['id_producto'] ?? '')) ?>"
                            data-product-id
                        >
                        <input
                            type="search"
                            value="<?= e((string) ($part['producto_label'] ?? $part['id_producto'] ?? '')) ?>"
                            placeholder="Buscar por ID o descripción"
                            autocomplete="off"
                            data-product-search
                            aria-label="Buscar producto para transferencia"
                        >
                        <div class="transfer-search-results" data-product-results hidden role="listbox"></div>
                        <small data-product-status>Escribe al menos 2 caracteres.</small>
                    </label>
                    <label class="transfer-field">
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
                    <label class="transfer-field">
                        <span>Observaciones</span>
                        <input
                            name="partidas[<?= e((string) $index) ?>][observaciones]"
                            maxlength="500"
                            value="<?= e((string) ($part['observaciones'] ?? '')) ?>"
                        >
                    </label>
                    <button
                        class="button button--secondary transfer-remove-part"
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

    <div class="transfer-form-actions">
        <button class="button" type="submit" <?= !$hasActiveContext ? 'disabled' : '' ?>>
            Aplicar transferencia
        </button>
        <a class="button button--secondary" href="/inventario/transferencias">Cancelar</a>
    </div>
</form>
