'use strict';
(async () => {
    const supported = await window.GRWebAuthn.available();
    const login = document.querySelector('[data-passkey-login]');
    const setup = document.querySelector('[data-passkey-setup]');
    const setupText = document.querySelector('[data-passkey-setup-text]');
    const panel = document.querySelector('[data-passkey-panel]');
    const enroll = document.querySelector('[data-passkey-enroll]');
    const list = document.querySelector('[data-passkey-list]');
    const dialog = document.querySelector('[data-passkey-dialog]');
    const compatibility = document.querySelector('[data-passkey-compatibility]');
    const info = text => document.querySelectorAll('[data-passkey-message]').forEach(el => { el.textContent = text; });
    let action = 'enroll';
    let credentialId = 0;
    let busy = false;
    let state;
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value;
    const encode = buffer => {
        let value = ''; for (const byte of new Uint8Array(buffer)) value += String.fromCharCode(byte);
        return btoa(value).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    };
    const decode = value => {
        let encoded = value.replace(/-/g, '+').replace(/_/g, '/');
        encoded += '='.repeat((4 - encoded.length % 4) % 4);
        return Uint8Array.from(atob(encoded), c => c.charCodeAt(0));
    };
    async function request(path, data) {
        const options = {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}};
        if (data !== undefined) {
            options.method = 'POST'; options.headers['X-CSRF-Token'] = csrf(); options.body = new URLSearchParams(data);
        }
        const response = await fetch(path, options);
        if (response.redirected) { location.assign(response.url); throw new Error('La sesión cambió.'); }
        if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Recarga la página o entra con tu contraseña.');
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'No se pudo verificar el acceso.');
        return result;
    }
    const formatDate = value => value ? new Intl.DateTimeFormat('es-MX', {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(value.replace(' ', 'T'))) : 'Aún no utilizada';
    function renderCredentials(credentials) {
        if (!list) return;
        list.replaceChildren();
        if (!credentials.length) {
            const empty = document.createElement('p'); empty.className = 'passkey-empty'; empty.textContent = 'No tienes passkeys registradas.'; list.append(empty); return;
        }
        for (const credential of credentials) {
            const item = document.createElement('article'); item.className = 'passkey-item';
            const details = document.createElement('div');
            const name = document.createElement('strong'); name.textContent = credential.name;
            const dates = document.createElement('small'); dates.textContent = `Registrada: ${formatDate(credential.created_at)} · Último uso: ${formatDate(credential.last_used_at)}`;
            details.append(name, dates);
            const button = document.createElement('button'); button.type = 'button'; button.className = 'device-secondary'; button.textContent = 'Revocar'; button.dataset.passkeyRevoke = String(credential.id);
            item.append(details, button); list.append(item);
        }
    }
    async function refresh() {
        state = await request(login ? '/auth/passkeys/estado' : '/perfil/passkeys/estado');
        if (login) {
            const canLogin = supported && state.available && state.has_credentials;
            login.hidden = !canLogin;
            if (setup) setup.hidden = canLogin;
            if (setupText) setupText.textContent = !supported
                ? 'Este navegador no admite passkeys. Entra con contraseña.'
                : !state.available
                    ? 'El servidor aún no permite passkeys. Entra con contraseña.'
                    : 'Entra con contraseña y regístrala en Perfil.';
        }
        if (panel) panel.hidden = false;
        if (enroll) enroll.hidden = !(supported && state.available);
        if (compatibility) compatibility.textContent = supported
            ? (state.available ? 'WebAuthn disponible en este navegador.' : 'El servidor aún no tiene habilitado WebAuthn.')
            : 'Este navegador o contexto no es compatible. Puedes revocar passkeys existentes, pero no registrar una nueva aquí.';
        renderCredentials(state.credentials || []);
    }
    async function ceremony(kind, password = '', name = '') {
        if (busy) return;
        busy = true;
        const buttons = [login, enroll, dialog?.querySelector('[type="submit"]')].filter(Boolean);
        buttons.forEach(button => { button.disabled = true; });
        try {
            if (!state?.available) throw new Error('El acceso con passkeys no está disponible en el servidor. Usa tu contraseña.');
            if (!supported) throw new Error('Este navegador no es compatible con WebAuthn.');
            if (state.origin !== location.origin) throw new Error('Abre ' + state.origin + ' para usar tu passkey.');
            info('Sigue las indicaciones de seguridad de tu dispositivo.');
            const options = await request(kind === 'login' ? '/auth/passkeys/opciones' : '/perfil/passkeys/opciones', kind === 'login' ? {} : {password});
            if (options.redirect) { location.assign(options.redirect); return; }
            options.publicKey.challenge = decode(options.publicKey.challenge);
            if (options.publicKey.user) options.publicKey.user.id = decode(options.publicKey.user.id);
            for (const key of ['allowCredentials', 'excludeCredentials']) for (const item of options.publicKey[key] || []) item.id = decode(item.id);
            const credential = kind === 'login' ? await navigator.credentials.get(options) : await navigator.credentials.create(options);
            if (!credential) throw new Error('El autenticador no respondió.');
            const data = {rawId: encode(credential.rawId), clientDataJSON: encode(credential.response.clientDataJSON)};
            if (kind === 'login') {
                data.authenticatorData = encode(credential.response.authenticatorData); data.signature = encode(credential.response.signature);
                data.userHandle = credential.response.userHandle ? encode(credential.response.userHandle) : '';
            } else { data.attestationObject = encode(credential.response.attestationObject); data.name = name; }
            const result = await request(kind === 'login' ? '/auth/passkeys/verificar' : '/perfil/passkeys/registrar', data);
            if (kind === 'login') { location.assign(result.redirect); return; }
            dialog.close(); await refresh(); info('Passkey registrada. Ya puedes usar “Ingresar con biometría” al cerrar sesión.');
        } catch (error) {
            info(error.name === 'NotAllowedError' ? 'La verificación fue cancelada o no hay un autenticador disponible.' : error.message);
        } finally { busy = false; buttons.forEach(button => { button.disabled = false; }); }
    }
    function openEnroll() {
        action = 'enroll'; credentialId = 0;
        dialog.querySelector('[data-passkey-dialog-title]').textContent = 'Registrar una passkey';
        dialog.querySelector('[data-passkey-dialog-help]').textContent = 'Confirma tu contraseña y después verifica tu identidad con el método que ofrezca el dispositivo.';
        dialog.querySelector('[data-passkey-name-row]').hidden = false; dialog.querySelector('[data-passkey-name]').required = true; dialog.showModal();
    }
    function openRevoke(id) {
        action = 'revoke'; credentialId = id;
        dialog.querySelector('[data-passkey-dialog-title]').textContent = 'Revocar esta passkey';
        dialog.querySelector('[data-passkey-dialog-help]').textContent = 'Confirma tu contraseña. Esta passkey dejará de permitir acceso, aunque puede permanecer en el administrador del dispositivo.';
        dialog.querySelector('[data-passkey-name-row]').hidden = true; dialog.querySelector('[data-passkey-name]').required = false; dialog.showModal();
    }
    login?.addEventListener('click', () => ceremony('login'));
    enroll?.addEventListener('click', openEnroll);
    list?.addEventListener('click', event => { const button = event.target instanceof Element ? event.target.closest('[data-passkey-revoke]') : null; if (button) openRevoke(Number(button.dataset.passkeyRevoke)); });
    dialog?.querySelector('[data-passkey-cancel]')?.addEventListener('click', () => dialog.close());
    dialog?.addEventListener('close', () => { dialog.querySelector('[data-passkey-password]').value = ''; dialog.querySelector('[data-passkey-name]').value = ''; });
    dialog?.querySelector('form')?.addEventListener('submit', async event => {
        event.preventDefault(); if (busy) return;
        const password = dialog.querySelector('[data-passkey-password]').value;
        const name = dialog.querySelector('[data-passkey-name]').value.trim();
        dialog.querySelector('[data-passkey-password]').value = '';
        if (action === 'enroll') { await ceremony('register', password, name); return; }
        busy = true;
        try { await request('/perfil/passkeys/revocar', {password, credential_id: String(credentialId)}); dialog.close(); await refresh(); info('Passkey revocada.'); }
        catch (error) { info(error.message); }
        finally { busy = false; }
    });
    try { await refresh(); }
    catch {
        if (login) login.hidden = true;
        if (setup) setup.hidden = false;
        if (setupText) setupText.textContent = 'No se pudo comprobar el acceso con passkeys. Ingresa con tu contraseña.';
        if (panel) panel.hidden = false;
        info('No se pudo consultar el estado de las passkeys.');
    }
})();
