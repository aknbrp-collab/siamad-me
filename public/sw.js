// SIAKAD service worker: shows Web Push pop-ups and opens their link.
// It caches nothing; SIAKAD needs the server for every page.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(self.registration.showNotification(data.title || 'SIAKAD', {
        body: data.body || '',
        icon: '/brand/icon-192',
        badge: '/brand/icon-192',
        // Same tag (e.g. one forum thread): the new pop-up replaces the old one.
        tag: data.tag || undefined,
        renotify: Boolean(data.tag),
        data: { url: data.url || '/notifications' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/notifications', self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const open = windows.find((client) => new URL(client.url).origin === self.location.origin);

        if (open) {
            await open.focus();
            return open.navigate(url);
        }

        return self.clients.openWindow(url);
    })());
});
