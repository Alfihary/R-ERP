(() => {
    const section = document.querySelector('.vcard-public__help');
    const form = document.getElementById('vcard-help-form');
    const serviceInput = form?.querySelector('input[name="servicio"]');
    const messageInput = document.getElementById('vcard-help-message');
    const detailsInput = document.getElementById('vcard-help-details');
    const fieldsHost = document.getElementById('vcard-help-specific-fields');
    const topic = document.getElementById('vcard-help-topic');
    const subtitle = document.getElementById('vcard-help-subtitle');
    const chipsHost = document.getElementById('vcard-help-chips');
    const submit = document.getElementById('vcard-help-submit');
    const status = document.getElementById('vcard-help-status');

    if (!section || !form || !serviceInput || !messageInput || !detailsInput || !fieldsHost || !topic || !subtitle || !chipsHost) return;

    const commonUnknown = [
        { value: 'no_se', label: 'No sé' },
        { value: 'no_aplica', label: 'No aplica' },
        { value: 'no_estoy_seguro', label: 'No estoy seguro' },
    ];
    const urgencyOptions = [['normal', 'Normal'], ['esta_semana', 'Esta semana'], ['urgente', 'Urgente']];
    const serviceConfig = {
        proyecto: {
            title: 'Cuéntanos sobre tu proyecto', subtitle: 'Con algunos datos podemos canalizarte con el área adecuada.', cta: 'Solicitar asesoría para mi proyecto',
            fields: [
                { key: 'tipo_proyecto', label: 'Tipo de proyecto', type: 'select', required: true, options: [['nuevo', 'Nuevo proyecto'], ['ampliacion', 'Ampliación'], ['reemplazo', 'Reemplazo'], ['remodelacion', 'Remodelación'], ['asesoria', 'Asesoría']] },
                { key: 'aplicacion', label: 'Aplicación', type: 'text', placeholder: 'Ej. cámara de refrigeración' },
                { key: 'ubicacion', label: 'Ciudad o ubicación', type: 'text', autocomplete: 'address-level2' },
                { key: 'etapa', label: 'Etapa del proyecto', type: 'select', options: [['idea', 'Idea'], ['planeacion', 'Planeación'], ['cotizando', 'Cotizando'], ['ejecucion', 'En ejecución'], ['reemplazo', 'Reemplazo']] },
                { key: 'capacidad_aproximada', label: 'Capacidad aproximada (opcional)', type: 'text', placeholder: 'Si la conoces' },
                { key: 'descripcion', label: '¿Qué necesitas?', type: 'textarea', required: true, placeholder: 'Cuéntanos brevemente sobre tu proyecto' },
            ], summary: ['tipo_proyecto', 'aplicacion', 'ubicacion', 'etapa', 'capacidad_aproximada'],
        },
        // Se conserva para compatibilidad interna; la pestaña independiente ya no se renderiza.
        refaccion: {
            title: 'Te ayudamos a encontrar la refacción correcta', subtitle: 'No necesitas conocer todos los datos técnicos. Ingresa lo que tengas.', cta: 'Buscar mi refacción',
            fields: [
                { key: 'tipo_refaccion', label: 'Tipo de refacción', type: 'text', placeholder: 'Ej. compresor, tarjeta, motor' },
                { key: 'marca', label: 'Marca', type: 'text', suggestions: true },
                { key: 'modelo_clave', label: 'Modelo o clave', type: 'text', suggestions: true },
                { key: 'refrigerante', label: 'Refrigerante', type: 'text', suggestions: true },
                { key: 'voltaje', label: 'Voltaje', type: 'text', suggestions: true },
                { key: 'cantidad', label: 'Cantidad', type: 'number', min: '1', placeholder: '1' },
                { key: 'descripcion', label: 'Información adicional', type: 'textarea', placeholder: '¿Dónde se utiliza o qué falla presenta?' },
            ], summary: ['tipo_refaccion', 'marca', 'modelo_clave', 'refrigerante', 'cantidad'],
        },
        capacitacion: {
            title: 'Encuentra la capacitación adecuada', subtitle: 'Cuéntanos qué quieres aprender y tu nivel actual.', cta: 'Quiero información del Instituto ACR',
            fields: [
                { key: 'curso_tema', label: 'Curso o tema de interés', type: 'text', required: true, placeholder: 'Ej. refrigeración comercial' },
                { key: 'nivel', label: 'Nivel de experiencia', type: 'select', options: [['principiante', 'Principiante'], ['tecnico', 'Técnico'], ['instalador', 'Instalador'], ['mantenimiento', 'Mantenimiento'], ['profesional', 'Profesional']] },
                { key: 'modalidad', label: 'Modalidad preferida', type: 'select', options: [['presencial', 'Presencial'], ['online', 'En línea'], ['indistinto', 'Me es indistinto']] },
                { key: 'ciudad', label: 'Ciudad', type: 'text', autocomplete: 'address-level2' },
            ], summary: ['curso_tema', 'nivel', 'modalidad', 'ciudad'],
        },
        asesoria: { title: 'Solicita tu cotización', subtitle: 'Dinos qué necesitas y te canalizamos rápidamente.', cta: 'Solicitar cotización' },
    };

    const quoteTypes = {
        producto: {
            title: 'Producto', fields: [
                { key: 'descripcion', label: '¿Qué producto necesitas?', type: 'textarea', required: true, placeholder: 'Describe el producto que buscas' },
                { key: 'marca_modelo', label: 'Marca o modelo', type: 'text' },
                { key: 'cantidad', label: 'Cantidad', type: 'number', min: '1', placeholder: '1' },
                { key: 'urgencia', label: '¿Para cuándo lo necesitas?', type: 'select', options: urgencyOptions },
            ], summary: ['descripcion', 'marca_modelo', 'cantidad', 'urgencia'],
        },
        refaccion: {
            title: 'Refacción', fields: [
                { key: 'tipo_refaccion_ui', label: '¿Qué refacción necesitas?', type: 'text', placeholder: 'Ej. compresor, tarjeta, motor' },
                { key: 'marca_equipo_ui', label: 'Marca del equipo', type: 'text', suggestions: true },
                { key: 'modelo_equipo_ui', label: 'Modelo del equipo', type: 'text', suggestions: true },
                { key: 'numero_parte_ui', label: 'Número de parte / clave', type: 'text', suggestions: true },
                { key: 'refrigerante_ui', label: 'Refrigerante', type: 'text', suggestions: true },
                { key: 'voltaje_ui', label: 'Voltaje', type: 'text', suggestions: true },
                { key: 'cantidad', label: 'Cantidad', type: 'number', min: '1', placeholder: '1' },
                { key: 'urgencia', label: 'Urgencia', type: 'select', options: urgencyOptions },
            ], summary: ['tipo_refaccion_ui', 'marca_equipo_ui', 'modelo_equipo_ui', 'numero_parte_ui', 'refrigerante_ui', 'voltaje_ui', 'cantidad', 'urgencia'],
        },
        equipo: {
            title: 'Equipo', fields: [
                { key: 'tipo_equipo_ui', label: 'Tipo de equipo', type: 'text', required: true },
                { key: 'aplicacion_ui', label: 'Aplicación', type: 'text' },
                { key: 'capacidad_aproximada_ui', label: 'Capacidad aproximada', type: 'text' },
                { key: 'refrigerante_ui', label: 'Refrigerante', type: 'text', suggestions: true },
                { key: 'voltaje_ui', label: 'Voltaje', type: 'text', suggestions: true },
                { key: 'marca_preferida_ui', label: 'Marca preferida', type: 'text', suggestions: true },
                { key: 'cantidad', label: 'Cantidad', type: 'number', min: '1', placeholder: '1' },
                { key: 'urgencia', label: 'Urgencia', type: 'select', options: urgencyOptions },
            ], summary: ['tipo_equipo_ui', 'aplicacion_ui', 'capacidad_aproximada_ui', 'refrigerante_ui', 'voltaje_ui', 'marca_preferida_ui', 'cantidad', 'urgencia'],
        },
        servicio: {
            title: 'Servicio', fields: [
                { key: 'tipo_servicio_ui', label: 'Tipo de servicio', type: 'select', options: [['instalacion', 'Instalación'], ['mantenimiento', 'Mantenimiento'], ['reparacion', 'Reparación'], ['diagnostico', 'Diagnóstico'], ['otro', 'Otro']] },
                { key: 'equipo_relacionado_ui', label: 'Equipo relacionado', type: 'text' },
                { key: 'problema_ui', label: '¿Qué problema presenta?', type: 'textarea', required: true, placeholder: 'Describe brevemente el problema' },
                { key: 'ubicacion_ui', label: 'Ubicación', type: 'text', autocomplete: 'address-level2' },
                { key: 'urgencia', label: 'Urgencia', type: 'select', options: urgencyOptions },
            ], summary: ['tipo_servicio_ui', 'equipo_relacionado_ui', 'problema_ui', 'ubicacion_ui', 'urgencia'],
        },
        otro: {
            title: 'Otro', fields: [{ key: 'descripcion', label: 'Cuéntanos qué necesitas', type: 'textarea', required: true, placeholder: 'Describe lo que necesitas' }], summary: ['descripcion'],
        },
    };

    const labels = new Map(); let datalistIndex = 0;
    function fieldElement(config) {
        const label = document.createElement('label'); label.append(document.createTextNode(config.label)); let control;
        if (config.type === 'select') { control = document.createElement('select'); control.add(new Option('Selecciona una opción', '')); config.options.forEach(([value, text]) => control.add(new Option(text, value))); }
        else if (config.type === 'textarea') { control = document.createElement('textarea'); control.rows = 3; control.maxLength = 2000; label.classList.add('vcard-public__help-message'); }
        else { control = document.createElement('input'); control.type = config.type; if (config.min) control.min = config.min; if (config.type === 'number') control.step = '1'; }
        control.name = config.key; control.dataset.detail = config.key; control.required = config.required === true; control.autocomplete = config.autocomplete || 'off'; if (config.placeholder) control.placeholder = config.placeholder;
        if (config.suggestions) { const listId = `vcard-help-options-${++datalistIndex}`; const list = document.createElement('datalist'); list.id = listId; commonUnknown.forEach(({ label: optionLabel }) => { const option = document.createElement('option'); option.value = optionLabel; list.append(option); }); control.setAttribute('list', listId); label.append(control, list); } else label.append(control);
        labels.set(config.key, config.label); return label;
    }
    function currentDetails() { return Object.fromEntries([...fieldsHost.querySelectorAll('[data-detail]')].map((field) => [field.dataset.detail, field.value.trim()]).filter(([, value]) => value !== '')); }
    function humanValue(value) { const unknownLabels = { no_se: 'No sé', no_aplica: 'No aplica', no_estoy_seguro: 'No estoy seguro' }; return Object.hasOwn(unknownLabels, value) ? unknownLabels[value] : value.replaceAll('_', ' '); }
    function addIfPresent(target, key, value) { if (value) target[key] = value; }
    function quoteDetails(values) {
        const type = values.tipo || 'producto'; const details = { tipo: type };
        if (type === 'producto' || type === 'otro') { addIfPresent(details, 'descripcion', values.descripcion); addIfPresent(details, 'marca_modelo', values.marca_modelo); addIfPresent(details, 'cantidad', values.cantidad); addIfPresent(details, 'urgencia', values.urgencia); }
        else if (type === 'refaccion') { const parts = [['Tipo de refacción', values.tipo_refaccion_ui], ['Marca', values.marca_equipo_ui], ['Modelo', values.modelo_equipo_ui], ['Número de parte', values.numero_parte_ui], ['Refrigerante', values.refrigerante_ui], ['Voltaje', values.voltaje_ui]].filter(([, value]) => value); addIfPresent(details, 'descripcion', parts.map(([label, value]) => `${label}: ${value}`).join('; ')); addIfPresent(details, 'marca_modelo', [values.modelo_equipo_ui, values.numero_parte_ui].filter(Boolean).join(' / ')); addIfPresent(details, 'cantidad', values.cantidad); addIfPresent(details, 'urgencia', values.urgencia); }
        else if (type === 'equipo') { const parts = [['Tipo de equipo', values.tipo_equipo_ui], ['Aplicación', values.aplicacion_ui], ['Capacidad', values.capacidad_aproximada_ui], ['Refrigerante', values.refrigerante_ui], ['Voltaje', values.voltaje_ui]].filter(([, value]) => value); addIfPresent(details, 'descripcion', parts.map(([label, value]) => `${label}: ${value}`).join('; ')); addIfPresent(details, 'marca_modelo', values.marca_preferida_ui); addIfPresent(details, 'cantidad', values.cantidad); addIfPresent(details, 'urgencia', values.urgencia); }
        else if (type === 'servicio') { const parts = [['Tipo de servicio', values.tipo_servicio_ui], ['Problema', values.problema_ui], ['Ubicación', values.ubicacion_ui]].filter(([, value]) => value); addIfPresent(details, 'descripcion', parts.map(([label, value]) => `${label}: ${value}`).join('; ')); addIfPresent(details, 'marca_modelo', values.equipo_relacionado_ui); addIfPresent(details, 'urgencia', values.urgencia); }
        return details;
    }
    function refreshSummary() {
        const service = serviceInput.value; const rawDetails = currentDetails(); const details = service === 'asesoria' ? quoteDetails(rawDetails) : rawDetails; detailsInput.value = JSON.stringify(details);
        const config = serviceConfig[service]; const summaryConfig = service === 'asesoria' ? quoteTypes[rawDetails.tipo || 'producto'] : config; const chips = [];
        if (service === 'asesoria' && rawDetails.tipo) chips.push((summaryConfig?.title || rawDetails.tipo).toLocaleUpperCase('es-MX'));
        (summaryConfig?.summary || []).forEach((key) => { const raw = rawDetails[key]; if (!raw) return; let value = humanValue(raw); if (key === 'cantidad') value += Number(raw) === 1 ? ' pieza' : ' piezas'; chips.push(value.toLocaleUpperCase('es-MX')); });
        chipsHost.replaceChildren(); if (!chips.length) { const empty = document.createElement('span'); empty.className = 'vcard-public__help-empty'; empty.textContent = 'Completa los datos para ver el resumen.'; chipsHost.append(empty); } else chips.forEach((text) => { const chip = document.createElement('span'); chip.className = 'vcard-public__help-chip'; chip.textContent = text; chipsHost.append(chip); });
        const description = details.descripcion || details.curso_tema || ''; const summaryText = Object.entries(details).map(([key, value]) => `${labels.get(key) || key}: ${humanValue(String(value))}`).join('; '); messageInput.value = description || (summaryText ? `Solicitud ${config?.title || 'comercial'}: ${summaryText}` : '');
    }
    function renderQuoteFields(type) {
        const config = quoteTypes[type] || quoteTypes.producto; fieldsHost.replaceChildren(fieldElement({ key: 'tipo', label: '¿Qué deseas cotizar?', type: 'select', required: true, options: [['producto', 'Producto'], ['refaccion', 'Refacción'], ['equipo', 'Equipo'], ['servicio', 'Servicio'], ['otro', 'Otro']] })); const typeControl = fieldsHost.querySelector('[data-detail="tipo"]'); typeControl.value = type; config.fields.forEach((field) => fieldsHost.append(fieldElement(field))); typeControl.addEventListener('change', () => { renderQuoteFields(typeControl.value || 'producto'); refreshSummary(); });
    }
    function selectService(service, focusFields = false) {
        const requested = service === 'refaccion' ? 'asesoria' : service; const selected = shortcuts.find((shortcut) => shortcut.dataset.service === requested); const activeService = selected ? requested : ''; const config = serviceConfig[activeService]; form.hidden = activeService === ''; serviceInput.value = activeService; fieldsHost.replaceChildren(); labels.clear(); if (activeService === 'asesoria') renderQuoteFields('producto'); else if (config) config.fields.forEach((field) => fieldsHost.append(fieldElement(field))); topic.textContent = config?.title || 'Cuéntanos qué necesitas'; subtitle.textContent = config?.subtitle || 'Con algunos datos podemos canalizarte con el área adecuada.'; if (submit) submit.textContent = config?.cta || 'Enviar solicitud'; detailsInput.value = '{}'; messageInput.value = ''; if (status) status.textContent = ''; shortcuts.forEach((shortcut) => { const active = shortcut === selected; shortcut.classList.toggle('is-active', active); shortcut.setAttribute('aria-selected', String(active)); shortcut.setAttribute('aria-expanded', String(active)); }); refreshSummary(); if (focusFields) fieldsHost.querySelector('input, select, textarea')?.focus();
    }
    const shortcuts = [...section.querySelectorAll('.vcard-public__help-shortcut[data-service]')];
    shortcuts.forEach((shortcut, index) => { shortcut.addEventListener('click', (event) => { event.preventDefault(); const wasActive = shortcut.getAttribute('aria-selected') === 'true'; selectService(wasActive ? '' : shortcut.dataset.service); }); shortcut.addEventListener('keydown', (event) => { if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return; event.preventDefault(); const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? shortcuts.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : shortcuts.length - 1)) % shortcuts.length; shortcuts[nextIndex].focus(); }); });
    fieldsHost.addEventListener('input', refreshSummary); fieldsHost.addEventListener('change', refreshSummary); const initialService = serviceInput.value || shortcuts.find((item) => item.getAttribute('aria-selected') === 'true')?.dataset.service || 'asesoria'; selectService(initialService);
    form.addEventListener('submit', (event) => { refreshSummary(); const service = serviceInput.value; const rawDetails = currentDetails(); const details = service === 'asesoria' ? quoteDetails(rawDetails) : rawDetails; if (service === 'refaccion' && Object.keys(details).length === 0) { event.preventDefault(); if (status) status.textContent = 'Agrega al menos un dato de la refacción; puedes escribir “No sé” si lo prefieres.'; fieldsHost.querySelector('input, select, textarea')?.focus(); return; } if (service === 'asesoria' && rawDetails.tipo === 'refaccion' && !details.descripcion && !details.marca_modelo) { event.preventDefault(); if (status) status.textContent = 'Agrega al menos un dato de la refacción; puedes escribir “No sé” si lo prefieres.'; fieldsHost.querySelector('input, select, textarea')?.focus(); return; } if (!form.reportValidity()) { event.preventDefault(); if (status) status.textContent = 'Revisa los campos marcados para continuar.'; return; } if (form.dataset.enabled !== 'true') { event.preventDefault(); if (status) status.textContent = 'El envío todavía no está activado.'; return; } form.setAttribute('aria-busy', 'true'); if (submit) { submit.disabled = true; submit.textContent = 'Enviando solicitud…'; } if (status) status.textContent = 'Enviando tu solicitud…'; });
})();
