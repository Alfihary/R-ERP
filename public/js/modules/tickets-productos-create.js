(function () {
    'use strict';

    const ready = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }

        callback();
    };

    const createOption = (value, text) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = text;

        return option;
    };

    const normalizeWarehouse = (item) => {
        if (item === null || typeof item !== 'object') {
            return null;
        }

        const companyId = String(item.empresa_id ?? '').trim();
        const warehouseId = String(item.almacen_id ?? '').trim();
        const code = String(item.codigo ?? '').trim();
        const name = String(item.nombre ?? '').trim();

        if (companyId === '' || warehouseId === '') {
            return null;
        }

        return {
            empresa_id: companyId,
            almacen_id: warehouseId,
            codigo: code,
            nombre: name,
        };
    };

    const readWarehouses = (dataElement) => {
        if (!(dataElement instanceof HTMLScriptElement)) {
            return [];
        }

        try {
            const parsed = JSON.parse(dataElement.textContent || '[]');

            if (!Array.isArray(parsed)) {
                return [];
            }

            return parsed
                .map(normalizeWarehouse)
                .filter((item) => item !== null);
        } catch (error) {
            return [];
        }
    };

    ready(() => {
        const company = document.querySelector('[data-company-select]');
        const warehouse = document.querySelector('[data-warehouse-select]');
        const message = document.querySelector('[data-warehouse-message]');
        const data = document.getElementById('ticket-products-warehouses-data');

        if (!(company instanceof HTMLSelectElement)
            || !(warehouse instanceof HTMLSelectElement)
            || !(message instanceof HTMLElement)
        ) {
            return;
        }

        const warehouses = readWarehouses(data);

        const refreshWarehouses = () => {
            const selectedCompanyId = String(company.value || '').trim();
            const selectedWarehouseId = String(warehouse.dataset.selectedWarehouse || warehouse.value || '').trim();

            warehouse.replaceChildren(createOption('', 'Selecciona un almacén'));

            if (selectedCompanyId === '') {
                warehouse.value = '';
                warehouse.dataset.selectedWarehouse = '';
                warehouse.disabled = true;
                message.textContent = 'Selecciona una empresa primero.';
                return;
            }

            const available = warehouses.filter((item) => item.empresa_id === selectedCompanyId);

            if (available.length === 0) {
                const empty = createOption('', 'Sin almacenes asignados para esta empresa');
                empty.disabled = true;
                warehouse.append(empty);
                warehouse.value = '';
                warehouse.dataset.selectedWarehouse = '';
                warehouse.disabled = true;
                message.textContent = 'No tienes almacenes asignados para esta empresa.';
                return;
            }

            warehouse.disabled = false;
            message.textContent = 'El listado se limita a la empresa seleccionada.';

            available.forEach((item) => {
                const option = createOption(item.almacen_id, `${item.codigo} · ${item.nombre}`);

                if (item.almacen_id === selectedWarehouseId) {
                    option.selected = true;
                }

                warehouse.append(option);
            });

            if (!available.some((item) => item.almacen_id === warehouse.value)) {
                warehouse.value = '';
            }

            warehouse.dataset.selectedWarehouse = warehouse.value;
        };

        company.addEventListener('change', () => {
            warehouse.dataset.selectedWarehouse = '';
            refreshWarehouses();
        });

        refreshWarehouses();
    });
}());
