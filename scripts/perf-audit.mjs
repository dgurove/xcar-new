// Замер быстродействия списков (31.10.2026, владелец: «страницы тяжёлые, как будто при скролле сотни раз рендерится»):
// каждый экран — телефоном (390 px, касания, процессор ×4 — средний Android) и ПК (1440): загрузка до покоя (2 с без
// длинных задач), узлы, контроллеры, пересчёты стилей и раскладки; прокрутка вниз и вверх по 4 000 px — длинные задачи
// и медленные кадры; покой — что работает само. Сравнивать только до/после на одной машине: безголовый Chrome рисует
// программно, отрисовка завышена, а жест пальцем в нём `#scroller` не листает — листается колесом.
// Запуск: свой `PHP_CLI_SERVER_WORKERS=4 php artisan serve --port=8013 --no-reload` (общий 8010 однопоточный), затем
//   node scripts/perf-audit.mjs [часть адреса]     (MODE=phone,pc PORT=8013 IDLE=10)
const { chromium } = await import(process.env.PLAYWRIGHT || '/home/claude/tools/playwright/node_modules/playwright/index.mjs');
const port = process.env.PORT || '8013';
const modes = (process.env.MODE || 'phone,pc').split(',');
const idle = Number(process.env.IDLE || 10) * 1000;
const only = process.argv[2] || '';
const host = s => s === 'site' ? 'xcar.localhost' : `${s}.xcar.localhost`;

// [поверхность, адрес, кто смотрит]: 3 — админ, 6 — менеджер.
const screens = [
    ['crm', '/?preset=all&vid=table', 3], ['crm', '/?preset=published&vid=table', 3], ['crm', '/work/deals?preset=all', 3],
    ['crm', '/work/mail', 3], ['site', '/', 6], ['site', '/deals', 6], ['park', '/cars?preset=stored', 3],
    ['park', '/cars?preset=out', 3], ['logistics', '/transports?preset=all', 3],
].filter(([s, path]) => `${s}${path}`.includes(only));

const metrics = async cdp => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map(m => [m.name, m.value]));
const diff = (a, b) => ({
    recalc: b.RecalcStyleCount - a.RecalcStyleCount, recalcMs: Math.round((b.RecalcStyleDuration - a.RecalcStyleDuration) * 1000),
    layout: b.LayoutCount - a.LayoutCount, layoutMs: Math.round((b.LayoutDuration - a.LayoutDuration) * 1000),
    js: Math.round((b.ScriptDuration - a.ScriptDuration) * 1000),
});

// Длинные задачи и кадры — счётчиками в странице.
function watch() {
    window.__lt = [];
    new PerformanceObserver(l => { for (const e of l.getEntries()) window.__lt.push([e.startTime, e.duration]); }).observe({ type: 'longtask', buffered: true });
}
function frames() {
    window.__lt = [];
    window.__f = [];
    let last = performance.now();
    window.__run = true;
    const tick = t => { window.__f.push(t - last); last = t; if (window.__run) requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
}
function framesDone() {
    window.__run = false;
    const f = window.__f.slice(1);
    return { frames: f.length, slow: f.filter(d => d > 34).length, max: Math.round(Math.max(0, ...f)), lt: window.__lt.length, ltMs: Math.round(window.__lt.reduce((s, [, d]) => s + d, 0)) };
}

// Прокрутка колесом; не поехала (тяжёлая страница глотает жест) — по кадру скриптом, это видно в выводе.
async function scroll(page, cdp, phone) {
    const top = () => page.evaluate(() => document.querySelector('#scroller')?.scrollTop ?? scrollY);
    const g = { x: phone ? 200 : 720, y: 500, speed: 2500, gestureSourceType: 'mouse' };
    await cdp.send('Input.synthesizeScrollGesture', { ...g, yDistance: -4000 });
    let how = 'колесо', down = await top();
    if (down === 0) {
        how = 'скрипт';
        await page.evaluate(() => new Promise(done => { const s = document.querySelector('#scroller'); let n = 0; const step = () => { s.scrollTop += 60; if (++n < 66) requestAnimationFrame(step); else done(); }; requestAnimationFrame(step); }));
        down = await top();
        await page.evaluate(() => new Promise(done => { const s = document.querySelector('#scroller'); let n = 0; const step = () => { s.scrollTop -= 60; if (++n < 66) requestAnimationFrame(step); else done(); }; requestAnimationFrame(step); }));
    } else {
        await cdp.send('Input.synthesizeScrollGesture', { ...g, yDistance: 4000 });
    }
    return { how, down };
}

const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH });
for (const mode of modes) for (const [surface, path, user] of screens) {
    const phone = mode === 'phone';
    const ctx = await browser.newContext(phone
        ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, serviceWorkers: 'block' }
        : { viewport: { width: 1440, height: 900 }, serviceWorkers: 'block' });
    const page = await ctx.newPage();
    try {
        await page.goto(`http://xcar.localhost:${port}/dev/login/${user}`);
        const cdp = await ctx.newCDPSession(page);
        await cdp.send('Performance.enable');
        if (phone) await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
        let requests = 0;
        page.on('request', () => requests++);
        const m0 = await metrics(cdp);
        const t0 = Date.now();
        const resp = await page.goto(`http://${host(surface)}:${port}${path}`, { waitUntil: 'load', timeout: 180_000 });
        const load = Date.now() - t0;
        const html = (await resp.body()).length;
        const server = (await resp.headerValue('server-timing'))?.match(/app;dur=([\d.]+).*q;desc="(\d+)"/);
        await page.evaluate(watch);
        for (let i = 0; i < 90; i++) {
            await page.waitForTimeout(1000);
            if (i >= 2 && await page.evaluate(() => !window.__lt.some(([t, d]) => t + d > performance.now() - 2000))) break;
        }
        const settle = Date.now() - t0;
        const m1 = await metrics(cdp);
        const dom = await page.evaluate(() => ({ nodes: document.getElementsByTagName('*').length, ctrl: document.querySelectorAll('[data-controller]').length, rows: document.querySelectorAll('[data-detail-key]').length }));
        await page.evaluate(frames);
        const m2 = await metrics(cdp);
        requests = 0;
        const sc = await scroll(page, cdp, phone);
        await page.waitForTimeout(500);
        const m3 = await metrics(cdp);
        const fr = await page.evaluate(framesDone);
        const scrollRequests = requests;
        requests = 0;
        const m4 = await metrics(cdp);
        await page.waitForTimeout(idle);
        const m5 = await metrics(cdp);
        const l = diff(m0, m1), s = diff(m2, m3), q = diff(m4, m5);
        console.log(`${mode.padEnd(5)} ${(surface + path).padEnd(34)} сервер ${server ? `${Math.round(server[1])} мс/${server[2]} запр.` : '?'}, ${Math.round(html / 1024)} КБ, `
            + `узлов ${dom.nodes}, строк ${dom.rows}, контроллеров ${dom.ctrl}`);
        console.log(`      загрузка ${load} мс, покой через ${settle} мс; стили ${l.recalc}× ${l.recalcMs} мс, раскладка ${l.layout}× ${l.layoutMs} мс, скрипты ${l.js} мс`);
        console.log(`      прокрутка (${sc.how}, ${sc.down} px): длинных задач ${fr.lt} / ${fr.ltMs} мс, кадров ${fr.frames}, медленных ${fr.slow}, худший ${fr.max} мс; `
            + `стили ${s.recalc}× ${s.recalcMs} мс, раскладка ${s.layout}× ${s.layoutMs} мс, скрипты ${s.js} мс, запросов ${scrollRequests}`);
        console.log(`      покой ${idle / 1000} с: стили ${q.recalc}×, раскладка ${q.layout}×, скрипты ${q.js} мс, запросов ${requests}`);
    } catch (e) {
        console.log(`${mode} ${surface}${path} — ошибка: ${e.message.split('\n')[0]}`);
    }
    await ctx.close();
}
await browser.close();
