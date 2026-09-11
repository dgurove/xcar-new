// Service worker XCar. HTML — только из сети (страницы живые), при обрыве
// — /offline. Сборка и картинки — из кэша, картинок не больше ~40 МБ:
// у iOS потолок около 50.
const VERSION = 'v1';
const STATIC = `static-${VERSION}`;
const MEDIA = `media-${VERSION}`;
const MEDIA_LIMIT = 400;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(STATIC).then((c) => c.addAll(['/offline'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => ![STATIC, MEDIA].includes(k)).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== location.origin) return;

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/pwa/') || url.pathname.startsWith('/images/')) {
        event.respondWith(cacheFirst(STATIC, request));
        return;
    }
    if (url.pathname.startsWith('/media/')) {
        event.respondWith(cacheFirst(MEDIA, request, MEDIA_LIMIT));
        return;
    }
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/offline')));
    }
});

async function cacheFirst(name, request, limit) {
    const cache = await caches.open(name);
    const hit = await cache.match(request);
    if (hit) return hit;
    const response = await fetch(request);
    if (response.ok) {
        cache.put(request, response.clone());
        if (limit) trim(cache, limit);
    }
    return response;
}

async function trim(cache, limit) {
    const keys = await cache.keys();
    if (keys.length > limit) await Promise.all(keys.slice(0, keys.length - limit).map((k) => cache.delete(k)));
}

// Пуш. Основной формат — Declarative Web Push (iOS 18.4+ показывает без
// воркера); здесь тот же JSON разбирается для Android.
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data?.json() ?? {}; } catch { data = { notification: { title: event.data?.text() || 'XCar' } }; }
    const n = data.notification || data;
    if (n.app_badge !== undefined && navigator.setAppBadge) navigator.setAppBadge(n.app_badge);
    event.waitUntil(self.registration.showNotification(n.title || 'XCar', {
        body: n.body || '',
        icon: '/pwa/icon-192.png',
        badge: '/pwa/icon-192.png',
        tag: n.tag,
        data: { navigate: n.navigate || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.navigate || '/', location.origin).href;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        const open = list.find((c) => 'focus' in c);
        if (open) { open.navigate(target); return open.focus(); }
        return self.clients.openWindow(target);
    }));
});
