(function () {
    'use strict';

    const roots = document.querySelectorAll('[data-sat-search]');

    if (roots.length === 0) {
        return;
    }

    const debounceMs = 250;
    const minLength = 2;

    roots.forEach((root) => {
        const endpoint = root.dataset.endpoint || '';
        const idInput = root.querySelector('[data-sat-key-id]');
        const searchInput = root.querySelector('[data-sat-key-search]');
        const results = root.querySelector('[data-sat-results]');
        const status = root.querySelector('[data-sat-status]');
        const clear = root.querySelector('[data-sat-clear]');

        if (
            !endpoint
            || !(idInput instanceof HTMLInputElement)
            || !(searchInput instanceof HTMLInputElement)
            || !(results instanceof HTMLElement)
            || !(status instanceof HTMLElement)
        ) {
            return;
        }

        let timer = 0;
        let requestId = 0;
        let controller = null;

        const setStatus = (message) => {
            status.textContent = message;
        };

        const hideResults = () => {
            results.hidden = true;
            results.innerHTML = '';
        };

        const selectItem = (item) => {
            idInput.value = String(item.id);
            searchInput.value = `${item.codigo} · ${item.descripcion}`;
            hideResults();
            setStatus('Clave SAT seleccionada.');
        };

        const renderItems = (items) => {
            results.innerHTML = '';

            if (items.length === 0) {
                hideResults();
                setStatus('Sin resultados activos.');
                return;
            }

            items.forEach((item) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'product-sat-search__option';
                option.setAttribute('role', 'option');
                option.textContent = `${item.codigo} · ${item.descripcion}`;
                option.addEventListener('click', () => selectItem(item));
                results.append(option);
            });

            results.hidden = false;
            setStatus(`${items.length} resultado(s) activo(s).`);
        };

        const search = () => {
            const query = searchInput.value.trim();

            idInput.value = '';
            hideResults();

            if (query.length < minLength) {
                setStatus('Escribe al menos 2 caracteres.');
                return;
            }

            requestId += 1;
            const activeRequest = requestId;

            if (controller !== null) {
                controller.abort();
            }

            controller = new AbortController();
            setStatus('Buscando clave SAT...');

            fetch(`${endpoint}?q=${encodeURIComponent(query)}`, {
                headers: {
                    'Accept': 'application/json',
                },
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

                    const items = Array.isArray(data.items)
                        ? data.items
                        : [];
                    renderItems(items);
                })
                .catch((error) => {
                    if (error.name === 'AbortError') {
                        return;
                    }

                    hideResults();
                    setStatus('No fue posible buscar la clave SAT.');
                });
        };

        searchInput.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(search, debounceMs);
        });

        searchInput.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            hideResults();
            setStatus('');
        });

        if (clear instanceof HTMLButtonElement) {
            clear.addEventListener('click', () => {
                idInput.value = '';
                searchInput.value = '';
                hideResults();
                setStatus('Clave SAT sin asignar.');
                searchInput.focus();
            });
        }
    });
})();
