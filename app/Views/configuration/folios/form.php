<?php

declare(strict_types=1);

$values = is_array($values ?? null) ? $values : [];
$errors = is_array($errors ?? null) ? $errors : [];
$companies = is_array($companies ?? null) ? $companies : [];
$warehouses = is_array($warehouses ?? null) ? $warehouses : [];
$documentTypes = is_array($documentTypes ?? null) ? $documentTypes : [];
$prefixSuggestions = is_array($prefixSuggestions ?? null) ? $prefixSuggestions : [];
$editing = ($editing ?? false) === true;
$locked = ($locked ?? false) === true;
$action = $editing ? '/configuracion/folios/actualizar' : '/configuracion/folios';
$value = static fn (string $key): string => (string) ($values[$key] ?? '');
$error = static fn (string $key): string => (string) ($errors[$key] ?? '');
$disabled = $locked ? 'disabled' : '';
?>
<section class="folio-page">
    <header class="folio-page__header">
        <div>
            <p class="eyebrow">Configuración operativa</p>
            <h1><?= $editing ? 'Editar serie documental' : 'Crear serie documental' ?></h1>
            <p>Configura folios por almacén. La UI no emite folios funcionales.</p>
        </div>
        <a class="button button--secondary" href="/configuracion/folios">Volver</a>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger"><?= e(implode(' ', array_values($errors))) ?></div>
    <?php endif; ?>
    <?php if ($locked): ?>
        <div class="alert alert--warning">
            Esta serie ya tiene folios emitidos. En MVP solo puede cambiarse el estado activo.
        </div>
    <?php endif; ?>

    <form class="folio-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field($csrf) ?>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= e($value('id')) ?>">
        <?php endif; ?>
        <?php if ($locked): ?>
            <?php foreach ([
                'empresa_id', 'almacen_id', 'tipo_documento', 'codigo_serie',
                'prefijo', 'formato', 'separador', 'siguiente_numero',
                'longitud', 'reinicio_anual', 'anio_actual',
            ] as $key): ?>
                <input type="hidden" name="<?= e($key) ?>" value="<?= e($value($key)) ?>">
            <?php endforeach; ?>
        <?php endif; ?>

        <fieldset>
            <legend>Scope documental</legend>
            <label class="field">
                <span>Empresa *</span>
                <select name="empresa_id" required <?= $disabled ?>>
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
                <span>Almacén *</span>
                <select name="almacen_id" required <?= $disabled ?>>
                    <option value="">Selecciona almacén</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?= e($warehouse['id'] ?? '') ?>" <?= (int) ($value('almacen_id') ?: 0) === (int) ($warehouse['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e($warehouse['nombre'] ?? '') ?> · <?= e($warehouse['codigo'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small><?= e($error('almacen_id') ?: 'Al crear, el código del almacén se copia como snapshot.') ?></small>
            </label>
            <label class="field">
                <span>Tipo de documento *</span>
                <select name="tipo_documento" required <?= $disabled ?>>
                    <option value="">Selecciona tipo</option>
                    <?php foreach ($documentTypes as $type): ?>
                        <option value="<?= e($type) ?>" <?= ($value('tipo_documento')) === $type ? 'selected' : '' ?>>
                            <?= e($type) ?><?= isset($prefixSuggestions[$type]) ? ' · ' . e($prefixSuggestions[$type]) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($error('tipo_documento') !== ''): ?><small><?= e($error('tipo_documento')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Código de serie *</span>
                <input name="codigo_serie" required maxlength="20" value="<?= e($value('codigo_serie')) ?>" <?= $disabled ?>>
                <small><?= e($error('codigo_serie') ?: 'Ejemplo: F, R, TR, AJ. Único por empresa, almacén y tipo.') ?></small>
            </label>
        </fieldset>

        <fieldset>
            <legend>Formato</legend>
            <label class="field">
                <span>Prefijo *</span>
                <input name="prefijo" required maxlength="20" value="<?= e($value('prefijo')) ?>" <?= $disabled ?>>
                <small><?= e($error('prefijo') ?: 'Mayúsculas y números, sin espacios.') ?></small>
            </label>
            <label class="field">
                <span>Formato *</span>
                <input name="formato" required value="<?= e($value('formato') ?: '{PREFIJO}-{ALMACEN}{NUMERO}') ?>" <?= $disabled ?>>
                <?php if ($error('formato') !== ''): ?><small><?= e($error('formato')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Separador *</span>
                <input name="separador" required maxlength="5" value="<?= e($value('separador') ?: '-') ?>" <?= $disabled ?>>
                <?php if ($error('separador') !== ''): ?><small><?= e($error('separador')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Siguiente número *</span>
                <input type="number" name="siguiente_numero" min="1" required value="<?= e($value('siguiente_numero') ?: '1') ?>" <?= $disabled ?>>
                <?php if ($error('siguiente_numero') !== ''): ?><small><?= e($error('siguiente_numero')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Longitud *</span>
                <input type="number" name="longitud" min="1" max="12" required value="<?= e($value('longitud') ?: '6') ?>" <?= $disabled ?>>
                <?php if ($error('longitud') !== ''): ?><small><?= e($error('longitud')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Año actual</span>
                <input type="number" name="anio_actual" min="2000" max="2100" value="<?= e($value('anio_actual')) ?>" <?= $disabled ?>>
                <?php if ($error('anio_actual') !== ''): ?><small><?= e($error('anio_actual')) ?></small><?php endif; ?>
            </label>
            <label class="check-field">
                <input type="checkbox" name="reinicio_anual" value="1" <?= (string) ($values['reinicio_anual'] ?? '0') === '1' ? 'checked' : '' ?> <?= $disabled ?>>
                <span>Reinicio anual</span>
            </label>
            <label class="check-field">
                <input type="checkbox" name="activo" value="1" <?= (string) ($values['activo'] ?? '1') === '1' ? 'checked' : '' ?>>
                <span>Activa</span>
            </label>
        </fieldset>

        <aside class="folio-preview">
            <span>Vista previa sin emisión</span>
            <strong><?= e($preview ?? 'F-BO000001') ?></strong>
            <p>No inserta registros en documentos_folios.</p>
        </aside>

        <div class="form-actions">
            <button class="button" type="submit"><?= $editing ? 'Guardar cambios' : 'Crear serie' ?></button>
            <a class="button button--secondary" href="/configuracion/folios">Cancelar</a>
        </div>
    </form>
</section>
