'use strict';

(() => {
    const popover = document.querySelector('[data-notification-popover]');
    if (!popover) return;

    const trigger = popover.querySelector('[data-notification-trigger]');
    const panel = popover.querySelector('[data-notification-panel]');
    if (!trigger || !panel) return;

    const close = (returnFocus = false) => {
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        if (returnFocus) trigger.focus();
    };

    trigger.addEventListener('click', () => {
        const opening = panel.hidden;
        panel.hidden = !opening;
        trigger.setAttribute('aria-expanded', opening ? 'true' : 'false');
    });

    document.addEventListener('click', (event) => {
        if (!popover.contains(event.target)) close();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) close(true);
    });

    panel.addEventListener('click', (event) => {
        if (event.target.closest('a, button')) close();
    });
})();