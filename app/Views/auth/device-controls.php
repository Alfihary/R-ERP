<section class="device-panel" aria-label="Instalación y protección de la sesión">
    <p data-device-info>Protege esta sesión con tu dispositivo.</p>
    <button type="button" class="device-secondary" data-device-enable hidden>Proteger esta sesión con huella</button>
    <button type="button" class="device-secondary" data-device-lock hidden>Bloquear sesión</button>
    <button type="button" class="pwa-install" data-pwa-install hidden>Instalar aplicación</button>
    <p>Para entrar con huella después de cerrar sesión, <a href="/perfil#passkeys-title">registra una passkey en Perfil</a>.</p>
    <p data-device-message role="status" aria-live="polite"></p>
    <p data-pwa-message role="status"></p>
</section>
<dialog class="device-dialog" data-device-dialog aria-labelledby="device-title">
    <h2 id="device-title">Proteger esta sesión</h2>
    <p>Confirma tu contraseña y activa la huella, el rostro o el PIN del dispositivo. La protección dura hasta cerrar sesión, con un máximo de 8 horas.</p>
    <form>
        <label for="device-password">Contraseña actual</label>
        <input id="device-password" type="password" autocomplete="current-password" required maxlength="1024">
        <p data-device-message role="status" aria-live="polite"></p>
        <div class="device-dialog-actions">
            <button type="submit" class="device-secondary">Activar protección</button>
            <button type="button" class="device-secondary" data-device-cancel>Cancelar</button>
        </div>
    </form>
</dialog>
