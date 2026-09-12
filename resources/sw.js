// Service worker XCar. Отдаётся через /sw.js (PwaController::worker): VERSION
// подставляется из хэша сборки, так что после каждой выкладки кэш свежий сам.
// HTML — только из сети (страницы живые), при обрыве — /offline; запрос HTML
// уходит параллельно старту воркера (navigation preload). Сборка, шрифт и
// картинки — из кэша, картинок не больше ~40 МБ: у iOS потолок около 50.
const VERSION = '__VERSION__';
const STATIC = `static-${VERSION}`;
const MEDIA = `media-${VERSION}`;
const MEDIA_LIMIT = 400;

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(STATIC);
        let assets = [];
        try {
            const manifest = await (await fetch('/build/manifest.json', { cache: 'no-cache' })).json();
            assets = Object.values(manifest).map((e) => '/build/' + e.file);
        } catch {}
        await cache.addAll(['/offline', '/fonts/onest-var.woff2', ...assets]);
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => ![STATIC, MEDIA].includes(k)).map((k) => caches.delete(k)));
        await self.registration.navigationPreload?.enable();
        await self.clients.claim();
    })());
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
        event.respondWith((async () => {
            try { return (await event.preloadResponse) || (await fetch(request)); } catch { return caches.match('/offline'); }
        })());
    }
});

async function cacheFirst(name, request, limit) {
    const cache = await caches.open(name);
    const hit = await cache.match(request);
    if (hit) return hit;
    const response = await fetch(request);
    if (response.ok) {
        await cache.put(request, response.clone());
        if (limit) await trim(cache, limit);
    }
    return response;
}

async function trim(cache, limit) {
    const keys = await cache.keys();
    if (keys.length > limit) await Promise.all(keys.slice(0, keys.length - limit).map((k) => cache.delete(k)));
}

// Пуш. Основной формат — Declarative Web Push (iOS 18.4+ показывает без
// воркера); здесь тот же JSON разбирается для Android.
// Поверхность по хосту service worker: иконка уведомления — своя у сайта, CRM и стоянки.
const surface = () => (location.hostname.startsWith('crm.') ? 'crm' : location.hostname.startsWith('park.') ? 'park' : 'site');

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data?.json() ?? {}; } catch { data = { notification: { title: event.data?.text() || 'XCar' } }; }
    const n = data.notification || data;
    if (n.app_badge !== undefined && navigator.setAppBadge) navigator.setAppBadge(n.app_badge);
    event.waitUntil(self.registration.showNotification(n.title || 'XCar', {
        body: n.body || '',
        icon: `/pwa/${surface()}/icon-192.png`,
        badge: `/pwa/${surface()}/icon-mono-512.png`, // Android рисует бейдж силуэтом
        tag: n.tag,
        data: { navigate: n.navigate || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.navigate || '/', location.origin).href;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        const open = list.find((c) => 'focus' in c);
        // Открытое окно переходит само (Turbo.visit в pwa_controller) — без перезагрузки и без разрыва live.
        if (open) { open.postMessage({ navigate: target }); return open.focus(); }
        return self.clients.openWindow(target);
    }));
});
