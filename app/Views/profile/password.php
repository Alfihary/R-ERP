<?php

declare(strict_types=1);

$errors = is_array($errors ?? null) ? $errors : [];
$error = static fn (string $key): string => (string) ($errors[$key] ?? '');
?>
<section class="profile-page profile-page--narrow">
    <header class="profile-page__header">
        <div>
            <p class="eyebrow">Cuenta personal</p>
            <h1>Cambiar contraseña</h1>
            <p>
                La contraseña se actualiza de forma privada. No se muestra ni se
                almacena en texto plano.
            </p>
        </div>
        <a class="button button--secondary" href="/perfil">Volver a perfil</a>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            No se pudo actualizar la contraseña. Revisa los campos marcados.
        </div>
    <?php endif; ?>

    <form class="profile-form profile-form--password" method="post" action="/perfil/password">
        <?= csrf_field($csrf) ?>
        <fieldset>
            <legend>Confirmación segura</legend>
            <label class="field field--wide">
                <span>Contraseña actual</span>
                <input name="password_actual" type="password" autocomplete="current-password" required>
                <?php if ($error('password_actual') !== ''): ?><small><?= e($error('password_actual')) ?></small><?php endif; ?>
            </label>
            <label class="field field--wide">
                <span>Nueva contraseña</span>
                <input name="password_nueva" type="password" autocomplete="new-password" required minlength="8">
                <?php if ($error('password_nueva') !== ''): ?><small><?= e($error('password_nueva')) ?></small><?php endif; ?>
            </label>
            <label class="field field--wide">
                <span>Confirmar nueva contraseña</span>
                <input name="password_confirmacion" type="password" autocomplete="new-password" required minlength="8">
                <?php if ($error('password_confirmacion') !== ''): ?><small><?= e($error('password_confirmacion')) ?></small><?php endif; ?>
            </label>
        </fieldset>

        <div class="form-actions">
            <button class="button" type="submit">Actualizar contraseña</button>
            <a class="button button--secondary" href="/perfil">Cancelar</a>
        </div>
    </form>
</section>
