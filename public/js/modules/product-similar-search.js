(() => {
    const root = document.querySelector('[data-similar-search]');
    if (!root) return;
    const input = root.querySelector('[data-similar-input]');
    const results = root.querySelector('[data-similar-results]');
    const csrf = document.querySelector('form.product-form input[name="_token"]');
    const productId = root.dataset.productId || '';
    let timer = null;
    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        const query = input.value.trim();
        if (query.length < 2) { results.hidden = true; results.replaceChildren(); return; }
        timer = window.setTimeout(() => {
            fetch(`/productos/similares/buscar?id_producto=${encodeURIComponent(productId)}&q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } })
                .then(response => response.ok ? response.json() : {items: []})
                .then(data => {
                    results.replaceChildren();
                    (data.items || []).forEach(item => {
                        const button = document.createElement('button');
                        button.type = 'submit';
                        button.textContent = `${item.id_producto} · ${item.descripcion}`;
                        button.addEventListener('click', event => {
                            event.preventDefault();
                            const body = new URLSearchParams({
                                _token: csrf ? csrf.value : '',
                                id_producto: productId,
                                similar_id: item.id_producto,
                            });
                            fetch('/productos/similares/agregar', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body,
                            }).then(response => {
                                if (response.redirected) window.location.href = response.url;
                                else window.location.reload();
                            });
                        });
                        results.appendChild(button);
                    });
                    results.hidden = results.childElementCount === 0;
                }).catch(() => { results.hidden = true; });
        }, 180);
    });
})();
