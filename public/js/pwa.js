'use strict';

(() => {
    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', async () => {
            try {
                const registration = await navigator.serviceWorker.register(
                    '/sw.js?v=8',
                    {
                        scope: '/',
                        updateViaCache: 'none'
                    }
                );

                await registration.update();
            } catch (error) {
                console.error(
                    'No se pudo registrar el Service Worker:',
                    error
                );

                document
                    .querySelectorAll('[data-pwa-message]')
                    .forEach((el) => {
                        el.textContent =
                            'No se pudo preparar la instalación. Recarga cuando tengas conexión.';
                    });
            }
        });
    }

    let installPrompt;

    const buttons = document.querySelectorAll(
        '[data-pwa-install]'
    );

    const installed = () =>
        window.matchMedia('(display-mode: standalone)').matches ||
        navigator.standalone === true;

    const hide = () => {
        buttons.forEach((button) => {
            button.hidden = true;
        });
    };

    window.addEventListener(
        'beforeinstallprompt',
        (event) => {
            event.preventDefault();

            installPrompt = event;

            if (!installed()) {
                buttons.forEach((button) => {
                    button.hidden = false;
                });
            }
        }
    );

    buttons.forEach((button) => {
        button.addEventListener(
            'click',
            async () => {
                if (!installPrompt) {
                    return;
                }

                const prompt = installPrompt;

                installPrompt = null;

                hide();

                try {
                    await prompt.prompt();
                    await prompt.userChoice;
                } catch (error) {
                    console.warn(
                        'La instalación PWA fue cancelada.',
                        error
                    );
                }
            }
        );
    });

    window.addEventListener(
        'appinstalled',
        hide
    );

    if (
        !installed() &&
        /iPad|iPhone|iPod/.test(navigator.userAgent)
    ) {
        document
            .querySelectorAll('[data-pwa-message]')
            .forEach((el) => {
                el.textContent =
                    'Para instalar: abre Compartir en Safari y elige “Agregar a inicio”.';
            });
    }
})();
