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
// Принудительный сброс у всех (07.10.2026, владелец: «приложение бесконечно обновляется — сделай форс апдейт»; ещё раз
// 08.10.2026 и 11.10.2026: у начальника не видно изменений): сменили метку (`PwaController::RESET`) — новая версия воркера
// стирает все кэши — сборку, экраны и картинки, и открытые окна перезагружаются один раз; сервер разово шлёт
// `Clear-Site-Data: "cache"` (`ResolveSurface`) — браузер выбрасывает и свой HTTP-кэш.
const RESET = '__RESET__';
const STATIC = `static-${VERSION}-${RESET}`;
const MEDIA = `media-${VERSION}-${RESET}`;
const PAGES = `pages-${VERSION}-${RESET}`;
const MEDIA_LIMIT = 300;
const PAGES_LIMIT = 30;
// Экраны, которые нельзя показывать из кэша: вход, выход, служебное.
// Корень — лендинг гостя: вошедшему он редирект в список, из кэша его показывать нельзя.
const NO_PAGE_CACHE = /^\/($|(login|logout|register|password|passkey|i|offline|dev|live|up)(\/|$))/;

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

// Новая версия после выкладки (был кэш прошлой сборки) — открытые окна обновляются сами (04.10.2026, владелец: «у них
// всё тот же ярлык»): ярлык на iPhone живёт в памяти днями, а старый код страницы ждал бы полного перехода. Когда — решает
// окно (pwa_controller): сразу, если человек ничего не делает, иначе следующим переходом (16.10.2026: navigate() у всех
// окон перезагружал страницу под пальцем — с открытой шторкой и набранным).
self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        const update = keys.some((k) => (k.startsWith('static-') && k !== STATIC) || (k.startsWith('pages-') && k !== PAGES));
        await Promise.all(keys.filter((k) => ![STATIC, MEDIA, PAGES].includes(k)).map((k) => caches.delete(k)));
        await self.registration.navigationPreload?.enable();
        await self.clients.claim();
        if (!update) return;
        for (const client of await self.clients.matchAll({ type: 'window' })) client.postMessage({ reload: true });
    })());
});

// Свежие ответы на запуск с иконки — по адресу, несколько секунд (см. fetch ниже).
const recent = new Map();

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== location.origin) return;

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/') || url.pathname.startsWith('/pwa/') || url.pathname.startsWith('/images/')) {
        event.respondWith(cacheFirst(event, STATIC, request));
        return;
    }
    if (url.pathname.startsWith('/hot/')) {
        event.respondWith(cacheFirst(event, MEDIA, request, MEDIA_LIMIT));
        return;
    }
    // Экран показан из кэша, свежий уже пришёл следом — тихий replace страницы (app.js stalePage) получает его, а не
    // рисует ту же страницу на сервере второй раз.
    const fresh = request.mode !== 'navigate' && !request.headers.get('Turbo-Frame') && (request.headers.get('Accept') || '').includes('text/html') && recent.get(url.href);
    if (fresh && Date.now() - fresh.at < 10_000) {
        recent.delete(url.href);
        event.respondWith(fresh.response);
        return;
    }
    // Запуск с иконки: последний экран из кэша сразу, свежий — следом (страница сама
    // перечитывает себя морфом, если старше 5 с — app.js, meta rendered-at).
    if (request.mode === 'navigate') {
        event.respondWith((async () => {
            const cacheable = !NO_PAGE_CACHE.test(url.pathname);
            const network = (async () => {
                const response = (await event.preloadResponse) || (await fetch(request));
                if (response.redirected && /\/login(\/|$|\?)/.test(new URL(response.url).pathname)) later(event, caches.delete(PAGES));
                // `no-store` — экраны менеджера под замком (DeviceGate): с ценами в кэш воркера не ложатся.
                else if (cacheable && response.ok && (response.headers.get('Content-Type') || '').includes('text/html') && !/no-store/.test(response.headers.get('Cache-Control') || '')) {
                    // Страница отдаётся браузеру сразу, запись в кэш — следом: иначе <head> со стилями ждал бы диска.
                    // Не записалось — страница всё равно показана из сети, а не «нет связи».
                    later(event, store(PAGES, request, response.clone(), PAGES_LIMIT));
                }
                return response;
            })();
            const cached = cacheable && await (await caches.open(PAGES)).match(request);
            // Экран менеджера, которого касается замок (X-Lockable, DeviceGate): сначала сеть, кэш — только если она не
            // успела за 0,7 с. Включили замок — сервер отвечает окном подтверждения, и экран с ценами из кэша не мелькает.
            if (cached && cached.headers.get('X-Lockable')) {
                const quick = await Promise.race([network.catch(() => null), new Promise((r) => setTimeout(r, 700))]);
                if (quick) return quick;
            }
            if (cached) {
                later(event, network.then(async (response) => {
                    // Страницы больше нет у этого человека (предложение ушло от него) — копия с ценами из кэша уходит.
                    if (response.status === 404 || response.status === 403) {
                        try { await (await caches.open(PAGES)).delete(request); } catch {}
                        return;
                    }
                    if (!response.ok || response.redirected) return;
                    // В сети уже другая сборка (выкладка, а этот воркер ещё прежний): экран из кэша — со старыми файлами,
                    // и тихое обновление страницы (app.js stalePage) приведёт Turbo к полной перезагрузке. Старую копию
                    // выбрасываем раньше, чем страница перезагрузится: иначе, если свежая не легла в кэш (место на
                    // iPhone), каждая перезагрузка снова получала старую — петля (10.10.2026, «pwa: петля перезагрузок»).
                    const build = response.headers.get('X-Build');
                    if (build && cached.headers.get('X-Build') !== build) {
                        try {
                            const pages = await caches.open(PAGES);
                            const current = await pages.match(request);
                            if (current && current.headers.get('X-Build') !== build) await pages.delete(request);
                        } catch {}
                    }
                    for (const [key, entry] of recent) if (Date.now() - entry.at > 10_000) recent.delete(key);
                    recent.set(url.href, { response: response.clone(), at: Date.now() });
                }).catch(() => {}));
                return cached;
            }
            try { return await network; } catch { return caches.match('/offline'); }
        })());
    }
});

// Дописать в фоне, продлив жизнь воркера. Позднее продление старые WebKit отбивают (InvalidStateError) — тогда просто
// в фоне: ответ странице от этого пострадать не должен.
function later(event, promise) {
    try { event.waitUntil(promise); } catch { promise.catch(() => {}); }
}

async function cacheFirst(event, name, request, limit) {
    try {
        const hit = await (await caches.open(name)).match(request);
        if (hit) return hit;
    } catch {}
    const response = await fetch(request);
    // Картинка уходит странице сразу (рисуется по мере загрузки), запись в кэш — следом, не задерживая её.
    if (response.ok) later(event, store(name, request, response.clone(), limit));
    return response;
}

// Запись в кэш с обрезкой по лимиту. Обрезка перебирает все ключи — на каждой картинке это дорого, поэтому в среднем
// раз в 20 записей; случайно, а не счётчиком: iOS часто перезапускает воркер, и счётчик до 20 не доходил бы.
async function store(name, request, response, limit) {
    try {
        const cache = await caches.open(name);
        await cache.put(request, response);
        if (limit && Math.random() < 0.05) await trim(cache, limit);
    } catch {
        await dropHeavy();
    }
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
const surface = () => ['crm', 'park'].find((name) => location.hostname.startsWith(`${name}.`)) ?? 'site';

// Страница просит забыть экраны: выход.
self.addEventListener('message', (event) => {
    if (event.data?.forgetPages) later(event, caches.delete(PAGES));
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
        // Важное висит, пока его не нажмут или не смахнут (Chrome на ПК); новое с тем же tag снова звенит.
        requireInteraction: !!data.important,
        renotify: !!(data.important && n.tag),
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
