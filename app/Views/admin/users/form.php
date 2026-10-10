<?php

declare(strict_types=1);

$values = is_array($values ?? null) ? $values : [];
$errors = is_array($errors ?? null) ? $errors : [];
$editing = ($editing ?? false) === true;
$userId = (int) ($userId ?? 0);
$selectedRoles = array_map('intval', is_array($values['roles'] ?? null) ? $values['roles'] : []);
$value = static fn (string $key): string => (string) ($values[$key] ?? '');
$error = static fn (string $key): string => (string) ($errors[$key] ?? '');
$action = $editing ? '/admin/usuarios/' . $userId . '/editar' : '/admin/usuarios';
?>
<section class="admin-users-page">
    <header class="admin-users-page__header"><div><p class="eyebrow">Administración de acceso</p><h1><?= $editing ? 'Editar usuario' : 'Crear usuario' ?></h1><p>Las reglas definitivas permanecen en UserAdminService.</p></div><a class="button button--secondary" href="/admin/usuarios">Volver</a></header>
    <?php if ($errors !== []): ?><div class="alert alert--danger"><?= e($errors['general'] ?? 'Corrige los campos marcados.') ?></div><?php endif; ?>
    <form class="admin-user-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field($csrf) ?>
        <fieldset><legend>Identidad y acceso</legend>
            <label class="field"><span>Username *</span><input name="username" required minlength="3" maxlength="50" pattern="[a-z0-9._-]{3,50}" autocomplete="username" value="<?= e($value('username')) ?>"><small>3–50 caracteres: a-z, 0-9, punto, guion y guion bajo.</small><?php if ($error('username') !== ''): ?><small class="field-error"><?= e($error('username')) ?></small><?php endif; ?></label>
            <label class="field"><span>Email *</span><input type="email" name="email" required maxlength="254" autocomplete="email" value="<?= e($value('email')) ?>"><?php if ($error('email') !== ''): ?><small class="field-error"><?= e($error('email')) ?></small><?php endif; ?></label>
            <?php if (!$editing): ?><label class="field"><span>Contraseña inicial *</span><input type="password" name="password" required minlength="12" maxlength="255" autocomplete="new-password"><small>Mínimo 12 caracteres. Nunca se conserva tras un error.</small></label><?php endif; ?>
            <label class="field field--inline"><input type="checkbox" name="activo" value="1" <?= !$editing || (int) ($values['activo'] ?? 0) === 1 ? 'checked' : '' ?>><span>Usuario activo</span></label>
        </fieldset>
        <fieldset><legend>Empresa y almacén</legend>
            <label class="field"><span>Empresa *</span><select name="company_id" required><option value="">Selecciona</option><?php foreach ($companies as $company): ?><option value="<?= e($company['id']) ?>" <?= (int) $value('company_id') === (int) $company['id'] ? 'selected' : '' ?>><?= e($company['nombre']) ?></option><?php endforeach; ?></select><?php if ($error('company_id') !== ''): ?><small class="field-error"><?= e($error('company_id')) ?></small><?php endif; ?></label>
            <label class="field"><span>Almacén *</span><select name="warehouse_id" required><option value="">Selecciona</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= e($warehouse['id']) ?>" data-company-id="<?= e($warehouse['empresa_id']) ?>" <?= (int) $value('warehouse_id') === (int) $warehouse['id'] ? 'selected' : '' ?>><?= e($warehouse['nombre']) ?></option><?php endforeach; ?></select><?php if ($error('warehouse_id') !== ''): ?><small class="field-error"><?= e($error('warehouse_id')) ?></small><?php endif; ?></label>
        </fieldset>
        <fieldset><legend>Roles</legend><div class="admin-users-role-grid"><?php foreach ($roles as $role): ?><label class="field field--inline"><input type="checkbox" name="roles[]" value="<?= e($role['id']) ?>" <?= in_array((int) $role['id'], $selectedRoles, true) ? 'checked' : '' ?>><span><?= e($role['nombre']) ?> <small>(<?= e($role['codigo']) ?>)</small></span></label><?php endforeach; ?></div><?php if ($error('roles') !== ''): ?><small class="field-error"><?= e($error('roles')) ?></small><?php endif; ?><small>Un usuario activo necesita al menos un rol.</small></fieldset>
        <?php if ($editing): ?><fieldset class="admin-users-readonly"><legend>Nombre visible</legend><p><?= e(trim(implode(' ', array_filter([$values['primer_nombre'] ?? '', $values['segundo_nombre'] ?? '', $values['apellido_paterno'] ?? '', $values['apellido_materno'] ?? '']))) ?: 'Sin nombre visible') ?></p><small>El perfil visible se administra en su flujo propio; esta fase no mueve esa escritura al controller.</small></fieldset><?php endif; ?>
        <div class="form-actions"><button class="button" type="submit"><?= $editing ? 'Guardar cambios' : 'Crear usuario' ?></button><a class="button button--secondary" href="/admin/usuarios">Cancelar</a></div>
    </form>
    <?php if ($editing): ?>
        <?php if (($abilities['roles'] ?? false) === true): ?><form class="admin-users-secondary-form" method="post" action="/admin/usuarios/<?= e($userId) ?>/roles"><?= csrf_field($csrf) ?><fieldset><legend>Sincronizar roles</legend><?php foreach ($roles as $role): ?><label class="field field--inline"><input type="checkbox" name="roles[]" value="<?= e($role['id']) ?>" <?= in_array((int) $role['id'], $selectedRoles, true) ? 'checked' : '' ?>><span><?= e($role['nombre']) ?></span></label><?php endforeach; ?><button class="button button--secondary" type="submit">Guardar roles</button></fieldset></form><?php endif; ?>
        <?php if (($abilities['password'] ?? false) === true): ?><form class="admin-users-secondary-form" method="post" action="/admin/usuarios/<?= e($userId) ?>/password"><?= csrf_field($csrf) ?><fieldset><legend>Restablecer contraseña</legend><label class="field"><span>Nueva contraseña</span><input type="password" name="password" required minlength="12" maxlength="255" autocomplete="new-password"></label><label class="field"><span>Confirmación</span><input type="password" name="password_confirmation" required minlength="12" maxlength="255" autocomplete="new-password"></label><button class="button button--secondary" type="submit">Restablecer contraseña</button></fieldset></form><?php endif; ?>
    <?php endif; ?>
</section>
