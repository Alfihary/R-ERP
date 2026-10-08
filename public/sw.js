'use strict';

const CACHE = 'gr-public-v8';

const PUBLIC_FILES = [
    '/offline.html',
    '/css/modules/offline.css',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/maskable-512.png',
    '/icons/apple-touch-icon.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then(async (cache) => {
                await Promise.all(
                    PUBLIC_FILES.map(async (url) => {
                        try {
                            await cache.add(url);
                        } catch (error) {
                            console.warn('No se pudo cachear:', url);
                        }
                    })
                );
            })
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) =>
                        key.startsWith('gr-public-') &&
                        key !== CACHE
                    )
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET') {
        return;
    }

    if (url.origin !== self.location.origin) {
        return;
    }

    // Las páginas del ERP siempre se solicitan al servidor.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches.match('/offline.html')
            )
        );
        return;
    }

    // Solo los archivos públicos de la lista usan caché.
    if (PUBLIC_FILES.includes(url.pathname) && !url.search) {
        event.respondWith(
            caches.match(request).then((cached) => {
                return cached || fetch(request);
            })
        );
    }
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (error) {
        data = {};
    }
    const title = typeof data.title === 'string' && data.title.trim() !== ''
        ? data.title.slice(0, 120)
        : 'SoporteGR ERP';
    const body = typeof data.body === 'string' && data.body.trim() !== ''
        ? data.body.slice(0, 180)
        : 'Tienes una nueva notificación asignada.';
    const actionUrl = typeof data.action_url === 'string'
        && data.action_url.startsWith('/')
        && !data.action_url.startsWith('//')
        && !data.action_url.includes('\\')
        ? data.action_url
        : '/app#centro-notificaciones';
    event.waitUntil(Promise.all([
        self.registration.showNotification(title, {
            body,
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            data: { action_url: actionUrl }
        }),
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => Promise.all(
            clients.map((client) => client.postMessage({ type: 'NOTIFICATION_CREATED' }))
        ))
    ]));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = event.notification?.data?.action_url;
    const actionUrl = typeof target === 'string'
        && target.startsWith('/')
        && !target.startsWith('//')
        && !target.includes('\\')
        ? target
        : '/app#centro-notificaciones';
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
        for (const client of clients) {
            if ('focus' in client) {
                return client.focus().then(() => client.navigate(actionUrl));
            }
        }
        return self.clients.openWindow(actionUrl);
    }));
});
