/*
 * Maestro Touch Legal service worker: browser push notifications only (D49).
 *
 * It deliberately does not intercept page loads or cache anything. Client and matter pages must never be
 * served from a cache on a shared computer, and a worker with nothing to cache cannot fail to install,
 * which is what keeps the "Alerts" button working.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (_) {}

    event.waitUntil(self.registration.showNotification(data.title || 'Maestro Touch Legal', {
        body: data.body || 'You have a new notification.',
        icon: data.icon || '/images/2025/08/blue-1.png',
        badge: data.icon || '/images/2025/08/blue-1.png',
        tag: data.tag || undefined,
        data: { url: data.url || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    let target = '/';
    try {
        // Only ever open pages on this site.
        const url = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin);
        if (url.origin === self.location.origin) target = url.href;
    } catch (_) {}

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        for (const client of list) {
            if (client.url === target && 'focus' in client) return client.focus();
        }
        return self.clients.openWindow(target);
    }));
});
