<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService || !is_array($errors ?? null) || !is_array($values ?? null)) {
    throw new RuntimeException('Product ticket create data is incomplete.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nuevo ticket de productos</title>
</head>
<body>
    <main>
        <header>
            <p>Solicitudes de alta de productos</p>
            <h1>Crear ticket de productos</h1>
            <p role="note">Este ticket es documental y no crea productos reales.</p>
        </header>

        <?php if ($errors !== []): ?>
            <section role="alert" aria-labelledby="ticket-producto-errores">
                <h2 id="ticket-producto-errores">Revisa la solicitud</h2>
                <ul>
                    <?php foreach ($errors as $field => $message): ?>
                        <li><?= e($field) ?>: <?= e($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <form method="post" action="/tickets/productos">
            <?= csrf_field($csrf) ?>

            <fieldset>
                <legend>Datos del ticket</legend>
                <label for="empresa_id">Empresa</label>
                <input
                    id="empresa_id"
                    name="empresa_id"
                    inputmode="numeric"
                    value="<?= e($values['empresa_id'] ?? '') ?>"
                    required
                >

                <label for="almacen_id">Almacén</label>
                <input
                    id="almacen_id"
                    name="almacen_id"
                    inputmode="numeric"
                    value="<?= e($values['almacen_id'] ?? '') ?>"
                    required
                >

                <label for="observaciones_generales">Observaciones generales</label>
                <textarea id="observaciones_generales" name="observaciones_generales"><?= e($values['observaciones_generales'] ?? '') ?></textarea>
            </fieldset>

            <fieldset>
                <legend>Partida 1</legend>
                <p>Vista mínima: más partidas se agregarán en una fase posterior.</p>

                <label for="partida_descripcion">Descripción</label>
                <textarea id="partida_descripcion" name="partidas[0][descripcion]" required><?= e($values['partidas'][0]['descripcion'] ?? '') ?></textarea>

                <label for="partida_modelo">Modelo</label>
                <input id="partida_modelo" name="partidas[0][modelo]" value="<?= e($values['partidas'][0]['modelo'] ?? '') ?>">

                <label for="partida_marca">Marca documental</label>
                <input id="partida_marca" name="partidas[0][marca_texto]" value="<?= e($values['partidas'][0]['marca_texto'] ?? '') ?>">

                <label for="partida_proveedor">Proveedor documental</label>
                <input id="partida_proveedor" name="partidas[0][proveedor_texto]" value="<?= e($values['partidas'][0]['proveedor_texto'] ?? '') ?>">

                <label for="partida_unidad_sat">Unidad SAT</label>
                <input id="partida_unidad_sat" name="partidas[0][unidad_sat_id]" inputmode="numeric" value="<?= e($values['partidas'][0]['unidad_sat_id'] ?? '') ?>">

                <label for="partida_clave_sat">Clave SAT</label>
                <input id="partida_clave_sat" name="partidas[0][clave_sat_id]" inputmode="numeric" value="<?= e($values['partidas'][0]['clave_sat_id'] ?? '') ?>">

                <label for="partida_moneda">Moneda</label>
                <input id="partida_moneda" name="partidas[0][moneda_id]" inputmode="numeric" value="<?= e($values['partidas'][0]['moneda_id'] ?? '') ?>">

                <label for="partida_costo">Costo sugerido documental</label>
                <input id="partida_costo" name="partidas[0][costo_sugerido]" inputmode="decimal" value="<?= e($values['partidas'][0]['costo_sugerido'] ?? '') ?>">

                <label for="partida_peso">Peso</label>
                <input id="partida_peso" name="partidas[0][peso]" inputmode="decimal" value="<?= e($values['partidas'][0]['peso'] ?? '') ?>">

                <label>
                    <input type="checkbox" name="partidas[0][lleva_serie]" value="1">
                    Lleva serie
                </label>

                <label for="partida_observaciones">Observaciones</label>
                <textarea id="partida_observaciones" name="partidas[0][observaciones]"><?= e($values['partidas'][0]['observaciones'] ?? '') ?></textarea>
            </fieldset>

            <button class="button" type="submit">Crear ticket documental</button>
            <a href="/tickets/productos">Cancelar</a>
        </form>
    </main>
</body>
</html>
