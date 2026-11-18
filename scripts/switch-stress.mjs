// Сторож «не даёт переключаться между разделами» (18.11.2026, CRM: «застревает в разделе, что бы ни выбрал — обратно
// перекидывает», и на ПК, и на телефоне). Быстрые случайные переходы по разделам с тем, что бывает в работе: открытое
// окно строки, шторка шапки, прокрутка, живые события `refresh` до и после нажатия, второй раздел следом за первым.
// После каждого нажатия экран должен быть в нажатом разделе. Причина была в визите, который Turbo не мог отменить
// (`resources/js/lib/visit-guard.js`): тихое перечитывание раздела доезжало и рисовалось поверх нового.
//
// Запуск при своём сервере (`php artisan serve --port=8017 --no-reload`):
//   node scripts/switch-stress.mjs        N=70 SEED=7 PC=1 SLOW=900 (мс к ответу сервера, как на проде) HOST=crm|site USER_ID=3
// Выход 1, если хоть одно нажатие привело не туда; у промаха печатается журнал событий Turbo и истории.
const { chromium, devices } = await import(process.env.PLAYWRIGHT || '/home/claude/tools/playwright/node_modules/playwright/index.mjs');
const port = process.env.PORT || '8017';
const host = process.env.HOST || 'crm';
const base = host === 'site' ? `http://xcar.localhost:${port}` : `http://${host}.xcar.localhost:${port}`;
const user = Number(process.env.USER_ID || 3);
const pc = process.env.PC === '1';
const rounds = Number(process.env.N || 60);
const seed = Number(process.env.SEED || 7);
let rnd = seed; const rand = () => (rnd = (rnd * 1103515245 + 12345) % 2147483648) / 2147483648;
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
const ctx = await browser.newContext(pc ? { viewport: { width: 1440, height: 900 }, serviceWorkers: 'block' } : { ...devices['Pixel 7'], serviceWorkers: 'block' });
await ctx.addInitScript((id) => {
    try { localStorage.setItem(`push:offer:${id}`, JSON.stringify({ n: 3, at: Date.now() })); } catch {}
    window.EventSource = class extends EventTarget { static CONNECTING = 0; static OPEN = 1; static CLOSED = 2; constructor() { super(); this.readyState = 1; window.__es = this; setTimeout(() => this.onopen?.(), 0); } close() { this.readyState = 2; } };
    window.__log = [];
    const t0 = performance.now();
    const note = (s) => { window.__log.push(`${Math.round(performance.now() - t0)} ${s}`); if (window.__log.length > 400) window.__log.shift(); };
    window.__note = note;
    for (const n of ['turbo:click', 'turbo:before-visit', 'turbo:visit', 'turbo:before-render', 'turbo:render', 'turbo:load', 'turbo:fetch-request-error']) document.addEventListener(n, (e) => note(`${n.slice(6)} ${e.detail?.action ?? ''} ${String(e.detail?.url ?? '').replace(location.origin, '').slice(0, 60)}${e.defaultPrevented ? ' ОТМЕНЁН' : ''}`), true);
    addEventListener('popstate', (e) => note(`popstate → ${location.pathname}${location.search.slice(0, 30)} overlay=${JSON.stringify(e.state?.overlay ?? null)}`), true);
    const go = history.go.bind(history); history.go = (d) => { note(`history.go(${d})`); return go(d); };
    const back = history.back.bind(history); history.back = () => { note('history.back()'); return back(); };
}, user);
await ctx.route((url) => url.host === new URL(base).host && !/\/(build|media|hot|pwa|fonts|images|live)\//.test(url.pathname), async (route) => {
    const request = route.request();
    if (request.method() !== 'GET' || !['document', 'fetch'].includes(request.resourceType())) return route.continue();
    // Сервер прода отвечает на список 0,5–2 с — без этого гонки не видно.
    if (process.env.SLOW) await new Promise((r) => setTimeout(r, Number(process.env.SLOW) * (0.3 + rand())));
    const response = await route.fetch().catch(() => null);
    if (!response) return route.abort();
    let body = await response.text();
    if ((response.headers()['content-type'] || '').includes('text/html') && body.includes('<head>') && !body.includes('name="mercure-hub"')) body = body.replace('<head>', '<head><meta name="mercure-hub" content="/fake-hub"><meta name="mercure-topics" content="staff">');
    return route.fulfill({ response, body });
});
const page = await ctx.newPage();
page.on('dialog', (d) => d.dismiss().catch(() => {}));
page.on('pageerror', (e) => console.log('  ! ошибка страницы:', e.message.slice(0, 160)));
await page.goto(`http://xcar.localhost:${port}/dev/login/${user}`);
await page.goto(base + '/'); await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(1500);
const sel = pc ? 'header a[data-section]' : '#tabbar a.tab';
const tabs = await page.$$eval(sel, (as) => [...new Set(as.filter((a) => a.offsetParent || a.closest('#tabbar')).map((a) => a.getAttribute('href')))].filter((h) => h && !h.startsWith('http')));
console.log('разделы:', tabs.join(' '));
const where = () => page.evaluate(() => ({ tab: document.body.dataset.navTab ?? '', url: location.pathname + location.search, modal: !!document.querySelector('dialog:modal'), peek: !!document.querySelector('turbo-frame#detail')?.children.length }));
const tapSection = async (href) => {
    const at = await page.evaluate(([sel, href]) => { const a = [...document.querySelectorAll(sel)].find((n) => n.getAttribute('href') === href && n.getBoundingClientRect().width > 0); if (!a) return null; const r = a.getBoundingClientRect(); const p = [r.left + r.width / 2, r.top + r.height / 2]; const top = document.elementFromPoint(p[0], p[1]); return top && (a === top || a.contains(top)) ? p : null; }, [sel, href]);
    if (!at) return false;
    await page.evaluate((h) => window.__note(`=== нажимаю раздел ${h}`), href);
    if (pc) await page.mouse.click(at[0], at[1]); else await page.touchscreen.tap(at[0], at[1]);
    return true;
};
const event = (type, data) => page.evaluate(([type, data]) => { window.__note(`живое событие ${type} ${JSON.stringify(data).slice(0, 70)}`); window.__es?.dispatchEvent(new MessageEvent(type, { data: JSON.stringify(data) })); }, [type, data]);
const failures = [];
let done = 0;
for (let i = 0; i < rounds; i++) {
    const now = await where();
    const target = tabs.filter((t) => t !== now.tab)[Math.floor(rand() * (tabs.length - 1))] ?? tabs[0];
    const act = rand();
    let did = 'сразу';
    if (act < 0.25) {
        // открыть окно строки (карточка машины, письмо)
        const row = await page.evaluate(() => { const r = [...document.querySelectorAll('tr[data-detail-key], a.inbox-row, a.row')].find((r) => { const b = r.getBoundingClientRect(); return b.top > 200 && b.bottom < innerHeight - 160 && b.width > 100; }); if (!r) return null; const b = r.getBoundingClientRect(); return [b.left + Math.min(120, b.width / 2), b.top + b.height / 2]; });
        if (row) { if (pc) await page.mouse.click(row[0], row[1]); else await page.touchscreen.tap(row[0], row[1]); await page.waitForTimeout(200 + rand() * 1500); did = 'после окна строки'; }
    } else if (act < 0.45) {
        await event('refresh', { paths: [new URL(page.url()).pathname, target] }); await page.waitForTimeout(rand() * 300); did = 'после живого refresh';
    } else if (act < 0.6) {
        await page.evaluate(() => { const s = document.querySelector('#scroller'); if (s) s.scrollTop = 600 + Math.random() * 900; }); await page.waitForTimeout(100 + rand() * 300); did = 'после прокрутки';
    } else if (act < 0.7) {
        // шторка шапки (уведомления) — открыть и сразу уйти
        const bell = await page.evaluate(() => { const b = [...document.querySelectorAll('header .header-btn, header button')].find((n) => /bell|notif/i.test(n.outerHTML.slice(0, 500)) && n.getBoundingClientRect().width > 0); if (!b) return null; const r = b.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; });
        if (bell) { if (pc) await page.mouse.click(bell[0], bell[1]); else await page.touchscreen.tap(bell[0], bell[1]); await page.waitForTimeout(300 + rand() * 500); await page.keyboard.press('Escape'); await page.waitForTimeout(rand() * 250); did = 'после шторки уведомлений'; }
    }
    if (!(await tapSection(target))) {
        // раздел закрыт слоем — закрыть как человек и нажать
        // раздел под слоем (окно письма, шторка) — закрыть как человек: крестик окна или Esc
        let ok = false;
        for (let k = 0; k < 4 && !ok; k++) {
            const x = await page.evaluate(() => { const b = [...document.querySelectorAll('dialog[open] [data-action*="close"], dialog[open] .sheet-close, dialog[open] [aria-label="Закрыть"]')].find((n) => n.getBoundingClientRect().width > 0); if (!b) return null; const r = b.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; });
            if (x && k < 2) { if (pc) await page.mouse.click(x[0], x[1]); else await page.touchscreen.tap(x[0], x[1]); } else await page.keyboard.press('Escape');
            await page.waitForTimeout(500);
            ok = await tapSection(target);
        }
        if (!ok) { const w = await where(); console.log(`  · ${i}: раздел ${target} не нажимается: ${w.url} ${w.modal ? '[шторка]' : ''}`); const log = await page.evaluate(() => window.__log.slice(-12)); console.log(log.map((l) => '      ' + l).join('\n')); continue; }
        did += ', слой закрыт';
    }
    // иногда второй раздел почти сразу
    let final = target;
    if (rand() < 0.3) { await page.waitForTimeout(40 + rand() * 250); const other = tabs.filter((t) => t !== target)[Math.floor(rand() * (tabs.length - 1))]; if (await tapSection(other)) { final = other; did += ` и следом ${other}`; } }
    if (rand() < 0.3) { await page.waitForTimeout(rand() * 400); await event('refresh', { paths: [target, final, '/offers'] }); did += ', событие вдогонку'; }
    await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(1300 + (Number(process.env.SLOW) || 0));
    const got = await where();
    done++;
    if (got.tab !== final) {
        failures.push({ i, did, final, got });
        console.log(`  ✗ ${i}: нажал ${final} (${did}) — оказался в ${got.tab} ${got.url}${got.modal ? ' [шторка]' : ''}`);
        const log = await page.evaluate(() => window.__log.slice(-26));
        console.log(log.map((l) => '      ' + l).join('\n'));
        if (failures.length >= Number(process.env.STOP || 3)) break;
    }
}
console.log(`\n${done - failures.length} из ${done} переключений попали в нажатый раздел (${pc ? 'ПК' : 'телефон'}, зерно ${seed})`);
await browser.close();
process.exit(failures.length ? 1 : 0);
