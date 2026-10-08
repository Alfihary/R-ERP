'use strict';

(() => {
    const body = document.body;
    const enableButton = document.querySelector('[data-push-enable]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const publicKey = body?.dataset.pushPublicKey || '';
    let pollTimer = null;
    let pushActive = false;
    const pushDebug = (event, details = {}) => {
        console.info(`[PushDebug] ${event}`, details);
    };

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    }[char]));

    const base64ToBytes = (value) => {
        const padding = '='.repeat((4 - (value.length % 4)) % 4);
        const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = window.atob(base64);
        return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
    };

    const safeAction = (url) => {
        if (typeof url !== 'string' || !url.startsWith('/') || url.startsWith('//') || url.includes('\\')) {
            return '';
        }
        return url;
    };

    const isUsefulAction = (url) => {
        if (!url) return false;
        try {
            const target = new URL(url, window.location.origin);
            const currentCenter = window.location.pathname === '/app'
                && target.pathname === '/app'
                && target.hash === '#centro-notificaciones';
            return !currentCenter;
        } catch (error) {
            return false;
        }
    };

    const renderItem = (item, compact = false) => {
        const title = escapeHtml(item.titulo || 'Notificación');
        const message = escapeHtml(item.mensaje || '');
        const unread = item.leida_at === null || typeof item.leida_at === 'undefined';
        const id = Number(item.id || 0);
        const action = safeAction(item.accion_url);
        const usefulAction = isUsefulAction(action);
        if (compact) {
            const actionMarkup = unread && id > 0
                ? `<form method="post" action="/app/notificaciones/${id}/leer"><input type="hidden" name="_token" value="${escapeHtml(csrf)}"><button class="notification-popover__action" type="submit">${usefulAction ? 'Ver' : 'Leer'}</button></form>`
                : (usefulAction ? `<a class="notification-popover__action" href="${escapeHtml(action)}">Ver</a>` : '');
            return `<li class="notification-popover__item${unread ? ' is-unread' : ''}"><span class="notification-popover__dot" aria-hidden="true"></span><div class="notification-popover__copy"><strong>${title}</strong><span>${escapeHtml(item.created_at || '')}</span></div>${actionMarkup}</li>`;
        }
        const actionMarkup = unread && id > 0
            ? `<form method="post" action="/app/notificaciones/${id}/leer"><input type="hidden" name="_token" value="${escapeHtml(csrf)}"><button class="button button--secondary notification-item__action" type="submit">${usefulAction ? 'Ver' : 'Marcar leída'}</button></form>`
            : (usefulAction ? `<a class="button button--secondary notification-item__action" href="${escapeHtml(action)}">Ver</a>` : `<span class="notification-item__read-label">Leída</span>`);
        return `<li class="notification-item${unread ? ' is-unread' : ' is-read'}" data-notification-id="${id}"><span class="notification-item__icon" aria-hidden="true">🔔</span><div class="notification-item__copy"><div class="notification-item__title-row"><h3>${title}</h3></div><p>${message}</p><div class="notification-item__meta"><time>${escapeHtml(item.created_at || '')}</time><span class="notification-role">${escapeHtml(item.rol_codigo || '')}</span></div></div><div class="notification-item__actions">${actionMarkup}</div></li>`;
    };

    const updateUi = (data) => {
        const unread = Math.max(0, Number(data.unread_count || 0));
        const today = Math.max(0, Number(data.nuevas_hoy || 0));
        document.querySelectorAll('[data-notifications-unread]').forEach((el) => { el.textContent = String(unread); });
        document.querySelectorAll('[data-notifications-today]').forEach((el) => { el.textContent = String(today); });
        document.querySelectorAll('[data-notifications-count]').forEach((el) => {
            el.textContent = `${unread} sin leer`;
            el.setAttribute('aria-label', `${unread} sin leer`);
        });
        const bell = document.querySelector('[data-notification-trigger]');
        if (bell) {
            bell.setAttribute('aria-label', unread > 0 ? `Notificaciones, ${unread} sin leer` : 'Notificaciones');
            let badge = bell.querySelector('[data-notification-bell-count]');
            if (unread > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'notification-bell__count';
                    badge.setAttribute('aria-hidden', 'true');
                    badge.dataset.notificationBellCount = '';
                    bell.appendChild(badge);
                }
                badge.textContent = String(unread);
            } else if (badge) {
                badge.remove();
            }
        }
        const popoverCount = document.querySelector('[data-notification-popover-count]');
        if (popoverCount) {
            popoverCount.textContent = `${unread} sin leer`;
            popoverCount.hidden = unread === 0;
        }
        const items = Array.isArray(data.notificaciones) ? data.notificaciones : [];
        const compactList = document.querySelector('[data-notification-popover-list]');
        if (compactList) compactList.innerHTML = items.map((item) => renderItem(item, true)).join('');
        const fullList = document.querySelector('[data-notification-list]');
        if (fullList) {
            const known = new Set([...fullList.querySelectorAll('[data-notification-id]')].map((el) => el.dataset.notificationId));
            items.slice().reverse().forEach((item) => {
                const id = String(item.id || '');
                if (id !== '' && !known.has(id)) fullList.insertAdjacentHTML('afterbegin', renderItem(item));
            });
        } else if (items.length > 0) {
            const empty = document.querySelector('[data-notification-empty]');
            if (empty) {
                const list = document.createElement('ul');
                list.className = 'notification-list';
                list.dataset.notificationList = '';
                list.innerHTML = items.map((item) => renderItem(item)).join('');
                empty.replaceWith(list);
            }
        }
    };

    const refresh = async () => {
        try {
            const response = await fetch('/api/notificaciones/estado', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            if (data && data.ok === true) updateUi(data);
        } catch (error) {
            // El Centro renderizado en servidor permanece disponible si falla la actualización.
        }
    };

    const schedule = () => {
        if (pollTimer) window.clearInterval(pollTimer);
        if (document.hidden) return;
        pollTimer = window.setInterval(refresh, pushActive ? 60000 : 12000);
    };

    const subscriptionPayload = (subscription) => {
        const serialized = subscription?.toJSON?.() || {};
        return {
            endpoint: serialized.endpoint || subscription?.endpoint || '',
            keys: {
                p256dh: serialized.keys?.p256dh || '',
                auth: serialized.keys?.auth || '',
            },
        };
    };

    const syncSubscription = async (subscription) => {
        const response = await fetch('/api/notificaciones/push/suscripcion', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, Accept: 'application/json' },
            body: JSON.stringify(subscriptionPayload(subscription)),
        });
        pushDebug('api-response-status', { status: response.status, contentType: response.headers.get('content-type') || '' });
        if (!response.ok) throw new Error('subscription_failed');
        return response;
    };

    const markPushActive = () => {
        pushActive = true;
        if (enableButton) {
            enableButton.textContent = 'Notificaciones activadas';
            enableButton.dataset.pushState = 'active';
            enableButton.disabled = true;
        }
        schedule();
    };

    const markPushSyncFailed = () => {
        pushActive = false;
        if (enableButton) {
            enableButton.textContent = 'No se pudo registrar este dispositivo';
            enableButton.dataset.pushState = 'error';
            enableButton.disabled = false;
        }
        schedule();
    };

    const activatePush = async () => {
        if (!enableButton || !publicKey || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
        let phase = 'permission';
        try {
            const permission = await Notification.requestPermission();
            pushDebug('permission', { value: permission });
            if (permission !== 'granted') {
                pushDebug('permission-denied', { value: permission });
                enableButton.textContent = 'Notificaciones bloqueadas';
                enableButton.dataset.pushState = 'denied';
                return;
            }
            phase = 'service-worker-ready';
            const registration = await navigator.serviceWorker.ready;
            pushDebug('service-worker-ready', {
                scope: registration.scope,
                state: registration.active?.state || 'unknown',
                controlled: Boolean(navigator.serviceWorker.controller),
            });
            phase = 'subscription';
            let subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                phase = 'subscribe';
                pushDebug('subscribe-start');
                subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64ToBytes(publicKey) });
                pushDebug('subscribe-success', {
                    hasEndpoint: typeof subscription.endpoint === 'string' && subscription.endpoint !== '',
                    hasP256dh: Boolean(subscription.toJSON()?.keys?.p256dh),
                    hasAuth: Boolean(subscription.toJSON()?.keys?.auth),
                });
            } else {
                pushDebug('subscription-existing');
            }
            phase = 'api-request';
            pushDebug('api-request-start');
            await syncSubscription(subscription);
            markPushActive();
            pushDebug('completed');
        } catch (error) {
            pushDebug(phase === 'api-request' ? 'api-error' : phase === 'subscribe' ? 'subscribe-error' : 'api-error', {
                name: error?.name || 'Error',
                message: String(error?.message || 'unknown').slice(0, 160),
            });
            markPushSyncFailed();
        }
    };
    if (enableButton) {
        const supported = publicKey && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        if (supported && Notification.permission !== 'denied') {
            enableButton.hidden = false;
            enableButton.addEventListener('click', activatePush);
            navigator.serviceWorker.ready
                .then((registration) => registration.pushManager.getSubscription())
                .then(async (subscription) => {
                    if (!subscription) return;
                    pushDebug('subscription-existing');
                    try {
                        await syncSubscription(subscription);
                        markPushActive();
                    } catch (error) {
                        pushDebug('api-error', {
                            name: error?.name || 'Error',
                            message: String(error?.message || 'unknown').slice(0, 160),
                        });
                        markPushSyncFailed();
                    }
                })
                .catch(() => {});
        }
    }
    navigator.serviceWorker?.addEventListener('message', (event) => {
        if (event.data?.type === 'NOTIFICATION_CREATED') refresh();
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { refresh(); schedule(); } else if (pollTimer) window.clearInterval(pollTimer); });
    refresh();
    schedule();
})();
