<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService || !is_array($errors ?? null) || !is_array($values ?? null)) {
    throw new RuntimeException('Product ticket create data is incomplete.');
}

$permissions = is_array($permissions ?? null) ? $permissions : [];
$canCreate = ($permissions['canCreate'] ?? false) === true;
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
            <p>Captura la solicitud documental para revisión de partidas.</p>
            <p class="alert alert--warning ticket-products__note" role="note">
                Este ticket es documental y no crea productos reales.
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
        <form class="ticket-products__form" method="post" action="/tickets/productos">
            <?= csrf_field($csrf) ?>

            <fieldset class="home-section ticket-products__fieldset">
                <legend>Datos del ticket</legend>
                <div class="ticket-products__form-grid">
                    <label class="field" for="empresa_id">
                        <span>Empresa</span>
                        <input
                            id="empresa_id"
                            name="empresa_id"
                            inputmode="numeric"
                            value="<?= e($values['empresa_id'] ?? '') ?>"
                            required
                        >
                    </label>

                    <label class="field" for="almacen_id">
                        <span>Almacén</span>
                        <input
                            id="almacen_id"
                            name="almacen_id"
                            inputmode="numeric"
                            value="<?= e($values['almacen_id'] ?? '') ?>"
                            required
                        >
                    </label>

                    <label class="field ticket-products__field-full" for="observaciones_generales">
                        <span>Observaciones generales</span>
                        <textarea id="observaciones_generales" name="observaciones_generales"><?= e($values['observaciones_generales'] ?? '') ?></textarea>
                    </label>
                </div>
            </fieldset>

            <fieldset class="home-section ticket-products__fieldset">
                <legend>Partida 1</legend>
                <p class="ticket-products__hint">Vista mínima: más partidas se agregarán en una fase posterior sin JavaScript dinámico todavía.</p>

                <div class="ticket-products__form-grid">
                    <label class="field ticket-products__field-full" for="partida_descripcion">
                        <span>Descripción</span>
                        <textarea id="partida_descripcion" name="partidas[0][descripcion]" required><?= e($values['partidas'][0]['descripcion'] ?? '') ?></textarea>
                    </label>

                    <label class="field" for="partida_modelo">
                        <span>Modelo</span>
                        <input id="partida_modelo" name="partidas[0][modelo]" value="<?= e($values['partidas'][0]['modelo'] ?? '') ?>">
                    </label>

                    <label class="field" for="partida_marca">
                        <span>Marca documental</span>
                        <input id="partida_marca" name="partidas[0][marca_texto]" value="<?= e($values['partidas'][0]['marca_texto'] ?? '') ?>">
                    </label>

                    <label class="field" for="partida_proveedor">
                        <span>Proveedor documental</span>
                        <input id="partida_proveedor" name="partidas[0][proveedor_texto]" value="<?= e($values['partidas'][0]['proveedor_texto'] ?? '') ?>">
                    </label>

                    <label class="field" for="partida_unidad_sat">
                        <span>Unidad SAT</span>
                        <input id="partida_unidad_sat" name="partidas[0][unidad_sat_id]" inputmode="numeric" value="<?= e($values['partidas'][0]['unidad_sat_id'] ?? '') ?>">
                    </label>

                    <label class="field" for="partida_clave_sat">
                        <span>Clave SAT</span>
                        <input id="partida_clave_sat" name="partidas[0][clave_sat_id]" inputmode="numeric" value="<?= e($values['partidas'][0]['clave_sat_id'] ?? '') ?>">
                    </label>

                    <label class="field" for="partida_moneda">
                        <span>Moneda</span>
                        <input id="partida_moneda" name="partidas[0][moneda_id]" inputmode="numeric" value="<?= e($values['partidas'][0]['moneda_id'] ?? '') ?>">
                    </label>

                    <label class="field" for="partida_costo">
                        <span>Costo sugerido documental</span>
                        <input id="partida_costo" name="partidas[0][costo_sugerido]" inputmode="decimal" value="<?= e($values['partidas'][0]['costo_sugerido'] ?? '') ?>">
                    </label>

                    <label class="field" for="partida_peso">
                        <span>Peso</span>
                        <input id="partida_peso" name="partidas[0][peso]" inputmode="decimal" value="<?= e($values['partidas'][0]['peso'] ?? '') ?>">
                    </label>

                    <label class="ticket-products__check">
                        <input type="checkbox" name="partidas[0][lleva_serie]" value="1">
                        <span>Lleva serie</span>
                    </label>

                    <label class="field ticket-products__field-full" for="partida_observaciones">
                        <span>Observaciones</span>
                        <textarea id="partida_observaciones" name="partidas[0][observaciones]"><?= e($values['partidas'][0]['observaciones'] ?? '') ?></textarea>
                    </label>
                </div>
            </fieldset>

            <div class="form-actions ticket-products__actions">
                <button class="button" type="submit">Crear ticket documental</button>
                <a class="button button--secondary" href="/tickets/productos">Cancelar</a>
            </div>
        </form>
        <?php endif; ?>
    </main>
</body>
</html>
