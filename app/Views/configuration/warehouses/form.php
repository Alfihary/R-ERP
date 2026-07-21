<?php

declare(strict_types=1);

$values = is_array($values ?? null) ? $values : [];
$errors = is_array($errors ?? null) ? $errors : [];
$companies = is_array($companies ?? null) ? $companies : [];
$types = is_array($types ?? null) ? $types : [];
$editing = ($editing ?? false) === true;
$action = $editing
    ? '/configuracion/almacenes/actualizar'
    : '/configuracion/almacenes';

$value = static fn (string $key): string => (string) ($values[$key] ?? '');
$checked = static function (string $key) use ($values): string {
    $default = $key === 'es_principal' ? '0' : '1';

    return (string) ($values[$key] ?? $default) === '1' ? 'checked' : '';
};
$error = static fn (string $key): string => (string) ($errors[$key] ?? '');
?>
<section class="config-page">
    <header class="config-page__header">
        <div>
            <p class="eyebrow">Configuración operativa</p>
            <h1><?= $editing ? 'Editar almacén' : 'Crear almacén' ?></h1>
            <p>El código de almacén será estable para folios operativos futuros.</p>
        </div>
        <a class="button button--secondary" href="/configuracion/almacenes">Volver</a>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">Corrige los campos marcados.</div>
    <?php endif; ?>

    <form class="config-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field($csrf) ?>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= e($value('id')) ?>">
        <?php endif; ?>

        <fieldset>
            <legend>Identidad operativa</legend>
            <label class="field">
                <span>Empresa *</span>
                <select name="empresa_id" required <?= $editing ? 'readonly' : '' ?>>
                    <option value="">Selecciona empresa</option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= e($company['id'] ?? '') ?>" <?= (int) ($value('empresa_id') ?: 0) === (int) ($company['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e($company['nombre'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($error('empresa_id') !== ''): ?><small><?= e($error('empresa_id')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Nombre *</span>
                <input name="nombre" required maxlength="150" value="<?= e($value('nombre')) ?>">
                <?php if ($error('nombre') !== ''): ?><small><?= e($error('nombre')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Código *</span>
                <input name="codigo" required maxlength="64" value="<?= e($value('codigo')) ?>">
                <small><?= e($error('codigo') ?: 'Usa de 2 a 64 caracteres: mayúsculas, números y separadores . _ -. Los espacios se normalizan a guion. Ejemplo: BO o BODEGA-PRINCIPAL.') ?></small>
            </label>
            <label class="field">
                <span>Tipo *</span>
                <select name="tipo_almacen" required>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= e($type) ?>" <?= ($value('tipo_almacen') ?: 'GENERAL') === $type ? 'selected' : '' ?>>
                            <?= e($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($error('tipo_almacen') !== ''): ?><small><?= e($error('tipo_almacen')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Responsable</span>
                <input name="responsable" maxlength="160" value="<?= e($value('responsable')) ?>">
            </label>
        </fieldset>

        <fieldset>
            <legend>Capacidades</legend>
            <?php foreach ([
                'permite_ventas' => 'Permite ventas',
                'permite_compras' => 'Permite compras',
                'permite_inventario' => 'Permite inventario',
                'permite_transferencias' => 'Permite transferencias',
                'es_principal' => 'Almacén principal de la empresa',
            ] as $key => $label): ?>
                <label class="check-field">
                    <input type="checkbox" name="<?= e($key) ?>" value="1" <?= $checked($key) ?>>
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <fieldset>
            <legend>Contacto y dirección</legend>
            <label class="field">
                <span>Teléfono</span>
                <input name="telefono" maxlength="30" value="<?= e($value('telefono')) ?>">
            </label>
            <label class="field">
                <span>Email</span>
                <input type="email" name="email" maxlength="120" value="<?= e($value('email')) ?>">
                <?php if ($error('email') !== ''): ?><small><?= e($error('email')) ?></small><?php endif; ?>
            </label>
            <?php foreach ([
                'pais' => 'País',
                'estado' => 'Estado',
                'municipio' => 'Municipio',
                'colonia' => 'Colonia',
                'calle' => 'Calle',
                'numero_exterior' => 'Número exterior',
                'numero_interior' => 'Número interior',
                'codigo_postal' => 'Código postal',
            ] as $key => $label): ?>
                <label class="field">
                    <span><?= e($label) ?></span>
                    <input name="<?= e($key) ?>" maxlength="160" value="<?= e($value($key)) ?>">
                    <?php if ($error($key) !== ''): ?><small><?= e($error($key)) ?></small><?php endif; ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <div class="form-actions">
            <button class="button" type="submit"><?= $editing ? 'Guardar cambios' : 'Crear almacén' ?></button>
            <a class="button button--secondary" href="/configuracion/almacenes">Cancelar</a>
        </div>
    </form>
</section>
