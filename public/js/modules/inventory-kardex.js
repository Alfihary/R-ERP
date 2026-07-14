(function () {
    'use strict';

    const form = document.querySelector('[data-kardex-form]');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const endpoint = form.dataset.productSearchEndpoint || '';
    const searchInput = form.querySelector('[data-kardex-product-search]');
    const idInput = form.querySelector('[data-kardex-product-id]');
    const results = form.querySelector('[data-kardex-product-results]');
    const status = form.querySelector('[data-kardex-product-status]');

    if (
        !endpoint
        || !(searchInput instanceof HTMLInputElement)
        || !(idInput instanceof HTMLInputElement)
        || !(results instanceof HTMLElement)
    ) {
        return;
    }

    const setStatus = (message) => {
        if (status instanceof HTMLElement) {
            status.textContent = message;
        }
    };

    const hideResults = () => {
        results.hidden = true;
        results.innerHTML = '';
    };

    const renderItems = (items) => {
        results.innerHTML = '';

        if (items.length === 0) {
            hideResults();
            setStatus('Sin resultados activos.');
            return;
        }

        items.forEach((item) => {
            const productId = String(item.id_producto || '');
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'kardex-search-option';
            option.setAttribute('role', 'option');
            option.textContent = `${productId} · ${item.descripcion || ''}`;

            option.addEventListener('click', () => {
                idInput.value = productId;
                searchInput.value = `${productId} · ${item.descripcion || ''}`;
                hideResults();
                setStatus(`${item.tipo_codigo || ''} seleccionado.`);
            });

            results.append(option);
        });

        results.hidden = false;
        setStatus(`${items.length} resultado(s).`);
    };

    let timer = 0;
    let requestId = 0;
    let controller = null;

    searchInput.addEventListener('input', () => {
        window.clearTimeout(timer);
        idInput.value = '';
        hideResults();

        const query = searchInput.value.trim();

        if (query.length < 2) {
            setStatus('Escribe al menos 2 caracteres.');
            return;
        }

        timer = window.setTimeout(() => {
            requestId += 1;
            const activeRequest = requestId;

            if (controller !== null) {
                controller.abort();
            }

            controller = new AbortController();
            setStatus('Buscando producto...');

            fetch(`${endpoint}?q=${encodeURIComponent(query)}`, {
                headers: {'Accept': 'application/json'},
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Solicitud rechazada.');
                    }

                    return response.json();
                })
                .then((data) => {
                    if (activeRequest !== requestId) {
                        return;
                    }

                    renderItems(Array.isArray(data.items) ? data.items : []);
                })
                .catch((error) => {
                    if (error.name === 'AbortError') {
                        return;
                    }

                    hideResults();
                    setStatus('No fue posible buscar productos.');
                });
        }, 250);
    });

    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            hideResults();
        }
    });
})();
