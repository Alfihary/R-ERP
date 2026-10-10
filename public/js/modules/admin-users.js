(function () {
    'use strict';

    const company = document.querySelector('[name="company_id"]');
    const warehouse = document.querySelector('[name="warehouse_id"]');
    if (!company || !warehouse) return;

    const sync = () => {
        const companyId = company.value;
        Array.from(warehouse.options).forEach((option) => {
            if (!option.value) return;
            const visible = !companyId || option.dataset.companyId === companyId;
            option.hidden = !visible;
            if (!visible && option.selected) warehouse.value = '';
        });
    };
    company.addEventListener('change', sync);
    sync();
}());
