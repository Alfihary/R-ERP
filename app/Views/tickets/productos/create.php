<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService || !is_array($errors ?? null) || !is_array($values ?? null)) {
    throw new RuntimeException('Product ticket create data is incomplete.');
}

$permissions = is_array($permissions ?? null) ? $permissions : [];
$canCreate = ($permissions['canCreate'] ?? false) === true;
$catalogs = is_array($catalogs ?? null) ? $catalogs : [];
$companies = is_array($catalogs['companies'] ?? null) ? $catalogs['companies'] : [];
$warehouses = is_array($catalogs['warehouses'] ?? null) ? $catalogs['warehouses'] : [];
$allWarehouses = is_array($catalogs['all_warehouses'] ?? null) ? $catalogs['all_warehouses'] : $warehouses;
$brands = is_array($catalogs['brands'] ?? null) ? $catalogs['brands'] : [];
$currencies = is_array($catalogs['currencies'] ?? null) ? $catalogs['currencies'] : [];
$satUnits = is_array($catalogs['sat_units'] ?? null) ? $catalogs['sat_units'] : [];
$satKeys = is_array($catalogs['sat_keys'] ?? null) ? $catalogs['sat_keys'] : [];
$value = static function (string $key, string $default = '') use ($values): string {
    $candidate = $values[$key] ?? $default;

    return is_scalar($candidate) ? (string) $candidate : $default;
};
$partValue = static function (string $key, string $default = '') use ($values): string {
    $candidate = $values['partidas'][0][$key] ?? $default;

    return is_scalar($candidate) ? (string) $candidate : $default;
};
$satUnitLabel = static function (array $unit): string {
    $label = trim((string) ($unit['codigo'] ?? '') . ' - ' . (string) ($unit['nombre'] ?? ''));
    $description = trim((string) ($unit['descripcion'] ?? ''));

    return $description === '' ? $label : $label . ' - ' . $description;
};
$satKeyLabel = static function (array $satKey): string {
    return trim((string) ($satKey['codigo'] ?? '') . ' - ' . (string) ($satKey['descripcion'] ?? ''));
};
$warehouseOptions = array_map(
    static fn (array $warehouse): array => [
        'empresa_id' => (int) ($warehouse['empresa_id'] ?? 0),
        'almacen_id' => (int) ($warehouse['id'] ?? 0),
        'codigo' => (string) ($warehouse['codigo'] ?? ''),
        'nombre' => (string) ($warehouse['nombre'] ?? ''),
    ],
    $allWarehouses
);
$warehouseOptionsJson = json_encode(
    $warehouseOptions,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nuevo ticket de productos</title>
    <link rel="stylesheet" href="/css/core/app.css">
    <link rel="stylesheet" href="/css/modules/tickets-productos.css">
</head>
<body class="ticket-products">
    <main class="app-main ticket-products__page">
        <header class="page-heading ticket-products__hero">
            <div class="page-heading__eyebrow">
                <p class="page-heading__path">Solicitudes de alta de productos</p>
                <a class="button button--secondary" href="/tickets/productos">Volver al listado</a>
            </div>
            <h1>Crear ticket de productos</h1>
            <p>Captura la solicitud para revisión de partidas.</p>
            <p class="alert alert--warning ticket-products__note" role="note">
                Este ticket es documental y no crea productos reales. Aprobar una partida no crea el producto automáticamente.
            </p>
        </header>

        <?php if ($errors !== []): ?>
            <section class="alert alert--danger ticket-products__errors" role="alert" aria-labelledby="ticket-producto-errores">
                <h2 id="ticket-producto-errores">Revisa la solicitud</h2>
                <ul>
                    <?php foreach ($errors as $field => $message): ?>
                        <li><?= e($field) ?>: <?= e($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (!$canCreate): ?>
            <section class="alert alert--warning ticket-products__permission-alert" role="alert">
                <strong>No tienes permiso para crear tickets.</strong>
                <p>La ruta conserva el control principal de seguridad mediante middleware.</p>
            </section>
        <?php else: ?>
        <form class="ticket-products__form" method="post" action="/tickets/productos" enctype="multipart/form-data">
            <?= csrf_field($csrf) ?>

            <fieldset class="home-section ticket-products__fieldset">
                <legend>Datos del ticket</legend>
                <div class="ticket-products__form-grid">
                    <label class="field" for="empresa_id">
                        <span>Empresa</span>
                        <select
                            id="empresa_id"
                            name="empresa_id"
                            data-company-select
                            required
                        >
                            <option value="">Selecciona una empresa</option>
                            <?php foreach ($companies as $company): ?>
                                <?php $companyId = (string) ($company['id'] ?? ''); ?>
                                <option value="<?= e($companyId) ?>" <?= $value('empresa_id') === $companyId ? 'selected' : '' ?>>
                                    <?= e((string) ($company['codigo'] ?? '')) ?> · <?= e((string) ($company['nombre'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Solo empresas activas asignadas a tu usuario.</small>
                    </label>

                    <label class="field" for="almacen_id">
                        <span>Almacén</span>
                        <select
                            id="almacen_id"
                            name="almacen_id"
                            data-warehouse-select
                            data-selected-warehouse="<?= e($value('almacen_id')) ?>"
                            required
                        >
                            <option value="">Selecciona un almacén</option>
                        </select>
                        <small data-warehouse-message>Selecciona una empresa primero.</small>
                    </label>

                    <label class="field ticket-products__field-full" for="observaciones_generales">
                        <span>Observaciones generales</span>
                        <textarea id="observaciones_generales" name="observaciones_generales"><?= e($value('observaciones_generales')) ?></textarea>
                    </label>
                </div>
            </fieldset>

            <fieldset class="home-section ticket-products__fieldset">
                <legend>Partida 1</legend>
                <p class="ticket-products__hint">Vista mínima: más partidas se agregarán en una fase posterior sin JavaScript dinámico todavía.</p>

                <div class="ticket-products__form-grid">
                    <label class="field ticket-products__field-full" for="partida_descripcion">
                        <span>Descripción</span>
                        <textarea id="partida_descripcion" name="partidas[0][descripcion]" required><?= e($partValue('descripcion')) ?></textarea>
                    </label>

                    <label class="field" for="partida_modelo">
                        <span>Modelo</span>
                        <input id="partida_modelo" name="partidas[0][modelo]" value="<?= e($partValue('modelo')) ?>">
                    </label>

                    <label class="field" for="partida_marca">
                        <span>Marca</span>
                        <select id="partida_marca" name="partidas[0][marca_id]">
                            <option value="">Selecciona una marca</option>
                            <?php foreach ($brands as $brand): ?>
                                <?php $brandId = (string) ($brand['id'] ?? ''); ?>
                                <option value="<?= e($brandId) ?>" <?= $partValue('marca_id') === $brandId ? 'selected' : '' ?>>
                                    <?= e((string) ($brand['codigo'] ?? '')) ?> · <?= e((string) ($brand['nombre'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field" for="partida_proveedor">
                        <span>Proveedor</span>
                        <input id="partida_proveedor" name="partidas[0][proveedor_texto]" value="<?= e($partValue('proveedor_texto')) ?>">
                    </label>

                    <label class="field" for="partida_unidad_sat">
                        <span>Unidad SAT</span>
                        <input
                            id="partida_unidad_sat"
                            name="partidas[0][unidad_sat_busqueda]"
                            list="unidades_sat_options"
                            value="<?= e($partValue('unidad_sat_busqueda')) ?>"
                            placeholder="Escribe clave o descripción..."
                        >
                        <datalist id="unidades_sat_options">
                            <?php foreach ($satUnits as $unit): ?>
                                <option value="<?= e($satUnitLabel($unit)) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <small>Escribe o selecciona una opción del catálogo activo.</small>
                    </label>

                    <label class="field" for="partida_clave_sat">
                        <span>Clave SAT</span>
                        <input
                            id="partida_clave_sat"
                            name="partidas[0][clave_sat_busqueda]"
                            list="claves_sat_options"
                            value="<?= e($partValue('clave_sat_busqueda')) ?>"
                            placeholder="Escribe clave o descripción..."
                        >
                        <datalist id="claves_sat_options">
                            <?php foreach ($satKeys as $satKey): ?>
                                <option value="<?= e($satKeyLabel($satKey)) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <small>Escribe o selecciona una opción del catálogo activo.</small>
                    </label>

                    <label class="field" for="partida_moneda">
                        <span>Moneda</span>
                        <select id="partida_moneda" name="partidas[0][moneda_id]">
                            <option value="">Selecciona una moneda</option>
                            <?php foreach ($currencies as $currency): ?>
                                <?php $currencyId = (string) ($currency['id'] ?? ''); ?>
                                <option value="<?= e($currencyId) ?>" <?= $partValue('moneda_id') === $currencyId ? 'selected' : '' ?>>
                                    <?= e((string) ($currency['codigo'] ?? '')) ?> · <?= e((string) ($currency['nombre'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field" for="partida_costo">
                        <span>Costo sugerido</span>
                        <input id="partida_costo" name="partidas[0][costo_sugerido]" inputmode="decimal" value="<?= e($partValue('costo_sugerido')) ?>">
                    </label>

                    <label class="field" for="partida_peso">
                        <span>Peso</span>
                        <input id="partida_peso" name="partidas[0][peso]" inputmode="decimal" value="<?= e($partValue('peso')) ?>">
                    </label>

                    <label class="ticket-products__check">
                        <input type="checkbox" name="partidas[0][lleva_serie]" value="1">
                        <span>Lleva serie</span>
                    </label>

                    <label class="field ticket-products__field-full" for="partida_observaciones">
                        <span>Observaciones</span>
                        <textarea id="partida_observaciones" name="partidas[0][observaciones]"><?= e($partValue('observaciones')) ?></textarea>
                    </label>
                </div>
            </fieldset>

            <fieldset class="home-section ticket-products__fieldset">
                <legend>Adjuntos de soporte</legend>
                <p class="ticket-products__hint">
                    Puedes anexar fichas, imágenes o documentos de apoyo desde la solicitud. La descarga se habilitará en una fase posterior.
                </p>
                <label class="field ticket-products__field-full" for="ticket_adjuntos">
                    <span>Archivos iniciales del ticket</span>
                    <input
                        id="ticket_adjuntos"
                        type="file"
                        name="adjuntos[]"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                        multiple
                    >
                    <small>PDF, JPG, JPEG, PNG o WEBP. Máximo 5 MB por archivo. Se almacenan en storage privado.</small>
                </label>
            </fieldset>

            <div class="form-actions ticket-products__actions">
                <button class="button" type="submit">Crear ticket</button>
                <a class="button button--secondary" href="/tickets/productos">Cancelar</a>
            </div>
        </form>
        <?php endif; ?>
    </main>
    <script type="application/json" id="ticket-products-warehouses-data"><?= $warehouseOptionsJson ?></script>
    <script src="/js/modules/tickets-productos-create.js" defer></script>
</body>
</html>
