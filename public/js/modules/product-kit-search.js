(() => {
    'use strict';
    const root = document.querySelector('[data-product-kit-search]');
    if (!root) return;
    const input = root.querySelector('[data-kit-query]');
    const results = root.querySelector('[data-kit-results]');
    const selected = root.querySelector('[data-kit-selected-id]');
    let timer = null; let controller = null;
    const render = (items) => { results.replaceChildren(); items.forEach((item) => { const button=document.createElement('button');button.type='button';button.dataset.componentId=item.id_producto||'';const id=document.createElement('strong');id.textContent=item.id_producto||'';const label=document.createElement('span');label.textContent=` — ${item.descripcion||''}${item.sku?` · ${item.sku}`:''}`;button.append(id,label);button.addEventListener('click',()=>{if(selected)selected.value=button.dataset.componentId||'';input.value=item.id_producto||'';results.replaceChildren();});results.appendChild(button); }); };
    input?.addEventListener('input', () => { clearTimeout(timer); if (controller) controller.abort(); const q=input.value.trim(); if(!q){render([]);return;} timer=setTimeout(async()=>{controller=new AbortController();try{const url=new URL(root.dataset.searchUrl,window.location.origin);url.searchParams.set('kit_id_producto',root.dataset.kitId||'');url.searchParams.set('q',q);url.searchParams.set('limit','10');const response=await fetch(url,{headers:{Accept:'application/json'},signal:controller.signal});if(!response.ok)throw new Error('search');const data=await response.json();render(Array.isArray(data.items)?data.items:[]);}catch(error){if(error.name!=='AbortError')render([]);}},250);});
})();
