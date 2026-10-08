'use strict';

(() => {
    const page = document.querySelector('[data-credential-page]');
    if (!page) return;

    const flipCard = page.querySelector('[data-credential-flip-card]');
    const flipButton = page.querySelector('[data-credential-flip]');
    const downloadButton = page.querySelector('[data-credential-download-front]');
    const front = page.querySelector('[data-credential-face="front"]');
    const back = page.querySelector('[data-credential-face="back"]');
    const status = page.querySelector('[data-credential-export-status]');
    let busy = false;

    function setFlipped(flipped) {
        flipCard?.classList.toggle('is-flipped', flipped);
        flipButton?.setAttribute('aria-pressed', String(flipped));
        if (flipButton) flipButton.textContent = flipped ? 'Ver frente' : 'Ver reverso';
        front?.setAttribute('aria-hidden', String(flipped));
        back?.setAttribute('aria-hidden', String(!flipped));
    }

    flipButton?.addEventListener('click', () => {
        setFlipped(!flipCard?.classList.contains('is-flipped'));
    });

    async function readyImages(element) {
        const images = [...element.querySelectorAll('img')];
        await Promise.all(images.map(image => {
            if (image.complete && image.naturalWidth > 0) return image.decode?.().catch(() => undefined);
            return new Promise(resolve => {
                image.addEventListener('load', resolve, {once: true});
                image.addEventListener('error', resolve, {once: true});
            });
        }));
    }

    async function renderFront() {
        await readyImages(front);
        if (document.fonts?.ready) await document.fonts.ready;
        return window.html2canvas(front, {
            backgroundColor: null,
            imageTimeout: 12000,
            logging: false,
            scale: 3,
            useCORS: true,
            ignoreElements: element => element.hasAttribute('data-export-exclude'),
        });
    }

    function save(canvas) {
        const link = document.createElement('a');
        link.download = 'credencial-grupo-refrigerantes-frente.png';
        link.href = canvas.toDataURL('image/png', 1);
        link.click();
    }

    async function downloadFront() {
        if (busy) return;
        if (!front || typeof window.html2canvas !== 'function') {
            status.textContent = 'No fue posible cargar el generador PNG. Recarga la página e inténtalo de nuevo.';
            return;
        }

        busy = true;
        document.documentElement.classList.add('credential-png-export');
        page.classList.add('is-exporting');
        downloadButton.disabled = true;
        flipButton.disabled = true;
        status.textContent = 'Generando el frente en alta resolución…';

        try {
            save(await renderFront());
            status.textContent = 'Frente descargado correctamente.';
        } catch (error) {
            console.error('Credential front PNG export failed.', error);
            status.textContent = 'No se pudo generar el PNG. Comprueba que la fotografía y el QR estén disponibles.';
        } finally {
            document.documentElement.classList.remove('credential-png-export');
            page.classList.remove('is-exporting');
            downloadButton.disabled = false;
            flipButton.disabled = false;
            busy = false;
        }
    }

    downloadButton?.addEventListener('click', downloadFront);
    setFlipped(false);
})();
