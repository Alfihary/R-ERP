'use strict';
(() => {
    const button = document.querySelector('[data-password-toggle]');
    const password = document.getElementById('password');
    if (button && password) {
        button.hidden = false;
        button.addEventListener('click', () => {
            const reveal = password.type === 'password';
            password.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(reveal));
            button.setAttribute('aria-label', reveal ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    }
    document.querySelector('.recovery-link')?.addEventListener('click', () => {
        const support = document.getElementById('login-support');
        if (support) support.open = true;
    });
})();
