'use strict';

(() => {
    const splash = document.querySelector('[data-pwa-splash]');
    if (!splash) return;

    const storageKey = 'soportegr:pwa-splash:v1';
    let alreadyShown = false;
    try {
        alreadyShown = window.sessionStorage.getItem(storageKey) === 'shown';
        if (!alreadyShown) window.sessionStorage.setItem(storageKey, 'shown');
    } catch (error) {
        // El splash sigue siendo opcional si el almacenamiento está bloqueado.
    }

    if (alreadyShown) return;

    splash.hidden = false;
    splash.classList.add('is-visible');
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
    const duration = reducedMotion ? 120 : 1000;

    window.setTimeout(() => {
        splash.classList.add('is-leaving');
        window.setTimeout(() => {
            splash.hidden = true;
            splash.classList.remove('is-visible', 'is-leaving');
        }, reducedMotion ? 120 : 300);
    }, duration);
})();
