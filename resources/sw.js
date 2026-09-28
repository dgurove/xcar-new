// Service worker XCar. Отдаётся через /sw.js (PwaController::worker): VERSION
// подставляется из хэша сборки, так что после каждой выкладки кэш свежий сам.
// HTML — только из сети (страницы живые), при обрыве — /offline; запрос HTML
// уходит параллельно старту воркера (navigation preload). Сборка, шрифт и
// картинки — из кэша. Картинки — только сжатые версии (/hot/: w320…w960, ~30–80 КБ), до 300 штук
// (~15 МБ): у iOS потолок около 50. Оригиналы (/media/, ~200–400 КБ) не храним — их открывают редко
// (увеличение, «Скачать»), хватает HTTP-кэша браузера. При установке качаем только сам app.js, app.css,
// шрифт и /offline: сканер QR (1 МБ), редактор и прочие куски лягут в кэш при первом использовании.
// Кэш — только ускорение: не записался (место кончилось) — ответ всё равно из
// сети, а кэш картинок сбрасывается. Раньше отказ записи ронял сам ответ: у
// телефона с полным хранилищем не грузились стили и новые фото.
const VERSION = '__VERSION__';
const STATIC = `static-${VERSION}`;
const MEDIA = `media-${VERSION}`;
const PAGES = `pages-${VERSION}`;
const MEDIA_LIMIT = 300;
const PAGES_LIMIT = 30;
// Экраны, которые нельзя показывать из кэша: вход, выход, служебное.
const NO_PAGE_CACHE = /^\/(login|logout|register|password|passkey|i|offline|dev|live|up)(\/|$)/;

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(STATIC);
        let assets = [];
        try {
            const manifest = await (await fetch('/build/manifest.json', { cache: 'no-cache' })).json();
            // Только точки входа (app.js, app.css) и их css — не каждый кусок сборки.
            assets = Object.values(manifest).filter((e) => e.isEntry).flatMap((e) => [e.file, ...(e.css || [])]).map((f) => '/build/' + f);
        } catch {}
        const precache = () => cache.addAll(['/offline', '/fonts/onest-var.woff2', ...assets]);
        // Места нет — освобождаем картинки и экраны прошлых версий и пробуем ещё раз; не вышло — ставимся без
        // запаса: иначе остался бы старый воркер, у которого и ломается загрузка.
        try { await precache(); } catch {
            await dropHeavy();
            try { await precache(); } catch {}
        }
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => ![STATIC, MEDIA, PAGES].includes(k)).map((k) => caches.delete(k)));
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
    if (url.pathname.startsWith('/hot/')) {
        event.respondWith(cacheFirst(MEDIA, request, MEDIA_LIMIT));
        return;
    }
    // Запуск с иконки: последний экран из кэша сразу, свежий — следом (страница сама
    // перечитывает себя морфом, если старше 5 с — app.js, meta rendered-at).
    if (request.mode === 'navigate') {
        event.respondWith((async () => {
            const cacheable = !NO_PAGE_CACHE.test(url.pathname);
            const network = (async () => {
                const response = (await event.preloadResponse) || (await fetch(request));
                if (response.redirected && /\/login(\/|$|\?)/.test(new URL(response.url).pathname)) await caches.delete(PAGES);
                else if (cacheable && response.ok && (response.headers.get('Content-Type') || '').includes('text/html')) {
                    // Не записалось — страница всё равно показывается из сети, а не «нет связи».
                    try {
                        const cache = await caches.open(PAGES);
                        await cache.put(request, response.clone());
                        await trim(cache, PAGES_LIMIT);
                    } catch { await dropHeavy(); }
                }
                return response;
            })();
            const cached = cacheable && await caches.match(request);
            if (cached) { event.waitUntil(network.catch(() => {})); return cached; }
            try { return await network; } catch { return caches.match('/offline'); }
        })());
    }
});

async function cacheFirst(name, request, limit) {
    let cache = null;
    try {
        cache = await caches.open(name);
        const hit = await cache.match(request);
        if (hit) return hit;
    } catch {}
    const response = await fetch(request);
    if (cache && response.ok) {
        try {
            await cache.put(request, response.clone());
            if (limit) await trim(cache, limit);
        } catch {
            await dropHeavy();
        }
    }
    return response;
}

// Картинки и экраны всех версий — то, что можно выбросить, когда хранилище полно.
async function dropHeavy() {
    try {
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => k.startsWith('media-') || k.startsWith('pages-')).map((k) => caches.delete(k)));
    } catch {}
}

async function trim(cache, limit) {
    const keys = await cache.keys();
    if (keys.length > limit) await Promise.all(keys.slice(0, keys.length - limit).map((k) => cache.delete(k)));
}

// Пуш. Основной формат — Declarative Web Push (iOS 18.4+ показывает без
// воркера); здесь тот же JSON разбирается для Android.
// Поверхность по хосту service worker: иконка уведомления — своя у каждого приложения.
const surface = () => ['crm', 'park', 'garage'].find((name) => location.hostname.startsWith(`${name}.`)) ?? 'site';

// Страница просит забыть экраны: выход.
self.addEventListener('message', (event) => {
    if (event.data?.forgetPages) event.waitUntil(caches.delete(PAGES));
});

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
