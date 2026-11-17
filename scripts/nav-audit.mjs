// Сторож «приложение, а не сайт» (17.11.2026, владелец: «нажал — несколько секунд ничего не происходит», «сбрасывается то,
// где ты был в другом разделе»). Телефон в эмуляции (процессор ×4) нажимает табы CRM и проверяет две вещи:
//
// 1. Отклик. От касания таба до первой перемены на экране (`отклик`: другой заголовок или скелетон раздела) и до готовой
//    страницы (`данные`), сколько раз за это сходили на сервер. Первый круг — разделы открываются впервые, второй — уже
//    открывались. Порог отклика — `SHOWN` мс (по умолчанию 150).
// 2. Место. Ушёл из раздела и вернулся — тот же адрес, та же прокрутка, те же догруженные строки, то же открытое окно:
//    уход табом, уход по всплывающему сообщению и «‹» из чата, полная перезагрузка в чат (системный пуш при закрытом
//    приложении), письмо на телефоне. И память табов не путается: «Чаты» открывают чаты.
//
// Запуск: свой сервер `PHP_CLI_SERVER_WORKERS=2 php artisan serve --port=8017 --no-reload` (гасить по PID), затем
//   node scripts/nav-audit.mjs            (PORT=8017 SHOWN=150 ONLY=отклик|место|переход SHOTS=каталог HOST=site USER_ID=6)
// Сайт (`HOST=site`, менеджер 6 или покупатель 46; 18.11.2026): там главное действие не таб, а «плитка → машина → назад» —
// блок «переход» меряет отклик таких нажатий (от клика: палец в эмуляции держится дольше настоящего), блок «место» —
// прокрутку списка после «назад» и после ухода табом и то, что машина из Галереи и Избранного остаётся в своём разделе.
// Выход 1, если хоть одна проверка не прошла. Безголовый Chromium рисует программно — сравнивать «до» и «после» на
// одной машине, абсолютные числа с айфона даёт `php artisan perf:pages`.
const { chromium, devices } = await import(process.env.PLAYWRIGHT || '/home/claude/tools/playwright/node_modules/playwright/index.mjs');
const port = process.env.PORT || '8017';
const limit = Number(process.env.SHOWN || 150);
const only = process.env.ONLY || '';
const shots = process.env.SHOTS || '';
// HOST=site|park|logistics — отклик табов другого хоста (место проверяется только в CRM: сценарии его разделов).
const host = process.env.HOST || 'crm';
const crm = host === 'site' ? `http://xcar.localhost:${port}` : `http://${host}.xcar.localhost:${port}`;
const user = Number(process.env.USER_ID || 3);

const results = [];
const check = (name, ok, note = '') => { results.push({ name, ok }); console.log(`${ok ? '  ✓' : '  ✗'} ${name}${note ? ' — ' + note : ''}`); };

const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
const ctx = await browser.newContext({ ...devices['Pixel 7'], serviceWorkers: 'block' });
await ctx.addInitScript((id) => {
    // Шторка «включите уведомления» закрывает таб-бар через пару секунд после входа — здесь она не предмет проверки.
    try { localStorage.setItem(`push:offer:${id}`, JSON.stringify({ n: 3, at: Date.now() })); } catch {}
    // Живые события — подставной источник (хаба локально нет): проверка «раздел устарел, пока был скрыт» шлёт событие сама.
    window.EventSource = class extends EventTarget {
        static CONNECTING = 0; static OPEN = 1; static CLOSED = 2;
        constructor() { super(); this.readyState = 1; window.__es = this; setTimeout(() => this.onopen?.(), 0); }
        close() { this.readyState = 2; }
    };
    window.__nav = { events: [], t0: 0, shown: null, sign: '' };
    // Знак экрана — что на нём видно, а не адрес: возврат из снимка ставит адрес раньше, чем рисует (18.11.2026).
    const sign = () => `${document.querySelector('h1')?.textContent.trim() ?? ''}|${document.body?.dataset.navTab ?? ''}|${document.querySelector('[data-section-ghost]') ? 'ghost' : ''}|${document.querySelector('#scroller')?.textContent.replace(/\s+/g, ' ').trim().slice(0, 80) ?? ''}`;
    window.__navArm = () => {
        const nav = window.__nav;
        nav.events = []; nav.shown = null; nav.t0 = 0; nav.click = null; nav.ghost = false; nav.vt = 0; nav.long = 0; nav.sign = sign();
        const watch = () => {
            if (document.querySelector('[data-section-ghost]')) nav.ghost = true;
            if (nav.t0 && nav.shown === null && sign() !== nav.sign) nav.shown = Math.round(performance.now() - nav.t0);
            if (nav.shown === null) requestAnimationFrame(watch);
        };
        requestAnimationFrame(watch);
    };
    document.addEventListener('touchstart', () => { if (window.__nav && !window.__nav.t0) window.__nav.t0 = performance.now(); }, true);
    document.addEventListener('click', () => { const nav = window.__nav; if (nav?.t0 && nav.click === null) nav.click = Math.round(performance.now() - nav.t0); }, true);
    try { new PerformanceObserver((list) => { for (const e of list.getEntries()) if (window.__nav?.t0) window.__nav.long += Math.round(e.duration); }).observe({ entryTypes: ['longtask'] }); } catch {}
    const startVT = document.startViewTransition?.bind(document);
    if (startVT) document.startViewTransition = (...args) => { if (window.__nav) window.__nav.vt += 1; return startVT(...args); };
    for (const name of ['turbo:visit', 'turbo:before-fetch-request', 'turbo:render', 'turbo:load']) {
        document.addEventListener(name, (event) => window.__nav.events.push([name.slice(6), Math.round(performance.now() - (window.__nav.t0 || performance.now())), event.detail?.url?.toString?.() ?? '']), true);
    }
}, user);
// Страницам без хаба (локально) подставляем его адрес и тему — `live.js` откроет подставной источник.
await ctx.route((url) => url.host === new URL(crm).host && !/\/(build|media|hot|pwa|fonts|images|live)\//.test(url.pathname), async (route) => {
    const request = route.request();
    if (request.method() !== 'GET' || !['document', 'fetch'].includes(request.resourceType())) return route.continue();
    const response = await route.fetch().catch(() => null);
    if (!response) return route.abort();
    let body = await response.text();
    if ((response.headers()['content-type'] || '').includes('text/html') && body.includes('<head>') && !body.includes('name="mercure-hub"')) {
        body = body.replace('<head>', '<head><meta name="mercure-hub" content="/fake-hub"><meta name="mercure-topics" content="staff">');
    }
    return route.fulfill({ response, body });
});
const page = await ctx.newPage();
page.on('dialog', (d) => d.dismiss().catch(() => {}));
await page.goto(`http://xcar.localhost:${port}/dev/login/${user}`);
const cdp = await ctx.newCDPSession(page);
await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });

const settle = async (ms = 1200) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(ms); };
const state = () => page.evaluate(() => ({
    url: location.pathname + location.search,
    y: Math.round(document.querySelector('#scroller')?.scrollTop ?? scrollY),
    rows: document.querySelectorAll('tr[data-detail-key]').length,
    peek: !!document.querySelector('turbo-frame#detail')?.children.length,
    letters: !!document.querySelector('dialog[open] turbo-frame#letters-frame, dialog[open] #letters-frame'),
    title: document.querySelector('h1')?.textContent.trim() ?? '',
    tab: document.body.dataset.navTab ?? '',
    // Что стоит под шапкой: первая строка или плитка и её верх. Место — это она, а не число прокрутки: у свежего DOM
    // высоты кусков за экраном (`content-visibility`) запасные, и та же строка стоит при другом `scrollTop`.
    anchor: (() => {
        const box = document.querySelector('#scroller')?.getBoundingClientRect();
        const el = [...document.querySelectorAll('#scroller tr[data-detail-key], #scroller article.card, #scroller .row')].find((e) => e.getBoundingClientRect().top >= (box?.top ?? 0) + 40);
        return el ? { id: el.dataset.detailKey || el.id || el.querySelector('a[href]')?.getAttribute('href') || '', top: Math.round(el.getBoundingClientRect().top) } : null;
    })(),
    cars: new Set([...document.querySelectorAll('a[href*="/offers/"]')].map((a) => a.getAttribute('href').match(/\/offers\/(\d+)/)?.[1]).filter(Boolean)).size,
}));
const tabs = () => page.$$eval('#tabbar a.tab', (as) => as.map((a) => a.getAttribute('href')));
const scrollTo = async (y) => { await page.evaluate((y) => { document.querySelector('#scroller').scrollTop = y; }, y); await page.waitForTimeout(700); };
async function tap(href, { timed = false } = {}) {
    // Шторка, что открылась сама (вопрос менеджеру о сделке, уведомления), закрывает таб-бар — здесь она не предмет проверки.
    // Закрываем как человек (Esc — `cancel`): скриптовый `close()` оставил бы запись слоя в истории (`overlays.js`).
    for (let i = 0; i < 3 && await page.evaluate(() => !!document.querySelector('dialog:modal')); i++) { await page.keyboard.press('Escape'); await page.waitForTimeout(450); }
    const link = await page.$(`#tabbar a.tab[href="${href}"]`);
    const box = await link?.boundingBox();
    if (!box) return null;
    const requests = [];
    const onRequest = (r) => { if (['document', 'fetch', 'xhr'].includes(r.resourceType()) && r.url().startsWith(crm) && !r.url().includes('/live/')) requests.push(r.url().replace(crm, '')); };
    page.on('request', onRequest);
    await page.evaluate(() => window.__navArm());
    await page.touchscreen.tap(box.x + box.width / 2, box.y + box.height / 2);
    await page.waitForFunction(() => window.__nav.events.some((e) => e[0] === 'load'), null, { timeout: 30000 }).catch(() => {});
    const nav = await page.evaluate(() => ({ shown: window.__nav.shown, load: window.__nav.events.find((e) => e[0] === 'load')?.[1] ?? null }));
    await settle(timed ? 900 : 1200);
    page.off('request', onRequest);
    return { ...nav, requests };
}
// Нажатие не таба: что нажать, находит функция на странице (координаты точки). Отклик — от касания и от клика.
const escSheets = async () => { for (let i = 0; i < 3 && await page.evaluate(() => !!document.querySelector('dialog:modal')); i++) { await page.keyboard.press('Escape'); await page.waitForTimeout(450); } };
async function press(find, arg) {
    await escSheets();
    const at = await page.evaluate(find, arg);
    if (!at) return null;
    const requests = [];
    const onRequest = (r) => { if (['document', 'fetch', 'xhr'].includes(r.resourceType()) && r.url().startsWith(crm) && !r.url().includes('/live/')) requests.push(r.url().replace(crm, '')); };
    page.on('request', onRequest);
    await page.evaluate(() => window.__navArm());
    await page.touchscreen.tap(at[0], at[1]);
    await page.waitForFunction(() => window.__nav.events.some((e) => e[0] === 'load'), null, { timeout: 15000 }).catch(() => {});
    const nav = await page.evaluate(() => { const n = window.__nav; return { shown: n.shown, click: n.click, load: n.events.find((e) => e[0] === 'load')?.[1] ?? null, ghost: n.ghost, vt: n.vt, long: n.long }; });
    await settle(900);
    page.off('request', onRequest);
    return { ...nav, afterClick: nav.shown === null ? null : nav.shown - (nav.click ?? 0), requests };
}
// Первая видимая ссылка списка (между шапкой и таб-баром), чей адрес подходит под выражение.
const linkIn = (source) => press((source) => {
    const re = new RegExp(source);
    const a = [...document.querySelectorAll('a[href]')].find((a) => { const r = a.getBoundingClientRect(); return r.width > 20 && r.height > 20 && r.top > 170 && r.bottom < innerHeight - 110 && a.offsetParent && !a.classList.contains('btn') && re.test(a.getAttribute('href')); });
    if (!a) return null;
    const r = a.getBoundingClientRect();
    return [r.left + Math.min(r.width / 2, 90), r.top + r.height / 2];
}, source);
const pressBack = () => press(() => { const b = [...document.querySelectorAll('[data-controller~="back"]')].find((n) => n.offsetParent && n.getBoundingClientRect().width > 0); if (!b) return null; const r = b.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; });
const back = async () => {
    const at = await page.evaluate(() => { const b = [...document.querySelectorAll('[data-controller~="back"]')].find((n) => n.offsetParent && n.getBoundingClientRect().width > 0); if (!b) return null; const r = b.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; });
    if (!at) return false;
    await page.touchscreen.tap(at[0], at[1]);
    await settle();
    return true;
};
const shot = async (name) => { if (shots) await page.screenshot({ path: `${shots}/${name}.png` }).catch(() => {}); };

// ——— 1. Отклик ———
if (!only || only === 'отклик') {
    console.log('\nОтклик на нажатие таба (телефон, процессор ×4)');
    await page.goto(crm + '/'); await settle(2000);
    const order = (await tabs()).slice(1).concat((await tabs())[0]);
    for (const round of ['впервые', 'повторно']) {
        for (const href of order) {
            const r = await tap(href, { timed: true });
            if (!r) { check(`${round} ${href}`, false, 'таб-бара нет'); continue; }
            const pages = r.requests.filter((u) => !u.startsWith('/build/'));
            const note = `отклик ${r.shown ?? '—'} мс, данные ${r.load ?? '—'} мс, запросов страницы ${pages.length}`;
            check(`${round} ${href}: отклик не позже ${limit} мс`, r.shown !== null && r.shown <= limit, note);
            if (round === 'повторно') check(`повторно ${href}: без запроса к серверу до показа`, pages.length === 0 || (r.shown !== null && r.shown <= limit), `запросы: ${pages.join(', ') || 'нет'}`);
            await shot(`отклик-${round}-${href.replace(/\W+/g, '-')}`);
        }
    }
}

// ——— 2. Место ———
if ((!only || only === 'место') && host === 'crm') {
    console.log('\nМесто в разделе');
    await page.goto(crm + '/offers'); await settle(1500);
    const [offers, mail, chats] = await tabs().then((t) => [t.find((h) => h.startsWith('/offers')), t.find((h) => h.includes('mail')), t.find((h) => h.includes('chats'))]).catch(() => []);
    const same = (a, b, what = ['url', 'y']) => what.every((k) => (k === 'y' ? Math.abs(a.y - b.y) <= 4 : a[k] === b[k]));
    const fmt = (s) => `${s.url} y=${s.y} строк=${s.rows}${s.peek ? ' окно' : ''}${s.letters ? ' письмо' : ''}`;

    // Уход табом.
    await page.goto(crm + '/offers?preset=all'); await settle(1500);
    await scrollTo(2500);
    let was = await state();
    await tap(chats); await tap(offers);
    let now = await state();
    check('уход табом и возврат: тот же адрес и прокрутка', same(was, now), `было ${fmt(was)}, стало ${fmt(now)}`);

    // Прокрутка другого раздела не переезжает в этот: Почта на 900 → «Предложения» на своём месте, и обратно.
    await tap(mail);
    await scrollTo(900);
    await tap(offers);
    now = await state();
    check('место раздела не берёт прокрутку покинутого', same(was, now), `было ${fmt(was)}, стало ${fmt(now)}`);
    await tap(mail);
    const back900 = await state();
    check('и покинутый помнит своё', Math.abs(back900.y - 900) <= 4, `Почта y=${back900.y}`);
    await tap(offers);

    // Раздел устарел, пока был скрыт: по нему пришло событие — при возврате он показан сразу и тихо перечитан.
    const hub = await page.evaluate(() => !!window.__es);
    if (hub) {
        await tap(chats);
        await page.evaluate(() => window.__es.dispatchEvent(new MessageEvent('refresh', { data: JSON.stringify({ paths: ['/offers'] }) })));
        const later = [];
        const watch = (q) => { if (q.resourceType() === 'fetch' && q.url().startsWith(crm + '/offers')) later.push(q.url().replace(crm, '')); };
        page.on('request', watch);
        const r = await tap(offers);
        // Перечитывание ждёт, пока экран успокоится (палец, прокрутка, идущий визит) — даём ему время.
        await page.waitForTimeout(3000);
        page.off('request', watch);
        check('устаревший раздел: показан сразу и перечитан следом', r.shown !== null && r.shown <= limit && later.length > 0, `отклик ${r.shown} мс, запросы: ${later.join(', ') || 'нет'}`);
        now = await state();
        check('после тихого обновления место то же', same(was, now), `было ${fmt(was)}, стало ${fmt(now)}`);
    } else {
        check('подставной источник живых событий открыт', false);
    }

    // Догруженные строки.
    await scrollTo(999999); await page.waitForTimeout(2500); await scrollTo(999999); await page.waitForTimeout(2500);
    const deep = await state();
    await scrollTo(Math.max(0, Math.round(deep.y * 0.9)));
    was = await state();
    await tap(mail); await tap(offers);
    now = await state();
    check('догруженные строки: число строк и прокрутка на месте', same(was, now, ['url', 'y', 'rows']), `было ${fmt(was)}, стало ${fmt(now)}`);

    // Память табов после «‹» из чата: уходим как по всплывающему сообщению.
    await page.goto(crm + '/offers?preset=all'); await settle(1500);
    await scrollTo(1800);
    was = await state();
    const chat = await page.evaluate(async () => (await (await fetch('/work/chats')).text()).match(/href="([^"]*\/work\/chats\/\d+)[^"]*"/)?.[1]);
    if (chat) {
        await page.evaluate((u) => window.Turbo.visit(u), chat); await settle();
        const left = await back();
        now = await state();
        check('всплывающее сообщение → чат → «‹»: вернулись на то же место', left && same(was, now), `было ${fmt(was)}, стало ${fmt(now)}`);
        await tap(mail); await tap(chats);
        now = await state();
        check('после «‹» из чата таб «Чаты» открывает чаты', now.url.startsWith('/work/chats'), `открылось ${now.url}`);
        await tap(offers);
        now = await state();
        check('и «Предложения» помнят место', same(was, now), `было ${fmt(was)}, стало ${fmt(now)}`);

        // Системный пуш при закрытом приложении: новое окно без истории и без памяти сессии сразу открывает чат.
        await page.goto(crm + '/offers?preset=all'); await settle(1500);
        await scrollTo(1800); await page.waitForTimeout(600);
        was = await state();
        // Приложение ушло в фон и выгружено: место пишется на `pagehide`.
        await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
        const fresh = await ctx.newPage();
        await fresh.goto(crm + chat); await fresh.waitForLoadState('networkidle').catch(() => {}); await fresh.waitForTimeout(1200);
        // «‹» в чате без истории ведёт в список чатов, оттуда — таб «Предложения».
        const at = await fresh.evaluate(() => { const b = [...document.querySelectorAll('[data-controller~="back"]')].find((n) => n.offsetParent && n.getBoundingClientRect().width > 0); if (!b) return null; const r = b.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; });
        if (at) { await fresh.touchscreen.tap(at[0], at[1]); await fresh.waitForFunction(() => location.pathname === '/work/chats', null, { timeout: 10000 }).catch(() => {}); await fresh.waitForLoadState('networkidle').catch(() => {}); await fresh.waitForTimeout(1500); }
        const link = await fresh.$(`#tabbar a.tab[href="${offers}"]`);
        const box = await link?.boundingBox();
        if (box) { await fresh.touchscreen.tap(box.x + box.width / 2, box.y + box.height / 2); await fresh.waitForFunction(() => location.pathname.startsWith('/offers'), null, { timeout: 10000 }).catch(() => {}); await fresh.waitForLoadState('networkidle').catch(() => {}); await fresh.waitForTimeout(1800); }
        now = await fresh.evaluate(() => ({ url: location.pathname + location.search, y: Math.round(document.querySelector('#scroller')?.scrollTop ?? scrollY), rows: document.querySelectorAll('tr[data-detail-key]').length }));
        await fresh.close();
        check('пуш при закрытом приложении → чат → «‹» → «Предложения»: то же место', same(was, now), `было ${fmt(was)}, стало ${fmt(now)}`);
    } else {
        check('чат для проверки найден', false);
    }

    // Открытое окно машины.
    await page.goto(crm + '/offers?preset=all'); await settle(1500);
    await scrollTo(1200);
    const row = await page.evaluate(() => { const r = [...document.querySelectorAll('tr[data-detail-key]')].find((r) => { const b = r.getBoundingClientRect(); return b.top > 260 && b.bottom < 600; }); if (!r) return null; const b = r.getBoundingClientRect(); return [b.left + 90, b.top + b.height / 2]; });
    if (row && chat) {
        await page.touchscreen.tap(row[0], row[1]); await settle(2500);
        was = await state();
        await page.evaluate((u) => window.Turbo.visit(u), chat); await settle();
        const t0 = Date.now();
        await back();
        // Экран со списком и открытым окном собирается из снимка дольше пустого списка — ждём место, а не гадаем.
        await page.waitForFunction((y) => Math.abs((document.querySelector('#scroller')?.scrollTop ?? 0) - y) <= 4, was.y, { timeout: 8000 }).catch(() => {});
        console.log(`  · список с открытым окном вернулся за ${Date.now() - t0} мс`);
        now = await state();
        check('открытое окно машины переживает уход в чат', was.peek && now.peek && same(was, now), `было ${fmt(was)}, стало ${fmt(now)}`);
        await shot('место-окно');
    }

    // Письмо на телефоне.
    await page.goto(crm + mail); await settle(1500);
    const letter = await page.evaluate(() => { const r = [...document.querySelectorAll('a.inbox-row')].find((r) => { const b = r.getBoundingClientRect(); return b.top > 260 && b.bottom < 640; }); if (!r) return null; const b = r.getBoundingClientRect(); return [b.left + 160, b.top + b.height / 2]; });
    if (letter) {
        await page.touchscreen.tap(letter[0], letter[1]); await settle(2500);
        was = await state();
        await page.evaluate(() => window.Turbo.visit('/work/chats')); await settle();
        await tap(mail);
        now = await state();
        check('открытое письмо переживает уход в другой раздел', was.letters && now.letters, `было ${fmt(was)}, стало ${fmt(now)}`);
        // Приложение выгрузили из памяти (полная загрузка того же адреса): письмо снова открыто — оно в адресе (`?letters=`).
        await page.reload(); await settle(2500);
        now = await state();
        check('открытое письмо переживает перезапуск приложения', now.letters, `стало ${fmt(now)}`);
        await shot('место-письмо');
    }
}

// ——— 3. Сайт: нажатия не табов ———
// Главное действие менеджера и покупателя — «плитка → машина → назад» (прод 17.11.2026: чаще нажатий табов, отклик 0,5 с).
if (host === 'site' && (!only || only === 'переход')) {
    console.log('\nПереходы сайта не табами (телефон, процессор ×4; отклик — от клика)');
    const report = (name, r, extra = '') => {
        if (!r) { check(name, false, 'нажать нечего'); return; }
        check(`${name}: отклик не позже ${limit} мс`, r.afterClick !== null && r.afterClick <= limit,
            `отклик ${r.afterClick ?? '—'} мс (от касания ${r.shown ?? '—'}), данные ${r.load ?? '—'} мс, долгие задачи ${r.long} мс, ${r.ghost ? 'заготовка есть' : 'заготовки нет'}${r.vt ? `, переходов вида ${r.vt}` : ''}${extra}`);
    };
    const hrefs = await tabs();
    await page.goto(crm + '/offers'); await settle(1500); await escSheets();
    await scrollTo(900);
    let was = await state();
    let r = await linkIn('/offers/\\d+');
    let now;
    if (r) {
        report('список → машина', r);
        r = await pressBack();
        now = await state();
        report('машина → назад', r, `, запросов страницы ${r?.requests.length ?? '—'}`);
        check('назад из машины: список на том же месте', now.url === was.url && Math.abs(now.y - was.y) <= 4, `было ${was.url} y=${was.y}, стало ${now.url} y=${now.y}`);
        report('список → машина второй раз', await linkIn('/offers/\\d+'));
        report('машина → следующая («›»)', await press(() => { const a = document.querySelector('a[rel="next"][href*="/offers/"]'); if (!a?.offsetParent) return null; const b = a.getBoundingClientRect(); return [b.left + b.width / 2, b.top + b.height / 2]; }));
    } else {
        // Человек выбрал вид «таблица» (вид на сайте помнится): строка открывает окно, а не страницу — это сценарий CRM.
        console.log('  · список таблицей: машина открывается окном — страницу машины меряет покупатель (USER_ID=46)');
    }
    // Третий таб роли: «Сделки» менеджера, «Интерес» покупателя — строка, страница, назад.
    if (hrefs[2]) {
        await tap(hrefs[2]); await scrollTo(500);
        was = await state();
        r = await linkIn('/(deals|garage/cars|offers)/\\d+');
        if (r) {
            report(`${hrefs[2]} → строка`, r);
            r = await pressBack(); now = await state();
            report(`назад в ${hrefs[2]}`, r);
            check(`назад в ${hrefs[2]}: то же место`, now.url === was.url && Math.abs(now.y - was.y) <= 4, `было ${was.url} y=${was.y}, стало ${now.url} y=${now.y}`);
        }
    }
    // Кабинет → Чаты → чат → «‹».
    await page.goto(crm + '/account'); await settle(1200);
    r = await linkIn('/account/chats$');
    if (r) {
        report('кабинет → чаты', r);
        r = await linkIn('/account/chats/(offer/)?\\d+');
        if (r) { report('чаты → чат', r); report('чат → назад', await pressBack()); }
    }
}

// ——— 4. Сайт: место и раздел ———
if (host === 'site' && (!only || only === 'место')) {
    console.log('\nМесто и раздел на сайте');
    const hrefs = await tabs();
    const fmt = (s) => `${s.url} y=${s.y} раздел=${s.tab}${s.anchor ? ` верхняя ${s.anchor.id}@${s.anchor.top}` : ''}`;
    const same = (a, b) => a.url === b.url && (a.anchor && b.anchor ? a.anchor.id === b.anchor.id && Math.abs(a.anchor.top - b.anchor.top) <= 4 : Math.abs(a.y - b.y) <= 4);
    const other = hrefs.find((h) => h.startsWith('/garage')) ?? hrefs[2];

    await page.goto(crm + '/offers'); await settle(1500); await escSheets();
    await scrollTo(1400);
    let was = await state();
    await tap(other); await tap('/offers');
    let now = await state();
    check('уход табом и возврат: тот же список и прокрутка', same(was, now), `было ${fmt(was)}, стало ${fmt(now)}`);

    // Машина со страницы другого раздела остаётся в нём: подсветка таб-бара и память раздела.
    if (hrefs.includes('/gallery')) {
        await tap('/gallery'); await settle(600);
        if (await linkIn('/offers/\\d+')) {
            const car = await state();
            check('машина из Галереи остаётся в разделе «Галерея»', car.tab === '/gallery', fmt(car));
            await tap('/offers'); now = await state();
            check('«Предложения» после неё открывают свой список', now.url.split('?')[0] === '/offers', fmt(now));
            await tap('/gallery'); now = await state();
            check('«Галерея» помнит открытую в ней машину', now.url.split('?')[0] === car.url.split('?')[0], `была ${fmt(car)}, стало ${fmt(now)}`);
            await tap('/gallery'); // второй раз — к корню раздела
        } else {
            // Галерея пуста (окно до 23:59 кончилось): та же дверь сервера — машина каталога с контекстом галереи.
            const any = await page.evaluate(async () => (await (await fetch('/offers')).text()).match(/href="[^"]*(\/offers\/\d+)[?"]/)?.[1]);
            if (any) {
                await page.evaluate((u) => window.Turbo.visit(u), `${any}?list=gallery`); await settle();
                now = await state();
                check('машина с контекстом галереи — в разделе «Галерея»', now.tab === '/gallery', fmt(now));
            }
        }
    }
    await page.goto(crm + '/account/favorites'); await settle(1200);
    if (await linkIn('/offers/\\d+')) {
        const car = await state();
        check('машина из Избранного остаётся в «Кабинете»', car.tab === '/account', fmt(car));
        await tap('/offers'); now = await state();
        check('«Предложения» после неё открывают свой список', now.url.split('?')[0] === '/offers', fmt(now));
    } else console.log('  · в избранном пусто — пропуск');

    // Вторая страница списка: машина и системное «назад».
    await page.goto(crm + '/offers?page=2'); await settle(1500); await escSheets();
    if ((await state()).cars > 3) {
        await scrollTo(600);
        was = await state();
        if (await linkIn('/offers/\\d+')) {
            await page.goBack(); await settle();
            now = await state();
            check('вторая страница: после машины то же место', same(was, now), `было ${fmt(was)}, стало ${fmt(now)}`);
        }
    } else console.log('  · второй страницы нет — пропуск');

    // Запуск с иконки: адрес из манифеста открывается без круга через редирект.
    const fresh = await ctx.newPage();
    const hops = [];
    fresh.on('response', (res) => { if (res.request().resourceType() === 'document') hops.push(res.status()); });
    await fresh.goto(crm + '/offers?app=1'); await fresh.waitForLoadState('load').catch(() => {});
    await fresh.close();
    check('запуск с иконки (/offers?app=1): без редиректа', hops.length === 1 && hops[0] === 200, `ответы: ${hops.join(' → ')}`);
}

await browser.close();
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length} из ${results.length} проверок прошли`);
process.exit(failed.length ? 1 : 0);
