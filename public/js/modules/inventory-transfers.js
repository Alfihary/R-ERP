(function () {
    'use strict';

    const form = document.querySelector('[data-inventory-transfer-form]');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const partsRoot = form.querySelector('[data-parts]');
    const addButton = form.querySelector('[data-add-part]');
    const endpoint = form.dataset.productSearchEndpoint || '';

    if (!(partsRoot instanceof HTMLElement) || !endpoint) {
        return;
    }

    const minLength = 2;
    const debounceMs = 250;

    const reindex = () => {
        partsRoot.querySelectorAll('[data-part]').forEach((part, index) => {
            part.querySelectorAll('[name]').forEach((input) => {
                input.name = input.name.replace(/partidas\[\d+]/, `partidas[${index}]`);
            });
        });
    };

    const selectedIds = () => Array.from(
        partsRoot.querySelectorAll('[data-product-id]')
    )
        .filter((input) => input instanceof HTMLInputElement)
        .map((input) => input.value)
        .filter((value) => value !== '');

    const setStatus = (root, message) => {
        const status = root.querySelector('[data-product-status]');

        if (status instanceof HTMLElement) {
            status.textContent = message;
        }
    };

    const hideResults = (root) => {
        const results = root.querySelector('[data-product-results]');

        if (results instanceof HTMLElement) {
            results.hidden = true;
            results.innerHTML = '';
        }
    };

    const setSeriesMode = (part, tracksSeries) => {
        const flag = part.querySelector('[data-product-tracks-series]');
        const panel = part.querySelector('[data-series-panel]');
        const input = part.querySelector('[data-series-input]');
        const quantity = part.querySelector('[name$="[cantidad]"]');

        if (flag instanceof HTMLInputElement) {
            flag.value = tracksSeries ? '1' : '0';
        }
        if (panel instanceof HTMLElement) {
            panel.hidden = !tracksSeries;
        }
        if (input instanceof HTMLTextAreaElement && !tracksSeries) {
            input.value = '';
        }
        if (quantity instanceof HTMLInputElement) {
            quantity.step = tracksSeries ? '1' : '0.000001';
            quantity.placeholder = tracksSeries ? '1' : '1.000000';
            quantity.inputMode = tracksSeries ? 'numeric' : 'decimal';
        }
    };

    const bindSearch = (part) => {
        const idInput = part.querySelector('[data-product-id]');
        const searchInput = part.querySelector('[data-product-search]');
        const results = part.querySelector('[data-product-results]');

        if (
            !(idInput instanceof HTMLInputElement)
            || !(searchInput instanceof HTMLInputElement)
            || !(results instanceof HTMLElement)
        ) {
            return;
        }

        let timer = 0;
        let requestId = 0;
        let controller = null;

        const renderItems = (items) => {
            results.innerHTML = '';

            if (items.length === 0) {
                hideResults(part);
                setStatus(part, 'Sin productos inventariables activos.');
                return;
            }

            const currentSelected = selectedIds();

            items.forEach((item) => {
                const productId = String(item.id_producto || '');
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'transfer-search-option';
                option.setAttribute('role', 'option');
                option.textContent = `${productId} · ${item.descripcion || ''}`;

                if (currentSelected.includes(productId) && idInput.value !== productId) {
                    option.disabled = true;
                    option.textContent += ' · ya seleccionado';
                }

                option.addEventListener('click', () => {
                    const tracksSeries = item.controla_series === true;
                    idInput.value = productId;
                    searchInput.value = `${productId} · ${item.descripcion || ''}`;
                    setSeriesMode(part, tracksSeries);
                    hideResults(part);
                    setStatus(
                        part,
                        tracksSeries
                            ? `${item.tipo_codigo || ''} seriado seleccionado. Captura una serie por unidad.`
                            : `${item.tipo_codigo || ''} seleccionado.`
                    );
                });
                results.append(option);
            });

            results.hidden = false;
            setStatus(part, `${items.length} resultado(s).`);
        };

        const search = () => {
            const query = searchInput.value.trim();
            idInput.value = '';
            setSeriesMode(part, false);
            hideResults(part);

            if (query.length < minLength) {
                setStatus(part, 'Escribe al menos 2 caracteres.');
                return;
            }

            requestId += 1;
            const activeRequest = requestId;

            if (controller !== null) {
                controller.abort();
            }

            controller = new AbortController();
            setStatus(part, 'Buscando producto...');

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

                    hideResults(part);
                    setStatus(part, 'No fue posible buscar productos.');
                });
        };

        searchInput.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(search, debounceMs);
        });

        searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideResults(part);
            }
        });
    };

    const bindPart = (part) => {
        bindSearch(part);
        const remove = part.querySelector('[data-remove-part]');

        if (remove instanceof HTMLButtonElement) {
            remove.addEventListener('click', () => {
                if (partsRoot.querySelectorAll('[data-part]').length === 1) {
                    return;
                }

                part.remove();
                reindex();
            });
        }
    };

    partsRoot.querySelectorAll('[data-part]').forEach(bindPart);

    if (addButton instanceof HTMLButtonElement) {
        addButton.addEventListener('click', () => {
            const first = partsRoot.querySelector('[data-part]');

            if (!(first instanceof HTMLElement)) {
                return;
            }

            const clone = first.cloneNode(true);

            if (!(clone instanceof HTMLElement)) {
                return;
            }

            clone.querySelectorAll('input').forEach((input) => {
                input.value = '';
            });
            clone.querySelectorAll('textarea').forEach((textarea) => {
                textarea.value = '';
            });
            setSeriesMode(clone, false);
            hideResults(clone);
            setStatus(clone, 'Escribe al menos 2 caracteres.');
            partsRoot.append(clone);
            reindex();
            bindPart(clone);
            const search = clone.querySelector('[data-product-search]');

            if (search instanceof HTMLInputElement) {
                search.focus();
            }
        });
    }
})();
